<?php
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

// Obtener datos del usuario
$usuario = obtenerUsuarioActual($conn);
if (!$usuario) {
    cerrarSesion();
    header('Location: login.php');
    exit;
}

$vendedor_id = $usuario['id'];

// ============================================
// 1. OBTENER PROPIEDADES DEL VENDEDOR
// ============================================
$propiedades = [];
$error_msg = '';
$alertas = [];

try {
    // Consulta principal con JOIN a las tablas correctas
    $stmt = $conn->prepare("
        SELECT 
            p.id,
            p.title,
            p.operation_type,
            p.address_municipality,
            p.address_city,
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
            pf.commission_percentage,
            pm.file_path as image_url,
            pm.is_primary as is_primary_image
        FROM properties p
        LEFT JOIN property_details pd ON p.id = pd.property_id
        LEFT JOIN property_financials pf ON p.id = pf.property_id
        LEFT JOIN property_media pm ON p.id = pm.property_id AND pm.is_primary = 1
        WHERE p.owner_id = ?
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
        $error_msg = "No tienes propiedades registradas en el sistema.";
    }

} catch (PDOException $e) {
    $error_msg = "Error al cargar tus propiedades: " . $e->getMessage();
    error_log("Error en mis_propiedades.php: " . $e->getMessage());
}

// ============================================
// 2. CALCULAR MÉTRICAS Y ESTADÍSTICAS
// ============================================
$stats = [
    'total' => count($propiedades),
    'activas' => 0,
    'pendientes' => 0,
    'vendidas' => 0,
    'suspendidas' => 0,
    'venta' => 0,
    'compra' => 0,
    'con_precio' => 0,
    'sin_precio' => 0,
    'con_imagen' => 0,
    'sin_imagen' => 0,
    'total_inventario' => 0,
    'comision_potencial_total' => 0,
    'propiedades_riesgo' => 0
];

