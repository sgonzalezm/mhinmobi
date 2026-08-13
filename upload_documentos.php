<?php
// ============================================
// upload_documentos.php
// Página para que los clientes suban documentos
// ============================================

session_start();
require_once 'includes/conexion.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Y verifica la conexión a la BD
if (!$conn) {
    die("Error de conexión a la base de datos");
}

// ============================================================
// NUEVO: VERIFICAR SI ES ACCESO ADMINISTRATIVO (CON ID)
// ============================================================
$modo_admin = false;
$admin_property_id = 0;
$admin_property_title = '';

// Si viene con 'id' en lugar de 'token', es un acceso administrativo
if (isset($_GET['id']) && is_numeric($_GET['id']) && !isset($_GET['token'])) {
    // Verificar que el usuario está logueado
    if (!isset($_SESSION['usuario_id'])) {
        header('Location: login.php');
        exit;
    }
    
    $admin_property_id = intval($_GET['id']);
    $modo_admin = true;
    
    // Obtener datos de la propiedad
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
// FLUJO NORMAL: VERIFICAR TOKEN (SOLO SI NO ES MODO ADMIN)
// ============================================================
$token = $_GET['token'] ?? '';
$token_data = null;
$usar_token_real = false;

if (!$modo_admin) {
    // === FLUJO NORMAL CON TOKEN ===
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
                <p style='color: #999; font-size: 14px; margin-top: 20px;'>Contacta con el agente inmobiliario para obtener un nuevo enlace.</p>
            </div>
        </body>
        </html>
        ");
    }

    // Validar token en la base de datos
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
            // Verificar si existe pero está expirado o usado
            $stmt2 = $conn->prepare("
                SELECT t.*, p.title as property_title 
                FROM document_upload_tokens t
                JOIN properties p ON t.property_id = p.id
                WHERE t.token = ?
            ");
            $stmt2->execute([$token]);
            $token_check = $stmt2->fetch(PDO::FETCH_ASSOC);
            
            if ($token_check) {
                $error_msg = "El enlace ya no es válido.";
                if ($token_check['is_used'] == 1) {
                    $error_msg = "Este enlace ya ha sido utilizado. ";
                    if ($token_check['upload_count'] >= $token_check['max_uploads']) {
                        $error_msg .= "Se alcanzó el límite de " . $token_check['max_uploads'] . " archivos.";
                    }
                } elseif (strtotime($token_check['expires_at']) < time()) {
                    $error_msg .= "El enlace expiró el " . date('d/m/Y H:i', strtotime($token_check['expires_at']));
                }
                die("
                <!DOCTYPE html>
                <html>
                <head><title>Enlace Expirado</title></head>
                <body style='font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background: #f5f7fa;'>
                    <div style='background: white; padding: 40px; border-radius: 12px; text-align: center; max-width: 500px; box-shadow: 0 10px 40px rgba(0,0,0,0.08);'>
                        <div style='font-size: 60px; margin-bottom: 20px;'>⏰</div>
                        <h2 style='color: #e74c3c;'>Enlace No Válido</h2>
                        <p style='color: #666;'>$error_msg</p>
                        <p style='color: #999; font-size: 14px; margin-top: 20px;'>Por favor, solicita un nuevo enlace al agente inmobiliario.</p>
                    </div>
                </body>
                </html>
                ");
            } else {
                die("
                <!DOCTYPE html>
                <html>
                <head><title>Error</title></head>
                <body style='font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background: #f5f7fa;'>
                    <div style='background: white; padding: 40px; border-radius: 12px; text-align: center; max-width: 500px; box-shadow: 0 10px 40px rgba(0,0,0,0.08);'>
                        <div style='font-size: 60px; margin-bottom: 20px;'>🔍</div>
                        <h2 style='color: #e74c3c;'>Token Inválido</h2>
                        <p style='color: #666;'>El enlace proporcionado no es válido.</p>
                    </div>
                </body>
                </html>
                ");
            }
        }
        
        if ($token_data['upload_count'] >= $token_data['max_uploads']) {
            die("
            <!DOCTYPE html>
            <html>
            <head><title>Límite Alcanzado</title></head>
            <body style='font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background: #f5f7fa;'>
                <div style='background: white; padding: 40px; border-radius: 12px; text-align: center; max-width: 500px; box-shadow: 0 10px 40px rgba(0,0,0,0.08);'>
                    <div style='font-size: 60px; margin-bottom: 20px;'>📁</div>
                    <h2 style='color: #f39c12;'>Límite Alcanzado</h2>
                    <p style='color: #666;'>Has subido el máximo de " . $token_data['max_uploads'] . " documentos permitidos.</p>
                    <p style='color: #999; font-size: 14px;'>Los documentos están en revisión por parte del equipo inmobiliario.</p>
                </div>
            </body>
            </html>
            ");
        }
        
        $usar_token_real = true;
        
    } catch (PDOException $e) {
        die("
        <!DOCTYPE html>
        <html>
        <head><title>Error</title></head>
        <body style='font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background: #f5f7fa;'>
            <div style='background: white; padding: 40px; border-radius: 12px; text-align: center; max-width: 500px; box-shadow: 0 10px 40px rgba(0,0,0,0.08);'>
                <div style='font-size: 60px; margin-bottom: 20px;'>❌</div>
                <h2 style='color: #e74c3c;'>Error del Sistema</h2>
                <p style='color: #666;'>Ha ocurrido un error al validar el enlace.</p>
                <p style='color: #999; font-size: 14px;'>Por favor, contacta al administrador del sistema.</p>
            </div>
        </body>
        </html>
        ");
    }
} else {
    // === MODO ADMIN: CREAR TOKEN VIRTUAL PARA COMPATIBILIDAD ===
    $token_data = [
        'id' => 0,
        'property_id' => $admin_property_id,
        'property_title' => $admin_property_title,
        'client_name' => 'Administrador',
        'client_email' => $_SESSION['usuario_email'] ?? 'admin@inmobiliariamh.com',
        'max_uploads' => 999, // Sin límite para admin
        'upload_count' => 0,
        'expires_at' => date('Y-m-d H:i:s', strtotime('+1 year')),
        'is_used' => 0,
        'token' => 'admin_' . $admin_property_id
    ];
    $usar_token_real = false;
}

// ============================================================
// PROCESAR SUBIDA DE ARCHIVOS (MODIFICADO PARA ADMIN)
// ============================================================
$mensaje = '';
$mensaje_tipo = '';
$archivo_subido = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['documento'])) {
    $document_type = $_POST['document_type'] ?? 'otros';
    $description = trim($_POST['description'] ?? '');
    $archivo = $_FILES['documento'];
    
    if ($archivo['error'] === UPLOAD_ERR_OK) {
        $max_file_size = 10 * 1024 * 1024; // 10MB
        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];
        
        // Validar tamaño
        if ($archivo['size'] > $max_file_size) {
            $mensaje = "El archivo excede el tamaño máximo de 10MB";
            $mensaje_tipo = 'error';
        } else {
            // Validar extensión
            $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
            if (!in_array($extension, $allowed_extensions)) {
                $mensaje = "Tipo de archivo no permitido. Solo: " . implode(', ', $allowed_extensions);
                $mensaje_tipo = 'error';
            } else {
                // Generar nombre único
                $timestamp = time();
                $nombre_limpio = preg_replace('/[^a-zA-Z0-9._-]/', '', pathinfo($archivo['name'], PATHINFO_FILENAME));
                $nombre_archivo = $timestamp . '_' . $nombre_limpio . '.' . $extension;
                
                // Crear directorio
                $directorio = 'uploads/clientes/' . $token_data['property_id'] . '/';
                if (!file_exists($directorio)) {
                    mkdir($directorio, 0777, true);
                }
                
                $ruta_destino = $directorio . $nombre_archivo;
                
                if (move_uploaded_file($archivo['tmp_name'], $ruta_destino)) {
                    try {
                        // En modo admin, usar token_id = 0 o NULL
                        $token_id = $usar_token_real ? $token_data['id'] : 0;
                        
                        $stmt = $conn->prepare("
                            INSERT INTO client_uploaded_documents 
                            (property_id, token_id, document_type, file_name, file_path, file_size, mime_type, description, client_ip, user_agent, status) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $token_data['property_id'],
                            $token_id,
                            $document_type,
                            $archivo['name'],
                            $ruta_destino,
                            $archivo['size'],
                            $archivo['type'],
                            $description,
                            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                            $_SERVER['HTTP_USER_AGENT'] ?? '',
                            'pending_review'
                        ]);
                        
                        $document_id = $conn->lastInsertId();
                        
                        // Actualizar contador SOLO si es token real
                        if ($usar_token_real) {
                            $stmt = $conn->prepare("UPDATE document_upload_tokens SET upload_count = upload_count + 1 WHERE id = ?");
                            $stmt->execute([$token_data['id']]);
                            
                            // Marcar como usado si llegó al límite
                            $new_count = $token_data['upload_count'] + 1;
                            if ($new_count >= $token_data['max_uploads']) {
                                $stmt = $conn->prepare("UPDATE document_upload_tokens SET is_used = 1, used_at = NOW() WHERE id = ?");
                                $stmt->execute([$token_data['id']]);
                            }
                            
                            $token_data['upload_count'] = $new_count;
                        }
                        
                        $mensaje = "¡Documento subido exitosamente!";
                        $mensaje_tipo = 'success';
                        $archivo_subido = true;
                        
                    } catch (PDOException $e) {
                        $mensaje = "Error al guardar en la base de datos: " . $e->getMessage();
                        $mensaje_tipo = 'error';
                        if (file_exists($ruta_destino)) {
                            unlink($ruta_destino);
                        }
                    }
                } else {
                    $mensaje = "Error al mover el archivo al servidor. Verifica los permisos de la carpeta.";
                    $mensaje_tipo = 'error';
                }
            }
        }
    } else {
        $errores_upload = [
            UPLOAD_ERR_INI_SIZE => 'El archivo excede el tamaño máximo permitido por el servidor.',
            UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño máximo permitido por el formulario.',
            UPLOAD_ERR_PARTIAL => 'El archivo se subió parcialmente.',
            UPLOAD_ERR_NO_FILE => 'No se seleccionó ningún archivo.',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal del servidor.',
            UPLOAD_ERR_CANT_WRITE => 'Error al escribir el archivo en el disco.',
            UPLOAD_ERR_EXTENSION => 'Extensión de archivo no permitida por el servidor.'
        ];
        $mensaje = $errores_upload[$archivo['error']] ?? 'Error desconocido al subir el archivo.';
        $mensaje_tipo = 'error';
    }
}

