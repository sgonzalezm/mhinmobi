<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

$tipo = $_GET['tipo'] ?? 'mensual';
$mes = $_GET['mes'] ?? date('Y-m');
$meta = $_GET['meta'] ?? 100000;

// Calcular fechas
$inicio = date('Y-m-01', strtotime($mes . '-01'));
$fin = date('Y-m-t', strtotime($mes . '-01'));

if ($tipo === 'trimestral') {
    $inicio = date('Y-m-01', strtotime('-2 months', strtotime($inicio)));
    $fin = date('Y-m-t', strtotime('+2 months', strtotime($fin)));
} elseif ($tipo === 'anual') {
    $inicio = date('Y-01-01', strtotime($mes . '-01'));
    $fin = date('Y-12-31', strtotime($mes . '-01'));
}

// Obtener datos
$stmt = $pdo->prepare("
    SELECT 
        u.id, u.name as asesor_nombre,
        COUNT(pt.id) as total_propiedades,
        SUM(f.asking_price * f.commission_percentage / 100) as comision_estimada,
        AVG(
            CASE pt.current_stage
                WHEN 'inventario' THEN 10
                WHEN 'contrato_compraventa' THEN 30
                WHEN 'poder_notarial' THEN 40
                WHEN 'credito' THEN 60
                WHEN 'compra_venta' THEN 80
                WHEN 'recepcion_recursos' THEN 90
                WHEN 'pagos_proveedores' THEN 95
                WHEN 'finalizado' THEN 100
                ELSE 0
            END
        ) as probabilidad_promedio
    FROM property_tracking pt
    JOIN properties p ON pt.property_id = p.id
    JOIN property_financials f ON p.id = f.property_id
    JOIN users u ON pt.initiated_by = u.id
    WHERE pt.status != 'completado' AND u.role = 'asesor'
    AND pt.initiated_at BETWEEN ? AND ?
    GROUP BY u.id
");
$stmt->execute([$inicio . ' 00:00:00', $fin . ' 23:59:59']);
$asesores = $stmt->fetchAll();

$total_proyeccion = 0;
foreach ($asesores as &$a) {
    $a['proyeccion'] = $a['comision_estimada'] * ($a['probabilidad_promedio'] / 100);
    $total_proyeccion += $a['proyeccion'];
}

echo json_encode([
    'total' => $total_proyeccion,
    'meta' => $meta,
    'cumplimiento' => $meta > 0 ? ($total_proyeccion / $meta) * 100 : 0,
    'propiedades' => count($asesores),
    'asesores' => $asesores
]);
?>