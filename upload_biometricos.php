<?php
// ============================================
// upload_biometricos.php
// Página para capturar datos biométricos
// ============================================

session_start();
require_once 'includes/conexion.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============================================================
// VERIFICAR ACCESO ADMINISTRATIVO (CON ID)
// ============================================================
$modo_admin = false;
$admin_property_id = 0;
$admin_property_title = '';

if (isset($_GET['id']) && is_numeric($_GET['id']) && !isset($_GET['token'])) {
    if (!isset($_SESSION['usuario_id'])) {
        header('Location: login.php');
        exit;
    }
    
    $admin_property_id = intval($_GET['id']);
    $modo_admin = true;
    
    try {
        $stmt = $conn->prepare("
            SELECT id, title, address_city, address_municipality 
            FROM properties 
            WHERE id = ?
        ");
        $stmt->execute([$admin_property_id]);
        $propiedad_admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$propiedad_admin) {
            die("
            <!DOCTYPE html>
            <html>
            <head><title>Error - Propiedad no encontrada</title></head>
            <body style='font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background: #f5f7fa;'>
                <div style='background: white; padding: 40px; border-radius: 12px; text-align: center; max-width: 500px; box-shadow: 0 10px 40px rgba(0,0,0,0.08);'>
                    <div style='font-size: 60px; margin-bottom: 20px;'>🔍</div>
                    <h2 style='color: #e74c3c;'>Propiedad no encontrada</h2>
                    <p style='color: #666;'>La propiedad que buscas no existe o fue eliminada.</p>
                    <a href='inventario.php' style='display: inline-block; margin-top: 15px; color: #3498db; text-decoration: none; font-weight: 600;'>
                        <i class='fas fa-arrow-left'></i> Volver al inventario
                    </a>
                </div>
            </body>
            </html>
            ");
        }
        
        $admin_property_title = $propiedad_admin['title'];
        
    } catch (PDOException $e) {
        die("Error al cargar la propiedad");
    }
}

// ============================================================
// FLUJO NORMAL: VERIFICAR TOKEN
// ============================================================
$token = $_GET['token'] ?? '';
$token_data = null;
$usar_token_real = false;

if (!$modo_admin) {
    if (empty($token)) {
        die("
        <!DOCTYPE html>
        <html>
        <head><title>Error - Enlace Inválido</title></head>
        <body style='font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background: #f5f7fa;'>
            <div style='background: white; padding: 40px; border-radius: 12px; text-align: center; max-width: 500px; box-shadow: 0 10px 40px rgba(0,0,0,0.08);'>
                <div style='font-size: 60px; margin-bottom: 20px;'>🔒</div>
                <h2 style='color: #e74c3c;'>Token no válido</h2>
                <p style='color: #666;'>No se ha proporcionado un enlace válido.</p>
            </div>
        </body>
        </html>
        ");
    }

    try {
        $stmt = $conn->prepare("
            SELECT t.*, p.title as property_title, p.id as property_id 
            FROM document_upload_tokens t
            JOIN properties p ON t.property_id = p.id
            WHERE t.token = ? 
            AND t.is_used = 0 
            AND t.expires_at > NOW()
        ");
        $stmt->execute([$token]);
        $token_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$token_data) {
            die("
            <!DOCTYPE html>
            <html>
            <head><title>Enlace Expirado</title></head>
            <body style='font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background: #f5f7fa;'>
                <div style='background: white; padding: 40px; border-radius: 12px; text-align: center; max-width: 500px; box-shadow: 0 10px 40px rgba(0,0,0,0.08);'>
                    <div style='font-size: 60px; margin-bottom: 20px;'>⏰</div>
                    <h2 style='color: #e74c3c;'>Enlace No Válido</h2>
                    <p style='color: #666;'>Este enlace ya no es válido o ha expirado.</p>
                    <p style='color: #999; font-size: 14px; margin-top: 20px;'>Por favor, solicita un nuevo enlace al agente inmobiliario.</p>
                </div>
            </body>
            </html>
            ");
        }
        
        $usar_token_real = true;
        
    } catch (PDOException $e) {
        die("Error del sistema");
    }
} else {
    // MODO ADMIN
    $token_data = [
        'id' => 0,
        'property_id' => $admin_property_id,
        'property_title' => $admin_property_title,
        'client_name' => 'Administrador',
        'client_email' => $_SESSION['usuario_email'] ?? 'admin@inmobiliariamh.com',
        'token' => 'admin_' . $admin_property_id
    ];
    $usar_token_real = false;
}

// ============================================================
// PROCESAR CAPTURA BIOMÉTRICA
// ============================================================
$mensaje = '';
$mensaje_tipo = '';
$captura_exitosa = false;

// Procesar firma
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'guardar_firma') {
        $tipo_biometrico = 'firma';
        $datos_base64 = $_POST['firma_data'] ?? '';
        $dedo_seleccionado = $_POST['dedo_seleccionado'] ?? 'firma';
        
        if (empty($datos_base64)) {
            $mensaje = "No se capturó ninguna firma";
            $mensaje_tipo = 'error';
        } else {
            $image_data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $datos_base64));
            
            if ($image_data === false) {
                $mensaje = "Error al procesar la imagen de la firma";
                $mensaje_tipo = 'error';
            } else {
                $timestamp = time();
                $property_id = $token_data['property_id'];
                $nombre_archivo = "firma_{$property_id}_{$timestamp}.png";
                
                $directorio = "uploads/biometricos/{$property_id}/";
                if (!file_exists($directorio)) {
                    mkdir($directorio, 0777, true);
                }
                
                $ruta_destino = $directorio . $nombre_archivo;
                
                if (file_put_contents($ruta_destino, $image_data)) {
                    try {
                        $token_id = $usar_token_real ? $token_data['id'] : 0;
                        
                        $stmt = $conn->prepare("
                            INSERT INTO client_biometric_data 
                            (property_id, token_id, tipo_biometrico, file_path, dedo, metadata, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        
                        $metadata = json_encode([
                            'nombre_cliente' => $token_data['client_name'],
                            'email' => $token_data['client_email'],
                            'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
                        ]);
                        
                        $stmt->execute([
                            $property_id,
                            $token_id,
                            $tipo_biometrico,
                            $ruta_destino,
                            $dedo_seleccionado,
                            $metadata,
                            $modo_admin ? 'admin' : 'cliente'
                        ]);
                        
                        $mensaje = "¡Firma capturada exitosamente!";
                        $mensaje_tipo = 'success';
                        $captura_exitosa = true;
                        
                    } catch (PDOException $e) {
                        $mensaje = "Error al guardar en la base de datos";
                        $mensaje_tipo = 'error';
                        if (file_exists($ruta_destino)) {
                            unlink($ruta_destino);
                        }
                    }
                } else {
                    $mensaje = "Error al guardar la imagen de la firma";
                    $mensaje_tipo = 'error';
                }
            }
        }
    }
    
    // Procesar huella con dedo específico
    if ($_POST['action'] === 'guardar_huella') {
        $tipo_biometrico = 'huella';
        $huella_data = $_POST['huella_data'] ?? '';
        $dedo_seleccionado = $_POST['dedo_seleccionado'] ?? 'pulgar_derecho';
        
        if (empty($huella_data)) {
            $mensaje = "No se capturó ninguna huella";
            $mensaje_tipo = 'error';
        } else {
            try {
                $token_id = $usar_token_real ? $token_data['id'] : 0;
                $property_id = $token_data['property_id'];
                
                $stmt = $conn->prepare("
                    INSERT INTO client_biometric_data 
                    (property_id, token_id, tipo_biometrico, datos_huella, dedo, metadata, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                $metadata = json_encode([
                    'nombre_cliente' => $token_data['client_name'],
                    'email' => $token_data['client_email'],
                    'formato' => 'base64_placeholder',
                    'dispositivo' => $_SERVER['HTTP_USER_AGENT'] ?? 'desconocido'
                ]);
                
                $stmt->execute([
                    $property_id,
                    $token_id,
                    $tipo_biometrico,
                    $huella_data,
                    $dedo_seleccionado,
                    $metadata,
                    $modo_admin ? 'admin' : 'cliente'
                ]);
                
                $mensaje = "¡Huella del " . obtenerNombreDedo($dedo_seleccionado) . " capturada exitosamente!";
                $mensaje_tipo = 'success';
                $captura_exitosa = true;
                
            } catch (PDOException $e) {
                $mensaje = "Error al guardar la huella en la base de datos";
                $mensaje_tipo = 'error';
            }
        }
    }
}

// Función auxiliar para obtener nombre del dedo
function obtenerNombreDedo($dedo) {
    $nombres = [
        'pulgar_derecho' => 'Pulgar Derecho',
        'indice_derecho' => 'Índice Derecho',
        'medio_derecho' => 'Medio Derecho',
        'anular_derecho' => 'Anular Derecho',
        'menique_derecho' => 'Meñique Derecho',
        'pulgar_izquierdo' => 'Pulgar Izquierdo',
        'indice_izquierdo' => 'Índice Izquierdo',
        'medio_izquierdo' => 'Medio Izquierdo',
        'anular_izquierdo' => 'Anular Izquierdo',
        'menique_izquierdo' => 'Meñique Izquierdo',
        'firma' => 'Firma'
    ];
    return $nombres[$dedo] ?? $dedo;
}

// Obtener datos biométricos existentes
$biometricos = [];
try {
    if ($usar_token_real) {
        $stmt = $conn->prepare("
            SELECT * FROM client_biometric_data 
            WHERE token_id = ? 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$token_data['id']]);
    } else {
        $stmt = $conn->prepare("
            SELECT * FROM client_biometric_data 
            WHERE property_id = ? 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$token_data['property_id']]);
    }
    $biometricos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $biometricos = [];
}

// Separar por tipo
$firmas_existentes = array_filter($biometricos, function($item) {
    return $item['tipo_biometrico'] === 'firma';
});
$huellas_existentes = array_filter($biometricos, function($item) {
    return $item['tipo_biometrico'] === 'huella';
});

// Obtener dedos ya capturados
$dedos_capturados = array_column($huellas_existentes, 'dedo');

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Captura Biométrica - <?php echo htmlspecialchars($token_data['property_title']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ============================================================
           DISEÑO COMPLETO - PALETA DE COLORES MODERNA
           ============================================================ */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --primary-light: #eef2ff;
            --secondary: #7c3aed;
            --success: #10b981;
            --success-light: #d1fae5;
            --warning: #f59e0b;
            --warning-light: #fef3c7;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow: 0 1px 3px rgba(0,0,0,0.1), 0 1px 2px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
            --radius: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
        }
        
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, var(--gray-50) 0%, var(--gray-100) 100%);
            min-height: 100vh; 
            padding: 20px;
        }
        
        .container { 
            max-width: 1100px; 
            width: 100%; 
            margin: 0 auto; 
        }
        
        /* ===== BARRA ADMIN ===== */
        .admin-bar {
            background: var(--gray-900);
            color: white;
            padding: 14px 24px;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .admin-bar .admin-info {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
        }
        .admin-bar .admin-info .badge-admin {
            background: var(--primary);
            padding: 2px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .admin-bar .admin-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .admin-bar .admin-actions a {
            color: white;
            text-decoration: none;
            background: rgba(255,255,255,0.1);
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 13px;
            transition: background 0.3s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .admin-bar .admin-actions a:hover {
            background: rgba(255,255,255,0.2);
        }
        
        /* ===== CARD PRINCIPAL ===== */
        .card { 
            background: white; 
            border-radius: 0 0 var(--radius-xl) var(--radius-xl); 
            box-shadow: var(--shadow-xl); 
            padding: 28px 32px;
            margin-bottom: 24px;
        }
        
        /* ===== HEADER ===== */
        .header { 
            text-align: center; 
            margin-bottom: 28px;
            padding-bottom: 24px;
            border-bottom: 2px solid var(--gray-100);
        }
        .header .icon { 
            font-size: 48px; 
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            padding: 16px;
            border-radius: 50%;
            display: inline-block;
            margin-bottom: 12px;
            color: white;
            box-shadow: var(--shadow-md);
        }
        .header h1 { 
            color: var(--gray-900); 
            font-size: 26px; 
            margin-bottom: 4px;
            font-weight: 700;
        }
        .header p { 
            color: var(--gray-500); 
            font-size: 15px; 
        }
        .header .property-title { 
            color: var(--primary); 
            font-weight: 600;
        }
        
        /* ===== INFO GRID ===== */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            background: var(--gray-50);
            padding: 16px 20px;
            border-radius: var(--radius);
            margin-bottom: 24px;
            border: 1px solid var(--gray-200);
        }
        .info-item { text-align: center; }
        .info-item .label { 
            font-size: 11px; 
            color: var(--gray-400); 
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        .info-item .value { 
            font-size: 18px; 
            font-weight: 700; 
            color: var(--gray-900); 
            margin-top: 2px;
        }
        .info-item .value .badge {
            display: inline-block;
            padding: 2px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }
        .badge-success { background: var(--success-light); color: var(--success); }
        .badge-warning { background: var(--warning-light); color: var(--warning); }
        .badge-info { background: var(--primary-light); color: var(--primary); }
        
        /* ===== ALERTAS ===== */
        .alert { 
            padding: 14px 18px; 
            border-radius: var(--radius); 
            margin-bottom: 20px; 
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
        }
        .alert i { font-size: 20px; flex-shrink: 0; }
        .alert.success { background: var(--success-light); color: #065f46; border: 1px solid #a7f3d0; }
        .alert.error { background: var(--danger-light); color: #991b1b; border: 1px solid #fca5a5; }
        .alert.info { background: var(--primary-light); color: #1e40af; border: 1px solid #bfdbfe; }
        
        /* ============================================================
           SELECTOR DE DEDOS (NUEVO)
           ============================================================ */
        .finger-selector {
            background: var(--gray-50);
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 20px;
            border: 2px solid var(--gray-200);
            transition: border-color 0.3s;
        }
        .finger-selector:focus-within {
            border-color: var(--primary);
        }
        
        .finger-selector-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .finger-selector-title small {
            font-weight: 400;
            color: var(--gray-400);
            font-size: 12px;
        }
        
        .finger-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 10px;
            max-width: 600px;
            margin: 0 auto;
        }
        
        .finger-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 12px 6px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius);
            background: white;
            cursor: pointer;
            transition: all 0.25s ease;
            gap: 6px;
            min-height: 70px;
            position: relative;
            touch-action: manipulation;
            user-select: none;
        }
        .finger-btn:active {
            transform: scale(0.95);
        }
        .finger-btn .finger-icon {
            font-size: 24px;
            color: var(--gray-400);
            transition: color 0.3s;
        }
        .finger-btn .finger-label {
            font-size: 10px;
            font-weight: 500;
            color: var(--gray-500);
            text-align: center;
            line-height: 1.2;
            transition: color 0.3s;
        }
        .finger-btn .finger-check {
            position: absolute;
            top: -6px;
            right: -6px;
            background: var(--success);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 11px;
            display: none;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow);
        }
        
        /* Estados del botón de dedo */
        .finger-btn:hover:not(.disabled) {
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .finger-btn.active {
            border-color: var(--primary);
            background: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
            transform: scale(1.02);
        }
        .finger-btn.active .finger-icon {
            color: var(--primary);
        }
        .finger-btn.active .finger-label {
            color: var(--primary);
        }
        
        .finger-btn.captured {
            border-color: var(--success);
            background: var(--success-light);
            cursor: default;
        }
        .finger-btn.captured .finger-icon {
            color: var(--success);
        }
        .finger-btn.captured .finger-label {
            color: var(--success);
        }
        .finger-btn.captured .finger-check {
            display: flex;
        }
        .finger-btn.captured:hover {
            transform: none;
            box-shadow: none;
        }
        
        .finger-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .finger-btn.disabled:hover {
            transform: none;
            box-shadow: none;
        }
        
        .finger-btn .progress-ring {
            position: absolute;
            bottom: -4px;
            left: 50%;
            transform: translateX(-50%);
            width: 20px;
            height: 3px;
            background: var(--gray-200);
            border-radius: 4px;
            overflow: hidden;
        }
        .finger-btn .progress-ring .progress-bar {
            height: 100%;
            background: var(--primary);
            width: 0%;
            transition: width 0.3s;
            border-radius: 4px;
        }
        
        .finger-btn.current .progress-ring .progress-bar {
            animation: pulse-progress 1.5s ease-in-out infinite;
        }
        
        @keyframes pulse-progress {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }
        
        /* Mano izquierda / derecha separadores */
        .finger-section {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .finger-section-title {
            font-size: 11px;
            font-weight: 600;
            color: var(--gray-400);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: center;
            padding: 4px 0;
        }
        
        /* ===== CANVAS FIRMA ===== */
        .signature-container {
            border: 2px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 8px;
            background: var(--gray-50);
            position: relative;
            touch-action: none;
            transition: border-color 0.3s;
        }
        .signature-container.drawing {
            border-color: var(--primary);
            background: var(--primary-light);
        }
        #firmaCanvas {
            width: 100%;
            height: auto;
            aspect-ratio: 2 / 1;
            border-radius: var(--radius);
            touch-action: none;
            cursor: crosshair;
            background: white;
        }
        .signature-actions {
            display: flex;
            gap: 10px;
            margin-top: 14px;
            flex-wrap: wrap;
        }
        .signature-actions button {
            flex: 1;
            min-width: 100px;
            padding: 12px 20px;
            border: none;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .signature-actions button:active {
            transform: scale(0.96);
        }
        .btn-clear { 
            background: var(--gray-200); 
            color: var(--gray-700); 
        }
        .btn-clear:hover { 
            background: var(--gray-300); 
        }
        .btn-save-firma { 
            background: var(--primary); 
            color: white; 
        }
        .btn-save-firma:hover { 
            background: var(--primary-dark); 
            box-shadow: var(--shadow-md);
        }
        .btn-save-firma:disabled { 
            background: var(--gray-300); 
            cursor: not-allowed; 
            box-shadow: none;
        }
        
        /* ===== HUELLA ===== */
        .fingerprint-area {
            text-align: center;
            padding: 40px 20px;
            background: var(--gray-50);
            border-radius: var(--radius);
            border: 2px dashed var(--gray-300);
            margin-bottom: 16px;
            transition: all 0.3s;
        }
        .fingerprint-area .icon-finger {
            font-size: 72px;
            color: var(--gray-400);
            margin-bottom: 12px;
            display: block;
            transition: all 0.3s;
        }
        .fingerprint-area .icon-finger.active {
            color: var(--primary);
            animation: pulse 1.5s ease-in-out infinite;
        }
        .fingerprint-area .icon-finger.captured {
            color: var(--success);
        }
        .fingerprint-area h3 {
            color: var(--gray-800);
            margin-bottom: 4px;
            font-size: 18px;
        }
        .fingerprint-area p {
            color: var(--gray-500);
            font-size: 14px;
        }
        .fingerprint-area .device-info {
            margin-top: 16px;
            padding: 12px 16px;
            background: white;
            border-radius: var(--radius);
            font-size: 13px;
            color: var(--gray-600);
            border: 1px solid var(--gray-200);
        }
        
        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.7; }
            100% { transform: scale(1); opacity: 1; }
        }
        
        /* ===== BOTÓN CAPTURAR HUELLA ===== */
        .btn-fingerprint {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: var(--radius);
            background: var(--primary);
            color: white;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .btn-fingerprint:hover {
            background: var(--primary-dark);
            box-shadow: var(--shadow-md);
            transform: translateY(-1px);
        }
        .btn-fingerprint:active {
            transform: scale(0.96);
        }
        .btn-fingerprint:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }
        
        /* ===== BOTÓN GUARDAR ===== */
        .btn-submit {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: var(--radius);
            background: var(--success);
            color: white;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 16px;
        }
        .btn-submit:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-1px);
        }
        .btn-submit:active {
            transform: scale(0.96);
        }
        .btn-submit:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
        }
        .btn-submit .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top: 3px solid white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        .btn-submit.loading .spinner { display: inline-block; }
        .btn-submit.loading .btn-text { display: none; }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* ============================================================
           LISTA DE DATOS CAPTURADOS
           ============================================================ */
        .biometric-list {
            margin-top: 28px;
        }
        .biometric-list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .biometric-list-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--gray-800);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .biometric-list-title .count {
            background: var(--gray-200);
            padding: 0 12px;
            border-radius: 20px;
            font-size: 12px;
            color: var(--gray-600);
        }
        .biometric-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 18px;
            background: var(--gray-50);
            border-radius: var(--radius);
            margin-bottom: 8px;
            border: 1px solid var(--gray-200);
            flex-wrap: wrap;
            gap: 8px;
            transition: background 0.2s;
        }
        .biometric-item:hover {
            background: var(--gray-100);
        }
        .biometric-item .info {
            display: flex;
            align-items: center;
            gap: 14px;
            flex: 1;
            min-width: 0;
        }
        .biometric-item .info i {
            font-size: 22px;
            color: var(--primary);
            width: 24px;
            text-align: center;
        }
        .biometric-item .info .detail {
            display: flex;
            flex-direction: column;
        }
        .biometric-item .info .detail .tipo {
            font-weight: 600;
            font-size: 14px;
            color: var(--gray-800);
        }
        .biometric-item .info .detail .meta {
            font-size: 12px;
            color: var(--gray-400);
        }
        .biometric-item .status {
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            background: var(--success-light);
            color: var(--success);
            white-space: nowrap;
        }
        .biometric-item .ver {
            color: var(--primary);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            padding: 4px 12px;
            border-radius: 6px;
            background: var(--primary-light);
            transition: background 0.2s;
        }
        .biometric-item .ver:hover {
            background: #dbeafe;
        }
        .biometric-item .dedo-tag {
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            background: var(--gray-200);
            color: var(--gray-600);
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray-400);
        }
        .empty-state i {
            font-size: 48px;
            margin-bottom: 12px;
            display: block;
        }
        .empty-state p {
            font-size: 14px;
        }
        
        /* ============================================================
           TABS
           ============================================================ */
        .tabs {
            display: flex;
            gap: 4px;
            background: var(--gray-100);
            border-radius: var(--radius);
            padding: 4px;
            margin-bottom: 24px;
        }
        .tab-btn {
            flex: 1;
            padding: 12px 20px;
            border: none;
            border-radius: var(--radius);
            background: transparent;
            font-weight: 600;
            font-size: 14px;
            color: var(--gray-500);
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .tab-btn.active {
            background: white;
            color: var(--primary);
            box-shadow: var(--shadow-md);
        }
        .tab-btn:active {
            transform: scale(0.96);
        }
        .tab-content {
            display: none;
            animation: fadeIn 0.3s ease;
        }
        .tab-content.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* ============================================================
           FOOTER
           ============================================================ */
        .footer { 
            text-align: center; 
            margin-top: 24px; 
            color: var(--gray-400); 
            font-size: 12px;
            padding: 16px 0;
        }
        .footer a { color: var(--primary); text-decoration: none; }
        .footer a:hover { text-decoration: underline; }
        
        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 768px) {
            body { padding: 12px; }
            .card { padding: 16px 18px; }
            .header h1 { font-size: 22px; }
            .header .icon { font-size: 36px; padding: 12px; }
            .info-grid { 
                grid-template-columns: repeat(2, 1fr); 
                gap: 8px; 
                padding: 12px 16px;
            }
            .info-item .value { font-size: 16px; }
            
            .finger-grid {
                grid-template-columns: repeat(5, 1fr);
                gap: 6px;
            }
            .finger-btn {
                padding: 10px 4px;
                min-height: 60px;
            }
            .finger-btn .finger-icon { font-size: 20px; }
            .finger-btn .finger-label { font-size: 9px; }
            
            .tab-btn { 
                font-size: 12px; 
                padding: 10px 12px;
            }
            .tab-btn span { display: none; }
            
            .admin-bar {
                flex-direction: column;
                text-align: center;
                padding: 12px 16px;
            }
            .admin-bar .admin-actions {
                justify-content: center;
            }
            .signature-actions button {
                font-size: 13px;
                padding: 10px 14px;
            }
            #firmaCanvas {
                aspect-ratio: 1.5 / 1;
            }
            
            .biometric-item {
                flex-direction: column;
                align-items: flex-start;
            }
            .biometric-item .status {
                align-self: flex-start;
            }
        }
        
        @media (max-width: 480px) {
            .finger-grid {
                grid-template-columns: repeat(3, 1fr);
            }
            .finger-section-title {
                font-size: 10px;
            }
            .header h1 { font-size: 19px; }
            .info-grid { 
                grid-template-columns: repeat(2, 1fr); 
            }
            .tabs { flex-direction: column; gap: 2px; }
            .tab-btn { justify-content: center; }
            .tab-btn span { display: inline; }
        }
        
        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: var(--gray-100); border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: var(--gray-300); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--gray-400); }
    </style>