// Obtener documentos subidos (MODIFICADO PARA ADMIN)
$documentos = [];
try {
    if ($usar_token_real) {
        // Si es token real, obtener por token_id
        $stmt = $conn->prepare("
            SELECT * FROM client_uploaded_documents 
            WHERE token_id = ? 
            ORDER BY uploaded_at DESC
        ");
        $stmt->execute([$token_data['id']]);
    } else {
        // Si es admin, obtener todos los documentos de la propiedad
        $stmt = $conn->prepare("
            SELECT * FROM client_uploaded_documents 
            WHERE property_id = ? 
            ORDER BY uploaded_at DESC
        ");
        $stmt->execute([$token_data['property_id']]);
    }
    $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $documentos = [];
}

// Calcular archivos restantes
if ($usar_token_real) {
    $archivos_restantes = $token_data['max_uploads'] - $token_data['upload_count'];
    $expira_en = (strtotime($token_data['expires_at']) - time()) / 86400;
    $expira_en = ceil($expira_en);
} else {
    $archivos_restantes = 999; // Sin límite para admin
    $expira_en = 365; // 1 año
}

// ============================================================
// HTML (CON BARRA ADMIN SI CORRESPONDE)
// ============================================================
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subir Documentos - <?php echo htmlspecialchars($token_data['property_title']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ... todos tus estilos existentes ... */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh; 
            padding: 20px;
        }
        .container { max-width: 900px; width: 100%; margin: 0 auto; }
        
        /* ===== BARRA ADMIN ===== */
        .admin-bar {
            background: #1d4ed8;
            color: white;
            padding: 10px 20px;
            border-radius: 12px 12px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 0;
        }
        .admin-bar .admin-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .admin-bar .admin-info i {
            font-size: 20px;
        }
        .admin-bar .admin-actions {
            display: flex;
            gap: 8px;
        }
        .admin-bar .admin-actions a {
            color: white;
            text-decoration: none;
            background: rgba(255,255,255,0.2);
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 13px;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .admin-bar .admin-actions a:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .card { 
            background: white; 
            border-radius: 0 0 16px 16px; 
            box-shadow: 0 20px 60px rgba(0,0,0,0.1); 
            padding: 40px;
            margin-bottom: 20px;
        }
        
        /* ... resto de tus estilos existentes ... */
        .header { text-align: center; margin-bottom: 30px; }
        .header .icon { 
            font-size: 60px; 
            color: #2c3e50; 
            margin-bottom: 10px;
            display: inline-block;
            background: #e8f0fe;
            padding: 20px;
            border-radius: 50%;
        }
        .header h1 { color: #2c3e50; font-size: 28px; margin-bottom: 5px; }
        .header p { color: #666; font-size: 16px; }
        .header .property-title { 
            color: #3498db; 
            font-weight: 600;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 25px;
        }
        .info-item { text-align: center; }
        .info-item .label { font-size: 12px; color: #999; text-transform: uppercase; letter-spacing: 0.5px; }
        .info-item .value { font-size: 18px; font-weight: 600; color: #2c3e50; margin-top: 3px; }
        .info-item .value.urgent { color: #e74c3c; }
        .info-item .value.warning { color: #f39c12; }
        .info-item .value.success { color: #27ae60; }
        
        .alert { 
            padding: 15px 20px; 
            border-radius: 8px; 
            margin-bottom: 20px; 
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .alert i { font-size: 20px; }
        .alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert.info { background: #cce5ff; color: #004085; border: 1px solid #b8daff; }
        
        .drop-zone {
            border: 3px dashed #d1d5db;
            border-radius: 12px;
            padding: 50px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #fafafa;
            margin-bottom: 20px;
            position: relative;
        }
        .drop-zone:hover { border-color: #3498db; background: #f0f7ff; }
        .drop-zone.dragover { border-color: #3498db; background: #e8f0fe; transform: scale(1.01); }
        .drop-zone i { font-size: 56px; color: #3498db; margin-bottom: 15px; display: block; }
        .drop-zone h3 { color: #333; margin-bottom: 5px; font-size: 18px; }
        .drop-zone p { color: #999; font-size: 14px; }
        .drop-zone .formats { font-size: 12px; color: #bbb; margin-top: 10px; }
        .drop-zone input[type="file"] { display: none; }
        
        .form-group { margin-bottom: 18px; }
        .form-group label { 
            display: block; 
            margin-bottom: 6px; 
            font-weight: 600; 
            color: #333; 
            font-size: 14px; 
        }
        .form-group label .required { color: #e74c3c; }
        .form-group select, 
        .form-group textarea { 
            width: 100%; 
            padding: 12px 15px; 
            border: 2px solid #e1e5e9; 
            border-radius: 8px; 
            font-size: 14px;
            transition: border-color 0.3s;
            font-family: inherit;
        }
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #3498db;
            outline: none;
        }
        .form-group textarea { min-height: 70px; resize: vertical; }
        
        #fileInfo {
            display: none;
            padding: 12px 16px;
            background: #e8f0fe;
            border-radius: 8px;
            margin: 10px 0 20px 0;
            align-items: center;
            gap: 12px;
        }
        #fileInfo i { font-size: 24px; color: #3498db; }
        #fileInfo .file-name { font-weight: 500; }
        #fileInfo .file-size { color: #666; font-size: 13px; }
        #fileInfo .file-remove { 
            margin-left: auto; 
            color: #e74c3c; 
            cursor: pointer;
            font-size: 18px;
        }
        #fileInfo .file-remove:hover { transform: scale(1.2); }
        
        .btn-upload { 
            background: linear-gradient(135deg, #2c3e50, #34495e);
            color: white; 
            border: none; 
            padding: 14px 35px; 
            border-radius: 8px; 
            font-size: 16px; 
            font-weight: 600;
            cursor: pointer; 
            transition: all 0.3s; 
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .btn-upload:hover { 
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(44, 62, 80, 0.3);
        }
        .btn-upload:disabled { 
            background: #95a5a6; 
            cursor: not-allowed; 
            transform: none !important;
            box-shadow: none !important;
        }
        .btn-upload .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top: 3px solid white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        .btn-upload.loading .spinner { display: inline-block; }
        .btn-upload.loading .btn-text { display: none; }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .file-list { margin-top: 30px; }
        .file-list-title { 
            font-size: 18px; 
            font-weight: 600; 
            color: #2c3e50; 
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .file-list-title .count { 
            background: #e8f0fe; 
            padding: 2px 10px; 
            border-radius: 20px; 
            font-size: 13px;
            color: #3498db;
        }
        
        .file-item { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding: 14px 18px; 
            background: #f8f9fa; 
            border-radius: 8px; 
            margin-bottom: 10px; 
            border-left: 4px solid #3498db;
            transition: background 0.2s;
            flex-wrap: wrap;
            gap: 8px;
        }
        .file-item:hover { background: #f0f1f2; }
        .file-item .file-info { display: flex; align-items: center; gap: 14px; flex: 1; min-width: 0; }
        .file-item .file-info i { font-size: 24px; color: #3498db; }
        .file-item .file-info .name { 
            font-weight: 500; 
            white-space: nowrap; 
            overflow: hidden; 
            text-overflow: ellipsis; 
        }
        .file-item .file-info .size { font-size: 12px; color: #999; margin-left: 8px; }
        .file-item .file-info .desc { font-size: 13px; color: #666; display: block; }
        .file-item .file-status { 
            padding: 4px 12px; 
            border-radius: 20px; 
            font-size: 12px; 
            font-weight: 600;
            white-space: nowrap;
        }
        .file-item .file-status.pending_review { background: #fff3cd; color: #856404; }
        .file-item .file-status.approved { background: #d4edda; color: #155724; }
        .file-item .file-status.rejected { background: #f8d7da; color: #721c24; }
        .file-item .file-status.pending_correction { background: #cce5ff; color: #004085; }
        .file-item .file-time { font-size: 12px; color: #999; }
        
        .footer { 
            text-align: center; 
            margin-top: 30px; 
            color: #999; 
            font-size: 13px;
            padding: 20px 0;
        }
        .footer a { color: #3498db; text-decoration: none; }
        .footer a:hover { text-decoration: underline; }
        
        @media (max-width: 768px) {
            .container { padding: 0; }
            .card { padding: 20px; }
            .header h1 { font-size: 22px; }
            .info-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; padding: 15px; }
            .file-item { flex-direction: column; align-items: flex-start; }
            .file-item .file-time { margin-left: 38px; }
            .drop-zone { padding: 30px 15px; }
            .drop-zone i { font-size: 40px; }
            .admin-bar {
                flex-direction: column;
                text-align: center;
                padding: 12px 16px;
            }
            .admin-bar .admin-actions {
                flex-wrap: wrap;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
<div class="container">

    <?php if ($modo_admin): ?>
    <!-- ===== BARRA ADMINISTRADOR ===== -->
    <div class="admin-bar">
        <div class="admin-info">
            <i class="fas fa-user-shield"></i>
            <span>
                <strong>Modo Administrador</strong> · 
                Gestionando: <?php echo htmlspecialchars($token_data['property_title']); ?>
                <span style="font-size: 12px; opacity: 0.8; margin-left: 8px;">
                    <i class="fas fa-hashtag"></i> ID: <?php echo $token_data['property_id']; ?>
                </span>
            </span>
        </div>
        <div class="admin-actions">
            <a href="propiedad_detalle_inventario.php?id=<?php echo $token_data['property_id']; ?>">
                <i class="fas fa-arrow-left"></i> Volver a detalles
            </a>
            <a href="inventario.php">
                <i class="fas fa-th-list"></i> Inventario
            </a>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="header">
            <div class="icon"><i class="fas fa-file-upload"></i></div>
            <h1>Subir Documentos</h1>
            <p>
                Para la propiedad: 
                <span class="property-title"><?php echo htmlspecialchars($token_data['property_title']); ?></span>
                <?php if ($modo_admin): ?>
                    <span style="display: inline-block; background: #1d4ed8; color: white; padding: 2px 10px; border-radius: 12px; font-size: 11px; margin-left: 8px;">
                        <i class="fas fa-user-shield"></i> Admin
                    </span>
                <?php endif; ?>
            </p>
        </div>

        <div class="info-grid">
            <div class="info-item">
                <div class="label">📧 Cliente</div>
                <div class="value"><?php echo htmlspecialchars($token_data['client_name'] ?: 'No especificado'); ?></div>
            </div>
            <div class="info-item">
                <div class="label">📄 Archivos Restantes</div>
                <div class="value <?php echo $archivos_restantes <= 2 ? 'urgent' : ($archivos_restantes <= 5 ? 'warning' : 'success'); ?>">
                    <?php 
                        if ($modo_admin) {
                            echo '∞ (Admin)';
                        } else {
                            echo $archivos_restantes . ' / ' . $token_data['max_uploads'];
                        }
                    ?>
                </div>
            </div>
            <div class="info-item">
                <div class="label">⏰ Expira en</div>
                <div class="value <?php echo $expira_en <= 1 ? 'urgent' : ($expira_en <= 3 ? 'warning' : ''); ?>">
                    <?php 
                        if ($modo_admin) {
                            echo '∞ (Admin)';
                        } elseif ($expira_en <= 0) {
                            echo "Hoy";
                        } elseif ($expira_en == 1) {
                            echo "Mañana";
                        } else {
                            echo $expira_en . " días";
                        }
                    ?>
                </div>
            </div>
            <div class="info-item">
                <div class="label">📁 Subidos</div>
                <div class="value"><?php echo count($documentos); ?></div>
            </div>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert <?php echo $mensaje_tipo; ?>">
                <i class="fas fa-<?php echo $mensaje_tipo === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <?php if ($archivos_restantes > 0 || $modo_admin): ?>
        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <div class="form-group">
                <label for="document_type">Tipo de Documento <span class="required">*</span></label>
                <select name="document_type" id="document_type" required>
                    <option value="">Selecciona el tipo de documento...</option>
                    <option value="identificacion">📇 Identificación Oficial</option>
                    <option value="comprobante_domicilio">🏠 Comprobante de Domicilio</option>
                    <option value="escritura">📜 Escrituras / Título de Propiedad</option>
                    <option value="avaluo">💰 Avalúo</option>
                    <option value="ficha_tecnica">📋 Ficha Técnica</option>
                    <option value="certificado">📄 Certificado de Libertad</option>
                    <option value="contrato_compraventa">✍️ Contrato de Compraventa</option>
                    <option value="estado_cuenta">📊 Estado de Cuenta</option>
                    <option value="otros">📎 Otros</option>
                </select>
            </div>

            <div class="form-group">
                <label for="description">Descripción (Opcional)</label>
                <textarea name="description" id="description" placeholder="Breve descripción del documento (ej: INE del propietario, última escritura, etc.)"></textarea>
            </div>

            <div class="drop-zone" id="dropZone">
                <i class="fas fa-cloud-upload-alt"></i>
                <h3>Arrastra y suelta tu archivo aquí</h3>
                <p>o haz clic para seleccionar desde tu computadora</p>
                <div class="formats">📁 Formatos permitidos: PDF, JPG, PNG, DOC, DOCX, XLS, XLSX (Max: 10MB)</div>
                <input type="file" name="documento" id="fileInput" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx" required>
            </div>

            <div id="fileInfo">
                <i class="fas fa-file"></i>
                <div>
                    <div class="file-name" id="fileName">archivo.pdf</div>
                    <div class="file-size" id="fileSize">0 MB</div>
                </div>
                <span class="file-remove" onclick="resetFileInput()">&times;</span>
            </div>

            <button type="submit" class="btn-upload" id="uploadBtn">
                <span class="spinner"></span>
                <span class="btn-text"><i class="fas fa-upload"></i> Subir Documento</span>
            </button>
        </form>
        <?php else: ?>
            <div class="alert info">
                <i class="fas fa-info-circle"></i>
                Has alcanzado el límite de <strong><?php echo $token_data['max_uploads']; ?></strong> archivos permitidos. 
                Todos los documentos han sido enviados para revisión.
            </div>
        <?php endif; ?>

        <?php if (!empty($documentos)): ?>
        <div class="file-list">
            <div class="file-list-title">
                <i class="fas fa-list"></i> Documentos Subidos
                <span class="count"><?php echo count($documentos); ?></span>
            </div>
            <?php foreach ($documentos as $doc): ?>
                <div class="file-item">
                    <div class="file-info">
                        <i class="fas fa-file-<?php 
                            echo match($doc['document_type']) {
                                'identificacion' => 'id-card',
                                'comprobante_domicilio' => 'home',
                                'escritura' => 'scroll',
                                'avaluo' => 'chart-pie',
                                'ficha_tecnica' => 'clipboard-list',
                                'certificado' => 'certificate',
                                'contrato_compraventa' => 'handshake',
                                'estado_cuenta' => 'chart-bar',
                                default => 'file'
                            };
                        ?>"></i>
                        <div>
                            <div class="name">
                                <?php echo htmlspecialchars($doc['file_name']); ?>
                                <span class="size">(<?php echo number_format($doc['file_size'] / 1024, 1); ?> KB)</span>
                            </div>
                            <span class="desc">
                                <?php echo htmlspecialchars($doc['description'] ?: 'Sin descripción'); ?>
                            </span>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <span class="file-status <?php echo $doc['status']; ?>">
                            <?php 
                                echo match($doc['status']) {
                                    'pending_review' => '⏳ Pendiente',
                                    'approved' => '✅ Aprobado',
                                    'rejected' => '❌ Rechazado',
                                    'pending_correction' => '🔄 Corrección',
                                    default => $doc['status']
                                };
                            ?>
                        </span>
                        <span class="file-time">
                            <?php echo date('d/m/Y H:i', strtotime($doc['uploaded_at'])); ?>
                        </span>
                        <?php if ($modo_admin): ?>
                            <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" 
                               style="color: #3498db; font-size: 13px; text-decoration: none; background: #e8f0fe; padding: 2px 10px; border-radius: 4px;">
                                <i class="fas fa-eye"></i> Ver
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="footer">
        <p>
            <i class="fas fa-lock" style="color: #3498db;"></i> 
            Este es un enlace seguro de la plataforma Inmobiliaria MH.
            <br>
            ¿Problemas para subir documentos? Contacta a tu agente inmobiliario.
        </p>
    </div>
</div>

<script>
// ===== DRAG AND DROP =====
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const fileInfo = document.getElementById('fileInfo');
const fileName = document.getElementById('fileName');
const fileSize = document.getElementById('fileSize');
const uploadBtn = document.getElementById('uploadBtn');

dropZone.addEventListener('dragover', function(e) {
    e.preventDefault();
    this.classList.add('dragover');
});

dropZone.addEventListener('dragleave', function(e) {
    e.preventDefault();
    this.classList.remove('dragover');
});

dropZone.addEventListener('drop', function(e) {
    e.preventDefault();
    this.classList.remove('dragover');
    
    if (e.dataTransfer.files.length) {
        fileInput.files = e.dataTransfer.files;
        updateFileInfo();
    }
});

dropZone.addEventListener('click', function() {
    fileInput.click();
});

fileInput.addEventListener('change', function() {
    if (this.files.length) {
        updateFileInfo();
    }
});

function updateFileInfo() {
    const file = fileInput.files[0];
    if (file) {
        fileName.textContent = file.name;
        const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
        fileSize.textContent = sizeMB + ' MB';
        fileInfo.style.display = 'flex';
        
        // Validar tamaño
        if (file.size > 10 * 1024 * 1024) {
            alert('⚠️ El archivo excede el tamaño máximo de 10MB');
            resetFileInput();
        }
    }
}

function resetFileInput() {
    fileInput.value = '';
    fileInfo.style.display = 'none';
}

// ===== SUBMIT CON LOADING =====
document.getElementById('uploadForm').addEventListener('submit', function(e) {
    const file = fileInput.files[0];
    if (!file) {
        e.preventDefault();
        alert('⚠️ Por favor selecciona un archivo para subir.');
        return;
    }
    
    // Validar extensión
    const allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];
    const extension = file.name.split('.').pop().toLowerCase();
    if (!allowedExtensions.includes(extension)) {
        e.preventDefault();
        alert('⚠️ Formato no permitido. Solo: ' + allowedExtensions.join(', '));
        return;
    }
    
    uploadBtn.classList.add('loading');
    uploadBtn.disabled = true;
});
</script>

</body>
</html>