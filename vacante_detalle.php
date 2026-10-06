<?php
// ===== DEPURACIÓN (quitar en producción) =====
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ===== CONEXIÓN PDO =====
require_once 'includes/conexion.php';

// ===== FUNCIONES AUXILIARES =====
function getModalidadLabel($modalidad) {
    $labels = [
        'tiempo_completo' => 'Tiempo completo',
        'medio_tiempo'    => 'Medio tiempo',
        'por_proyecto'    => 'Por proyecto',
        'practicante'     => 'Practicante',
        'freelance'       => 'Freelance',
    ];
    return $labels[$modalidad] ?? ucfirst(str_replace('_', ' ', (string)$modalidad));
}
function getModalidadIcon($modalidad) {
    $icons = [
        'tiempo_completo' => 'fa-regular fa-clock',
        'medio_tiempo'    => 'fa-regular fa-clock',
        'por_proyecto'    => 'fa-regular fa-diagram-project',
        'practicante'     => 'fa-regular fa-graduation-cap',
        'freelance'       => 'fa-regular fa-laptop',
    ];
    return $icons[$modalidad] ?? 'fa-regular fa-briefcase';
}
function getAreaLabel($area) {
    $labels = [
        'ventas'         => 'Ventas',
        'administracion' => 'Administración',
        'marketing'      => 'Marketing',
        'operaciones'    => 'Operaciones',
        'atencion'       => 'Atención a clientes',
        'legal'          => 'Legal',
        'tecnologia'     => 'Tecnología',
    ];
    return $labels[$area] ?? ucfirst((string)$area);
}
function formatearSalario($min, $max) {
    if (empty($min) && empty($max)) return 'Salario a convenir';
    if (!empty($min) && !empty($max)) {
        return '$' . number_format(floatval($min), 0, ',', '.') . ' - $' . number_format(floatval($max), 0, ',', '.');
    }
    if (!empty($min)) return 'Desde $' . number_format(floatval($min), 0, ',', '.');
    return 'Hasta $' . number_format(floatval($max), 0, ',', '.');
}
function tiempoPublicado($fecha) {
    if (empty($fecha)) return '';
    $diff = time() - strtotime($fecha);
    $dias = floor($diff / 86400);
    if ($dias <= 0) return 'Publicado hoy';
    if ($dias == 1) return 'Publicado hace 1 día';
    if ($dias < 30) return "Publicado hace $dias días";
    $meses = floor($dias / 30);
    return $meses == 1 ? 'Publicado hace 1 mes' : "Publicado hace $meses meses";
}
function fechaLarga($fecha) {
    if (empty($fecha)) return '';
    $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    $ts = strtotime($fecha);
    return date('j', $ts) . ' de ' . $meses[(int)date('n', $ts) - 1] . ' de ' . date('Y', $ts);
}
function renderLista($texto) {
    if (empty($texto)) return '';
    $texto = trim($texto);
    if (stripos($texto, '<ul') !== false || stripos($texto, '<li') !== false) return $texto;
    $lineas = preg_split('/\r\n|\r|\n/', $texto);
    $items = '';
    foreach ($lineas as $linea) {
        $linea = trim($linea);
        if ($linea === '') continue;
        $linea = preg_replace('/^[-•*]\s*/', '', $linea);
        $items .= '<li>' . htmlspecialchars($linea) . '</li>';
    }
    return $items ? '<ul>' . $items . '</ul>' : '';
}

// ===== OBTENER ID Y VALIDAR =====
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$vacante = null;
$error = null;

if ($id <= 0) {
    $error = 'ID de vacante no válido.';
} elseif ($conn) {
    try {
        $sql = "
            SELECT v.id, v.titulo, v.area, v.modalidad, v.ubicacion,
                   v.descripcion_corta, v.descripcion_completa,
                   v.responsabilidades, v.requisitos,
                   v.salario_min, v.salario_max, v.experiencia, v.created_at
            FROM vacantes v
            WHERE v.id = :id AND v.activo = 1
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $vacante = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$vacante) $error = 'La vacante que buscas no existe o ya no está disponible.';
    } catch (PDOException $e) {
        error_log("Error al consultar vacante: " . $e->getMessage());
        $error = 'Ocurrió un error al cargar la vacante. Intenta más tarde.';
    }
} else {
    $error = 'No hay conexión a la base de datos.';
}

