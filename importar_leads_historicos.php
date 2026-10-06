<?php
/**
 * importar_leads_historicos.php
 * Importa TODOS los leads históricos de los formularios de Meta a la BD.
 * Ejecutar UNA SOLA VEZ desde el navegador.
 *
 * ⚠️ BORRAR ESTE ARCHIVO DESPUÉS DE USARLO (por seguridad)
 */

// ============================================
// SEGURIDAD BÁSICA
// ============================================
$CLAVE_SECRETA = 'importar_veraterra_2026';
if (($_GET['clave'] ?? '') !== $CLAVE_SECRETA) {
    die('Acceso denegado. Falta la clave.');
}

// ============================================
// CONFIGURACIÓN
// ============================================
$PAGE_ID = '1303158402871125';

// ============================================
// CARGAR CONEXIÓN Y TOKEN
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

// ============================================
// SALIDA HTML
// ============================================
echo "<html><head><meta charset='UTF-8'>";
echo "<title>Importar Leads Históricos</title>";
echo "<style>
    body{font-family:system-ui,sans-serif;background:#f5f7fa;padding:30px;line-height:1.6;max-width:900px;margin:0 auto;}
    h1{color:#0b1f3a;}
    h2{color:#c5a059;margin-top:30px;border-bottom:2px solid #eee;padding-bottom:6px;}
    .log{background:#1e1e1e;color:#0f0;padding:15px;border-radius:8px;font-family:monospace;font-size:13px;max-height:400px;overflow-y:auto;white-space:pre-wrap;}
    .stats{background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.06);margin-top:20px;}
    .stats b{color:#0b1f3a;font-size:1.2em;}
    a.btn{display:inline-block;background:#c5a059;color:#fff;padding:10px 24px;border-radius:50px;text-decoration:none;font-weight:600;margin-top:20px;}
</style></head><body>";

echo "<h1>📥 Importación de Leads Históricos</h1>";
echo "<div class='log'>";

$totalImportados = 0;
$totalDuplicados = 0;
$totalErrores    = 0;

// --- 1. Obtener lista de formularios ---
echo "[INFO] Consultando formularios de la página...\n";
$urlForm = "https://graph.facebook.com/v21.0/{$PAGE_ID}/leadgen_forms?fields=id,name,status&access_token=" . urlencode($ACCESS_TOKEN);
$formsData = llamarAPI($urlForm);

if (isset($formsData['error'])) {
    echo "[ERROR] No se pudo obtener formularios: {$formsData['body']}\n";
    echo "</div></body></html>";
    exit;
}

if (empty($formsData['data'])) {
    echo "[WARN] No hay formularios en esta página.\n";
    echo "</div></body></html>";
    exit;
}

echo "[OK] Encontrados " . count($formsData['data']) . " formularios.\n\n";

// --- 2. Recorrer cada formulario ---
foreach ($formsData['data'] as $form) {
    $formId = $form['id'];
    $formName = $form['name'];

    echo "─── FORMULARIO: {$formName} (ID: {$formId}) ───\n";

    $urlLeads = "https://graph.facebook.com/v21.0/{$formId}/leads"
              . "?fields=id,created_time,field_data,ad_id,ad_name,campaign_id,campaign_name"
              . "&limit=100"
              . "&access_token=" . urlencode($ACCESS_TOKEN);

    $pagina = 0;
    do {
        $pagina++;
        echo "[PÁG] Consultando página {$pagina}...\n";
        $leadsData = llamarAPI($urlLeads);

        if (isset($leadsData['error'])) {
            echo "[ERROR] " . substr($leadsData['body'], 0, 200) . "\n";
            break;
        }

        if (empty($leadsData['data'])) {
            echo "[INFO] No hay más leads.\n";
            break;
        }

        foreach ($leadsData['data'] as $lead) {
            $leadId      = $lead['id'];
            $createdTime = isset($lead['created_time'])
                            ? date('Y-m-d H:i:s', strtotime($lead['created_time']))
                            : date('Y-m-d H:i:s');

            $fullName = extraerCampo($lead, ['full_name','nombre','name','nombre_completo']);
            $email    = extraerCampo($lead, ['email','correo','correo_electronico']);
            $phone    = extraerCampo($lead, ['phone_number','phone','telefono','teléfono','celular','whatsapp']);
            $mensaje  = extraerCampo($lead, ['mensaje','message','comentarios','comentario']);

            try {
                $stmt = $conn->prepare("
                    INSERT IGNORE INTO leads_meta
                    (lead_id, form_id, form_name, page_id, ad_id, ad_name,
                     campaign_id, campaign_name, created_time,
                     full_name, email, phone, mensaje, raw_data)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $leadId, $formId, $formName, $PAGE_ID,
                    $lead['ad_id']       ?? null,
                    $lead['ad_name']     ?? null,
                    $lead['campaign_id'] ?? null,
                    $lead['campaign_name'] ?? null,
                    $createdTime,
                    $fullName, $email, $phone, $mensaje,
                    json_encode($lead, JSON_UNESCAPED_UNICODE)
                ]);

                if ($stmt->rowCount() > 0) {
                    $totalImportados++;
                    echo "[OK] ✅ Importado: {$fullName} | {$email} | {$phone}\n";
                } else {
                    $totalDuplicados++;
                    echo "[SKIP] Ya existía: {$fullName} ({$email})\n";
                }
            } catch (PDOException $e) {
                $totalErrores++;
                echo "[ERROR] BD: " . $e->getMessage() . "\n";
            }
        }

        $urlLeads = $leadsData['paging']['next'] ?? null;

    } while (!empty($urlLeads));

    echo "\n";
}

echo "</div>";

// --- 3. Resumen ---
echo "<div class='stats'>";
echo "<h2>📊 Resumen</h2>";
echo "<p>✅ Leads importados: <b>{$totalImportados}</b></p>";
echo "<p>⏭️ Leads duplicados (ya existían): <b>{$totalDuplicados}</b></p>";
echo "<p>❌ Errores: <b>{$totalErrores}</b></p>";
echo "<a href='gestion_leads.php' class='btn'>Ver en el panel →</a>";
echo "</div>";

echo "</body></html>";