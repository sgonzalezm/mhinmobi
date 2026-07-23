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
    $checkMultimedia = $conn->query("SHOW TABLES LIKE 'property_media'");
    
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

// Función para formatear precio (seguro)
function formatearPrecio($precio) {
    if ($precio === null || $precio === '' || $precio == 0) {
        return 'Precio no disponible';
    }
    return '$' . number_format(floatval($precio), 0, ',', '.');
}

// Función para obtener el badge de operación
function getOperationBadge($operationType) {
    $opType = strtolower(trim($operationType ?? ''));
    if ($opType === 'venta' || $opType === 'compra') {
        return ['class' => 'venta', 'label' => 'Venta'];
    } elseif ($opType === 'renta' || $opType === 'alquiler') {
        return ['class' => 'renta', 'label' => 'Renta'];
    } else {
        return ['class' => 'general', 'label' => 'General'];
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
        /* Estilos específicos para las tarjetas horizontales */
        .properties-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-top: 20px;
        }

        .property-card-horizontal {
            display: flex;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            transition: all 0.2s ease-in-out;
            position: relative;
            height: 100%;
            min-height: 180px;
        }

        .property-card-horizontal:hover {
            box-shadow: 0 6px 12px rgba(0,0,0,0.08);
            transform: translateY(-2px);
            border-color: #cbd5e1;
        }

        .property-image-container {
            width: 140px;
            min-width: 140px;
            position: relative;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .property-image-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            min-height: 120px;
        }

        .property-image-container .no-image {
            color: #94a3b8;
            font-size: 2rem;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
        }

        .property-image-container .no-image i {
            font-size: 2.5rem;
        }

        .property-image-container .no-image span {
            font-size: 0.6rem;
            color: #cbd5e1;
        }

        .property-badge {
            position: absolute;
            top: 8px;
            left: 8px;
            background: rgba(16, 185, 129, 0.9);
            color: white;
            font-size: 0.65rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            z-index: 1;
        }

        .property-badge.venta {
            background: rgba(16, 185, 129, 0.9);
        }

        .property-badge.renta {
            background: rgba(59, 130, 246, 0.9);
        }

        .property-badge.general {
            background: rgba(107, 114, 128, 0.9);
        }

        .property-content {
            padding: 14px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            flex: 1;
            min-width: 0;
        }

        .property-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 4px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            line-height: 1.3;
        }

        .property-location {
            font-size: 0.8rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 8px;
        }

        .property-location i {
            color: #10b981;
        }

        .property-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-top: 1px solid #f1f5f9;
            padding-top: 8px;
            margin-top: auto;
            flex-wrap: wrap;
            gap: 5px;
        }

        .property-price {
            font-size: 1rem;
            font-weight: 800;
            color: #059669;
        }

        .property-price.no-price {
            color: #94a3b8;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .property-actions {
            display: flex;
            gap: 6px;
        }

        .property-action-btn {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #64748b;
            width: 28px;
            height: 28px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            border: none;
        }

        .property-action-btn:hover {
            background: #10b981;
            color: white;
            border-color: #10b981;
        }

        .message-box {
            padding: 15px 20px;
            border-radius: 8px;
            margin: 10px 0;
            display: flex;
            align-items: center;
            gap: 12px;
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
            font-size: 1.2rem;
        }

        .stats-extra {
            display: flex;
            gap: 10px;
            font-size: 0.85rem;
            color: #64748b;
            margin-top: 5px;
            flex-wrap: wrap;
        }

        .stats-extra span {
            background: #f1f5f9;
            padding: 2px 10px;
            border-radius: 12px;
        }

        /* Responsivo */
        @media (max-width: 992px) {
            .properties-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .property-image-container {
                width: 100px;
                min-width: 100px;
            }
            
            .property-content {
                padding: 10px;
            }
            
            .property-title {
                font-size: 0.85rem;
            }
            
            .property-footer {
                flex-direction: column;
                align-items: stretch;
            }
            
            .property-actions {
                justify-content: flex-end;
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
            <h3><i class="fas fa-th-large"></i> Catálogo de Inmuebles</h3>
            <div class="search-box">
                <input type="text" placeholder="Buscar por municipio o título..." id="searchTable">
                <select id="filterOperation">
                    <option value="">Todas las operaciones</option>
                    <option value="venta">Venta</option>
                    <option value="renta">Renta</option>
                </select>
            </div>
        </div>

        <div style="padding: 20px;">
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
                        $price = $propiedad['price'] ?? null;
                        $hasPrice = ($price !== null && $price > 0);
                        $priceClass = $hasPrice ? '' : 'no-price';
                        $priceText = $hasPrice ? formatearPrecio($price) : 'Sin precio asignado';
                        $imagePath = getImagePath($propiedad['image_url'] ?? '');
                        $hasImage = !empty($imagePath);
                    ?>
                        <div class="property-card-horizontal" 
                             data-text="<?php echo strtolower(htmlspecialchars(($propiedad['title'] ?? '') . ' ' . ($propiedad['municipality'] ?? ''))); ?>" 
                             data-operation="<?php echo strtolower(trim($propiedad['operation_type'] ?? '')); ?>">
                            <div class="property-image-container">
                                <span class="property-badge <?php echo $badge['class']; ?>">
                                    <?php echo $badge['label']; ?>
                                </span>
                                <?php if ($hasImage): ?>
                                    <img src="<?php echo $imagePath; ?>" 
                                         alt="<?php echo htmlspecialchars($propiedad['title'] ?? 'Inmueble'); ?>" 
                                         loading="lazy"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="no-image" style="display: none;">
                                        <i class="fas fa-image"></i>
                                        <span>Error al cargar</span>
                                    </div>
                                <?php else: ?>
                                    <div class="no-image">
                                        <i class="fas fa-building"></i>
                                        <span>Sin imagen</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="property-content">
                                <div>
                                    <div class="property-title" title="<?php echo htmlspecialchars($propiedad['title'] ?? 'Sin Título'); ?>">
                                        <?php echo htmlspecialchars($propiedad['title'] ?? 'Sin Título'); ?>
                                    </div>
                                    <div class="property-location">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <span><?php echo htmlspecialchars($propiedad['municipality'] ?? 'Ubicación no especificada'); ?></span>
                                    </div>
                                    <?php if ($hasPrice && isset($propiedad['commission_percentage']) && $propiedad['commission_percentage'] > 0): ?>
                                        <div style="font-size: 0.7rem; color: #94a3b8; margin-top: 2px;">
                                            <i class="fas fa-percent"></i> Comisión: <?php echo number_format($propiedad['commission_percentage'], 1); ?>%
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="property-footer">
                                    <div class="property-price <?php echo $priceClass; ?>">
                                        <?php echo $priceText; ?>
                                    </div>
                                    <div class="property-actions">
                                        <button class="property-action-btn" title="Ver detalles" onclick="verPropiedad('<?php echo $propiedad['id']; ?>')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="property-action-btn" title="Editar" onclick="editarPropiedad('<?php echo $propiedad['id']; ?>')">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Mostrar datos de depuración (solo en desarrollo) -->
                <?php if (isset($_GET['debug'])): ?>
                    <div style="margin-top: 30px; padding: 20px; background: #f8fafc; border-radius: 8px; overflow-x: auto;">
                        <h4>Datos de depuración (primer registro):</h4>
                        <pre style="font-size: 12px;"><?php print_r(reset($propiedades)); ?></pre>
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
        searchInput.addEventListener('keyup', filtrarTarjetas);
        filterOperation.addEventListener('change', filtrarTarjetas);
    }

    function filtrarTarjetas() {
        const searchText = (searchInput.value || '').toLowerCase().trim();
        const operationVal = (filterOperation.value || '').toLowerCase().trim();
        const cards = document.querySelectorAll('.property-card-horizontal');

        cards.forEach(card => {
            const cardText = (card.getAttribute('data-text') || '').toLowerCase();
            const cardOp = (card.getAttribute('data-operation') || '').toLowerCase();

            const matchesSearch = cardText.includes(searchText);
            const matchesOp = operationVal === '' || cardOp === operationVal;

            card.style.display = (matchesSearch && matchesOp) ? '' : 'none';
        });
    }

    // ===== Acciones del sistema =====
    window.nuevaPropiedad = function() {
        window.location.href = 'propiedad_nueva.php';
    };

    window.verPropiedad = function(id) {
        window.location.href = 'propiedad_detalle.php?id=' + id;
    };

    window.editarPropiedad = function(id) {
        window.location.href = 'propiedad_editar.php?id=' + id;
    };
});
</script>

</body>
</html>