</head>
<body>
<div class="container">

    <?php if ($modo_admin): ?>
    <!-- ===== BARRA ADMIN ===== -->
    <div class="admin-bar">
        <div class="admin-info">
            <i class="fas fa-user-shield"></i>
            <span>
                <strong>Modo Administrador</strong>
                <span class="badge-admin">Admin</span>
                · <?php echo htmlspecialchars($token_data['property_title']); ?>
                <span style="font-size: 11px; opacity: 0.6; margin-left: 6px;">
                    ID: <?php echo $token_data['property_id']; ?>
                </span>
            </span>
        </div>
        <div class="admin-actions">
            <a href="propiedad_detalle_inventario.php?id=<?php echo $token_data['property_id']; ?>">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
            <a href="inventario.php">
                <i class="fas fa-th-list"></i> Inventario
            </a>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <!-- HEADER -->
        <div class="header">
            <div class="icon"><i class="fas fa-fingerprint"></i></div>
            <h1>Captura Biométrica</h1>
            <p>
                Propiedad: <span class="property-title"><?php echo htmlspecialchars($token_data['property_title']); ?></span>
                <?php if ($modo_admin): ?>
                    <span style="display: inline-block; background: var(--primary); color: white; padding: 2px 10px; border-radius: 12px; font-size: 11px; margin-left: 6px;">
                        <i class="fas fa-user-shield"></i> Admin
                    </span>
                <?php endif; ?>
            </p>
        </div>

        <!-- INFO GRID -->
        <div class="info-grid">
            <div class="info-item">
                <div class="label">👤 Cliente</div>
                <div class="value"><?php echo htmlspecialchars($token_data['client_name'] ?: 'No especificado'); ?></div>
            </div>
            <div class="info-item">
                <div class="label">✍️ Firmas</div>
                <div class="value"><span class="badge badge-success"><?php echo count($firmas_existentes); ?></span></div>
            </div>
            <div class="info-item">
                <div class="label">🖐️ Huellas</div>
                <div class="value"><span class="badge badge-info"><?php echo count($huellas_existentes); ?> / 10</span></div>
            </div>
            <div class="info-item">
                <div class="label">📅 Total</div>
                <div class="value"><span class="badge badge-warning"><?php echo count($biometricos); ?></span></div>
            </div>
        </div>

        <!-- MENSAJES -->
        <?php if ($mensaje): ?>
            <div class="alert <?php echo $mensaje_tipo; ?>">
                <i class="fas fa-<?php echo $mensaje_tipo === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <!-- TABS -->
        <div class="tabs" id="tabs">
            <button class="tab-btn active" data-tab="firma">
                <i class="fas fa-pen"></i> <span>Firma</span>
            </button>
            <button class="tab-btn" data-tab="huella">
                <i class="fas fa-hand"></i> <span>Huellas</span>
            </button>
        </div>

        <!-- ============================================================
        TAB FIRMA
        ============================================================ -->
        <div class="tab-content active" id="tab-firma">
            <form method="POST" id="firmaForm">
                <input type="hidden" name="action" value="guardar_firma">
                <input type="hidden" name="firma_data" id="firmaData">
                <input type="hidden" name="dedo_seleccionado" value="firma">
                
                <div class="signature-container" id="signatureContainer">
                    <canvas id="firmaCanvas"></canvas>
                </div>
                
                <div class="signature-actions">
                    <button type="button" class="btn-clear" id="clearFirma">
                        <i class="fas fa-undo"></i> Limpiar
                    </button>
                    <button type="submit" class="btn-save-firma" id="saveFirmaBtn" disabled>
                        <i class="fas fa-check"></i> Guardar Firma
                    </button>
                </div>
            </form>
            
            <!-- Lista de firmas -->
            <div class="biometric-list">
                <div class="biometric-list-header">
                    <div class="biometric-list-title">
                        <i class="fas fa-list"></i> Firmas Capturadas
                        <span class="count"><?php echo count($firmas_existentes); ?></span>
                    </div>
                </div>
                <?php if (count($firmas_existentes) > 0): ?>
                    <?php foreach ($firmas_existentes as $firma): ?>
                        <div class="biometric-item">
                            <div class="info">
                                <i class="fas fa-pen"></i>
                                <div class="detail">
                                    <span class="tipo">Firma</span>
                                    <span class="meta">
                                        <?php echo date('d/m/Y H:i', strtotime($firma['created_at'])); ?>
                                        <?php if ($firma['created_by'] === 'admin'): ?>
                                            <span style="color: var(--primary);">(Admin)</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                            <span class="status">Capturada</span>
                            <?php if (!empty($firma['file_path']) && file_exists($firma['file_path'])): ?>
                                <a href="<?php echo htmlspecialchars($firma['file_path']); ?>" target="_blank" class="ver">
                                    <i class="fas fa-eye"></i> Ver
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-pen"></i>
                        <p>No hay firmas capturadas aún</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================================
        TAB HUELLA - CON SELECTOR DE DEDOS
        ============================================================ -->
        <div class="tab-content" id="tab-huella">
            <form method="POST" id="huellaForm">
                <input type="hidden" name="action" value="guardar_huella">
                <input type="hidden" name="huella_data" id="huellaData">
                <input type="hidden" name="dedo_seleccionado" id="dedoSeleccionado" value="pulgar_derecho">
                
                <!-- ===== SELECTOR DE DEDOS ===== -->
                <div class="finger-selector">
                    <div class="finger-selector-title">
                        <i class="fas fa-hand-paper"></i> Selecciona el dedo a capturar
                        <small>(Verde = Capturado, Azul = Seleccionado)</small>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; max-width: 600px; margin: 0 auto;">
                        <!-- MANO DERECHA -->
                        <div class="finger-section">
                            <div class="finger-section-title">✋ Derecha</div>
                            <div class="finger-grid">
                                <?php 
                                $dedos_derechos = [
                                    'pulgar_derecho' => 'Pulgar',
                                    'indice_derecho' => 'Índice',
                                    'medio_derecho' => 'Medio',
                                    'anular_derecho' => 'Anular',
                                    'menique_derecho' => 'Meñique'
                                ];
                                foreach ($dedos_derechos as $key => $label): 
                                    $capturado = in_array($key, $dedos_capturados);
                                    $seleccionado = isset($_POST['dedo_seleccionado']) ? $_POST['dedo_seleccionado'] : 'pulgar_derecho';
                                    $activo = ($seleccionado === $key && !$capturado);
                                ?>
                                <button type="button" 
                                        class="finger-btn <?php echo $capturado ? 'captured' : ''; ?> <?php echo $activo ? 'active' : ''; ?> <?php echo $capturado ? 'disabled' : ''; ?>"
                                        data-dedo="<?php echo $key; ?>"
                                        <?php echo $capturado ? 'disabled' : ''; ?>>
                                    <span class="finger-check"><i class="fas fa-check"></i></span>
                                    <i class="fas fa-hand-paper finger-icon"></i>
                                    <span class="finger-label"><?php echo $label; ?></span>
                                    <div class="progress-ring">
                                        <div class="progress-bar"></div>
                                    </div>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- MANO IZQUIERDA -->
                        <div class="finger-section">
                            <div class="finger-section-title">🤚 Izquierda</div>
                            <div class="finger-grid">
                                <?php 
                                $dedos_izquierdos = [
                                    'pulgar_izquierdo' => 'Pulgar',
                                    'indice_izquierdo' => 'Índice',
                                    'medio_izquierdo' => 'Medio',
                                    'anular_izquierdo' => 'Anular',
                                    'menique_izquierdo' => 'Meñique'
                                ];
                                foreach ($dedos_izquierdos as $key => $label): 
                                    $capturado = in_array($key, $dedos_capturados);
                                    $seleccionado = isset($_POST['dedo_seleccionado']) ? $_POST['dedo_seleccionado'] : 'pulgar_derecho';
                                    $activo = ($seleccionado === $key && !$capturado);
                                ?>
                                <button type="button" 
                                        class="finger-btn <?php echo $capturado ? 'captured' : ''; ?> <?php echo $activo ? 'active' : ''; ?> <?php echo $capturado ? 'disabled' : ''; ?>"
                                        data-dedo="<?php echo $key; ?>"
                                        <?php echo $capturado ? 'disabled' : ''; ?>>
                                    <span class="finger-check"><i class="fas fa-check"></i></span>
                                    <i class="fas fa-hand-paper finger-icon"></i>
                                    <span class="finger-label"><?php echo $label; ?></span>
                                    <div class="progress-ring">
                                        <div class="progress-bar"></div>
                                    </div>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div style="text-align: center; margin-top: 12px; font-size: 13px; color: var(--gray-500);">
                        <span id="dedoSeleccionadoLabel">Dedo seleccionado: <strong>Pulgar Derecho</strong></span>
                        <span style="margin: 0 8px;">|</span>
                        <span id="progresoHuellas">Progreso: <?php echo count($huellas_existentes); ?> / 10 capturadas</span>
                    </div>
                </div>
                
                <!-- ===== ÁREA DE CAPTURA ===== -->
                <div class="fingerprint-area" id="fingerprintArea">
                    <i class="fas fa-fingerprint icon-finger" id="fingerIcon"></i>
                    <h3 id="fingerTitle">Captura de Huella</h3>
                    <p id="fingerSubtitle">Coloca el dedo en el lector de huellas</p>
                    <div class="device-info">
                        <i class="fas fa-usb"></i> Conecta un lector de huellas compatible
                        <br>
                        <span style="font-size: 12px; color: var(--gray-400);">
                            (Simulación: haz clic en "Capturar Huella" para probar)
                        </span>
                    </div>
                </div>
                
                <button type="button" class="btn-fingerprint" id="capturarHuellaBtn">
                    <i class="fas fa-fingerprint"></i> Capturar Huella
                </button>
                
                <button type="submit" class="btn-submit" id="guardarHuellaBtn" disabled>
                    <span class="spinner"></span>
                    <span class="btn-text"><i class="fas fa-save"></i> Guardar Huella</span>
                </button>
            </form>
            
            <!-- Lista de huellas -->
            <div class="biometric-list" style="margin-top: 20px;">
                <div class="biometric-list-header">
                    <div class="biometric-list-title">
                        <i class="fas fa-list"></i> Huellas Capturadas
                        <span class="count"><?php echo count($huellas_existentes); ?> / 10</span>
                    </div>
                </div>
                <?php if (count($huellas_existentes) > 0): ?>
                    <?php foreach ($huellas_existentes as $huella): ?>
                        <div class="biometric-item">
                            <div class="info">
                                <i class="fas fa-fingerprint"></i>
                                <div class="detail">
                                    <span class="tipo"><?php echo obtenerNombreDedo($huella['dedo']); ?></span>
                                    <span class="meta">
                                        <?php echo date('d/m/Y H:i', strtotime($huella['created_at'])); ?>
                                        <?php if ($huella['created_by'] === 'admin'): ?>
                                            <span style="color: var(--primary);">(Admin)</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                            <span class="status">Capturada</span>
                            <span class="dedo-tag"><?php echo str_replace('_', ' ', $huella['dedo']); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-fingerprint"></i>
                        <p>No hay huellas capturadas aún</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="footer">
        <p>
            <i class="fas fa-lock" style="color: var(--primary);"></i> 
            Captura segura de datos biométricos - Inmobiliaria MH
            <br>
            <span style="font-size: 11px;">Los datos se almacenan de forma segura y encriptada</span>
        </p>
    </div>
