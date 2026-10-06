<?php
/**
 * procesar_cola_leads.php
 * Cron Job: cada 1-2 minutos
 *
 * 1. Toma leads pendientes de leads_cola
 * 2. Consulta la API Graph para obtener los datos completos
 * 3. Los inserta en leads_meta
 * 4. Envía mensaje de WhatsApp con plantilla
 * 5. Marca el lead como procesado
 *
 * Ejecutar: php /home/usuario/domains/tudominio.com/procesar_cola_leads.php
 * O vía URL con clave: https://tudominio.com/procesar_cola_leads.php?clave=xxx
 */

// ============================================
// SEGURIDAD
// ============================================
$CLAVE_SECRETA = 'procesar_veraterra_2026';
$esCLI = (php_sapi_name() === 'cli');

if (!$esCLI && ($_GET['clave'] ?? '') !== $CLAVE_SECRETA) {
    die('Acceso denegado.');
}

// ============================================
// CONFIGURACIÓN
// ============================================
$PAGE_ID        = '1303158402871125';
$MAX_LEADS      = 20;  // Máximo de leads por ejecución (para no saturar)
$SEGUNDOS_PAUSA = 3;   // Pausa entre envíos de WhatsApp (evita bloqueos)

// ============================================
// CARGA DE DEPENDENCIAS
// ============================================
require_once __DIR__ . '/includes/conexion.php';

if (!isset($conn) || !$conn) {
    die('❌ No se pudo conectar a la BD.');
}

$tokenFile = __DIR__ . '/token.txt';
if (!file_exists($tokenFile)) {
    die('❌ No existe token.txt');
}
$ACCESS_TOKEN = trim(file_get_contents($tokenFile));
if (empty($ACCESS_TOKEN)) {
    die('❌ token.txt está vacío.');
}

// ============================================
// FUNCIONES AUXILIARES
// ============================================
function llamarAPI($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200) return ['error' => true, 'http' => $http, 'body' => $resp];
    return json_decode($resp, true);
}

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
 * Envía un mensaje de WhatsApp usando una plantilla aprobada.
 */
function enviarWhatsApp($phoneNumberId, $token, $destino, $templateName, $langCode, $params = []) {
    $url = "https://graph.facebook.com/v21.0/{$phoneNumberId}/messages";

    // Normalizar el número: solo dígitos, con código de país
    $destino = preg_replace('/\D/', '', $destino);

    $payload = [
        'messaging_product' => 'whatsapp',
        'to'                => $destino,
        'type'              => 'template',
        'template'          => [
            'name'     => $templateName,
            'language' => ['code' => $langCode],
        ],
    ];

    // Si hay parámetros, agregarlos al body
    if (!empty($params)) {
        $payload['template']['components'] = [[
            'type'       => 'body',
            'parameters' => array_map(function($p) {
                return ['type' => 'text', 'text' => (string)$p];
            }, $params),
        ]];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$token}",
            "Content-Type: application/json",
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok'   => ($http === 200),
        'http' => $http,
        'body' => json_decode($resp, true),
    ];
}

// ============================================
// SALIDA
// ============================================
if (!$esCLI) {
    echo "<html><head><meta charset='UTF-8'><title>Procesar Cola</title>";
    echo "<style>body{font-family:system-ui;background:#f5f7fa;padding:30px;max-width:900px;margin:0 auto;}";
    echo ".log{background:#1e1e1e;color:#0f0;padding:15px;border-radius:8px;font-family:monospace;font-size:13px;max-height:500px;overflow-y:auto;white-space:pre-wrap;}";
    echo "h1{color:#0b1f3a;}</style></head><body>";
    echo "<h1>⚙️ Procesando cola de leads</h1><div class='log'>";
}

function linea($msg) {
    global $esCLI;
    echo $esCLI ? "[$msg]\n" : htmlspecialchars($msg) . "\n";
    if (!$esCLI) { @ob_flush(); @flush(); }
}

