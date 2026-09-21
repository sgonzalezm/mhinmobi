<?php
// ===== DEPURACIÓN (quitar en producción) =====
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ===== CONEXIÓN PDO =====
require_once 'includes/conexion.php';

// ===== FUNCIONES AUXILIARES =====
function formatearPrecio($precio) {
    if ($precio === null || $precio == 0) {
        return 'Consultar precio';
    }
    return '$' . number_format(floatval($precio), 0, ',', '.');
}

function getImagenUrl($imagen) {
    if (empty($imagen)) {
        return 'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?auto=format&fit=crop&w=600&q=80';
    }
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

// ===== CONSULTAR PROPIEDADES DESTACADAS =====
$propiedades_destacadas = [];

if ($conn) {
    try {
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
                f.id as featuring_id,
                f.status as featuring_status,
                f.end_date as featuring_end,
                (SELECT file_path FROM property_media 
                 WHERE property_id = p.id AND is_primary = 1 
                 ORDER BY sort_order ASC LIMIT 1) as imagen_principal
            FROM properties p
            LEFT JOIN property_details pd ON p.id = pd.property_id
            LEFT JOIN property_financials pf ON p.id = pf.property_id
            LEFT JOIN property_featuring f ON p.id = f.property_id AND f.status = 'active'
            WHERE p.status = 'activo'
            GROUP BY p.id
            ORDER BY 
                CASE WHEN f.status = 'active' THEN 0 ELSE 1 END,
                p.created_at DESC
            LIMIT 6
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $propiedades_destacadas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Error al consultar propiedades destacadas: " . $e->getMessage());
        $propiedades_destacadas = [];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Vera Terra Inmobiliaria</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
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

        .search-link {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* ===== HERO ===== */
        .hero {
            position: relative;
            min-height: 520px;
            background: linear-gradient(rgba(11, 31, 58, 0.45), rgba(11, 31, 58, 0.75)),
                url('https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?auto=format&fit=crop&w=1600&q=80') center/cover no-repeat;
            display: flex;
            align-items: center;
            padding: 0 8%;
        }

        .hero-content {
            max-width: 580px;
            color: #fff;
        }

        .hero-content .tag {
            display: inline-block;
            background: rgba(197, 160, 89, 0.2);
            backdrop-filter: blur(4px);
            padding: 6px 18px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 1px;
            color: var(--gold);
            border: 1px solid rgba(197, 160, 89, 0.3);
            margin-bottom: 18px;
            text-transform: uppercase;
        }

        .hero h1 {
            font-family: 'Playfair Display', serif;
            font-size: 2.8rem;
            line-height: 1.2;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .hero h1 span {
            color: var(--gold);
        }

        .hero p {
            font-size: 1rem;
            font-weight: 300;
            opacity: 0.9;
            max-width: 440px;
            margin-bottom: 30px;
        }

        .hero-stats {
            display: flex;
            gap: 40px;
            margin-top: 35px;
        }

        .hero-stats .stat {
            text-align: center;
        }

        .hero-stats .stat-number {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--gold);
        }

        .hero-stats .stat-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.8;
        }

        /* ===== SECCIÓN "ME INTERESA" ===== */
        .interest-section {
            padding: 70px 5%;
            background: var(--light-bg);
            text-align: center;
        }

        .interest-section .section-title {
            margin-bottom: 8px;
        }

        .interest-section .section-subtitle {
            margin-bottom: 45px;
        }

        .interest-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            justify-content: center;
            align-items: stretch;
            max-width: 900px;
            margin: 0 auto;
        }

        .interest-btn {
            flex: 1 1 240px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 30px 24px;
            border-radius: var(--radius);
            background: #fff;
            border: 2px solid transparent;
            box-shadow: var(--shadow);
            cursor: pointer;
            transition: transform var(--transition), box-shadow var(--transition), border-color var(--transition), background var(--transition);
            font-family: 'Montserrat', sans-serif;
            text-align: center;
            min-width: 220px;
        }

        .interest-btn i {
            font-size: 2rem;
            transition: transform var(--transition);
        }

        .interest-btn .interest-label {
            font-weight: 600;
            font-size: 1rem;
            color: var(--navy);
            letter-spacing: 0.3px;
        }

        .interest-btn .interest-sub {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: 400;
        }

        .interest-btn:hover {
            transform: translateY(-6px);
            box-shadow: 0 15px 40px rgba(11, 31, 58, 0.12);
        }

        .interest-btn:hover i {
            transform: scale(1.1);
        }

        /* Botón Vender (outline dorado) */
        .interest-btn.btn-sell {
            border-color: var(--gold);
        }

        .interest-btn.btn-sell i {
            color: var(--gold);
        }

        .interest-btn.btn-sell:hover {
            background: var(--gold-light);
        }

        /* Botón Comprar (relleno dorado) */
        .interest-btn.btn-buy {
            background: var(--gold);
            border-color: var(--gold);
        }

        .interest-btn.btn-buy i {
            color: #fff;
        }

        .interest-btn.btn-buy .interest-label {
            color: #fff;
        }

        .interest-btn.btn-buy .interest-sub {
            color: rgba(255, 255, 255, 0.85);
        }

        .interest-btn.btn-buy:hover {
            background: var(--gold-hover);
            border-color: var(--gold-hover);
        }

        /* Botón WhatsApp (verde) */
        .interest-btn.btn-whatsapp-card {
            background: #25D366;
            border-color: #25D366;
        }

        .interest-btn.btn-whatsapp-card i {
            color: #fff;
        }

        .interest-btn.btn-whatsapp-card .interest-label {
            color: #fff;
        }

        .interest-btn.btn-whatsapp-card .interest-sub {
            color: rgba(255, 255, 255, 0.9);
        }

        .interest-btn.btn-whatsapp-card:hover {
            background: #1da85a;
            border-color: #1da85a;
        }

        /* ===== PROPIEDADES DESTACADAS ===== */
        .properties-section {
            padding: 70px 5%;
            background: #ffffff;
        }

        .properties-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 30px;
            margin-top: 20px;
        }

        .property-card {
            background: #fff;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: transform var(--transition), box-shadow var(--transition);
            border: 1px solid rgba(197, 160, 89, 0.15);
        }

        .property-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 40px rgba(11, 31, 58, 0.12);
        }

        .property-img-container {
            position: relative;
            width: 100%;
            aspect-ratio: 4 / 3;          /* Proporción más natural para fotos de casas */
            overflow: hidden;
            background: #f0f0f0;          /* Fondo mientras carga */
        }

        .property-img-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center center; /* Centra el recorte */
            transition: transform 0.6s ease;
        }

        .property-card:hover .property-img-container img {
            transform: scale(1.06);
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
        }

        .property-info {
            padding: 20px 20px 18px;
        }

        .property-info h3 {
            font-size: 1rem;
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
            margin-bottom: 10px;
        }

        .property-info .price {
            font-weight: 700;
            color: var(--gold);
            font-size: 1.1rem;
        }

        .property-info .features {
            display: flex;
            gap: 18px;
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 10px;
            border-top: 1px solid #eee;
            padding-top: 10px;
        }

        .property-info .features span i {
            margin-right: 4px;
            color: var(--gold);
        }

        /* ===== SERVICIOS ===== */
        .services-section {
            padding: 70px 5%;
            background: var(--light-bg);
        }

        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 30px;
            margin-top: 10px;
        }

        .service-item {
            background: #fff;
            padding: 35px 20px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            transition: transform var(--transition), box-shadow var(--transition);
            border-bottom: 4px solid transparent;
            text-align: center;
        }

        .service-item:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 35px rgba(11, 31, 58, 0.08);
            border-bottom-color: var(--gold);
        }

        .service-icon {
            font-size: 2.8rem;
            color: var(--gold);
            margin-bottom: 15px;
        }

        .service-item h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--navy);
        }

        .service-item p {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: 6px;
            line-height: 1.5;
        }

        /* ===== TESTIMONIOS ===== */
        .testimonials-section {
            padding: 70px 5%;
            background: #fff;
        }

        .testimonials-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 30px;
            margin-top: 20px;
        }

        .testimonial-card {
            background: var(--light-bg);
            padding: 28px 24px;
            border-radius: var(--radius);
            border-left: 4px solid var(--gold);
            transition: transform var(--transition);
        }

        .testimonial-card:hover {
            transform: translateX(4px);
        }

        .testimonial-card p {
            font-style: italic;
            font-size: 0.9rem;
            color: var(--text-dark);
            margin-bottom: 12px;
        }

        .testimonial-card .author {
            font-weight: 600;
            color: var(--navy);
            font-size: 0.85rem;
        }

        .testimonial-card .author span {
            font-weight: 400;
            color: var(--text-muted);
            font-size: 0.75rem;
        }

        /* ===== CONTACTO / CTA ===== */
        .cta-section {
            padding: 60px 5%;
            background: var(--navy);
            color: #fff;
            text-align: center;
        }

        .cta-section h2 {
            font-family: 'Playfair Display', serif;
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .cta-section p {
            opacity: 0.8;
            max-width: 500px;
            margin: 0 auto 30px;
        }

        .cta-section .btn-gold {
            background: var(--gold);
            color: var(--navy);
        }

        .cta-section .btn-gold:hover {
            background: #fff;
        }

        /* ===== FOOTER ===== */
        footer {
            background: #ffffff;
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
            color: #000;
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
            color: #494848;
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
            .hero h1 {
                font-size: 2.2rem;
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

            .hero {
                min-height: 400px;
                padding: 0 6%;
            }

            .hero h1 {
                font-size: 1.8rem;
            }

            .hero-stats {
                gap: 20px;
                flex-wrap: wrap;
            }

            .hero-stats .stat-number {
                font-size: 1.3rem;
            }

            .section-title {
                font-size: 1.8rem;
            }

            .interest-section {
                padding: 50px 5%;
            }

            .interest-buttons {
                flex-direction: column;
                gap: 16px;
            }

            .interest-btn {
                flex: 1 1 auto;
                width: 100%;
                padding: 24px 20px;
            }

            .properties-grid {
                grid-template-columns: 1fr;
            }

            .services-grid {
                grid-template-columns: 1fr 1fr;
            }

            .testimonials-grid {
                grid-template-columns: 1fr;
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
            .services-grid {
                grid-template-columns: 1fr;
            }

            .hero h1 {
                font-size: 1.5rem;
            }

            .btn-gold {
                padding: 12px 28px;
                font-size: 0.85rem;
            }

            .interest-btn i {
                font-size: 1.6rem;
            }

            .interest-btn .interest-label {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>

    <?php include 'modulos/navbar.php'; ?>

    <!-- ===== HERO ===== -->
    <section class="hero">
        <div class="hero-content">
            <div class="tag"><i class="fas fa-star"></i> Confianza y excelencia</div>
            <h1>Certeza jurídica y valor patrimonial <span>en cada propiedad.</span></h1>
            <p>Más de 10 años asesorando a nuestros clientes con transparencia, ética y un profundo conocimiento del mercado inmobiliario.</p>
            <a href="#interes" class="btn-gold"><i class="fas fa-check-circle"></i> Explorar propiedades</a>
            <div class="hero-stats">
                <div class="stat">
                    <div class="stat-number">+150</div>
                    <div class="stat-label">Operaciones exitosas</div>
                </div>
                <div class="stat">
                    <div class="stat-number">98%</div>
                    <div class="stat-label">Satisfacción</div>
                </div>
                <div class="stat">
                    <div class="stat-number">10</div>
                    <div class="stat-label">Años de experiencia</div>
                </div>
            </div>
        </div>
    </section>

    <!-- ===== SECCIÓN "ME INTERESA" ===== -->
    <section class="interest-section" id="interes">
        <div class="container">
            <h2 class="section-title">Me interesa</h2>
            <p class="section-subtitle">Cuéntanos qué necesitas y te acompañamos en cada paso del camino.</p>

            <div class="interest-buttons">

                <!-- Botón 1: Vender (destino por definir) -->
                <a href="vender_formulario.php" id="btn-vender" class="interest-btn btn-sell" aria-label="Quiero vender mi casa">
                    <i class="fas fa-hand-holding-dollar"></i>
                    <span class="interest-label">Quiero vender mi casa</span>
                    <span class="interest-sub">Recibe una valuación sin compromiso</span>
                </a>

                <!-- Botón 2: Comprar -->
                <a href="propiedades.php" class="interest-btn btn-buy" aria-label="Quiero comprar una casa">
                    <i class="fas fa-house-chimney"></i>
                    <span class="interest-label">Quiero comprar una casa</span>
                    <span class="interest-sub">Explora nuestro catálogo disponible</span>
                </a>

                <!-- Botón 3: WhatsApp -->
                <a href="https://wa.me/523311586937?text=Hola%2C%20estoy%20interesado%20en%20una%20asesor%C3%ADa%20inmobiliaria%20con%20Vera%20Terra"
                   target="_blank"
                   rel="noopener"
                   class="interest-btn btn-whatsapp-card"
                   aria-label="Contáctanos por WhatsApp">
                    <i class="fab fa-whatsapp"></i>
                    <span class="interest-label">Contáctanos por WhatsApp</span>
                    <span class="interest-sub">Atención inmediata y personalizada</span>
                </a>

            </div>
        </div>
    </section>

    <!-- ===== PROPIEDADES DESTACADAS (DESDE BD) ===== -->
    <section class="properties-section" id="propiedades">
        <div class="container">
            <h2 class="section-title">Propiedades destacadas</h2>
            <p class="section-subtitle">Selección exclusiva de inmuebles con alto potencial de inversión y plusvalía.</p>
            <div class="properties-grid">

                <?php if (!empty($propiedades_destacadas)): ?>
                    <?php foreach ($propiedades_destacadas as $prop): 
                        $tiene_featuring = tieneFeaturing($prop);
                        $ubicacion       = getUbicacionCompleta($prop);
                        $precio          = formatearPrecio($prop['price']);
                        $imagen          = getImagenUrl($prop['imagen_principal']);
                    ?>
                        <div class="property-card">
                            <div class="property-img-container">
                                <img src="<?php echo $imagen; ?>" 
                                     alt="<?php echo htmlspecialchars($prop['title']); ?>" 
                                     loading="lazy" />
                                <div class="property-badge">
                                    <i class="fas fa-gem"></i>
                                </div>
                            </div>
                            <div class="property-info">
                                <h3><?php echo htmlspecialchars($prop['title']); ?></h3>
                                <div class="location">
                                    <i class="fas fa-location-dot"></i> 
                                    <?php echo htmlspecialchars($ubicacion); ?>
                                </div>
                                <div class="price"><?php echo $precio; ?></div>
                                <div class="features">
                                    <?php if (!empty($prop['bedrooms']) && $prop['bedrooms'] > 0): ?>
                                        <span><i class="fas fa-bed"></i> <?php echo htmlspecialchars($prop['bedrooms']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($prop['bathrooms']) && $prop['bathrooms'] > 0): ?>
                                        <span><i class="fas fa-bath"></i> <?php echo htmlspecialchars($prop['bathrooms']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($prop['square_meters']) && $prop['square_meters'] > 0): ?>
                                        <span><i class="fas fa-vector-square"></i> <?php echo number_format($prop['square_meters'], 0, ',', '.'); ?> m²</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="grid-column: 1/-1; text-align:center; color: var(--text-muted);">
                        No hay propiedades destacadas por el momento.
                    </p>
                <?php endif; ?>

            </div>
            <div style="margin-top: 35px;">
                <a href="propiedades.php" class="btn-outline-gold">Ver todas las propiedades <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>
    </section>

    <!-- ===== SERVICIOS ===== -->
    <section class="services-section" id="servicios">
        <div class="container">
            <h2 class="section-title">Nuestros servicios</h2>
            <p class="section-subtitle">Acompañamos cada etapa de tu proyecto inmobiliario con soluciones integrales y personalizadas.</p>
            <div class="services-grid">

                <div class="service-item">
                    <div class="service-icon"><i class="fas fa-handshake"></i></div>
                    <h3>Compra-Venta de propiedades</h3>
                    <p>Asesoramiento completo en la compra y venta de propiedades, garantizando procesos seguros y eficientes.</p>
                </div>

                <div class="service-item">
                    <div class="service-icon"><i class="fas fa-hammer"></i></div>
                    <h3>Remodelaciones</h3>
                    <p>Diseño y ejecución de proyectos de remodelación para transformar tu espacio en el hogar o negocio.</p>
                </div>

                <div class="service-item">
                    <div class="service-icon"><i class="fas fa-vector-square"></i></div>
                    <h3>Avalúos</h3>
                    <p>Estudios de mercado y avalúos profesionales para tomar decisiones con información precisa.</p>
                </div>

                <div class="service-item">
                    <div class="service-icon"><i class="fas fa-coins"></i></div>
                    <h3>Creditos hipotecarios y Asesoría Financiera</h3>
                    <p>Planeación fiscal, análisis de rentabilidad y acompañamiento en créditos hipotecarios.</p>
                </div>

                <div class="service-item">
                    <div class="service-icon"><i class="fas fa-file-contract"></i></div>
                    <h3>Tramites Infonavit y notariales</h3>
                    <p>Asistencia en trámites relacionados con Infonavit y procesos notariales para garantizar la legalidad de tus transacciones inmobiliarias.</p>
                </div>

            </div>
        </div>
    </section>

    <!-- ===== CTA / CONTACTO ===== -->
    <section class="cta-section" id="contacto">
        <div class="container">
            <h2>¿Listo para dar el siguiente paso?</h2>
            <p>Contáctanos y descubre cómo podemos ayudarte a hacer realidad tus proyectos inmobiliarios.</p>
            <a href="contacto.php" class="btn-gold"><i class="fas fa-envelope"></i> Solicitar asesoría</a>
        </div>
    </section>

    <?php include 'modulos/footer.php'; ?>

    <!-- ===== SCRIPT para efecto de scroll en navbar ===== -->
    <script>
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
    </script>
</body>
</html>