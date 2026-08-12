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
        }

        .property-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 15px 40px rgba(11, 31, 58, 0.12);
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

        .pagination button {
            padding: 10px 16px;
            border: 1px solid #ddd;
            background: #fff;
            border-radius: 8px;
            cursor: pointer;
            transition: all var(--transition);
            font-family: 'Montserrat', sans-serif;
            font-weight: 500;
            font-size: 0.85rem;
            color: var(--text-dark);
            min-width: 44px;
        }

        .pagination button:hover {
            border-color: var(--gold);
            color: var(--gold);
        }

        .pagination button.active {
            background: var(--gold);
            color: #fff;
            border-color: var(--gold);
        }

        .pagination button:disabled {
            opacity: 0.4;
            cursor: not-allowed;
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

            .pagination button {
                padding: 8px 12px;
                font-size: 0.8rem;
                min-width: 36px;
            }
        }
    </style>
</head>
<body>

    <?php include 'navbar.php'; ?>

    <!-- ===== FILTROS ===== -->
    <section class="filters-section">
        <div class="container">
            <div class="filters-wrapper">
                <div class="filters-group">
                    <select id="filterType">
                        <option value="all">Todos los tipos</option>
                        <option value="venta">Venta</option>
                        <option value="renta">Renta</option>
                    </select>
                    <select id="filterCategory">
                        <option value="all">Todas las categorías</option>
                        <option value="residencial">Residencial</option>
                        <option value="corporativo">Corporativo</option>
                        <option value="lujo">Lujo</option>
                    </select>
                    <input type="text" id="filterSearch" placeholder="Buscar por ubicación..." />
                    <button class="btn-outline-gold" id="applyFilters"><i class="fa-regular fa-sliders"></i> Filtrar</button>
                    <button class="btn-outline-gold" id="resetFilters"><i class="fa-regular fa-rotate"></i> Reiniciar</button>
                </div>
                <div class="results-count">
                    Mostrando <span id="resultsCount">6</span> propiedades
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
                <!-- Las tarjetas se generarán con JavaScript -->
            </div>

            <!-- ===== PAGINACIÓN ===== -->
            <div class="pagination" id="pagination">
                <button id="prevPage" disabled><i class="fa-regular fa-chevron-left"></i></button>
                <button class="active">1</button>
                <button>2</button>
                <button>3</button>
                <button id="nextPage"><i class="fa-regular fa-chevron-right"></i></button>
            </div>
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

    <!-- ===== FOOTER ===== -->
    <footer>
        <a href="index.html" class="footer-logo">
            <div class="logo-icon">VT</div>
            <span>VERA TERRA</span>
        </a>

        <div class="footer-contact">
            <span><i class="fa-regular fa-location-dot"></i> Av. Reforma 123, CDMX</span>
            <span><i class="fa-regular fa-phone"></i> 33 1234 5678</span>
            <span><i class="fa-regular fa-envelope"></i> contacto@veraterra.com</span>
        </div>

        <div class="social-links">
            <a href="#" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
            <a href="#" aria-label="LinkedIn"><i class="fa-brands fa-linkedin-in"></i></a>
            <a href="#" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
            <a href="#" aria-label="YouTube"><i class="fa-brands fa-youtube"></i></a>
        </div>
    </footer>

    <script>
        // ============================================================
        //  BASE DE DATOS SIMULADA (REEMPLAZAR CON CONEXIÓN REAL)
        // ============================================================
        const propiedadesData = [{
            id: 1,
            titulo: 'Penthouse en Polanco',
            ubicacion: 'Polanco, CDMX',
            precio: '$2,850,000 MXN',
            tipo: 'venta',
            categoria: 'lujo',
            habitaciones: 3,
            banos: 3.5,
            superficie: '260 m²',
            imagen: 'https://images.unsplash.com/photo-1512917774080-9991f1c4c750?auto=format&fit=crop&w=600&q=80',
            descripcion: 'Impresionante penthouse con vista panorámica, acabados de lujo y terraza privada.'
        }, {
            id: 2,
            titulo: 'Residencia en Club de Golf',
            ubicacion: 'Bosques de las Lomas',
            precio: '$4,200,000 MXN',
            tipo: 'venta',
            categoria: 'residencial',
            habitaciones: 4,
            banos: 4.5,
            superficie: '450 m²',
            imagen: 'https://images.unsplash.com/photo-1613977257363-707ba9348227?auto=format&fit=crop&w=600&q=80',
            descripcion: 'Espectacular residencia con jardín privado, alberca y acceso al club de golf.'
        }, {
            id: 3,
            titulo: 'Oficina Corporativa en Reforma',
            ubicacion: 'Av. Reforma, CDMX',
            precio: '$3,100,000 MXN',
            tipo: 'venta',
            categoria: 'corporativo',
            habitaciones: 0,
            banos: 2,
            superficie: '320 m²',
            imagen: 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=600&q=80',
            descripcion: 'Moderno espacio corporativo en pleno corazón financiero, con recepción y 2 estacionamientos.'
        }, {
            id: 4,
            titulo: 'Departamento en Condesa',
            ubicacion: 'Condesa, CDMX',
            precio: '$18,500 MXN/mes',
            tipo: 'renta',
            categoria: 'residencial',
            habitaciones: 2,
            banos: 2,
            superficie: '110 m²',
            imagen: 'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?auto=format&fit=crop&w=600&q=80',
            descripcion: 'Hermoso departamento con balcón, excelente ubicación cerca de restaurantes y parques.'
        }, {
            id: 5,
            titulo: 'Casa en Santa Fe',
            ubicacion: 'Santa Fe, CDMX',
            precio: '$5,600,000 MXN',
            tipo: 'venta',
            categoria: 'lujo',
            habitaciones: 5,
            banos: 5,
            superficie: '520 m²',
            imagen: 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=600&q=80',
            descripcion: 'Imponente casa con acabados italianos, alberca climatizada y roof garden.'
        }, {
            id: 6,
            titulo: 'Local Comercial en Roma',
            ubicacion: 'Roma, CDMX',
            precio: '$28,000 MXN/mes',
            tipo: 'renta',
            categoria: 'corporativo',
            habitaciones: 0,
            banos: 1,
            superficie: '85 m²',
            imagen: 'https://images.unsplash.com/photo-1497366216548-37526070297c?auto=format&fit=crop&w=600&q=80',
            descripcion: 'Local con gran visibilidad en calle concurrida, ideal para restaurante o boutique.'
        }, {
            id: 7,
            titulo: 'Terreno en Valle de Bravo',
            ubicacion: 'Valle de Bravo, EdoMex',
            precio: '$1,200,000 MXN',
            tipo: 'venta',
            categoria: 'residencial',
            habitaciones: 0,
            banos: 0,
            superficie: '1500 m²',
            imagen: 'https://images.unsplash.com/photo-1500382017468-9049fed747ef?auto=format&fit=crop&w=600&q=80',
            descripcion: 'Terreno con vista al lago, ideal para construcción de casa de descanso.'
        }, {
            id: 8,
            titulo: 'Ático en Interlomas',
            ubicacion: 'Interlomas, Estado de México',
            precio: '$3,800,000 MXN',
            tipo: 'venta',
            categoria: 'lujo',
            habitaciones: 3,
            banos: 3,
            superficie: '310 m²',
            imagen: 'https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?auto=format&fit=crop&w=600&q=80',
            descripcion: 'Ático con diseño vanguardista, terraza panorámica y acceso a amenities exclusivos.'
        }];

        // ============================================================
        //  FUNCIONES PARA RENDERIZAR PROPIEDADES
        // ============================================================
        let currentPage = 1;
        const itemsPerPage = 6;
        let filteredData = [...propiedadesData];

        function renderProperties(data) {
            const grid = document.getElementById('propertiesGrid');
            grid.innerHTML = '';

            if (data.length === 0) {
                grid.innerHTML = `
                    <div style="grid-column:1/-1; text-align:center; padding:60px 20px; color:var(--text-muted);">
                        <i class="fa-regular fa-house-circle-exclamation" style="font-size:3rem; color:var(--gold); margin-bottom:15px; display:block;"></i>
                        <h3 style="font-size:1.2rem; margin-bottom:10px;">No encontramos propiedades</h3>
                        <p>Intenta ajustar los filtros de búsqueda</p>
                    </div>
                `;
                return;
            }

            data.forEach(prop => {
                const card = document.createElement('div');
                card.className = 'property-card';

                const statusClass = prop.tipo === 'venta' ? 'venta' : 'renta';
                const statusLabel = prop.tipo === 'venta' ? 'En Venta' : 'En Renta';
                const badgeIcon = prop.categoria === 'lujo' ? 'fa-regular fa-gem' : 'fa-regular fa-building';

                card.innerHTML = `
                    <div class="property-img-container">
                        <img src="${prop.imagen}" alt="${prop.titulo}" loading="lazy" />
                        <div class="property-badge"><i class="${badgeIcon}"></i></div>
                        <div class="property-status ${statusClass}">${statusLabel}</div>
                    </div>
                    <div class="property-info">
                        <h3>${prop.titulo}</h3>
                        <div class="location"><i class="fa-regular fa-location-dot"></i> ${prop.ubicacion}</div>
                        <div class="price">${prop.precio}</div>
                        <div class="features">
                            ${prop.habitaciones > 0 ? `<span><i class="fa-regular fa-bed"></i> ${prop.habitaciones}</span>` : ''}
                            ${prop.banos > 0 ? `<span><i class="fa-regular fa-bath"></i> ${prop.banos}</span>` : ''}
                            <span><i class="fa-regular fa-vector-square"></i> ${prop.superficie}</span>
                        </div>
                        <div class="property-actions">
                            <a href="https://wa.me/5213312345678?text=Hola%2C%20me%20interesa%20la%20propiedad%3A%20${encodeURIComponent(prop.titulo)}%20en%20${encodeURIComponent(prop.ubicacion)}%20con%20precio%20${encodeURIComponent(prop.precio)}" 
                               target="_blank" 
                               class="btn-whatsapp">
                                <i class="fa-brands fa-whatsapp"></i> Consultar
                            </a>
                            <a href="#" class="btn-outline-gold">Ver más</a>
                        </div>
                    </div>
                `;
                grid.appendChild(card);
            });
        }

        function updatePagination(data) {
            const totalPages = Math.ceil(data.length / itemsPerPage);
            const pagination = document.getElementById('pagination');
            const prevBtn = document.getElementById('prevPage');
            const nextBtn = document.getElementById('nextPage');

            // Actualizar botones de página
            const pageButtons = pagination.querySelectorAll('button:not(#prevPage):not(#nextPage)');
            pageButtons.forEach(btn => btn.remove());

            for (let i = 1; i <= totalPages; i++) {
                const btn = document.createElement('button');
                btn.textContent = i;
                btn.className = i === currentPage ? 'active' : '';
                btn.addEventListener('click', () => {
                    currentPage = i;
                    applyFiltersAndPagination();
                });
                pagination.insertBefore(btn, nextBtn);
            }

            prevBtn.disabled = currentPage === 1;
            nextBtn.disabled = currentPage === totalPages || totalPages === 0;

            document.getElementById('resultsCount').textContent = data.length;
        }

        function applyFiltersAndPagination() {
            // Aplicar filtros
            const type = document.getElementById('filterType').value;
            const category = document.getElementById('filterCategory').value;
            const search = document.getElementById('filterSearch').value.toLowerCase().trim();

            filteredData = propiedadesData.filter(prop => {
                const matchType = type === 'all' || prop.tipo === type;
                const matchCategory = category === 'all' || prop.categoria === category;
                const matchSearch = prop.ubicacion.toLowerCase().includes(search) ||
                                   prop.titulo.toLowerCase().includes(search);
                return matchType && matchCategory && matchSearch;
            });

            // Paginación
            const start = (currentPage - 1) * itemsPerPage;
            const end = start + itemsPerPage;
            const pageData = filteredData.slice(start, end);

            renderProperties(pageData);
            updatePagination(filteredData);
        }

        // ============================================================
        //  EVENTOS DE FILTROS
        // ============================================================
        document.getElementById('applyFilters').addEventListener('click', () => {
            currentPage = 1;
            applyFiltersAndPagination();
        });

        document.getElementById('resetFilters').addEventListener('click', () => {
            document.getElementById('filterType').value = 'all';
            document.getElementById('filterCategory').value = 'all';
            document.getElementById('filterSearch').value = '';
            currentPage = 1;
            applyFiltersAndPagination();
        });

        // Enter en búsqueda
        document.getElementById('filterSearch').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                currentPage = 1;
                applyFiltersAndPagination();
            }
        });

        // Navegación de página
        document.getElementById('prevPage').addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage--;
                applyFiltersAndPagination();
            }
        });

        document.getElementById('nextPage').addEventListener('click', () => {
            const totalPages = Math.ceil(filteredData.length / itemsPerPage);
            if (currentPage < totalPages) {
                currentPage++;
                applyFiltersAndPagination();
            }
        });

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

            // Render inicial
            applyFiltersAndPagination();
        });
    </script>
</body>
</html>