<?php
/**
 * webhook_leads.php
 * Recibe notificaciones de Meta Lead Ads en tiempo real.
 * Solo guarda el leadgen_id en una cola. Otro script (cron) lo procesa después.
 *
 * Configura esta URL como Webhook en tu App de Meta:
 *   https://tudominio.com/webhook_leads.php
 */

// ============================================
// LOG DE DEPURACIÓN
// ============================================
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);

function escribirLog($mensaje) {
    global $logDir;
    file_put_contents(
        $logDir . '/webhook_' . date('Y-m-d') . '.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $mensaje . PHP_EOL,
        FILE_APPEND
    );
}

// ============================================
// CARGA DE CONEXIÓN
// ============================================
require_once __DIR__ . '/includes/conexion.php';

if (!isset($conn) || !$conn) {
    escribirLog('ERROR: No hay conexión a BD');
    http_response_code(500);
    exit;
}

// ============================================
// CONFIGURACIÓN
// ============================================
// Este token debe coincidir EXACTAMENTE con el que pusiste en Meta
$VERIFY_TOKEN = 'veraterra_webhook_2026';

// ============================================
// 1. VERIFICACIÓN DEL WEBHOOK (GET)
// Meta llama a esta URL la primera vez para validar
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $modo          = $_GET['hub_mode']          ?? '';
    $tokenRecibido = $_GET['hub_verify_token']  ?? '';
    $challenge     = $_GET['hub_challenge']     ?? '';

    escribirLog("GET recibido | modo=$modo | token=" . substr($tokenRecibido, 0, 10) . "...");

    if ($modo === 'subscribe' && $tokenRecibido === $VERIFY_TOKEN) {
        escribirLog('✅ Verificación exitosa');
        echo $challenge;
        exit;
    }

    escribirLog('❌ Verificación fallida');
    http_response_code(403);
    echo 'Token de verificación inválido';
    exit;
}

// ============================================
// 2. RECEPCIÓN DE NOTIFICACIONES (POST)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    escribirLog('POST recibido: ' . substr($raw, 0, 500));

    $input = json_decode($raw, true);

    if (!$input) {
        escribirLog('ERROR: JSON inválido');
        http_response_code(200); // Siempre 200 para que Meta no reintente
        exit;
    }

    // Estructura esperada: { object: "page", entry: [ { changes: [ { field: "leadgen", value: {...} } ] } ] }
    if (($input['object'] ?? '') === 'page' && !empty($input['entry'])) {
        foreach ($input['entry'] as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                if (($change['field'] ?? '') === 'leadgen') {
                    $leadgenId = $change['value']['leadgen_id'] ?? null;
                    $formId    = $change['value']['form_id']    ?? null;
                    $pageId    = $change['value']['page_id']    ?? null;
                    $adId      = $change['value']['ad_id']      ?? null;
                    $adgroupId = $change['value']['adgroup_id'] ?? null;

                    if (!$leadgenId) continue;

                    try {
                        $stmt = $conn->prepare("
                            INSERT IGNORE INTO leads_cola
                            (leadgen_id, form_id, page_id, ad_id, adgroup_id, procesado, fecha_recibido)
                            VALUES (?, ?, ?, ?, ?, 0, NOW())
                        ");
                        $stmt->execute([$leadgenId, $formId, $pageId, $adId, $adgroupId]);
                        escribirLog("✅ Lead encolado: $leadgenId");
                    } catch (PDOException $e) {
                        escribirLog('ERROR BD: ' . $e->getMessage());
                    }
                }
            }
        }
    }

    http_response_code(200);
    echo 'EVENT_RECEIVED';
    exit;
}

// Cualquier otro método
http_response_code(405);
exit;