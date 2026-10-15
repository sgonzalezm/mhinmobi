<?php
session_start();

// ===== DEPURACIÓN (quitar en producción) =====
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ===== CONEXIÓN PDO =====
require_once 'includes/conexion.php';

// ===== FUNCIONES AUXILIARES =====
function limpiar($valor) {
    return htmlspecialchars(trim($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

// ========================================
// PROCESAR ENVÍO DEL FORMULARIO
// ========================================
$errores = [];
$exito = false;
$mensaje_exito = '';

// Valores por defecto (para repoblar el formulario)
$datos = [
    'nombre'              => '',
    'telefono'            => '',
    'email'               => '',
    'tipo_propiedad'      => '',
    'operacion'           => 'venta',
    'direccion'           => '',
    'colonia'             => '',
    'ciudad'              => '',
    'm2_construccion'     => '',
    'm2_terreno'          => '',
    'recamaras'           => '',
    'banos'               => '',
    'estacionamientos'    => '',
    'antiguedad'          => '',
    'condiciones_fisicas' => '',
    'situacion_actual'    => '',
    'nss'                 => '',
    'cuenta_agua'         => '',
    'cuenta_predial'      => '',
    'tiene_adeudo'        => 0,
    'monto_adeudo'        => '',
    'concepto_adeudo'     => '',
    'ganancias_esperadas' => '',
    'precio_libre_gastos' => '',
    'urgencia_venta'      => '',
    'referido_por'        => 0,
    'nombre_referidor'    => '',
    'comentarios'         => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Recoger y limpiar datos
    foreach ($datos as $key => $default) {
        if ($key === 'tiene_adeudo' || $key === 'referido_por') {
            $datos[$key] = isset($_POST[$key]) ? 1 : 0;
        } else {
            $datos[$key] = limpiar($_POST[$key] ?? '');
        }
    }

    // ========================================
    // VALIDACIONES
    // ========================================
    if (empty($datos['nombre'])) {
        $errores[] = 'El nombre es obligatorio.';
    }
    if (empty($datos['telefono'])) {
        $errores[] = 'El teléfono es obligatorio.';
    }
    if (empty($datos['email']) || !filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El correo electrónico es obligatorio y debe ser válido.';
    }
    if (empty($datos['tipo_propiedad'])) {
        $errores[] = 'Selecciona el tipo de propiedad.';
    }
    if (empty($datos['direccion'])) {
        $errores[] = 'La dirección es obligatoria.';
    }
    if (empty($datos['ciudad'])) {
        $errores[] = 'La ciudad es obligatoria.';
    }
    if (empty($datos['precio_libre_gastos'])) {
        $errores[] = 'Indica cuánto deseas por la propiedad libre de gastos.';
    }
    if (empty($datos['urgencia_venta'])) {
        $errores[] = 'Selecciona la urgencia de la venta.';
    }

    // ========================================
    // GUARDAR EN BASE DE DATOS
    // ========================================
    if (empty($errores) && $conn) {
        try {
            $sql = "INSERT INTO evaluaciones_propiedades (
                nombre, telefono, email,
                tipo_propiedad, operacion, direccion, colonia, ciudad,
                m2_construccion, m2_terreno, recamaras, banos, estacionamientos,
                antiguedad, condiciones_fisicas,
                situacion_actual, nss, cuenta_agua, cuenta_predial,
                tiene_adeudo, monto_adeudo, concepto_adeudo,
                ganancias_esperadas, precio_libre_gastos,
                urgencia_venta,
                referido_por, nombre_referidor,
                comentarios,
                fecha_registro
            ) VALUES (
                :nombre, :telefono, :email,
                :tipo_propiedad, :operacion, :direccion, :colonia, :ciudad,
                :m2_construccion, :m2_terreno, :recamaras, :banos, :estacionamientos,
                :antiguedad, :condiciones_fisicas,
                :situacion_actual, :nss, :cuenta_agua, :cuenta_predial,
                :tiene_adeudo, :monto_adeudo, :concepto_adeudo,
                :ganancias_esperadas, :precio_libre_gastos,
                :urgencia_venta,
                :referido_por, :nombre_referidor,
                :comentarios,
                NOW()
            )";

            $stmt = $conn->prepare($sql);
            $stmt->execute($datos);

            $exito = true;
            $mensaje_exito = '¡Gracias! Hemos recibido la información de tu propiedad. Un asesor se pondrá en contacto contigo a la brevedad.';

            // Limpiar datos después de guardar
            foreach ($datos as $key => $value) {
                if (is_int($value)) {
                    $datos[$key] = 0;
                } else {
                    $datos[$key] = '';
                }
            }
            $datos['operacion'] = 'venta';

        } catch (PDOException $e) {
            error_log("Error al guardar evaluación: " . $e->getMessage());
            $errores[] = 'Hubo un error al guardar tu información. Por favor intenta de nuevo.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Sube tu Propiedad | Vera Terra Inmobiliaria</title>
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

        html { scroll-behavior: smooth; }

        body {
            font-family: 'Montserrat', sans-serif;
            background: var(--light-bg);
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
            font-family: 'Montserrat', sans-serif;
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
            font-family: 'Montserrat', sans-serif;
        }

        .btn-outline-gold:hover {
            background: var(--gold);
            color: #fff;
        }

        /* ===== HEADER / NAVBAR (mismo estilo landing) ===== */
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
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
            font-size: 1.25rem;
            color: var(--navy);
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

        nav a:hover { color: var(--gold); }

        /* ===== HERO DEL FORMULARIO ===== */
        .form-hero {
            position: relative;
            padding: 60px 5% 50px;
            background: linear-gradient(rgba(11, 31, 58, 0.85), rgba(11, 31, 58, 0.9)),
                        url('https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?auto=format&fit=crop&w=1600&q=80') center/cover no-repeat;
            color: #fff;
            text-align: center;
        }

        .form-hero .tag {
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

        .form-hero h1 {
            font-family: 'Playfair Display', serif;
            font-size: 2.6rem;
            line-height: 1.2;
            font-weight: 700;
            margin-bottom: 15px;
        }

        .form-hero h1 span { color: var(--gold); }

        .form-hero p {
            font-size: 1rem;
            font-weight: 300;
            opacity: 0.9;
            max-width: 620px;
            margin: 0 auto;
        }

        /* ===== CONTENEDOR DEL FORMULARIO ===== */
        .form-wrapper {
            max-width: 900px;
            margin: -40px auto 60px;
            padding: 0 20px;
            position: relative;
            z-index: 5;
        }

        .form-card {
            background: #fff;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 50px 55px;
            border-top: 5px solid var(--gold);
        }

        /* ===== MENSAJES ===== */
        .alert {
            padding: 16px 20px;
            border-radius: var(--radius);
            margin-bottom: 25px;
            font-size: 0.9rem;
        }

        .alert-error {
            background: #fdf2f2;
            border-left: 4px solid #dc3545;
            color: #721c24;
        }

        .alert-error ul {
            margin: 0;
            padding-left: 20px;
        }

        .alert-success {
            background: #f0f9f4;
            border-left: 4px solid #28a745;
            color: #155724;
            text-align: center;
            padding: 30px 25px;
        }

        .alert-success i {
            font-size: 3rem;
            color: #28a745;
            margin-bottom: 15px;
            display: block;
        }

        .alert-success h3 {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem;
            color: var(--navy);
            margin-bottom: 10px;
        }

        .alert-success p { color: var(--text-muted); }

        /* ===== SECCIONES DEL FORMULARIO ===== */
        .form-section {
            margin-bottom: 45px;
            padding-bottom: 35px;
            border-bottom: 1px solid #eee;
        }

        .form-section:last-of-type {
            border-bottom: none;
            margin-bottom: 20px;
            padding-bottom: 0;
        }

        .form-section-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 25px;
        }

        .form-section-number {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: var(--gold-light);
            color: var(--gold);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .form-section-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.4rem;
            color: var(--navy);
            font-weight: 700;
            margin: 0;
        }

        .form-section-subtitle {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        /* ===== CAMPOS ===== */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--navy);
            margin-bottom: 8px;
            letter-spacing: 0.2px;
        }

        .form-group label .required {
            color: #dc3545;
            margin-left: 3px;
        }

        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="tel"],
        .form-group input[type="number"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1.5px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.9rem;
            font-family: 'Montserrat', sans-serif;
            color: var(--text-dark);
            background: #fff;
            transition: border-color var(--transition), box-shadow var(--transition);
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--gold);
            box-shadow: 0 0 0 3px rgba(197, 160, 89, 0.12);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-group small {
            display: block;
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 6px;
            font-style: italic;
        }

        /* ===== CHECKBOX CUSTOM ===== */
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 18px;
            background: var(--light-bg);
            border-radius: 8px;
            border: 1.5px solid #e0e0e0;
            cursor: pointer;
            transition: border-color var(--transition), background var(--transition);
            margin-bottom: 20px;
        }

        .checkbox-group:hover {
            border-color: var(--gold);
            background: var(--gold-light);
        }

        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            accent-color: var(--gold);
            cursor: pointer;
            flex-shrink: 0;
        }

        .checkbox-group label {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--navy);
            cursor: pointer;
            margin: 0;
        }

        /* ===== SUBGRUPOS CONDICIONALES ===== */
        .conditional-group {
            padding: 22px 25px;
            background: var(--light-bg);
            border-radius: 8px;
            border-left: 4px solid var(--gold);
            margin-bottom: 20px;
        }

        .conditional-group .form-group:last-child {
            margin-bottom: 0;
        }

        /* ===== BOTÓN ENVÍO ===== */
        .form-submit-wrapper {
            text-align: center;
            margin-top: 35px;
            padding-top: 30px;
            border-top: 2px solid var(--gold-light);
        }

        .form-submit-wrapper .btn-gold {
            padding: 16px 50px;
            font-size: 1rem;
        }

        .form-submit-wrapper .btn-gold i {
            margin-right: 8px;
        }

        .form-submit-note {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 15px;
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

            .form-hero {
                padding: 40px 5% 60px;
            }

            .form-hero h1 {
                font-size: 1.8rem;
            }

            .form-card {
                padding: 30px 22px;
            }

            .form-row,
            .form-row-3 {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .form-section-title {
                font-size: 1.15rem;
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
            .form-hero h1 {
                font-size: 1.5rem;
            }

            .form-submit-wrapper .btn-gold {
                width: 100%;
                padding: 14px 20px;
            }
        }
    </style>
</head>
<body>

    <!-- ===== NAVBAR ===== -->
    <?php if (file_exists('modulos/navbar.php')): ?>
        <?php include 'modulos/navbar.php'; ?>
    <?php else: ?>
        <header>
            <a href="index.php" class="logo">
                <div class="logo-icon">VT</div>
                <div class="logo-text">
                    Vera Terra
                    <span>Inmobiliaria</span>
                </div>
            </a>
            <nav>
                <ul>
                    <li><a href="index.php">Inicio</a></li>
                    <li><a href="propiedades.php">Propiedades</a></li>
                    <li><a href="vender.php" class="active">Vender</a></li>
                    <li><a href="contacto.php">Contacto</a></li>
                </ul>
            </nav>
        </header>
    <?php endif; ?>

    <!-- ===== HERO DEL FORMULARIO ===== -->
    <section class="form-hero">
        <div class="tag"><i class="fas fa-star"></i> Evaluación sin costo</div>
        <h1>Sube tu propiedad y recibe una <span>evaluación gratuita</span></h1>
        <p>Completa el formulario y un asesor se pondrá en contacto contigo. Sin compromiso, sin complicaciones.</p>
    </section>

    <!-- ===== FORMULARIO ===== -->
    <div class="form-wrapper">
        <div class="form-card">

            <?php if (!empty($errores)): ?>
                <div class="alert alert-error">
                    <ul>
                        <?php foreach ($errores as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($exito): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <h3>¡Gracias por confiar en nosotros!</h3>
                    <p><?php echo htmlspecialchars($mensaje_exito); ?></p>
                </div>
            <?php else: ?>

            <form method="POST" action="" id="formPropiedad">

                <!-- ========================================
                     SECCIÓN 1: DATOS DE CONTACTO
                     ======================================== -->
                <div class="form-section">
                    <div class="form-section-header">
                        <div class="form-section-number">1</div>
                        <div>
                            <h2 class="form-section-title">Tus datos de contacto</h2>
                            <p class="form-section-subtitle">Para poder contactarte y darte seguimiento</p>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="nombre">Nombre completo <span class="required">*</span></label>
                            <input type="text" id="nombre" name="nombre" required
                                   value="<?php echo htmlspecialchars($datos['nombre']); ?>"
                                   placeholder="Ej: Juan Pérez López">
                        </div>
                        <div class="form-group">
                            <label for="telefono">Teléfono / WhatsApp <span class="required">*</span></label>
                            <input type="tel" id="telefono" name="telefono" required
                                   value="<?php echo htmlspecialchars($datos['telefono']); ?>"
                                   placeholder="Ej: 331 123 4567">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="email">Correo electrónico <span class="required">*</span></label>
                        <input type="email" id="email" name="email" required
                               value="<?php echo htmlspecialchars($datos['email']); ?>"
                               placeholder="tucorreo@ejemplo.com">
                    </div>
                </div>

                <!-- ========================================
                     SECCIÓN 2: LA PROPIEDAD
                     ======================================== -->
                <div class="form-section">
                    <div class="form-section-header">
                        <div class="form-section-number">2</div>
                        <div>
                            <h2 class="form-section-title">Cuéntanos de tu propiedad</h2>
                            <p class="form-section-subtitle">Características principales del inmueble</p>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="tipo_propiedad">Tipo de propiedad <span class="required">*</span></label>
                            <select id="tipo_propiedad" name="tipo_propiedad" required>
                                <option value="">Selecciona...</option>
                                <option value="casa" <?php echo $datos['tipo_propiedad'] === 'casa' ? 'selected' : ''; ?>>Casa</option>
                                <option value="departamento" <?php echo $datos['tipo_propiedad'] === 'departamento' ? 'selected' : ''; ?>>Departamento</option>
                                <option value="terreno" <?php echo $datos['tipo_propiedad'] === 'terreno' ? 'selected' : ''; ?>>Terreno</option>
                                <option value="local" <?php echo $datos['tipo_propiedad'] === 'local' ? 'selected' : ''; ?>>Local comercial</option>
                                <option value="bodega" <?php echo $datos['tipo_propiedad'] === 'bodega' ? 'selected' : ''; ?>>Bodega</option>
                                <option value="otro" <?php echo $datos['tipo_propiedad'] === 'otro' ? 'selected' : ''; ?>>Otro</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="operacion">¿Qué deseas hacer?</label>
                            <select id="operacion" name="operacion">
                                <option value="venta" <?php echo $datos['operacion'] === 'venta' ? 'selected' : ''; ?>>Vender</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="direccion">Dirección (calle y número) <span class="required">*</span></label>
                        <input type="text" id="direccion" name="direccion" required
                               value="<?php echo htmlspecialchars($datos['direccion']); ?>"
                               placeholder="Ej: Av. Vallarta 1234">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="colonia">Colonia</label>
                            <input type="text" id="colonia" name="colonia"
                                   value="<?php echo htmlspecialchars($datos['colonia']); ?>"
                                   placeholder="Ej: Centro">
                        </div>
                        <div class="form-group">
                            <label for="ciudad">Ciudad / Municipio <span class="required">*</span></label>
                            <input type="text" id="ciudad" name="ciudad" required
                                   value="<?php echo htmlspecialchars($datos['ciudad']); ?>"
                                   placeholder="Ej: Guadalajara">
                        </div>
                    </div>

                    <div class="form-row-3">
                        <div class="form-group">
                            <label for="m2_construccion">M² construcción</label>
                            <input type="number" id="m2_construccion" name="m2_construccion" min="0" step="0.01"
                                   value="<?php echo htmlspecialchars($datos['m2_construccion']); ?>"
                                   placeholder="0">
                        </div>
                        <div class="form-group">
                            <label for="m2_terreno">M² terreno</label>
                            <input type="number" id="m2_terreno" name="m2_terreno" min="0" step="0.01"
                                   value="<?php echo htmlspecialchars($datos['m2_terreno']); ?>"
                                   placeholder="0">
                        </div>
                        <div class="form-group">
                            <label for="antiguedad">Antigüedad (años)</label>
                            <input type="number" id="antiguedad" name="antiguedad" min="0"
                                   value="<?php echo htmlspecialchars($datos['antiguedad']); ?>"
                                   placeholder="0">
                        </div>
                    </div>

                    <div class="form-row-3">
                        <div class="form-group">
                            <label for="recamaras">Recámaras</label>
                            <input type="number" id="recamaras" name="recamaras" min="0"
                                   value="<?php echo htmlspecialchars($datos['recamaras']); ?>"
                                   placeholder="0">
                        </div>
                        <div class="form-group">
                            <label for="banos">Baños</label>
                            <input type="number" id="banos" name="banos" min="0" step="0.5"
                                   value="<?php echo htmlspecialchars($datos['banos']); ?>"
                                   placeholder="0">
                        </div>
                        <div class="form-group">
                            <label for="estacionamientos">Estacionamientos</label>
                            <input type="number" id="estacionamientos" name="estacionamientos" min="0"
                                   value="<?php echo htmlspecialchars($datos['estacionamientos']); ?>"
                                   placeholder="0">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="condiciones_fisicas">Condiciones físicas actuales</label>
                        <select id="condiciones_fisicas" name="condiciones_fisicas">
                            <option value="">Selecciona...</option>
                            <option value="excelente" <?php echo $datos['condiciones_fisicas'] === 'excelente' ? 'selected' : ''; ?>>Excelente - Como nueva</option>
                            <option value="buena" <?php echo $datos['condiciones_fisicas'] === 'buena' ? 'selected' : ''; ?>>Buena - Bien mantenida</option>
                            <option value="regular" <?php echo $datos['condiciones_fisicas'] === 'regular' ? 'selected' : ''; ?>>Regular - Necesita mantenimiento</option>
                            <option value="mala" <?php echo $datos['condiciones_fisicas'] === 'mala' ? 'selected' : ''; ?>>Mala - Requiere remodelación</option>
                            <option value="obra_negra" <?php echo $datos['condiciones_fisicas'] === 'obra_negra' ? 'selected' : ''; ?>>En Obra negra</option>
                        </select>
                    </div>
                </div>

                <!-- ========================================
                     SECCIÓN 3: SITUACIÓN ACTUAL Y DOCUMENTOS
                     ======================================== -->
                <div class="form-section">
                    <div class="form-section-header">
                        <div class="form-section-number">3</div>
                        <div>
                            <h2 class="form-section-title">Situación actual y documentos</h2>
                            <p class="form-section-subtitle">Estado actual del inmueble y datos opcionales</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="situacion_actual">¿Cuál es la situación actual de la propiedad?</label>
                        <select id="situacion_actual" name="situacion_actual">
                            <option value="">Selecciona...</option>
                            <option value="habitada" <?php echo $datos['situacion_actual'] === 'habitada' ? 'selected' : ''; ?>>Habitada (por ti o familiar)</option>
                            <option value="sola" <?php echo $datos['situacion_actual'] === 'sola' ? 'selected' : ''; ?>>Sola / Vacía</option>
                            <option value="rentada" <?php echo $datos['situacion_actual'] === 'rentada' ? 'selected' : ''; ?>>Rentada</option>
                            <option value="invadida" <?php echo $datos['situacion_actual'] === 'invadida' ? 'selected' : ''; ?>>Invadida</option>
                            <option value="vandalizada" <?php echo $datos['situacion_actual'] === 'vandalizada' ? 'selected' : ''; ?>>Vandalizada</option>
                            <option value="en_tramite" <?php echo $datos['situacion_actual'] === 'en_tramite' ? 'selected' : ''; ?>>En trámite legal</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="nss">Número de Seguridad Social (NSS)</label>
                            <input type="text" id="nss" name="nss" value="<?php echo htmlspecialchars($datos['nss']); ?>"
                                   placeholder="Opcional - Ej: 12345678901">
                        </div>
                        <div class="form-group">
                            <label for="cuenta_agua">Número de cuenta de agua</label>
                            <input type="text" id="cuenta_agua" name="cuenta_agua" value="<?php echo htmlspecialchars($datos['cuenta_agua']); ?>"
                                    placeholder="Opcional - Ej: 1234567890">
                        </div>
                        <div class="form-group">
                            <label for="cuenta_predial">Número de cuenta predial</label>
                            <input type="text" id="cuenta_predial" name="cuenta_predial" value="<?php echo htmlspecialchars($datos['cuenta_predial']); ?>"
                                   placeholder="Opcional - Ej: 1234567890">
                        </div>
                    </div>
                </div>

                <!-- ========================================
                     SECCIÓN 4: ASPECTOS FINANCIEROS
                     ======================================== -->
                <div class="form-section">
                    <div class="form-section-header">
                        <div class="form-section-number">4</div>
                        <div>
                            <h2 class="form-section-title">Aspectos financieros</h2>
                            <p class="form-section-subtitle">Información sobre adeudos y expectativas de venta</p>
                        </div>
                    </div>

                    <label class="checkbox-group" for="tiene_adeudo">
                        <input type="checkbox" name="tiene_adeudo" value="1" id="tiene_adeudo"
                               <?php echo !empty($datos['tiene_adeudo']) ? 'checked' : ''; ?>>
                        <label for="tiene_adeudo">¿La propiedad tiene algún adeudo?</label>
                    </label>

                    <div id="adeudoDetalles" class="conditional-group" style="<?php echo empty($datos['tiene_adeudo']) ? 'display:none;' : ''; ?>">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="monto_adeudo">¿Cuánto se debe?</label>
                                <input type="number" id="monto_adeudo" name="monto_adeudo" min="0" step="0.01"
                                       value="<?php echo htmlspecialchars($datos['monto_adeudo']); ?>"
                                       placeholder="0.00">
                            </div>
                            <div class="form-group">
                                <label for="concepto_adeudo">Concepto del adeudo</label>
                                <input type="text" id="concepto_adeudo" name="concepto_adeudo"
                                       value="<?php echo htmlspecialchars($datos['concepto_adeudo']); ?>"
                                       placeholder="Ej: Hipoteca, impuestos, servicios...">
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="ganancias_esperadas">¿Cuánto esperas ganar con la venta?</label>
                            <input type="number" id="ganancias_esperadas" name="ganancias_esperadas" min="0" step="0.01"
                                   value="<?php echo htmlspecialchars($datos['ganancias_esperadas']); ?>"
                                   placeholder="Opcional">
                        </div>
                        <div class="form-group">
                            <label for="precio_libre_gastos">¿Cuánto deseas por la propiedad libre de gastos? <span class="required">*</span></label>
                            <input type="number" id="precio_libre_gastos" name="precio_libre_gastos" min="0" step="0.01" required
                                   value="<?php echo htmlspecialchars($datos['precio_libre_gastos']); ?>"
                                   placeholder="0.00">
                            <small>Monto que quieres recibir tú, sin descuentos ni comisiones.</small>
                        </div>
                    </div>
                </div>

                <!-- ========================================
                     SECCIÓN 5: URGENCIA
                     ======================================== -->
                <div class="form-section">
                    <div class="form-section-header">
                        <div class="form-section-number">5</div>
                        <div>
                            <h2 class="form-section-title">Urgencia de la venta</h2>
                            <p class="form-section-subtitle">Nos ayuda a priorizar tu atención</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="urgencia_venta">¿Qué tan urgente es para ti vender? <span class="required">*</span></label>
                        <select id="urgencia_venta" name="urgencia_venta" required>
                            <option value="">Selecciona...</option>
                            <option value="inmediata" <?php echo $datos['urgencia_venta'] === 'inmediata' ? 'selected' : ''; ?>>Inmediata (menos de 1 mes)</option>
                            <option value="alta" <?php echo $datos['urgencia_venta'] === 'alta' ? 'selected' : ''; ?>>Alta (1 a 3 meses)</option>
                            <option value="media" <?php echo $datos['urgencia_venta'] === 'media' ? 'selected' : ''; ?>>Media (3 a 6 meses)</option>
                            <option value="baja" <?php echo $datos['urgencia_venta'] === 'baja' ? 'selected' : ''; ?>>Baja (más de 6 meses)</option>
                            <option value="solo_informacion" <?php echo $datos['urgencia_venta'] === 'solo_informacion' ? 'selected' : ''; ?>>Solo quiero información</option>
                        </select>
                    </div>
                </div>

                <!-- ========================================
                     SECCIÓN 6: PROGRAMA DE REFERIDOS
                     ======================================== -->
                <div class="form-section">
                    <div class="form-section-header">
                        <div class="form-section-number">6</div>
                        <div>
                            <h2 class="form-section-title">Programa de referidos</h2>
                            <p class="form-section-subtitle">Si alguien te recomendó, dinos quién para agradecerle</p>
                        </div>
                    </div>

                    <label class="checkbox-group" for="referido_por">
                        <input type="checkbox" name="referido_por" value="1" id="referido_por"
                               <?php echo !empty($datos['referido_por']) ? 'checked' : ''; ?>>
                        <label for="referido_por">Sí, alguien me recomendó</label>
                    </label>

                    <div id="referidoDetalles" class="conditional-group" style="<?php echo empty($datos['referido_por']) ? 'display:none;' : ''; ?>">
                        <div class="form-group">
                            <label for="nombre_referidor">Nombre de quien te recomendó</label>
                            <input type="text" id="nombre_referidor" name="nombre_referidor"
                                   value="<?php echo htmlspecialchars($datos['nombre_referidor']); ?>"
                                   placeholder="Nombre completo o teléfono">
                        </div>
                    </div>
                </div>

                <!-- ========================================
                     SECCIÓN 7: COMENTARIOS
                     ======================================== -->
                <div class="form-section">
                    <div class="form-section-header">
                        <div class="form-section-number">7</div>
                        <div>
                            <h2 class="form-section-title">Comentarios adicionales</h2>
                            <p class="form-section-subtitle">Opcional - ¿Algo más que debamos saber?</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="comentarios">Comentarios</label>
                        <textarea id="comentarios" name="comentarios" rows="4"
                                  placeholder="Cuéntanos cualquier detalle adicional sobre tu propiedad..."><?php echo htmlspecialchars($datos['comentarios']); ?></textarea>
                    </div>
                </div>

                <!-- ========================================
                     BOTÓN DE ENVÍO
                     ======================================== -->
                <div class="form-submit-wrapper">
                    <button type="submit" class="btn-gold">
                        <i class="fas fa-paper-plane"></i> Enviar mi propiedad para evaluación
                    </button>
                    <p class="form-submit-note">
                        <i class="fas fa-shield-alt"></i> Tus datos están seguros. No compartimos tu información con terceros.
                    </p>
                </div>

            </form>

            <?php endif; ?>

        </div>
    </div>

    <!-- ===== FOOTER ===== -->
    <?php if (file_exists('modulos/footer.php')): ?>
        <?php include 'modulos/footer.php'; ?>
    <?php else: ?>
        <footer>
            <div class="footer-logo">
                <div class="logo-icon">VT</div>
                Vera Terra
            </div>
            <div class="footer-contact">
                <span><i class="fas fa-phone"></i> 331 158 6937</span>
                <span><i class="fas fa-envelope"></i> contacto@veraterra.com</span>
            </div>
            <div class="social-links">
                <a href="#" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                <a href="#" aria-label="WhatsApp"><i class="fab fa-whatsapp"></i></a>
            </div>
        </footer>
    <?php endif; ?>

    <!-- ===== SCRIPT PARA CAMPOS CONDICIONALES ===== -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {

            // Mostrar/ocultar detalles del adeudo
            var tieneAdeudo = document.getElementById('tiene_adeudo');
            var adeudoDetalles = document.getElementById('adeudoDetalles');
            if (tieneAdeudo && adeudoDetalles) {
                tieneAdeudo.addEventListener('change', function() {
                    adeudoDetalles.style.display = this.checked ? 'block' : 'none';
                });
            }

            // Mostrar/ocultar detalles del referido
            var referidoPor = document.getElementById('referido_por');
            var referidoDetalles = document.getElementById('referidoDetalles');
            if (referidoPor && referidoDetalles) {
                referidoPor.addEventListener('change', function() {
                    referidoDetalles.style.display = this.checked ? 'block' : 'none';
                });
            }

            // Smooth scroll al inicio si hay errores
            <?php if (!empty($errores)): ?>
                window.scrollTo({ top: 0, behavior: 'smooth' });
            <?php endif; ?>

        });
    </script>

</body>
</html>