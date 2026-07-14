<?php
session_start();
require_once 'includes/conexion.php';

// ========================================
// CONFIGURACIÓN DE PAGINACIÓN
// ========================================
$propiedades_por_pagina = 9;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$pagina_actual = max(1, $pagina_actual);
$offset = ($pagina_actual - 1) * $propiedades_por_pagina;

// ========================================
// FILTROS
// ========================================
$filtro_operacion = isset($_GET['operacion']) ? $_GET['operacion'] : '';
$filtro_ciudad = isset($_GET['ciudad']) ? $_GET['ciudad'] : '';
$filtro_precio_min = isset($_GET['precio_min']) ? (float)$_GET['precio_min'] : 0;
$filtro_precio_max = isset($_GET['precio_max']) ? (float)$_GET['precio_max'] : 999999999;
$filtro_recamaras = isset($_GET['recamaras']) ? (int)$_GET['recamaras'] : 0;
$busqueda = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';

// ========================================
// CONSTRUIR CONSULTA CON FILTROS
// ========================================
// 🔥 CAMBIO: Ya no filtramos por status, mostramos todas
$where_conditions = ["1=1"];
$params = [];

if (!empty($filtro_operacion)) {
    $where_conditions[] = "p.operation_type = ?";
    $params[] = $filtro_operacion;
}

if (!empty($filtro_ciudad)) {
    $where_conditions[] = "(p.address_city LIKE ? OR p.address_municipality LIKE ?)";
    $params[] = "%$filtro_ciudad%";
    $params[] = "%$filtro_ciudad%";
}

if ($filtro_precio_min > 0) {
    $where_conditions[] = "pf.asking_price >= ?";
    $params[] = $filtro_precio_min;
}

if ($filtro_precio_max < 999999999) {
    $where_conditions[] = "pf.asking_price <= ?";
    $params[] = $filtro_precio_max;
}

if ($filtro_recamaras > 0) {
    $where_conditions[] = "pd.bedrooms >= ?";
    $params[] = $filtro_recamaras;
}

if (!empty($busqueda)) {
    $where_conditions[] = "(p.titulo LIKE ? OR p.address_city LIKE ? OR p.address_municipality LIKE ?)";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
}

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// ========================================
// CONTAR TOTAL DE PROPIEDADES
// ========================================
$sql_count = "SELECT COUNT(DISTINCT p.id) as total 
              FROM properties p
              LEFT JOIN property_details pd ON p.id = pd.property_id
              LEFT JOIN property_financials pf ON p.id = pf.property_id
              $where_sql";

$stmt_count = $conn->prepare($sql_count);
$stmt_count->execute($params);
$total_propiedades = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
$total_paginas = ceil($total_propiedades / $propiedades_por_pagina);

// ========================================
// OBTENER PROPIEDADES
// ========================================
$sql = "SELECT 
            p.id,
            p.operation_type,
            p.status,
            p.address_city,
            p.address_municipality,
            p.created_at,
            pd.square_meters,
            pd.bedrooms,
            pd.bathrooms,
            pd.parking_spots,
            pf.asking_price,
            (SELECT file_path FROM property_media 
             WHERE property_id = p.id AND is_primary = 1 
             LIMIT 1) as imagen_principal,
            (SELECT COUNT(*) FROM property_media WHERE property_id = p.id) as total_imagenes,
            u.name as propietario
        FROM properties p
        LEFT JOIN property_details pd ON p.id = pd.property_id
        LEFT JOIN property_financials pf ON p.id = pf.property_id
        LEFT JOIN users u ON p.owner_id = u.id
        $where_sql
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?";

// Agregar parámetros de paginación
$params[] = $propiedades_por_pagina;
$params[] = $offset;

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$propiedades = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ========================================
// OBTENER CIUDADES ÚNICAS PARA FILTROS
// ========================================
$sql_ciudades = "SELECT DISTINCT address_city FROM properties 
                 WHERE address_city IS NOT NULL AND address_city != ''
                 ORDER BY address_city";
$stmt_ciudades = $conn->query($sql_ciudades);
$ciudades = $stmt_ciudades->fetchAll(PDO::FETCH_COLUMN);

