<?php
// ============================================
// contacto.php - Página pública de contacto
// ============================================

// Mostrar errores (solo en desarrollo, puedes desactivar en producción)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'includes/conexion.php';

// Variables para mensajes al usuario
$mensaje_exito = '';
$mensaje_error = '';

// ============================================
// PROCESAR FORMULARIO
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_contacto'])) {
    // Procesar y sanitizar datos
    $nombre                = trim($_POST['nombre'] ?? '');
    $email                 = trim($_POST['email'] ?? '');
    $telefono              = trim($_POST['telefono'] ?? '');
    $asunto                = trim($_POST['asunto'] ?? '');
    $mensaje               = trim($_POST['mensaje'] ?? '');
    $tipo_interes          = $_POST['tipo_interes'] ?? 'otro';
    $propiedad_interes     = !empty($_POST['propiedad_interes']) ? (int)$_POST['propiedad_interes'] : null;
    $presupuesto           = !empty($_POST['presupuesto']) ? (float)$_POST['presupuesto'] : null;
    $preferencia_contacto  = $_POST['preferencia_contacto'] ?? 'cualquiera';
    $ip_address            = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent            = $_SERVER['HTTP_USER_AGENT'] ?? null;

    // Validaciones
    $errores = [];
    if (empty($nombre) || strlen($nombre) < 2) {
        $errores[] = 'El nombre debe tener al menos 2 caracteres.';
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'Ingresa un correo electrónico válido.';
    }
    if (empty($telefono) || !preg_match('/^[0-9+\-\s()]{8,20}$/', $telefono)) {
        $errores[] = 'Ingresa un número de teléfono válido (ej: 33 1234 5678).';
    }
    if (empty($asunto)) {
        $errores[] = 'El asunto es obligatorio.';
    }
    if (empty($mensaje) || strlen($mensaje) < 10) {
        $errores[] = 'El mensaje debe tener al menos 10 caracteres.';
    }

    if (empty($errores)) {
        try {
            // Verificar que la tabla contactos existe (opcional, puedes quitarlo si ya existe)
            $check = $conn->query("SHOW TABLES LIKE 'contactos'");
            if ($check->rowCount() === 0) {
                throw new Exception('La tabla "contactos" no existe. Contacta al administrador.');
            }

            $sql = "INSERT INTO contactos (
                nombre, email, telefono, asunto, mensaje,
                tipo_interes, propiedad_interes, presupuesto, preferencia_contacto,
                ip_address, user_agent, status,
                fecha_contacto, fecha_actualizacion, notas_admin
            ) VALUES (
                :nombre, :email, :telefono, :asunto, :mensaje,
                :tipo_interes, :propiedad_interes, :presupuesto, :preferencia_contacto,
                :ip_address, :user_agent, 'nuevo',
                NOW(), NOW(), NULL
            )";

            $stmt = $conn->prepare($sql);
            $result = $stmt->execute([
                ':nombre'                => $nombre,
                ':email'                 => $email,
                ':telefono'              => $telefono,
                ':asunto'                => $asunto,
                ':mensaje'               => $mensaje,
                ':tipo_interes'          => $tipo_interes,
                ':propiedad_interes'     => $propiedad_interes,
                ':presupuesto'           => $presupuesto,
                ':preferencia_contacto'  => $preferencia_contacto,
                ':ip_address'            => $ip_address,
                ':user_agent'            => $user_agent
            ]);

            if ($result) {
                $contacto_id = $conn->lastInsertId();
                $mensaje_exito = '¡Gracias por contactarnos! Nos pondremos en contacto contigo a la brevedad.';
                $_POST = []; // Limpiar formulario
            } else {
                $mensaje_error = 'Ocurrió un error al guardar tu mensaje. Intenta nuevamente.';
            }
        } catch (Exception $e) {
            error_log("Error en contacto.php: " . $e->getMessage());
            $mensaje_error = 'Error en el servidor: ' . $e->getMessage();
        }
    } else {
        $mensaje_error = implode('<br>', $errores);
    }
}

