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

// ===== FILTROS =====
$filtro_status = $_GET['status'] ?? '';
$filtro_buscar = trim($_GET['buscar'] ?? '');
$filtro_form   = $_GET['form_id'] ?? '';

$where  = [];
$params = [];

if ($filtro_status !== '') {
    $where[] = "status = ?";
    $params[] = $filtro_status;
}
if ($filtro_buscar !== '') {
    $where[] = "(full_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $like = "%$filtro_buscar%";
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($filtro_form !== '') {
    $where[] = "form_id = ?";
    $params[] = $filtro_form;
}

$sqlWhere = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// ===== CONSULTAR LEADS =====
$leads = [];
$stats = ['total' => 0, 'nuevo' => 0, 'contactado' => 0, 'en_proceso' => 0, 'cerrado_ganado' => 0, 'cerrado_perdido' => 0];
$formularios = [];

try {
    // Listado
    $sql = "SELECT * FROM leads_meta $sqlWhere ORDER BY created_time DESC LIMIT 200";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Estadísticas
    $stmt = $conn->query("SELECT status, COUNT(*) as total FROM leads_meta GROUP BY status");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stats[$row['status']] = (int)$row['total'];
        $stats['total'] += (int)$row['total'];
    }

    // Formularios únicos (para el filtro)
    $stmt = $conn->query("SELECT DISTINCT form_id, form_name FROM leads_meta WHERE form_id IS NOT NULL ORDER BY form_name");
    $formularios = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error en gestion_leads: " . $e->getMessage());
}