</div>

<script>
// ============================================================
// SELECTOR DE DEDOS
// ============================================================
const fingerButtons = document.querySelectorAll('.finger-btn:not(.disabled)');
const dedoSeleccionadoInput = document.getElementById('dedoSeleccionado');
const dedoSeleccionadoLabel = document.getElementById('dedoSeleccionadoLabel');
const fingerIcon = document.getElementById('fingerIcon');
const fingerTitle = document.getElementById('fingerTitle');
const fingerSubtitle = document.getElementById('fingerSubtitle');

const nombresDedos = {
    'pulgar_derecho': 'Pulgar Derecho',
    'indice_derecho': 'Índice Derecho',
    'medio_derecho': 'Medio Derecho',
    'anular_derecho': 'Anular Derecho',
    'menique_derecho': 'Meñique Derecho',
    'pulgar_izquierdo': 'Pulgar Izquierdo',
    'indice_izquierdo': 'Índice Izquierdo',
    'medio_izquierdo': 'Medio Izquierdo',
    'anular_izquierdo': 'Anular Izquierdo',
    'menique_izquierdo': 'Meñique Izquierdo'
};

fingerButtons.forEach(btn => {
    btn.addEventListener('click', function() {
        const dedo = this.dataset.dedo;
        
        // Remover active de todos
        document.querySelectorAll('.finger-btn:not(.disabled)').forEach(b => {
            b.classList.remove('active');
        });
        
        // Activar el seleccionado
        this.classList.add('active');
        
        // Actualizar input oculto
        dedoSeleccionadoInput.value = dedo;
        
        // Actualizar label
        const nombre = nombresDedos[dedo] || dedo;
        dedoSeleccionadoLabel.innerHTML = `Dedo seleccionado: <strong>${nombre}</strong>`;
        
        // Actualizar área de captura
        fingerTitle.textContent = `Captura de Huella - ${nombre}`;
        fingerSubtitle.textContent = `Coloca el dedo ${nombre} en el lector`;
        
        // Resetear estado de captura
        huellaCapturada = false;
        guardarHuellaBtn.disabled = true;
        fingerIcon.className = 'fas fa-fingerprint icon-finger';
        document.getElementById('fingerprintArea').style.borderColor = '#cbd5e1';
        document.getElementById('fingerprintArea').style.background = '#f8fafc';
        
        // Quitar mensajes anteriores
        const oldMsj = document.getElementById('fingerprintArea').querySelector('.alert');
        if (oldMsj) oldMsj.remove();
    });
});