// ============================================
// OBTENER PROPIEDADES PARA EL SELECT
// ============================================
$propiedades_lista = [];
try {
    $stmt = $conn->prepare("SELECT id, title, address_city FROM properties WHERE status = 'activo' ORDER BY title ASC");
    $stmt->execute();
    $propiedades_lista = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al cargar propiedades: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Contacto - Vera Terra Inmobiliaria</title>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
    <!-- Google Fonts -->
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
        body { font-family: 'Montserrat', sans-serif; background: #ffffff; color: var(--text-dark); line-height: 1.6; }
        a { text-decoration: none; color: inherit; }
        img { max-width: 100%; display: block; }
        .container { max-width: 1200px; margin: 0 auto; padding: 0 20px; }

        /* ===== HEADER ===== */
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
        nav a:hover, nav a.active { color: var(--gold); }

        /* ===== CONTACT SECTION ===== */
        .contact-section {
            padding: 60px 5%;
            background: var(--light-bg);
        }
        .contact-wrapper {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 50px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .contact-info { padding: 40px 0; }
        .contact-info .section-title {
            font-family: 'Playfair Display', serif;
            font-size: 2.5rem;
            color: var(--navy);
            margin-bottom: 15px;
            font-weight: 700;
        }
        .contact-info .section-subtitle {
            color: var(--text-muted);
            font-size: 1rem;
            font-weight: 300;
            margin-bottom: 30px;
            line-height: 1.8;
        }
        .contact-details {
            display: flex;
            flex-direction: column;
            gap: 20px;
            margin-top: 30px;
        }
        .contact-detail-item {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            padding: 16px 20px;
            background: #fff;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            transition: transform var(--transition);
        }
        .contact-detail-item:hover { transform: translateX(5px); }
        .contact-detail-item .icon-circle {
            width: 44px;
            height: 44px;
            min-width: 44px;
            background: var(--gold-light);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gold);
            font-size: 1.1rem;
        }
        .contact-detail-item .info h4 {
            font-size: 0.9rem;
            color: var(--text-muted);
            font-weight: 500;
            margin-bottom: 2px;
        }
        .contact-detail-item .info p { font-weight: 500; color: var(--navy); }
        .contact-detail-item .info a { color: var(--navy); transition: color var(--transition); }
        .contact-detail-item .info a:hover { color: var(--gold); }

        /* ===== FORM ===== */
        .contact-form-wrapper {
            background: #fff;
            padding: 40px 35px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid rgba(197, 160, 89, 0.1);
        }
        .contact-form-wrapper h3 {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem;
            color: var(--navy);
            margin-bottom: 8px;
        }
        .contact-form-wrapper .form-subtitle {
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-bottom: 25px;
        }
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block;
            font-weight: 500;
            font-size: 0.85rem;
            color: var(--navy);
            margin-bottom: 5px;
        }
        .form-group label .required { color: #e74c3c; }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.9rem;
            color: var(--text-dark);
            transition: border-color var(--transition), box-shadow var(--transition);
            background: #fafafa;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--gold);
            box-shadow: 0 0 0 3px rgba(197, 160, 89, 0.1);
            background: #fff;
        }
        .form-group textarea { min-height: 120px; resize: vertical; }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }
        .btn-submit {
            display: inline-block;
            background: var(--gold);
            color: #fff;
            padding: 14px 40px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 1rem;
            border: none;
            cursor: pointer;
            transition: background var(--transition), transform var(--transition), box-shadow var(--transition);
            box-shadow: 0 4px 14px rgba(197, 160, 89, 0.3);
            width: 100%;
            letter-spacing: 0.5px;
        }
        .btn-submit:hover {
            background: var(--gold-hover);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(197, 160, 89, 0.4);
        }
        .btn-submit:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        /* ===== MESSAGES ===== */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .alert i { margin-right: 10px; }

        /* ===== MAPA ===== */
        .map-section { padding: 0 5% 60px; background: var(--light-bg); }
        .map-section .map-container {
            max-width: 1200px;
            margin: 0 auto;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
        }
        .map-section .map-container iframe {
            width: 100%;
            height: 400px;
            border: none;
            display: block;
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
        .footer-logo .logo-icon { width: 32px; height: 32px; font-size: 0.7rem; }
        .footer-contact {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            color: #ccc;
        }
        .footer-contact i { color: var(--gold); margin-right: 6px; }
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

        /* ===== WHATSAPP FLOATING ===== */
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
        .whatsapp-float:hover .tooltip { opacity: 1; }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 992px) {
            .contact-wrapper { grid-template-columns: 1fr; gap: 30px; }
            .contact-info { padding: 20px 0 0; }
        }
        @media (max-width: 768px) {
            header { flex-direction: column; gap: 12px; padding: 12px 5%; }
            nav ul { gap: 16px; flex-wrap: wrap; justify-content: center; }
            .contact-info .section-title { font-size: 2rem; }
            .contact-form-wrapper { padding: 25px 20px; }
            .form-row { grid-template-columns: 1fr; }
            .whatsapp-float { width: 50px; height: 50px; font-size: 1.6rem; bottom: 20px; right: 20px; }
            .whatsapp-float .tooltip { display: none; }
            footer { flex-direction: column; text-align: center; }
            .footer-contact { justify-content: center; }
            .map-section .map-container iframe { height: 250px; }
        }
        @media (max-width: 480px) {
            .contact-detail-item { padding: 12px 16px; }
            .contact-detail-item .icon-circle { width: 36px; height: 36px; min-width: 36px; font-size: 0.9rem; }
        }
    </style>