// Helpers
function badgeStatus($status) {
    $map = [
        'nuevo'            => ['Nuevo', '#0d6efd'],
        'contactado'       => ['Contactado', '#fd7e14'],
        'en_proceso'       => ['En proceso', '#6f42c1'],
        'cerrado_ganado'   => ['Ganado', '#198754'],
        'cerrado_perdido'  => ['Perdido', '#dc3545'],
    ];
    $info = $map[$status] ?? ['Desconocido', '#6c757d'];
    return '<span class="badge-status" style="background:' . $info[1] . ';">' . $info[0] . '</span>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/socios.css">
    <title>Leads Meta | Inmobiliaria MH</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .badge-status {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 12px;
            color: #fff;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.3px;
        }
        .lead-row {
            display: grid;
            grid-template-columns: 40px 1fr 1.2fr 1fr 110px 130px 90px;
            gap: 12px;
            padding: 14px 16px;
            border-bottom: 1px solid #f1f3f5;
            align-items: center;
            transition: background 0.2s ease;
        }
        .lead-row:hover {
            background: #f8f9fa;
        }
        .lead-row.header {
            background: #f1f3f5;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray);
            padding: 12px 16px;
        }
        .lead-row .avatar-small {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
        }
        .lead-row .lead-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 2px;
        }
        .lead-row .lead-email {
            font-size: 0.85rem;
            color: var(--gray);
        }
        .lead-row .lead-phone {
            font-size: 0.85rem;
            color: var(--dark);
        }
        .lead-row .lead-form {
            font-size: 0.8rem;
            color: var(--gray);
        }
        .lead-row .lead-fecha {
            font-size: 0.8rem;
            color: var(--gray);
        }
        .lead-row .lead-actions a {
            color: var(--gray);
            padding: 6px 10px;
            border-radius: 6px;
            transition: all 0.2s;
        }
        .lead-row .lead-actions a:hover {
            background: #e9ecef;
            color: var(--primary);
        }
        .filtros-bar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
            align-items: center;
        }
        .filtros-bar input,
        .filtros-bar select {
            padding: 8px 14px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            font-size: 0.9rem;
            font-family: inherit;
        }
        .filtros-bar input {
            flex: 1;
            min-width: 200px;
        }
        .filtros-bar .btn-primary-f {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 9px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }
        .filtros-bar .btn-primary-f:hover {
            opacity: 0.9;
        }
        @media (max-width: 992px) {
            .lead-row {
                grid-template-columns: 40px 1fr 100px;
            }
            .lead-row > div:nth-child(4),
            .lead-row > div:nth-child(5),
            .lead-row > div:nth-child(6) {
                display: none;
            }
            .lead-row.header > div:nth-child(4),
            .lead-row.header > div:nth-child(5),
            .lead-row.header > div:nth-child(6) {
                display: none;
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<?php include 'modulos/sidebar.php'; ?>

<main class="main-content">
    <div class="main-header">
        <div class="header-left">
            <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
            <h1>Leads de Meta</h1>
            <p class="welcome">
                <i class="fas fa-bullseye"></i>
                <?php echo $stats['nuevo']; ?> nuevos · <?php echo $stats['total']; ?> totales
            </p>
        </div>
        <div class="header-actions">
            <a href="gestion_leads.php" class="btn-header secondary">
                <i class="fas fa-sync-alt"></i> Refrescar
            </a>
        </div>
    </div>

    <!-- ESTADÍSTICAS -->
    <div class="stats-grid">
        <div class="stat-card info">
            <span class="stat-icon"><i class="fas fa-users"></i></span>
            <div class="stat-number"><?php echo $stats['total']; ?></div>
            <div class="stat-label">Total Leads</div>
        </div>
        <div class="stat-card">
            <span class="stat-icon"><i class="fas fa-bell"></i></span>
            <div class="stat-number"><?php echo $stats['nuevo']; ?></div>
            <div class="stat-label">Nuevos</div>
        </div>
        <div class="stat-card warning">
            <span class="stat-icon"><i class="fas fa-phone"></i></span>
            <div class="stat-number"><?php echo $stats['contactado']; ?></div>
            <div class="stat-label">Contactados</div>
        </div>
        <div class="stat-card success">
            <span class="stat-icon"><i class="fas fa-trophy"></i></span>
            <div class="stat-number"><?php echo $stats['cerrado_ganado']; ?></div>
            <div class="stat-label">Ganados</div>
        </div>
        <div class="stat-card danger">
            <span class="stat-icon"><i class="fas fa-times-circle"></i></span>
            <div class="stat-number"><?php echo $stats['cerrado_perdido']; ?></div>
            <div class="stat-label">Perdidos</div>
        </div>
    </div>

    <!-- FILTROS -->
    <div class="table-container">
        <div class="table-header">
            <h3><i class="fas fa-filter"></i> Filtros</h3>
        </div>
        <div style="padding: 16px;">
            <form method="GET" class="filtros-bar">
                <input type="text" name="buscar" placeholder="Buscar por nombre, email o teléfono..." value="<?php echo htmlspecialchars($filtro_buscar); ?>">
                <select name="status">
                    <option value="">Todos los estados</option>
                    <option value="nuevo" <?php echo $filtro_status=='nuevo'?'selected':''; ?>>Nuevo</option>
                    <option value="contactado" <?php echo $filtro_status=='contactado'?'selected':''; ?>>Contactado</option>
                    <option value="en_proceso" <?php echo $filtro_status=='en_proceso'?'selected':''; ?>>En proceso</option>
                    <option value="cerrado_ganado" <?php echo $filtro_status=='cerrado_ganado'?'selected':''; ?>>Ganado</option>
                    <option value="cerrado_perdido" <?php echo $filtro_status=='cerrado_perdido'?'selected':''; ?>>Perdido</option>
                </select>
                <select name="form_id">
                    <option value="">Todos los formularios</option>
                    <?php foreach ($formularios as $f): ?>
                        <option value="<?php echo htmlspecialchars($f['form_id']); ?>" <?php echo $filtro_form==$f['form_id']?'selected':''; ?>>
                            <?php echo htmlspecialchars($f['form_name'] ?? $f['form_id']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-primary-f"><i class="fas fa-search"></i> Filtrar</button>
                <a href="gestion_leads.php" class="btn-header secondary" style="text-decoration:none;">Limpiar</a>
            </form>
        </div>
    </div>

    <!-- LISTADO -->
    <div class="table-container" style="margin-top: 20px;">
        <div class="table-header">
            <h3><i class="fas fa-list"></i> Listado de Leads (<?php echo count($leads); ?>)</h3>
        </div>

        <div class="lead-row header">
            <div></div>
            <div>Contacto</div>
            <div>Email</div>
            <div>Teléfono</div>
            <div>Estado</div>
            <div>Fecha</div>
            <div>Acciones</div>
        </div>

        <?php if (empty($leads)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No hay leads</h3>
                <p style="color: var(--gray);">Cuando lleguen leads desde Meta aparecerán aquí.</p>
            </div>
        <?php else: ?>
            <?php foreach ($leads as $lead): ?>
                <div class="lead-row">
                    <div class="avatar-small">
                        <?php echo strtoupper(substr($lead['full_name'] ?? 'L', 0, 1)); ?>
                    </div>
                    <div>
                        <div class="lead-name"><?php echo htmlspecialchars($lead['full_name'] ?? 'Sin nombre'); ?></div>
                        <div class="lead-form"><?php echo htmlspecialchars($lead['form_name'] ?? 'Formulario'); ?></div>
                    </div>
                    <div class="lead-email">
                        <?php if ($lead['email']): ?>
                            <a href="mailto:<?php echo htmlspecialchars($lead['email']); ?>" style="color:var(--primary);">
                                <?php echo htmlspecialchars($lead['email']); ?>
                            </a>
                        <?php else: ?>
                            <span style="color:var(--gray);">—</span>
                        <?php endif; ?>
                    </div>
                    <div class="lead-phone">
                        <?php if ($lead['phone']): ?>
                            <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $lead['phone']); ?>" target="_blank" style="color:var(--primary);">
                                <i class="fab fa-whatsapp"></i> <?php echo htmlspecialchars($lead['phone']); ?>
                            </a>
                        <?php else: ?>
                            <span style="color:var(--gray);">—</span>
                        <?php endif; ?>
                    </div>
                    <div><?php echo badgeStatus($lead['status']); ?></div>
                    <div class="lead-fecha">
                        <?php echo date('d/m/Y H:i', strtotime($lead['created_time'] ?? $lead['fecha_recepcion'])); ?>
                    </div>
                    <div class="lead-actions">
                        <a href="lead_detalle.php?id=<?php echo (int)$lead['id']; ?>" title="Ver detalle">
                            <i class="fas fa-eye"></i>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
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

    // Auto-refresh cada 60 segundos
    setTimeout(function() {
        location.reload();
    }, 60000); // 60,000 ms = 60 segundos
    
</script>

</body>
</html>