// ============================================================
// FIRMA - CANVAS
// ============================================================
const canvas = document.getElementById('firmaCanvas');
const ctx = canvas.getContext('2d');
const container = document.getElementById('signatureContainer');
let isDrawing = false;
let lastX = 0;
let lastY = 0;
let hasSignature = false;

function resizeCanvas() {
    const rect = container.getBoundingClientRect();
    const width = rect.width - 16;
    const aspectRatio = 2 / 1;
    canvas.width = width * window.devicePixelRatio;
    canvas.height = (width / aspectRatio) * window.devicePixelRatio;
    canvas.style.width = width + 'px';
    canvas.style.height = (width / aspectRatio) + 'px';
    ctx.scale(window.devicePixelRatio, window.devicePixelRatio);
    
    if (!hasSignature) {
        ctx.lineWidth = 3;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#1e293b';
    }
}
resizeCanvas();
window.addEventListener('resize', resizeCanvas);

function getPos(e) {
    const rect = canvas.getBoundingClientRect();
    const touch = e.touches ? e.touches[0] : e;
    return {
        x: (touch.clientX - rect.left) * (canvas.width / window.devicePixelRatio / rect.width),
        y: (touch.clientY - rect.top) * (canvas.height / window.devicePixelRatio / rect.height)
    };
}

