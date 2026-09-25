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

// ===== Vista seleccionada: activas | vendidas | todas =====
$vista = $_GET['vista'] ?? 'activas';
if (!in_array($vista, ['activas', 'vendidas', 'todas'])) {
    $vista = 'activas';
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
        // Construir WHERE según vista
        $whereSql = '';
        if ($vista === 'activas') {
            $whereSql = "WHERE p.status = 'activo'";
        } elseif ($vista === 'vendidas') {
            $whereSql = "WHERE p.status = 'vendido'";
        }
        // 'todas' => sin WHERE

        // Consulta con JOIN para obtener datos de ambas tablas y la imagen principal
        $stmt = $conn->prepare("
            SELECT 
                p.id,
                p.title,
                p.operation_type,
                p.municipio,
                p.estado,
                p.colonia,
                p.domicilio,
                p.status,
                p.created_at,
                f.asking_price as price,
                f.min_acceptable_price,
                f.potential_profit_margin,
                m.file_path as image_url,
                m.is_primary as is_primary_image
            FROM properties p
            LEFT JOIN property_financials f ON p.id = f.property_id
            LEFT JOIN property_media m ON p.id = m.property_id AND m.is_primary = 1
            $whereSql
            ORDER BY p.created_at DESC
            LIMIT 100
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
        error_log("Propiedades encontradas (vista=$vista): " . count($propiedades));
        
        // Si no hay resultados, mostrar mensaje informativo según vista
        if (empty($propiedades)) {
            if ($vista === 'activas') {
                $error_msg = "No hay propiedades activas en el sistema.";
            } elseif ($vista === 'vendidas') {
                $error_msg = "Aún no hay propiedades vendidas en el historial.";
            } else {
                $error_msg = "No hay propiedades registradas en el sistema.";
            }
        }
    }
} catch (PDOException $e) {
    $error_msg = "Error al cargar propiedades: " . $e->getMessage();
    error_log("Error en inventario_maestro.php: " . $e->getMessage());
}

// ===== Contadores globales (para mostrar en los tabs) =====
$countActivas     = 0;
$countVendidas = 0;
$countTodas       = 0;

