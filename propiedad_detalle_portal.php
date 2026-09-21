<?php
// ============================================
// propiedad_detalle_portal.php
// Detalle público de propiedad - Vera Terra Inmobiliaria
// ============================================

session_start();
require_once 'includes/conexion.php';

// ===== OBTENER ID =====
$property_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($property_id <= 0) {
    header('Location: propiedades.php');
    exit;
}

// ===== FUNCIONES AUXILIARES =====
function formatearPrecio($precio) {
    if ($precio === null || $precio == 0) return 'Consultar precio';
    return '$' . number_format(floatval($precio), 0, ',', '.');
}

function getImagenUrl($imagen) {
    if (empty($imagen)) {
        return 'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?auto=format&fit=crop&w=1200&q=80';
    }
    if (strpos($imagen, 'uploads/') === 0) return htmlspecialchars($imagen);
    return 'uploads/propiedades/' . htmlspecialchars($imagen);
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

function getUbicacionCompleta($propiedad) {
    $parts = [];
    if (!empty($propiedad['address_municipality'])) $parts[] = $propiedad['address_municipality'];
    if (!empty($propiedad['address_city'])) $parts[] = $propiedad['address_city'];
    return !empty($parts) ? implode(', ', $parts) : 'Ubicación no especificada';
}

// ===== OBTENER PROPIEDAD =====
$propiedad = null;
try {
    $stmt = $conn->prepare("
        SELECT 
            p.id, p.title, p.operation_type, p.property_type, p.status,
            p.address_city, p.address_municipality, p.created_at,
            pd.square_meters, pd.bedrooms, pd.bathrooms, pd.parking_spots,
            pd.description,
            pf.asking_price as price
        FROM properties p
        LEFT JOIN property_details pd ON p.id = pd.property_id
        LEFT JOIN property_financials pf ON p.id = pf.property_id
        WHERE p.id = ? AND p.status IN ('activo', 'apartada')
        LIMIT 1
    ");
    $stmt->execute([$property_id]);
    $propiedad = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error detalle propiedad: " . $e->getMessage());
}

if (!$propiedad) {
    header('Location: propiedades.php');
    exit;
}

// ===== OBTENER GALERÍA =====
$imagenes = [];
try {
    $stmt = $conn->prepare("
        SELECT file_path, is_primary, sort_order
        FROM property_media
        WHERE property_id = ?
        ORDER BY is_primary DESC, sort_order ASC
    ");
    $stmt->execute([$property_id]);
    $imagenes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $imagenes = [];
}

$imagen_principal = !empty($imagenes) ? $imagenes[0]['file_path'] : '';

// ===== DECODIFICAR AMENIDADES =====
$amenidades = [];
if (!empty($propiedad['amenities'])) {
    $decoded = json_decode($propiedad['amenities'], true);
    if (is_array($decoded)) $amenidades = $decoded;
}

// ===== PROPIEDADES RELACIONADAS (misma ciudad / tipo) =====
$relacionadas = [];
try {
    $stmt = $conn->prepare("
        SELECT 
            p.id, p.title, p.operation_type, p.address_city, p.address_municipality,
            p.property_type,
            pd.square_meters, pd.bedrooms, pd.bathrooms, pd.parking_spots,
            pf.asking_price as price,
            (SELECT file_path FROM property_media 
             WHERE property_id = p.id AND is_primary = 1 
             ORDER BY sort_order ASC LIMIT 1) as imagen_principal
        FROM properties p
        LEFT JOIN property_details pd ON p.id = pd.property_id
        LEFT JOIN property_financials pf ON p.id = pf.property_id
        WHERE p.status = 'activo' 
          AND p.id != ?
          AND (p.operation_type = ? OR p.address_city = ?)
        ORDER BY RAND()
        LIMIT 3
    ");
    $stmt->execute([
        $property_id, 
        $propiedad['operation_type'] ?? 'venta',
        $propiedad['address_city'] ?? ''
    ]);
    $relacionadas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $relacionadas = [];
}

$badge_op = getTipoLabel($propiedad['operation_type'] ?? 'venta');
$tipo_clase = getTipoClase($propiedad['operation_type'] ?? 'venta');
$icono_categoria = getCategoriaIcon($propiedad['property_type'] ?? '');
$ubicacion = getUbicacionCompleta($propiedad);
$precio = formatearPrecio($propiedad['price']);
$esta_apartada = strtolower($propiedad['status']) === 'apartada';

// Datos para WhatsApp
$whatsapp_numero = '523311586937';
$whatsapp_texto = urlencode('Hola, me interesa la propiedad: ' . $propiedad['title'] . ' (ID: ' . $property_id . ') en ' . $ubicacion . ' con precio ' . $precio);
$whatsapp_url = "https://wa.me/{$whatsapp_numero}?text={$whatsapp_texto}";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo htmlspecialchars($propiedad['title']); ?> | Vera Terra Inmobiliaria</title>
    <meta name="description" content="<?php echo htmlspecialchars(mb_substr($propiedad['description'] ?? $propiedad['title'], 0, 160)); ?>" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet" />
    <style>
        /* ===== RESET & ROOT ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; }

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

        html { scroll-behavior: smooth; }

        body {
            font-family: 'Montserrat', sans-serif;
            background: #ffffff;
            color: var(--text-dark);
            line-height: 1.6;
        }

        a { text-decoration: none; color: inherit; }
        img { max-width: 100%; display: block; }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* ===== BOTONES ===== */
        .btn-gold {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: var(--gold);
            color: #fff;
            padding: 14px 32px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.9rem;
            border: none;
            cursor: pointer;
            transition: all var(--transition);
            box-shadow: 0 4px 14px rgba(197, 160, 89, 0.3);
            letter-spacing: 0.3px;
        }

        .btn-gold:hover {
            background: var(--gold-hover);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(197, 160, 89, 0.4);
        }

        .btn-outline-gold {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: transparent;
            color: var(--gold);
            padding: 12px 28px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.9rem;
            border: 2px solid var(--gold);
            transition: all var(--transition);
            cursor: pointer;
        }

        .btn-outline-gold:hover {
            background: var(--gold);
            color: #fff;
        }

        .btn-whatsapp {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: #25D366;
            color: #fff;
            padding: 14px 32px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.9rem;
            border: none;
            cursor: pointer;
            transition: all var(--transition);
            box-shadow: 0 4px 14px rgba(37, 211, 102, 0.3);
        }

        .btn-whatsapp:hover {
            background: #1da85a;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(37, 211, 102, 0.4);
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

        header.scrolled { box-shadow: 0 4px 30px rgba(0, 0, 0, 0.08); }

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
        nav a.active::after { width: 100%; }

        nav a:hover,
        nav a.active { color: var(--gold); }

        /* ===== BREADCRUMB ===== */
        .breadcrumb-bar {
            background: var(--light-bg);
            padding: 16px 5%;
            border-bottom: 1px solid #eee;
        }

        .breadcrumb {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8rem;
            color: var(--text-muted);
            flex-wrap: wrap;
        }

        .breadcrumb a {
            color: var(--text-muted);
            transition: color var(--transition);
        }

        .breadcrumb a:hover { color: var(--gold); }

        .breadcrumb .separator { color: #ccc; }

        .breadcrumb .current {
            color: var(--navy);
            font-weight: 600;
        }

        /* ===== ALERTA APARTADO ===== */
        .alert-apartada {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border-left: 4px solid #f59e0b;
            border-radius: 8px;
            padding: 16px 20px;
            margin: 20px 0;
            display: flex;
            align-items: center;
            gap: 14px;
            color: #92400e;
            font-size: 0.9rem;
        }

        .alert-apartada i {
            font-size: 1.5rem;
            color: #f59e0b;
            flex-shrink: 0;
        }

        .alert-apartada strong { display: block; margin-bottom: 2px; }

        /* ===== LAYOUT DETALLE ===== */
        .detail-section {
            padding: 30px 5% 60px;
        }

        .detail-wrapper {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* ===== GALERÍA ESTILO AIRBNB ===== */
        .gallery-wrapper {
            display: grid;
            grid-template-columns: 1fr 130px;
            gap: 10px;
            margin-bottom: 30px;
            height: 520px;
        }

        /* --- Imagen principal --- */
        .gallery-main {
            position: relative;
            background: #eee;
            overflow: hidden;
            border-radius: var(--radius);
            cursor: zoom-in;
            height: 100%;
        }

        .gallery-main img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            display: block;
            transition: transform 0.5s ease, opacity 0.3s ease;
        }

        .gallery-main:hover img { transform: scale(1.02); }

        /* Badges sobre la imagen principal */
        .gallery-main .badge-operation {
            position: absolute;
            top: 20px;
            left: 20px;
            padding: 8px 20px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            z-index: 5;
            box-shadow: 0 4px 14px rgba(0,0,0,0.2);
        }

        .badge-operation.venta { background: var(--gold); color: var(--navy); }
        .badge-operation.renta { background: #2e7d5e; color: #fff; }

        .gallery-main .badge-status-apartada {
            position: absolute;
            top: 20px;
            right: 20px;
            padding: 8px 18px;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            background: #f59e0b;
            color: #fff;
            z-index: 5;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Contador de fotos */
        .gallery-counter {
            position: absolute;
            bottom: 20px;
            right: 20px;
            background: rgba(11, 31, 58, 0.85);
            color: #fff;
            padding: 6px 14px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            z-index: 5;
            display: flex;
            align-items: center;
            gap: 6px;
            backdrop-filter: blur(6px);
        }

        .gallery-counter i { color: var(--gold); }

        /* Flechas prev/next sobre la imagen principal */
        .gallery-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.92);
            color: var(--navy);
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            transition: all var(--transition);
            z-index: 5;
            box-shadow: 0 4px 14px rgba(0,0,0,0.15);
        }

        .gallery-nav:hover {
            background: var(--gold);
            color: #fff;
            transform: translateY(-50%) scale(1.08);
        }

        .gallery-nav.prev { left: 20px; }
        .gallery-nav.next { right: 20px; }

        /* --- Tira lateral de miniaturas --- */
        .gallery-thumbs {
            display: flex;
            flex-direction: column;
            gap: 8px;
            height: 100%;
            overflow-y: auto;
            overflow-x: hidden;
            scroll-behavior: smooth;
            padding-right: 4px;
        }

        /* Scrollbar personalizada */
        .gallery-thumbs::-webkit-scrollbar {
            width: 6px;
        }

        .gallery-thumbs::-webkit-scrollbar-track {
            background: #f0f0f0;
            border-radius: 3px;
        }

        .gallery-thumbs::-webkit-scrollbar-thumb {
            background: var(--gold);
            border-radius: 3px;
        }

        .gallery-thumbs::-webkit-scrollbar-thumb:hover {
            background: var(--gold-hover);
        }

        .gallery-thumb {
            position: relative;
            background: #eee;
            border-radius: 8px;
            overflow: hidden;
            cursor: pointer;
            flex-shrink: 0;
            aspect-ratio: 4 / 3;
            border: 3px solid transparent;
            transition: all var(--transition);
        }

        .gallery-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            display: block;
            transition: transform 0.4s ease, opacity 0.3s ease;
        }

        .gallery-thumb:hover img {
            transform: scale(1.08);
            opacity: 0.9;
        }

        .gallery-thumb.active {
            border-color: var(--gold);
            box-shadow: 0 0 0 2px rgba(197, 160, 89, 0.3);
        }

        .gallery-thumb.active::after {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(197, 160, 89, 0.15);
            pointer-events: none;
        }

        .gallery-thumb .thumb-overlay {
            position: absolute;
            inset: 0;
            background: rgba(11, 31, 58, 0.75);
            color: var(--gold);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: 700;
            gap: 2px;
        }

        .gallery-thumb .thumb-overlay span {
            font-size: 0.65rem;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            color: #fff;
        }

        /* ===== LIGHTBOX (vista pantalla completa) ===== */
        .lightbox {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(11, 31, 58, 0.96);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 40px;
            animation: fadeIn 0.3s ease;
        }

        .lightbox.active { display: flex; }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .lightbox-img-wrap {
            position: relative;
            max-width: 90vw;
            max-height: 85vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .lightbox-img-wrap img {
            max-width: 90vw;
            max-height: 85vh;
            object-fit: contain;
            border-radius: 6px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }

        .lightbox-close {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
            border: none;
            cursor: pointer;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition);
            backdrop-filter: blur(8px);
            z-index: 10;
        }

        .lightbox-close:hover {
            background: var(--gold);
            transform: rotate(90deg);
        }

        .lightbox-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
            border: none;
            cursor: pointer;
            font-size: 1.4rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition);
            backdrop-filter: blur(8px);
            z-index: 10;
        }

        .lightbox-nav:hover {
            background: var(--gold);
            color: var(--navy);
        }

        .lightbox-nav.prev { left: 20px; }
        .lightbox-nav.next { right: 20px; }

        .lightbox-counter {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
            padding: 8px 20px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 1px;
            backdrop-filter: blur(8px);
        }

        /* ===== LAYOUT INFO + SIDEBAR ===== */
        .content-layout {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 40px;
            align-items: start;
        }

        /* ===== INFO PRINCIPAL ===== */
        .property-header {
            margin-bottom: 28px;
            padding-bottom: 24px;
            border-bottom: 2px solid var(--gold-light);
        }

        .property-header .category-line {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--gold);
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 12px;
        }

        .property-header .category-line i { font-size: 1rem; }

        .property-header h1 {
            font-family: 'Playfair Display', serif;
            font-size: 2.2rem;
            color: var(--navy);
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 12px;
            letter-spacing: -0.5px;
        }

        .property-header .location-line {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-muted);
            font-size: 0.95rem;
            margin-bottom: 18px;
        }

        .property-header .location-line i { color: var(--gold); }

        .property-header .price-line {
            display: flex;
            align-items: baseline;
            gap: 12px;
            flex-wrap: wrap;
        }

        .property-header .price {
            font-family: 'Playfair Display', serif;
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--navy);
            line-height: 1;
        }

        .property-header .price-note {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-style: italic;
        }

        /* ===== FEATURES GRID ===== */
        .features-panel {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0;
            background: var(--light-bg);
            border-radius: var(--radius);
            margin-bottom: 32px;
            border: 1px solid rgba(197, 160, 89, 0.15);
            overflow: hidden;
        }

        .feature-cell {
            text-align: center;
            padding: 22px 12px;
            border-right: 1px solid rgba(197, 160, 89, 0.15);
            transition: background var(--transition);
        }

        .feature-cell:last-child { border-right: none; }

        .feature-cell:hover { background: rgba(197, 160, 89, 0.06); }

        .feature-cell i {
            font-size: 1.5rem;
            color: var(--gold);
            margin-bottom: 10px;
            display: block;
        }

        .feature-cell .value {
            font-family: 'Playfair Display', serif;
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--navy);
            display: block;
            line-height: 1;
        }

        .feature-cell .label {
            font-size: 0.7rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 6px;
            display: block;
        }

        /* ===== SECCIONES ===== */
        .info-block {
            margin-bottom: 36px;
        }

        .info-block h2 {
            font-family: 'Playfair Display', serif;
            font-size: 1.4rem;
            color: var(--navy);
            font-weight: 700;
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--gold-light);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-block h2 i {
            color: var(--gold);
            font-size: 1.1rem;
        }

        .info-block .description {
            color: var(--text-muted);
            font-size: 0.95rem;
            line-height: 1.8;
            white-space: pre-line;
        }

        /* ===== AMENIDADES ===== */
        .amenities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
        }

        .amenity-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: var(--light-bg);
            border-radius: 8px;
            font-size: 0.85rem;
            color: var(--navy);
            border-left: 3px solid var(--gold);
            transition: transform var(--transition);
        }

        .amenity-item:hover { transform: translateX(4px); }

        .amenity-item i {
            color: var(--gold);
            font-size: 1rem;
            width: 18px;
            text-align: center;
        }

        /* ===== DETALLES EXTRA (grid 2 columnas) ===== */
        .extra-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 16px;
            background: var(--light-bg);
            border-radius: 8px;
            font-size: 0.85rem;
        }

        .detail-row .label {
            color: var(--text-muted);
        }

        .detail-row .value {
            color: var(--navy);
            font-weight: 600;
        }

        /* ===== SIDEBAR CONTACTO ===== */
        .sidebar {
            position: sticky;
            top: 100px;
        }

        .contact-card {
            background: #fff;
            border: 1px solid rgba(197, 160, 89, 0.2);
            border-radius: var(--radius);
            padding: 28px;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .contact-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--gold), var(--navy));
        }

        .contact-card .agent-header {
            text-align: center;
            padding-bottom: 20px;
            border-bottom: 1px solid #f0f0f0;
            margin-bottom: 22px;
        }

        .contact-card .agent-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: linear-gradient(145deg, var(--navy), #1a3552);
            color: var(--gold);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin: 0 auto 12px;
            border: 3px solid var(--gold-light);
        }

        .contact-card .agent-name {
            font-family: 'Playfair Display', serif;
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--navy);
            margin-bottom: 2px;
        }

        .contact-card .agent-role {
            font-size: 0.75rem;
            color: var(--gold);
            text-transform: uppercase;
            letter-spacing: 2px;
            font-weight: 600;
        }

        .contact-card h3 {
            font-size: 1rem;
            color: var(--navy);
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .contact-card h3 i { color: var(--gold); }

        .contact-form .form-group {
            margin-bottom: 14px;
        }

        .contact-form label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--navy);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .contact-form input,
        .contact-form textarea {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.88rem;
            transition: border-color var(--transition);
            background: #fafafa;
        }

        .contact-form input:focus,
        .contact-form textarea:focus {
            border-color: var(--gold);
            outline: none;
            background: #fff;
        }

        .contact-form textarea { resize: vertical; min-height: 80px; }

        .contact-form .btn-submit {
            width: 100%;
            padding: 14px;
            background: var(--navy);
            color: #fff;
            border: none;
            border-radius: 50px;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            letter-spacing: 0.5px;
        }

        .contact-form .btn-submit:hover {
            background: #1a3552;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(11, 31, 58, 0.2);
        }

        .contact-card .divider {
            text-align: center;
            margin: 16px 0;
            position: relative;
        }

        .contact-card .divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: #f0f0f0;
        }

        .contact-card .divider span {
            background: #fff;
            padding: 0 12px;
            position: relative;
            color: var(--text-muted);
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .contact-card .btn-whatsapp-full {
            width: 100%;
            padding: 13px;
            background: #25D366;
            color: #fff;
            border: none;
            border-radius: 50px;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
        }

        .contact-card .btn-whatsapp-full:hover {
            background: #1da85a;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(37, 211, 102, 0.3);
        }

        .contact-card .privacy-note {
            font-size: 0.7rem;
            color: #94a3b8;
            text-align: center;
            margin-top: 14px;
            line-height: 1.5;
        }

        .contact-card .privacy-note i { color: var(--gold); }

        .contact-card .ref-line {
            text-align: center;
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #f0f0f0;
            letter-spacing: 1px;
            text-transform: uppercase;
            font-weight: 500;
        }

        /* ===== PROPIEDADES RELACIONADAS ===== */
        .related-section {
            background: var(--light-bg);
            padding: 60px 5%;
            border-top: 1px solid #eee;
        }

        .related-section .container {
            max-width: 1200px;
        }

        .related-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .related-header h2 {
            font-family: 'Playfair Display', serif;
            font-size: 2rem;
            color: var(--navy);
            font-weight: 700;
            margin-bottom: 8px;
        }

        .related-header p {
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        .related-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 30px;
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
        }

        .property-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 15px 40px rgba(11, 31, 58, 0.12);
        }

        .property-card .property-img-container {
            position: relative;
            aspect-ratio: 16 / 10;
            overflow: hidden;
            background: #eee;
        }

        .property-card .property-img-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            display: block;
            transition: transform 0.5s ease;
        }

        .property-card:hover .property-img-container img { transform: scale(1.05); }

        .property-card .property-status {
            position: absolute;
            bottom: 14px;
            left: 14px;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            z-index: 5;
        }

        .property-card .property-status.venta { background: var(--gold); color: var(--navy); }
        .property-card .property-status.renta { background: #2e7d5e; color: #fff; }

        .property-card .property-info {
            padding: 20px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .property-card .property-info h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--navy);
            margin-bottom: 6px;
        }

        .property-card .property-info .location {
            font-size: 0.8rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 10px;
        }

        .property-card .property-info .price {
            font-weight: 700;
            color: var(--gold);
            font-size: 1.15rem;
            margin-bottom: 12px;
        }

        .property-card .property-info .features {
            display: flex;
            gap: 16px;
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: auto;
            padding-top: 12px;
            border-top: 1px solid #eee;
        }

        .property-card .property-info .features span i {
            margin-right: 4px;
            color: var(--gold);
        }

        /* ===== WHATSAPP FLOAT ===== */
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

        .whatsapp-float:hover .tooltip { opacity: 1; }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 992px) {
            .content-layout {
                grid-template-columns: 1fr;
                gap: 30px;
            }

            .sidebar { position: static; }

            /* Galería responsive: imagen arriba, thumbs abajo */
            .gallery-wrapper {
                grid-template-columns: 1fr;
                grid-template-rows: auto auto;
                height: auto;
            }

            .gallery-main {
                aspect-ratio: 16 / 10;
                height: auto;
            }

            .gallery-thumbs {
                flex-direction: row;
                height: auto;
                overflow-x: auto;
                overflow-y: hidden;
                padding-bottom: 6px;
            }

            .gallery-thumb {
                min-width: 100px;
                width: 100px;
                aspect-ratio: 4 / 3;
                flex-shrink: 0;
            }

            .gallery-nav {
                width: 38px;
                height: 38px;
                font-size: 0.9rem;
            }

            .gallery-nav.prev { left: 12px; }
            .gallery-nav.next { right: 12px; }

            .lightbox { padding: 20px; }
            .lightbox-nav { width: 44px; height: 44px; font-size: 1.1rem; }
            .lightbox-nav.prev { left: 10px; }
            .lightbox-nav.next { right: 10px; }
            .lightbox-close { width: 40px; height: 40px; top: 12px; right: 12px; }
        }

        @media (max-width: 768px) {
            header {
                flex-direction: column;
                gap: 12px;
                padding: 12px 5%;
            }

            nav ul { gap: 16px; flex-wrap: wrap; justify-content: center; }

            .property-header h1 { font-size: 1.5rem; }
            .property-header .price { font-size: 1.6rem; }

            .features-panel {
                grid-template-columns: repeat(2, 1fr);
            }

            .feature-cell:nth-child(2) { border-right: none; }
            .feature-cell:nth-child(1),
            .feature-cell:nth-child(2) { border-bottom: 1px solid rgba(197, 160, 89, 0.15); }

            .extra-details { grid-template-columns: 1fr; }

            .amenities-grid { grid-template-columns: 1fr; }

            .related-grid { grid-template-columns: 1fr; }

            .whatsapp-float {
                width: 50px;
                height: 50px;
                font-size: 1.6rem;
                bottom: 20px;
                right: 20px;
            }

            .whatsapp-float .tooltip { display: none; }
        }

        @media (max-width: 480px) {
            .detail-section { padding: 20px 5% 40px; }

            .contact-card { padding: 22px 18px; }

            .gallery-thumb {
                min-width: 80px;
                width: 80px;
            }
        }
    </style>