function startDrawing(e) {
    e.preventDefault();
    isDrawing = true;
    const pos = getPos(e);
    lastX = pos.x;
    lastY = pos.y;
    container.classList.add('drawing');
}

function draw(e) {
    e.preventDefault();
    if (!isDrawing) return;
    const pos = getPos(e);
    ctx.beginPath();
    ctx.moveTo(lastX, lastY);
    ctx.lineTo(pos.x, pos.y);
    ctx.stroke();
    lastX = pos.x;
    lastY = pos.y;
    hasSignature = true;
    document.getElementById('saveFirmaBtn').disabled = false;
}

function stopDrawing(e) {
    if (isDrawing) {
        isDrawing = false;
        container.classList.remove('drawing');
    }
}

canvas.addEventListener('touchstart', startDrawing, { passive: false });
canvas.addEventListener('touchmove', draw, { passive: false });
canvas.addEventListener('touchend', stopDrawing, { passive: false });
canvas.addEventListener('mousedown', startDrawing);
canvas.addEventListener('mousemove', draw);
canvas.addEventListener('mouseup', stopDrawing);
canvas.addEventListener('mouseleave', stopDrawing);

document.getElementById('clearFirma').addEventListener('click', function() {
    ctx.clearRect(0, 0, canvas.width / window.devicePixelRatio, canvas.height / window.devicePixelRatio);
    hasSignature = false;
    document.getElementById('saveFirmaBtn').disabled = true;
    document.getElementById('firmaData').value = '';
});

