<?php
// ============================================
// admin/contactos.php
// Panel de gestión de contactos (estilo accesos.php)
// ============================================

// === DEPURACIÓN (eliminar en producción) ===
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'includes/conexion.php';
require_once 'includes/auth.php';

// Verificar autenticación
if (!estaLogueado()) {
    header('Location: ../login.php');
    exit;
}

$usuario = obtenerUsuarioActual($conn);
if (!$usuario) {
    cerrarSesion();
    header('Location: ../login.php');
    exit;
}

// Solo administradores
if (!esAdmin()) {
    header('Location: ../vender.php');
    exit;
}

$mensaje = '';
$tipo_mensaje = '';

// ============================================
// PROCESAR ACCIONES POST
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    try {
        switch ($accion) {
            case 'cambiar_estado':
                $id = intval($_POST['contacto_id']);
                $nuevo_estado = $_POST['nuevo_estado'] ?? '';
                $estados_validos = ['nuevo', 'leido', 'en_proceso', 'respondido', 'archivado'];
                if (!in_array($nuevo_estado, $estados_validos)) {
                    throw new Exception('Estado no válido');
                }
                $stmt = $conn->prepare("UPDATE contactos SET status = :status WHERE id = :id");
                $stmt->execute([':status' => $nuevo_estado, ':id' => $id]);
                $mensaje = 'Estado actualizado correctamente.';
                $tipo_mensaje = 'success';
                break;

            case 'eliminar_contacto':
                $id = intval($_POST['contacto_id']);
                $stmt = $conn->prepare("SELECT id FROM contactos WHERE id = ?");
                $stmt->execute([$id]);
                if (!$stmt->fetch()) {
                    throw new Exception('El contacto no existe');
                }
                $stmt = $conn->prepare("DELETE FROM contactos WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $mensaje = 'Contacto eliminado permanentemente.';
                $tipo_mensaje = 'success';
                break;

            default:
                throw new Exception('Acción no reconocida');
        }
    } catch (Exception $e) {
        $mensaje = $e->getMessage();
        $tipo_mensaje = 'error';
    }
}

// ============================================
// VERIFICAR EXISTENCIA DE LA TABLA
// ============================================
$tabla_existe = false;
try {
    $result = $conn->query("SHOW TABLES LIKE 'contactos'");
    $tabla_existe = $result->rowCount() > 0;
} catch (PDOException $e) {
    // Si la consulta falla, asumimos que no existe
    $tabla_existe = false;
}