// ========================================
// DEPURACIÓN - Ver qué propiedades se obtuvieron
// ========================================
error_log("=== PROPIEDADES ENCONTRADAS ===");
error_log("Total: " . $total_propiedades);
error_log("Propiedades: " . print_r($propiedades, true));
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🏠 Propiedades Disponibles</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ======================================== */
        /* ESTILOS PRINCIPALES */
        /* ======================================== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            min-height: 100vh;
        }

        .container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 20px;
        }

        /* ======================================== */
        /* HEADER */
        /* ======================================== */
        .page-header {
            background: linear-gradient(135deg, #1a1a2e 0%, #2d2d44 100%);
            color: white;
            padding: 30px 0;
            border-radius: 16px;
            margin-bottom: 30px;
            text-align: center;
        }

        .page-header h1 {
            font-size: 32px;
            font-weight: 700;
        }

        .page-header p {
            opacity: 0.8;
            margin-top: 5px;
        }

        .total-propiedades {
            display: inline-block;
            background: rgba(255,255,255,0.15);
            padding: 5px 18px;
            border-radius: 20px;
            font-size: 14px;
            margin-top: 10px;
        }

        /* ======================================== */
        /* BARRA DE BÚSQUEDA Y FILTROS */
        /* ======================================== */
        .filters-section {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }

        .filters-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }

        .filters-row .search-box {
            flex: 2;
            min-width: 200px;
        }

        .filters-row .filter-group {
            flex: 1;
            min-width: 150px;
        }

        .filters-row input,
        .filters-row select {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            background: white;
        }

        .filters-row input:focus,
        .filters-row select:focus {
            border-color: #1a1a2e;
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 26, 46, 0.1);
        }

        .filters-row .btn-filter {
            padding: 10px 24px;
            background: #1a1a2e;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            min-width: 80px;
        }

        .filters-row .btn-filter:hover {
            background: #2d2d44;
            transform: translateY(-2px);
        }

        .filters-row .btn-clear {
            padding: 10px 20px;
            background: transparent;
            color: #6c757d;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .filters-row .btn-clear:hover {
            background: #f8f9fa;
        }

        /* ======================================== */
        /* GRID DE PROPIEDADES */
        /* ======================================== */
        .properties-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }

        /* ======================================== */
        /* TARJETA DE PROPIEDAD */
        /* ======================================== */
        .property-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 15px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            position: relative;
        }

        .property-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
        }

        .property-image {
            position: relative;
            height: 220px;
            background: #dee2e6;
            overflow: hidden;
        }

        .property-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .property-card:hover .property-image img {
            transform: scale(1.03);
        }

        .property-image .no-image {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #6c757d;
            font-size: 14px;
            background: #e9ecef;
            flex-direction: column;
            gap: 10px;
        }

        .property-image .no-image i {
            font-size: 48px;
            opacity: 0.3;
        }

        .property-badges {
            position: absolute;
            top: 12px;
            left: 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .property-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-venta {
            background: #28a745;
            color: white;
        }

        .badge-alquiler {
            background: #007bff;
            color: white;
        }

        .badge-renta {
            background: #fd7e14;
            color: white;
        }

        .badge-nuevo {
            background: #dc3545;
            color: white;
            animation: pulse-badge 2s infinite;
        }

        @keyframes pulse-badge {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        .property-imagenes-count {
            position: absolute;
            bottom: 12px;
            right: 12px;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .property-body {
            padding: 18px 20px 20px;
        }

        .property-title {
            font-size: 18px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 4px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
            min-height: 50px;
        }

        .property-title a {
            color: #1a1a2e;
            text-decoration: none;
        }

        .property-title a:hover {
            color: #28a745;
        }

        .property-location {
            color: #6c757d;
            font-size: 14px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .property-location i {
            font-size: 13px;
        }

        .property-price {
            font-size: 24px;
            font-weight: 700;
            color: #28a745;
            margin-bottom: 10px;
        }

        .property-features {
            display: flex;
            gap: 15px;
            padding: 10px 0;
            border-top: 1px solid #f0f0f0;
            border-bottom: 1px solid #f0f0f0;
            margin-bottom: 12px;
        }

        .property-features .feature {
            display: flex;
            align-items: center;
            gap: 5px;
            color: #495057;
            font-size: 13px;
        }

        .property-features .feature i {
            color: #6c757d;
            font-size: 14px;
        }

        .property-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 5px;
        }

        .property-footer .propietario {
            color: #6c757d;
            font-size: 13px;
        }

        .property-footer .propietario i {
            margin-right: 4px;
        }

        .property-footer .btn-ver {
            padding: 8px 20px;
            background: #1a1a2e;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            font-size: 14px;
        }

        .property-footer .btn-ver:hover {
            background: #2d2d44;
            transform: translateY(-2px);
        }

        /* ======================================== */
        /* PAGINACIÓN */
        /* ======================================== */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 40px;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            padding: 10px 16px;
            border-radius: 8px;
            text-decoration: none;
            color: #1a1a2e;
            font-weight: 600;
            transition: all 0.3s;
            border: 2px solid transparent;
        }

        .pagination a:hover {
            background: #f0f0f0;
            border-color: #dee2e6;
        }

        .pagination .active {
            background: #1a1a2e;
            color: white;
            border-color: #1a1a2e;
        }

        .pagination .disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        /* ======================================== */
        /* SIN RESULTADOS */
        /* ======================================== */
        .no-results {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }

        .no-results i {
            font-size: 64px;
            opacity: 0.3;
            margin-bottom: 20px;
        }

        .no-results h3 {
            color: #1a1a2e;
            margin-bottom: 10px;
        }

        /* ======================================== */
        /* RESPONSIVE */
        /* ======================================== */
        @media (max-width: 768px) {
            .filters-row .search-box,
            .filters-row .filter-group {
                min-width: 100%;
                flex: 100%;
            }

            .filters-row .btn-filter,
            .filters-row .btn-clear {
                width: 100%;
            }

            .properties-grid {
                grid-template-columns: 1fr;
            }

            .page-header h1 {
                font-size: 24px;
            }

            .property-price {
                font-size: 20px;
            }
        }

        /* ======================================== */
        /* SPINNER DE CARGA */
        /* ======================================== */
        .loading {
            text-align: center;
            padding: 40px;
            display: none;
        }

        .loading i {
            font-size: 40px;
            color: #1a1a2e;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>

<div class="container">
    <!-- ======================================== -->
    <!-- HEADER -->
    <!-- ======================================== -->
    <div class="page-header">
        <h1>🏠 Propiedades Disponibles</h1>
        <p>Encuentra la propiedad que estás buscando</p>
        <span class="total-propiedades">
            <i class="fas fa-home"></i> <?php echo number_format($total_propiedades); ?> propiedades
        </span>
    </div>

    <!-- ======================================== -->
    <!-- FILTROS Y BÚSQUEDA -->
    <!-- ======================================== -->
    <div class="filters-section">
        <form method="GET" action="" id="filtersForm">
            <div class="filters-row">
                <div class="search-box">
                    <input type="text" name="buscar" placeholder="🔍 Buscar por título o ubicación..." 
                           value="<?php echo htmlspecialchars($busqueda); ?>">
                </div>

                <div class="filter-group">
                    <select name="operacion">
                        <option value="">Tipo de operación</option>
                        <option value="venta" <?php echo $filtro_operacion == 'venta' ? 'selected' : ''; ?>>Venta</option>
                        <option value="alquiler" <?php echo $filtro_operacion == 'alquiler' ? 'selected' : ''; ?>>Alquiler</option>
                        <option value="renta" <?php echo $filtro_operacion == 'renta' ? 'selected' : ''; ?>>Renta</option>
                    </select>
                </div>

                <div class="filter-group">
                    <select name="ciudad">
                        <option value="">Todas las ciudades</option>
                        <?php foreach ($ciudades as $ciudad): ?>
                            <option value="<?php echo htmlspecialchars($ciudad); ?>" 
                                <?php echo $filtro_ciudad == $ciudad ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ciudad); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <select name="recamaras">
                        <option value="0">Recámaras</option>
                        <option value="1" <?php echo $filtro_recamaras == 1 ? 'selected' : ''; ?>>1+</option>
                        <option value="2" <?php echo $filtro_recamaras == 2 ? 'selected' : ''; ?>>2+</option>
                        <option value="3" <?php echo $filtro_recamaras == 3 ? 'selected' : ''; ?>>3+</option>
                        <option value="4" <?php echo $filtro_recamaras == 4 ? 'selected' : ''; ?>>4+</option>
                        <option value="5" <?php echo $filtro_recamaras == 5 ? 'selected' : ''; ?>>5+</option>
                    </select>
                </div>

                <button type="submit" class="btn-filter">
                    <i class="fas fa-search"></i> Filtrar
                </button>

                <a href="propiedades_inventario.php" class="btn-clear">
                    <i class="fas fa-times"></i> Limpiar
                </a>
            </div>
        </form>
    </div>

    <!-- ======================================== -->
    <!-- GRID DE PROPIEDADES -->
    <!-- ======================================== -->
    <div id="loading" class="loading">
        <i class="fas fa-spinner"></i>
    </div>

    <?php if (empty($propiedades)): ?>
        <div class="no-results">
            <i class="fas fa-home"></i>
            <h3>No se encontraron propiedades</h3>
            <p>Intenta ajustar los filtros de búsqueda</p>
            <?php if ($total_propiedades == 0): ?>
                <p style="margin-top: 10px; font-size: 14px;">
                    💡 <strong>Consejo:</strong> Asegúrate de que las propiedades tengan datos completos en las tablas 
                    <code>property_details</code> y <code>property_financials</code>
                </p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="properties-grid">
            <?php foreach ($propiedades as $propiedad): 
                $es_nuevo = (strtotime($propiedad['created_at']) > strtotime('-7 days'));
                $imagen_url = $propiedad['imagen_principal'] ?? null;
                $imagen_existe = $imagen_url && file_exists($imagen_url);
            ?>
                <div class="property-card">
                    <!-- Imagen -->
                    <div class="property-image">
                        <?php if ($imagen_existe): ?>
                            <img src="<?php echo htmlspecialchars($imagen_url); ?>" 
                                 alt="<?php echo htmlspecialchars($propiedad['titulo'] ?? 'Propiedad'); ?>">
                        <?php else: ?>
                            <div class="no-image">
                                <i class="fas fa-home"></i>
                                <span>Sin imagen</span>
                            </div>
                        <?php endif; ?>

                        <!-- Badges -->
                        <div class="property-badges">
                            <span class="property-badge badge-<?php echo $propiedad['operation_type']; ?>">
                                <?php echo ucfirst($propiedad['operation_type']); ?>
                            </span>
                            <?php if ($es_nuevo): ?>
                                <span class="property-badge badge-nuevo">Nuevo</span>
                            <?php endif; ?>
                        </div>

                        <!-- Contador de imágenes -->
                        <div class="property-imagenes-count">
                            <i class="fas fa-images"></i> <?php echo $propiedad['total_imagenes'] ?? 0; ?>
                        </div>
                    </div>

                    <!-- Cuerpo -->
                    <div class="property-body">
                        <h3 class="property-title">
                            <a href="propiedades_detalle.php?id=<?php echo $propiedad['id']; ?>">
                                <?php echo htmlspecialchars($propiedad['titulo'] ?? 'Propiedad sin título'); ?>
                            </a>
                        </h3>

                        <div class="property-location">
                            <i class="fas fa-map-marker-alt"></i>
                            <?php 
                            $ubicacion = '';
                            if (!empty($propiedad['address_city'])) {
                                $ubicacion .= $propiedad['address_city'];
                            }
                            if (!empty($propiedad['address_municipality'])) {
                                $ubicacion .= ($ubicacion ? ', ' : '') . $propiedad['address_municipality'];
                            }
                            echo htmlspecialchars($ubicacion ?: 'Ubicación no especificada');
                            ?>
                        </div>

                        <div class="property-price">
                            $<?php echo number_format($propiedad['asking_price'] ?? 0, 0); ?>
                        </div>

                        <div class="property-features">
                            <span class="feature">
                                <i class="fas fa-vector-square"></i>
                                <?php echo htmlspecialchars($propiedad['square_meters'] ?? 'N/A'); ?> m²
                            </span>
                            <span class="feature">
                                <i class="fas fa-bed"></i>
                                <?php echo htmlspecialchars($propiedad['bedrooms'] ?? 'N/A'); ?>
                            </span>
                            <span class="feature">
                                <i class="fas fa-bath"></i>
                                <?php echo htmlspecialchars($propiedad['bathrooms'] ?? 'N/A'); ?>
                            </span>
                            <span class="feature">
                                <i class="fas fa-car"></i>
                                <?php echo htmlspecialchars($propiedad['parking_spots'] ?? 'N/A'); ?>
                            </span>
                        </div>

                        <div class="property-footer">
                            <span class="propietario">
                                <i class="fas fa-user"></i>
                                <?php echo htmlspecialchars($propiedad['propietario'] ?? 'Propietario'); ?>
                            </span>
                            <a href="propiedades_detalle.php?id=<?php echo $propiedad['id']; ?>" class="btn-ver">
                                Ver detalles <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- ======================================== -->
        <!-- PAGINACIÓN -->
        <!-- ======================================== -->
        <?php if ($total_paginas > 1): ?>
            <div class="pagination">
                <?php if ($pagina_actual > 1): ?>
                    <a href="propiedades_inventario.php?pagina=<?php echo $pagina_actual - 1; ?>&<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual - 1])); ?>">
                        <i class="fas fa-chevron-left"></i> Anterior
                    </a>
                <?php else: ?>
                    <span class="disabled"><i class="fas fa-chevron-left"></i> Anterior</span>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                    <?php if ($i == $pagina_actual): ?>
                        <span class="active"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="propiedades_inventario.php?pagina=<?php echo $i; ?>&<?php echo http_build_query(array_merge($_GET, ['pagina' => $i])); ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($pagina_actual < $total_paginas): ?>
                    <a href="?pagina=<?php echo $pagina_actual + 1; ?>&<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual + 1])); ?>">
                        Siguiente <i class="fas fa-chevron-right"></i>
                    </a>
                <?php else: ?>
                    <span class="disabled">Siguiente <i class="fas fa-chevron-right"></i></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

</body>
</html>