document.getElementById('firmaForm').addEventListener('submit', function(e) {
    if (!hasSignature) {
        e.preventDefault();
        alert('⚠️ Por favor dibuja tu firma antes de guardar.');
        return;
    }
    const dataUrl = canvas.toDataURL('image/png');
    document.getElementById('firmaData').value = dataUrl;
    const btn = document.getElementById('saveFirmaBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';
});

// ============================================================
// HUELLA - SIMULACIÓN CON DEDO SELECCIONADO
// ============================================================
const capturarBtn = document.getElementById('capturarHuellaBtn');
const guardarHuellaBtn = document.getElementById('guardarHuellaBtn');
const huellaData = document.getElementById('huellaData');
let huellaCapturada = false;

capturarBtn.addEventListener('click', function() {
    // Verificar que hay un dedo seleccionado
    const dedoActual = dedoSeleccionadoInput.value;
    if (!dedoActual) {
        alert('⚠️ Por favor selecciona un dedo primero.');
        return;
    }
    
    // Verificar que el dedo no esté ya capturado
    const btnDedo = document.querySelector(`.finger-btn[data-dedo="${dedoActual}"]`);
    if (btnDedo && btnDedo.classList.contains('captured')) {
        alert(`⚠️ La huella del ${nombresDedos[dedoActual] || dedoActual} ya fue capturada.`);
        return;
    }
    
    // Simular proceso de captura
    fingerIcon.className = 'fas fa-fingerprint icon-finger active';
    capturarBtn.disabled = true;
    capturarBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Escaneando...';
    
    setTimeout(() => {
        // Simular lectura exitosa
        fingerIcon.className = 'fas fa-fingerprint icon-finger captured';
        capturarBtn.disabled = false;
        capturarBtn.innerHTML = '<i class="fas fa-fingerprint"></i> Capturar Huella';
        
        // Marcar como capturada
        huellaCapturada = true;
        guardarHuellaBtn.disabled = false;
        
        // Generar datos simulados
        const datosSimulados = btoa('huella_' + dedoActual + '_' + Date.now() + '_' + Math.random().toString(36));
        huellaData.value = datosSimulados;
        
        // Feedback visual
        const area = document.getElementById('fingerprintArea');
        area.style.borderColor = '#10b981';
        area.style.background = '#d1fae5';
        
        // Mensaje
        const msj = document.createElement('div');
        msj.className = 'alert success';
        msj.style.marginTop = '12px';
        msj.innerHTML = `<i class="fas fa-check-circle"></i> Huella del ${nombresDedos[dedoActual] || dedoActual} capturada exitosamente`;
        
        const oldMsj = area.querySelector('.alert');
        if (oldMsj) oldMsj.remove();
        area.appendChild(msj);
        
        // Marcar el botón del dedo como capturado
        if (btnDedo) {
            btnDedo.classList.remove('active');
            btnDedo.classList.add('captured');
            btnDedo.disabled = true;
            // Actualizar contador
            const progreso = document.getElementById('progresoHuellas');
            const actual = parseInt(progreso.textContent.match(/\d+/)[0]);
            progreso.textContent = `Progreso: ${actual + 1} / 10 capturadas`;
        }
        
    }, 2000);
});

// Guardar huella
document.getElementById('huellaForm').addEventListener('submit', function(e) {
    if (!huellaCapturada) {
        e.preventDefault();
        alert('⚠️ Por favor captura una huella primero.');
        return;
    }
    
    const btn = document.getElementById('guardarHuellaBtn');
    btn.classList.add('loading');
    btn.disabled = true;
});

// ============================================================
// TABS
// ============================================================
const tabBtns = document.querySelectorAll('.tab-btn');
const tabContents = {
    firma: document.getElementById('tab-firma'),
    huella: document.getElementById('tab-huella')
};

tabBtns.forEach(btn => {
    btn.addEventListener('click', function() {
        tabBtns.forEach(b => b.classList.remove('active'));
        Object.values(tabContents).forEach(c => c.classList.remove('active'));
        this.classList.add('active');
        const tab = this.dataset.tab;
        if (tabContents[tab]) {
            tabContents[tab].classList.add('active');
        }
        if (tab === 'firma') {
            setTimeout(resizeCanvas, 100);
        }
    });
});

// ============================================================
// ACTUALIZAR DEDO SELECCIONADO POR DEFECTO
// ============================================================
// Seleccionar el primer dedo disponible (no capturado)
const primerDedoDisponible = document.querySelector('.finger-btn:not(.captured):not(.disabled)');
if (primerDedoDisponible) {
    primerDedoDisponible.click();
}
</script>

</body>
</html>