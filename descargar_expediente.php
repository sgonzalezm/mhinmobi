<?php
// ============================================
// descargar_expediente.php - VERSIÓN CORREGIDA
// ============================================

// 🔥 ACTIVAR ERRORES PARA DEPURACIÓN
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// 🔥 LOG DE ERRORES EN ARCHIVO
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log_expediente.log');

session_start();
require_once 'includes/conexion.php';
require_once 'includes/auth.php';

// 🔥 REGISTRAR INICIO DEL PROCESO
error_log("=== INICIANDO descargar_expediente.php ===");

// Verificar autenticación
if (!estaLogueado()) {
    error_log("ERROR: Usuario no autenticado");
    header('Location: login.php');
    exit;
}

$usuario = obtenerUsuarioActual($conn);
if (!$usuario) {
    error_log("ERROR: Usuario no encontrado");
    header('Location: login.php');
    exit;
}

error_log("Usuario autenticado: " . $usuario['name'] . " (ID: " . $usuario['id'] . ")");

// Obtener ID de la propiedad
$property_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
error_log("Property ID: " . $property_id);

if ($property_id <= 0) {
    error_log("ERROR: ID de propiedad inválido");
    die('ID de propiedad inválido');
}

// Verificar permisos
$es_admin = esAdmin();
error_log("Es admin: " . ($es_admin ? 'SI' : 'NO'));

