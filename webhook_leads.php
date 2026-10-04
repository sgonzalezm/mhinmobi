<?php
/**
 * webhook_leads.php
 * Endpoint para recibir leads de Meta (Facebook/Instagram) en tiempo real.
 * Vera Terra Inmobiliaria
 *
 * Usa la conexión existente en includes/conexion.php (variable $conn)
 */

// ============================================
// CONFIGURACIÓN — EDITA ESTOS VALORES
// ============================================
$VERIFY_TOKEN = 'veraterra_webhook_2026_secreto_cambiar';  // Inven-tate uno, lo pondrás también en Meta
$APP_SECRET   = 'a3ae2160f19f70e6bc0764b87324df04';                       // Meta → Configuración → Básica → Clave secreta
$LOG_FILE     = __DIR__ . '/webhook_leads.log';

// ============================================
// LOGGING
// ============================================
function logMsg($msg) {
    global $LOG_FILE;
    $fecha = date('Y-m-d H:i:s');
    @file_put_contents($LOG_FILE, "[$fecha] $msg\n", FILE_APPEND);
}

// ============================================
// 1. VERIFICACIÓN DEL WEBHOOK (GET)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode']         ?? $_GET['hub.mode']         ?? null;
    $token     = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? null;
    $challenge = $_GET['hub_challenge']    ?? $_GET['hub.challenge']    ?? null;

    if ($mode === 'subscribe' && $token === $VERIFY_TOKEN) {
        logMsg("✅ Verificación exitosa del webhook.");
        http_response_code(200);
        echo $challenge;
        exit;
    } else {
        logMsg("❌ Falló verificación. mode=$mode token=$token");
        http_response_code(403);
        echo "Forbidden";
        exit;
    }
}

// ============================================
// 2. RECEPCIÓN DE LEADS (POST)
// ============================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$raw = file_get_contents('php://input');
logMsg("📥 POST recibido: " . $raw);

// ============================================
// 3. VALIDAR FIRMA (X-Hub-Signature-256)
// ============================================
$signatureHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if (!empty($signatureHeader) && $APP_SECRET !== 'TU_APP_SECRET_AQUI') {
    list($algo, $hash) = explode('=', $signatureHeader, 2);
    $expected = hash_hmac('sha256', $raw, $APP_SECRET);
    if (!hash_equals($expected, $hash)) {
        logMsg("❌ Firma inválida. Se rechaza el payload.");
        http_response_code(403);
        exit;
    }
    logMsg("✅ Firma válida.");
}

// ============================================
// 4. PARSEAR PAYLOAD
// ============================================
$payload = json_decode($raw, true);
if (!$payload || !isset($payload['entry'])) {
    logMsg("⚠️ Payload vacío o mal formado.");
    http_response_code(200);
    exit;
}

// ============================================
// 5. CONECTAR A LA BD (usa tu conexion.php)
// ============================================
try {
    require_once __DIR__ . '/includes/conexion.php';
} catch (Throwable $e) {
    logMsg("❌ Error cargando conexion.php: " . $e->getMessage());
    http_response_code(200);
    echo "EVENT_RECEIVED"; // Importante: Meta necesita 200
    exit;
}

if (!isset($conn) || !$conn) {
    logMsg("❌ Variable \$conn no disponible tras cargar conexion.php");
    http_response_code(200);
    echo "EVENT_RECEIVED";
    exit;
}