</head>
<body>

<?php include 'modulos/navbar.php'; ?>

<!-- ===== CONTACT SECTION ===== -->
<section class="contact-section">
    <div class="container">
        <div class="contact-wrapper">
            <!-- Información de contacto -->
            <div class="contact-info">
                <h1 class="section-title">¿Listo para encontrar tu <br><span style="color: var(--gold);">propiedad ideal?</span></h1>
                <p class="section-subtitle">
                    En Vera Terra estamos comprometidos con hacer realidad tus sueños inmobiliarios.
                    Completa el formulario y nos pondremos en contacto contigo para brindarte la asesoría
                    personalizada que mereces.
                </p>
                <div class="contact-details">
                    <div class="contact-detail-item">
                        <div class="icon-circle"><i class="fa-solid fa-phone"></i></div>
                        <div class="info">
                            <h4>Teléfono</h4>
                            <p><a href="tel:+523318852307">+52 33 1885 2307</a></p>
                        </div>
                    </div>
                    <div class="contact-detail-item">
                        <div class="icon-circle"><i class="fa-solid fa-envelope"></i></div>
                        <div class="info">
                            <h4>Email</h4>
                            <p><a href="mailto:contacto@veraterra.com">contacto@veraterra.com</a></p>
                        </div>
                    </div>
                    <div class="contact-detail-item">
                        <div class="icon-circle"><i class="fa-solid fa-location-dot"></i></div>
                        <div class="info">
                            <h4>Ubicación</h4>
                            <p>Guadalajara, Jalisco, México</p>
                        </div>
                    </div>
                    <div class="contact-detail-item">
                        <div class="icon-circle"><i class="fa-regular fa-clock"></i></div>
                        <div class="info">
                            <h4>Horario de atención</h4>
                            <p>Lunes a Viernes: 9:00 - 19:00<br>Sábados: 10:00 - 14:00</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Formulario -->
            <div class="contact-form-wrapper">
                <h3>Envíanos un mensaje</h3>
                <p class="form-subtitle">Completa todos los campos y te responderemos a la brevedad.</p>

                <?php if ($mensaje_exito): ?>
                    <div class="alert alert-success">
                        <i class="fa-solid fa-check-circle"></i> <?php echo $mensaje_exito; ?>
                    </div>
                <?php endif; ?>

                <?php if ($mensaje_error): ?>
                    <div class="alert alert-error">
                        <i class="fa-solid fa-exclamation-circle"></i> <?php echo $mensaje_error; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="contactForm" novalidate>
                    <input type="hidden" name="enviar_contacto" value="1">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="nombre">Nombre completo <span class="required">*</span></label>
                            <input type="text" id="nombre" name="nombre"
                                   value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>"
                                   placeholder="Tu nombre completo" required>
                        </div>
                        <div class="form-group">
                            <label for="email">Correo electrónico <span class="required">*</span></label>
                            <input type="email" id="email" name="email"
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                   placeholder="tu@email.com" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="telefono">Teléfono <span class="required">*</span></label>
                            <input type="tel" id="telefono" name="telefono"
                                   value="<?php echo htmlspecialchars($_POST['telefono'] ?? ''); ?>"
                                   placeholder="33 1234 5678" required>
                        </div>
                        <div class="form-group">
                            <label for="tipo_interes">Tipo de interés</label>
                            <select id="tipo_interes" name="tipo_interes">
                                <option value="compra" <?php echo (($_POST['tipo_interes'] ?? '') === 'compra') ? 'selected' : ''; ?>>Compra</option>
                                <option value="venta" <?php echo (($_POST['tipo_interes'] ?? '') === 'venta') ? 'selected' : ''; ?>>Venta</option>
                                <option value="renta" <?php echo (($_POST['tipo_interes'] ?? '') === 'renta') ? 'selected' : ''; ?>>Renta</option>
                                <option value="asesoria" <?php echo (($_POST['tipo_interes'] ?? '') === 'asesoria') ? 'selected' : ''; ?>>Asesoría</option>
                                <option value="otro" <?php echo (($_POST['tipo_interes'] ?? '') === 'otro') ? 'selected' : ''; ?>>Otro</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="propiedad_interes">Propiedad de interés (opcional)</label>
                            <select id="propiedad_interes" name="propiedad_interes">
                                <option value="">Selecciona una propiedad</option>
                                <?php foreach ($propiedades_lista as $prop): ?>
                                    <option value="<?php echo $prop['id']; ?>"
                                        <?php echo (($_POST['propiedad_interes'] ?? '') == $prop['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($prop['title'] . ' - ' . $prop['address_city']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="presupuesto">Presupuesto aproximado (opcional)</label>
                            <input type="number" id="presupuesto" name="presupuesto"
                                   value="<?php echo htmlspecialchars($_POST['presupuesto'] ?? ''); ?>"
                                   placeholder="Ej: 2500000" step="10000">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="asunto">Asunto <span class="required">*</span></label>
                        <input type="text" id="asunto" name="asunto"
                               value="<?php echo htmlspecialchars($_POST['asunto'] ?? ''); ?>"
                               placeholder="Motivo de tu contacto" required>
                    </div>

                    <div class="form-group">
                        <label for="mensaje">Mensaje <span class="required">*</span></label>
                        <textarea id="mensaje" name="mensaje" rows="5"
                                  placeholder="Cuéntanos qué estás buscando..." required><?php echo htmlspecialchars($_POST['mensaje'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="preferencia_contacto">Preferencia de contacto</label>
                        <select id="preferencia_contacto" name="preferencia_contacto">
                            <option value="cualquiera" <?php echo (($_POST['preferencia_contacto'] ?? '') === 'cualquiera') ? 'selected' : ''; ?>>Cualquier medio</option>
                            <option value="email" <?php echo (($_POST['preferencia_contacto'] ?? '') === 'email') ? 'selected' : ''; ?>>Email</option>
                            <option value="telefono" <?php echo (($_POST['preferencia_contacto'] ?? '') === 'telefono') ? 'selected' : ''; ?>>Teléfono</option>
                            <option value="whatsapp" <?php echo (($_POST['preferencia_contacto'] ?? '') === 'whatsapp') ? 'selected' : ''; ?>>WhatsApp</option>
                        </select>
                    </div>

                    <button type="submit" class="btn-submit" id="submitBtn">
                        <i class="fa-regular fa-paper-plane"></i> Enviar mensaje
                    </button>

                    <p style="font-size:0.75rem; color:var(--text-muted); margin-top:15px; text-align:center;">
                        <i class="fa-regular fa-lock"></i> Tus datos están seguros. No compartiremos tu información.
                    </p>
                </form>
            </div>
        </div>
    </div>
</section>

<!-- ===== MAPA ===== -->
<section class="map-section">
    <div class="map-container">
        <iframe
            src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d119437.65863176374!2d-103.39760691240206!3d20.65978732830243!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x8428b18cb52fd39b%3A0xd63d9302bf865750!2sGuadalajara%2C%20Jal.%2C%20Mexico!5e0!3m2!1sen!2sus!4v1700000000000"
            allowfullscreen=""
            loading="lazy"
            referrerpolicy="no-referrer-when-downgrade">
        </iframe>
    </div>
</section>

<!-- ===== WHATSAPP FLOATING ===== -->
<a href="https://wa.me/523318852307?text=Hola%2C%20estoy%20interesado%20en%20una%20propiedad%20de%20Vera%20Terra"
   target="_blank"
   class="whatsapp-float"
   aria-label="Contactar por WhatsApp">
    <i class="fa-brands fa-whatsapp"></i>
    <span class="tooltip">¡Escríbenos!</span>
</a>

<?php include 'modulos/footer.php'; ?>

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

        // ============================================================
        //  VALIDACIÓN CLIENTE
        // ============================================================
        const form = document.getElementById('contactForm');
        const submitBtn = document.getElementById('submitBtn');

        form.addEventListener('submit', function(e) {
            let isValid = true;
            const inputs = form.querySelectorAll('input[required], textarea[required]');

            inputs.forEach(input => {
                if (!input.value.trim()) {
                    input.style.borderColor = '#e74c3c';
                    isValid = false;
                } else {
                    input.style.borderColor = '#ddd';
                }
            });

            const emailInput = document.getElementById('email');
            if (emailInput.value.trim() && !emailInput.value.includes('@')) {
                emailInput.style.borderColor = '#e74c3c';
                isValid = false;
            }

            if (!isValid) {
                e.preventDefault();
                alert('Por favor, completa todos los campos obligatorios.');
            } else {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando...';
            }
        });

        form.querySelectorAll('input, textarea').forEach(input => {
            input.addEventListener('input', function() {
                if (this.value.trim()) {
                    this.style.borderColor = '#ddd';
                }
            });
        });
    });
</script>
</body>
</html>