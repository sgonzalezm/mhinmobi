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

// Obtener propiedades del inventario general con datos financieros y multimedia
$propiedades = [];
$error_msg = '';

try {
    // Verificar si las tablas existen
    $checkProperties = $conn->query("SHOW TABLES LIKE 'properties'");
    $checkFinancial = $conn->query("SHOW TABLES LIKE 'property_financials'");
    $checkMedia = $conn->query("SHOW TABLES LIKE 'property_media'");
    
    if ($checkProperties->rowCount() == 0) {
        $error_msg = "La tabla 'properties' no existe en la base de datos.";
    } elseif ($checkFinancial->rowCount() == 0) {
        $error_msg = "La tabla 'property_financials' no existe en la base de datos.";
    } else {
        // Consulta con JOIN para obtener datos de ambas tablas y la imagen principal
        $stmt = $conn->prepare("
            SELECT 
                p.id,
                p.title,
                p.operation_type,
                p.address_municipality as municipality,
                p.status,
                p.created_at,
                f.asking_price as price,
                f.min_acceptable_price,
                f.potential_profit_margin,
                f.commission_percentage,
                m.file_path as image_url,
                m.is_primary as is_primary_image
            FROM properties p
            LEFT JOIN property_financials f ON p.id = f.property_id
            LEFT JOIN property_media m ON p.id = m.property_id AND m.is_primary = 1
            WHERE p.status = 'activo'
            ORDER BY p.created_at DESC
            LIMIT 20
        ");
        $stmt->execute();
        $propiedades = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Si no hay imágenes primarias, intentar obtener cualquier imagen
        if (!empty($propiedades)) {
            foreach ($propiedades as $key => $prop) {
                if (empty($prop['image_url'])) {
                    // Obtener cualquier imagen de la propiedad
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
        
        // Depuración: Ver cuántos registros se obtuvieron
        error_log("Propiedades encontradas: " . count($propiedades));
        
        // Si no hay resultados, mostrar mensaje informativo
        if (empty($propiedades)) {
            $error_msg = "No hay propiedades activas en el sistema.";
        }
    }
} catch (PDOException $e) {
    $error_msg = "Error al cargar propiedades: " . $e->getMessage();
    error_log("Error en inventario.php: " . $e->getMessage());
}

// Estadísticas de propiedades
$stats = [
    'total' => count($propiedades),
    'venta' => 0,
    'renta' => 0,
    'con_precio' => 0,
    'sin_precio' => 0,
    'con_imagen' => 0,
    'sin_imagen' => 0
];

foreach ($propiedades as $p) {
    // Contar propiedades con y sin precio
    if (isset($p['price']) && $p['price'] > 0) {
        $stats['con_precio']++;
    } else {
        $stats['sin_precio']++;
    }
    
    // Contar propiedades con y sin imagen
    if (!empty($p['image_url'])) {
        $stats['con_imagen']++;
    } else {
        $stats['sin_imagen']++;
    }
    
    // Clasificar por tipo de operación
    if (isset($p['operation_type'])) {
        $opType = strtolower(trim($p['operation_type']));
        if ($opType === 'venta' || $opType === 'compra') $stats['venta']++;
        if ($opType === 'renta' || $opType === 'alquiler') $stats['renta']++;
    }
}

// Función para formatear precio (con separador de miles = coma)
function formatearPrecio($precio) {
    if ($precio === null || $precio === '' || $precio == 0) {
        return 'Precio no disponible';
    }
    // Usamos coma como separador de miles y punto para decimales
    return '$' . number_format(floatval($precio), 0, ',', '.');
}

// Función para obtener el badge de operación
function getOperationBadge($operationType) {
    $opType = strtolower(trim($operationType ?? ''));
    if ($opType === 'venta' || $opType === 'compra') {
        return ['class' => 'venta', 'label' => 'Venta', 'icon' => 'fa-tag'];
    } elseif ($opType === 'renta' || $opType === 'alquiler') {
        return ['class' => 'renta', 'label' => 'Renta', 'icon' => 'fa-key'];
    } else {
        return ['class' => 'general', 'label' => 'General', 'icon' => 'fa-building'];
    }
}

// Función para obtener la ruta de la imagen
function getImagePath($imageUrl) {
    if (empty($imageUrl)) {
        return '';
    }
    // Si la ruta ya incluye 'uploads/', no la agregamos de nuevo
    if (strpos($imageUrl, 'uploads/') === 0) {
        return htmlspecialchars($imageUrl);
    }
    // Si no, asumimos que está en la carpeta uploads/propiedades/
    return 'uploads/propiedades/' . htmlspecialchars($imageUrl);
}

// Función para obtener el estado
function getStatusBadge($status) {
    $status = strtolower(trim($status ?? ''));
    if ($status === 'activo') {
        return ['class' => 'status-active', 'label' => 'Activo'];
    } elseif ($status === 'inactivo') {
        return ['class' => 'status-inactive', 'label' => 'Inactivo'];
    } elseif ($status === 'vendido') {
        return ['class' => 'status-sold', 'label' => 'Vendido'];
    } else {
        return ['class' => 'status-other', 'label' => ucfirst($status)];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/socios.css">
    <title>Inventario de Propiedades | Inmobiliaria MH</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ===== ESTILOS CORPORATIVOS ===== */
        * {
            box-sizing: border-box;
        }

        .properties-grid {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 15px;
        }

        /* Tarjeta horizontal delgada - estilo corporativo */
        .property-row {
            display: flex;
            align-items: center;
            background: #ffffff;
            border: 1px solid #e8edf4;
            border-radius: 8px;
            padding: 8px 12px;
            transition: all 0.15s ease;
            cursor: default;
            min-height: 64px;
            gap: 12px;
        }

        .property-row:hover {
            background: #f8faff;
            border-color: #c7d2e0;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
        }

        /* Columna de imagen - pequeña */
        .property-row-image {
            width: 48px;
            min-width: 48px;
            height: 48px;
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
            font-size: 1.2rem;
        }

        .property-row-image .no-image i {
            font-size: 1.2rem;
        }

        /* Badge de operación - más pequeño */
        .property-row-badge {
            position: absolute;
            top: -2px;
            left: -2px;
            font-size: 0.5rem;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: white;
            background: #10b981;
        }

        .property-row-badge.venta {
            background: #10b981;
        }
        .property-row-badge.renta {
            background: #3b82f6;
        }
        .property-row-badge.general {
            background: #6b7280;
        }

        /* Columna de información principal */
        .property-row-info {
            flex: 1;
            min-width: 0;
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .property-row-title {
            font-size: 0.85rem;
            font-weight: 600;
            color: #0f172a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 280px;
        }

        .property-row-title i {
            color: #64748b;
            font-size: 0.7rem;
            margin-right: 4px;
        }

        .property-row-location {
            font-size: 0.75rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }

        .property-row-location i {
            color: #10b981;
            font-size: 0.7rem;
        }

        /* Estado de la propiedad */
        .property-row-status {
            font-size: 0.65rem;
            font-weight: 600;
            padding: 2px 10px;
            border-radius: 12px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        .property-row-status.status-active {
            background: #dcfce7;
            color: #166534;
        }

        .property-row-status.status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .property-row-status.status-sold {
            background: #fef3c7;
            color: #92400e;
        }

        .property-row-status.status-other {
            background: #f1f5f9;
            color: #475569;
        }

        /* Comisión */
        .property-row-commission {
            font-size: 0.7rem;
            color: #64748b;
            white-space: nowrap;
        }

        .property-row-commission i {
            color: #8b5cf6;
            font-size: 0.65rem;
        }

        /* Precio - destacado y con coma */
        .property-row-price {
            font-size: 0.95rem;
            font-weight: 700;
            color: #0f172a;
            white-space: nowrap;
            min-width: 120px;
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .property-row-price .currency {
            color: #64748b;
            font-weight: 600;
            font-size: 0.8rem;
        }

        .property-row-price.no-price {
            color: #94a3b8;
            font-weight: 500;
            font-size: 0.75rem;
        }

        /* Acciones */
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
            font-size: 0.8rem;
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

        /* Mensajes */
        .message-box {
            padding: 12px 16px;
            border-radius: 8px;
            margin: 8px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
        }

        .message-box.info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #93c5fd;
        }

        .message-box.warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fcd34d;
        }

        .message-box.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .message-box.success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }

        .message-box i {
            font-size: 1rem;
        }

        /* Estadísticas adicionales - más compactas */
        .stats-extra {
            display: flex;
            gap: 8px;
            font-size: 0.78rem;
            color: #64748b;
            margin-top: 4px;
            flex-wrap: wrap;
        }

        .stats-extra span {
            background: #f1f5f9;
            padding: 2px 10px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .stats-extra span i {
            font-size: 0.7rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .property-row {
                flex-wrap: wrap;
                padding: 10px 12px;
                min-height: auto;
                gap: 8px;
            }

            .property-row-info {
                flex: 1 1 100%;
                gap: 8px;
            }

            .property-row-title {
                max-width: 100%;
                white-space: normal;
                font-size: 0.8rem;
            }

            .property-row-location {
                font-size: 0.7rem;
                width: 100%;
            }

            .property-row-price {
                min-width: auto;
                text-align: left;
                font-size: 0.9rem;
                margin-left: auto;
            }

            .property-row-actions {
                margin-left: auto;
            }

            .property-row-status {
                font-size: 0.6rem;
                padding: 1px 8px;
            }

            .property-row-commission {
                font-size: 0.65rem;
            }
        }

        @media (max-width: 480px) {
            .property-row {
                padding: 8px 10px;
            }

            .property-row-image {
                width: 40px;
                min-width: 40px;
                height: 40px;
            }

            .property-row-title {
                font-size: 0.75rem;
            }

            .property-row-price {
                font-size: 0.8rem;
                min-width: 80px;
            }

            .property-row-actions .action-btn {
                width: 24px;
                height: 24px;
                font-size: 0.7rem;
            }
        }

        /* Scroll horizontal para tabla en móvil */
        .table-container {
            overflow-x: auto;
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
            <h1>Inventario Maestro - Propiedades</h1>
            <p class="welcome">
                <i class="fas fa-building"></i> Gestión y disponibilidad general de inmuebles
            </p>
        </div>
        <div class="header-actions">
            <button class="btn-header primary" onclick="nuevaPropiedad()">
                <i class="fas fa-plus"></i> Nueva Propiedad
            </button>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-icon"><i class="fas fa-home"></i></span>
            <div class="stat-number"><?php echo $stats['total']; ?></div>
            <div class="stat-label">Total Inmuebles</div>
        </div>
        <div class="stat-card success">
            <span class="stat-icon"><i class="fas fa-tag"></i></span>
            <div class="stat-number"><?php echo $stats['venta']; ?></div>
            <div class="stat-label">En Venta</div>
        </div>
        <div class="stat-card warning">
            <span class="stat-icon"><i class="fas fa-key"></i></span>
            <div class="stat-number"><?php echo $stats['renta']; ?></div>
            <div class="stat-label">En Renta</div>
        </div>
    </div>

    <!-- Estadísticas adicionales -->
    <div class="stats-extra">
        <span><i class="fas fa-check-circle" style="color: #10b981;"></i> Con precio: <?php echo $stats['con_precio']; ?></span>
        <span><i class="fas fa-exclamation-circle" style="color: #f59e0b;"></i> Sin precio: <?php echo $stats['sin_precio']; ?></span>
        <span><i class="fas fa-image" style="color: #3b82f6;"></i> Con imagen: <?php echo $stats['con_imagen']; ?></span>
        <span><i class="fas fa-image" style="color: #94a3b8;"></i> Sin imagen: <?php echo $stats['sin_imagen']; ?></span>
    </div>

    <div class="table-container">
        <div class="table-header">
            <h3><i class="fas fa-list-ul"></i> Listado de Inmuebles</h3>
            <div class="search-box">
                <input type="text" placeholder="Buscar por municipio o título..." id="searchTable">
                <select id="filterOperation">
                    <option value="">Todas las operaciones</option>
                    <option value="venta">Venta</option>
                    <option value="renta">Renta</option>
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

            <!-- Mensaje informativo sobre precios -->
            <?php if (!empty($propiedades) && $stats['sin_precio'] > 0): ?>
                <div class="message-box warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Hay <?php echo $stats['sin_precio']; ?> propiedad(es) sin precio asignado en la tabla property_financials.</span>
                </div>
            <?php endif; ?>

            <!-- Mensaje informativo sobre imágenes -->
            <?php if (!empty($propiedades) && $stats['sin_imagen'] > 0): ?>
                <div class="message-box info">
                    <i class="fas fa-info-circle"></i>
                    <span>Hay <?php echo $stats['sin_imagen']; ?> propiedad(es) sin imagen principal.</span>
                </div>
            <?php endif; ?>

            <?php if (empty($propiedades) && empty($error_msg)): ?>
                <div class="empty-state" style="text-align: center; padding: 40px;">
                    <i class="fas fa-home" style="font-size: 3rem; color: var(--gray); margin-bottom: 15px;"></i>
                    <h3>No hay propiedades registradas</h3>
                    <p style="color: var(--gray);">Comienza dando de alta una propiedad en el sistema</p>
                    <button onclick="nuevaPropiedad()" class="btn-header primary" style="margin-top: 20px;">
                        <i class="fas fa-plus"></i> Agregar Propiedad
                    </button>
                </div>
            <?php elseif (!empty($propiedades)): ?>
                <div class="properties-grid" id="propertiesGrid">
                    <?php foreach ($propiedades as $propiedad): 
                        $badge = getOperationBadge($propiedad['operation_type'] ?? '');
                        $statusBadge = getStatusBadge($propiedad['status'] ?? '');
                        $price = $propiedad['price'] ?? null;
                        $hasPrice = ($price !== null && $price > 0);
                        $priceClass = $hasPrice ? '' : 'no-price';
                        $priceText = $hasPrice ? formatearPrecio($price) : 'Sin precio';
                        $imagePath = getImagePath($propiedad['image_url'] ?? '');
                        $hasImage = !empty($imagePath);
                        $title = htmlspecialchars($propiedad['title'] ?? 'Sin título');
                        $municipality = htmlspecialchars($propiedad['municipality'] ?? 'Ubicación no especificada');
                        $commission = $propiedad['commission_percentage'] ?? 0;
                    ?>
                        <div class="property-row" 
                             data-text="<?php echo strtolower($title . ' ' . $municipality); ?>" 
                             data-operation="<?php echo strtolower(trim($propiedad['operation_type'] ?? '')); ?>">
                            
                            <!-- Imagen -->
                            <div class="property-row-image">
                                <span class="property-row-badge <?php echo $badge['class']; ?>">
                                    <?php echo $badge['label']; ?>
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
                                
                                <div class="property-row-location">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span><?php echo $municipality; ?></span>
                                </div>

                                <!-- Estado -->
                                <span class="property-row-status <?php echo $statusBadge['class']; ?>">
                                    <?php echo $statusBadge['label']; ?>
                                </span>

                                <!-- Comisión -->
                                <?php if ($commission > 0): ?>
                                    <div class="property-row-commission">
                                        <i class="fas fa-percent"></i> <?php echo number_format($commission, 1); ?>%
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Precio -->
                            <div class="property-row-price <?php echo $priceClass; ?>">
                                <?php if ($hasPrice): ?>
                                    <span class="currency">$</span><?php echo number_format(floatval($price), 0, ',', '.'); ?>
                                <?php else: ?>
                                    <?php echo $priceText; ?>
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
                
                <!-- Mostrar datos de depuración (solo en desarrollo) -->
                <?php if (isset($_GET['debug'])): ?>
                    <div style="margin-top: 20px; padding: 16px; background: #f8fafc; border-radius: 8px; overflow-x: auto; font-size: 12px;">
                        <h4 style="margin: 0 0 10px 0; font-size: 0.85rem;">Datos de depuración (primer registro):</h4>
                        <pre style="margin: 0;"><?php print_r(reset($propiedades)); ?></pre>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
// ===== Menú móvil =====
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

    // Cerrar sidebar al hacer clic en enlaces en móvil
    document.querySelectorAll('.sidebar nav a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 992 && sidebar && sidebar.classList.contains('open')) {
                toggleSidebar();
            }
        });
    });

    // ===== Filtros en tiempo real =====
    const searchInput = document.getElementById('searchTable');
    const filterOperation = document.getElementById('filterOperation');

    if(searchInput && filterOperation) {
        searchInput.addEventListener('keyup', filtrarFilas);
        filterOperation.addEventListener('change', filtrarFilas);
    }

    function filtrarFilas() {
        const searchText = (searchInput.value || '').toLowerCase().trim();
        const operationVal = (filterOperation.value || '').toLowerCase().trim();
        const rows = document.querySelectorAll('.property-row');

        rows.forEach(row => {
            const rowText = (row.getAttribute('data-text') || '').toLowerCase();
            const rowOp = (row.getAttribute('data-operation') || '').toLowerCase();

            const matchesSearch = rowText.includes(searchText);
            const matchesOp = operationVal === '' || rowOp === operationVal;

            row.style.display = (matchesSearch && matchesOp) ? '' : 'none';
        });
    }

    // ===== Acciones del sistema =====
    window.nuevaPropiedad = function() {
        window.location.href = 'propiedad_nueva.php';
    };

    window.verPropiedad = function(id) {
        window.location.href = 'propiedad_detalle_inventario.php?id=' + id;
    };

    window.editarPropiedad = function(id) {
        window.location.href = 'propiedad_editar_inventario.php?id=' + id;
    };
});
</script>

</body>
</html>