foreach ($propiedades as $p) {
    // Estadísticas por estado
    $status = strtolower(trim($p['status'] ?? ''));
    if ($status === 'activo') $stats['activas']++;
    elseif ($status === 'pendiente') $stats['pendientes']++;
    elseif ($status === 'vendido') $stats['vendidas']++;
    elseif ($status === 'suspendido') $stats['suspendidas']++;
    
    // Estadísticas por operación
    $opType = strtolower(trim($p['operation_type'] ?? ''));
    if ($opType === 'venta') $stats['venta']++;
    if ($opType === 'compra') $stats['compra']++;
    
    // Precios
    if (isset($p['price']) && $p['price'] > 0) {
        $stats['con_precio']++;
        $stats['total_inventario'] += $p['price'];
        // Calcular comisión potencial (asking_price * commission_percentage / 100)
        $commissionAmount = ($p['price'] * ($p['commission_percentage'] ?? 0)) / 100;
        $stats['comision_potencial_total'] += $commissionAmount;
    } else {
        $stats['sin_precio']++;
    }
    
    // Imágenes
    if (!empty($p['image_url'])) {
        $stats['con_imagen']++;
    } else {
        $stats['sin_imagen']++;
    }
    
    // Propiedades en riesgo
    if (empty($p['price']) || $p['price'] == 0 || empty($p['image_url'])) {
        $stats['propiedades_riesgo']++;
    }
    
    // Alertas específicas
    if (empty($p['price']) || $p['price'] == 0) {
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
    
    if (($p['days_active'] ?? 0) > 30 && $p['status'] === 'activo') {
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
    <style>
        /* ===== ESTILOS CORPORATIVOS ===== */
        * { box-sizing: border-box; }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .metric-card {
            background: #ffffff;
            border: 1px solid #e8edf4;
            border-radius: 10px;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.2s;
        }

        .metric-card:hover {
            border-color: #c7d2e0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }

        .metric-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .metric-icon.blue { background: #dbeafe; color: #1d4ed8; }
        .metric-icon.green { background: #dcfce7; color: #16a34a; }
        .metric-icon.purple { background: #ede9fe; color: #7c3aed; }
        .metric-icon.orange { background: #fef3c7; color: #d97706; }
        .metric-icon.red { background: #fee2e2; color: #dc2626; }
        .metric-icon.teal { background: #ccfbf1; color: #0d9488; }
        .metric-icon.pink { background: #fce7f3; color: #db2777; }

        .metric-info {
            flex: 1;
            min-width: 0;
        }

        .metric-value {
            font-size: 1.3rem;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.2;
        }

        .metric-label {
            font-size: 0.7rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            font-weight: 600;
        }

        .metric-trend {
            font-size: 0.65rem;
            font-weight: 600;
            padding: 1px 8px;
            border-radius: 10px;
            margin-left: auto;
            white-space: nowrap;
        }

        .metric-trend.positive { background: #dcfce7; color: #16a34a; }
        .metric-trend.negative { background: #fee2e2; color: #dc2626; }
        .metric-trend.neutral { background: #f1f5f9; color: #475569; }

        /* Alertas */
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

        .alert-item:last-child {
            border-bottom: none;
        }

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

        .alert-item .alert-action:hover {
            text-decoration: underline;
        }

        /* Lista de propiedades */
        .properties-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-top: 12px;
        }

        .property-row {
            display: flex;
            align-items: center;
            background: #ffffff;
            border: 1px solid #e8edf4;
            border-radius: 8px;
            padding: 8px 12px;
            transition: all 0.15s ease;
            gap: 12px;
            min-height: 60px;
        }

        .property-row:hover {
            background: #f8faff;
            border-color: #c7d2e0;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
        }

        .property-row-image {
            width: 44px;
            min-width: 44px;
            height: 44px;
            border-radius: 6px;
            overflow: hidden;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            flex-shrink: 0;
        }

        .property-row-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .property-row-image .no-image {
            color: #94a3b8;
            font-size: 1rem;
        }

        .property-row-badge {
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

        .property-row-badge.venta { background: #10b981; }
        .property-row-badge.compra { background: #3b82f6; }
        .property-row-badge.general { background: #6b7280; }

        .property-row-info {
            flex: 1;
            min-width: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .property-row-title {
            font-size: 0.85rem;
            font-weight: 600;
            color: #0f172a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
        }

        .property-row-title i {
            color: #64748b;
            font-size: 0.7rem;
            margin-right: 4px;
        }

        .property-row-location {
            font-size: 0.7rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }

        .property-row-location i {
            color: #10b981;
            font-size: 0.65rem;
        }

        .property-row-details {
            font-size: 0.65rem;
            color: #94a3b8;
            display: flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }

        .property-row-details i {
            font-size: 0.6rem;
        }

        .property-row-status {
            font-size: 0.6rem;
            font-weight: 600;
            padding: 2px 10px;
            border-radius: 12px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        .property-row-status.status-active { background: #dcfce7; color: #166534; }
        .property-row-status.status-pending { background: #fef3c7; color: #92400e; }
        .property-row-status.status-sold { background: #dbeafe; color: #1e40af; }
        .property-row-status.status-suspended { background: #fee2e2; color: #991b1b; }
        .property-row-status.status-other { background: #f1f5f9; color: #475569; }

        .property-row-price {
            font-size: 0.9rem;
            font-weight: 700;
            color: #0f172a;
            white-space: nowrap;
            min-width: 110px;
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .property-row-price .currency {
            color: #64748b;
            font-weight: 600;
            font-size: 0.75rem;
        }

        .property-row-price.no-price {
            color: #94a3b8;
            font-weight: 500;
            font-size: 0.7rem;
        }

        .property-row-actions {
            display: flex;
            gap: 4px;
            flex-shrink: 0;
        }

        .property-row-actions .action-btn {
            width: 28px;
            height: 28px;
            border: none;
            background: transparent;
            color: #94a3b8;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s;
            font-size: 0.75rem;
        }

        .property-row-actions .action-btn:hover {
            background: #f1f5f9;
            color: #0f172a;
        }

        .property-row-actions .action-btn.view:hover {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .property-row-actions .action-btn.edit:hover {
            background: #dcfce7;
            color: #16a34a;
        }

        .commission-highlight {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 6px;
            padding: 2px 10px;
            font-size: 0.7rem;
            color: #475569;
            white-space: nowrap;
        }

        .commission-highlight strong {
            color: #7c3aed;
        }

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

        .table-container {
            overflow-x: auto;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
        }

        .empty-state i {
            font-size: 3rem;
            color: #cbd5e1;
            margin-bottom: 15px;
        }

        .empty-state h3 {
            color: #1e293b;
            margin-bottom: 8px;
        }

        .empty-state p {
            color: #94a3b8;
        }

        @media (max-width: 992px) {
            .metrics-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .property-row {
                flex-wrap: wrap;
                padding: 10px 12px;
                min-height: auto;
                gap: 8px;
            }

            .property-row-info {
                flex: 1 1 100%;
                gap: 6px;
            }

            .property-row-title {
                max-width: 100%;
                white-space: normal;
                font-size: 0.8rem;
            }

            .property-row-location {
                font-size: 0.65rem;
                width: 100%;
            }

            .property-row-details {
                font-size: 0.6rem;
                width: 100%;
            }

            .property-row-price {
                min-width: auto;
                text-align: left;
                font-size: 0.85rem;
                margin-left: auto;
            }

            .property-row-actions {
                margin-left: auto;
            }

            .property-row-status {
                font-size: 0.55rem;
                padding: 1px 8px;
            }

            .commission-highlight {
                font-size: 0.6rem;
                padding: 1px 8px;
            }

            .metric-card {
                padding: 10px 12px;
            }

            .metric-value {
                font-size: 1.1rem;
            }
        }

        @media (max-width: 480px) {
            .property-row {
                padding: 8px 10px;
            }

            .property-row-image {
                width: 36px;
                min-width: 36px;
                height: 36px;
            }

            .property-row-title {
                font-size: 0.75rem;
            }

            .property-row-price {
                font-size: 0.8rem;
                min-width: 70px;
            }

            .property-row-actions .action-btn {
                width: 24px;
                height: 24px;
                font-size: 0.65rem;
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php include 'sidebar.php'; ?>

<main class="main-content">
    <div class="main-header">
        <div class="header-left">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <h1>Mis Propiedades</h1>
            <p class="welcome">
                <i class="fas fa-user-tie"></i> Bienvenido, <?php echo htmlspecialchars($usuario['nombre'] ?? 'Vendedor'); ?>
            </p>
        </div>
        <div class="header-actions">
            <button class="btn-header primary" onclick="nuevaPropiedad()">
                <i class="fas fa-plus"></i> Nueva Propiedad
            </button>
        </div>
    </div>

    <!-- ===== MÉTRICAS DESTACADAS ===== -->
    <div class="metrics-grid">
        <div class="metric-card">
            <div class="metric-icon blue"><i class="fas fa-home"></i></div>
            <div class="metric-info">
                <div class="metric-value"><?php echo $stats['total']; ?></div>
                <div class="metric-label">Total Propiedades</div>
            </div>
            <span class="metric-trend neutral"><?php echo $stats['activas']; ?> activas</span>
        </div>

        <div class="metric-card">
            <div class="metric-icon green"><i class="fas fa-tag"></i></div>
            <div class="metric-info">
                <div class="metric-value"><?php echo formatearPrecio($stats['total_inventario']); ?></div>
                <div class="metric-label">Valor Total Inventario</div>
            </div>
        </div>

        <div class="metric-card">
            <div class="metric-icon purple"><i class="fas fa-coins"></i></div>
            <div class="metric-info">
                <div class="metric-value"><?php echo formatearPrecio($stats['comision_potencial_total']); ?></div>
                <div class="metric-label">Comisión Potencial Total</div>
            </div>
        </div>

        <div class="metric-card">
            <div class="metric-icon orange"><i class="fas fa-image"></i></div>
            <div class="metric-info">
                <div class="metric-value"><?php echo $stats['con_imagen']; ?>/<?php echo $stats['total']; ?></div>
                <div class="metric-label">Con Imagen Principal</div>
            </div>
            <?php if ($stats['sin_imagen'] > 0): ?>
                <span class="metric-trend negative"><?php echo $stats['sin_imagen']; ?> pendientes</span>
            <?php else: ?>
                <span class="metric-trend positive">✓ Completo</span>
            <?php endif; ?>
        </div>

        <div class="metric-card">
            <div class="metric-icon red"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="metric-info">
                <div class="metric-value"><?php echo $stats['propiedades_riesgo']; ?></div>
                <div class="metric-label">Propiedades en Riesgo</div>
            </div>
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
        <div class="table-header">
            <h3><i class="fas fa-list-ul"></i> Listado de mis propiedades</h3>
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
                    <h3>No tienes propiedades registradas</h3>
                    <p>Comienza registrando tu primera propiedad en el sistema</p>
                    <button onclick="nuevaPropiedad()" class="btn-header primary" style="margin-top: 16px;">
                        <i class="fas fa-plus"></i> Registrar Propiedad
                    </button>
                </div>
            <?php elseif (!empty($propiedades)): ?>
                <div class="properties-list" id="propertiesList">
                    <?php foreach ($propiedades as $propiedad): 
                        $badge = getOperationBadge($propiedad['operation_type'] ?? '');
                        $statusBadge = getStatusBadge($propiedad['status'] ?? '');
                        $price = $propiedad['price'] ?? null;
                        $hasPrice = ($price !== null && $price > 0);
                        $priceClass = $hasPrice ? '' : 'no-price';
                        $imagePath = getImagePath($propiedad['image_url'] ?? '');
                        $hasImage = !empty($imagePath);
                        $title = htmlspecialchars($propiedad['title'] ?? 'Sin título');
                        $municipality = htmlspecialchars($propiedad['address_municipality'] ?? '');
                        $city = htmlspecialchars($propiedad['address_city'] ?? '');
                        $location = $municipality . ($city ? ', ' . $city : '');
                        $commission = $propiedad['commission_percentage'] ?? 0;
                        $commissionAmount = $hasPrice ? ($price * $commission / 100) : 0;
                        $details = getDetallesCorta($propiedad);
                    ?>
                        <div class="property-row" 
                             data-text="<?php echo strtolower($title . ' ' . $location); ?>" 
                             data-operation="<?php echo strtolower(trim($propiedad['operation_type'] ?? '')); ?>"
                             data-status="<?php echo strtolower(trim($propiedad['status'] ?? '')); ?>">
                            
                            <!-- Imagen -->
                            <div class="property-row-image">
                                <span class="property-row-badge <?php echo $badge['class']; ?>">
                                    <?php echo substr($badge['label'], 0, 1); ?>
                                </span>
                                <?php if ($hasImage): ?>
                                    <img src="<?php echo $imagePath; ?>" 
                                         alt="<?php echo $title; ?>" 
                                         loading="lazy"
                                         onerror="this.style.display='none'; this.parentElement.querySelector('.no-image').style.display='flex';">
                                    <div class="no-image" style="display: none;">
                                        <i class="fas fa-image"></i>
                                    </div>
                                <?php else: ?>
                                    <div class="no-image">
                                        <i class="fas fa-building"></i>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Información -->
                            <div class="property-row-info">
                                <div class="property-row-title" title="<?php echo $title; ?>">
                                    <i class="fas fa-home"></i> <?php echo $title; ?>
                                </div>
                                
                                <?php if (!empty($location)): ?>
                                <div class="property-row-location">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span><?php echo $location; ?></span>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($details)): ?>
                                <div class="property-row-details">
                                    <i class="fas fa-info-circle"></i>
                                    <span><?php echo $details; ?></span>
                                </div>
                                <?php endif; ?>

                                <span class="property-row-status <?php echo $statusBadge['class']; ?>">
                                    <i class="fas <?php echo $statusBadge['icon']; ?>"></i>
                                    <?php echo $statusBadge['label']; ?>
                                </span>

                                <?php if ($commission > 0): ?>
                                    <span class="commission-highlight">
                                        <i class="fas fa-percent"></i> <?php echo number_format($commission, 1); ?>% 
                                        <strong>(<?php echo formatearPrecio($commissionAmount); ?>)</strong>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Precio -->
                            <div class="property-row-price <?php echo $priceClass; ?>">
                                <?php if ($hasPrice): ?>
                                    <span class="currency">$</span><?php echo number_format(floatval($price), 0, ',', '.'); ?>
                                <?php else: ?>
                                    <i class="fas fa-exclamation-circle" style="color: #f59e0b;"></i>
                                    Sin precio
                                <?php endif; ?>
                            </div>

                            <!-- Acciones -->
                            <div class="property-row-actions">
                                <button class="action-btn view" title="Ver detalles" onclick="verPropiedad('<?php echo $propiedad['id']; ?>')">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="action-btn edit" title="Editar" onclick="editarPropiedad('<?php echo $propiedad['id']; ?>')">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
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

    if(menuToggle) {
        menuToggle.addEventListener('click', toggleSidebar);
    }
    
    if(overlay) {
        overlay.addEventListener('click', toggleSidebar);
    }

    document.querySelectorAll('.sidebar nav a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 992 && sidebar && sidebar.classList.contains('open')) {
                toggleSidebar();
            }
        });
    });

    // Filtros
    const searchInput = document.getElementById('searchTable');
    const filterOperation = document.getElementById('filterOperation');
    const filterStatus = document.getElementById('filterStatus');

    function filtrarFilas() {
        const searchText = (searchInput.value || '').toLowerCase().trim();
        const operationVal = (filterOperation.value || '').toLowerCase().trim();
        const statusVal = (filterStatus.value || '').toLowerCase().trim();
        const rows = document.querySelectorAll('.property-row');

        rows.forEach(row => {
            const rowText = (row.getAttribute('data-text') || '').toLowerCase();
            const rowOp = (row.getAttribute('data-operation') || '').toLowerCase();
            const rowStatus = (row.getAttribute('data-status') || '').toLowerCase();

            const matchesSearch = rowText.includes(searchText);
            const matchesOp = operationVal === '' || rowOp === operationVal;
            const matchesStatus = statusVal === '' || rowStatus === statusVal;

            row.style.display = (matchesSearch && matchesOp && matchesStatus) ? '' : 'none';
        });
    }

    if(searchInput) searchInput.addEventListener('keyup', filtrarFilas);
    if(filterOperation) filterOperation.addEventListener('change', filtrarFilas);
    if(filterStatus) filterStatus.addEventListener('change', filtrarFilas);

    // Acciones
    window.nuevaPropiedad = function() {
        window.location.href = 'propiedad_nueva.php';
    };

    window.verPropiedad = function(id) {
        window.location.href = 'propiedad_detalle_vendedor.php?id=' + id;
    };

    window.editarPropiedad = function(id) {
        window.location.href = 'propiedad_editar_vendedor.php?id=' + id;
    };
});
</script>

</body>
</html>