<?php
// ============================================
// propiedades_portal.php
// Página pública de propiedades con datos de BD
// ============================================

session_start();
require_once 'includes/conexion.php';

// Configuración de paginación
$itemsPorPagina = 6;
$pagina_actual = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($pagina_actual < 1) $pagina_actual = 1;

// ===== OBTENER FILTROS =====
$filtro_tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'all';
$filtro_categoria = isset($_GET['categoria']) ? $_GET['categoria'] : 'all';
$filtro_busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';

// ===== CONSTRUIR CONSULTA =====
$where_conditions = [];
$params = [];

// Solo propiedades activas
$where_conditions[] = "p.status = 'activo'";

// Filtro por tipo de operación
if ($filtro_tipo !== 'all' && in_array($filtro_tipo, ['venta', 'renta'])) {
    $where_conditions[] = "p.operation_type = ?";
    $params[] = $filtro_tipo;
}

// Filtro por categoría (mapeo a tipos de propiedad)
if ($filtro_categoria !== 'all') {
    $categoria_map = [
        'residencial' => ['Casa', 'Departamento', 'Terreno'],
        'corporativo' => ['Local comercial', 'Oficina', 'Nave industrial'],
        'lujo' => ['Casa', 'Departamento']
    ];
    
    if (isset($categoria_map[$filtro_categoria])) {
        $placeholders = implode(',', array_fill(0, count($categoria_map[$filtro_categoria]), '?'));
        $where_conditions[] = "p.property_type IN ($placeholders)";
        $params = array_merge($params, $categoria_map[$filtro_categoria]);
    }
}

