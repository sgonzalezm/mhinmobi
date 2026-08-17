<?php
// generar_contrato.php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/conexion.php';
require_once 'includes/auth.php';

// Buscar FPDF en diferentes ubicaciones
$fpdf_paths = [
    'includes/fpdf/fpdf.php',
    'includes/fpdf184/fpdf.php',
    'fpdf/fpdf.php',
    'includes/tcpdf/tcpdf.php'
];

$fpdf_loaded = false;
foreach ($fpdf_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $fpdf_loaded = true;
        error_log("FPDF cargado desde: " . $path);
        break;
    }
}

if (!$fpdf_loaded) {
    $_SESSION['error'] = 'Error: No se encontró la librería FPDF. Contacte al administrador.';
    error_log("ERROR: No se encontró FPDF en generar_contrato.php");
    header('Location: proceso_detalle.php?id=' . $_GET['proceso_id']);
    exit;
}

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
    // Obtener datos completos para el contrato
    $stmt = $conn->prepare("
        SELECT 
            pt.*,
            p.title as property_title,
            p.operation_type,
            p.address_municipality,
            p.address_state,
            p.description,
            f.asking_price,
            f.commission_percentage,
            u.name as seller_name,
            u.email as seller_email,
            u.telefono as seller_phone
        FROM property_tracking pt
        JOIN properties p ON pt.property_id = p.id
        LEFT JOIN property_financials f ON p.id = f.property_id
        LEFT JOIN users u ON pt.initiated_by = u.id
        WHERE pt.id = ?
    ");
    $stmt->execute([$proceso_id]);
    $datos = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$datos) {
        throw new Exception('Proceso no encontrado');
    }
    
    // Verificar que tenemos los datos necesarios
    if (empty($datos['address_street'])) {
        $datos['address_street'] = 'No especificada';
    }
    if (empty($datos['address_number'])) {
        $datos['address_number'] = 'S/N';
    }
    if (empty($datos['address_colony'])) {
        $datos['address_colony'] = 'No especificada';
    }
    if (empty($datos['address_municipality'])) {
        $datos['address_municipality'] = 'No especificado';
    }
    if (empty($datos['address_state'])) {
        $datos['address_state'] = 'No especificado';
    }
    if (empty($datos['address_zip'])) {
        $datos['address_zip'] = 'N/A';
    }
    if (empty($datos['description'])) {
        $datos['description'] = 'No especificadas';
    }
    if (empty($datos['asking_price'])) {
        $datos['asking_price'] = 0;
    }
    if (empty($datos['commission_percentage'])) {
        $datos['commission_percentage'] = 0;
    }
    if (empty($datos['seller_name'])) {
        $datos['seller_name'] = 'No especificado';
    }
    if (empty($datos['seller_lastname'])) {
        $datos['seller_lastname'] = '';
    }
    
    // Crear nuevo PDF
    $pdf = new FPDF();
    $pdf->AddPage();
    
    // Configurar título
    $pdf->SetTitle('Contrato de Compraventa - ' . $datos['property_title']);
    
    // Encabezado
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, utf8_decode('CONTRATO DE COMPRAVENTA DE INMUEBLE'), 0, 1, 'C');
    $pdf->Ln(5);
    
    // Fecha
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 7, utf8_decode('Fecha: ' . date('d/m/Y')), 0, 1, 'C');
    $pdf->Cell(0, 7, utf8_decode('Folio: ' . str_pad($proceso_id, 6, '0', STR_PAD_LEFT)), 0, 1, 'C');
    $pdf->Ln(10);
    
    // DECLARACIONES
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, utf8_decode('DECLARACIONES'), 0, 1);
    $pdf->Ln(5);
    
    // Primera declaración
    $pdf->SetFont('Arial', '', 10);
    $direccion = $datos['address_street'] . ' ' . $datos['address_number'] . ', ' . 
                 $datos['address_colony'] . ', ' . $datos['address_municipality'] . ', ' . 
                 $datos['address_state'] . ', C.P. ' . $datos['address_zip'];
    
    $pdf->MultiCell(0, 6, utf8_decode(
        'PRIMERA. - Declara el VENDEDOR ser legítimo propietario del inmueble ubicado en: ' . 
        $direccion . '.'
    ));
    $pdf->Ln(5);
    
    // Segunda declaración
    $pdf->MultiCell(0, 6, utf8_decode(
        'SEGUNDA. - El inmueble objeto de este contrato cuenta con las siguientes características:'
    ));
    $pdf->Ln(2);
    
    $pdf->SetX(20);
    $pdf->MultiCell(170, 6, utf8_decode($datos['description']));
    $pdf->Ln(5);
    
    // Tercera declaración - Precio
    $pdf->MultiCell(0, 6, utf8_decode(
        'TERCERA. - El precio de venta acordado por las partes es de: $' . 
        number_format($datos['asking_price'], 2) . ' M.N.'
    ));
    $pdf->Ln(10);
    
    // CLAUSULAS
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, utf8_decode('CLAUSULAS'), 0, 1);
    $pdf->Ln(5);
    
    $pdf->SetFont('Arial', '', 10);
    
    // Cláusulas
    $clausulas = [
        'PRIMERA. - El VENDEDOR se obliga a transmitir la propiedad del inmueble al COMPRADOR, quien se obliga a pagar el precio pactado en los términos y condiciones establecidos.',
        'SEGUNDA. - El pago se realizará de la siguiente manera:',
        '   a) Cantidad de: $' . number_format($datos['asking_price'], 2) . ' M.N. en la fecha de firma de escrituras.',
        'TERCERA. - La comisión inmobiliaria será del ' . $datos['commission_percentage'] . '% sobre el precio de venta.',
        'CUARTA. - Los gastos de escrituración serán cubiertos según lo acordado por ambas partes.',
        'QUINTA. - La entrega del inmueble se realizará en la fecha establecida de común acuerdo.',
        'SEXTA. - El COMPRADOR declara conocer el estado físico y legal del inmueble.'
    ];
    
    foreach ($clausulas as $clausula) {
        $pdf->MultiCell(0, 6, utf8_decode($clausula));
        $pdf->Ln(2);
    }
    
    // Firmas
    $pdf->Ln(30);
    
    // Guardar posición Y para firmas
    $y = $pdf->GetY();
    
    // Líneas para firmas
    $pdf->Line(30, $y, 80, $y);
    $pdf->Line(120, $y, 170, $y);
    
    $pdf->Ln(5);
    
    // Nombres en las firmas
    $pdf->Cell(80, 5, utf8_decode($datos['seller_name'] . ' ' . $datos['seller_lastname']), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0, 'C');
    $pdf->Cell(80, 5, utf8_decode('COMPRADOR'), 0, 1, 'C');
    
    $pdf->Ln(3);
    $pdf->Cell(80, 5, utf8_decode('VENDEDOR'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0, 'C');
    $pdf->Cell(80, 5, utf8_decode('COMPRADOR'), 0, 1, 'C');
    
    // Nota legal
    $pdf->Ln(30);
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 5, utf8_decode(
        'Este documento es un borrador y debe ser revisado por un profesional legal antes de su firma definitiva.'
    ), 0, 1, 'C');
    
    // Crear directorio si no existe
    $upload_dir = 'uploads/contratos/';
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0777, true)) {
            throw new Exception('No se pudo crear el directorio de uploads');
        }
    }
    
    // Verificar permisos de escritura
    if (!is_writable($upload_dir)) {
        chmod($upload_dir, 0777);
    }
    
    // Generar nombre de archivo
    $filename = 'contrato_compraventa_' . $proceso_id . '_' . date('Ymd_His') . '.pdf';
    $filepath = $upload_dir . $filename;
    
    // Guardar PDF
    $pdf->Output('F', $filepath);
    
    // Verificar que el archivo se creó
    if (!file_exists($filepath)) {
        throw new Exception('No se pudo guardar el archivo PDF');
    }
    
    // Registrar documento en la base de datos
    $stmtDoc = $conn->prepare("
        INSERT INTO tracking_documents 
        (tracking_id, document_type, file_path, generated_at)
        VALUES (?, 'contrato_compraventa', ?, NOW())
    ");
    $stmtDoc->execute([$proceso_id, $filepath]);
    
    // Mensaje de éxito
    $_SESSION['success'] = 'Contrato de compraventa generado correctamente.';
    
    // Redirigir
    header('Location: proceso_detalle.php?id=' . $proceso_id);
    exit;
    
} catch (PDOException $e) {
    error_log("Error de base de datos: " . $e->getMessage());
    $_SESSION['error'] = 'Error de base de datos: ' . $e->getMessage();
    header('Location: proceso_detalle.php?id=' . $proceso_id);
    exit;
    
} catch (Exception $e) {
    error_log("Error al generar contrato: " . $e->getMessage());
    $_SESSION['error'] = 'Error al generar el contrato: ' . $e->getMessage();
    header('Location: proceso_detalle.php?id=' . $proceso_id);
    exit;
}
?>