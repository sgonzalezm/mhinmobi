<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$tipo = $_GET['tipo'] ?? '';
$estado = $_GET['estado'] ?? '';
$orden = $_GET['orden'] ?? 'comisiones';

$sql = "
    SELECT 
        u.id, u.name as asesor_nombre,
        COUNT(pt.id) as total_propiedades,
        SUM(f.asking_price) as volumen_ventas,
        SUM(f.asking_price * f.commission_percentage / 100) as total_comisiones,
        AVG(f.asking_price * f.commission_percentage / 100) as promedio_comision,
        AVG(DATEDIFF(pt.updated_at, pt.initiated_at)) as eficiencia
    FROM property_tracking pt
    JOIN properties p ON pt.property_id = p.id
    JOIN property_financials f ON p.id = f.property_id
    JOIN users u ON pt.initiated_by = u.id
    WHERE u.role = 'asesor'
";

$params = [];

if ($tipo) {
    $sql .= " AND p.operation_type = ?";
    $params[] = $tipo;
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

$sql .= " GROUP BY u.id";

$orden_map = [
    'ventas' => 'volumen_ventas DESC',
    'propiedades' => 'total_propiedades DESC',
    'eficiencia' => 'eficiencia ASC',
    'comisiones' => 'total_comisiones DESC'
];
$sql .= " ORDER BY " . ($orden_map[$orden] ?? 'total_comisiones DESC');

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll());
?>