// ============================================
// PROCESAMIENTO
// ============================================
try {
    // 1. Tomar leads pendientes
    $stmt = $conn->prepare("
        SELECT * FROM leads_cola
        WHERE procesado = 0
        ORDER BY fecha_recibido ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, $MAX_LEADS, PDO::PARAM_INT);
    $stmt->execute();
    $pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    linea("INFO: {$pendientes} leads pendientes encontrados");

    if (empty($pendientes)) {
        linea("INFO: Nada que procesar.");
        if (!$esCLI) echo "</div></body></html>";
        exit;
    }

    // 2. Obtener el Phone Number ID de WhatsApp desde la BD o variable
    // ⚠️ AJUSTA ESTO: guarda tu PHONE_NUMBER_ID en una tabla o aquí directo
    $PHONE_NUMBER_ID = 'TU_PHONE_NUMBER_ID_AQUI';

    // Nombre de tu plantilla aprobada y su idioma
    $TEMPLATE_NAME = 'bienvenida_lead_veraterra'; // ← El nombre exacto de tu plantilla
    $TEMPLATE_LANG = 'es_MX';

    $procesados = 0;

    foreach ($pendientes as $lead) {
        $leadgenId = $lead['leadgen_id'];
        linea("─── Procesando lead: {$leadgenId} ───");

        // 3. Consultar la API para traer los datos del lead
        $url = "https://graph.facebook.com/v21.0/{$leadgenId}"
             . "?fields=id,created_time,field_data,ad_id,ad_name,campaign_id,campaign_name,form_id"
             . "&access_token=" . urlencode($ACCESS_TOKEN);

        $leadData = llamarAPI($url);

        if (isset($leadData['error'])) {
            linea("[ERROR] API: " . substr($leadData['body'], 0, 200));
            // Marcamos como procesado con error para no reintentar infinito
            $conn->prepare("UPDATE leads_cola SET procesado = 2, fecha_procesado = NOW() WHERE id = ?")
                 ->execute([$lead['id']]);
            continue;
        }

        // 4. Extraer campos (igual que tu importador)
        $createdTime = isset($leadData['created_time'])
                        ? date('Y-m-d H:i:s', strtotime($leadData['created_time']))
                        : date('Y-m-d H:i:s');

        $fullName = extraerCampo($leadData, ['full_name','nombre','name','nombre_completo']);
        $email    = extraerCampo($leadData, ['email','correo','correo_electronico']);
        $phone    = extraerCampo($leadData, ['phone_number','phone','telefono','teléfono','celular','whatsapp']);
        $mensaje  = extraerCampo($leadData, ['mensaje','message','comentarios','comentario']);

        // 5. Insertar en leads_meta
        try {
            $stmt2 = $conn->prepare("
                INSERT IGNORE INTO leads_meta
                (lead_id, form_id, form_name, page_id, ad_id, ad_name,
                 campaign_id, campaign_name, created_time,
                 full_name, email, phone, mensaje, raw_data)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt2->execute([
                $leadData['id'], $leadData['form_id'] ?? null, null, $PAGE_ID,
                $leadData['ad_id']       ?? null,
                $leadData['ad_name']     ?? null,
                $leadData['campaign_id'] ?? null,
                $leadData['campaign_name'] ?? null,
                $createdTime,
                $fullName, $email, $phone, $mensaje,
                json_encode($leadData, JSON_UNESCAPED_UNICODE)
            ]);
            linea("✅ Lead insertado: {$fullName} | {$phone}");
        } catch (PDOException $e) {
            linea("[ERROR] BD: " . $e->getMessage());
        }

        // 6. Enviar el WhatsApp (solo si tenemos teléfono)
        if (!empty($phone)) {
            $resultado = enviarWhatsApp(
                $PHONE_NUMBER_ID,
                $ACCESS_TOKEN,
                $phone,
                $TEMPLATE_NAME,
                $TEMPLATE_LANG,
                [$fullName ?? ''] // Parámetros de la plantilla: {{1}}
            );

            if ($resultado['ok']) {
                linea("📤 WhatsApp enviado a {$phone}");
            } else {
                linea("❌ Error WhatsApp ({$resultado['http']}): " . json_encode($resultado['body']));
            }
        } else {
            linea("⚠️ Sin teléfono, no se envía WhatsApp");
        }

        // 7. Marcar como procesado
        $conn->prepare("UPDATE leads_cola SET procesado = 1, fecha_procesado = NOW() WHERE id = ?")
             ->execute([$lead['id']]);

        $procesados++;

        // Pausa entre envíos para no saturar la API
        if ($procesados < count($pendientes)) {
            sleep($SEGUNDOS_PAUSA);
        }
    }

    linea("✅ Total procesados: {$procesados}");

} catch (Exception $e) {
    linea("❌ EXCEPCIÓN: " . $e->getMessage());
}

if (!$esCLI) echo "</div></body></html>";