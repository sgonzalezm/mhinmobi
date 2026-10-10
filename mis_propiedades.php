<?php
session_start();

require_once 'includes/conexion.php';
require_once 'includes/auth.php';

// Verificar autenticación
if (!estaLogueado()) {
    header('Location: login.php');
    exit;
}

// Obtener datos del usuario
$usuario = obtenerUsuarioActual($conn);
if (!$usuario) {
    cerrarSesion();
    header('Location: login.php');
    exit;
}

$vendedor_id = $usuario['id'];

// ============================================
// 0. NIVEL Y RENDIMIENTO DEL MES ACTUAL
// ============================================
$niveles_comision = [];
$nivel_actual = null;
$nivel_siguiente = null;
$comision_porcentaje_actual = 5.0;

$inicio_mes = date('Y-m-01 00:00:00');
$fin_mes    = date('Y-m-t 23:59:59');

$ventas_mes = [];
$stats_mes = [
    'count'             => 0,
    'monto_vendido'     => 0,
    'comision_estimada' => 0,
    'propiedades'       => [] // IDs de propiedades vendidas este mes
];

try {
    // Cargar niveles activos ordenados
    $stmtNiv = $conn->query("
        SELECT * FROM comision_niveles 
        WHERE activo = 1 
        ORDER BY nivel_orden ASC
    ");
    $niveles_comision = $stmtNiv->fetchAll(PDO::FETCH_ASSOC);

    // Propiedades asignadas al vendedor vendidas ESTE MES
    $stmtVendidasMes = $conn->prepare("
        SELECT 
            p.id,
            p.title,
            p.status,
            p.updated_at AS sold_at,
            pf.asking_price
        FROM properties p
        LEFT JOIN property_financials pf ON p.id = pf.property_id
        WHERE p.assigned_to = ?
          AND LOWER(p.status) IN ('vendido', 'completado')
          AND p.updated_at BETWEEN ? AND ?
        ORDER BY p.updated_at DESC
    ");
    $stmtVendidasMes->execute([$vendedor_id, $inicio_mes, $fin_mes]);
    $ventas_mes = $stmtVendidasMes->fetchAll(PDO::FETCH_ASSOC);

    $stats_mes['count'] = count($ventas_mes);
    foreach ($ventas_mes as $v) {
        $stats_mes['monto_vendido'] += (float)($v['asking_price'] ?? 0);
        $stats_mes['propiedades'][] = (int)$v['id'];
    }

    // Determinar nivel según ventas del mes
    foreach ($niveles_comision as $idx => $n) {
        $min = (int)$n['ventas_min'];
        $max = (int)$n['ventas_max'];
        if ($stats_mes['count'] >= $min && $stats_mes['count'] <= $max) {
            $nivel_actual = $n;
            $nivel_siguiente = $niveles_comision[$idx + 1] ?? null;
            break;
        }
    }

    // Fallback: si supera el máximo del último nivel, o está por debajo del primero
    if (!$nivel_actual && !empty($niveles_comision)) {
        $ultimo = end($niveles_comision);
        if ($stats_mes['count'] >= (int)$ultimo['ventas_min']) {
            $nivel_actual = $ultimo;
            $nivel_siguiente = null;
        } else {
            $nivel_actual = $niveles_comision[0];
            $nivel_siguiente = $niveles_comision[1] ?? null;
        }
    }

    if ($nivel_actual) {
        $comision_porcentaje_actual = (float)$nivel_actual['comision_porcentaje'];
    }

    // Comisión estimada del mes
    if ($stats_mes['count'] > 0) {
        $stats_mes['comision_estimada'] = $stats_mes['monto_vendido'] * ($comision_porcentaje_actual / 100);
    }

} catch (PDOException $e) {
    error_log("Error calculando nivel y rendimiento mensual: " . $e->getMessage());
}

// ============================================
// 1. OBTENER PROPIEDADES ASIGNADAS AL VENDEDOR
// ============================================
$propiedades = [];
$error_msg = '';
$alertas = [];

try {
    $stmt = $conn->prepare("
        SELECT 
            p.id,
            p.title,
            p.operation_type,
            p.domicilio,
            p.colonia,
            p.municipio,
            p.estado,
            p.status,
            p.created_at,
            p.updated_at,
            DATEDIFF(NOW(), p.created_at) as days_active,
            pd.square_meters,
            pd.bedrooms,
            pd.bathrooms,
            pd.parking_spots,
            pf.asking_price as price,
            pf.min_acceptable_price,
            pf.potential_profit_margin,
            pm.file_path as image_url,
            pm.is_primary as is_primary_image
        FROM properties p
        LEFT JOIN property_details pd ON p.id = pd.property_id
        LEFT JOIN property_financials pf ON p.id = pf.property_id
        LEFT JOIN property_media pm ON p.id = pm.property_id AND pm.is_primary = 1
        WHERE p.assigned_to = ?
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$vendedor_id]);
    $propiedades = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Si no hay imagen primaria, buscar cualquier imagen
    if (!empty($propiedades)) {
        foreach ($propiedades as $key => $prop) {
            if (empty($prop['image_url'])) {
                $stmtImg = $conn->prepare("
                    SELECT file_path 
                    FROM property_media 
                    WHERE property_id = ? 
                    LIMIT 1
                ");
                $stmtImg->execute([$prop['id']]);
                $img = $stmtImg->fetch(PDO::FETCH_ASSOC);
                if ($img) {
                    $propiedades[$key]['image_url'] = $img['file_path'];
                }
            }
        }
    }

    if (empty($propiedades)) {
        $error_msg = "No tienes propiedades asignadas en el sistema.";
    }

} catch (PDOException $e) {
    $error_msg = "Error al cargar tus propiedades: " . $e->getMessage();
    error_log("Error en mis_propiedades.php: " . $e->getMessage());
}

// ============================================
// 2. CALCULAR MÉTRICAS Y ESTADÍSTICAS
// ============================================
$stats = [
    'total'             => count($propiedades),
    'activas'           => 0,
    'pendientes'        => 0,
    'vendidas'          => 0,
    'suspendidas'       => 0,
    'venta'             => 0,
    'compra'            => 0,
    'con_precio'        => 0,
    'sin_precio'        => 0,
    'con_imagen'        => 0,
    'sin_imagen'        => 0,
    'total_inventario'  => 0,
    'propiedades_riesgo'=> 0
];

foreach ($propiedades as $key => $p) {
    $status = strtolower(trim($p['status'] ?? ''));

    if ($status === 'activo') $stats['activas']++;
    elseif ($status === 'pendiente') $stats['pendientes']++;
    elseif ($status === 'vendido') $stats['vendidas']++;
    elseif ($status === 'suspendido') $stats['suspendidas']++;

    $opType = strtolower(trim($p['operation_type'] ?? ''));
    if ($opType === 'venta') $stats['venta']++;
    if ($opType === 'compra') $stats['compra']++;

    $precioProp = isset($p['price']) ? (float)$p['price'] : 0;
    if ($precioProp > 0) {
        $stats['con_precio']++;
        $stats['total_inventario'] += $precioProp;
    } else {
        $stats['sin_precio']++;
    }

    // Marca de venta del mes
    $propiedades[$key]['vendida_este_mes'] = in_array((int)$p['id'], $stats_mes['propiedades'], true);
    $propiedades[$key]['porcentaje_nivel']  = $comision_porcentaje_actual;

    // Imágenes
    if (!empty($p['image_url'])) {
        $stats['con_imagen']++;
    } else {
        $stats['sin_imagen']++;
    }

    // Propiedades en riesgo
    if ($precioProp <= 0 || empty($p['image_url'])) {
        $stats['propiedades_riesgo']++;
    }

    // Alertas
    if ($precioProp <= 0) {
        $alertas[] = [
            'type' => 'warning',
            'icon' => 'fa-triangle-exclamation',
            'message' => "La propiedad '{$p['title']}' no tiene precio asignado",
            'property_id' => $p['id']
        ];
    }

    if (empty($p['image_url'])) {
        $alertas[] = [
            'type' => 'info',
            'icon' => 'fa-circle-info',
            'message' => "La propiedad '{$p['title']}' no tiene imagen principal",
            'property_id' => $p['id']
        ];
    }

    if (($p['days_active'] ?? 0) > 30 && $status === 'activo') {
        $alertas[] = [
            'type' => 'warning',
            'icon' => 'fa-clock',
            'message' => "La propiedad '{$p['title']}' lleva {$p['days_active']} días activa sin cambios",
            'property_id' => $p['id']
        ];
    }
}

// ============================================
// 3. FUNCIONES AUXILIARES
// ============================================

function formatearPrecio($precio) {
    if ($precio === null || $precio === '' || $precio == 0) {
        return 'Sin asignar';
    }
    return '$' . number_format(floatval($precio), 0, ',', '.');
}

function getOperationBadge($operationType) {
    $opType = strtolower(trim($operationType ?? ''));
    if ($opType === 'venta') {
        return ['class' => 'venta', 'label' => 'Venta', 'icon' => 'fa-tag'];
    } elseif ($opType === 'compra') {
        return ['class' => 'compra', 'label' => 'Compra', 'icon' => 'fa-handshake'];
    } else {
        return ['class' => 'general', 'label' => 'General', 'icon' => 'fa-building'];
    }
}

function getStatusBadge($status) {
    $status = strtolower(trim($status ?? ''));
    if ($status === 'activo') {
        return ['class' => 'status-active', 'label' => 'Activo', 'icon' => 'fa-circle-check'];
    } elseif ($status === 'pendiente') {
        return ['class' => 'status-pending', 'label' => 'Pendiente', 'icon' => 'fa-clock'];
    } elseif ($status === 'vendido') {
        return ['class' => 'status-sold', 'label' => 'Vendido', 'icon' => 'fa-check-double'];
    } elseif ($status === 'suspendido') {
        return ['class' => 'status-suspended', 'label' => 'Suspendido', 'icon' => 'fa-circle-xmark'];
    } else {
        return ['class' => 'status-other', 'label' => ucfirst($status), 'icon' => 'fa-circle'];
    }
}

function getImagePath($imageUrl) {
    if (empty($imageUrl)) {
        return '';
    }
    if (strpos($imageUrl, 'uploads/') === 0) {
        return htmlspecialchars($imageUrl);
    }
    return 'uploads/propiedades/' . htmlspecialchars($imageUrl);
}

function getDetallesCorta($detalles) {
    $parts = [];
    if (!empty($detalles['bedrooms'])) $parts[] = $detalles['bedrooms'] . ' hab';
    if (!empty($detalles['bathrooms'])) $parts[] = $detalles['bathrooms'] . ' baños';
    if (!empty($detalles['parking_spots'])) $parts[] = $detalles['parking_spots'] . ' est';
    if (!empty($detalles['square_meters'])) $parts[] = $detalles['square_meters'] . ' m²';
    return implode(' • ', $parts);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/socios.css">
    <title>Mis Propiedades | Panel Vendedor</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.4.0/exceljs.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>

    <style>
        * { box-sizing: border-box; }

        /* ===== TABLERO DEL MES ===== */
        .dashboard-month {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid #e8edf4;
            border-radius: 14px;
            padding: 20px 22px;
            margin-bottom: 18px;
        }

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .dashboard-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .dashboard-title i { color: #1d4ed8; }

        .month-label {
            font-size: 0.75rem;
            color: #64748b;
            font-weight: 600;
            background: #f1f5f9;
            padding: 3px 10px;
            border-radius: 10px;
            text-transform: capitalize;
        }

        .nivel-badge-lg {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 14px;
            border-radius: 14px;
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.3px;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }

        .dash-block {
            background: #fff;
            border: 1px solid #e8edf4;
            border-radius: 10px;
            padding: 14px 16px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            transition: all 0.2s;
        }

        .dash-block:hover {
            border-color: #c7d2e0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }

        .dash-block.highlight {
            background: linear-gradient(135deg, #f0fdfa 0%, #ffffff 100%);
            border-color: #99f6e4;
        }

        .dash-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }

        .dash-icon.blue   { background: #dbeafe; color: #1d4ed8; }
        .dash-icon.green  { background: #dcfce7; color: #16a34a; }
        .dash-icon.teal   { background: #ccfbf1; color: #0d9488; }

        .dash-info { flex: 1; min-width: 0; }

        .dash-value {
            font-size: 1.35rem;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.2;
        }

        .dash-label {
            font-size: 0.72rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            font-weight: 600;
            margin-top: 2px;
        }

        .dash-sub {
            font-size: 0.72rem;
            color: #94a3b8;
            margin-top: 6px;
            line-height: 1.35;
        }

        .dash-progress {
            margin-top: 8px;
            padding-top: 12px;
            border-top: 1px solid #e8edf4;
        }

        .dash-progress.max-level {
            color: #16a34a;
            font-weight: 600;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .progress-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.82rem;
            color: #334155;
            margin-bottom: 8px;
            gap: 10px;
            flex-wrap: wrap;
        }

        .progress-info i { color: #10b981; margin-right: 4px; }
        .progress-pct { font-weight: 700; color: #0f172a; }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.4s ease;
        }

        .dashboard-note {
            margin-top: 14px;
            font-size: 0.72rem;
            color: #64748b;
            background: #f8fafc;
            padding: 8px 12px;
            border-radius: 8px;
            border-left: 3px solid #3b82f6;
        }

        .dashboard-note i { color: #3b82f6; margin-right: 4px; }

        /* ===== Badge de nivel junto al saludo ===== */
        .nivel-badge-vendedor {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 700;
            margin-left: 8px;
            vertical-align: middle;
            letter-spacing: 0.3px;
        }

        /* ===== Alertas ===== */
        .alerts-container {
            background: #ffffff;
            border: 1px solid #e8edf4;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 16px;
        }

        .alert-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 6px 0;
            font-size: 0.85rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .alert-item:last-child { border-bottom: none; }

        .alert-item .alert-icon.warning { color: #d97706; }
        .alert-item .alert-icon.info { color: #3b82f6; }
        .alert-item .alert-icon.success { color: #16a34a; }
        .alert-item .alert-icon.danger { color: #dc2626; }

        .alert-item .alert-action {
            margin-left: auto;
            font-size: 0.7rem;
            color: #3b82f6;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }

        .alert-item .alert-action:hover { text-decoration: underline; }

        /* ===== TABLA ===== */
        .properties-table-wrapper {
            overflow-x: auto;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            background: #fff;
        }

        .properties-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
            min-width: 1050px;
        }

        .properties-table thead th {
            background: #f8fafc;
            color: #334155;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.68rem;
            letter-spacing: 0.5px;
            padding: 10px 12px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        .properties-table tbody td {
            padding: 10px 12px;
            border-bottom: 1px solid #f1f5f9;
            color: #0f172a;
            vertical-align: middle;
        }

        .properties-table tbody tr:hover { background: #f8faff; }
        .properties-table tbody tr:last-child td { border-bottom: none; }

        .col-img { width: 60px; text-align: center; }

        .col-img .thumb {
            width: 48px;
            height: 48px;
            border-radius: 6px;
            overflow: hidden;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            position: relative;
        }

        .col-img .thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .col-img .thumb .no-image {
            color: #94a3b8;
            font-size: 1.1rem;
        }

        .col-img .thumb .op-badge-mini {
            position: absolute;
            top: -2px;
            left: -2px;
            font-size: 0.45rem;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: white;
        }

        .op-badge-mini.venta { background: #10b981; }
        .op-badge-mini.compra { background: #3b82f6; }
        .op-badge-mini.general { background: #6b7280; }

        .col-title {
            min-width: 180px;
            max-width: 240px;
            font-weight: 600;
        }

        .status-pill {
            display: inline-block;
            font-size: 0.65rem;
            font-weight: 700;
            padding: 2px 10px;
            border-radius: 12px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }
        .status-pill.status-active { background: #dcfce7; color: #166534; }
        .status-pill.status-pending { background: #fef3c7; color: #92400e; }
        .status-pill.status-sold { background: #dbeafe; color: #1e40af; }
        .status-pill.status-suspended { background: #fee2e2; color: #991b1b; }
        .status-pill.status-other { background: #f1f5f9; color: #475569; }

        .col-details {
            font-size: 0.72rem;
            color: #64748b;
            white-space: nowrap;
        }

        .col-price {
            text-align: right;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
            color: #0f172a;
        }
        .col-price.no-price {
            color: #94a3b8;
            font-weight: 500;
            font-size: 0.72rem;
        }

        .col-days {
            text-align: center;
            font-size: 0.75rem;
            color: #64748b;
            white-space: nowrap;
        }
        .col-days.alert {
            color: #d97706;
            font-weight: 700;
        }

        .col-actions {
            text-align: center;
            white-space: nowrap;
        }
        .action-btn {
            width: 30px;
            height: 30px;
            border: none;
            background: transparent;
            color: #94a3b8;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s;
            font-size: 0.8rem;
        }
        .action-btn:hover { background: #f1f5f9; color: #0f172a; }
        .action-btn.view:hover { background: #dbeafe; color: #1d4ed8; }
        .action-btn.edit:hover { background: #dcfce7; color: #16a34a; }

        /* ===== Marcas de venta ===== */
        .badge-vendida-mes {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.68rem;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 12px;
            background: #d1fae5;
            color: #065f46;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .badge-vendida-prev {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.68rem;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 12px;
            background: #f1f5f9;
            color: #64748b;
        }

        .badge-no-vendida {
            color: #cbd5e1;
            font-size: 0.8rem;
        }

        tr.row-sold-month {
            background: #f0fdfa !important;
        }

        tr.row-sold-month:hover {
            background: #e6fbf5 !important;
        }

        /* ===== Toolbar ===== */
        .table-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-bottom: 1px solid #e2e8f0;
            background: #fff;
            flex-wrap: wrap;
        }
        .table-toolbar .toolbar-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .table-toolbar .toolbar-left h3 {
            margin: 0;
            font-size: 0.9rem;
            color: #0f172a;
        }
        .table-toolbar .search-box {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }
        .table-toolbar .search-box input,
        .table-toolbar .search-box select {
            padding: 7px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.8rem;
            outline: none;
            transition: border 0.15s;
        }
        .table-toolbar .search-box input:focus,
        .table-toolbar .search-box select:focus {
            border-color: #1d4ed8;
        }

        .filter-toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.78rem;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
            background: #fff;
            user-select: none;
            transition: all 0.15s;
        }

        .filter-toggle:hover { border-color: #1d4ed8; color: #1d4ed8; }

        .filter-toggle input { accent-color: #10b981; }

        .filter-toggle span i { color: #10b981; margin-right: 2px; }

        .btn-excel {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            background: #16a34a;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
        }
        .btn-excel:hover { background: #15803d; }
        .btn-excel:disabled { background: #94a3b8; cursor: not-allowed; }

        .message-box {
            padding: 12px 16px;
            border-radius: 8px;
            margin: 8px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
        }

        .message-box.info { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
        .message-box.warning { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
        .message-box.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .message-box.success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }

        .table-container { overflow-x: auto; }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
        }

        .empty-state i {
            font-size: 3rem;
            color: #cbd5e1;
            margin-bottom: 15px;
        }

        .empty-state h3 { color: #1e293b; margin-bottom: 8px; }
        .empty-state p { color: #94a3b8; }

        .export-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        .export-overlay.show { display: flex; }
        .export-box {
            background: #fff;
            padding: 24px 32px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .export-box i { font-size: 1.6rem; color: #16a34a; }
        .export-box span { font-size: 0.9rem; font-weight: 600; color: #0f172a; }

        @media (max-width: 768px) {
            .dashboard-grid { grid-template-columns: 1fr; }
            .dashboard-title { font-size: 0.95rem; }
            .table-toolbar { flex-direction: column; align-items: stretch; }
            .table-toolbar .search-box { width: 100%; }
            .table-toolbar .search-box input { flex: 1; }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php include 'modulos/sidebar.php'; ?>

<main class="main-content">
    <div class="main-header">
        <div class="header-left">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <h1>Mis Propiedades</h1>
            <p class="welcome">
                <i class="fas fa-user-tie"></i> Bienvenido, <?php echo htmlspecialchars($usuario['nombre'] ?? 'Vendedor'); ?>
                <?php if ($nivel_actual): ?>
                    <span class="nivel-badge-vendedor"
                          style="background: <?php echo htmlspecialchars($nivel_actual['color_hex']); ?>20;
                                 color: <?php echo htmlspecialchars($nivel_actual['color_hex']); ?>;">
                        <?php echo htmlspecialchars($nivel_actual['icono']); ?>
                        <?php echo htmlspecialchars($nivel_actual['nivel_nombre']); ?>
                        · <?php echo number_format($comision_porcentaje_actual, 1); ?>%
                    </span>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <!-- ===== TABLERO DE RENDIMIENTO DEL MES ===== -->
    <div class="dashboard-month">
        <div class="dashboard-header">
            <div class="dashboard-title">
                <i class="fas fa-chart-line"></i>
                Rendimiento del mes
                <span class="month-label"><?php echo ucfirst(strftime('%B %Y', strtotime(date('Y-m-01')))); ?></span>
            </div>
            <?php if ($nivel_actual): ?>
                <span class="nivel-badge-lg"
                      style="background: <?php echo htmlspecialchars($nivel_actual['color_hex']); ?>20;
                             color: <?php echo htmlspecialchars($nivel_actual['color_hex']); ?>;">
                    <?php echo htmlspecialchars($nivel_actual['icono']); ?>
                    <?php echo htmlspecialchars($nivel_actual['nivel_nombre']); ?>
                    · <?php echo number_format($comision_porcentaje_actual, 1); ?>%
                </span>
            <?php endif; ?>
        </div>

        <div class="dashboard-grid">
            <!-- Ventas del mes -->
            <div class="dash-block">
                <div class="dash-icon blue"><i class="fas fa-handshake"></i></div>
                <div class="dash-info">
                    <div class="dash-value"><?php echo $stats_mes['count']; ?></div>
                    <div class="dash-label">Ventas cerradas este mes</div>
                    <?php if ($nivel_actual): ?>
                        <div class="dash-sub">
                            Nivel <?php echo htmlspecialchars($nivel_actual['nivel_nombre']); ?>
                            (<?php echo $nivel_actual['ventas_min']; ?>–<?php echo $nivel_actual['ventas_max']; ?> ventas)
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Monto vendido -->
            <div class="dash-block">
                <div class="dash-icon green"><i class="fas fa-sack-dollar"></i></div>
                <div class="dash-info">
                    <div class="dash-value"><?php echo formatearPrecio($stats_mes['monto_vendido']); ?></div>
                    <div class="dash-label">Monto vendido este mes</div>
                    <div class="dash-sub">Suma de precios de propiedades vendidas</div>
                </div>
            </div>

            <!-- Comisión estimada -->
            <div class="dash-block highlight">
                <div class="dash-icon teal"><i class="fas fa-coins"></i></div>
                <div class="dash-info">
                    <div class="dash-value">
                        <?php echo $stats_mes['count'] > 0
                            ? formatearPrecio($stats_mes['comision_estimada'])
                            : '—'; ?>
                    </div>
                    <div class="dash-label">Comisión estimada del mes</div>
                    <div class="dash-sub">
                        <?php if ($stats_mes['count'] > 0): ?>
                            <?php echo number_format($comision_porcentaje_actual, 1); ?>% sobre
                            <?php echo formatearPrecio($stats_mes['monto_vendido']); ?>
                        <?php else: ?>
                            Aún sin ventas este mes
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Barra de progreso al siguiente nivel -->
        <?php if ($nivel_siguiente): ?>
            <?php
                $min_actual = (int)$nivel_actual['ventas_min'];
                $meta = (int)$nivel_siguiente['ventas_min'];
                $progreso = $meta > $min_actual
                    ? min(100, (($stats_mes['count'] - $min_actual) / max(1, $meta - $min_actual)) * 100)
                    : 0;
                $progreso = max(0, $progreso);
                $faltan = max(0, $meta - $stats_mes['count']);
            ?>
            <div class="dash-progress">
                <div class="progress-info">
                    <span>
                        <i class="fas fa-arrow-trend-up"></i>
                        Faltan <strong><?php echo $faltan; ?></strong> venta<?php echo $faltan === 1 ? '' : 's'; ?> para llegar a
                        <strong><?php echo htmlspecialchars($nivel_siguiente['nivel_nombre']); ?></strong>
                        (<?php echo number_format((float)$nivel_siguiente['comision_porcentaje'], 1); ?>%)
                    </span>
                    <span class="progress-pct"><?php echo round($progreso); ?>%</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill"
                         style="width: <?php echo $progreso; ?>%;
                                background: <?php echo htmlspecialchars($nivel_siguiente['color_hex']); ?>;">
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="dash-progress max-level">
                <i class="fas fa-trophy"></i>
                ¡Estás en el nivel más alto! Comisión del <?php echo number_format($comision_porcentaje_actual, 1); ?>% aplicada.
            </div>
        <?php endif; ?>

        <div class="dashboard-note">
            <i class="fas fa-info-circle"></i>
            El nivel se calcula por las ventas cerradas del <strong>mes calendario</strong>
            (<?php echo date('d/m', strtotime($inicio_mes)); ?> al <?php echo date('d/m', strtotime($fin_mes)); ?>).
            Las comisiones son <strong>estimadas</strong> hasta que se escritura cada operación.
        </div>
    </div>

    <!-- ===== ALERTAS ===== -->
    <?php if (!empty($alertas)): ?>
    <div class="alerts-container">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
            <i class="fas fa-bell" style="color: #d97706;"></i>
            <span style="font-weight: 600; font-size: 0.9rem; color: #0f172a;">Alertas y acciones pendientes</span>
            <span style="font-size: 0.7rem; color: #94a3b8; margin-left: auto;"><?php echo count($alertas); ?> alertas</span>
        </div>
        <?php foreach ($alertas as $alerta): ?>
        <div class="alert-item">
            <span class="alert-icon <?php echo $alerta['type']; ?>">
                <i class="fas <?php echo $alerta['icon']; ?>"></i>
            </span>
            <span><?php echo htmlspecialchars($alerta['message']); ?></span>
            <span class="alert-action" onclick="editarPropiedad('<?php echo $alerta['property_id']; ?>')">
                <i class="fas fa-arrow-right"></i> Ir a propiedad
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ===== LISTADO DE PROPIEDADES ===== -->
    <div class="table-container">
        <div class="table-toolbar">
            <div class="toolbar-left">
                <h3><i class="fas fa-list-ul"></i> Listado de mis propiedades asignadas</h3>
            </div>
            <div class="search-box">
                <input type="text" placeholder="Buscar por título o ubicación..." id="searchTable">
                <select id="filterOperation">
                    <option value="">Todas las operaciones</option>
                    <option value="venta">Venta</option>
                    <option value="compra">Compra</option>
                </select>
                <select id="filterStatus">
                    <option value="">Todos los estados</option>
                    <option value="activo">Activo</option>
                    <option value="pendiente">Pendiente</option>
                    <option value="vendido">Vendido</option>
                    <option value="suspendido">Suspendido</option>
                </select>
                <label class="filter-toggle">
                    <input type="checkbox" id="filterVendidasMes">
                    <span><i class="fas fa-check-circle"></i> Solo vendidas del mes</span>
                </label>
                <button class="btn-excel" id="btnExportar" onclick="exportarExcel()">
                    <i class="fas fa-file-excel"></i> Exportar Excel
                </button>
            </div>
        </div>

        <div style="padding: 16px 20px;">
            <?php if (!empty($error_msg)): ?>
                <div class="message-box <?php echo strpos($error_msg, 'Error') !== false ? 'error' : 'info'; ?>">
                    <i class="fas <?php echo strpos($error_msg, 'Error') !== false ? 'fa-exclamation-circle' : 'fa-info-circle'; ?>"></i>
                    <span><?php echo htmlspecialchars($error_msg); ?></span>
                </div>
            <?php endif; ?>

            <?php if (empty($propiedades) && empty($error_msg)): ?>
                <div class="empty-state">
                    <i class="fas fa-home"></i>
                    <h3>No tienes propiedades asignadas</h3>
                    <p>Cuando un administrador te asigne propiedades, aparecerán aquí</p>
                </div>
            <?php elseif (!empty($propiedades)): ?>
                <div class="properties-table-wrapper">
                    <table class="properties-table" id="propertiesTable">
                        <thead>
                            <tr>
                                <th class="col-img">Imagen</th>
                                <th class="col-title">Título</th>
                                <th>Operación</th>
                                <th>Estado</th>
                                <th>Ubicación</th>
                                <th>Detalles</th>
                                <th style="text-align:center;">Días</th>
                                <th style="text-align:right;">Precio</th>
                                <th style="text-align:center;">Venta</th>
                                <th class="col-actions">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($propiedades as $propiedad):
                                $badge = getOperationBadge($propiedad['operation_type'] ?? '');
                                $statusBadge = getStatusBadge($propiedad['status'] ?? '');
                                $price = $propiedad['price'] ?? null;
                                $hasPrice = ($price !== null && $price > 0);
                                $priceClass = $hasPrice ? '' : 'no-price';
                                $imagePath = getImagePath($propiedad['image_url'] ?? '');
                                $hasImage = !empty($imagePath);
                                $title = htmlspecialchars($propiedad['title'] ?? 'Sin título');
                                $domicilio = htmlspecialchars($propiedad['domicilio'] ?? '');
                                $colonia = htmlspecialchars($propiedad['colonia'] ?? '');
                                $municipio = htmlspecialchars($propiedad['municipio'] ?? '');
                                $estado = htmlspecialchars($propiedad['estado'] ?? '');
                                $location = $municipio . ($colonia ? ', ' . $colonia : '');
                                if (empty($location)) $location = $estado ?: 'No especificada';
                                $details = getDetallesCorta($propiedad);
                                $opType = strtolower(trim($propiedad['operation_type'] ?? ''));
                                $statusLower = strtolower(trim($propiedad['status'] ?? ''));
                                $days = (int)($propiedad['days_active'] ?? 0);
                                $daysAlert = ($days > 30 && $statusLower === 'activo');
                                $vendidaMes = !empty($propiedad['vendida_este_mes']);
                                $esVendida = in_array($statusLower, ['vendido', 'completado'], true);
                                $rowClass = $vendidaMes ? 'row-sold-month' : '';
                            ?>
                                <tr class="<?php echo $rowClass; ?>"
                                    data-text="<?php echo strtolower($title . ' ' . $location); ?>"
                                    data-operation="<?php echo $opType; ?>"
                                    data-status="<?php echo $statusLower; ?>"
                                    data-vendida-mes="<?php echo $vendidaMes ? '1' : '0'; ?>">
                                    <td class="col-img">
                                        <div class="thumb">
                                            <span class="op-badge-mini <?php echo $badge['class']; ?>">
                                                <?php echo substr($badge['label'], 0, 1); ?>
                                            </span>
                                            <?php if ($hasImage): ?>
                                                <img src="<?php echo $imagePath; ?>"
                                                     alt="<?php echo $title; ?>"
                                                     loading="lazy"
                                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                <div class="no-image" style="display:none;"><i class="fas fa-image"></i></div>
                                            <?php else: ?>
                                                <div class="no-image"><i class="fas fa-building"></i></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="col-title" data-raw-title="<?php echo $title; ?>">
                                        <?php echo $title; ?>
                                    </td>
                                    <td><?php echo $badge['label']; ?></td>
                                    <td>
                                        <span class="status-pill <?php echo $statusBadge['class']; ?>">
                                            <?php echo $statusBadge['label']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $location; ?></td>
                                    <td class="col-details"><?php echo $details ?: '—'; ?></td>
                                    <td class="col-days <?php echo $daysAlert ? 'alert' : ''; ?>">
                                        <?php echo $days; ?>d
                                        <?php if ($daysAlert): ?>
                                            <i class="fas fa-exclamation-triangle" style="margin-left:3px;"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-price <?php echo $priceClass; ?>"
                                        data-raw-price="<?php echo $hasPrice ? floatval($price) : ''; ?>">
                                        <?php if ($hasPrice): ?>
                                            <?php echo formatearPrecio($price); ?>
                                        <?php else: ?>
                                            Sin precio
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if ($vendidaMes): ?>
                                            <span class="badge-vendida-mes" title="Vendida este mes">
                                                <i class="fas fa-check-circle"></i> Este mes
                                            </span>
                                        <?php elseif ($esVendida): ?>
                                            <span class="badge-vendida-prev" title="Vendida en un mes anterior">
                                                <i class="fas fa-check"></i> Vendida
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-no-vendida">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-actions">
                                        <button class="action-btn view" title="Ver detalles" onclick="verPropiedad('<?php echo $propiedad['id']; ?>')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="action-btn edit" title="Editar" onclick="editarPropiedad('<?php echo $propiedad['id']; ?>')">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (isset($_GET['debug'])): ?>
                    <div style="margin-top: 20px; padding: 16px; background: #f8fafc; border-radius: 8px; overflow-x: auto; font-size: 12px;">
                        <h4 style="margin: 0 0 10px 0; font-size: 0.85rem;">Datos de depuración:</h4>
                        <pre style="margin: 0;"><?php print_r($propiedades); ?></pre>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</main>

<div class="export-overlay" id="exportOverlay">
    <div class="export-box">
        <i class="fas fa-file-excel fa-spin"></i>
        <span>Generando archivo Excel...</span>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    function toggleSidebar() {
        if (sidebar && overlay) {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('show');
            document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
        }
    }

    if(menuToggle) menuToggle.addEventListener('click', toggleSidebar);
    if(overlay) overlay.addEventListener('click', toggleSidebar);

    document.querySelectorAll('.sidebar nav a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 992 && sidebar && sidebar.classList.contains('open')) {
                toggleSidebar();
            }
        });
    });

    // ===== Filtros =====
    const searchInput = document.getElementById('searchTable');
    const filterOperation = document.getElementById('filterOperation');
    const filterStatus = document.getElementById('filterStatus');
    const filterVendidasMes = document.getElementById('filterVendidasMes');

    function filtrarFilas() {
        const searchText = (searchInput.value || '').toLowerCase().trim();
        const operationVal = (filterOperation.value || '').toLowerCase().trim();
        const statusVal = (filterStatus.value || '').toLowerCase().trim();
        const soloVendidasMes = filterVendidasMes && filterVendidasMes.checked;
        const rows = document.querySelectorAll('.properties-table tbody tr');

        rows.forEach(row => {
            const rowText = (row.getAttribute('data-text') || '').toLowerCase();
            const rowOp = (row.getAttribute('data-operation') || '').toLowerCase();
            const rowStatus = (row.getAttribute('data-status') || '').toLowerCase();
            const vendidaMes = row.getAttribute('data-vendida-mes') === '1';

            const matchesSearch = rowText.includes(searchText);
            const matchesOp = operationVal === '' || rowOp === operationVal;
            const matchesStatus = statusVal === '' || rowStatus === statusVal;
            const matchesVendida = !soloVendidasMes || vendidaMes;

            row.style.display = (matchesSearch && matchesOp && matchesStatus && matchesVendida) ? '' : 'none';
        });
    }

    if(searchInput) searchInput.addEventListener('keyup', filtrarFilas);
    if(filterOperation) filterOperation.addEventListener('change', filtrarFilas);
    if(filterStatus) filterStatus.addEventListener('change', filtrarFilas);
    if(filterVendidasMes) filterVendidasMes.addEventListener('change', filtrarFilas);

    // ===== Acciones =====
    window.nuevaPropiedad = function() {
        window.location.href = 'propiedad_nueva.php';
    };

    window.verPropiedad = function(id) {
        window.location.href = 'propiedad_detalle_vendedor.php?id=' + id;
    };

    window.editarPropiedad = function(id) {
        window.location.href = 'propiedad_detalle_vendedor.php?id=' + id;
    };
});

// ===== Exportar a Excel =====
window.exportarExcel = async function() {
    const tabla = document.getElementById('propertiesTable');
    if (!tabla) {
        alert('No hay datos para exportar.');
        return;
    }

    if (typeof ExcelJS === 'undefined' || typeof saveAs === 'undefined') {
        alert('Las librerías de exportación no se cargaron. Verifica tu conexión a internet.');
        return;
    }

    const overlay = document.getElementById('exportOverlay');
    const btnExportar = document.getElementById('btnExportar');
    if (overlay) overlay.classList.add('show');
    if (btnExportar) btnExportar.disabled = true;

    try {
        const filas = [];
        const headers = [];

        // Encabezados (excepto la última columna "Acciones")
        tabla.querySelectorAll('thead th').forEach((th, idx, arr) => {
            if (idx < arr.length - 1) {
                headers.push(th.innerText.trim().toUpperCase());
            }
        });

        // Filas (excepto la última celda)
        tabla.querySelectorAll('tbody tr').forEach(tr => {
            const celdas = tr.querySelectorAll('td');
            const fila = [];
            celdas.forEach((td, idx) => {
                if (idx < celdas.length - 1) {
                    // Precio
                    const rawPrice = td.getAttribute('data-raw-price');
                    if (rawPrice !== null && rawPrice !== '' && !isNaN(parseFloat(rawPrice))) {
                        fila.push({ tipo: 'numero', valor: parseFloat(rawPrice) });
                        return;
                    }
                    if (rawPrice !== null && rawPrice === '') {
                        fila.push({ tipo: 'texto', valor: 'Sin precio' });
                        return;
                    }

                    // Título
                    const rawTitle = td.getAttribute('data-raw-title');
                    if (rawTitle !== null) {
                        fila.push({ tipo: 'texto', valor: rawTitle.trim() });
                        return;
                    }

                    // Resto
                    const clon = td.cloneNode(true);
                    clon.querySelectorAll('[data-exclude="true"]').forEach(el => el.remove());
                    clon.querySelectorAll('i.fa').forEach(el => el.remove());
                    let texto = clon.innerText.trim().replace(/\s+/g, ' ');
                    fila.push({ tipo: 'texto', valor: texto });
                }
            });
            filas.push(fila);
        });

        if (filas.length === 0) {
            alert('No hay datos para exportar.');
            return;
        }

        const workbook = new ExcelJS.Workbook();
        workbook.creator = 'Vera Terra Inmobiliaria';
        workbook.created = new Date();

        const worksheet = workbook.addWorksheet('Mis Propiedades', {
            views: [{ state: 'frozen', ySplit: 4 }]
        });

        // Anchos
        const anchos = headers.map((h, colIdx) => {
            let maxLen = h.length;
            filas.forEach(fila => {
                const cell = fila[colIdx];
                if (!cell) return;
                let texto = '';
                if (cell.tipo === 'numero') {
                    texto = '$' + cell.valor.toLocaleString('en-US');
                } else {
                    texto = String(cell.valor || '');
                }
                if (texto.length > maxLen) maxLen = texto.length;
            });
            let ancho = maxLen + 4;
            if (ancho < 12) ancho = 12;
            if (ancho > 55) ancho = 55;
            return ancho;
        });

        worksheet.columns = anchos.map(w => ({ width: w }));

        // Logo
        try {
            const logoUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/') + 'css/Logo1_veraterra.png';
            const resp = await fetch(logoUrl);
            if (resp.ok) {
                const blob = await resp.blob();
                const arrayBuffer = await blob.arrayBuffer();
                const imageId = workbook.addImage({
                    buffer: arrayBuffer,
                    extension: 'png'
                });
                worksheet.addImage(imageId, {
                    tl: { col: 0, row: 0 },
                    ext: { width: 110, height: 60 }
                });
            }
        } catch (e) {
            console.warn('No se pudo cargar el logo:', e);
        }

        const totalCols = headers.length;
        const ultimaColLetra = String.fromCharCode(64 + totalCols);

        worksheet.mergeCells(`B1:${ultimaColLetra}1`);
        const tituloCell = worksheet.getCell('B1');
        tituloCell.value = 'Vera Terra Inmobiliaria';
        tituloCell.font = { name: 'Calibri', size: 18, bold: true, color: { argb: 'FF1D4ED8' } };
        tituloCell.alignment = { vertical: 'middle', horizontal: 'left' };

        worksheet.mergeCells(`B2:${ultimaColLetra}2`);
        const subtituloCell = worksheet.getCell('B2');
        subtituloCell.value = 'Mis Propiedades - Panel Vendedor';
        subtituloCell.font = { name: 'Calibri', size: 12, color: { argb: 'FF475569' } };
        subtituloCell.alignment = { vertical: 'middle', horizontal: 'left' };

        worksheet.mergeCells(`B3:${ultimaColLetra}3`);
        const fechaCell = worksheet.getCell('B3');
        const fecha = new Date().toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' });
        fechaCell.value = `Generado el ${fecha}`;
        fechaCell.font = { name: 'Calibri', size: 9, italic: true, color: { argb: 'FF64748B' } };
        fechaCell.alignment = { vertical: 'middle', horizontal: 'left' };

        worksheet.getRow(1).height = 24;
        worksheet.getRow(2).height = 20;
        worksheet.getRow(3).height = 16;
        worksheet.getRow(4).height = 22;

        // Encabezados
        const headerRow = worksheet.getRow(4);
        headers.forEach((h, i) => {
            const cell = headerRow.getCell(i + 1);
            cell.value = h;
            cell.font = { name: 'Calibri', size: 10, bold: true, color: { argb: 'FFFFFFFF' } };
            cell.fill = {
                type: 'pattern',
                pattern: 'solid',
                fgColor: { argb: 'FF1D4ED8' }
            };
            cell.alignment = { vertical: 'middle', horizontal: 'left' };
            cell.border = {
                top:    { style: 'thin', color: { argb: 'FF1E40AF' } },
                left:   { style: 'thin', color: { argb: 'FF1E40AF' } },
                bottom: { style: 'thin', color: { argb: 'FF1E40AF' } },
                right:  { style: 'thin', color: { argb: 'FF1E40AF' } }
            };
        });

        // Filas
        filas.forEach((fila, rowIdx) => {
            const row = worksheet.getRow(5 + rowIdx);
            const esPar = rowIdx % 2 === 0;

            fila.forEach((celda, colIdx) => {
                const cell = row.getCell(colIdx + 1);

                if (celda.tipo === 'numero') {
                    cell.value = celda.valor;
                    cell.numFmt = '"$"#,##0';
                    cell.alignment = { vertical: 'middle', horizontal: 'right' };
                    cell.font = { name: 'Calibri', size: 10, bold: true, color: { argb: 'FF0F172A' } };
                } else {
                    cell.value = celda.valor || '';
                    if (String(celda.valor).toLowerCase().includes('sin precio') ||
                        String(celda.valor).toLowerCase().includes('no calculable')) {
                        cell.font = { name: 'Calibri', size: 10, italic: true, color: { argb: 'FF94A3B8' } };
                        cell.alignment = { vertical: 'middle', horizontal: 'right' };
                    } else {
                        cell.font = { name: 'Calibri', size: 10, color: { argb: 'FF0F172A' } };
                        cell.alignment = { vertical: 'middle', horizontal: 'left', wrapText: false };
                    }
                }

                cell.fill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: { argb: esPar ? 'FFF8FAFC' : 'FFFFFFFF' }
                };

                cell.border = {
                    top:    { style: 'thin', color: { argb: 'FFE2E8F0' } },
                    left:   { style: 'thin', color: { argb: 'FFE2E8F0' } },
                    bottom: { style: 'thin', color: { argb: 'FFE2E8F0' } },
                    right:  { style: 'thin', color: { argb: 'FFE2E8F0' } }
                };
            });

            row.height = 20;
        });

        worksheet.autoFilter = {
            from: { row: 4, column: 1 },
            to:   { row: 4, column: headers.length }
        };

        const buffer = await workbook.xlsx.writeBuffer();
        const blob = new Blob([buffer], {
            type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        });
        const nombreArchivo = `Mis_Propiedades_${new Date().toISOString().slice(0, 10)}.xlsx`;
        saveAs(blob, nombreArchivo);

    } catch (error) {
        console.error('Error al exportar:', error);
        alert('Ocurrió un error al generar el archivo Excel. Revisa la consola para más detalles.');
    } finally {
        if (overlay) overlay.classList.remove('show');
        if (btnExportar) btnExportar.disabled = false;
    }
};
</script>

</body>
</html>