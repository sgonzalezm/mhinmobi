<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$page = $_GET['page'] ?? 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// ======== ESTADÍSTICAS ========
$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT u.id) as total_asesores,
        COUNT(pt.id) as total_propiedades,
        SUM(f.asking_price * f.commission_percentage / 100) as total_comisiones,
        AVG(f.asking_price * f.commission_percentage / 100) as promedio_comision
    FROM property_tracking pt
    JOIN properties p ON pt.property_id = p.id
    JOIN property_financials f ON p.id = f.property_id
    JOIN users u ON pt.initiated_by = u.id
    WHERE pt.status = 'completado' AND u.role = 'asesor'
    AND pt.updated_at BETWEEN ? AND ?
");
$stmt->execute([$desde . ' 00:00:00', $hasta . ' 23:59:59']);
$stats = $stmt->fetch();

// ======== TABLA ========
$stmt = $pdo->prepare("
    SELECT 
        u.id as asesor_id,
        u.name as asesor_nombre,
        COUNT(pt.id) as total_propiedades,
        SUM(f.asking_price * f.commission_percentage / 100) as total_comisiones,
        AVG(f.asking_price * f.commission_percentage / 100) as promedio_comision,
        MAX(f.asking_price) as precio_maximo,
        MAX(pt.updated_at) as ultima_venta
    FROM property_tracking pt
    JOIN properties p ON pt.property_id = p.id
    JOIN property_financials f ON p.id = f.property_id
    JOIN users u ON pt.initiated_by = u.id
    WHERE pt.status = 'completado' AND u.role = 'asesor'
    AND pt.updated_at BETWEEN ? AND ?
    GROUP BY u.id
    ORDER BY total_comisiones DESC
    LIMIT ? OFFSET ?
");
$stmt->execute([$desde . ' 00:00:00', $hasta . ' 23:59:59', $limit, $offset]);
$data = $stmt->fetchAll();

// ======== TOTAL ========
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT u.id) as total 
    FROM property_tracking pt
    JOIN users u ON pt.initiated_by = u.id
    WHERE pt.status = 'completado' AND u.role = 'asesor'
    AND pt.updated_at BETWEEN ? AND ?
");
$stmt->execute([$desde . ' 00:00:00', $hasta . ' 23:59:59']);
$total = $stmt->fetch()['total'];

echo json_encode(['stats' => $stats, 'data' => $data, 'total' => $total]);
?>