<?php
session_start();
require_once 'includes/conexion.php';
require_once 'includes/auth.php';

if (!estaLogueado()) { header('Location: login.php'); exit; }
$usuario = obtenerUsuarioActual($conn);
if (!$usuario) { cerrarSesion(); header('Location: login.php'); exit; }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: gestion_leads.php'); exit; }

$mensaje_exito = '';
$mensaje_error = '';

// ===== GUARDAR CAMBIOS =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nuevoStatus = $_POST['status'] ?? 'nuevo';
    $nuevasNotas = trim($_POST['notas'] ?? '');

    $statusValidos = ['nuevo','contactado','en_proceso','cerrado_ganado','cerrado_perdido'];
    if (!in_array($nuevoStatus, $statusValidos)) $nuevoStatus = 'nuevo';

    try {
        $stmt = $conn->prepare("UPDATE leads_meta SET status = ?, notas = ? WHERE id = ?");
        $stmt->execute([$nuevoStatus, $nuevasNotas, $id]);

        // Registrar en historial
        $stmt = $conn->prepare("INSERT INTO leads_historial (lead_id, usuario_id, accion, detalle) VALUES (?, ?, ?, ?)");
        $stmt->execute([$id, $_SESSION['usuario_id'], 'actualizacion', "Status: $nuevoStatus"]);

        $mensaje_exito = "Lead actualizado correctamente.";
    } catch (PDOException $e) {
        $mensaje_error = "Error al guardar: " . $e->getMessage();
    }
}

