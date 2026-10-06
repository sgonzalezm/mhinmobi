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

// ===== CONSULTAR VACANTES ACTIVAS =====
$vacantes = [];
if ($conn) {
    try {
        $sql = "
            SELECT v.id, v.titulo, v.area, v.modalidad, v.ubicacion,
                   v.descripcion_corta, v.salario_min, v.salario_max,
                   v.experiencia, v.created_at
            FROM vacantes v
            WHERE v.activo = 1
            ORDER BY v.created_at DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $vacantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error al consultar vacantes: " . $e->getMessage());
        $vacantes = [];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Vacantes | Vera Terra Inmobiliaria</title>
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
        .btn-outline-gold:hover { background: var(--gold); color: #fff; }

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
            min-height: 340px;
            background: linear-gradient(rgba(11, 31, 58, 0.55), rgba(11, 31, 58, 0.8)),
                url('https://images.unsplash.com/photo-1521737604893-d14cc237f11d?auto=format&fit=crop&w=1600&q=80') center/cover no-repeat;
            display: flex;
            align-items: center;
            padding: 0 8%;
        }
        .page-hero-content { max-width: 640px; color: #fff; }
        .page-hero-content .tag {
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
        .page-hero-content h1 {
            font-family: 'Playfair Display', serif;
            font-size: 2.6rem;
            line-height: 1.2;
            font-weight: 700;
            margin-bottom: 16px;
        }
        .page-hero-content h1 span { color: var(--gold); }
        .page-hero-content p {
            font-size: 1rem;
            font-weight: 300;
            opacity: 0.9;
            max-width: 520px;
        }

        /* ===== SECCIÓN VACANTES ===== */
        .vacantes-section { padding: 70px 5%; background: #ffffff; }

        .vacantes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 30px;
            margin-top: 20px;
        }

        /* ===== TARJETA DE VACANTE ===== */
        .vacante-card {
            background: #fff;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid rgba(197, 160, 89, 0.15);
            padding: 28px 26px 24px;
            transition: transform var(--transition), box-shadow var(--transition), border-color var(--transition);
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
        }
        .vacante-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 4px; height: 100%;
            background: var(--gold);
            opacity: 0;
            transition: opacity var(--transition);
        }
        .vacante-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 40px rgba(11, 31, 58, 0.12);
            border-color: rgba(197, 160, 89, 0.4);
        }
        .vacante-card:hover::before { opacity: 1; }

        .vacante-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 14px;
        }
        .vacante-header h3 {
            font-size: 1.15rem;
            font-weight: 600;
            color: var(--navy);
            line-height: 1.3;
        }
        .vacante-area-badge {
            flex-shrink: 0;
            background: var(--gold-light);
            color: var(--navy);
            font-size: 0.65rem;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .vacante-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-bottom: 14px;
        }
        .vacante-meta span { display: flex; align-items: center; gap: 5px; }
        .vacante-meta i { color: var(--gold); }

        .vacante-descripcion {
            font-size: 0.88rem;
            color: var(--text-dark);
            margin-bottom: 18px;
            flex-grow: 1;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .vacante-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            border-top: 1px solid #eee;
            padding-top: 16px;
            margin-top: auto;
        }
        .vacante-salario {
            font-weight: 700;
            color: var(--gold);
            font-size: 0.95rem;
        }
        .vacante-salario small {
            display: block;
            font-weight: 400;
            color: var(--text-muted);
            font-size: 0.7rem;
            margin-top: 2px;
        }
        .vacante-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--navy);
            color: #fff;
            padding: 10px 22px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            transition: background var(--transition), transform var(--transition);
            white-space: nowrap;
        }
        .vacante-btn:hover { background: var(--gold); transform: translateY(-2px); }

        .vacante-fecha {
            font-size: 0.72rem;
            color: var(--text-muted);
            margin-top: 12px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .vacante-fecha i { color: var(--gold); font-size: 0.7rem; }

        /* ===== ESTADO VACÍO ===== */
        .vacantes-empty {
            grid-column: 1 / -1;
            text-align: center;
            padding: 60px 20px;
            background: var(--light-bg);
            border-radius: var(--radius);
            border: 1px dashed rgba(197, 160, 89, 0.4);
        }
        .vacantes-empty i { font-size: 3rem; color: var(--gold); margin-bottom: 18px; }
        .vacantes-empty h3 {
            font-family: 'Playfair Display', serif;
            font-size: 1.4rem;
            color: var(--navy);
            margin-bottom: 8px;
        }
        .vacantes-empty p {
            color: var(--text-muted);
            font-size: 0.9rem;
            max-width: 420px;
            margin: 0 auto 24px;
        }

        /* ===== CTA FINAL ===== */
        .vacantes-cta {
            background: var(--light-bg);
            border-radius: var(--radius);
            padding: 45px 30px;
            text-align: center;
            margin-top: 60px;
            border: 1px solid rgba(197, 160, 89, 0.2);
        }
        .vacantes-cta h3 {
            font-family: 'Playfair Display', serif;
            font-size: 1.6rem;
            color: var(--navy);
            margin-bottom: 10px;
        }
        .vacantes-cta p {
            color: var(--text-muted);
            font-size: 0.9rem;
            max-width: 520px;
            margin: 0 auto 24px;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 992px) {
            .page-hero-content h1 { font-size: 2.1rem; }
        }

        @media (max-width: 768px) {
            header { flex-direction: column; gap: 12px; padding: 12px 5%; }
            nav ul { gap: 16px; flex-wrap: wrap; justify-content: center; }
            .page-hero { min-height: 280px; padding: 0 6%; }
            .page-hero-content h1 { font-size: 1.7rem; }
            .section-title { font-size: 1.8rem; }
            .vacantes-section { padding: 50px 5%; }
            .vacantes-grid { grid-template-columns: 1fr; }
            .vacante-footer { flex-direction: column; align-items: stretch; }
            .vacante-btn { justify-content: center; }
            footer { flex-direction: column; text-align: center; }
            .footer-contact { justify-content: center; }
        }

        @media (max-width: 480px) {
            .page-hero-content h1 { font-size: 1.4rem; }
            .vacante-card { padding: 22px 20px; }
            .vacante-header { flex-direction: column; }
            .vacante-area-badge { align-self: flex-start; }
            .btn-gold { padding: 12px 28px; font-size: 0.85rem; }
        }
    </style>
</head>
<body>

    <?php include 'modulos/navbar.php'; ?>

    <!-- ===== HERO INTERNO ===== -->
    <section class="page-hero">
        <div class="page-hero-content">
            <div class="tag"><i class="fas fa-briefcase"></i> Únete al equipo</div>
            <h1>Construye tu futuro con <span>Vera Terra</span></h1>
            <p>Buscamos personas talentosas y comprometidas que compartan nuestra pasión por el sector inmobiliario y la excelencia en el servicio.</p>
        </div>
    </section>

    <!-- ===== VACANTES DISPONIBLES ===== -->
    <section class="vacantes-section" id="vacantes">
        <div class="container">
            <h2 class="section-title">Vacantes disponibles</h2>
            <p class="section-subtitle">Explora nuestras oportunidades laborales y forma parte de un equipo en constante crecimiento.</p>

            <div class="vacantes-grid">

            <?php if (!empty($vacantes)): ?>
                <?php foreach ($vacantes as $v): 
                    $modalidad_label = getModalidadLabel($v['modalidad'] ?? '');
                    $modalidad_icon  = getModalidadIcon($v['modalidad'] ?? '');
                    $area_label      = getAreaLabel($v['area'] ?? '');
                    $salario         = formatearSalario($v['salario_min'] ?? null, $v['salario_max'] ?? null);
                    $fecha           = tiempoPublicado($v['created_at'] ?? '');
                ?>
                    <article class="vacante-card">
                        <div class="vacante-header">
                            <h3><?php echo htmlspecialchars($v['titulo']); ?></h3>
                            <span class="vacante-area-badge"><?php echo htmlspecialchars($area_label); ?></span>
                        </div>

                        <div class="vacante-meta">
                            <?php if (!empty($v['ubicacion'])): ?>
                                <span><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($v['ubicacion']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($modalidad_label)): ?>
                                <span><i class="<?php echo $modalidad_icon; ?>"></i> <?php echo htmlspecialchars($modalidad_label); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($v['experiencia'])): ?>
                                <span><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($v['experiencia']); ?></span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($v['descripcion_corta'])): ?>
                            <p class="vacante-descripcion"><?php echo htmlspecialchars($v['descripcion_corta']); ?></p>
                        <?php endif; ?>

                        <div class="vacante-footer">
                            <div class="vacante-salario">
                                <?php echo $salario; ?>
                                <small>Salario mensual</small>
                            </div>
                            <a href="vacante_detalle.php?id=<?php echo urlencode($v['id']); ?>" class="vacante-btn">
                                Ver detalles <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>

                        <?php if (!empty($fecha)): ?>
                            <div class="vacante-fecha">
                                <i class="fa-regular fa-calendar"></i> <?php echo $fecha; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="vacantes-empty">
                    <i class="fa-regular fa-folder-open"></i>
                    <h3>No hay vacantes disponibles por el momento</h3>
                    <p>Estamos en constante crecimiento. Envíanos tu currículum y te contactaremos cuando surja una oportunidad que encaje contigo.</p>
                    <a href="contacto.php" class="btn-gold"><i class="fas fa-paper-plane"></i> Enviar mi currículum</a>
                </div>
            <?php endif; ?>

            </div>

            <!-- ===== CTA FINAL ===== -->
            <div class="vacantes-cta">
                <h3>¿No encuentras la vacante ideal?</h3>
                <p>Déjanos tus datos y tu currículum. Nuestro equipo de Recursos Humanos te contactará cuando tengamos una oportunidad que se ajuste a tu perfil.</p>
                <a href="contacto.php" class="btn-outline-gold"><i class="fas fa-envelope"></i> Contactar a Recursos Humanos</a>
            </div>

        </div>
    </section>

    <?php include 'modulos/footer.php'; ?>

    <!-- ===== SCRIPT para efecto de scroll en navbar ===== -->
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