try {
    // ============================================================
    // 1. OBTENER DATOS DE LA PROPIEDAD
    // ============================================================
    error_log("Obteniendo datos de propiedad...");
    
    $stmt = $conn->prepare("
        SELECT 
            p.id,
            p.title,
            p.address_city,
            p.address_municipality,
            p.operation_type,
            p.status,
            p.created_at,
            pd.square_meters,
            pd.bedrooms,
            pd.bathrooms,
            pd.parking_spots,
            pf.asking_price,
            pf.commission_percentage,
            u.name as propietario_nombre,
            u.email as propietario_email
        FROM properties p
        LEFT JOIN property_details pd ON p.id = pd.property_id
        LEFT JOIN property_financials pf ON p.id = pf.property_id
        LEFT JOIN users u ON p.owner_id = u.id
        WHERE p.id = ?
    ");
    $stmt->execute([$property_id]);
    $propiedad = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$propiedad) {
        error_log("ERROR: Propiedad no encontrada - ID: " . $property_id);
        die('Propiedad no encontrada');
    }
    
    error_log("Propiedad encontrada: " . $propiedad['title']);
    
    // Verificar que el usuario tenga acceso
    if (!$es_admin) {
        $stmt = $conn->prepare("SELECT owner_id FROM properties WHERE id = ?");
        $stmt->execute([$property_id]);
        $owner = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$owner || $owner['owner_id'] != $_SESSION['usuario_id']) {
            error_log("ERROR: Usuario no tiene permiso para esta propiedad");
            die('No tienes permisos para descargar este expediente');
        }
    }
    
    // ============================================================
    // 2. OBTENER ARCHIVOS (CORREGIDO - SIN client_name/client_email)
    // ============================================================
    error_log("Obteniendo imágenes...");
    
    $stmt = $conn->prepare("
        SELECT file_path, file_name, is_primary, sort_order
        FROM property_media
        WHERE property_id = ?
        ORDER BY is_primary DESC, sort_order ASC
    ");
    $stmt->execute([$property_id]);
    $imagenes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Imágenes encontradas: " . count($imagenes));
    
    error_log("Obteniendo documentos generales...");
    $stmt = $conn->prepare("
        SELECT file_path, file_name, document_type, uploaded_at
        FROM property_documents
        WHERE property_id = ?
        ORDER BY uploaded_at DESC
    ");
    $stmt->execute([$property_id]);
    $documentos_generales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Documentos generales encontrados: " . count($documentos_generales));
    
    // 🔥 CORRECCIÓN AQUÍ: Eliminar client_name y client_email
    error_log("Obteniendo documentos de clientes...");
    $stmt = $conn->prepare("
        SELECT file_path, file_name, document_type, uploaded_at, 
               status, property_id, token_id
        FROM client_uploaded_documents
        WHERE property_id = ?
        ORDER BY uploaded_at DESC
    ");
    $stmt->execute([$property_id]);
    $documentos_clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Documentos de clientes encontrados: " . count($documentos_clientes));
    
    // 🔥 Si necesitas el nombre del cliente, puedes obtenerlo desde otra tabla
    // Por ejemplo, desde la tabla de tokens o desde la propiedad
    foreach ($documentos_clientes as &$doc) {
        // Si tienes una tabla de tokens, puedes obtener el nombre del cliente
        if (!empty($doc['token_id'])) {
            $stmt_token = $conn->prepare("
                SELECT client_name, client_email 
                FROM document_upload_tokens 
                WHERE id = ?
            ");
            $stmt_token->execute([$doc['token_id']]);
            $token = $stmt_token->fetch(PDO::FETCH_ASSOC);
            if ($token) {
                $doc['client_name'] = $token['client_name'] ?? 'Cliente';
                $doc['client_email'] = $token['client_email'] ?? '';
            } else {
                $doc['client_name'] = 'Cliente';
                $doc['client_email'] = '';
            }
        } else {
            $doc['client_name'] = 'Cliente';
            $doc['client_email'] = '';
        }
    }
    unset($doc);
    
    error_log("Obteniendo datos biométricos...");
    $stmt = $conn->prepare("
        SELECT file_path, tipo_biometrico, dedo, created_at, created_by
        FROM client_biometric_data
        WHERE property_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$property_id]);
    $biometricos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Datos biométricos encontrados: " . count($biometricos));
    
    // ============================================================
    // 3. VERIFICAR QUE HAY ARCHIVOS PARA DESCARGAR
    // ============================================================
    $total_archivos = count($imagenes) + count($documentos_generales) + 
                      count($documentos_clientes) + count($biometricos);
    
    if ($total_archivos == 0) {
        error_log("ADVERTENCIA: No hay archivos para descargar");
        echo "
        <!DOCTYPE html>
        <html>
        <head><title>Sin archivos</title></head>
        <body style='font-family: Arial; display: flex; justify-content: center; align-items: center; height: 100vh;'>
            <div style='text-align: center; padding: 40px;'>
                <div style='font-size: 60px; margin-bottom: 20px;'>📂</div>
                <h2>No hay archivos para descargar</h2>
                <p style='color: #666;'>Esta propiedad no tiene imágenes ni documentos asociados.</p>
                <a href='propiedad_detalle_inventario.php?id=" . $property_id . "' 
                   style='display: inline-block; margin-top: 20px; padding: 10px 30px; background: #4f46e5; color: white; text-decoration: none; border-radius: 8px;'>
                    Volver a la propiedad
                </a>
            </div>
        </body>
        </html>
        ";
        exit;
    }
    
    // ============================================================
    // 4. CREAR DIRECTORIO TEMPORAL
    // ============================================================
    error_log("Creando directorio temporal...");
    
    $temp_dir = sys_get_temp_dir() . '/expediente_' . $property_id . '_' . time();
    if (!mkdir($temp_dir, 0777, true)) {
        error_log("ERROR: No se pudo crear directorio temporal: " . $temp_dir);
        die('Error al crear directorio temporal');
    }
    error_log("Directorio temporal creado: " . $temp_dir);
    
    // ============================================================
    // 5. CREAR METADATOS
    // ============================================================
    error_log("Generando metadatos...");
    
    $metadata = "========================================\n";
    $metadata .= "EXPEDIENTE DE PROPIEDAD\n";
    $metadata .= "========================================\n\n";
    $metadata .= "ID: #" . $propiedad['id'] . "\n";
    $metadata .= "Título: " . $propiedad['title'] . "\n";
    $metadata .= "Ubicación: " . $propiedad['address_municipality'] . ", " . $propiedad['address_city'] . "\n";
    $metadata .= "Tipo de operación: " . ucfirst($propiedad['operation_type']) . "\n";
    $metadata .= "Estado: " . ucfirst($propiedad['status']) . "\n";
    $metadata .= "Fecha de registro: " . date('d/m/Y H:i', strtotime($propiedad['created_at'])) . "\n\n";
    
    $metadata .= "--- CARACTERÍSTICAS ---\n";
    $metadata .= "M²: " . ($propiedad['square_meters'] ?? 'N/A') . "\n";
    $metadata .= "Recámaras: " . ($propiedad['bedrooms'] ?? 'N/A') . "\n";
    $metadata .= "Baños: " . ($propiedad['bathrooms'] ?? 'N/A') . "\n";
    $metadata .= "Estacionamientos: " . ($propiedad['parking_spots'] ?? 'N/A') . "\n\n";
    
    $metadata .= "--- FINANCIERO ---\n";
    $metadata .= "Precio: $" . number_format($propiedad['asking_price'] ?? 0, 2) . "\n";
    $metadata .= "Comisión: " . ($propiedad['commission_percentage'] ?? 'N/A') . "%\n\n";
    
    $metadata .= "--- PROPIETARIO ---\n";
    $metadata .= "Nombre: " . ($propiedad['propietario_nombre'] ?? 'N/A') . "\n";
    $metadata .= "Email: " . ($propiedad['propietario_email'] ?? 'N/A') . "\n\n";
    
    $metadata .= "--- RESUMEN DE ARCHIVOS ---\n";
    $metadata .= "Imágenes: " . count($imagenes) . " archivos\n";
    $metadata .= "Documentos generales: " . count($documentos_generales) . " archivos\n";
    $metadata .= "Documentos de clientes: " . count($documentos_clientes) . " archivos\n";
    $metadata .= "Datos biométricos: " . count($biometricos) . " archivos\n\n";
    
    $metadata .= "========================================\n";
    $metadata .= "Expediente generado el: " . date('d/m/Y H:i:s') . "\n";
    $metadata .= "Generado por: " . ($usuario['name'] ?? 'Sistema') . "\n";
    $metadata .= "========================================\n";
    
    file_put_contents($temp_dir . '/00_METADATOS.txt', $metadata);
    
    // ============================================================
    // 6. FUNCIÓN PARA COPIAR ARCHIVOS
    // ============================================================
    function agregarArchivoAlZip($origen, $destino) {
        if (empty($origen)) {
            return false;
        }
        
        // Verificar si es una URL
        if (filter_var($origen, FILTER_VALIDATE_URL)) {
            $contenido = @file_get_contents($origen);
            if ($contenido !== false) {
                file_put_contents($destino, $contenido);
                return true;
            }
            return false;
        } else {
            // Archivo local
            if (file_exists($origen)) {
                return copy($origen, $destino);
            }
            return false;
        }
    }
    
    // ============================================================
    // 7. COPIAR ARCHIVOS
    // ============================================================
    $archivos_copiados = 0;
    
    // Imágenes
    if (!empty($imagenes)) {
        $img_dir = $temp_dir . '/01_IMAGENES';
        mkdir($img_dir, 0777, true);
        error_log("Directorio de imágenes creado: " . $img_dir);
        
        foreach ($imagenes as $index => $img) {
            if (empty($img['file_path'])) continue;
            
            $prefix = $img['is_primary'] ? 'PRINCIPAL_' : '';
            $numero = str_pad($index + 1, 2, '0', STR_PAD_LEFT);
            $nombre_archivo = $prefix . $numero . '_' . basename($img['file_path']);
            $destino = $img_dir . '/' . $nombre_archivo;
            
            if (agregarArchivoAlZip($img['file_path'], $destino)) {
                $archivos_copiados++;
            }
        }
    }
    
    // Documentos generales
    if (!empty($documentos_generales)) {
        $docs_dir = $temp_dir . '/02_DOCUMENTOS_GENERALES';
        mkdir($docs_dir, 0777, true);
        error_log("Directorio de documentos generales creado: " . $docs_dir);
        
        foreach ($documentos_generales as $doc) {
            if (empty($doc['file_path'])) continue;
            
            $tipo = str_replace('_', '-', $doc['document_type'] ?? 'documento');
            $nombre_archivo = $tipo . '_' . basename($doc['file_path']);
            $destino = $docs_dir . '/' . $nombre_archivo;
            
            if (agregarArchivoAlZip($doc['file_path'], $destino)) {
                $archivos_copiados++;
            }
        }
    }
    
    // 🔥 Documentos de clientes (CORREGIDO - usa client_name del token)
    if (!empty($documentos_clientes)) {
        $docs_cli_dir = $temp_dir . '/03_DOCUMENTOS_CLIENTES';
        mkdir($docs_cli_dir, 0777, true);
        error_log("Directorio de documentos de clientes creado: " . $docs_cli_dir);
        
        foreach ($documentos_clientes as $doc) {
            if (empty($doc['file_path'])) continue;
            
            // Usar el client_name que obtuvimos del token
            $nombre_cliente = preg_replace('/[^a-zA-Z0-9]/', '_', $doc['client_name'] ?? 'cliente');
            $tipo = str_replace('_', '-', $doc['document_type'] ?? 'documento');
            $estado = $doc['status'] ?? 'pendiente';
            $nombre_archivo = $tipo . '_' . $nombre_cliente . '_' . $estado . '_' . basename($doc['file_path']);
            $destino = $docs_cli_dir . '/' . $nombre_archivo;
            
            if (agregarArchivoAlZip($doc['file_path'], $destino)) {
                $archivos_copiados++;
            }
        }
    }
    
    // Datos biométricos
    if (!empty($biometricos)) {
        $bio_dir = $temp_dir . '/04_BIOMETRICOS';
        mkdir($bio_dir, 0777, true);
        error_log("Directorio de biométricos creado: " . $bio_dir);
        
        foreach ($biometricos as $bio) {
            if (empty($bio['file_path'])) continue;
            
            $tipo = $bio['tipo_biometrico'] ?? 'biometrico';
            $dedo = str_replace('_', '-', $bio['dedo'] ?? '');
            $creado_por = $bio['created_by'] ?? 'cliente';
            $nombre_archivo = $tipo . ($dedo ? '_' . $dedo : '') . '_' . $creado_por . '_' . basename($bio['file_path']);
            $destino = $bio_dir . '/' . $nombre_archivo;
            
            if (agregarArchivoAlZip($bio['file_path'], $destino)) {
                $archivos_copiados++;
            }
        }
    }
    
    error_log("Total de archivos copiados: " . $archivos_copiados);
    
    if ($archivos_copiados == 0) {
        error_log("ERROR: No se pudo copiar ningún archivo");
        deleteDirectory($temp_dir);
        die('No se pudieron copiar los archivos. Verifica que las rutas sean correctas.');
    }
    
    // ============================================================
    // 8. GENERAR HTML DE RESUMEN
    // ============================================================
    error_log("Generando HTML de resumen...");
    
    $html_resumen = "
    <!DOCTYPE html>
    <html>
    <head>
        <title>Expediente - " . htmlspecialchars($propiedad['title']) . "</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; color: #333; }
            h1 { color: #1a1a2e; border-bottom: 3px solid #c9a84c; padding-bottom: 10px; }
            .section { margin: 25px 0; }
            .section h2 { color: #2d3748; background: #f8f9fa; padding: 10px 15px; border-radius: 5px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { padding: 8px 12px; border: 1px solid #e2e8f0; text-align: left; }
            th { background: #f8f9fa; font-weight: 600; }
            .badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 12px; }
            .badge-activo { background: #d4edda; color: #155724; }
            .badge-pendiente { background: #fff3cd; color: #856404; }
            .badge-vendido { background: #cce5ff; color: #004085; }
            .file-list { columns: 2; list-style: none; padding: 0; }
            .file-list li { padding: 4px 0; border-bottom: 1px solid #f0f0f0; }
        </style>
    </head>
    <body>
        <h1>📋 Expediente de Propiedad</h1>
        <p><strong>ID:</strong> #" . $propiedad['id'] . " | <strong>Fecha:</strong> " . date('d/m/Y H:i') . "</p>
        
        <div class='section'>
            <h2>🏠 Información General</h2>
            <table>
                <tr><th>Campo</th><th>Valor</th></tr>
                <tr><td>Título</td><td>" . htmlspecialchars($propiedad['title']) . "</td></tr>
                <tr><td>Ubicación</td><td>" . htmlspecialchars($propiedad['address_municipality'] . ', ' . $propiedad['address_city']) . "</td></tr>
                <tr><td>Tipo de operación</td><td>" . ucfirst($propiedad['operation_type']) . "</td></tr>
                <tr><td>Estado</td><td><span class='badge badge-" . $propiedad['status'] . "'>" . ucfirst($propiedad['status']) . "</span></td></tr>
                <tr><td>Precio</td><td>$" . number_format($propiedad['asking_price'] ?? 0, 2) . "</td></tr>
            </table>
        </div>
        
        <div class='section'>
            <h2>📊 Resumen de Archivos</h2>
            <table>
                <tr><th>Tipo</th><th>Cantidad</th></tr>
                <tr><td>🖼️ Imágenes</td><td>" . count($imagenes) . "</td></tr>
                <tr><td>📄 Documentos Generales</td><td>" . count($documentos_generales) . "</td></tr>
                <tr><td>📎 Documentos de Clientes</td><td>" . count($documentos_clientes) . "</td></tr>
                <tr><td>🖐️ Datos Biométricos</td><td>" . count($biometricos) . "</td></tr>
            </table>
        </div>
        
        <div class='section'>
            <h2>📁 Contenido del Expediente</h2>
            <ul class='file-list'>
                <li>📁 00_METADATOS.txt - Información general</li>
                <li>📁 01_IMAGENES/ - Fotos de la propiedad</li>
                <li>📁 02_DOCUMENTOS_GENERALES/ - Documentos administrativos</li>
                <li>📁 03_DOCUMENTOS_CLIENTES/ - Documentos subidos por clientes</li>
                <li>📁 04_BIOMETRICOS/ - Firmas y huellas dactilares</li>
            </ul>
        </div>
        
        <p style='color: #666; font-size: 12px; margin-top: 30px; border-top: 1px solid #eee; padding-top: 15px;'>
            Expediente generado automáticamente por Inmobiliaria MH
        </p>
    </body>
    </html>
    ";
    
    file_put_contents($temp_dir . '/05_INDEX.html', $html_resumen);
    
    // ============================================================
    // 9. CREAR EL ZIP
    // ============================================================
    error_log("Creando archivo ZIP...");
    
    $zip_filename = 'expediente_' . $property_id . '_' . date('Ymd_His') . '.zip';
    $zip_path = sys_get_temp_dir() . '/' . $zip_filename;
    
    // Verificar que la extensión ZipArchive está disponible
    if (!class_exists('ZipArchive')) {
        error_log("ERROR: ZipArchive no está disponible");
        deleteDirectory($temp_dir);
        die('La extensión ZIP no está disponible en el servidor. Contacta al administrador.');
    }
    
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        error_log("ERROR: No se pudo crear el archivo ZIP");
        deleteDirectory($temp_dir);
        die('Error al crear el archivo ZIP');
    }
    
    // Agregar todos los archivos del directorio temporal
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temp_dir),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    $archivos_zip = 0;
    foreach ($files as $file) {
        if (!$file->isDir()) {
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($temp_dir) + 1);
            $zip->addFile($filePath, $relativePath);
            $archivos_zip++;
            error_log("Agregado al ZIP: " . $relativePath);
        }
    }
    
    $zip->close();
    error_log("ZIP creado con " . $archivos_zip . " archivos");
    
    // ============================================================
    // 10. LIMPIAR DIRECTORIO TEMPORAL
    // ============================================================
    function deleteDirectory($dir) {
        if (!file_exists($dir)) return true;
        if (!is_dir($dir)) return unlink($dir);
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') continue;
            if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) return false;
        }
        return rmdir($dir);
    }
    
    deleteDirectory($temp_dir);
    error_log("Directorio temporal eliminado");
    
    // ============================================================
    // 11. DESCARGAR EL ZIP
    // ============================================================
    if (!file_exists($zip_path)) {
        error_log("ERROR: El archivo ZIP no existe: " . $zip_path);
        die('Error al generar el expediente. Intenta nuevamente.');
    }
    
    $zip_size = filesize($zip_path);
    error_log("Tamaño del ZIP: " . $zip_size . " bytes");
    
    // Configurar headers para descarga
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
    header('Content-Length: ' . $zip_size);
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    // Enviar archivo
    readfile($zip_path);
    
    // Eliminar ZIP temporal
    unlink($zip_path);
    error_log("=== PROCESO COMPLETADO EXITOSAMENTE ===");
    
    exit;
    
} catch (Exception $e) {
    error_log("EXCEPCIÓN CAPTURADA: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    die('Error: ' . $e->getMessage());
}
?>