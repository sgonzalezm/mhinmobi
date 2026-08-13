<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Vera Terra Inmobiliaria</title>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <!-- Google Fonts: Montserrat + Playfair Display para un toque más elegante -->
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
            height: 220px;
            overflow: hidden;
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

        /* ===== TESTIMONIOS (nuevo bloque) ===== */
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

        /* ===== CONTACTO / CTA (nuevo) ===== */
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
        }
    </style>
</head>
<body>

    <?php include 'navbar.php'; ?>

    <!-- ===== HERO ===== -->
    <section class="hero">
        <div class="hero-content">
            <div class="tag"><i class="fa-regular fa-star"></i> Confianza y excelencia</div>
            <h1>Certeza jurídica y valor patrimonial <span>en cada propiedad.</span></h1>
            <p>Más de 15 años asesorando a nuestros clientes con transparencia, ética y un profundo conocimiento del mercado inmobiliario.</p>
            <a href="#" class="btn-gold"><i class="fa-regular fa-circle-check"></i> Explorar propiedades</a>
            <div class="hero-stats">
                <div class="stat">
                    <div class="stat-number">+350</div>
                    <div class="stat-label">Operaciones exitosas</div>
                </div>
                <div class="stat">
                    <div class="stat-number">98%</div>
                    <div class="stat-label">Satisfacción</div>
                </div>
                <div class="stat">
                    <div class="stat-number">15</div>
                    <div class="stat-label">Años de experiencia</div>
                </div>
            </div>
        </div>
    </section>

    <!-- ===== PROPIEDADES DESTACADAS ===== -->
    <section class="properties-section" id="propiedades">
        <div class="container">
            <h2 class="section-title">Propiedades destacadas</h2>
            <p class="section-subtitle">Selección exclusiva de inmuebles con alto potencial de inversión y plusvalía.</p>
            <div class="properties-grid">

                <?php 
                // Incluir codigo para obtener propiedades desde la base de datos o un array
                ?>

                <div class="property-card">
                    <div class="property-img-container">
                        <img src="https://images.unsplash.com/photo-1512917774080-9991f1c4c750?auto=format&fit=crop&w=600&q=80" alt="Penthouse en Polanco" loading="lazy" />
                        <div class="property-badge"><i class="fa-regular fa-gem"></i></div>
                    </div>
                    <div class="property-info">
                        <h3>Penthouse en Polanco</h3>
                        <div class="location"><i class="fa-regular fa-location-dot"></i> Polanco, CDMX</div>
                        <div class="price">$2,850,000 MXN</div>
                        <div class="features">
                            <span><i class="fa-regular fa-bed"></i> 3</span>
                            <span><i class="fa-regular fa-bath"></i> 3.5</span>
                            <span><i class="fa-regular fa-vector-square"></i> 260 m²</span>
                        </div>
                    </div>
                </div>

                <div class="property-card">
                    <div class="property-img-container">
                        <img src="https://images.unsplash.com/photo-1613977257363-707ba9348227?auto=format&fit=crop&w=600&q=80" alt="Residencia en Club de Golf" loading="lazy" />
                        <div class="property-badge"><i class="fa-regular fa-gem"></i></div>
                    </div>
                    <div class="property-info">
                        <h3>Residencia en Club de Golf</h3>
                        <div class="location"><i class="fa-regular fa-location-dot"></i> Bosques de las Lomas</div>
                        <div class="price">$4,200,000 MXN</div>
                        <div class="features">
                            <span><i class="fa-regular fa-bed"></i> 4</span>
                            <span><i class="fa-regular fa-bath"></i> 4.5</span>
                            <span><i class="fa-regular fa-vector-square"></i> 450 m²</span>
                        </div>
                    </div>
                </div>

                <div class="property-card">
                    <div class="property-img-container">
                        <img src="https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=600&q=80" alt="Oficina Corporativa" loading="lazy" />
                        <div class="property-badge"><i class="fa-regular fa-gem"></i></div>
                    </div>
                    <div class="property-info">
                        <h3>Oficina Corporativa en Reforma</h3>
                        <div class="location"><i class="fa-regular fa-location-dot"></i> Av. Reforma, CDMX</div>
                        <div class="price">$3,100,000 MXN</div>
                        <div class="features">
                            <span><i class="fa-regular fa-bed"></i> N/A</span>
                            <span><i class="fa-regular fa-bath"></i> 2</span>
                            <span><i class="fa-regular fa-vector-square"></i> 320 m²</span>
                        </div>
                    </div>
                </div>

            </div>
            <div style="margin-top: 35px;">
                <a href="#" class="btn-outline-gold">Ver todas las propiedades <i class="fa-regular fa-arrow-right"></i></a>
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
                    <div class="service-icon"><i class="fa-regular fa-gavel"></i></div>
                    <h3>Asesoría Jurídica</h3>
                    <p>Revisión de contratos, escrituras y due diligence para garantizar certeza legal en cada transacción.</p>
                </div>

                <div class="service-item">
                    <div class="service-icon"><i class="fa-regular fa-handshake"></i></div>
                    <h3>Gestión Inmobiliaria</h3>
                    <p>Administración de propiedades, búsqueda de inquilinos y mantenimiento integral.</p>
                </div>

                <div class="service-item">
                    <div class="service-icon"><i class="fa-regular fa-calculator"></i></div>
                    <h3>Valoración de Activos</h3>
                    <p>Estudios de mercado y avalúos profesionales para tomar decisiones con información precisa.</p>
                </div>

                <div class="service-item">
                    <div class="service-icon"><i class="fa-regular fa-building-columns"></i></div>
                    <h3>Asesoría Financiera</h3>
                    <p>Planeación fiscal, análisis de rentabilidad y acompañamiento en créditos hipotecarios.</p>
                </div>

            </div>
        </div>
    </section>

    <!-- ===== TESTIMONIOS (nuevo) ===== -->
    <section class="testimonials-section" id="testimonios">
        <div class="container">
            <h2 class="section-title">Lo que dicen nuestros clientes</h2>
            <p class="section-subtitle">La confianza de quienes han confiado en nosotros es nuestro mejor respaldo.</p>
            <div class="testimonials-grid">

                <div class="testimonial-card">
                    <p>“El equipo de Vera Terra nos acompañó en cada paso. Su asesoría jurídica fue impecable y logramos cerrar la compra de nuestra casa en tiempo récord.”</p>
                    <div class="author">Laura Méndez <span>– Cliente residencial</span></div>
                </div>

                <div class="testimonial-card">
                    <p>“Gracias a su valoración de activos pudimos identificar el verdadero potencial de nuestra inversión. Profesionalismo y transparencia total.”</p>
                    <div class="author">Carlos Herrera <span>– Inversionista</span></div>
                </div>

                <div class="testimonial-card">
                    <p>“Nos encargaron la gestión de varias oficinas corporativas y el resultado fue excelente. Siempre atentos y con soluciones rápidas.”</p>
                    <div class="author">Ana Sofía Torres <span>– Directora de Operaciones</span></div>
                </div>

            </div>
        </div>
    </section>

    <!-- ===== CTA / CONTACTO (nuevo) ===== -->
    <section class="cta-section" id="contacto">
        <div class="container">
            <h2>¿Listo para dar el siguiente paso?</h2>
            <p>Contáctanos y descubre cómo podemos ayudarte a hacer realidad tus proyectos inmobiliarios.</p>
            <a href="#" class="btn-gold"><i class="fa-regular fa-envelope"></i> Solicitar asesoría</a>
        </div>
    </section>

    <!-- ===== FOOTER ===== -->
    <footer>
        <a href="#" class="footer-logo">
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

    <!-- ===== SCRIPT para efecto de scroll en navbar ===== -->
    <script>
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