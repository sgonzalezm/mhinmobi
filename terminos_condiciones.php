<?php
// terminos_condiciones.php
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Términos y Condiciones | Vera Terra Inmobiliaria</title>
    <meta name="description" content="Términos y Condiciones de uso del sitio web y servicios de Vera Terra Inmobiliaria." />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet" />
    <style>
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
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Montserrat', sans-serif; background: #ffffff; color: var(--text-dark); line-height: 1.7; }
        a { text-decoration: none; color: inherit; }
        .container { max-width: 900px; margin: 0 auto; padding: 0 20px; }
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
        .legal-hero h1 { font-family: 'Playfair Display', serif; font-size: 2.6rem; font-weight: 700; margin-bottom: 12px; }
        .legal-hero p { opacity: 0.85; font-weight: 300; font-size: 0.95rem; }
        .legal-content { padding: 70px 5%; background: #fff; }
        .legal-content .intro {
            background: var(--light-bg);
            border-left: 4px solid var(--gold);
            padding: 22px 26px;
            border-radius: var(--radius);
            margin-bottom: 45px;
            font-size: 0.95rem;
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
        .legal-content p, .legal-content li { color: var(--text-muted); font-size: 0.95rem; margin-bottom: 12px; }
        .legal-content ul { padding-left: 22px; margin-bottom: 16px; }
        .legal-content ul li::marker { color: var(--gold); }
        .legal-content strong { color: var(--navy); font-weight: 600; }
        .legal-content a.inline-link { color: var(--gold); font-weight: 600; border-bottom: 1px solid var(--gold); }
        .legal-content a.inline-link:hover { color: var(--gold-hover); }
        .legal-update {
            display: inline-block;
            background: var(--gold-light);
            color: var(--navy);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
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
        .contact-box h3 { font-family: 'Playfair Display', serif; color: var(--gold); font-size: 1.3rem; margin-bottom: 10px; }
        .contact-box p { color: rgba(255, 255, 255, 0.85); margin-bottom: 18px; }
        .contact-box .btn-gold {
            display: inline-block;
            background: var(--gold);
            color: #fff;
            padding: 12px 32px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: background var(--transition), transform var(--transition);
        }
        .contact-box .btn-gold:hover { background: var(--gold-hover); transform: translateY(-3px); }
        @media (max-width: 768px) {
            .legal-hero h1 { font-size: 1.9rem; }
            .legal-content { padding: 50px 5%; }
            .legal-content h2 { font-size: 1.3rem; }
        }
    </style>
</head>
<body>

    <section class="legal-hero">
        <div class="tag"><i class="fas fa-file-contract"></i> Documento legal</div>
        <h1>Términos y Condiciones</h1>
        <p>Vera Terra Inmobiliaria &mdash; Última actualización: <?php echo date('d/m/Y'); ?></p>
    </section>

    <section class="legal-content">
        <div class="container">

            <div class="intro">
                Bienvenido al sitio web de <strong>Vera Terra Inmobiliaria</strong>. Al acceder y utilizar este sitio, aceptas los presentes Términos y Condiciones. Si no estás de acuerdo con ellos, te pedimos no utilizar nuestros servicios.
            </div>

            <span class="legal-update">Vigente a partir del <?php echo "2 de Octubre de 2026"; ?></span>

            <h2>1. Identificación del prestador</h2>
            <p>Este sitio es operado por <strong>Vera Terra Inmobiliaria</strong>, con domicilio en Av. Chapultepec Nte. 15A, Col. Americana, Lafayette, Piso 16 Oficina 10, Guadalajara, Jalisco, 44600, México.</p>

            <h2>2. Objeto</h2>
            <p>Vera Terra Inmobiliaria ofrece servicios de intermediación inmobiliaria, asesoría en compra-venta de propiedades, avalúos, remodelaciones, trámites notariales e Infonavit, así como asesoría financiera.</p>

            <h2>3. Uso del sitio</h2>
            <p>El usuario se compromete a usar el sitio de manera lícita, sin realizar actividades que puedan dañar, sobrecargar o deteriorar el funcionamiento del mismo. Queda prohibido:</p>
            <ul>
                <li>Usar el sitio para fines fraudulentos o ilícitos.</li>
                <li>Intentar acceder sin autorización a sistemas o datos.</li>
                <li>Reproducir, copiar o distribuir el contenido sin consentimiento previo.</li>
            </ul>

            <h2>4. Propiedades y precios</h2>
            <p>La información sobre propiedades (precios, metros, ubicación, características) se proporciona con la mayor precisión posible, pero <strong>puede estar sujeta a cambios sin previo aviso</strong>. Los precios publicados son de referencia y pueden variar según condiciones de mercado, disponibilidad y negociación.</p>

            <h2>5. Registro y comunicaciones</h2>
            <p>Al enviar tus datos a través de nuestros formularios, aceptas recibir comunicaciones de nuestra parte relacionadas con tu solicitud. Puedes solicitar la baja de nuestras listas en cualquier momento escribiendo a <a href="mailto:contacto@veraterra.com" class="inline-link">contacto@veraterra.com</a>.</p>

            <h2>6. Propiedad intelectual</h2>
            <p>Todos los contenidos de este sitio (textos, imágenes, logotipos, diseños, código) son propiedad de Vera Terra Inmobiliaria o de sus respectivos titulares, y están protegidos por las leyes de propiedad intelectual aplicables en México.</p>

            <h2>7. Limitación de responsabilidad</h2>
            <p>Vera Terra Inmobiliaria no se hace responsable por:</p>
            <ul>
                <li>Daños derivados del uso indebido del sitio.</li>
                <li>Interrupciones del servicio por causas ajenas a nuestro control.</li>
                <li>Información contenida en sitios de terceros enlazados.</li>
                <li>Operaciones inmobiliarias realizadas fuera de nuestros canales oficiales.</li>
            </ul>

            <h2>8. Enlaces a terceros</h2>
            <p>Este sitio puede contener enlaces a sitios externos (Meta, WhatsApp, etc.). No somos responsables por las prácticas o contenido de esos sitios. Te recomendamos revisar sus propias políticas.</p>

            <h2>9. Modificaciones</h2>
            <p>Nos reservamos el derecho de modificar estos Términos y Condiciones en cualquier momento. Los cambios entrarán en vigor al publicarse en este sitio.</p>

            <h2>10. Legislación aplicable y jurisdicción</h2>
            <p>Estos Términos se rigen por las leyes de los Estados Unidos Mexicanos. Cualquier controversia será resuelta por los tribunales competentes de Guadalajara, Jalisco.</p>

            <h2>11. Contacto</h2>
            <p>Si tienes dudas sobre estos Términos y Condiciones, contáctanos a:</p>
            <ul>
                <li><strong>Correo electrónico:</strong> <a href="mailto:atencion@veraterra.com" class="inline-link">atencion@veraterra.com</a></li>
                <li><strong>WhatsApp:</strong> <a href="https://wa.me/523311586937" target="_blank" rel="noopener" class="inline-link">+52 33 1158 6937</a></li>
                <li><strong>Sitio web:</strong> <a href="contacto.php" class="inline-link">Formulario de contacto</a></li>
            </ul>

            <div class="contact-box">
                <h3>¿Tienes dudas?</h3>
                <p>Estamos aquí para ayudarte. Contáctanos y te responderemos a la brevedad.</p>
                <a href="contacto.php" class="btn-gold"><i class="fas fa-envelope"></i> Contactar a Vera Terra</a>
            </div>

        </div>
    </section>

</body>
</html>