</head>
<body>

    <?php include 'modulos/navbar.php'; ?>

    <!-- ===== BREADCRUMB ===== -->
    <div class="breadcrumb-bar">
        <div class="breadcrumb">
            <a href="propiedades.php"><i class="fa-solid fa-house"></i> Inicio</a>
            <span class="separator">/</span>
            <a href="propiedades.php?tipo=<?php echo urlencode($propiedad['operation_type'] ?? ''); ?>">
                <?php echo htmlspecialchars(ucfirst($propiedad['operation_type'] ?? 'Propiedades')); ?>
            </a>
            <span class="separator">/</span>
            <span class="current"><?php echo htmlspecialchars($propiedad['title']); ?></span>
        </div>
    </div>

    <!-- ===== DETALLE ===== -->
    <section class="detail-section">
        <div class="detail-wrapper">

            <!-- Alerta de apartado -->
            <?php if ($esta_apartada): ?>
                <div class="alert-apartada">
                    <i class="fa-solid fa-lock"></i>
                    <div>
                        <strong>Propiedad apartada</strong>
                        Esta propiedad está en proceso de negociación. Contáctanos para más información.
                    </div>
                </div>
            <?php endif; ?>

            <!-- ===== GALERÍA ===== -->
            <?php 
            $total_imagenes = count($imagenes);
            $imagenes_json = [];
            foreach ($imagenes as $img) {
                $imagenes_json[] = getImagenUrl($img['file_path']);
            }
            ?>
            
            <?php if ($total_imagenes > 0): ?>
                <div class="gallery-wrapper" id="galleryWrapper">
                    <!-- Imagen principal -->
                    <div class="gallery-main" onclick="abrirLightbox()">
                        <span class="badge-operation <?php echo $tipo_clase; ?>">
                            <?php echo $badge_op; ?>
                        </span>
                        <?php if ($esta_apartada): ?>
                            <span class="badge-status-apartada">
                                <i class="fa-solid fa-lock"></i> Apartada
                            </span>
                        <?php endif; ?>

                        <img src="<?php echo getImagenUrl($imagen_principal); ?>" 
                             alt="<?php echo htmlspecialchars($propiedad['title']); ?>"
                             id="mainImage">

                        <!-- Flechas de navegación -->
                        <?php if ($total_imagenes > 1): ?>
                            <button class="gallery-nav prev" onclick="event.stopPropagation(); navGaleria(-1)" aria-label="Anterior">
                                <i class="fa-solid fa-chevron-left"></i>
                            </button>
                            <button class="gallery-nav next" onclick="event.stopPropagation(); navGaleria(1)" aria-label="Siguiente">
                                <i class="fa-solid fa-chevron-right"></i>
                            </button>
                        <?php endif; ?>

                        <!-- Contador -->
                        <div class="gallery-counter">
                            <i class="fa-solid fa-images"></i>
                            <span id="counterActual">1</span> / <?php echo $total_imagenes; ?>
                        </div>
                    </div>

                    <!-- Tira lateral de miniaturas (TODAS las fotos) -->
                    <?php if ($total_imagenes > 1): ?>
                        <div class="gallery-thumbs" id="galleryThumbs">
                            <?php foreach ($imagenes as $index => $img): ?>
                                <div class="gallery-thumb <?php echo $index === 0 ? 'active' : ''; ?>" 
                                     data-index="<?php echo $index; ?>"
                                     onclick="seleccionarImagen(<?php echo $index; ?>)">
                                    <img src="<?php echo getImagenUrl($img['file_path']); ?>" 
                                         alt="Imagen <?php echo $index + 1; ?>"
                                         loading="lazy">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- ===== LIGHTBOX ===== -->
            <div class="lightbox" id="lightbox" onclick="cerrarLightbox(event)">
                <button class="lightbox-close" onclick="cerrarLightbox(event)" aria-label="Cerrar">
                    <i class="fa-solid fa-xmark"></i>
                </button>
                <button class="lightbox-nav prev" onclick="event.stopPropagation(); navLightbox(-1)" aria-label="Anterior">
                    <i class="fa-solid fa-chevron-left"></i>
                </button>
                <div class="lightbox-img-wrap" onclick="event.stopPropagation()">
                    <img src="" alt="Vista ampliada" id="lightboxImg">
                </div>
                <button class="lightbox-nav next" onclick="event.stopPropagation(); navLightbox(1)" aria-label="Siguiente">
                    <i class="fa-solid fa-chevron-right"></i>
                </button>
                <div class="lightbox-counter">
                    <span id="lightboxActual">1</span> / <?php echo $total_imagenes; ?>
                </div>
            </div>

            <!-- ===== LAYOUT PRINCIPAL ===== -->
            <div class="content-layout">

                <!-- ===== COLUMNA IZQUIERDA ===== -->
                <div class="main-info">
                    <div class="property-header">
                        <div class="category-line">
                            <i class="<?php echo $icono_categoria; ?>"></i>
                            <?php echo htmlspecialchars($propiedad['property_type'] ?? 'Propiedad'); ?>
                        </div>

                        <h1><?php echo htmlspecialchars($propiedad['title']); ?></h1>

                        <div class="location-line">
                            <i class="fa-solid fa-location-dot"></i>
                            <?php echo htmlspecialchars($ubicacion); ?>
                        </div>

                        <div class="price-line">
                            <span class="price"><?php echo $precio; ?></span>
                            <?php if (strtolower($propiedad['operation_type']) === 'venta'): ?>
                                <span class="price-note">MXN · Precio sujeto a cambios</span>
                            <?php else: ?>
                                <span class="price-note">MXN · Precio mensual</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Features -->
                    <div class="features-panel">
                        <div class="feature-cell">
                            <i class="fa-solid fa-vector-square"></i>
                            <span class="value"><?php echo number_format($propiedad['square_meters'] ?? 0, 0, ',', '.'); ?></span>
                            <span class="label">m²</span>
                        </div>
                        <div class="feature-cell">
                            <i class="fa-solid fa-bed"></i>
                            <span class="value"><?php echo $propiedad['bedrooms'] ?? '—'; ?></span>
                            <span class="label">Recámaras</span>
                        </div>
                        <div class="feature-cell">
                            <i class="fa-solid fa-bath"></i>
                            <span class="value"><?php echo $propiedad['bathrooms'] ?? '—'; ?></span>
                            <span class="label">Baños</span>
                        </div>
                        <div class="feature-cell">
                            <i class="fa-solid fa-car"></i>
                            <span class="value"><?php echo $propiedad['parking_spots'] ?? '—'; ?></span>
                            <span class="label">Estacionamientos</span>
                        </div>
                    </div>

                    <!-- Descripción -->
                    <?php if (!empty($propiedad['description'])): ?>
                        <div class="info-block">
                            <h2><i class="fa-solid fa-align-left"></i> Descripción</h2>
                            <div class="description">
                                <?php echo nl2br(htmlspecialchars($propiedad['description'])); ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Amenidades -->
                    <?php if (!empty($amenidades)): ?>
                        <div class="info-block">
                            <h2><i class="fa-solid fa-star"></i> Amenidades</h2>
                            <div class="amenities-grid">
                                <?php foreach ($amenidades as $am): 
                                    $nombre_am = is_array($am) ? ($am['name'] ?? '') : $am;
                                    if (empty($nombre_am)) continue;
                                ?>
                                    <div class="amenity-item">
                                        <i class="fa-solid fa-check"></i>
                                        <?php echo htmlspecialchars($nombre_am); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Detalles adicionales -->
                    <div class="info-block">
                        <h2><i class="fa-solid fa-circle-info"></i> Detalles de la propiedad</h2>
                        <div class="extra-details">
                            <div class="detail-row">
                                <span class="label">Tipo de operación</span>
                                <span class="value"><?php echo htmlspecialchars($badge_op); ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="label">Tipo de propiedad</span>
                                <span class="value"><?php echo htmlspecialchars($propiedad['property_type'] ?? 'No especificado'); ?></span>
                            </div>
                            <?php if (!empty($propiedad['address_street'])): ?>
                                <div class="detail-row">
                                    <span class="label">Calle</span>
                                    <span class="value"><?php echo htmlspecialchars($propiedad['address_street']); ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="detail-row">
                                <span class="label">Referencia</span>
                                <span class="value">VT-<?php echo str_pad($property_id, 5, '0', STR_PAD_LEFT); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== SIDEBAR CONTACTO ===== -->
                <aside class="sidebar">
                    <div class="contact-card">
                        <div class="agent-header">
                            <div class="agent-avatar">
                                <i class="fa-solid fa-user-tie"></i>
                            </div>
                            <div class="agent-name">Asesor Vera Terra</div>
                            <div class="agent-role">Atención Personalizada</div>
                        </div>

                        <h3><i class="fa-solid fa-envelope"></i> Solicita información</h3>

                        <form class="contact-form" method="POST" action="procesar_contacto.php">
                            <input type="hidden" name="property_id" value="<?php echo $property_id; ?>">
                            <input type="hidden" name="property_title" value="<?php echo htmlspecialchars($propiedad['title']); ?>">

                            <div class="form-group">
                                <label>Nombre completo</label>
                                <input type="text" name="nombre" required placeholder="Tu nombre">
                            </div>

                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="email" required placeholder="tu@email.com">
                            </div>

                            <div class="form-group">
                                <label>Teléfono</label>
                                <input type="tel" name="telefono" placeholder="Tu teléfono">
                            </div>

                            <div class="form-group">
                                <label>Mensaje</label>
                                <textarea name="mensaje" rows="3">Hola, me interesa la propiedad "<?php echo htmlspecialchars($propiedad['title']); ?>". ¿Podrían darme más información?</textarea>
                            </div>

                            <button type="submit" class="btn-submit">
                                <i class="fa-solid fa-paper-plane"></i> Enviar Mensaje
                            </button>
                        </form>

                        <div class="divider"><span>o</span></div>

                        <a href="<?php echo $whatsapp_url; ?>" target="_blank" class="btn-whatsapp-full">
                            <i class="fa-brands fa-whatsapp"></i> Contactar por WhatsApp
                        </a>

                        <div class="privacy-note">
                            <i class="fa-solid fa-shield-alt"></i> 
                            Tus datos están protegidos. No compartimos tu información con terceros.
                        </div>

                        <div class="ref-line">
                            Ref: VT-<?php echo str_pad($property_id, 5, '0', STR_PAD_LEFT); ?>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </section>

    <!-- ===== PROPIEDADES RELACIONADAS ===== -->
    <?php if (!empty($relacionadas)): ?>
        <section class="related-section">
            <div class="container">
                <div class="related-header">
                    <h2>Propiedades similares</h2>
                    <p>Otras opciones que podrían interesarte</p>
                </div>

                <div class="related-grid">
                    <?php foreach ($relacionadas as $rel): 
                        $rel_tipo_clase = getTipoClase($rel['operation_type'] ?? 'venta');
                        $rel_tipo_label = getTipoLabel($rel['operation_type'] ?? 'venta');
                        $rel_ubicacion = getUbicacionCompleta($rel);
                        $rel_precio = formatearPrecio($rel['price']);
                    ?>
                        <div class="property-card">
                            <div class="property-img-container">
                                <img src="<?php echo getImagenUrl($rel['imagen_principal']); ?>" 
                                     alt="<?php echo htmlspecialchars($rel['title']); ?>" loading="lazy">
                                <div class="property-status <?php echo $rel_tipo_clase; ?>">
                                    <?php echo $rel_tipo_label; ?>
                                </div>
                            </div>
                            <div class="property-info">
                                <h3><?php echo htmlspecialchars($rel['title']); ?></h3>
                                <div class="location">
                                    <i class="fa-solid fa-location-dot"></i>
                                    <?php echo htmlspecialchars($rel_ubicacion); ?>
                                </div>
                                <div class="price"><?php echo $rel_precio; ?></div>
                                <div class="features">
                                    <?php if (!empty($rel['bedrooms'])): ?>
                                        <span><i class="fa-solid fa-bed"></i> <?php echo $rel['bedrooms']; ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($rel['bathrooms'])): ?>
                                        <span><i class="fa-solid fa-bath"></i> <?php echo $rel['bathrooms']; ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($rel['square_meters'])): ?>
                                        <span><i class="fa-solid fa-vector-square"></i> <?php echo number_format($rel['square_meters'], 0, ',', '.'); ?> m²</span>
                                    <?php endif; ?>
                                </div>
                                <div class="property-actions" style="margin-top:14px;">
                                    <a href="propiedad_detalle_portal.php?id=<?php echo $rel['id']; ?>" 
                                       class="btn-outline-gold" 
                                       style="width:100%; padding:10px 16px; font-size:0.8rem;">
                                        Ver detalles
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- ===== WHATSAPP FLOAT ===== -->
    <a href="https://wa.me/523311586937?text=Hola%2C%20estoy%20interesado%20en%20una%20propiedad%20de%20Vera%20Terra" 
       target="_blank" 
       class="whatsapp-float" 
       aria-label="Contactar por WhatsApp">
        <i class="fa-brands fa-whatsapp"></i>
        <span class="tooltip">¡Escríbenos!</span>
    </a>

    <?php include 'footer.php'; ?>

    <script>
        // ============================================================
        //  SCROLL NAVBAR
        // ============================================================
        document.addEventListener('DOMContentLoaded', function() {
            const header = document.getElementById('header');
            if (header) {
                window.addEventListener('scroll', function() {
                    if (window.scrollY > 30) {
                        header.classList.add('scrolled');
                    } else {
                        header.classList.remove('scrolled');
                    }
                });
            }
        });

        // ============================================================
        //  GALERÍA - NAVEGACIÓN COMPLETA
        // ============================================================
        
        // Array con todas las URLs de las imágenes (generado por PHP)
        const galeriaImagenes = <?php echo json_encode($imagenes_json); ?>;
        let indiceActual = 0;

        // Cambiar a una imagen específica por índice
        function seleccionarImagen(index) {
            if (index < 0 || index >= galeriaImagenes.length) return;
            indiceActual = index;

            const mainImg = document.getElementById('mainImage');
            const counter = document.getElementById('counterActual');
            const thumbs = document.querySelectorAll('.gallery-thumb');

            // Fade transition
            mainImg.style.opacity = '0.4';
            setTimeout(() => {
                mainImg.src = galeriaImagenes[index];
                mainImg.style.opacity = '1';
            }, 120);

            // Actualizar contador
            if (counter) counter.textContent = index + 1;

            // Actualizar thumb activo
            thumbs.forEach((t, i) => {
                t.classList.toggle('active', i === index);
            });

            // Scroll automático del thumb activo a la vista
            const thumbActivo = document.querySelector(`.gallery-thumb[data-index="${index}"]`);
            if (thumbActivo) {
                thumbActivo.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }

        // Navegar prev/next desde la galería principal
        function navGaleria(direccion) {
            let nuevo = indiceActual + direccion;
            if (nuevo < 0) nuevo = galeriaImagenes.length - 1;
            if (nuevo >= galeriaImagenes.length) nuevo = 0;
            seleccionarImagen(nuevo);
        }

        // ============================================================
        //  LIGHTBOX
        // ============================================================
        let lightboxIndice = 0;

        function abrirLightbox() {
            if (galeriaImagenes.length === 0) return;
            lightboxIndice = indiceActual;
            actualizarLightbox();
            document.getElementById('lightbox').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function cerrarLightbox(e) {
            if (e && e.target && !e.target.closest('.lightbox-close') && 
                !e.target.classList.contains('lightbox')) {
                return;
            }
            document.getElementById('lightbox').classList.remove('active');
            document.body.style.overflow = '';
        }

        function actualizarLightbox() {
            document.getElementById('lightboxImg').src = galeriaImagenes[lightboxIndice];
            document.getElementById('lightboxActual').textContent = lightboxIndice + 1;
        }

        function navLightbox(direccion) {
            let nuevo = lightboxIndice + direccion;
            if (nuevo < 0) nuevo = galeriaImagenes.length - 1;
            if (nuevo >= galeriaImagenes.length) nuevo = 0;
            lightboxIndice = nuevo;
            actualizarLightbox();
            // Sincronizar con la galería principal
            seleccionarImagen(nuevo);
        }

        // ============================================================
        //  NAVEGACIÓN CON TECLADO
        // ============================================================
        document.addEventListener('keydown', function(e) {
            const lightboxAbierto = document.getElementById('lightbox').classList.contains('active');
            
            if (e.key === 'Escape' && lightboxAbierto) {
                cerrarLightbox({ target: document.getElementById('lightbox') });
                return;
            }

            if (e.key === 'ArrowLeft') {
                if (lightboxAbierto) navLightbox(-1);
                else if (galeriaImagenes.length > 1) navGaleria(-1);
            }

            if (e.key === 'ArrowRight') {
                if (lightboxAbierto) navLightbox(1);
                else if (galeriaImagenes.length > 1) navGaleria(1);
            }
        });

        // ============================================================
        //  SWIPE TÁCTIL (móvil)
        // ============================================================
        (function() {
            let touchStartX = 0;
            let touchEndX = 0;

            const mainGallery = document.getElementById('galleryWrapper');
            if (!mainGallery) return;

            mainGallery.addEventListener('touchstart', e => {
                touchStartX = e.changedTouches[0].screenX;
            }, { passive: true });

            mainGallery.addEventListener('touchend', e => {
                touchEndX = e.changedTouches[0].screenX;
                const diff = touchStartX - touchEndX;
                
                if (Math.abs(diff) > 50 && galeriaImagenes.length > 1) {
                    if (diff > 0) navGaleria(1);
                    else navGaleria(-1);
                }
            }, { passive: true });

            const lightbox = document.getElementById('lightbox');
            if (lightbox) {
                lightbox.addEventListener('touchstart', e => {
                    touchStartX = e.changedTouches[0].screenX;
                }, { passive: true });

                lightbox.addEventListener('touchend', e => {
                    touchEndX = e.changedTouches[0].screenX;
                    const diff = touchStartX - touchEndX;
                    
                    if (Math.abs(diff) > 50 && galeriaImagenes.length > 1) {
                        if (diff > 0) navLightbox(1);
                        else navLightbox(-1);
                    }
                }, { passive: true });
            }
        })();

        // ============================================================
        //  SMOOTH SCROLL PARA ANCLAS
        // ============================================================
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
    </script>
</body>
</html>