// ===== DATOS DERIVADOS =====
$modalidad_label = $modalidad_icon = $area_label = $salario = $fecha_pub = $fecha_corta = $whatsapp_msg = '';
if ($vacante) {
    $modalidad_label = getModalidadLabel($vacante['modalidad'] ?? '');
    $modalidad_icon  = getModalidadIcon($vacante['modalidad'] ?? '');
    $area_label      = getAreaLabel($vacante['area'] ?? '');
    $salario         = formatearSalario($vacante['salario_min'] ?? null, $vacante['salario_max'] ?? null);
    $fecha_pub        = tiempoPublicado($vacante['created_at'] ?? '');
    $fecha_corta      = fechaLarga($vacante['created_at'] ?? '');
    $whatsapp_msg     = rawurlencode("Hola, estoy interesado(a) en la vacante \"" . $vacante['titulo'] . "\" (ID: " . $vacante['id'] . ") publicada en Vera Terra.");
}
$page_title = $vacante ? htmlspecialchars($vacante['titulo']) . ' | Vacantes' : 'Vacante no encontrada';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo $page_title; ?> | Vera Terra Inmobiliaria</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
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

        .container { max-width: 1200px; margin: 0 auto; padding: 0 20px; }

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
            text-align: center;
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
            text-align: center;
        }
        .btn-outline-gold:hover { background: var(--gold); color: #fff; }

        .btn-navy {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: var(--navy);
            color: #fff;
            padding: 14px 30px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.9rem;
            border: none;
            cursor: pointer;
            transition: background var(--transition), transform var(--transition);
            width: 100%;
        }
        .btn-navy:hover { background: #14335a; transform: translateY(-2px); }

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

        nav ul { display: flex; list-style: none; gap: 30px; align-items: center; }
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
            bottom: -4px; left: 0;
            width: 0%; height: 2px;
            background: var(--gold);
            transition: width var(--transition);
        }
        nav a:hover::after, nav a.active::after { width: 100%; }
        nav a:hover, nav a.active { color: var(--gold); }
        .search-link { display: flex; align-items: center; gap: 6px; }

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
        .footer-logo .logo-icon { width: 32px; height: 32px; font-size: 0.7rem; }
        .footer-contact { display: flex; flex-wrap: wrap; gap: 20px; color: #000; }
        .footer-contact i { color: var(--gold); margin-right: 6px; }
        .social-links { display: flex; gap: 16px; }
        .social-links a {
            color: #494848;
            font-size: 1.1rem;
            transition: color var(--transition), transform var(--transition);
            width: 38px; height: 38px;
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

        /* ===== HERO INTERNO ===== */
        .page-hero {
            position: relative;
            min-height: 300px;
            background: linear-gradient(rgba(11, 31, 58, 0.55), rgba(11, 31, 58, 0.85)),
                url('https://images.unsplash.com/photo-1521737604893-d14cc237f11d?auto=format&fit=crop&w=1600&q=80') center/cover no-repeat;
            display: flex;
            align-items: center;
            padding: 50px 8%;
        }
        .page-hero-content { max-width: 800px; color: #fff; }

        .breadcrumb {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 0.78rem;
            color: rgba(255, 255, 255, 0.75);
            margin-bottom: 16px;
        }
        .breadcrumb a { color: rgba(255, 255, 255, 0.75); transition: color var(--transition); }
        .breadcrumb a:hover { color: var(--gold); }
        .breadcrumb i { font-size: 0.6rem; opacity: 0.6; }

        .hero-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 16px;
        }
        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(197, 160, 89, 0.18);
            backdrop-filter: blur(4px);
            padding: 5px 14px;
            border-radius: 30px;
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            color: var(--gold);
            border: 1px solid rgba(197, 160, 89, 0.35);
            text-transform: uppercase;
        }

        .page-hero-content h1 {
            font-family: 'Playfair Display', serif;
            font-size: 2.4rem;
            line-height: 1.2;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .hero-fecha {
            font-size: 0.82rem;
            opacity: 0.85;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .hero-fecha i { color: var(--gold); }

        /* ===== LAYOUT PRINCIPAL ===== */
        .vacante-detalle-section { padding: 60px 5% 80px; background: #ffffff; }

        .vacante-layout {
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 40px;
            align-items: start;
        }

        /* ===== COLUMNA PRINCIPAL ===== */
        .vacante-main {
            background: #fff;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid rgba(197, 160, 89, 0.15);
            padding: 40px 38px;
        }
        .vacante-main h2 {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem;
            color: var(--navy);
            margin-bottom: 16px;
            font-weight: 700;
        }
        .vacante-main h2:not(:first-child) { margin-top: 38px; }

        .vacante-main p {
            font-size: 0.95rem;
            color: var(--text-dark);
            line-height: 1.75;
            margin-bottom: 14px;
        }

        .vacante-main ul { list-style: none; margin: 0 0 10px; padding: 0; }
        .vacante-main ul li {
            position: relative;
            padding: 8px 0 8px 30px;
            font-size: 0.92rem;
            color: var(--text-dark);
            line-height: 1.6;
            border-bottom: 1px solid #f2f2f2;
        }
        .vacante-main ul li:last-child { border-bottom: none; }
        .vacante-main ul li::before {
            content: '\f00c';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            left: 0; top: 9px;
            color: var(--gold);
            font-size: 0.8rem;
            width: 20px; height: 20px;
            background: var(--gold-light);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* ===== SIDEBAR ===== */
        .vacante-sidebar {
            position: sticky;
            top: 100px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .sidebar-card {
            background: #fff;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid rgba(197, 160, 89, 0.15);
            padding: 28px 26px;
        }
        .sidebar-card h3 {
            font-family: 'Playfair Display', serif;
            font-size: 1.15rem;
            color: var(--navy);
            margin-bottom: 18px;
            font-weight: 700;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--gold-light);
        }

        .sidebar-list { list-style: none; display: flex; flex-direction: column; gap: 14px; }
        .sidebar-list li { display: flex; align-items: flex-start; gap: 12px; font-size: 0.85rem; }
        .sidebar-list .icon-box {
            flex-shrink: 0;
            width: 36px; height: 36px;
            border-radius: 8px;
            background: var(--gold-light);
            color: var(--gold);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
        }
        .sidebar-list .info-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            font-weight: 600;
            display: block;
            margin-bottom: 2px;
        }
        .sidebar-list .info-value {
            font-size: 0.9rem;
            color: var(--navy);
            font-weight: 600;
            line-height: 1.35;
        }

        .sidebar-salario {
            background: var(--navy);
            color: #fff;
            border-radius: var(--radius);
            padding: 26px 24px;
            text-align: center;
            border: 1px solid rgba(197, 160, 89, 0.3);
        }
        .sidebar-salario .label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--gold);
            font-weight: 600;
            margin-bottom: 6px;
        }
        .sidebar-salario .monto {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: #fff;
            line-height: 1.2;
        }
        .sidebar-salario .nota { font-size: 0.72rem; opacity: 0.7; margin-top: 8px; }

        .sidebar-cta .btn-navy { margin-bottom: 10px; }
        .sidebar-cta .btn-outline-gold { width: 100%; }

        .sidebar-nota {
            font-size: 0.72rem;
            color: var(--text-muted);
            text-align: center;
            margin-top: 14px;
            line-height: 1.5;
        }

        /* ===== BOTÓN VOLVER ===== */
        .volver-wrapper { margin-top: 40px; text-align: left; }

        /* ===== ESTADO DE ERROR ===== */
        .error-section {
            padding: 80px 5%;
            background: var(--light-bg);
            min-height: 55vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .error-card {
            background: #fff;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 60px 40px;
            text-align: center;
            max-width: 520px;
            border-top: 4px solid var(--gold);
        }
        .error-card i { font-size: 3.2rem; color: var(--gold); margin-bottom: 20px; }
        .error-card h2 {
            font-family: 'Playfair Display', serif;
            font-size: 1.6rem;
            color: var(--navy);
            margin-bottom: 12px;
        }
        .error-card p {
            color: var(--text-muted);
            font-size: 0.92rem;
            margin-bottom: 28px;
            line-height: 1.6;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 992px) {
            .vacante-layout { grid-template-columns: 1fr; }
            .vacante-sidebar { position: static; }
            .page-hero-content h1 { font-size: 2rem; }
        }

        @media (max-width: 768px) {
            header { flex-direction: column; gap: 12px; padding: 12px 5%; }
            nav ul { gap: 16px; flex-wrap: wrap; justify-content: center; }
            .page-hero { min-height: 260px; padding: 40px 6%; }
            .page-hero-content h1 { font-size: 1.6rem; }
            .vacante-main { padding: 28px 22px; }
            .vacante-main h2 { font-size: 1.25rem; }
            .vacante-detalle-section { padding: 40px 5% 60px; }
            footer { flex-direction: column; text-align: center; }
            .footer-contact { justify-content: center; }
        }

        @media (max-width: 480px) {
            .page-hero-content h1 { font-size: 1.35rem; }
            .hero-badges { gap: 6px; }
            .hero-badge { font-size: 0.65rem; padding: 4px 10px; }
            .vacante-main { padding: 24px 18px; }
            .sidebar-card { padding: 22px 20px; }
        }
    </style>
</head>
<body>

    <?php include 'modulos/navbar.php'; ?>

    <?php if ($vacante): ?>

        <!-- ===== HERO INTERNO ===== -->
        <section class="page-hero">
            <div class="page-hero-content">
                <nav class="breadcrumb">
                    <a href="index.php">Inicio</a>
                    <i class="fas fa-chevron-right"></i>
                    <a href="vacantes.php">Vacantes</a>
                    <i class="fas fa-chevron-right"></i>
                    <span><?php echo htmlspecialchars($vacante['titulo']); ?></span>
                </nav>

                <div class="hero-badges">
                    <?php if (!empty($area_label)): ?>
                        <span class="hero-badge"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($area_label); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($modalidad_label)): ?>
                        <span class="hero-badge"><i class="<?php echo $modalidad_icon; ?>"></i> <?php echo htmlspecialchars($modalidad_label); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($vacante['ubicacion'])): ?>
                        <span class="hero-badge"><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($vacante['ubicacion']); ?></span>
                    <?php endif; ?>
                </div>

                <h1><?php echo htmlspecialchars($vacante['titulo']); ?></h1>

                <?php if (!empty($fecha_corta)): ?>
                    <div class="hero-fecha">
                        <i class="fa-regular fa-calendar"></i>
                        Publicado el <?php echo htmlspecialchars($fecha_corta); ?>
                        <?php if (!empty($fecha_pub)): ?>
                            <span style="opacity:0.6;">&nbsp;·&nbsp;<?php echo htmlspecialchars($fecha_pub); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ===== DETALLE DE LA VACANTE ===== -->
        <section class="vacante-detalle-section">
            <div class="container">
                <div class="vacante-layout">

                    <!-- ===== COLUMNA PRINCIPAL ===== -->
                    <div class="vacante-main">

                        <?php if (!empty($vacante['descripcion_corta'])): ?>
                            <p style="font-size:1rem; color: var(--text-muted); font-style: italic; border-left: 3px solid var(--gold); padding-left: 16px; margin-bottom: 30px;">
                                <?php echo htmlspecialchars($vacante['descripcion_corta']); ?>
                            </p>
                        <?php endif; ?>

                        <?php if (!empty($vacante['descripcion_completa'])): ?>
                            <h2><i class="fas fa-briefcase" style="color: var(--gold); font-size: 1.1rem; margin-right: 8px;"></i> Descripción del puesto</h2>
                            <p><?php echo nl2br(htmlspecialchars($vacante['descripcion_completa'])); ?></p>
                        <?php endif; ?>

                        <?php if (!empty($vacante['responsabilidades'])): ?>
                            <h2><i class="fas fa-list-check" style="color: var(--gold); font-size: 1.1rem; margin-right: 8px;"></i> Responsabilidades</h2>
                            <?php echo renderLista($vacante['responsabilidades']); ?>
                        <?php endif; ?>

                        <?php if (!empty($vacante['requisitos'])): ?>
                            <h2><i class="fas fa-user-check" style="color: var(--gold); font-size: 1.1rem; margin-right: 8px;"></i> Requisitos</h2>
                            <?php echo renderLista($vacante['requisitos']); ?>
                        <?php endif; ?>

                        <?php if (empty($vacante['descripcion_completa']) && empty($vacante['responsabilidades']) && empty($vacante['requisitos'])): ?>
                            <p style="color: var(--text-muted); font-style: italic;">
                                La descripción detallada de esta vacante estará disponible pronto. Contáctanos para más información.
                            </p>
                        <?php endif; ?>

                        <div class="volver-wrapper">
                            <a href="vacantes.php" class="btn-outline-gold">
                                <i class="fas fa-arrow-left"></i> Ver todas las vacantes
                            </a>
                        </div>
                    </div>

                    <!-- ===== SIDEBAR ===== -->
                    <aside class="vacante-sidebar">

                        <div class="sidebar-salario">
                            <div class="label">Salario mensual</div>
                            <div class="monto"><?php echo $salario; ?></div>
                            <div class="nota">Sujeto a experiencia y habilidades</div>
                        </div>

                        <div class="sidebar-card">
                            <h3>Resumen de la vacante</h3>
                            <ul class="sidebar-list">

                                <?php if (!empty($area_label)): ?>
                                <li>
                                    <div class="icon-box"><i class="fas fa-tag"></i></div>
                                    <div>
                                        <span class="info-label">Área</span>
                                        <span class="info-value"><?php echo htmlspecialchars($area_label); ?></span>
                                    </div>
                                </li>
                                <?php endif; ?>

                                <?php if (!empty($modalidad_label)): ?>
                                <li>
                                    <div class="icon-box"><i class="<?php echo $modalidad_icon; ?>"></i></div>
                                    <div>
                                        <span class="info-label">Modalidad</span>
                                        <span class="info-value"><?php echo htmlspecialchars($modalidad_label); ?></span>
                                    </div>
                                </li>
                                <?php endif; ?>

                                <?php if (!empty($vacante['ubicacion'])): ?>
                                <li>
                                    <div class="icon-box"><i class="fas fa-location-dot"></i></div>
                                    <div>
                                        <span class="info-label">Ubicación</span>
                                        <span class="info-value"><?php echo htmlspecialchars($vacante['ubicacion']); ?></span>
                                    </div>
                                </li>
                                <?php endif; ?>

                                <?php if (!empty($vacante['experiencia'])): ?>
                                <li>
                                    <div class="icon-box"><i class="fas fa-user-tie"></i></div>
                                    <div>
                                        <span class="info-label">Experiencia</span>
                                        <span class="info-value"><?php echo htmlspecialchars($vacante['experiencia']); ?></span>
                                    </div>
                                </li>
                                <?php endif; ?>

                                <?php if (!empty($fecha_corta)): ?>
                                <li>
                                    <div class="icon-box"><i class="fa-regular fa-calendar"></i></div>
                                    <div>
                                        <span class="info-label">Publicado</span>
                                        <span class="info-value"><?php echo htmlspecialchars($fecha_corta); ?></span>
                                    </div>
                                </li>
                                <?php endif; ?>

                            </ul>
                        </div>

                        <div class="sidebar-card sidebar-cta">
                            <h3>¿Te interesa esta vacante?</h3>

                            <a href="https://wa.me/523311586937?text=<?php echo $whatsapp_msg; ?>"
                               target="_blank"
                               rel="noopener"
                               class="btn-navy">
                                <i class="fab fa-whatsapp"></i> Postular por WhatsApp
                            </a>

                            <a href="contacto.php" class="btn-outline-gold">
                                <i class="fas fa-envelope"></i> Más información
                            </a>

                            <p class="sidebar-nota">
                                También puedes enviar tu CV a<br>
                                <strong style="color: var(--navy);">atencion@veraterra.com</strong>
                            </p>
                        </div>

                    </aside>

                </div>
            </div>
        </section>

    <?php else: ?>

        <!-- ===== ESTADO DE ERROR ===== -->
        <section class="error-section">
            <div class="error-card">
                <i class="fa-regular fa-face-frown"></i>
                <h2>Vacante no disponible</h2>
                <p><?php echo htmlspecialchars($error ?? 'La vacante que buscas no existe o ya no está disponible.'); ?></p>
                <a href="vacantes.php" class="btn-gold">
                    <i class="fas fa-arrow-left"></i> Ver vacantes disponibles
                </a>
            </div>
        </section>

    <?php endif; ?>

    <?php include 'modulos/footer.php'; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const header = document.getElementById('header');
            if (header) {
                window.addEventListener('scroll', function() {
                    if (window.scrollY > 30) header.classList.add('scrolled');
                    else header.classList.remove('scrolled');
                });
            }
        });
    </script>
</body>
</html>