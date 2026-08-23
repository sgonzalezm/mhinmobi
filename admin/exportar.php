<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Verificar que PHPSpreadsheet esté instalado
if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
    die('Instala PHPSpreadsheet: composer require phpoffice/phpspreadsheet');
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$tipo = $_GET['tipo'] ?? 'comisiones';

// ========== COMISIONES ==========
if ($tipo === 'comisiones') {
    $desde = $_GET['desde'] ?? date('Y-m-01');
    $hasta = $_GET['hasta'] ?? date('Y-m-d');
    
    $stmt = $pdo->prepare("
        SELECT 
            u.name as asesor,
            COUNT(pt.id) as propiedades,
            SUM(f.asking_price) as volumen,
            SUM(f.asking_price * f.commission_percentage / 100) as comisiones,
            AVG(f.asking_price * f.commission_percentage / 100) as promedio
        FROM property_tracking pt
        JOIN properties p ON pt.property_id = p.id
        JOIN property_financials f ON p.id = f.property_id
        JOIN users u ON pt.initiated_by = u.id
        WHERE pt.status = 'completado' AND u.role = 'asesor'
        AND pt.updated_at BETWEEN ? AND ?
        GROUP BY u.id
        ORDER BY comisiones DESC
    ");
    $stmt->execute([$desde . ' 00:00:00', $hasta . ' 23:59:59']);
    $data = $stmt->fetchAll();
    
    $headers = ['Asesor', 'Propiedades', 'Volumen', 'Comisiones', 'Promedio'];
    $rows = array_map(function($r) {
        return [
            $r['asesor'],
            $r['propiedades'],
            number_format($r['volumen'], 2),
            number_format($r['comisiones'], 2),
            number_format($r['promedio'], 2)
        ];
    }, $data);
    
    $filename = "comisiones_{$desde}_a_{$hasta}";
}

// ========== LEADERBOARD ==========
elseif ($tipo === 'leaderboard') {
    $desde = $_GET['desde'] ?? date('Y-m-01');
    $hasta = $_GET['hasta'] ?? date('Y-m-d');
    $tipo_op = $_GET['tipo'] ?? '';
    $estado = $_GET['estado'] ?? '';
    $orden = $_GET['orden'] ?? 'comisiones';
    
    $sql = "
        SELECT 
            u.name as asesor,
            COUNT(pt.id) as propiedades,
            SUM(f.asking_price) as volumen,
            SUM(f.asking_price * f.commission_percentage / 100) as comisiones,
            AVG(DATEDIFF(pt.updated_at, pt.initiated_at)) as eficiencia
        FROM property_tracking pt
        JOIN properties p ON pt.property_id = p.id
        JOIN property_financials f ON p.id = f.property_id
        JOIN users u ON pt.initiated_by = u.id
        WHERE u.role = 'asesor'
    ";
    $params = [];
    
    if ($tipo_op) {
        $sql .= " AND p.operation_type = ?";
        $params[] = $tipo_op;
    }
    
    if ($estado === 'completado') {
        $sql .= " AND pt.status = 'completado' AND pt.updated_at BETWEEN ? AND ?";
        $params[] = $desde . ' 00:00:00';
        $params[] = $hasta . ' 23:59:59';
    } elseif ($estado === 'en_progreso') {
        $sql .= " AND pt.status != 'completado' AND pt.initiated_at BETWEEN ? AND ?";
        $params[] = $desde . ' 00:00:00';
        $params[] = $hasta . ' 23:59:59';
    } else {
        $sql .= " AND pt.initiated_at BETWEEN ? AND ?";
        $params[] = $desde . ' 00:00:00';
        $params[] = $hasta . ' 23:59:59';
    }
    
    $sql .= " GROUP BY u.id ORDER BY comisiones DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();
    
    $headers = ['Asesor', 'Propiedades', 'Volumen', 'Comisiones', 'Eficiencia (días)'];
    $rows = array_map(function($r) {
        return [
            $r['asesor'],
            $r['propiedades'],
            number_format($r['volumen'], 2),
            number_format($r['comisiones'], 2),
            number_format($r['eficiencia'] ?? 0, 1)
        ];
    }, $data);
    
    $filename = "leaderboard_{$desde}_a_{$hasta}";
}

// ========== CREAR EXCEL ==========
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Encabezados
$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . '1', $header);
    $sheet->getStyle($col . '1')->getFont()->setBold(true);
    $col++;
}

// Datos
$row = 2;
foreach ($rows as $rowData) {
    $col = 'A';
    foreach ($rowData as $value) {
        $sheet->setCellValue($col . $row, $value);
        $col++;
    }
    $row++;
}

// Autoajustar
foreach (range('A', $col) as $colID) {
    $sheet->getColumnDimension($colID)->setAutoSize(true);
}

// Descargar
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment;filename=\"{$filename}.xlsx\"");
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>