<?php
// ============================================
// propiedad_detalle_inventario.php
// ============================================

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

$usuario = obtenerUsuarioActual($conn);
if (!$usuario) {
    cerrarSesion();
    header('Location: login.php');
    exit;
}

// Obtener ID de la propiedad
$property_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($property_id == 0) {
    header('Location: inventario.php');
    exit;
}

$propiedad = null;
$error_msg = '';

try {
    // Consulta general (sin restricción de owner) - USANDO TABLA socios
    $stmt = $conn->prepare("
        SELECT 
            p.id,
            p.owner_id,
            p.title,
            p.operation_type,
            p.address_city,
            p.address_municipality,
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
            (pf.asking_price * pf.commission_percentage / 100) as commission_amount,
            s.nombre as owner_name,
            s.email as owner_email
        FROM properties p
        LEFT JOIN property_details pd ON p.id = pd.property_id
        LEFT JOIN property_financials pf ON p.id = pf.property_id
        LEFT JOIN socios s ON p.owner_id = s.id
        WHERE p.id = ?
    ");
    $stmt->execute([$property_id]);
    $propiedad = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$propiedad) {
        $error_msg = "Propiedad no encontrada.";
    }

    // Obtener imagen principal
    $imagen_principal = '';
    if ($propiedad) {
        $stmtImg = $conn->prepare("
            SELECT file_path, is_primary
            FROM property_media
            WHERE property_id = ?
            ORDER BY is_primary DESC, sort_order ASC
            LIMIT 1
        ");
        $stmtImg->execute([$property_id]);
        $img = $stmtImg->fetch(PDO::FETCH_ASSOC);
        if ($img) {
            $imagen_principal = $img['file_path'];
        }
    }

} catch (PDOException $e) {
    $error_msg = "Error al cargar la propiedad: " . $e->getMessage();
    error_log("Error en propiedad_detalle_inventario.php: " . $e->getMessage());
}

// Funciones auxiliares
function formatearPrecio($precio) {
    if ($precio === null || $precio === '' || $precio == 0) {
        return 'Sin asignar';
    }
    return '$' . number_format(floatval($precio), 0, ',', '.');
}

function getStatusBadge($status) {
    $status = strtolower(trim($status ?? ''));
    $classes = [
        'activo' => 'status-active',
        'pendiente' => 'status-pending',
        'vendido' => 'status-sold',
        'suspendido' => 'status-suspended'
    ];
    return $classes[$status] ?? 'status-other';
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

function getOperationBadge($operationType) {
    $opType = strtolower(trim($operationType ?? ''));
    if ($opType === 'venta') {
        return ['class' => 'venta', 'label' => 'Venta'];
    } elseif ($opType === 'compra') {
        return ['class' => 'compra', 'label' => 'Compra'];
    } else {
        return ['class' => 'general', 'label' => 'General'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/socios.css">
    <title>Detalle Propiedad | Inventario</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }

        .detail-container {
            max-width: 1100px;
            margin: 0 auto;
        }

        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }

        .detail-title h1 {
            font-size: 1.4rem;
            margin: 0 0 4px 0;
            color: #0f172a;
        }

        .detail-title .subtitle {
            color: #64748b;
            font-size: 0.85rem;
        }

        .detail-actions {
            display: flex;
            gap: 8px;
        }

        .btn-detail {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-detail.secondary {
            background: #f1f5f9;
            color: #475569;
        }

        .btn-detail.secondary:hover {
            background: #e2e8f0;
        }

        .btn-detail.primary {
            background: #1d4ed8;
            color: white;
        }

        .btn-detail.primary:hover {
            background: #1e40af;
        }

        .main-card {
            background: #ffffff;
            border: 1px solid #e8edf4;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .main-card .card-body {
            padding: 20px;
        }

        .main-card .card-header {
            padding: 16px 20px;
            background: #f8fafc;
            border-bottom: 1px solid #e8edf4;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .main-card .card-header h3 {
            margin: 0;
            font-size: 0.95rem;
            color: #0f172a;
        }

        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px solid #f8fafc;
            font-size: 0.9rem;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-row .label {
            color: #64748b;
            font-weight: 500;
        }

        .info-row .value {
            color: #0f172a;
            font-weight: 600;
        }

        .info-row .value.highlight {
            color: #1d4ed8;
            font-size: 1.1rem;
        }

        .info-row .value.success {
            color: #16a34a;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-badge.status-active { background: #dcfce7; color: #166534; }
        .status-badge.status-pending { background: #fef3c7; color: #92400e; }
        .status-badge.status-sold { background: #dbeafe; color: #1e40af; }
        .status-badge.status-suspended { background: #fee2e2; color: #991b1b; }
        .status-badge.status-other { background: #f1f5f9; color: #475569; }

        .prop-image {
            width: 100%;
            max-height: 300px;
            object-fit: cover;
            background: #f1f5f9;
        }

        .prop-image-placeholder {
            width: 100%;
            height: 200px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
            color: #94a3b8;
            font-size: 3rem;
        }

        .info-tag {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            background: #f1f5f9;
            color: #475569;
        }

        .info-tag.venta { background: #dcfce7; color: #166534; }
        .info-tag.compra { background: #dbeafe; color: #1e40af; }
        .info-tag.general { background: #f1f5f9; color: #475569; }

        .owner-info {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e8edf4;
        }

        .owner-info .owner-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #1d4ed8;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .owner-info .owner-details {
            flex: 1;
        }

        .owner-info .owner-name {
            font-weight: 600;
            color: #0f172a;
            font-size: 0.9rem;
        }

        .owner-info .owner-contact {
            font-size: 0.75rem;
            color: #64748b;
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

        .message-box.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

        @media (max-width: 768px) {
            .two-col {
                grid-template-columns: 1fr;
            }

            .detail-title h1 {
                font-size: 1.1rem;
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php include 'sidebar.php'; ?>

<main class="main-content">
    <div class="detail-container">

        <?php if (!empty($error_msg)): ?>
            <div class="message-box error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error_msg); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($propiedad): 
            $badgeOp = getOperationBadge($propiedad['operation_type'] ?? '');
        ?>
            <!-- Header -->
            <div class="detail-header">
                <div class="detail-title">
                    <h1><?php echo htmlspecialchars($propiedad['title']); ?></h1>
                    <div class="subtitle">
                        <span class="status-badge <?php echo getStatusBadge($propiedad['status']); ?>">
                            <?php echo ucfirst($propiedad['status'] ?? 'Sin estado'); ?>
                        </span>
                        <span class="info-tag <?php echo $badgeOp['class']; ?>">
                            <?php echo $badgeOp['label']; ?>
                        </span>
                        <span style="margin-left: 12px;">
                            <i class="fas fa-calendar-alt"></i> 
                            <?php echo date('d/m/Y', strtotime($propiedad['created_at'])); ?>
                        </span>
                        <span style="margin-left: 12px;">
                            <i class="fas fa-hashtag"></i> ID: <?php echo $propiedad['id']; ?>
                        </span>
                    </div>
                </div>
                <div class="detail-actions">
                    <a href="inventario.php" class="btn-detail secondary">
                        <i class="fas fa-arrow-left"></i> Volver
                    </a>
                    <a href="propiedad_editar.php?id=<?php echo $property_id; ?>" class="btn-detail primary">
                        <i class="fas fa-edit"></i> Editar
                    </a>
                </div>
            </div>

            <!-- Imagen Principal -->
            <div class="main-card">
                <?php if (!empty($imagen_principal)): ?>
                    <img src="<?php echo getImagePath($imagen_principal); ?>" 
                         alt="<?php echo htmlspecialchars($propiedad['title']); ?>"
                         class="prop-image">
                <?php else: ?>
                    <div class="prop-image-placeholder">
                        <i class="fas fa-building"></i>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Información -->
            <div class="two-col">
                <!-- Columna Izquierda -->
                <div>
                    <div class="main-card">
                        <div class="card-header">
                            <h3><i class="fas fa-info-circle"></i> Información General</h3>
                        </div>
                        <div class="card-body">
                            <div class="info-row">
                                <span class="label">Título</span>
                                <span class="value"><?php echo htmlspecialchars($propiedad['title']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="label">Tipo de operación</span>
                                <span class="value"><?php echo ucfirst($propiedad['operation_type'] ?? 'No especificado'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="label">Municipio</span>
                                <span class="value"><?php echo htmlspecialchars($propiedad['address_municipality'] ?? 'No especificado'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="label">Ciudad</span>
                                <span class="value"><?php echo htmlspecialchars($propiedad['address_city'] ?? 'No especificado'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="label">Estado</span>
                                <span class="value">
                                    <span class="status-badge <?php echo getStatusBadge($propiedad['status']); ?>">
                                        <?php echo ucfirst($propiedad['status'] ?? 'Sin estado'); ?>
                                    </span>
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="label">Días en mercado</span>
                                <span class="value"><?php echo $propiedad['days_active'] ?? 0; ?> días</span>
                            </div>
                        </div>
                    </div>

                    <!-- Características -->
                    <div class="main-card">
                        <div class="card-header">
                            <h3><i class="fas fa-ruler-combined"></i> Características</h3>
                        </div>
                        <div class="card-body">
                            <div class="info-row">
                                <span class="label">Superficie</span>
                                <span class="value"><?php echo number_format($propiedad['square_meters'] ?? 0, 0, ',', '.'); ?> m²</span>
                            </div>
                            <div class="info-row">
                                <span class="label">Habitaciones</span>
                                <span class="value"><?php echo $propiedad['bedrooms'] ?? 'No especificado'; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="label">Baños</span>
                                <span class="value"><?php echo $propiedad['bathrooms'] ?? 'No especificado'; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="label">Estacionamientos</span>
                                <span class="value"><?php echo $propiedad['parking_spots'] ?? 'No especificado'; ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Columna Derecha -->
                <div>
                    <!-- Financiero -->
                    <div class="main-card">
                        <div class="card-header">
                            <h3><i class="fas fa-coins"></i> Información Financiera</h3>
                        </div>
                        <div class="card-body">
                            <div class="info-row">
                                <span class="label">Precio de venta</span>
                                <span class="value highlight"><?php echo formatearPrecio($propiedad['price']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="label">Precio mínimo</span>
                                <span class="value"><?php echo formatearPrecio($propiedad['min_acceptable_price']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="label">Margen potencial</span>
                                <span class="value <?php echo ($propiedad['potential_profit_margin'] ?? 0) > 0 ? 'success' : ''; ?>">
                                    <?php echo number_format($propiedad['potential_profit_margin'] ?? 0, 1); ?>%
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="label">Comisión</span>
                                <span class="value"><?php echo number_format($propiedad['commission_percentage'] ?? 0, 1); ?>%</span>
                            </div>
                            <div class="info-row" style="border-top: 2px solid #e8edf4; padding-top: 10px; margin-top: 4px;">
                                <span class="label" style="font-weight: 700;">Comisión estimada</span>
                                <span class="value highlight"><?php echo formatearPrecio($propiedad['commission_amount'] ?? 0); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Propietario / Vendedor (desde tabla socios) -->
                    <div class="main-card">
                        <div class="card-header">
                            <h3><i class="fas fa-user"></i> Propietario / Vendedor</h3>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($propiedad['owner_name'])): ?>
                                <div class="owner-info">
                                    <div class="owner-avatar">
                                        <?php echo strtoupper(substr($propiedad['owner_name'], 0, 1)); ?>
                                    </div>
                                    <div class="owner-details">
                                        <div class="owner-name"><?php echo htmlspecialchars($propiedad['owner_name']); ?></div>
                                        <div class="owner-contact">
                                            <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($propiedad['owner_email'] ?? 'No disponible'); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="info-row">
                                    <span class="label">Propietario ID</span>
                                    <span class="value">#<?php echo $propiedad['owner_id'] ?? 'No asignado'; ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="label">Email</span>
                                    <span class="value" style="color: #94a3b8; font-weight: 400;">No disponible</span>
                                </div>
                            <?php endif; ?>
                            <div class="info-row" style="margin-top: 8px; border-top: 1px solid #f1f5f9; padding-top: 8px;">
                                <span class="label">ID Propiedad</span>
                                <span class="value">#<?php echo $propiedad['id']; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <?php endif; ?>
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
});
</script>

</body>
</html>