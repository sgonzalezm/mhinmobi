<?php
session_start();
require_once 'includes/conexion.php';
require_once 'includes/auth.php';

// Verificar autenticación
if (!estaLogueado()) {
    header('Location: login.php');
    exit;
}

$usuario = obtenerUsuarioActual($conn);
if (!$usuario) {
    cerrarSesion();
    header('Location: login.php');
    exit;
}

// ===== OBTENER EVALUACIÓN =====
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$evaluacion = null;

if ($id > 0) {
    try {
        $stmt = $conn->prepare("SELECT * FROM evaluaciones_propiedades WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $evaluacion = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $evaluacion = null;
    }
}

if (!$evaluacion) {
    header('Location: mensajes.php');
    exit;
}

// Helpers
function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function si_no($v) { return !empty($v) ? 'Sí' : 'No'; }
function valor($v, $defecto = '—') {
    return ($v !== null && $v !== '' && $v !== '0') ? $v : $defecto;
}
function money($v) {
    return $v ? '$' . number_format((float)$v, 0) : '—';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/socios.css">
    <title>Evaluación #<?php echo (int)$evaluacion['id']; ?> | Inmobiliaria MH</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .detalle-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }
        @media (max-width: 992px) {
            .detalle-grid { grid-template-columns: 1fr; }
        }

        .card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 25px;
            margin-bottom: 20px;
        }
        .card h3 {
            font-size: 1.05rem;
            color: var(--dark);
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f1f3f5;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .card h3 i { color: var(--primary); }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f1f3f5;
            font-size: 0.92rem;
        }
        .info-row:last-child { border-bottom: none; }
        .info-row .label {
            color: var(--gray);
            font-weight: 500;
        }
        .info-row .value {
            color: var(--dark);
            font-weight: 600;
            text-align: right;
            max-width: 60%;
            word-break: break-word;
        }

        .avatar-large {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.6rem;
            margin: 0 auto 12px;
        }
        .contacto-header {
            text-align: center;
            padding-bottom: 18px;
            border-bottom: 1px solid #f1f3f5;
            margin-bottom: 18px;
        }
        .contacto-header h2 {
            font-size: 1.15rem;
            color: var(--dark);
            margin-bottom: 4px;
        }
        .contacto-header p {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .btn-contacto {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
            margin-top: 10px;
            transition: all 0.25s ease;
            border: none;
            cursor: pointer;
            width: 100%;
        }
        .btn-wa {
            background: #25D366;
            color: #fff;
        }
        .btn-wa:hover { background: #1ebe5b; }
        .btn-mail {
            background: var(--primary);
            color: #fff;
        }
        .btn-mail:hover { opacity: 0.9; }
        .btn-tel {
            background: #f1f3f5;
            color: var(--dark);
        }
        .btn-tel:hover { background: #e2e6ea; }

        .precio-destacado {
            background: linear-gradient(135deg, var(--primary), #1a3552);
            color: #fff;
            padding: 22px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 20px;
        }
        .precio-destacado .label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            opacity: 0.85;
        }
        .precio-destacado .monto {
            font-size: 2rem;
            font-weight: 700;
            margin-top: 6px;
        }
        .precio-destacado .sub {
            font-size: 0.78rem;
            opacity: 0.75;
            margin-top: 4px;
        }

        .badge-urgencia {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            color: #fff;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-urgencia.inmediata { background: #dc3545; }
        .badge-urgencia.alta      { background: #fd7e14; }
        .badge-urgencia.media     { background: #ffc107; color:#333; }
        .badge-urgencia.baja      { background: #28a745; }
        .badge-urgencia.solo_informacion { background: #6c757d; }

        .comentario-box {
            background: #f8f9fa;
            border-left: 4px solid var(--primary);
            padding: 16px 18px;
            border-radius: 6px;
            font-size: 0.92rem;
            color: var(--dark);
            line-height: 1.6;
            white-space: pre-wrap;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            background: #fff;
            border: 1.5px solid #e0e0e0;
            border-radius: 8px;
            color: var(--dark);
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.25s;
            margin-bottom: 20px;
        }
        .back-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .chip {
            display: inline-block;
            background: #f1f3f5;
            color: var(--dark);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.78rem;
            margin: 3px;
            font-weight: 500;
        }
        .chip i { color: var(--primary); margin-right: 4px; }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php include 'modulos/sidebar.php'; ?>

<main class="main-content">
    <div class="main-header">
        <div class="header-left">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <h1>Evaluación #<?php echo (int)$evaluacion['id']; ?></h1>
            <p class="welcome">
                <i class="fas fa-clock"></i>
                Recibida el <?php echo date('d/m/Y \a \l\a\s H:i', strtotime($evaluacion['fecha_registro'])); ?>
            </p>
        </div>
        <div class="header-actions">
            <a href="mensajes.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Volver a la bandeja
            </a>
        </div>
    </div>

    <!-- Precio destacado -->
    <div class="precio-destacado">
        <div class="label">Precio deseado libre de gastos</div>
        <div class="monto"><?php echo money($evaluacion['precio_libre_gastos']); ?></div>
        <div class="sub">Monto que el propietario quiere recibir</div>
    </div>

    <div class="detalle-grid">

        <!-- ===== COLUMNA IZQUIERDA ===== -->
        <div>

            <!-- Propiedad -->
            <div class="card">
                <h3><i class="fas fa-home"></i> Datos de la propiedad</h3>
                <div class="info-row"><span class="label">Tipo</span><span class="value"><?php echo h(ucfirst($evaluacion['tipo_propiedad'])); ?></span></div>
                <div class="info-row"><span class="label">Operación</span><span class="value"><?php echo h(ucfirst($evaluacion['operacion'])); ?></span></div>
                <div class="info-row"><span class="label">Dirección</span><span class="value"><?php echo h($evaluacion['direccion']); ?></span></div>
                <div class="info-row"><span class="label">Colonia</span><span class="value"><?php echo h(valor($evaluacion['colonia'])); ?></span></div>
                <div class="info-row"><span class="label">Ciudad / Municipio</span><span class="value"><?php echo h($evaluacion['ciudad']); ?></span></div>
                <div class="info-row"><span class="label">M² construcción</span><span class="value"><?php echo h(valor($evaluacion['m2_construccion'])); ?></span></div>
                <div class="info-row"><span class="label">M² terreno</span><span class="value"><?php echo h(valor($evaluacion['m2_terreno'])); ?></span></div>
                <div class="info-row"><span class="label">Recámaras</span><span class="value"><?php echo h(valor($evaluacion['recamaras'])); ?></span></div>
                <div class="info-row"><span class="label">Baños</span><span class="value"><?php echo h(valor($evaluacion['banos'])); ?></span></div>
                <div class="info-row"><span class="label">Estacionamientos</span><span class="value"><?php echo h(valor($evaluacion['estacionamientos'])); ?></span></div>
                <div class="info-row"><span class="label">Antigüedad</span><span class="value"><?php echo $evaluacion['antiguedad'] ? h($evaluacion['antiguedad']) . ' años' : '—'; ?></span></div>
                <div class="info-row"><span class="label">Condiciones físicas</span><span class="value"><?php echo h(ucfirst(str_replace('_', ' ', valor($evaluacion['condiciones_fisicas'])))); ?></span></div>
            </div>

            <!-- Situación y documentos -->
            <div class="card">
                <h3><i class="fas fa-file-contract"></i> Situación y documentos</h3>
                <div class="info-row"><span class="label">Situación actual</span><span class="value"><?php echo h(ucfirst(str_replace('_', ' ', valor($evaluacion['situacion_actual'])))); ?></span></div>
                <div class="info-row"><span class="label">NSS</span><span class="value"><?php echo h(valor($evaluacion['nss'])); ?></span></div>
                <div class="info-row"><span class="label">Cuenta de agua</span><span class="value"><?php echo h(valor($evaluacion['cuenta_agua'])); ?></span></div>
                <div class="info-row"><span class="label">Cuenta predial</span><span class="value"><?php echo h(valor($evaluacion['cuenta_predial'])); ?></span></div>
            </div>

            <!-- Financiero -->
            <div class="card">
                <h3><i class="fas fa-dollar-sign"></i> Aspectos financieros</h3>
                <div class="info-row"><span class="label">Precio libre de gastos</span><span class="value"><?php echo money($evaluacion['precio_libre_gastos']); ?></span></div>
                <div class="info-row"><span class="label">Ganancias esperadas</span><span class="value"><?php echo money($evaluacion['ganancias_esperadas']); ?></span></div>
                <div class="info-row"><span class="label">¿Tiene adeudo?</span><span class="value"><?php echo si_no($evaluacion['tiene_adeudo']); ?></span></div>
                <?php if (!empty($evaluacion['tiene_adeudo'])): ?>
                    <div class="info-row"><span class="label">Monto del adeudo</span><span class="value"><?php echo money($evaluacion['monto_adeudo']); ?></span></div>
                    <div class="info-row"><span class="label">Concepto</span><span class="value"><?php echo h(valor($evaluacion['concepto_adeudo'])); ?></span></div>
                <?php endif; ?>
            </div>

            <!-- Comentarios -->
            <?php if (!empty($evaluacion['comentarios'])): ?>
                <div class="card">
                    <h3><i class="fas fa-comment-dots"></i> Comentarios del propietario</h3>
                    <div class="comentario-box"><?php echo h($evaluacion['comentarios']); ?></div>
                </div>
            <?php endif; ?>

        </div>

        <!-- ===== COLUMNA DERECHA ===== -->
        <div>

            <!-- Contacto -->
            <div class="card">
                <div class="contacto-header">
                    <div class="avatar-large">
                        <?php echo strtoupper(substr($evaluacion['nombre'], 0, 1)); ?>
                    </div>
                    <h2><?php echo h($evaluacion['nombre']); ?></h2>
                    <p>Propietario</p>
                </div>

                <div class="info-row"><span class="label"><i class="fas fa-phone"></i> Teléfono</span><span class="value"><?php echo h($evaluacion['telefono']); ?></span></div>
                <div class="info-row"><span class="label"><i class="fas fa-envelope"></i> Email</span><span class="value" style="font-size:0.82rem;"><?php echo h($evaluacion['email']); ?></span></div>

                <a href="https://wa.me/52<?php echo preg_replace('/\D/', '', $evaluacion['telefono']); ?>" target="_blank" class="btn-contacto btn-wa">
                    <i class="fab fa-whatsapp"></i> Enviar WhatsApp
                </a>
                <a href="mailto:<?php echo h($evaluacion['email']); ?>" class="btn-contacto btn-mail">
                    <i class="fas fa-envelope"></i> Enviar correo
                </a>
                <a href="tel:<?php echo h($evaluacion['telefono']); ?>" class="btn-contacto btn-tel">
                    <i class="fas fa-phone"></i> Llamar
                </a>
            </div>

            <!-- Urgencia -->
            <div class="card">
                <h3><i class="fas fa-fire"></i> Urgencia de venta</h3>
                <div style="text-align:center; padding: 10px 0;">
                    <?php
                    $u = $evaluacion['urgencia_venta'] ?: 'solo_informacion';
                    ?>
                    <span class="badge-urgencia <?php echo h($u); ?>">
                        <i class="fas fa-bolt"></i>
                        <?php echo h(ucfirst(str_replace('_', ' ', $u))); ?>
                    </span>
                </div>
            </div>

            <!-- Referidos -->
            <div class="card">
                <h3><i class="fas fa-user-friends"></i> Programa de referidos</h3>
                <div class="info-row"><span class="label">¿Fue referido?</span><span class="value"><?php echo si_no($evaluacion['referido_por']); ?></span></div>
                <?php if (!empty($evaluacion['referido_por'])): ?>
                    <div class="info-row"><span class="label">Referido por</span><span class="value"><?php echo h(valor($evaluacion['nombre_referidor'])); ?></span></div>
                <?php endif; ?>
            </div>

            <!-- Resumen rápido -->
            <div class="card">
                <h3><i class="fas fa-tags"></i> Resumen rápido</h3>
                <div style="text-align:center;">
                    <span class="chip"><i class="fas fa-home"></i> <?php echo h(ucfirst($evaluacion['tipo_propiedad'])); ?></span>
                    <span class="chip"><i class="fas fa-map-marker-alt"></i> <?php echo h($evaluacion['ciudad']); ?></span>
                    <?php if ($evaluacion['recamaras']): ?>
                        <span class="chip"><i class="fas fa-bed"></i> <?php echo h($evaluacion['recamaras']); ?> rec</span>
                    <?php endif; ?>
                    <?php if ($evaluacion['banos']): ?>
                        <span class="chip"><i class="fas fa-bath"></i> <?php echo h($evaluacion['banos']); ?> baños</span>
                    <?php endif; ?>
                    <?php if ($evaluacion['estacionamientos']): ?>
                        <span class="chip"><i class="fas fa-car"></i> <?php echo h($evaluacion['estacionamientos']); ?></span>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</main>

<script>
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    function toggleSidebar() {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('show');
        document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
    }

    menuToggle.addEventListener('click', toggleSidebar);
    overlay.addEventListener('click', toggleSidebar);

    document.querySelectorAll('.sidebar nav a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 992) toggleSidebar();
        });
    });
</script>

</body>
</html>