// ===== OBTENER LEAD =====
$lead = null;
try {
    $stmt = $conn->prepare("SELECT * FROM leads_meta WHERE id = ?");
    $stmt->execute([$id]);
    $lead = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($lead) {
        $stmt = $conn->prepare("SELECT h.*, u.name as usuario_nombre FROM leads_historial h LEFT JOIN users u ON h.usuario_id = u.id WHERE h.lead_id = ? ORDER BY h.fecha DESC LIMIT 50");
        $stmt->execute([$id]);
        $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $mensaje_error = "Error al consultar: " . $e->getMessage();
}

if (!$lead) { header('Location: gestion_leads.php'); exit; }

$rawData = json_decode($lead['raw_data'] ?? '{}', true);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/socios.css">
    <title>Lead: <?php echo htmlspecialchars($lead['full_name'] ?? 'Sin nombre'); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .lead-detalle-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }
        @media (max-width: 992px) {
            .lead-detalle-grid { grid-template-columns: 1fr; }
        }
        .info-card {
            background: #fff;
            border-radius: 10px;
            padding: 24px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .info-card h3 {
            font-size: 1rem;
            color: var(--dark);
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 1px solid #f1f3f5;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f8f9fa;
            font-size: 0.9rem;
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
            word-break: break-all;
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 6px;
        }
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.9rem;
        }
        .form-group textarea {
            min-height: 120px;
            resize: vertical;
        }
        .btn-guardar {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 12px 28px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .btn-guardar:hover { opacity: 0.9; }
        .alert-success {
            background: #d4edda; color: #155724;
            padding: 12px 16px; border-radius: 8px;
            margin-bottom: 20px; font-size: 0.9rem;
        }
        .alert-error {
            background: #f8d7da; color: #721c24;
            padding: 12px 16px; border-radius: 8px;
            margin-bottom: 20px; font-size: 0.9rem;
        }
        .historial-item {
            padding: 10px 0;
            border-bottom: 1px solid #f8f9fa;
            font-size: 0.85rem;
        }
        .historial-item:last-child { border-bottom: none; }
        .historial-item .fecha {
            color: var(--gray);
            font-size: 0.75rem;
        }
        .acciones-rapidas {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 15px;
        }
        .acciones-rapidas a {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-whatsapp { background: #25D366; color: #fff; }
        .btn-email { background: #0d6efd; color: #fff; }
        .btn-llamar { background: #6f42c1; color: #fff; }
        .acciones-rapidas a:hover { opacity: 0.85; }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<?php include 'modulos/sidebar.php'; ?>

<main class="main-content">
    <div class="main-header">
        <div class="header-left">
            <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
            <h1><?php echo htmlspecialchars($lead['full_name'] ?? 'Lead'); ?></h1>
            <p class="welcome">
                <i class="fas fa-clock"></i>
                Recibido: <?php echo date('d/m/Y H:i', strtotime($lead['created_time'] ?? $lead['fecha_recepcion'])); ?>
            </p>
        </div>
        <div class="header-actions">
            <a href="gestion_leads.php" class="btn-header secondary">
                <i class="fas fa-arrow-left"></i> Volver al listado
            </a>
        </div>
    </div>

    <?php if ($mensaje_exito): ?>
        <div class="alert-success"><i class="fas fa-check-circle"></i> <?php echo $mensaje_exito; ?></div>
    <?php endif; ?>
    <?php if ($mensaje_error): ?>
        <div class="alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $mensaje_error; ?></div>
    <?php endif; ?>

    <div class="lead-detalle-grid">

        <!-- COLUMNA IZQUIERDA: INFORMACIÓN -->
        <div>
            <div class="info-card">
                <h3><i class="fas fa-user"></i> Información de contacto</h3>
                <div class="info-row">
                    <span class="label">Nombre</span>
                    <span class="value"><?php echo htmlspecialchars($lead['full_name'] ?? '—'); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Email</span>
                    <span class="value"><?php echo htmlspecialchars($lead['email'] ?? '—'); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Teléfono</span>
                    <span class="value"><?php echo htmlspecialchars($lead['phone'] ?? '—'); ?></span>
                </div>
                <?php if (!empty($lead['mensaje'])): ?>
                    <div class="info-row">
                        <span class="label">Mensaje</span>
                        <span class="value"><?php echo htmlspecialchars($lead['mensaje']); ?></span>
                    </div>
                <?php endif; ?>
                <div class="acciones-rapidas">
                    <?php if ($lead['phone']): ?>
                        <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $lead['phone']); ?>" target="_blank" class="btn-whatsapp">
                            <i class="fab fa-whatsapp"></i> WhatsApp
                        </a>
                        <a href="tel:<?php echo htmlspecialchars($lead['phone']); ?>" class="btn-llamar">
                            <i class="fas fa-phone"></i> Llamar
                        </a>
                    <?php endif; ?>
                    <?php if ($lead['email']): ?>
                        <a href="mailto:<?php echo htmlspecialchars($lead['email']); ?>" class="btn-email">
                            <i class="fas fa-envelope"></i> Email
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="info-card" style="margin-top: 20px;">
                <h3><i class="fas fa-chart-line"></i> Origen del lead</h3>
                <div class="info-row">
                    <span class="label">Formulario</span>
                    <span class="value"><?php echo htmlspecialchars($lead['form_name'] ?? $lead['form_id'] ?? '—'); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Campaña</span>
                    <span class="value"><?php echo htmlspecialchars($lead['campaign_name'] ?? $lead['campaign_id'] ?? '—'); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Anuncio</span>
                    <span class="value"><?php echo htmlspecialchars($lead['ad_name'] ?? $lead['ad_id'] ?? '—'); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">ID del Lead en Meta</span>
                    <span class="value" style="font-size:0.8rem;"><?php echo htmlspecialchars($lead['lead_id']); ?></span>
                </div>
            </div>

            <?php if (!empty($rawData['field_data'])): ?>
                <div class="info-card" style="margin-top: 20px;">
                    <h3><i class="fas fa-list"></i> Todos los campos del formulario</h3>
                    <?php foreach ($rawData['field_data'] as $campo): ?>
                        <div class="info-row">
                            <span class="label"><?php echo htmlspecialchars($campo['name'] ?? ''); ?></span>
                            <span class="value"><?php echo htmlspecialchars(implode(', ', $campo['values'] ?? [])); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- COLUMNA DERECHA: GESTIÓN -->
        <div>
            <div class="info-card">
                <h3><i class="fas fa-tasks"></i> Gestión del lead</h3>
                <form method="POST">
                    <div class="form-group">
                        <label for="status">Estado</label>
                        <select name="status" id="status">
                            <option value="nuevo" <?php echo $lead['status']=='nuevo'?'selected':''; ?>>🔵 Nuevo</option>
                            <option value="contactado" <?php echo $lead['status']=='contactado'?'selected':''; ?>>🟠 Contactado</option>
                            <option value="en_proceso" <?php echo $lead['status']=='en_proceso'?'selected':''; ?>>🟣 En proceso</option>
                            <option value="cerrado_ganado" <?php echo $lead['status']=='cerrado_ganado'?'selected':''; ?>>🟢 Cerrado - Ganado</option>
                            <option value="cerrado_perdido" <?php echo $lead['status']=='cerrado_perdido'?'selected':''; ?>>🔴 Cerrado - Perdido</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="notas">Notas internas</label>
                        <textarea name="notas" id="notas" placeholder="Escribe notas sobre este lead (llamadas, seguimiento, etc.)"><?php echo htmlspecialchars($lead['notas'] ?? ''); ?></textarea>
                    </div>
                    <button type="submit" class="btn-guardar">
                        <i class="fas fa-save"></i> Guardar cambios
                    </button>
                </form>
            </div>

            <div class="info-card" style="margin-top: 20px;">
                <h3><i class="fas fa-history"></i> Historial</h3>
                <?php if (empty($historial)): ?>
                    <p style="color: var(--gray); font-size: 0.85rem;">Sin actividad registrada.</p>
                <?php else: ?>
                    <?php foreach ($historial as $h): ?>
                        <div class="historial-item">
                            <div><strong><?php echo htmlspecialchars($h['usuario_nombre'] ?? 'Sistema'); ?></strong> — <?php echo htmlspecialchars($h['accion']); ?></div>
                            <div><?php echo htmlspecialchars($h['detalle'] ?? ''); ?></div>
                            <div class="fecha"><?php echo date('d/m/Y H:i', strtotime($h['fecha'])); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
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
</script>

</body>
</html>