// ============================================
// 6. PROCESAR CADA ENTRADA
// ============================================
try {
    foreach ($payload['entry'] as $entry) {
        $pageId = $entry['id'] ?? null;

        if (empty($entry['changes'])) continue;

        foreach ($entry['changes'] as $change) {
            if (($change['field'] ?? '') !== 'leadgen') continue;

            $v           = $change['value'] ?? [];
            $leadgenId   = $v['leadgen_id']  ?? null;
            $formId      = $v['form_id']     ?? null;
            $adId        = $v['ad_id']       ?? null;
            $campaignId  = $v['campaign_id'] ?? null;
            $createdTime = isset($v['created_time'])
                            ? date('Y-m-d H:i:s', $v['created_time'])
                            : date('Y-m-d H:i:s');

            if (!$leadgenId) continue;

            logMsg("🎯 Lead detectado: $leadgenId (form: $formId)");

            // Consultar datos completos del lead en Graph API
            $leadData = fetchLeadData($leadgenId);
            if (!$leadData) {
                logMsg("⚠️ No se pudieron obtener datos del lead $leadgenId");
                continue;
            }

            // Extraer campos comunes (ajusta según los nombres de tus formularios)
            $fullName = extraerCampo($leadData, ['full_name', 'nombre', 'name', 'nombre_completo']);
            $email    = extraerCampo($leadData, ['email', 'correo', 'correo_electronico']);
            $phone    = extraerCampo($leadData, ['phone_number', 'phone', 'telefono', 'teléfono', 'celular', 'whatsapp']);
            $mensaje  = extraerCampo($leadData, ['mensaje', 'message', 'comentarios', 'comentario']);

            // Insertar en BD
            $stmt = $conn->prepare("
                INSERT IGNORE INTO leads_meta
                (lead_id, form_id, form_name, page_id, ad_id, ad_name,
                 campaign_id, campaign_name, created_time,
                 full_name, email, phone, mensaje, raw_data)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $leadgenId,
                $formId,
                $leadData['form_id']       ?? null,
                $pageId,
                $adId,
                $leadData['ad_name']       ?? null,
                $campaignId,
                $leadData['campaign_name'] ?? null,
                $createdTime,
                $fullName,
                $email,
                $phone,
                $mensaje,
                json_encode($leadData, JSON_UNESCAPED_UNICODE)
            ]);

            if ($stmt->rowCount() > 0) {
                logMsg("💾 Lead guardado: $leadgenId | $fullName | $email | $phone");
                notificarLead($fullName, $email, $phone, $formId);
            } else {
                logMsg("ℹ️ Lead $leadgenId ya existía, no se duplicó.");
            }
        }
    }

} catch (PDOException $e) {
    logMsg("❌ Error BD: " . $e->getMessage());
}

// Meta SIEMPRE requiere HTTP 200
http_response_code(200);
echo "EVENT_RECEIVED";

// ============================================
// FUNCIONES AUXILIARES
// ============================================

/**
 * Obtiene los datos completos de un lead desde la Graph API.
 * Lee el token desde token.txt (mismo directorio).
 */
function fetchLeadData($leadgenId) {
    $tokenFile = __DIR__ . '/token.txt';
    if (!file_exists($tokenFile)) {
        logMsg("❌ No existe token.txt para consultar leads.");
        return null;
    }
    $accessToken = trim(file_get_contents($tokenFile));
    if (empty($accessToken)) return null;

    $fields = 'id,created_time,field_data,ad_id,ad_name,campaign_id,campaign_name,form_id';
    $url = "https://graph.facebook.com/v21.0/{$leadgenId}?fields={$fields}&access_token=" . urlencode($accessToken);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 200) {
        logMsg("❌ Error al consultar lead $leadgenId. HTTP $http. $resp");
        return null;
    }

    return json_decode($resp, true);
}

/**
 * Busca el valor de un campo por nombre (Meta devuelve los nombres
 * tal como se definieron en el formulario).
 */
function extraerCampo($leadData, $posiblesNombres) {
    if (empty($leadData['field_data'])) return null;
    foreach ($leadData['field_data'] as $field) {
        $nombreCampo = strtolower($field['name'] ?? '');
        foreach ($posiblesNombres as $posible) {
            if ($nombreCampo === strtolower($posible)) {
                return $field['values'][0] ?? null;
            }
        }
    }
    return null;
}

/**
 * Notifica por correo (opcional).
 */
function notificarLead($nombre, $email, $phone, $formId) {
    $para     = 'leads@veraterra.mx';
    $asunto   = '🏠 Nuevo lead de Vera Terra';
    $mensaje  = "Nuevo lead recibido:\n\n";
    $mensaje .= "Nombre: "     . ($nombre ?: '—') . "\n";
    $mensaje .= "Email: "      . ($email  ?: '—') . "\n";
    $mensaje .= "Teléfono: "   . ($phone  ?: '—') . "\n";
    $mensaje .= "Formulario: " . ($formId ?: '—') . "\n";
    $mensaje .= "Fecha: "      . date('Y-m-d H:i:s') . "\n";

    $headers  = "From: webhook@veraterra.mx\r\n";
    $headers .= "Reply-To: no-reply@veraterra.mx\r\n";

    @mail($para, $asunto, $mensaje, $headers);
}