// Si no existe, mostramos mensaje y no hacemos más consultas
if (!$tabla_existe) {
    $mensaje = 'La tabla "contactos" no existe en la base de datos. Ejecuta el script de creación.';
    $tipo_mensaje = 'error';
    $contactos = [];
    $total_contactos = 0;
    $total_paginas = 1;
    $status_counts = [];
} else {
    // ============================================
    // FILTROS Y PAGINACIÓN (solo si la tabla existe)
    // ============================================
    $filtro_status   = $_GET['status'] ?? 'todos';
    $filtro_tipo     = $_GET['tipo'] ?? 'todos';
    $filtro_busqueda = trim($_GET['busqueda'] ?? '');
    $filtro_desde    = $_GET['fecha_desde'] ?? '';
    $filtro_hasta    = $_GET['fecha_hasta'] ?? '';

    $itemsPorPagina = 15;
    $pagina_actual = isset($_GET['page']) ? intval($_GET['page']) : 1;
    if ($pagina_actual < 1) $pagina_actual = 1;

    $where = [];
    $params = [];

    if ($filtro_status !== 'todos' && in_array($filtro_status, ['nuevo', 'leido', 'en_proceso', 'respondido', 'archivado'])) {
        $where[] = "c.status = :status";
        $params[':status'] = $filtro_status;
    }

    if ($filtro_tipo !== 'todos' && in_array($filtro_tipo, ['compra', 'venta', 'renta', 'asesoria', 'otro'])) {
        $where[] = "c.tipo_interes = :tipo";
        $params[':tipo'] = $filtro_tipo;
    }

    if (!empty($filtro_busqueda)) {
        $where[] = "(c.nombre LIKE :busqueda OR c.email LIKE :busqueda OR c.telefono LIKE :busqueda OR c.asunto LIKE :busqueda OR c.mensaje LIKE :busqueda)";
        $params[':busqueda'] = '%' . $filtro_busqueda . '%';
    }

    if (!empty($filtro_desde)) {
        $where[] = "DATE(c.fecha_contacto) >= :fecha_desde";
        $params[':fecha_desde'] = $filtro_desde;
    }
    if (!empty($filtro_hasta)) {
        $where[] = "DATE(c.fecha_contacto) <= :fecha_hasta";
        $params[':fecha_hasta'] = $filtro_hasta;
    }

    $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    try {
        // Contar total
        $count_sql = "SELECT COUNT(*) as total FROM contactos c $where_clause";
        $stmt = $conn->prepare($count_sql);
        $stmt->execute($params);
        $total_contactos = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
        $total_paginas = max(1, ceil($total_contactos / $itemsPorPagina));
        if ($pagina_actual > $total_paginas) $pagina_actual = $total_paginas;
        $offset = ($pagina_actual - 1) * $itemsPorPagina;

        // Obtener contactos
        $sql = "
            SELECT 
                c.*,
                p.title as propiedad_titulo
            FROM contactos c
            LEFT JOIN properties p ON c.propiedad_interes = p.id
            $where_clause
            ORDER BY 
                CASE WHEN c.status = 'nuevo' THEN 0 ELSE 1 END,
                c.fecha_contacto DESC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $conn->prepare($sql);
        $params_query = $params;
        $params_query[':limit'] = $itemsPorPagina;
        $params_query[':offset'] = $offset;
        $stmt->execute($params_query);
        $contactos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Estadísticas por estado
        $stats_sql = "SELECT status, COUNT(*) as total FROM contactos GROUP BY status";
        $stats_stmt = $conn->prepare($stats_sql);
        $stats_stmt->execute();
        $status_counts = $stats_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    } catch (PDOException $e) {
        // Si hay error en las consultas, mostramos mensaje y vaciamos datos
        $mensaje = 'Error al consultar la base de datos: ' . $e->getMessage();
        $tipo_mensaje = 'error';
        $contactos = [];
        $total_contactos = 0;
        $total_paginas = 1;
        $status_counts = [];
    }
}

// ============================================
// FUNCIONES AUXILIARES
// ============================================
function getBadgeEstado($status) {
    $map = [
        'nuevo'       => ['class' => 'badge-nuevo', 'label' => '🆕 Nuevo'],
        'leido'       => ['class' => 'badge-leido', 'label' => '👁️ Leído'],
        'en_proceso'  => ['class' => 'badge-proceso', 'label' => '🔄 En proceso'],
        'respondido'  => ['class' => 'badge-respondido', 'label' => '✅ Respondido'],
        'archivado'   => ['class' => 'badge-archivado', 'label' => '📦 Archivado'],
    ];
    return $map[$status] ?? ['class' => 'badge-default', 'label' => $status];
}

function getTipoInteresLabel($tipo) {
    $labels = [
        'compra'   => '🏠 Compra',
        'venta'    => '💰 Venta',
        'renta'    => '📋 Renta',
        'asesoria' => '🧑‍💼 Asesoría',
        'otro'     => '📌 Otro'
    ];
    return $labels[$tipo] ?? $tipo;
}

function getPreferenciaLabel($pref) {
    $labels = [
        'email'      => '📧 Email',
        'telefono'   => '📞 Teléfono',
        'whatsapp'   => '💬 WhatsApp',
        'cualquiera' => '📱 Cualquiera'
    ];
    return $labels[$pref] ?? $pref;
}

