<?php
// generar_poder_notarial.php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/conexion.php';
require_once 'includes/auth.php';
require_once 'includes/PDFGenerator.php';

// Verificar autenticación
if (!estaLogueado()) {
    header('Location: login.php');
    exit;
}

// Obtener ID del proceso
$proceso_id = isset($_GET['proceso_id']) ? intval($_GET['proceso_id']) : 0;

if ($proceso_id <= 0) {
    header('Location: rastreabilidad.php');
    exit;
}

try {
    // Obtener datos
    $stmt = $conn->prepare("
        SELECT 
            pt.*,
            p.title as property_title,
            p.address_municipality,
            p.address_state,
            u.name as initiated_by_name
        FROM property_tracking pt
        JOIN properties p ON pt.property_id = p.id
        LEFT JOIN users u ON pt.initiated_by = u.id
        WHERE pt.id = ?
    ");
    $stmt->execute([$proceso_id]);
    $datos = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$datos) {
        throw new Exception("Proceso no encontrado");
    }
    
    // Crear PDF
    $pdf = new PDFGenerator();
    $pdf->SetTitle('Poder Notarial - ' . $datos['property_title']);
    $pdf->SetAuthor('Inmobiliaria MH');
    
    // Generar poder notarial
    $pdf->generarPoderNotarial($datos);
    
    // Guardar archivo
    $filename = 'poder_notarial_' . $proceso_id . '_' . date('Ymd_His') . '.pdf';
    $filepath = 'uploads/poderes/' . $filename;
    
    if (!file_exists('uploads/poderes/')) {
        mkdir('uploads/poderes/', 0777, true);
    }
    
    $pdf->Output('F', $filepath);
    
    // Registrar en base de datos
    $stmtDoc = $conn->prepare("
        INSERT INTO tracking_documents 
        (tracking_id, document_type, file_path, generated_at)
        VALUES (?, 'poder_notarial', ?, NOW())
    ");
    $stmtDoc->execute([$proceso_id, $filepath]);
    
    $_SESSION['success'] = 'Poder notarial generado correctamente.';
    header('Location: proceso_detalle.php?id=' . $proceso_id);
    exit;
    
} catch (Exception $e) {
    error_log("Error al generar poder notarial: " . $e->getMessage());
    $_SESSION['error'] = 'Error al generar el poder notarial: ' . $e->getMessage();
    header('Location: proceso_detalle.php?id=' . $proceso_id);
    exit;
}
?>