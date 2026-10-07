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

// ===== VISTA ACTUAL (tabs) =====
$vista = $_GET['vista'] ?? 'todos';
if (!in_array($vista, ['todos', 'mios'])) $vista = 'todos';

// ===== FILTROS =====
$filtro_status = $_GET['status'] ?? '';
$filtro_buscar = trim($_GET['buscar'] ?? '');
$filtro_form   = $_GET['form_id'] ?? '';

$where  = [];
$params = [];

if ($filtro_status !== '') {
    $where[] = "lm.status = ?";
    $params[] = $filtro_status;
}
if ($filtro_buscar !== '') {
    $where[] = "(lm.full_name LIKE ? OR lm.email LIKE ? OR lm.phone LIKE ?)";
    $like = "%$filtro_buscar%";
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($filtro_form !== '') {
    $where[] = "lm.form_id = ?";
    $params[] = $filtro_form;
}

$joinAsignacion   = '';
$selectAsignacion = '';
if ($vista === 'mios') {
    $joinAsignacion = "INNER JOIN leads_asignados la ON la.lead_id = lm.id AND la.usuario_id = ?";
    array_unshift($params, $usuario['id']);
    $selectAsignacion = ", la.estado_seguimiento, la.notas, la.proxima_accion,
                            la.fecha_asignacion, la.fecha_ultima_actualizacion,
                            la.id AS asignacion_id";
}

$sqlWhere = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// ===== CONSULTAR LEADS =====
$leads = [];
$stats = ['total' => 0, 'nuevo' => 0, 'contactado' => 0, 'en_proceso' => 0, 'cerrado_ganado' => 0, 'cerrado_perdido' => 0];
$statsMios = ['total' => 0];
$formularios = [];

try {
    $sql = "SELECT lm.* $selectAsignacion
            FROM leads_meta lm
            $joinAsignacion
            $sqlWhere
            ORDER BY lm.created_time DESC
            LIMIT 200";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->query("SELECT status, COUNT(*) as total FROM leads_meta GROUP BY status");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (isset($stats[$row['status']])) {
            $stats[$row['status']] = (int)$row['total'];
        }
        $stats['total'] += (int)$row['total'];
    }

    $stmt = $conn->prepare("SELECT COUNT(*) FROM leads_asignados WHERE usuario_id = ?");
    $stmt->execute([$usuario['id']]);
    $statsMios['total'] = (int)$stmt->fetchColumn();

    $stmt = $conn->query("SELECT DISTINCT form_id, form_name FROM leads_meta WHERE form_id IS NOT NULL ORDER BY form_name");
    $formularios = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error en gestion_leads: " . $e->getMessage());
}

// ===== HELPERS =====
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