try {
    $countActivas     = (int)$conn->query("SELECT COUNT(*) FROM properties WHERE status = 'activo'")->fetchColumn();
    $countVendidas = (int)$conn->query("SELECT COUNT(*) FROM properties WHERE status = 'vendido'")->fetchColumn();
    $countTodas       = (int)$conn->query("SELECT COUNT(*) FROM properties")->fetchColumn();
} catch (PDOException $e) {
    error_log("Error contadores: " . $e->getMessage());
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

// Función para obtener el estado (AHORA SOPORTA "finalizada")
function getStatusBadge($status) {
    $status = strtolower(trim($status ?? ''));
    if ($status === 'activo') {
        return ['class' => 'status-active', 'label' => 'Activo'];
    } elseif ($status === 'inactivo') {
        return ['class' => 'status-inactive', 'label' => 'Inactivo'];
    } elseif (in_array($status, ['finalizada', 'finalizado', 'vendido', 'vendida'])) {
        return ['class' => 'status-sold', 'label' => 'Finalizada'];
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
    <title>Inventario de Propiedades | Vera Terra </title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <!-- ===== Librerías para exportación XLSX con estilos ===== -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.4.0/exceljs.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>
    
    <style>
        /* ===== ESTILOS CORPORATIVOS ===== */
        * {
            box-sizing: border-box;
        }

        /* ===== TABLA DE PROPIEDADES ===== */
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
            min-width: 900px;
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

        .properties-table tbody tr:hover {
            background: #f8faff;
        }

        .properties-table tbody tr:last-child td {
            border-bottom: none;
        }

        /* Columna imagen */
        .col-img {
            width: 60px;
            text-align: center;
        }

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

        /* Columna título */
        .col-title {
            min-width: 180px;
            max-width: 260px;
            font-weight: 600;
        }

        .col-title .op-badge {
            display: inline-block;
            font-size: 0.55rem;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #fff;
            margin-right: 6px;
            vertical-align: middle;
        }

        .op-badge.venta { background: #10b981; }
        .op-badge.renta { background: #3b82f6; }
        .op-badge.general { background: #6b7280; }

        /* Estados */
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
        .status-pill.status-inactive { background: #fee2e2; color: #991b1b; }
        .status-pill.status-sold { background: #fef3c7; color: #92400e; }
        .status-pill.status-other { background: #f1f5f9; color: #475569; }

        /* Precio */
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
            font-size: 0.75rem;
        }

        /* Acciones */
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
        .action-btn.excel:hover { background: #dcfce7; color: #16a34a; }

        /* Barra superior de la tabla */
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
        .btn-excel:disabled {
            background: #94a3b8;
            cursor: not-allowed;
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

        /* ===== Tabs de vista ===== */
        .view-tabs {
            display: flex;
            gap: 6px;
            margin: 0 0 12px 0;
            padding: 4px;
            background: #f1f5f9;
            border-radius: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .view-tab {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            color: #475569;
            text-decoration: none;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .view-tab:hover { 
            background: #e2e8f0; 
            color: #0f172a; 
        }
        .view-tab.active { 
            background: #fff; 
            color: #0f172a; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.08); 
        }
        .view-tab .tab-count {
            background: #cbd5e1;
            color: #334155;
            font-size: 0.68rem;
            padding: 1px 7px;
            border-radius: 10px;
            font-weight: 700;
            min-width: 18px;
            text-align: center;
        }
        .view-tab.active .tab-count { 
            background: #1d4ed8; 
            color: #fff; 
        }
        .view-tab.portal-link {
            margin-left: auto;
            background: #1d4ed8;
            color: #fff;
        }
        .view-tab.portal-link:hover {
            background: #1e40af;
            color: #fff;
        }

        /* Spinner de carga para exportación */
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
        .export-box i {
            font-size: 1.6rem;
            color: #16a34a;
        }
        .export-box span {
            font-size: 0.9rem;
            font-weight: 600;
            color: #0f172a;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .table-toolbar { flex-direction: column; align-items: stretch; }
            .table-toolbar .search-box { width: 100%; }
            .table-toolbar .search-box input { flex: 1; }
        }

        @media (max-width: 600px) {
            .view-tab.portal-link { margin-left: 0; }
        }

        /* Scroll horizontal para tabla en móvil */
        .table-container {
            overflow-x: auto;
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

    <!-- ===== Tabs de vista ===== -->
    <div class="view-tabs">
        <a href="?vista=activas" class="view-tab <?php echo $vista === 'activas' ? 'active' : ''; ?>">
            <i class="fas fa-home"></i> Activas
            <span class="tab-count"><?php echo $countActivas; ?></span>
        </a>
        <a href="?vista=vendidas" class="view-tab <?php echo $vista === 'vendidas' ? 'active' : ''; ?>">
            <i class="fas fa-check-circle"></i> Historial / Vendidas
            <span class="tab-count"><?php echo $countVendidas; ?></span>
        </a>
        <a href="?vista=todas" class="view-tab <?php echo $vista === 'todas' ? 'active' : ''; ?>">
            <i class="fas fa-list"></i> Todas
            <span class="tab-count"><?php echo $countTodas; ?></span>
        </a>
        <a href="mapa_ventas.php" class="view-tab portal-link">
            <i class="fas fa-map-marked-alt"></i> Portal de Métricas
        </a>
    </div>

    <div class="table-container">
        <div class="table-toolbar">
            <div class="toolbar-left">
                <h3><i class="fas fa-list-ul"></i> Listado de Inmuebles</h3>
            </div>
            <div class="search-box">
                <input type="text" placeholder="Buscar por municipio o título..." id="searchTable">
                <select id="filterOperation">
                    <option value="">Todas las operaciones</option>
                    <option value="venta">Venta</option>
                    <option value="compra">Compra</option>
                </select>
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
                    <i class="fas fa-home" style="font-size: 3rem; color: #94a3b8; margin-bottom: 15px;"></i>
                    <h3>No hay propiedades registradas</h3>
                    <p style="color: #94a3b8;">Comienza dando de alta una propiedad en el sistema</p>
                    <button onclick="nuevaPropiedad()" class="btn-header primary" style="margin-top: 20px;">
                        <i class="fas fa-plus"></i> Agregar Propiedad
                    </button>
                </div>
            <?php elseif (!empty($propiedades)): ?>
                <div class="properties-table-wrapper">
                    <table class="properties-table" id="propertiesTable">
                        <thead>
                            <tr>
                                <th class="col-img">Imagen</th>
                                <th class="col-title">Título</th>
                                <th>Status</th>
                                <th>Estado Geo.</th>
                                <th>Municipio</th>
                                <th>Colonia</th>
                                <th>Dirección</th>
                                <th style="text-align:right;">Precio</th>
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
                                $priceText = $hasPrice ? formatearPrecio($price) : 'Sin precio';
                                $imagePath = getImagePath($propiedad['image_url'] ?? '');
                                $hasImage = !empty($imagePath);
                                $title = htmlspecialchars($propiedad['title'] ?? 'Sin título');
                                $domicilio = htmlspecialchars($propiedad['domicilio'] ?? 'No especificado');
                                $estado = htmlspecialchars($propiedad['status'] ?? 'No especificado');
                                $estadoGeo = htmlspecialchars($propiedad['estado'] ?? 'No especificado');
                                $municipio = htmlspecialchars($propiedad['municipio'] ?? 'No especificado');
                                $colonia = htmlspecialchars($propiedad['colonia'] ?? 'No especificada');
                                $opType = strtolower(trim($propiedad['operation_type'] ?? ''));
                                $statusLower = strtolower(trim($propiedad['status'] ?? ''));
                                $esVendida = in_array($statusLower, ['vendido', 'vendida']);
                            ?>
                                <tr data-text="<?php echo strtolower($title . ' ' . $municipio . ' ' . $domicilio . ' ' . $colonia . ' ' . $estadoGeo); ?>"
                                    data-operation="<?php echo $opType; ?>">
                                    <td class="col-img">
                                        <div class="thumb">
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
                                        <span class="op-badge <?php echo $badge['class']; ?>" data-exclude="true"><?php echo $badge['label']; ?></span>
                                        <?php echo $title; ?>
                                        <?php if ($esVendida): ?>
                                            <i class="fas fa-flag-checkered" style="color:#92400e; margin-left:4px;" title="Histórica"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-pill <?php echo $statusBadge['class']; ?>">
                                            <?php echo $statusBadge['label']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $estadoGeo; ?></td>
                                    <td><?php echo $municipio; ?></td>
                                    <td><?php echo $colonia; ?></td>
                                    <td><?php echo $domicilio; ?></td>
                                    <td class="col-price <?php echo $priceClass; ?>" 
                                        data-raw-price="<?php echo $hasPrice ? floatval($price) : ''; ?>">
                                        <?php echo $priceText; ?>
                                    </td>
                                    <td class="col-actions">
                                        <button class="action-btn view" title="Ver detalles" onclick="verPropiedad('<?php echo $propiedad['id']; ?>')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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

<!-- Overlay de carga para exportación -->
<div class="export-overlay" id="exportOverlay">
    <div class="export-box">
        <i class="fas fa-file-excel fa-spin"></i>
        <span>Generando archivo Excel...</span>
    </div>
</div>

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
        const rows = document.querySelectorAll('.properties-table tbody tr');

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
        window.location.href = 'vender.php';
    };

    window.verPropiedad = function(id) {
        window.location.href = 'propiedad_detalle_inventario.php?id=' + id;
    };

    window.editarPropiedad = function(id) {
        window.location.href = 'propiedad_editar_inventario.php?id=' + id;
    };
});

// ===== Exportar a Excel (.xlsx) con formato corporativo usando ExcelJS =====
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

    // Mostrar overlay de carga
    const overlay = document.getElementById('exportOverlay');
    const btnExportar = document.getElementById('btnExportar');
    if (overlay) overlay.classList.add('show');
    if (btnExportar) btnExportar.disabled = true;

    try {
        // ===== Recopilar datos de la tabla =====
        const filas = [];
        const headers = [];

        // Encabezados (excepto la última columna "Acciones")
        tabla.querySelectorAll('thead th').forEach((th, idx, arr) => {
            if (idx < arr.length - 1) {
                headers.push(th.innerText.trim().toUpperCase());
            }
        });

        // Filas de datos (excepto la última celda "Acciones")
        tabla.querySelectorAll('tbody tr').forEach(tr => {
            const celdas = tr.querySelectorAll('td');
            const fila = [];
            celdas.forEach((td, idx) => {
                if (idx < celdas.length - 1) {
                    // 1) Precio: usar data-raw-price
                    const rawPrice = td.getAttribute('data-raw-price');
                    if (rawPrice !== null && rawPrice !== '' && !isNaN(parseFloat(rawPrice))) {
                        fila.push({ tipo: 'numero', valor: parseFloat(rawPrice) });
                        return;
                    }
                    if (rawPrice !== null && rawPrice === '') {
                        fila.push({ tipo: 'texto', valor: 'Sin precio' });
                        return;
                    }

                    // 2) Título: usar data-raw-title (sin badge)
                    const rawTitle = td.getAttribute('data-raw-title');
                    if (rawTitle !== null) {
                        fila.push({ tipo: 'texto', valor: rawTitle.trim() });
                        return;
                    }

                    // 3) Resto: clonar, quitar elementos excluidos e iconos, leer texto limpio
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

        // ===== Crear workbook =====
        const workbook = new ExcelJS.Workbook();
        workbook.creator = 'Vera Terra Inmobiliaria';
        workbook.created = new Date();

        const worksheet = workbook.addWorksheet('Inventario', {
            views: [{ state: 'frozen', ySplit: 4 }]
        });

        // ===== Calcular anchos de columna dinámicamente =====
        // Para cada columna, buscamos el texto más largo (encabezado y datos)
        const anchos = headers.map((h, colIdx) => {
            let maxLen = h.length;
            filas.forEach(fila => {
                const cell = fila[colIdx];
                if (!cell) return;
                let texto = '';
                if (cell.tipo === 'numero') {
                    // Formato aproximado: $10,000,000
                    texto = '$' + cell.valor.toLocaleString('en-US');
                } else {
                    texto = String(cell.valor || '');
                }
                if (texto.length > maxLen) maxLen = texto.length;
            });
            // Ancho = largo máximo + padding, con límites min/max
            let ancho = maxLen + 4;
            if (ancho < 12) ancho = 12;   // mínimo
            if (ancho > 55) ancho = 55;   // máximo
            return ancho;
        });

        // Aplicar anchos
        worksheet.columns = anchos.map(w => ({ width: w }));

        // ===== Encabezado con logo y título (filas 1-3) =====
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
        const ultimaColLetra = String.fromCharCode(64 + totalCols); // A=1, B=2...

        // Título corporativo
        worksheet.mergeCells(`B1:${ultimaColLetra}1`);
        const tituloCell = worksheet.getCell('B1');
        tituloCell.value = 'Vera Terra Inmobiliaria';
        tituloCell.font = { name: 'Calibri', size: 18, bold: true, color: { argb: 'FF1D4ED8' } };
        tituloCell.alignment = { vertical: 'middle', horizontal: 'left' };

        worksheet.mergeCells(`B2:${ultimaColLetra}2`);
        const subtituloCell = worksheet.getCell('B2');
        subtituloCell.value = 'Inventario Maestro de Propiedades';
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

        // ===== Fila 4: encabezados de la tabla =====
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

        // ===== Filas de datos =====
        filas.forEach((fila, rowIdx) => {
            const row = worksheet.getRow(5 + rowIdx);
            const esPar = rowIdx % 2 === 0;

            fila.forEach((celda, colIdx) => {
                const cell = row.getCell(colIdx + 1);

                if (celda.tipo === 'numero') {
                    // Precio como número real → formato moneda
                    cell.value = celda.valor;
                    cell.numFmt = '"$"#,##0';
                    cell.alignment = { vertical: 'middle', horizontal: 'right' };
                    cell.font = { name: 'Calibri', size: 10, bold: true, color: { argb: 'FF0F172A' } };
                } else {
                    cell.value = celda.valor || '';
                    // Si es "Sin precio", estilo gris cursiva
                    if (String(celda.valor).toLowerCase().includes('sin precio')) {
                        cell.font = { name: 'Calibri', size: 10, italic: true, color: { argb: 'FF94A3B8' } };
                        cell.alignment = { vertical: 'middle', horizontal: 'right' };
                    } else {
                        cell.font = { name: 'Calibri', size: 10, color: { argb: 'FF0F172A' } };
                        cell.alignment = { vertical: 'middle', horizontal: 'left', wrapText: false };
                    }
                }

                // Fondo alterno
                cell.fill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: { argb: esPar ? 'FFF8FAFC' : 'FFFFFFFF' }
                };

                // Bordes
                cell.border = {
                    top:    { style: 'thin', color: { argb: 'FFE2E8F0' } },
                    left:   { style: 'thin', color: { argb: 'FFE2E8F0' } },
                    bottom: { style: 'thin', color: { argb: 'FFE2E8F0' } },
                    right:  { style: 'thin', color: { argb: 'FFE2E8F0' } }
                };
            });

            row.height = 20;
        });

        // ===== Autofiltro =====
        worksheet.autoFilter = {
            from: { row: 4, column: 1 },
            to:   { row: 4, column: headers.length }
        };

        // ===== Generar y descargar =====
        const buffer = await workbook.xlsx.writeBuffer();
        const blob = new Blob([buffer], {
            type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        });
        const nombreArchivo = `Inventario_Propiedades_${new Date().toISOString().slice(0, 10)}.xlsx`;
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