function formatearFecha($fecha) {
    return date('d/m/Y H:i', strtotime($fecha));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Contactos | Inmobiliaria</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/socios.css">
    <style>
        /* (Mismo CSS que tenías, no lo repito por espacio) */
        .contactos-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-item { background: white; padding: 12px 16px; border-radius: 10px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); text-align: center; border-left: 4px solid #4c51bf; }
        .stat-item .number { font-size: 1.6rem; font-weight: 700; color: #2d3748; }
        .stat-item .label { font-size: 0.75rem; text-transform: uppercase; color: #718096; letter-spacing: 0.5px; }
        .stat-item.nuevo { border-left-color: #e53e3e; }
        .stat-item.leido { border-left-color: #3182ce; }
        .stat-item.proceso { border-left-color: #d69e2e; }
        .stat-item.respondido { border-left-color: #38a169; }
        .stat-item.archivado { border-left-color: #718096; }
        .filtros-bar { background: white; padding: 18px 20px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); margin-bottom: 25px; display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .filtros-bar .grupo { display: flex; flex-direction: column; gap: 4px; }
        .filtros-bar .grupo label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: #718096; letter-spacing: 0.3px; }
        .filtros-bar select, .filtros-bar input { padding: 8px 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 0.9rem; background: #f8fafc; transition: border-color 0.3s; }
        .filtros-bar select:focus, .filtros-bar input:focus { outline: none; border-color: #4c51bf; }
        .filtros-bar .btn-filtrar { background: #4c51bf; color: white; border: none; padding: 8px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: background 0.3s; margin-top: auto; }
        .filtros-bar .btn-filtrar:hover { background: #3c41a8; }
        .filtros-bar .btn-limpiar { background: transparent; color: #718096; border: 1px solid #e2e8f0; padding: 8px 18px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.3s; margin-top: auto; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .filtros-bar .btn-limpiar:hover { background: #f1f5f9; border-color: #cbd5e1; }
        .tabla-contactos { background: white; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); overflow: hidden; overflow-x: auto; }
        .tabla-contactos table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        .tabla-contactos th { background: #f8fafc; padding: 12px 14px; text-align: left; font-weight: 600; color: #2d3748; border-bottom: 2px solid #e2e8f0; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap; }
        .tabla-contactos td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .tabla-contactos tr:hover { background: #f8fafc; }
        .tabla-contactos .mensaje-preview { max-width: 180px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: default; }
        .tabla-contactos .mensaje-preview:hover { white-space: normal; overflow: visible; background: white; position: relative; z-index: 5; box-shadow: 0 4px 12px rgba(0,0,0,0.1); padding: 6px 10px; border-radius: 6px; }
        .badge-rol, .badge-estado { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .badge-nuevo { background: #fee2e2; color: #991b1b; }
        .badge-leido { background: #dbeafe; color: #1e40af; }
        .badge-proceso { background: #fef3c7; color: #92400e; }
        .badge-respondido { background: #d1fae5; color: #065f46; }
        .badge-archivado { background: #f1f5f9; color: #475569; }
        .action-btns { display: flex; gap: 4px; flex-wrap: wrap; }
        .action-btn { padding: 4px 10px; border: none; border-radius: 6px; font-size: 0.75rem; font-weight: 600; cursor: pointer; transition: all 0.2s; color: white; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
        .action-btn.leer { background: #3b82f6; }
        .action-btn.leer:hover { background: #2563eb; }
        .action-btn.proceso { background: #f59e0b; }
        .action-btn.proceso:hover { background: #d97706; }
        .action-btn.responder { background: #10b981; }
        .action-btn.responder:hover { background: #059669; }
        .action-btn.archivar { background: #6b7280; }
        .action-btn.archivar:hover { background: #4b5563; }
        .action-btn.eliminar { background: #ef4444; }
        .action-btn.eliminar:hover { background: #dc2626; }
        .empty-state { text-align: center; padding: 40px 20px; color: #94a3b8; }
        .empty-state i { font-size: 3rem; display: block; margin-bottom: 10px; }
        @media (max-width: 768px) { .filtros-bar .grupo { width: 100%; } .filtros-bar select, .filtros-bar input { width: 100%; } .contactos-stats { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 480px) { .contactos-stats { grid-template-columns: 1fr 1fr; } }
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
            <h1>Gestión de Contactos</h1>
            <p class="welcome">
                <i class="fas fa-inbox"></i> Administra los mensajes recibidos
            </p>
        </div>
        <div class="header-actions">
            <a href="../contacto.php" target="_blank" class="btn-header primary" style="background: #4c51bf;">
                <i class="fas fa-external-link-alt"></i> Ver página de contacto
            </a>
        </div>
    </div>

    <?php if ($mensaje): ?>
        <div class="mensaje <?php echo $tipo_mensaje; ?>">
            <i class="fas <?php echo $tipo_mensaje === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>

    <!-- Estadísticas -->
    <div class="contactos-stats">
        <div class="stat-item nuevo">
            <div class="number"><?php echo $status_counts['nuevo'] ?? 0; ?></div>
            <div class="label">🆕 Nuevos</div>
        </div>
        <div class="stat-item leido">
            <div class="number"><?php echo $status_counts['leido'] ?? 0; ?></div>
            <div class="label">👁️ Leídos</div>
        </div>
        <div class="stat-item proceso">
            <div class="number"><?php echo $status_counts['en_proceso'] ?? 0; ?></div>
            <div class="label">🔄 En proceso</div>
        </div>
        <div class="stat-item respondido">
            <div class="number"><?php echo $status_counts['respondido'] ?? 0; ?></div>
            <div class="label">✅ Respondidos</div>
        </div>
        <div class="stat-item archivado">
            <div class="number"><?php echo $status_counts['archivado'] ?? 0; ?></div>
            <div class="label">📦 Archivados</div>
        </div>
    </div>

    <!-- Filtros -->
    <form method="GET" class="filtros-bar" id="filtrosForm">
        <div class="grupo">
            <label for="status">Estado</label>
            <select name="status" id="status">
                <option value="todos" <?php echo $filtro_status === 'todos' ? 'selected' : ''; ?>>Todos</option>
                <option value="nuevo" <?php echo $filtro_status === 'nuevo' ? 'selected' : ''; ?>>🆕 Nuevo</option>
                <option value="leido" <?php echo $filtro_status === 'leido' ? 'selected' : ''; ?>>👁️ Leído</option>
                <option value="en_proceso" <?php echo $filtro_status === 'en_proceso' ? 'selected' : ''; ?>>🔄 En proceso</option>
                <option value="respondido" <?php echo $filtro_status === 'respondido' ? 'selected' : ''; ?>>✅ Respondido</option>
                <option value="archivado" <?php echo $filtro_status === 'archivado' ? 'selected' : ''; ?>>📦 Archivado</option>
            </select>
        </div>
        <div class="grupo">
            <label for="tipo">Tipo de interés</label>
            <select name="tipo" id="tipo">
                <option value="todos" <?php echo $filtro_tipo === 'todos' ? 'selected' : ''; ?>>Todos</option>
                <option value="compra" <?php echo $filtro_tipo === 'compra' ? 'selected' : ''; ?>>🏠 Compra</option>
                <option value="venta" <?php echo $filtro_tipo === 'venta' ? 'selected' : ''; ?>>💰 Venta</option>
                <option value="renta" <?php echo $filtro_tipo === 'renta' ? 'selected' : ''; ?>>📋 Renta</option>
                <option value="asesoria" <?php echo $filtro_tipo === 'asesoria' ? 'selected' : ''; ?>>🧑‍💼 Asesoría</option>
                <option value="otro" <?php echo $filtro_tipo === 'otro' ? 'selected' : ''; ?>>📌 Otro</option>
            </select>
        </div>
        <div class="grupo">
            <label for="busqueda">Buscar</label>
            <input type="text" name="busqueda" id="busqueda" placeholder="Nombre, email, teléfono..." value="<?php echo htmlspecialchars($filtro_busqueda); ?>">
        </div>
        <div class="grupo">
            <label for="fecha_desde">Desde</label>
            <input type="date" name="fecha_desde" id="fecha_desde" value="<?php echo htmlspecialchars($filtro_desde); ?>">
        </div>
        <div class="grupo">
            <label for="fecha_hasta">Hasta</label>
            <input type="date" name="fecha_hasta" id="fecha_hasta" value="<?php echo htmlspecialchars($filtro_hasta); ?>">
        </div>
        <button type="submit" class="btn-filtrar"><i class="fas fa-filter"></i> Filtrar</button>
        <a href="contactos.php" class="btn-limpiar"><i class="fas fa-undo"></i> Limpiar</a>
    </form>

    <!-- Tabla -->
    <div class="tabla-contactos">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Fecha</th>
                    <th>Nombre</th>
                    <th>Contacto</th>
                    <th>Asunto</th>
                    <th>Mensaje</th>
                    <th>Tipo</th>
                    <th>Propiedad</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($contactos)): ?>
                    <tr>
                        <td colspan="10">
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p><?php echo $tabla_existe ? 'No hay contactos que coincidan con los filtros' : 'La tabla "contactos" no está disponible'; ?></p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($contactos as $c): 
                        $badge = getBadgeEstado($c['status']);
                    ?>
                        <tr>
                            <td><strong>#<?php echo $c['id']; ?></strong></td>
                            <td><?php echo formatearFecha($c['fecha_contacto']); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($c['nombre']); ?></strong>
                                <?php if ($c['status'] === 'nuevo'): ?>
                                    <span style="display:inline-block; width:8px; height:8px; background:#e53e3e; border-radius:50%; margin-left:4px;" title="Nuevo"></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-size:0.85rem;">
                                    <div><i class="fas fa-envelope" style="color:#4c51bf; width:16px;"></i> <?php echo htmlspecialchars($c['email']); ?></div>
                                    <div><i class="fas fa-phone" style="color:#4c51bf; width:16px;"></i> <?php echo htmlspecialchars($c['telefono']); ?></div>
                                    <div style="font-size:0.7rem; color:#718096;"><?php echo getPreferenciaLabel($c['preferencia_contacto']); ?></div>
                                </div>
                            </td>
                            <td><strong><?php echo htmlspecialchars($c['asunto']); ?></strong></td>
                            <td>
                                <div class="mensaje-preview" title="<?php echo htmlspecialchars($c['mensaje']); ?>">
                                    <?php echo htmlspecialchars(substr($c['mensaje'], 0, 60)) . (strlen($c['mensaje']) > 60 ? '…' : ''); ?>
                                </div>
                            </td>
                            <td><?php echo getTipoInteresLabel($c['tipo_interes']); ?></td>
                            <td>
                                <?php if ($c['propiedad_interes'] && $c['propiedad_titulo']): ?>
                                    <span style="font-size:0.8rem; color:#2d3748;"><?php echo htmlspecialchars($c['propiedad_titulo']); ?></span>
                                <?php else: ?>
                                    <span style="color:#94a3b8; font-size:0.8rem;">—</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge-rol <?php echo $badge['class']; ?>"><?php echo $badge['label']; ?></span></td>
                            <td>
                                <div class="action-btns">
                                    <?php if ($c['status'] === 'nuevo'): ?>
                                        <button class="action-btn leer" onclick="cambiarEstado(<?php echo $c['id']; ?>, 'leido')" title="Marcar como leído">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($c['status'] !== 'archivado'): ?>
                                        <button class="action-btn proceso" onclick="cambiarEstado(<?php echo $c['id']; ?>, 'en_proceso')" title="En proceso">
                                            <i class="fas fa-spinner"></i>
                                        </button>
                                        <button class="action-btn responder" onclick="cambiarEstado(<?php echo $c['id']; ?>, 'respondido')" title="Respondido">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button class="action-btn archivar" onclick="cambiarEstado(<?php echo $c['id']; ?>, 'archivado')" title="Archivar">
                                            <i class="fas fa-archive"></i>
                                        </button>
                                    <?php endif; ?>
                                    <button class="action-btn eliminar" onclick="eliminarContacto(<?php echo $c['id']; ?>)" title="Eliminar permanentemente">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginación -->
    <?php if ($tabla_existe && $total_paginas > 1): ?>
        <div class="pagination" style="display:flex; justify-content:center; gap:6px; padding:20px 0; flex-wrap:wrap;">
            <?php if ($pagina_actual > 1): ?>
                <a href="?page=<?php echo $pagina_actual - 1; ?>&<?php echo http_build_query(array_merge($_GET, ['page' => null])); ?>" class="action-btn edit" style="background:#e2e8f0; color:#2d3748; padding:6px 12px;">
                    <i class="fas fa-chevron-left"></i>
                </a>
            <?php else: ?>
                <span style="padding:6px 12px; background:#f1f5f9; color:#94a3b8; border-radius:6px;"><i class="fas fa-chevron-left"></i></span>
            <?php endif; ?>

            <?php
            $rango = 2;
            $inicio = max(1, $pagina_actual - $rango);
            $fin = min($total_paginas, $pagina_actual + $rango);
            if ($inicio > 1): ?>
                <a href="?page=1&<?php echo http_build_query(array_merge($_GET, ['page' => null])); ?>" style="padding:6px 12px; border:1px solid #e2e8f0; border-radius:6px; text-decoration:none; color:#2d3748;">1</a>
                <?php if ($inicio > 2): ?><span style="padding:6px 12px;">…</span><?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $inicio; $i <= $fin; $i++): ?>
                <a href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_merge($_GET, ['page' => null])); ?>" style="padding:6px 12px; border:1px solid #e2e8f0; border-radius:6px; text-decoration:none; color:#2d3748; <?php echo $i === $pagina_actual ? 'background:#4c51bf; color:white; border-color:#4c51bf;' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>

            <?php if ($fin < $total_paginas): ?>
                <?php if ($fin < $total_paginas - 1): ?><span style="padding:6px 12px;">…</span><?php endif; ?>
                <a href="?page=<?php echo $total_paginas; ?>&<?php echo http_build_query(array_merge($_GET, ['page' => null])); ?>" style="padding:6px 12px; border:1px solid #e2e8f0; border-radius:6px; text-decoration:none; color:#2d3748;"><?php echo $total_paginas; ?></a>
            <?php endif; ?>

            <?php if ($pagina_actual < $total_paginas): ?>
                <a href="?page=<?php echo $pagina_actual + 1; ?>&<?php echo http_build_query(array_merge($_GET, ['page' => null])); ?>" class="action-btn edit" style="background:#e2e8f0; color:#2d3748; padding:6px 12px;">
                    <i class="fas fa-chevron-right"></i>
                </a>
            <?php else: ?>
                <span style="padding:6px 12px; background:#f1f5f9; color:#94a3b8; border-radius:6px;"><i class="fas fa-chevron-right"></i></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>

<script>
    // Menú móvil
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

    function cambiarEstado(id, nuevoEstado) {
        if (!confirm(`¿Cambiar estado del contacto #${id} a "${nuevoEstado}"?`)) return;
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="accion" value="cambiar_estado">
            <input type="hidden" name="contacto_id" value="${id}">
            <input type="hidden" name="nuevo_estado" value="${nuevoEstado}">
        `;
        document.body.appendChild(form);
        form.submit();
    }

    function eliminarContacto(id) {
        if (!confirm(`¿Eliminar permanentemente el contacto #${id}?`)) return;
        if (!confirm('Confirmar eliminación.')) return;
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="accion" value="eliminar_contacto">
            <input type="hidden" name="contacto_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
</script>

</body>
</html>