function badgeSeguimiento($estado) {
    $map = [
        'nuevo'            => ['Nuevo', '#0d6efd'],
        'contactado'       => ['Contactado', '#fd7e14'],
        'negociacion'      => ['En negociación', '#6f42c1'],
        'seguimiento'      => ['En seguimiento', '#20c997'],
        'cerrado_ganado'   => ['Ganado', '#198754'],
        'cerrado_perdido'  => ['Perdido', '#dc3545'],
    ];
    $i = $map[$estado] ?? ['—', '#6c757d'];
    return '<span class="badge-status" style="background:' . $i[1] . ';">' . $i[0] . '</span>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
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
            white-space: nowrap;
        }

        /* ============ TABLA ESCRITORIO ============ */
        .lead-row {
            display: grid;
            grid-template-columns: 40px 1fr 1.2fr 1fr 110px 130px 90px;
            gap: 12px;
            padding: 14px 16px;
            border-bottom: 1px solid #f1f3f5;
            align-items: center;
            transition: background 0.2s ease;
        }
        .lead-row:hover { background: #f8f9fa; }
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
            width: 40px; height: 40px;
            border-radius: 50%;
            background: var(--primary);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
        }
        .lead-row .lead-name { font-weight: 600; color: var(--dark); margin-bottom: 2px; }
        .lead-row .lead-email,
        .lead-row .lead-phone,
        .lead-row .lead-form,
        .lead-row .lead-fecha { font-size: 0.85rem; color: var(--gray); }
        .lead-row .lead-actions a {
            color: var(--gray);
            padding: 6px 10px;
            border-radius: 6px;
            transition: all 0.2s;
            margin-right: 2px;
        }
        .lead-row .lead-actions a:hover { background: #e9ecef; color: var(--primary); }

        .filtros-bar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
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
        .filtros-bar input { flex: 1; min-width: 200px; }
        .filtros-bar .btn-primary-f {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 9px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }
        .filtros-bar .btn-primary-f:hover { opacity: 0.9; }

        /* ============ TABS ============ */
        .tabs-vista {
            display: flex;
            gap: 4px;
            margin: 0 0 22px;
            border-bottom: 2px solid #e9ecef;
            flex-wrap: wrap;
        }
        .tabs-vista .tab {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            color: var(--gray);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            transition: all 0.2s;
        }
        .tabs-vista .tab:hover { color: var(--primary); background: #f8f9fa; }
        .tabs-vista .tab.active { color: var(--primary); border-bottom-color: var(--primary); }
        .tabs-vista .tab-badge {
            background: #e9ecef;
            color: var(--dark);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 700;
            min-width: 22px;
            text-align: center;
        }
        .tabs-vista .tab.active .tab-badge { background: var(--primary); color: #fff; }

        /* ============ VISTA "MIS LEADS" (escritorio) ============ */
        .lead-row.mios { grid-template-columns: 40px 1.3fr 1fr 130px 130px 90px; }
        .lead-row.header.mios { grid-template-columns: 40px 1.3fr 1fr 130px 130px 90px; }

        /* ============ TARJETAS MOBILE (ocultas en escritorio) ============ */
        .lead-cards { display: none; }

        /* ============ RESPONSIVE ============ */
        @media (max-width: 992px) {
            .lead-row { grid-template-columns: 40px 1fr 100px; }
            .lead-row > div:nth-child(4),
            .lead-row > div:nth-child(5),
            .lead-row > div:nth-child(6) { display: none; }
            .lead-row.header > div:nth-child(4),
            .lead-row.header > div:nth-child(5),
            .lead-row.header > div:nth-child(6) { display: none; }

            .lead-row.mios { grid-template-columns: 40px 1fr 90px; }
            .lead-row.mios > div:nth-child(3),
            .lead-row.mios > div:nth-child(4),
            .lead-row.mios > div:nth-child(5) { display: none; }
            .lead-row.header.mios > div:nth-child(3),
            .lead-row.header.mios > div:nth-child(4),
            .lead-row.header.mios > div:nth-child(5) { display: none; }
        }

        /* ============ MOBILE: cambiar tabla por tarjetas ============ */
        @media (max-width: 768px) {
            /* Ocultar stats y descripción */
            .stats-grid { display: none !important; }
            .main-header .welcome { display: none !important; }

            /* Reducir padding general */
            .main-content { padding: 12px !important; }

            /* Tabs más compactos */
            .tabs-vista {
                margin-bottom: 12px;
                border-bottom-width: 1px;
            }
            .tabs-vista .tab {
                flex: 1;
                justify-content: center;
                padding: 10px 8px;
                font-size: 0.82rem;
                gap: 6px;
            }
            .tabs-vista .tab i { font-size: 0.9rem; }
            .tabs-vista .tab-badge {
                padding: 1px 6px;
                font-size: 0.7rem;
            }

            /* Filtros colapsables - solo mostrar búsqueda y botón */
            .table-container.filtros-container { display: none; }

            /* Ocultar tabla de escritorio */
            .lead-row { display: none !important; }
            .lead-row.header { display: none !important; }

            /* Mostrar tarjetas mobile */
            .lead-cards {
                display: flex;
                flex-direction: column;
                gap: 10px;
                padding: 12px;
            }

            /* Tarjeta individual */
            .lead-card {
                background: #fff;
                border: 1px solid #e9ecef;
                border-radius: 12px;
                padding: 14px;
                display: flex;
                flex-direction: column;
                gap: 10px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            }

            .lead-card-top {
                display: flex;
                align-items: center;
                gap: 12px;
            }
            .lead-card .avatar-small {
                width: 44px; height: 44px;
                border-radius: 50%;
                background: var(--primary);
                color: #fff;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: 700;
                font-size: 1.1rem;
                flex-shrink: 0;
            }
            .lead-card-info { flex: 1; min-width: 0; }
            .lead-card-name {
                font-weight: 700;
                color: var(--dark);
                font-size: 0.95rem;
                margin-bottom: 2px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .lead-card-form {
                font-size: 0.75rem;
                color: var(--gray);
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .lead-card-meta {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                align-items: center;
                font-size: 0.8rem;
            }
            .lead-card-meta .badge-status { font-size: 0.7rem; padding: 3px 10px; }

            .lead-card-phone {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                color: var(--primary);
                text-decoration: none;
                font-weight: 600;
                font-size: 0.85rem;
                padding: 8px 12px;
                background: #f0f7ff;
                border-radius: 8px;
                width: 100%;
                justify-content: center;
            }
            .lead-card-phone i { font-size: 1rem; }

            /* Botones de acción mobile */
            .lead-card-actions {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }
            .lead-card-actions a {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                padding: 11px 10px;
                border-radius: 9px;
                text-decoration: none;
                font-weight: 600;
                font-size: 0.85rem;
                transition: all 0.15s;
                border: none;
                cursor: pointer;
            }
            .lead-card-actions a.btn-ver {
                background: #f1f3f5;
                color: var(--dark);
            }
            .lead-card-actions a.btn-ver:active { background: #e2e6ea; }

            .lead-card-actions a.btn-tomar {
                background: var(--primary);
                color: #fff;
            }
            .lead-card-actions a.btn-tomar:active { opacity: 0.85; }

            /* Cuando solo hay una acción (vista mios) */
            .lead-card-actions.single {
                grid-template-columns: 1fr;
            }

            .lead-card-fecha {
                font-size: 0.72rem;
                color: var(--gray);
                display: flex;
                align-items: center;
                gap: 5px;
            }
        }

        /* ============ BARRA DE BÚSQUEDA MOBILE ============ */
        .mobile-search {
            display: none;
            margin-bottom: 12px;
        }
        .mobile-search form {
            display: flex;
            gap: 8px;
        }
        .mobile-search input {
            flex: 1;
            padding: 11px 14px;
            border: 1px solid #dee2e6;
            border-radius: 10px;
            font-size: 0.9rem;
            font-family: inherit;
        }
        .mobile-search button {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 0 16px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 1rem;
        }

        @media (max-width: 768px) {
            .mobile-search { display: block; }
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
            <h1>Leads Meta</h1>
            <p class="welcome">
                <i class="fas fa-bullseye"></i>
                <?php echo $stats['nuevo']; ?> nuevos · <?php echo $stats['total']; ?> totales
            </p>
        </div>
        <div class="header-actions">
            <a href="gestion_leads.php?vista=<?php echo $vista; ?>" class="btn-header secondary">
                <i class="fas fa-sync-alt"></i> <span class="hide-mobile">Refrescar</span>
            </a>
        </div>
    </div>

    <!-- TABS -->
    <div class="tabs-vista">
        <a href="?vista=todos" class="tab <?php echo $vista==='todos'?'active':''; ?>">
            <i class="fas fa-globe"></i> Todos
            <span class="tab-badge"><?php echo $stats['total']; ?></span>
        </a>
        <a href="?vista=mios" class="tab <?php echo $vista==='mios'?'active':''; ?>">
            <i class="fas fa-user-check"></i> Míos
            <span class="tab-badge"><?php echo $statsMios['total']; ?></span>
        </a>
    </div>

    <!-- ESTADÍSTICAS (se ocultan en móvil por CSS) -->
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

    <!-- BÚSQUEDA MOBILE (simple) -->
    <div class="mobile-search">
        <form method="GET">
            <input type="hidden" name="vista" value="<?php echo htmlspecialchars($vista); ?>">
            <input type="text" name="buscar" placeholder="🔍 Buscar nombre, teléfono..." value="<?php echo htmlspecialchars($filtro_buscar); ?>">
            <button type="submit"><i class="fas fa-search"></i></button>
        </form>
    </div>

    <!-- FILTROS ESCRITORIO (ocultos en móvil) -->
    <div class="table-container filtros-container">
        <div class="table-header">
            <h3><i class="fas fa-filter"></i> Filtros</h3>
        </div>
        <div style="padding: 16px;">
            <form method="GET" class="filtros-bar">
                <input type="hidden" name="vista" value="<?php echo htmlspecialchars($vista); ?>">
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
                <a href="gestion_leads.php?vista=<?php echo $vista; ?>" class="btn-header secondary" style="text-decoration:none;">Limpiar</a>
            </form>
        </div>
    </div>

    <!-- LISTADO -->
    <div class="table-container" style="margin-top: 20px;">
        <div class="table-header" style="padding: 14px 16px;">
            <h3 style="margin:0;font-size:1rem;">
                <i class="fas fa-list"></i>
                <?php if ($vista === 'mios'): ?>
                    Mis leads (<?php echo count($leads); ?>)
                <?php else: ?>
                    Leads (<?php echo count($leads); ?>)
                <?php endif; ?>
            </h3>
        </div>

        <?php if (empty($leads)): ?>
            <div class="empty-state" style="padding:50px 20px;text-align:center;">
                <i class="fas fa-inbox" style="font-size:3rem;color:#ccc;margin-bottom:12px;"></i>
                <h3 style="color:var(--dark);margin-bottom:6px;">
                    <?php echo $vista === 'mios' ? 'Aún no has tomado leads' : 'No hay leads'; ?>
                </h3>
                <p style="color: var(--gray);font-size:0.9rem;">
                    <?php if ($vista === 'mios'): ?>
                        Ve a <a href="?vista=todos" style="color:var(--primary);font-weight:600;">Todos</a> y toca <strong>Tomar</strong>.
                    <?php else: ?>
                        Cuando lleguen leads desde Meta aparecerán aquí.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>

            <?php if ($vista === 'todos'): ?>
                <!-- ============ VISTA TODOS ============ -->

                <!-- Tabla escritorio -->
                <div class="lead-row header">
                    <div></div>
                    <div>Contacto</div>
                    <div>Teléfono</div>
                    <div>Estado</div>
                    <div>Fecha</div>
                    <div>Acciones</div>
                </div>

                <?php foreach ($leads as $lead): ?>
                    <div class="lead-row">
                        <div class="avatar-small"><?php echo strtoupper(substr($lead['full_name'] ?? 'L', 0, 1)); ?></div>
                        <div>
                            <div class="lead-name"><?php echo htmlspecialchars($lead['full_name'] ?? 'Sin nombre'); ?></div>
                            <div class="lead-form"><?php echo htmlspecialchars($lead['form_name'] ?? 'Formulario'); ?></div>
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
                        <div class="lead-fecha"><?php echo date('d/m/Y H:i', strtotime($lead['created_time'] ?? $lead['fecha_recepcion'])); ?></div>
                        <div class="lead-actions">
                            <a href="lead_detalle.php?id=<?php echo (int)$lead['id']; ?>" title="Ver detalle"><i class="fas fa-eye"></i></a>
                            <a href="tomar_lead.php?id=<?php echo (int)$lead['id']; ?>" title="Tomar lead"><i class="fas fa-hand-holding-usd"></i></a>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Tarjetas mobile -->
                <div class="lead-cards">
                    <?php foreach ($leads as $lead): ?>
                        <div class="lead-card">
                            <div class="lead-card-top">
                                <div class="avatar-small"><?php echo strtoupper(substr($lead['full_name'] ?? 'L', 0, 1)); ?></div>
                                <div class="lead-card-info">
                                    <div class="lead-card-name"><?php echo htmlspecialchars($lead['full_name'] ?? 'Sin nombre'); ?></div>
                                    <div class="lead-card-form"><?php echo htmlspecialchars($lead['form_name'] ?? 'Formulario'); ?></div>
                                </div>
                                <?php echo badgeStatus($lead['status']); ?>
                            </div>

                            <?php if ($lead['phone']): ?>
                                <a class="lead-card-phone" href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $lead['phone']); ?>" target="_blank">
                                    <i class="fab fa-whatsapp"></i> <?php echo htmlspecialchars($lead['phone']); ?>
                                </a>
                            <?php endif; ?>

                            <div class="lead-card-fecha">
                                <i class="far fa-clock"></i>
                                <?php echo date('d/m/Y H:i', strtotime($lead['created_time'] ?? $lead['fecha_recepcion'])); ?>
                            </div>

                            <div class="lead-card-actions">
                                <a href="lead_detalle.php?id=<?php echo (int)$lead['id']; ?>" class="btn-ver">
                                    <i class="fas fa-eye"></i> Ver
                                </a>
                                <a href="tomar_lead.php?id=<?php echo (int)$lead['id']; ?>" class="btn-tomar">
                                    <i class="fas fa-hand-holding-usd"></i> Tomar
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <!-- ============ VISTA MIS LEADS ============ -->

                <!-- Tabla escritorio -->
                <div class="lead-row header mios">
                    <div></div>
                    <div>Contacto</div>
                    <div>Teléfono</div>
                    <div>Estado seguimiento</div>
                    <div>Próxima acción</div>
                    <div>Acciones</div>
                </div>

                <?php foreach ($leads as $lead): ?>
                    <div class="lead-row mios">
                        <div class="avatar-small"><?php echo strtoupper(substr($lead['full_name'] ?? 'L', 0, 1)); ?></div>
                        <div>
                            <div class="lead-name"><?php echo htmlspecialchars($lead['full_name'] ?? 'Sin nombre'); ?></div>
                            <div class="lead-form"><?php echo htmlspecialchars($lead['form_name'] ?? 'Formulario'); ?></div>
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
                        <div><?php echo badgeSeguimiento($lead['estado_seguimiento'] ?? 'nuevo'); ?></div>
                        <div class="lead-fecha">
                            <?php if (!empty($lead['proxima_accion'])): ?>
                                <i class="fas fa-clock"></i> <?php echo date('d/m/Y H:i', strtotime($lead['proxima_accion'])); ?>
                            <?php else: ?>
                                <span style="color:var(--gray);">Sin programar</span>
                            <?php endif; ?>
                        </div>
                        <div class="lead-actions">
                            <a href="lead_detalle.php?id=<?php echo (int)$lead['id']; ?>" title="Ver y dar seguimiento"><i class="fas fa-edit"></i></a>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Tarjetas mobile -->
                <div class="lead-cards">
                    <?php foreach ($leads as $lead): ?>
                        <div class="lead-card">
                            <div class="lead-card-top">
                                <div class="avatar-small"><?php echo strtoupper(substr($lead['full_name'] ?? 'L', 0, 1)); ?></div>
                                <div class="lead-card-info">
                                    <div class="lead-card-name"><?php echo htmlspecialchars($lead['full_name'] ?? 'Sin nombre'); ?></div>
                                    <div class="lead-card-form"><?php echo htmlspecialchars($lead['form_name'] ?? 'Formulario'); ?></div>
                                </div>
                                <?php echo badgeSeguimiento($lead['estado_seguimiento'] ?? 'nuevo'); ?>
                            </div>

                            <?php if ($lead['phone']): ?>
                                <a class="lead-card-phone" href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $lead['phone']); ?>" target="_blank">
                                    <i class="fab fa-whatsapp"></i> <?php echo htmlspecialchars($lead['phone']); ?>
                                </a>
                            <?php endif; ?>

                            <?php if (!empty($lead['proxima_accion'])): ?>
                                <div class="lead-card-fecha">
                                    <i class="fas fa-clock"></i>
                                    Próxima: <?php echo date('d/m/Y H:i', strtotime($lead['proxima_accion'])); ?>
                                </div>
                            <?php endif; ?>

                            <div class="lead-card-actions single">
                                <a href="lead_detalle.php?id=<?php echo (int)$lead['id']; ?>" class="btn-tomar">
                                    <i class="fas fa-edit"></i> Dar seguimiento
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>
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
    if (menuToggle) menuToggle.addEventListener('click', toggleSidebar);
    if (overlay) overlay.addEventListener('click', toggleSidebar);

    // Auto-refresh solo si no hay filtros y estamos en escritorio
    const tieneFiltros = <?php echo (!empty($filtro_buscar) || $filtro_status !== '' || $filtro_form !== '') ? 'true' : 'false'; ?>;
    const esMovil = window.matchMedia('(max-width: 768px)').matches;
    if (!tieneFiltros && !esMovil) {
        setTimeout(function() { location.reload(); }, 60000);
    }
</script>

</body>
</html>