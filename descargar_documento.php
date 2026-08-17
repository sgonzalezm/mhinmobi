<?php
// descargar_documento.php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/conexion.php';
require_once 'includes/auth.php';

// Verificar autenticación
if (!estaLogueado()) {
    header('Location: login.php');
    exit;
}

// Obtener ID del documento
$documento_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($documento_id <= 0) {
    header('Location: rastreabilidad.php');
    exit;
}

try {
    // Obtener información del documento
    $stmt = $conn->prepare("
        SELECT file_path, document_type
        FROM tracking_documents
        WHERE id = ?
    ");
    $stmt->execute([$documento_id]);
    $documento = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$documento) {
        throw new Exception("Documento no encontrado");
    }
    
    $filepath = $documento['file_path'];
    
    // Verificar que el archivo existe
    if (!file_exists($filepath)) {
        throw new Exception("El archivo no existe en el servidor");
    }
    
    // Obtener nombre del archivo
    $filename = basename($filepath);
    
    // Configurar headers para descarga
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filepath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    // Limpiar buffer de salida
    ob_clean();
    flush();
    
    // Enviar archivo
    readfile($filepath);
    exit;
    
} catch (Exception $e) {
    error_log("Error al descargar documento: " . $e->getMessage());
    $_SESSION['error'] = 'Error al descargar el documento: ' . $e->getMessage();
    header('Location: rastreabilidad.php');
    exit;
}
?>