// Búsqueda por ubicación o título
if (!empty($filtro_busqueda)) {
    $where_conditions[] = "(p.title LIKE ? OR p.address_city LIKE ? OR p.address_municipality LIKE ?)";
    $search_param = '%' . $filtro_busqueda . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// ===== CONSULTA PRINCIPAL CON FEATURING =====
try {
    // Contar total de propiedades (para paginación)
    $count_sql = "
        SELECT COUNT(DISTINCT p.id) as total
        FROM properties p
        LEFT JOIN property_details pd ON p.id = pd.property_id
        LEFT JOIN property_financials pf ON p.id = pf.property_id
        $where_clause
    ";
    $stmt = $conn->prepare($count_sql);
    $stmt->execute($params);
    $total_propiedades = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    $total_paginas = ceil($total_propiedades / $itemsPorPagina);
    
    // Asegurar página válida
    if ($pagina_actual > $total_paginas && $total_paginas > 0) {
        $pagina_actual = $total_paginas;
    }
    $offset = ($pagina_actual - 1) * $itemsPorPagina;
    
    // ===== CONSULTA DE PROPIEDADES CON FEATURING =====
    $sql = "
        SELECT 
            p.id,
            p.title,
            p.operation_type,
            p.address_city,
            p.address_municipality,
            p.status,
            p.property_type,
            p.created_at,
            pd.square_meters,
            pd.bedrooms,
            pd.bathrooms,
            pd.parking_spots,
            pf.asking_price as price,
            pf.min_acceptable_price,
            pf.commission_percentage,
            -- Featuring
            f.id as featuring_id,
            f.start_date as featuring_start,
            f.end_date as featuring_end,
            f.dias as featuring_dias,
            f.status as featuring_status,
            -- Imagen principal
            (SELECT file_path FROM property_media 
             WHERE property_id = p.id AND is_primary = 1 
             ORDER BY sort_order ASC LIMIT 1) as imagen_principal,
            -- Conteo de imágenes
            (SELECT COUNT(*) FROM property_media WHERE property_id = p.id) as total_imagenes
        FROM properties p
        LEFT JOIN property_details pd ON p.id = pd.property_id
        LEFT JOIN property_financials pf ON p.id = pf.property_id
        LEFT JOIN property_featuring f ON p.id = f.property_id AND f.status = 'active'
        $where_clause
        GROUP BY p.id
        ORDER BY 
            -- Primero las que tienen featuring activo
            CASE WHEN f.status = 'active' THEN 0 ELSE 1 END,
            -- Luego por fecha de creación (más recientes primero)
            p.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $conn->prepare($sql);
    $params_paginacion = array_merge($params, [$itemsPorPagina, $offset]);
    $stmt->execute($params_paginacion);
    $propiedades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error en propiedades_portal: " . $e->getMessage());
    $propiedades = [];
    $total_paginas = 0;
    $total_propiedades = 0;
}

// ===== FUNCIONES AUXILIARES =====
function formatearPrecio($precio) {
    if ($precio === null || $precio == 0) {
        return 'Consultar precio';
    }
    return '$' . number_format(floatval($precio), 0, ',', '.');
}

function getTipoClase($tipo) {
    return strtolower($tipo) === 'venta' ? 'venta' : 'renta';
}

function getTipoLabel($tipo) {
    return strtolower($tipo) === 'venta' ? 'En Venta' : 'En Renta';
}

function getCategoriaIcon($property_type) {
    $icons = [
        'Casa' => 'fa-regular fa-house',
        'Departamento' => 'fa-regular fa-building',
        'Terreno' => 'fa-regular fa-map',
        'Local comercial' => 'fa-regular fa-store',
        'Oficina' => 'fa-regular fa-building-columns',
        'Nave industrial' => 'fa-regular fa-warehouse'
    ];
    return $icons[$property_type] ?? 'fa-regular fa-building';
}

function getImagenUrl($imagen) {
    if (empty($imagen)) {
        return 'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?auto=format&fit=crop&w=600&q=80';
    }
    // Si ya tiene la ruta completa
    if (strpos($imagen, 'uploads/') === 0) {
        return htmlspecialchars($imagen);
    }
    return 'uploads/propiedades/' . htmlspecialchars($imagen);
}

function getUbicacionCompleta($propiedad) {
    $parts = [];
    if (!empty($propiedad['address_municipality'])) {
        $parts[] = $propiedad['address_municipality'];
    }
    if (!empty($propiedad['address_city'])) {
        $parts[] = $propiedad['address_city'];
    }
    return !empty($parts) ? implode(', ', $parts) : 'Ubicación no especificada';
}

function tieneFeaturing($propiedad) {
    return !empty($propiedad['featuring_id']) && $propiedad['featuring_status'] === 'active';
}

function getDiasRestantesFeaturing($propiedad) {
    if (!tieneFeaturing($propiedad)) return 0;
    $end = strtotime($propiedad['featuring_end']);
    $now = time();
    return max(0, ceil(($end - $now) / 86400));
}

// ===== OBTENER CATEGORÍAS DISPONIBLES PARA FILTROS =====
$categorias_disponibles = [];
try {
    $stmt = $conn->prepare("
        SELECT DISTINCT property_type 
        FROM properties 
        WHERE status = 'activo' AND property_type IS NOT NULL AND property_type != ''
    ");
    $stmt->execute();
    $tipos = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($tipos as $tipo) {
        if (in_array($tipo, ['Casa', 'Departamento', 'Terreno'])) {
            $categorias_disponibles['residencial'][] = $tipo;
        } elseif (in_array($tipo, ['Local comercial', 'Oficina', 'Nave industrial'])) {
            $categorias_disponibles['corporativo'][] = $tipo;
        } elseif (in_array($tipo, ['Casa', 'Departamento'])) {
            $categorias_disponibles['lujo'][] = $tipo;
        }
    }
} catch (PDOException $e) {
    // Si hay error, usar categorías por defecto
    $categorias_disponibles = [
        'residencial' => ['Casa', 'Departamento', 'Terreno'],
        'corporativo' => ['Local comercial', 'Oficina', 'Nave industrial'],
        'lujo' => ['Casa', 'Departamento']
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Propiedades - Vera Terra Inmobiliaria</title>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet" />
    <style>
        /* ===== RESET & ROOT ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --navy: #0b1f3a;
            --gold: #c5a059;
            --gold-hover: #b08d46;
            --gold-light: #f2e6d0;
            --light-bg: #f8f7f4;
            --text-dark: #1e1e1e;
            --text-muted: #5a5a5a;
            --shadow: 0 8px 30px rgba(11, 31, 58, 0.08);
            --radius: 10px;
            --transition: 0.3s ease;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Montserrat', sans-serif;
            background: #ffffff;
            color: var(--text-dark);
            line-height: 1.6;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        img {
            max-width: 100%;
            display: block;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* ===== BOTONES ===== */
        .btn-gold {
            display: inline-block;
            background: var(--gold);
            color: #fff;
            padding: 14px 36px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.95rem;
            border: none;
            cursor: pointer;
            transition: background var(--transition), transform var(--transition), box-shadow var(--transition);
            box-shadow: 0 4px 14px rgba(197, 160, 89, 0.3);
            letter-spacing: 0.3px;
        }

        .btn-gold:hover {
            background: var(--gold-hover);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(197, 160, 89, 0.4);
        }

        .btn-outline-gold {
            display: inline-block;
            background: transparent;
            color: var(--gold);
            padding: 12px 30px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.9rem;
            border: 2px solid var(--gold);
            transition: background var(--transition), color var(--transition);
            cursor: pointer;
        }

        .btn-outline-gold:hover {
            background: var(--gold);
            color: #fff;
        }

        .btn-whatsapp {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: #25D366;
            color: #fff;
            padding: 12px 28px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.95rem;
            border: none;
            cursor: pointer;
            transition: background var(--transition), transform var(--transition);
            box-shadow: 0 4px 14px rgba(37, 211, 102, 0.3);
        }

        .btn-whatsapp:hover {
            background: #1da85a;
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(37, 211, 102, 0.4);
        }

        .section-title {
            font-family: 'Playfair Display', serif;
            font-size: 2.2rem;
            color: var(--navy);
            margin-bottom: 10px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .section-subtitle {
            color: var(--text-muted);
            font-size: 1rem;
            font-weight: 300;
            margin-bottom: 40px;
        }

        /* ===== HEADER / NAVBAR ===== */
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 5%;
            background: #ffffff;
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.04);
            position: sticky;
            top: 0;
            z-index: 100;
            transition: box-shadow 0.3s;
        }

        header.scrolled {
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.08);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
            font-size: 1.25rem;
            color: var(--navy);
            letter-spacing: 0.5px;
        }

        .logo-icon {
            width: 44px;
            height: 44px;
            background: linear-gradient(145deg, var(--navy), #1a3552);
            clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gold);
            font-weight: 700;
            font-size: 1.1rem;
        }

        .logo-text span {
            display: block;
            font-size: 0.6rem;
            font-weight: 400;
            color: var(--gold);
            letter-spacing: 3px;
            text-transform: uppercase;
        }

        nav ul {
            display: flex;
            list-style: none;
            gap: 30px;
            align-items: center;
        }

        nav a {
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text-dark);
            transition: color var(--transition);
            position: relative;
        }

        nav a::after {
            content: '';
            position: absolute;
            bottom: -4px;
            left: 0;
            width: 0%;
            height: 2px;
            background: var(--gold);
            transition: width var(--transition);
        }

        nav a:hover::after,
        nav a.active::after {
            width: 100%;
        }

        nav a:hover,
        nav a.active {
            color: var(--gold);
        }

        /* ===== FILTROS ===== */
        .filters-section {
            padding: 30px 5% 20px;
            background: var(--light-bg);
            border-bottom: 1px solid #eee;
        }

        .filters-wrapper {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
            justify-content: space-between;
        }

        .filters-group {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }

        .filters-group select,
        .filters-group input {
            padding: 10px 16px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.85rem;
            background: #fff;
            color: var(--text-dark);
            transition: border-color var(--transition);
            min-width: 140px;
        }

        .filters-group select:focus,
        .filters-group input:focus {
            outline: none;
            border-color: var(--gold);
        }

        .filters-group .btn-outline-gold {
            padding: 10px 24px;
            font-size: 0.85rem;
        }

        .results-count {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        .results-count span {
            color: var(--navy);
            font-weight: 700;
        }

        /* ===== PROPIEDADES GRID ===== */
        .properties-section {
            padding: 40px 5% 60px;
            background: #ffffff;
        }

        .properties-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 30px;
            margin-top: 10px;
        }

        .property-card {
            background: #fff;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: transform var(--transition), box-shadow var(--transition);
            border: 1px solid rgba(197, 160, 89, 0.12);
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .property-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 15px 40px rgba(11, 31, 58, 0.12);
        }

        /* ===== FEATURING BADGE ===== */
        .property-card.featured {
            border: 2px solid var(--gold);
            box-shadow: 0 8px 30px rgba(197, 160, 89, 0.2);
        }

        .property-card.featured::before {
            content: '⭐ DESTACADA';
            position: absolute;
            top: 12px;
            left: 12px;
            z-index: 10;
            background: var(--gold);
            color: #fff;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            box-shadow: 0 2px 10px rgba(197, 160, 89, 0.3);
        }

        .property-card.featured .property-img-container::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(to bottom, rgba(197, 160, 89, 0.1), transparent);
            pointer-events: none;
        }

        .featuring-countdown {
            position: absolute;
            bottom: 14px;
            right: 14px;
            background: rgba(11, 31, 58, 0.85);
            color: var(--gold);
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 0.6rem;
            font-weight: 600;
            letter-spacing: 0.3px;
            border: 1px solid var(--gold);
            z-index: 5;
        }

        .property-img-container {
            position: relative;
            height: 220px;
            overflow: hidden;
            background: #eee;
        }

        .property-img-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .property-card:hover .property-img-container img {
            transform: scale(1.05);
        }

        .property-badge {
            position: absolute;
            top: 14px;
            right: 14px;
            background: var(--navy);
            color: var(--gold);
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            border: 1px solid var(--gold);
            z-index: 5;
        }

        .property-status {
            position: absolute;
            bottom: 14px;
            left: 14px;
            background: rgba(11, 31, 58, 0.85);
            color: #fff;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            z-index: 5;
        }

        .property-status.venta {
            background: var(--gold);
            color: var(--navy);
        }

        .property-status.renta {
            background: #2e7d5e;
            color: #fff;
        }

        .property-info {
            padding: 20px 20px 18px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .property-info h3 {
            font-size: 1.05rem;
            font-weight: 600;
            color: var(--navy);
            margin-bottom: 6px;
        }

        .property-info .location {
            font-size: 0.8rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 8px;
        }

        .property-info .price {
            font-weight: 700;
            color: var(--gold);
            font-size: 1.2rem;
            margin-bottom: 10px;
        }

        .property-info .features {
            display: flex;
            gap: 18px;
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 8px;
            border-top: 1px solid #eee;
            padding-top: 12px;
        }

        .property-info .features span i {
            margin-right: 4px;
            color: var(--gold);
        }

        .property-actions {
            display: flex;
            gap: 10px;
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px solid #f0f0f0;
        }

        .property-actions .btn-whatsapp {
            flex: 1;
            justify-content: center;
            padding: 10px 16px;
            font-size: 0.8rem;
        }

        .property-actions .btn-outline-gold {
            flex: 1;
            justify-content: center;
            text-align: center;
            padding: 10px 16px;
            font-size: 0.8rem;
        }

        /* ===== PAGINACIÓN ===== */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 50px;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 16px;
            border: 1px solid #ddd;
            background: #fff;
            border-radius: 8px;
            transition: all var(--transition);
            font-family: 'Montserrat', sans-serif;
            font-weight: 500;
            font-size: 0.85rem;
            color: var(--text-dark);
            min-width: 44px;
            cursor: pointer;
        }

        .pagination a:hover {
            border-color: var(--gold);
            color: var(--gold);
        }

        .pagination a.active {
            background: var(--gold);
            color: #fff;
            border-color: var(--gold);
        }

        .pagination a.disabled {
            opacity: 0.4;
            cursor: not-allowed;
            pointer-events: none;
        }

        .pagination .ellipsis {
            border: none;
            background: transparent;
            cursor: default;
        }

        /* ===== WHATSAPP FLOATING BUTTON ===== */
        .whatsapp-float {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 999;
            background: #25D366;
            color: #fff;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            box-shadow: 0 4px 20px rgba(37, 211, 102, 0.4);
            transition: transform var(--transition), box-shadow var(--transition);
            border: none;
            cursor: pointer;
            text-decoration: none;
        }

        .whatsapp-float:hover {
            transform: scale(1.1);
            box-shadow: 0 8px 30px rgba(37, 211, 102, 0.5);
        }

        .whatsapp-float .tooltip {
            position: absolute;
            right: 70px;
            background: var(--navy);
            color: #fff;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 0.75rem;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity var(--transition);
        }

        .whatsapp-float:hover .tooltip {
            opacity: 1;
        }

        /* ===== FOOTER ===== */
        footer {
            background: var(--navy);
            color: #fff;
            padding: 30px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            font-size: 0.8rem;
            border-top: 2px solid rgba(197, 160, 89, 0.2);
        }

        .footer-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            font-size: 1rem;
        }

        .footer-logo .logo-icon {
            width: 32px;
            height: 32px;
            font-size: 0.7rem;
        }

        .footer-contact {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            color: #ccc;
        }

        .footer-contact i {
            color: var(--gold);
            margin-right: 6px;
        }

        .social-links {
            display: flex;
            gap: 16px;
        }

        .social-links a {
            color: #fff;
            font-size: 1.1rem;
            transition: color var(--transition), transform var(--transition);
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.06);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .social-links a:hover {
            color: var(--gold);
            transform: translateY(-3px);
            background: rgba(255, 255, 255, 0.12);
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 992px) {
            .filters-wrapper {
                flex-direction: column;
                align-items: stretch;
            }

            .filters-group {
                justify-content: center;
            }

            .filters-group select,
            .filters-group input {
                min-width: 120px;
            }
        }

        @media (max-width: 768px) {
            header {
                flex-direction: column;
                gap: 12px;
                padding: 12px 5%;
            }

            nav ul {
                gap: 16px;
                flex-wrap: wrap;
                justify-content: center;
            }

            .section-title {
                font-size: 1.8rem;
            }

            .properties-grid {
                grid-template-columns: 1fr;
            }

            .property-img-container {
                height: 200px;
            }

            .filters-group {
                flex-direction: column;
                width: 100%;
            }

            .filters-group select,
            .filters-group input {
                width: 100%;
                min-width: unset;
            }

            .filters-group .btn-outline-gold {
                width: 100%;
                text-align: center;
            }

            .results-count {
                text-align: center;
                width: 100%;
            }

            .whatsapp-float {
                width: 50px;
                height: 50px;
                font-size: 1.6rem;
                bottom: 20px;
                right: 20px;
            }

            .whatsapp-float .tooltip {
                display: none;
            }

            .property-actions {
                flex-direction: column;
            }

            footer {
                flex-direction: column;
                text-align: center;
            }

            .footer-contact {
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .property-info .features {
                flex-wrap: wrap;
                gap: 10px;
            }

            .pagination a,
            .pagination span {
                padding: 8px 12px;
                font-size: 0.8rem;
                min-width: 36px;
            }
        }
    </style>
</head>
<body>

    <header id="header">
        <a href="index.php" class="logo">
            <div class="logo-icon">VT</div>
            <div class="logo-text">
                VERA TERRA
                <span>Inmobiliaria</span>
            </div>
        </a>
        <nav>
            <ul>
                <li><a href="index.php">Inicio</a></li>
                <li><a href="propiedades_portal.php" class="active">Propiedades</a></li>
                <li><a href="nosotros.php">Nosotros</a></li>
                <li><a href="contacto.php">Contacto</a></li>
            </ul>
        </nav>
    </header>

    <!-- ===== FILTROS ===== -->
    <section class="filters-section">
        <div class="container">
            <div class="filters-wrapper">
                <div class="filters-group">
                    <form method="GET" action="" id="filterForm" style="display:flex; flex-wrap:wrap; gap:12px; align-items:center; width:100%;">
                        <select name="tipo" id="filterType">
                            <option value="all" <?php echo $filtro_tipo === 'all' ? 'selected' : ''; ?>>Todos los tipos</option>
                            <option value="venta" <?php echo $filtro_tipo === 'venta' ? 'selected' : ''; ?>>Venta</option>
                            <option value="renta" <?php echo $filtro_tipo === 'renta' ? 'selected' : ''; ?>>Renta</option>
                        </select>
                        <select name="categoria" id="filterCategory">
                            <option value="all" <?php echo $filtro_categoria === 'all' ? 'selected' : ''; ?>>Todas las categorías</option>
                            <?php if (!empty($categorias_disponibles['residencial'])): ?>
                                <option value="residencial" <?php echo $filtro_categoria === 'residencial' ? 'selected' : ''; ?>>Residencial</option>
                            <?php endif; ?>
                            <?php if (!empty($categorias_disponibles['corporativo'])): ?>
                                <option value="corporativo" <?php echo $filtro_categoria === 'corporativo' ? 'selected' : ''; ?>>Corporativo</option>
                            <?php endif; ?>
                            <?php if (!empty($categorias_disponibles['lujo'])): ?>
                                <option value="lujo" <?php echo $filtro_categoria === 'lujo' ? 'selected' : ''; ?>>Lujo</option>
                            <?php endif; ?>
                        </select>
                        <input type="text" name="busqueda" id="filterSearch" placeholder="Buscar por ubicación..." value="<?php echo htmlspecialchars($filtro_busqueda); ?>" />
                        <button type="submit" class="btn-outline-gold"><i class="fa-regular fa-sliders"></i> Filtrar</button>
                        <a href="propiedades_portal.php" class="btn-outline-gold"><i class="fa-regular fa-rotate"></i> Reiniciar</a>
                    </form>
                </div>
                <div class="results-count">
                    Mostrando <span><?php echo count($propiedades); ?></span> de <span><?php echo $total_propiedades; ?></span> propiedades
                </div>
            </div>
        </div>
    </section>

    <!-- ===== PROPIEDADES ===== -->
    <section class="properties-section" id="propertiesSection">
        <div class="container">
            <h2 class="section-title">Nuestras propiedades</h2>
            <p class="section-subtitle">Encuentra la propiedad perfecta para ti, con la asesoría y respaldo que te mereces.</p>

            <div class="properties-grid" id="propertiesGrid">
                <?php if (empty($propiedades)): ?>
                    <div style="grid-column:1/-1; text-align:center; padding:60px 20px; color:var(--text-muted);">
                        <i class="fa-regular fa-house-circle-exclamation" style="font-size:3rem; color:var(--gold); margin-bottom:15px; display:block;"></i>
                        <h3 style="font-size:1.2rem; margin-bottom:10px;">No encontramos propiedades</h3>
                        <p>Intenta ajustar los filtros de búsqueda</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($propiedades as $prop): 
                        $tiene_featuring = tieneFeaturing($prop);
                        $dias_featuring = getDiasRestantesFeaturing($prop);
                        $tipo_clase = getTipoClase($prop['operation_type'] ?? 'venta');
                        $tipo_label = getTipoLabel($prop['operation_type'] ?? 'venta');
                        $icono_categoria = getCategoriaIcon($prop['property_type'] ?? '');
                        $ubicacion = getUbicacionCompleta($prop);
                        $precio = formatearPrecio($prop['price']);
                        $imagen = getImagenUrl($prop['imagen_principal']);
                        $card_class = $tiene_featuring ? 'property-card featured' : 'property-card';
                    ?>
                        <div class="<?php echo $card_class; ?>">
                            <div class="property-img-container">
                                <img src="<?php echo $imagen; ?>" alt="<?php echo htmlspecialchars($prop['title']); ?>" loading="lazy" />
                                <div class="property-badge"><i class="<?php echo $icono_categoria; ?>"></i></div>
                                <div class="property-status <?php echo $tipo_clase; ?>"><?php echo $tipo_label; ?></div>
                                <?php if ($tiene_featuring && $dias_featuring > 0): ?>
                                    <div class="featuring-countdown">
                                        <i class="fa-regular fa-star"></i> <?php echo $dias_featuring; ?> días destacada
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="property-info">
                                <h3><?php echo htmlspecialchars($prop['title']); ?></h3>
                                <div class="location"><i class="fa-regular fa-location-dot"></i> <?php echo htmlspecialchars($ubicacion); ?></div>
                                <div class="price"><?php echo $precio; ?></div>
                                <div class="features">
                                    <?php if (!empty($prop['bedrooms']) && $prop['bedrooms'] > 0): ?>
                                        <span><i class="fa-regular fa-bed"></i> <?php echo $prop['bedrooms']; ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($prop['bathrooms']) && $prop['bathrooms'] > 0): ?>
                                        <span><i class="fa-regular fa-bath"></i> <?php echo $prop['bathrooms']; ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($prop['square_meters']) && $prop['square_meters'] > 0): ?>
                                        <span><i class="fa-regular fa-vector-square"></i> <?php echo number_format($prop['square_meters'], 0, ',', '.'); ?> m²</span>
                                    <?php endif; ?>
                                    <?php if (!empty($prop['parking_spots']) && $prop['parking_spots'] > 0): ?>
                                        <span><i class="fa-regular fa-car"></i> <?php echo $prop['parking_spots']; ?></span>
                                    <?php endif; ?>
                                    <?php if (empty($prop['bedrooms']) && empty($prop['bathrooms']) && empty($prop['square_meters'])): ?>
                                        <span style="color: #999; font-style: italic;">Características no especificadas</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($tiene_featuring): ?>
                                    <div style="margin-top:6px; font-size:0.7rem; color:var(--gold);">
                                        <i class="fa-regular fa-star" style="color:var(--gold);"></i> Propiedad destacada
                                    </div>
                                <?php endif; ?>
                                <div class="property-actions">
                                    <a href="https://wa.me/5213312345678?text=Hola%2C%20me%20interesa%20la%20propiedad%3A%20<?php echo urlencode($prop['title']); ?>%20en%20<?php echo urlencode($ubicacion); ?>%20con%20precio%20<?php echo urlencode($precio); ?>" 
                                       target="_blank" 
                                       class="btn-whatsapp">
                                        <i class="fa-brands fa-whatsapp"></i> Consultar
                                    </a>
                                    <a href="propiedad_detalle_portal.php?id=<?php echo $prop['id']; ?>" class="btn-outline-gold">Ver más</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- ===== PAGINACIÓN ===== -->
            <?php if ($total_paginas > 1): ?>
                <div class="pagination" id="pagination">
                    <?php if ($pagina_actual > 1): ?>
                        <a href="?page=<?php echo $pagina_actual - 1; ?>&tipo=<?php echo urlencode($filtro_tipo); ?>&categoria=<?php echo urlencode($filtro_categoria); ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>">
                            <i class="fa-regular fa-chevron-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled"><i class="fa-regular fa-chevron-left"></i></span>
                    <?php endif; ?>

                    <?php
                    $rango = 2;
                    $inicio = max(1, $pagina_actual - $rango);
                    $fin = min($total_paginas, $pagina_actual + $rango);

                    if ($inicio > 1): ?>
                        <a href="?page=1&tipo=<?php echo urlencode($filtro_tipo); ?>&categoria=<?php echo urlencode($filtro_categoria); ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>">1</a>
                        <?php if ($inicio > 2): ?>
                            <span class="ellipsis">…</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $inicio; $i <= $fin; $i++): ?>
                        <a href="?page=<?php echo $i; ?>&tipo=<?php echo urlencode($filtro_tipo); ?>&categoria=<?php echo urlencode($filtro_categoria); ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>" 
                           class="<?php echo $i === $pagina_actual ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($fin < $total_paginas): ?>
                        <?php if ($fin < $total_paginas - 1): ?>
                            <span class="ellipsis">…</span>
                        <?php endif; ?>
                        <a href="?page=<?php echo $total_paginas; ?>&tipo=<?php echo urlencode($filtro_tipo); ?>&categoria=<?php echo urlencode($filtro_categoria); ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>">
                            <?php echo $total_paginas; ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($pagina_actual < $total_paginas): ?>
                        <a href="?page=<?php echo $pagina_actual + 1; ?>&tipo=<?php echo urlencode($filtro_tipo); ?>&categoria=<?php echo urlencode($filtro_categoria); ?>&busqueda=<?php echo urlencode($filtro_busqueda); ?>">
                            <i class="fa-regular fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled"><i class="fa-regular fa-chevron-right"></i></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- ===== WHATSAPP FLOATING BUTTON ===== -->
    <a href="https://wa.me/5213312345678?text=Hola%2C%20estoy%20interesado%20en%20una%20propiedad%20de%20Vera%20Terra" 
       target="_blank" 
       class="whatsapp-float" 
       aria-label="Contactar por WhatsApp">
        <i class="fa-brands fa-whatsapp"></i>
        <span class="tooltip">¡Escríbenos!</span>
    </a>

    <?php include 'footer.php'; ?>

    <script>
        // ============================================================
        //  SCROLL PARA NAVBAR
        // ============================================================
        document.addEventListener('DOMContentLoaded', function() {
            const header = document.getElementById('header');
            window.addEventListener('scroll', function() {
                if (window.scrollY > 30) {
                    header.classList.add('scrolled');
                } else {
                    header.classList.remove('scrolled');
                }
            });
        });
    </script>
</body>
</html>