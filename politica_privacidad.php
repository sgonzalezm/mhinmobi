<?php
// politica_privacidad.php
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Política de Privacidad | Vera Terra Inmobiliaria</title>
    <meta name="description" content="Política de Privacidad de Vera Terra Inmobiliaria. Conoce cómo recopilamos, usamos y protegemos tus datos personales." />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet" />
    <style>
        /* ===== RESET & ROOT (idénticos al index) ===== */
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

        /* ===== HEADER / NAVBAR (idénticos al index) ===== */
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

        /* ===== HERO INTERNO ===== */
        .legal-hero {
            background: linear-gradient(rgba(11, 31, 58, 0.85), rgba(11, 31, 58, 0.95)),
                url('https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?auto=format&fit=crop&w=1600&q=80') center/cover no-repeat;
            color: #fff;
            padding: 90px 5% 70px;
            text-align: center;
        }

        .legal-hero .tag {
            display: inline-block;
            background: rgba(197, 160, 89, 0.2);
            padding: 6px 18px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 1px;
            color: var(--gold);
            border: 1px solid rgba(197, 160, 89, 0.4);
            margin-bottom: 18px;
            text-transform: uppercase;
        }

        .legal-hero h1 {
            font-family: 'Playfair Display', serif;
            font-size: 2.6rem;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .legal-hero p {
            opacity: 0.85;
            font-weight: 300;
            font-size: 0.95rem;
        }

        /* ===== CONTENIDO LEGAL ===== */
        .legal-content {
            padding: 70px 5%;
            background: #fff;
        }

        .legal-content .intro {
            background: var(--light-bg);
            border-left: 4px solid var(--gold);
            padding: 22px 26px;
            border-radius: var(--radius);
            margin-bottom: 45px;
            font-size: 0.95rem;
            color: var(--text-dark);
        }

        .legal-content h2 {
            font-family: 'Playfair Display', serif;
            color: var(--navy);
            font-size: 1.5rem;
            margin-top: 40px;
            margin-bottom: 14px;
            padding-bottom: 8px;
            border-bottom: 1px solid rgba(197, 160, 89, 0.3);
        }

        .legal-content h3 {
            color: var(--navy);
            font-size: 1.05rem;
            margin-top: 22px;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .legal-content p,
        .legal-content li {
            color: var(--text-muted);
            font-size: 0.95rem;
            margin-bottom: 12px;
        }

        .legal-content ul {
            padding-left: 22px;
            margin-bottom: 16px;
        }

        .legal-content ul li::marker {
            color: var(--gold);
        }

        .legal-content strong {
            color: var(--navy);
            font-weight: 600;
        }

        .legal-content a.inline-link {
            color: var(--gold);
            font-weight: 600;
            border-bottom: 1px solid var(--gold);
        }

        .legal-content a.inline-link:hover {
            color: var(--gold-hover);
        }

        .legal-update {
            display: inline-block;
            background: var(--gold-light);
            color: var(--navy);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 25px;
        }

        .contact-box {
            background: var(--navy);
            color: #fff;
            padding: 30px;
            border-radius: var(--radius);
            margin-top: 45px;
            text-align: center;
        }

        .contact-box h3 {
            font-family: 'Playfair Display', serif;
            color: var(--gold);
            font-size: 1.3rem;
            margin-bottom: 10px;
        }

        .contact-box p {
            color: rgba(255, 255, 255, 0.85);
            margin-bottom: 18px;
        }

        .contact-box .btn-gold {
            background: var(--gold);
            color: #fff;
        }

        .contact-box .btn-gold:hover {
            background: var(--gold-hover);
        }

                /* ===== FOOTER (idénticos al index) ===== */
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

            .legal-hero h1 {
                font-size: 1.9rem;
            }

            .legal-content {
                padding: 50px 5%;
            }

            .legal-content h2 {
                font-size: 1.3rem;
            }
                        footer {
                flex-direction: column;
                text-align: center;
            }

            .footer-contact {
                justify-content: center;
            }
        }
    </style>
</head>
<body>

    <?php include 'modulos/navbar.php'; ?>

    <!-- ===== HERO ===== -->
    <section class="legal-hero">
        <div class="tag"><i class="fas fa-shield-halved"></i> Documento legal</div>
        <h1>Política de Privacidad</h1>
        <p>Vera Terra Inmobiliaria &mdash; Última actualización: <?php echo date('d/m/Y'); ?></p>
    </section>

    <!-- ===== CONTENIDO ===== -->
    <section class="legal-content">
        <div class="container">

            <div class="intro">
                En <strong>Vera Terra Inmobiliaria</strong> valoramos y respetamos tu privacidad. Esta Política de Privacidad describe cómo recopilamos, usamos, almacenamos y protegemos tu información personal cuando utilizas nuestro sitio web, nuestras aplicaciones y nuestros servicios inmobiliarios.
            </div>

            <span class="legal-update">Vigente a partir del <?php echo "2 de Octubre de 2026"; ?></span>

            <h2>1. Información que recopilamos</h2>
            <p>Recopilamos información personal que nos proporcionas de forma voluntaria cuando:</p>
            <ul>
                <li>Solicitas una asesoría o cotización a través de nuestros formularios.</li>
                <li>Te registras para recibir información sobre propiedades.</li>
                <li>Nos contactas por WhatsApp, correo electrónico o teléfono.</li>
                <li>Publicas o vendes una propiedad con nosotros.</li>
                <li>Interactúas con nuestras páginas en redes sociales (Facebook, Instagram, Meta).</li>
            </ul>

            <h3>Datos que podemos solicitar:</h3>
            <ul>
                <li><strong>Datos de identificación:</strong> nombre completo, correo electrónico, número telefónico.</li>
                <li><strong>Datos de la propiedad:</strong> dirección, tipo de inmueble, metros cuadrados, características.</li>
                <li><strong>Datos de navegación:</strong> dirección IP, tipo de dispositivo, navegador, páginas visitadas y cookies.</li>
                <li><strong>Datos de interacción en Meta:</strong> cuando interactúas con nuestros anuncios o páginas, Meta puede compartir con nosotros información agregada y anónima sobre el rendimiento de las campañas.</li>
            </ul>

            <h2>2. Uso de la información</h2>
            <p>Utilizamos tus datos personales para las siguientes finalidades:</p>
            <ul>
                <li>Brindarte asesoría inmobiliaria personalizada (compra, venta, avalúos, remodelaciones).</li>
                <li>Gestionar solicitudes de información, citas y seguimiento comercial.</li>
                <li>Enviarte información sobre propiedades que coincidan con tus intereses.</li>
                <li>Mejorar nuestros servicios, sitio web y experiencia de usuario.</li>
                <li>Cumplir con obligaciones legales, fiscales y contractuales.</li>
                <li>Mostrar publicidad relevante en plataformas de Meta (Facebook e Instagram) a través de audiencias personalizadas.</li>
            </ul>

            <h2>3. Base legal del tratamiento</h2>
            <p>Tratamos tus datos con base en:</p>
            <ul>
                <li>Tu <strong>consentimiento</strong> expreso al enviar formularios o contactarnos.</li>
                <li>La <strong>ejecución de un contrato</strong> cuando contratas nuestros servicios.</li>
                <li>El <strong>interés legítimo</strong> de ofrecerte servicios relacionados con tu búsqueda inmobiliaria.</li>
                <li>El <strong>cumplimiento de obligaciones legales</strong> aplicables en México.</li>
            </ul>

            <h2>4. Compartición con terceros</h2>
            <p><strong>No vendemos ni rentamos tus datos personales.</strong> Podemos compartir tu información únicamente con:</p>
            <ul>
                <li><strong>Proveedores de servicios tecnológicos:</strong> hosting, correo electrónico, CRM, herramientas de análisis.</li>
                <li><strong>Meta Platforms, Inc.:</strong> cuando interactúas con nuestros anuncios o páginas, sujeto a las políticas de Meta.</li>
                <li><strong>Autoridades competentes:</strong> cuando sea requerido por ley o para proteger derechos legales.</li>
                <li><strong>Notarías, bancos o instituciones financieras:</strong> cuando sea necesario para concretar una operación inmobiliaria.</li>
            </ul>

            <h2>5. Uso de cookies y tecnologías similares</h2>
            <p>Nuestro sitio web utiliza cookies y tecnologías similares para:</p>
            <ul>
                <li>Recordar tus preferencias de navegación.</li>
                <li>Analizar el tráfico y comportamiento de los usuarios.</li>
                <li>Medir la efectividad de nuestras campañas publicitarias en Meta y Google.</li>
            </ul>
            <p>Puedes desactivar las cookies desde la configuración de tu navegador. Sin embargo, algunas funcionalidades del sitio podrían no operar correctamente.</p>

            <h2>6. Píxel de Meta y herramientas de seguimiento</h2>
            <p>Utilizamos el <strong>Píxel de Meta</strong> y otras herramientas de seguimiento para:</p>
            <ul>
                <li>Medir conversiones y rendimiento de anuncios.</li>
                <li>Crear audiencias personalizadas para publicidad en Facebook e Instagram.</li>
                <li>Optimizar la entrega de anuncios a usuarios con intereses similares.</li>
            </ul>
            <p>Estas herramientas pueden recopilar datos como dirección IP, identificadores de dispositivo y eventos de navegación. Meta actúa como responsable independiente del tratamiento de estos datos, conforme a su propia <a href="https://www.facebook.com/privacy/policy/" target="_blank" rel="noopener" class="inline-link">Política de Privacidad</a>.</p>

            <h2>7. Conservación de datos</h2>
            <p>Conservamos tus datos personales únicamente durante el tiempo necesario para cumplir con las finalidades descritas en esta política, o bien durante los plazos establecidos por la legislación aplicable. Posteriormente, los eliminamos o anonimizamos de forma segura.</p>

            <h2>8. Tus derechos (Derechos ARCO)</h2>
            <p>De conformidad con la <strong>Ley Federal de Protección de Datos Personales en Posesión de los Particulares (LFPDPPP)</strong> de México, tienes derecho a:</p>
            <ul>
                <li><strong>Acceder</strong> a los datos personales que tenemos sobre ti.</li>
                <li><strong>Rectificar</strong> tus datos cuando sean inexactos o incompletos.</li>
                <li><strong>Cancelar</strong> tus datos cuando consideres que no son necesarios para las finalidades señaladas.</li>
                <li><strong>Oponerte</strong> al tratamiento de tus datos para fines específicos.</li>
                <li><strong>Revocar</strong> el consentimiento que nos hayas otorgado.</li>
            </ul>
            <p>Para ejercer cualquiera de estos derechos, envía una solicitud a: <a href="mailto:atencion@veraterra.com" class="inline-link">atencion@veraterra.com</a></p>

            <h2>9. Seguridad de la información</h2>
            <p>Implementamos medidas técnicas, administrativas y físicas razonables para proteger tus datos personales contra accesos no autorizados, pérdida, alteración o destrucción. Sin embargo, ningún sistema es completamente seguro, por lo que no podemos garantizar la seguridad absoluta de la información transmitida por internet.</p>

            <h2>10. Menores de edad</h2>
            <p>Nuestros servicios están dirigidos a personas mayores de 18 años. No recopilamos intencionalmente datos de menores de edad. Si detectamos que hemos recopilado información de un menor sin consentimiento de sus padres o tutores, procederemos a eliminarla.</p>

            <h2>11. Cambios a esta Política de Privacidad</h2>
            <p>Nos reservamos el derecho de actualizar esta Política de Privacidad en cualquier momento. Los cambios entrarán en vigor a partir de su publicación en este sitio web. Te recomendamos revisarla periódicamente.</p>

            <h2>12. Contacto</h2>
            <p>Si tienes dudas, comentarios o solicitudes relacionadas con esta Política de Privacidad, puedes contactarnos a través de:</p>
            <ul>
                <li><strong>Correo electrónico:</strong> <a href="mailto:atencion@veraterra.com" class="inline-link">atencion@veraterra.com</a></li>
                <li><strong>WhatsApp:</strong> <a href="https://wa.me/523311586937" target="_blank" rel="noopener" class="inline-link">+52 33 1158 6937</a></li>
                <li><strong>Sitio web:</strong> <a href="contacto.php" class="inline-link">Formulario de contacto</a></li>
            </ul>

            <div class="contact-box">
                <h3>¿Tienes dudas sobre tus datos?</h3>
                <p>Estamos aquí para ayudarte. Contáctanos y te responderemos a la brevedad.</p>
                <a href="contacto.php" class="btn-gold"><i class="fas fa-envelope"></i> Contactar a Vera Terra</a>
            </div>

        </div>
    </section>

    <?php include 'modulos/footer.php'; ?>

    <!-- ===== SCRIPT para efecto de scroll en navbar (idéntico al index) ===== -->
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