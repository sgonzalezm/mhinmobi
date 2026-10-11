<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/conexion.php';
require_once '../includes/auth.php';

// Verificar autenticación
if (!estaLogueado()) {
    header('Location: ../login.php');
    exit;
}

// Obtener datos del usuario
$usuario = obtenerUsuarioActual($conn);
if (!$usuario) {
    cerrarSesion();
    header('Location: ../login.php');
    exit;
}

// Verificar que sea administrador
if (!esAdmin()) {
    die('Acceso denegado. Solo administradores pueden acceder a este panel.');
}

// ===== FUNCIONES =====
function sanitizar($texto) {
    return htmlspecialchars(trim($texto ?? ''));
}

function formatearMoneda($monto) {
    if ($monto === null || $monto === '') {
        return '$0';
    }
    return '$' . number_format(floatval($monto), 0, ',', '.');
}

// ===== PROCESAR ACTUALIZACIONES =====
$mensaje = '';
$tipo_mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // === ACTUALIZAR COMISIÓN DE ASESOR (Manual) ===
    if (isset($_POST['accion']) && $_POST['accion'] === 'actualizar_comision_asesor') {
        try {
            $asesor_id = intval($_POST['asesor_id']);
            $nuevo_porcentaje = floatval($_POST['porcentaje']);
            
            if ($nuevo_porcentaje < 0 || $nuevo_porcentaje > 100) {
                throw new Exception('El porcentaje debe estar entre 0 y 100');
            }
            
            // Actualizar directamente en la tabla users
            $stmt = $conn->prepare("UPDATE users SET comision_porcentaje = ? WHERE id = ? AND role = 'asesor'");
            $stmt->execute([$nuevo_porcentaje, $asesor_id]);
            
            $mensaje = '✅ Comisión del asesor actualizada correctamente';
            $tipo_mensaje = 'success';
            
        } catch (Exception $e) {
            $mensaje = '❌ Error: ' . $e->getMessage();
            $tipo_mensaje = 'error';
        }
    }

    // === ACTUALIZAR NIVELES DE COMISIÓN (Configuración de referencia) ===
    if (isset($_POST['accion']) && $_POST['accion'] === 'actualizar_niveles') {
        try {
            foreach ($_POST['niveles'] as $id => $datos) {
                $stmt = $conn->prepare("
                    UPDATE comision_niveles 
                    SET nivel_nombre = ?,
                        ventas_min = ?,
                        ventas_max = ?,
                        comision_porcentaje = ?,
                        color_hex = ?,
                        icono = ?,
                        activo = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    sanitizar($datos['nombre']),
                    intval($datos['min']),
                    intval($datos['max']),
                    floatval($datos['porcentaje']),
                    sanitizar($datos['color']),
                    sanitizar($datos['icono']),
                    isset($datos['activo']) ? 1 : 0,
                    $id
                ]);
            }
            $mensaje = '✅ Niveles de comisión (referencia) actualizados correctamente';
            $tipo_mensaje = 'success';
            
        } catch (Exception $e) {
            $mensaje = '❌ Error al actualizar niveles: ' . $e->getMessage();
            $tipo_mensaje = 'error';
        }
    }
    
    // === AGREGAR NUEVO NIVEL ===
    if (isset($_POST['accion']) && $_POST['accion'] === 'agregar_nivel') {
        try {
            $stmt = $conn->prepare("
                INSERT INTO comision_niveles (nivel_nombre, nivel_orden, ventas_min, ventas_max, comision_porcentaje, color_hex, icono, activo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                sanitizar($_POST['nombre']),
                intval($_POST['orden']),
                intval($_POST['min']),
                intval($_POST['max']),
                floatval($_POST['porcentaje']),
                sanitizar($_POST['color']),
                sanitizar($_POST['icono']),
                isset($_POST['activo']) ? 1 : 0
            ]);
            $mensaje = '✅ Nuevo nivel creado correctamente';
            $tipo_mensaje = 'success';
            
        } catch (Exception $e) {
            $mensaje = '❌ Error al crear nivel: ' . $e->getMessage();
            $tipo_mensaje = 'error';
        }
    }

    // === MARCAR PROPIEDAD COMO ESCRITURADA ===
    if (isset($_POST['accion']) && $_POST['accion'] === 'marcar_escriturada') {
        try {
            $property_id = intval($_POST['property_id']);
            $fecha_input = trim($_POST['signed_at'] ?? '');

            if ($property_id <= 0) {
                throw new Exception('ID de propiedad inválido');
            }

            if ($fecha_input !== '') {
                $dt = DateTime::createFromFormat('Y-m-d\TH:i', $fecha_input);
                if (!$dt) {
                    $dt = DateTime::createFromFormat('Y-m-d', $fecha_input);
                }
                if (!$dt) {
                    throw new Exception('Formato de fecha inválido');
                }
                $fecha_sql = $dt->format('Y-m-d H:i:s');
            } else {
                $fecha_sql = date('Y-m-d H:i:s');
            }

            $stmt = $conn->prepare("SELECT id, title FROM properties WHERE id = ?");
            $stmt->execute([$property_id]);
            $prop = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$prop) {
                throw new Exception('Propiedad no encontrada');
            }

            $stmt = $conn->prepare("UPDATE properties SET signed_at = ? WHERE id = ?");
            $stmt->execute([$fecha_sql, $property_id]);

            $mensaje = '✅ Propiedad "' . sanitizar($prop['title']) . '" marcada como escriturada el ' . date('d/m/Y H:i', strtotime($fecha_sql));
            $tipo_mensaje = 'success';

        } catch (Exception $e) {
            $mensaje = '❌ Error: ' . $e->getMessage();
            $tipo_mensaje = 'error';
        }
    }

    // === DESMARCAR ESCRITURACIÓN ===
    if (isset($_POST['accion']) && $_POST['accion'] === 'desmarcar_escriturada') {
        try {
            $property_id = intval($_POST['property_id']);

            if ($property_id <= 0) {
                throw new Exception('ID de propiedad inválido');
            }

            $stmt = $conn->prepare("UPDATE properties SET signed_at = NULL WHERE id = ?");
            $stmt->execute([$property_id]);

            $mensaje = '✅ Escrituración removida correctamente';
            $tipo_mensaje = 'success';

        } catch (Exception $e) {
            $mensaje = '❌ Error: ' . $e->getMessage();
            $tipo_mensaje = 'error';
        }
    }
}

// ===== OBTENER DATOS =====

// 1. Obtener niveles de comisión (Solo para la pestaña de configuración)
$niveles = [];
try {
    $stmt = $conn->query("SELECT * FROM comision_niveles WHERE activo = 1 ORDER BY nivel_orden ASC");
    $niveles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error obteniendo niveles: " . $e->getMessage());
}

// 2. Obtener ASESORES (Usando comision_porcentaje de la tabla users)
$asesores = [];
try {
    $stmt = $conn->query("
        SELECT 
            u.id,
            u.name,
            u.email,
            u.telefono,
            u.comision_porcentaje as comision_actual,
            u.activo,
            COUNT(pt.id) as total_propiedades,
            SUM(CASE WHEN pt.status = 'completado' THEN 1 ELSE 0 END) as propiedades_vendidas,
            SUM(CASE WHEN pt.status = 'completado' THEN f.asking_price * f.commission_percentage / 100 ELSE 0 END) as total_comisiones,
            MAX(CASE WHEN pt.status = 'completado' THEN pt.updated_at ELSE NULL END) as ultima_venta,
            COUNT(CASE WHEN pt.status != 'completado' THEN 1 ELSE NULL END) as propiedades_en_proceso
        FROM users u
        LEFT JOIN property_tracking pt ON pt.initiated_by = u.id
        LEFT JOIN properties p ON pt.property_id = p.id
        LEFT JOIN property_financials f ON p.id = f.property_id
        WHERE u.role = 'asesor'
        GROUP BY u.id
        ORDER BY total_comisiones DESC
    ");
    $asesores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Agregar color de nivel basado en la tabla de referencia (solo para visualización)
    foreach ($asesores as &$a) {
        $ventas = $a['propiedades_vendidas'] ?? 0;
        $nivel_asignado = null;
        
        foreach ($niveles as $n) {
            if ($ventas >= $n['ventas_min'] && $ventas <= $n['ventas_max']) {
                $nivel_asignado = $n;
                break;
            }
        }
        
        if ($nivel_asignado) {
            $a['nivel_label'] = $nivel_asignado['icono'] . ' ' . $nivel_asignado['nivel_nombre'];
            $a['color'] = $nivel_asignado['color_hex'];
        } else {
            $a['nivel_label'] = 'Sin nivel';
            $a['color'] = '#6b7280';
        }
    }
    
} catch (PDOException $e) {
    error_log("Error obteniendo asesores: " . $e->getMessage());
}

// 3. Obtener CAPTADORES (sin métricas de propiedades aún)
$captadores = [];
try {
    $stmt = $conn->query("
        SELECT 
            u.id,
            u.name,
            u.email,
            u.telefono,
            u.activo,
            u.created_at
        FROM users u
        WHERE u.role = 'captador'
        ORDER BY u.name ASC
    ");
    $captadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error obteniendo captadores: " . $e->getMessage());
}

// 4. Estadísticas Generales
$stats = [
    'total_asesores' => count($asesores),
    'total_captadores' => count($captadores),
    'total_comisiones' => array_sum(array_column($asesores, 'total_comisiones')),
    'total_vendidas' => array_sum(array_column($asesores, 'propiedades_vendidas')),
    'en_proceso' => array_sum(array_column($asesores, 'propiedades_en_proceso'))
];

// 5. Obtener propiedades para el módulo de escrituración
$filtro_esc = $_GET['filtro_esc'] ?? 'todas';
$busqueda_esc = trim($_GET['buscar_esc'] ?? '');
$tab_activo = 'tab-asesores';
if (isset($_GET['filtro_esc']) || isset($_GET['buscar_esc'])) {
    $tab_activo = 'tab-escrituracion';
}

$propiedades = [];
$stats_esc = ['total' => 0, 'escrituradas' => 0, 'pendientes' => 0];

try {
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN signed_at IS NOT NULL THEN 1 ELSE 0 END) as escrituradas,
            SUM(CASE WHEN signed_at IS NULL THEN 1 ELSE 0 END) as pendientes
        FROM properties
    ");
    $stats_esc = $stmt->fetch(PDO::FETCH_ASSOC) ?: $stats_esc;

    $sql = "SELECT 
                p.id, p.title, p.estado, p.domicilio, p.status, p.signed_at, p.created_at,
                f.asking_price, u.name as asesor_nombre
            FROM properties p
            LEFT JOIN property_financials f ON p.id = f.property_id
            LEFT JOIN users u ON p.assigned_to = u.id
            WHERE 1=1";
    
    $params = [];
    if ($filtro_esc === 'escrituradas') $sql .= " AND p.signed_at IS NOT NULL";
    elseif ($filtro_esc === 'pendientes') $sql .= " AND p.signed_at IS NULL";

    if ($busqueda_esc !== '') {
        $sql .= " AND (p.title LIKE ? OR p.estado LIKE ? OR p.domicilio LIKE ?)";
        $like = '%' . $busqueda_esc . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }

    $sql .= " ORDER BY p.signed_at DESC, p.created_at DESC LIMIT 200";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $propiedades = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error obteniendo propiedades para escrituración: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Administración | Inmobiliaria MH</title>
    <link rel="stylesheet" href="../css/socios.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ===== ESTILOS GENERALES ===== */
        * { box-sizing: border-box; }
        .admin-container { padding: 20px 30px; max-width: 1400px; margin: 0 auto; }
        .admin-header-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .admin-header-bar h1 { font-size: 1.6rem; font-weight: 700; color: #0f172a; margin: 0; }
        .admin-header-bar h1 i { color: #10b981; margin-right: 10px; }
        .admin-user-info { display: flex; align-items: center; gap: 15px; background: #f8fafc; padding: 8px 16px 8px 12px; border-radius: 30px; border: 1px solid #e8edf4; }
        .admin-avatar { width: 36px; height: 36px; background: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 0.9rem; }
        .admin-name { font-weight: 600; color: #0f172a; font-size: 0.9rem; }
        .admin-role { font-size: 0.7rem; color: #64748b; background: #f1f5f9; padding: 2px 10px; border-radius: 12px; }
        .btn-logout { color: #ef4444; text-decoration: none; font-size: 0.85rem; padding: 6px 12px; border-radius: 6px; transition: background 0.15s; }
        .btn-logout:hover { background: #fee2e2; }

        .message-box { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-size: 0.9rem; }
        .message-box.success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .message-box.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: white; border: 1px solid #e8edf4; border-radius: 12px; padding: 20px; text-align: center; }
        .stat-card .stat-number { font-size: 2rem; font-weight: 700; color: #0f172a; }
        .stat-card .stat-label { font-size: 0.8rem; color: #64748b; margin-top: 4px; }
        .stat-card .stat-icon { font-size: 1.5rem; margin-bottom: 8px; display: inline-block; }
        .stat-card.green .stat-icon { color: #10b981; }
        .stat-card.blue .stat-icon { color: #3b82f6; }
        .stat-card.purple .stat-icon { color: #8b5cf6; }
        .stat-card.orange .stat-icon { color: #f59e0b; }

        /* ===== TABS ===== */
        .tabs { display: flex; gap: 5px; margin-bottom: 20px; border-bottom: 2px solid #e8edf4; flex-wrap: wrap; }
        .tab-btn { padding: 10px 20px; background: transparent; border: none; cursor: pointer; font-weight: 600; color: #64748b; border-bottom: 3px solid transparent; transition: all 0.2s; font-size: 0.9rem; }
        .tab-btn:hover { color: #0f172a; }
        .tab-btn.active { color: #10b981; border-bottom-color: #10b981; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* ===== ASESORES ===== */
        .asesores-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .asesor-card { background: white; border: 1px solid #e8edf4; border-radius: 12px; padding: 20px; transition: all 0.2s ease; position: relative; }
        .asesor-card:hover { box-shadow: 0 8px 25px rgba(0,0,0,0.08); transform: translateY(-2px); }
        .asesor-card .asesor-header { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
        .asesor-card .asesor-avatar { width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 1.2rem; flex-shrink: 0; }
        .asesor-card .asesor-info h3 { margin: 0; font-size: 1rem; color: #0f172a; }
        .asesor-card .asesor-info .asesor-email { font-size: 0.8rem; color: #64748b; }
        .asesor-card .nivel-badge { display: inline-block; padding: 2px 12px; border-radius: 12px; font-size: 0.7rem; font-weight: 600; background: #f1f5f9; color: #64748b; margin-top: 4px; }
        .asesor-card .stats-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; margin: 12px 0; }
        .asesor-card .stats-row .stat-mini { background: #f8fafc; padding: 8px 12px; border-radius: 8px; text-align: center; }
        .asesor-card .stats-row .stat-mini .number { font-size: 1.1rem; font-weight: 700; color: #0f172a; }
        .asesor-card .stats-row .stat-mini .label { font-size: 0.6rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.3px; }
        
        /* Caja de comisión informativa */
        .comision-info-box {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            padding: 12px;
            text-align: center;
            margin-top: 12px;
        }
        .comision-info-box .label { font-size: 0.7rem; color: #166534; text-transform: uppercase; font-weight: 600; }
        .comision-info-box .value { font-size: 1.5rem; font-weight: 800; color: #065f46; }
        .comision-info-box .hint { font-size: 0.7rem; color: #4ade80; margin-top: 4px; }

        /* ===== CAPTADORES ===== */
        .captadores-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
        .captador-card { background: white; border: 1px solid #e8edf4; border-radius: 12px; padding: 20px; }
        .captador-card .captador-header { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
        .captador-card .captador-avatar { width: 48px; height: 48px; border-radius: 50%; background: #8b5cf6; color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.2rem; }
        .captador-card .captador-info h3 { margin: 0; font-size: 1rem; color: #0f172a; }
        .captador-card .captador-info .email { font-size: 0.8rem; color: #64748b; }
        .captador-card .stats-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 12px; }
        .captador-card .stat-mini { background: #f8fafc; padding: 8px; border-radius: 8px; text-align: center; }
        .captador-card .stat-mini .number { font-size: 1.1rem; font-weight: 700; color: #0f172a; }
        .captador-card .stat-mini .label { font-size: 0.6rem; color: #64748b; text-transform: uppercase; }

        /* ===== TABLAS (ESCRITURACIÓN, NIVELES, HISTORIAL) ===== */
        .table-container { background: white; border: 1px solid #e8edf4; border-radius: 12px; padding: 20px; overflow-x: auto; }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th { background: #f8fafc; padding: 10px 14px; text-align: left; font-size: 0.75rem; font-weight: 600; color: #64748b; text-transform: uppercase; border-bottom: 2px solid #e8edf4; }
        .data-table td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; vertical-align: middle; }
        .data-table tr:hover td { background: #f8fafc; }
        .data-table input[type="text"], .data-table input[type="number"], .data-table input[type="color"] { padding: 4px 8px; border: 1px solid #e8edf4; border-radius: 6px; font-size: 0.85rem; width: 100%; }
        
        .badge-esc { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 12px; font-size: 0.7rem; font-weight: 600; }
        .badge-esc.si { background: #d1fae5; color: #065f46; }
        .badge-esc.no { background: #fef3c7; color: #92400e; }

        .btn-agregar-nivel, .btn-guardar-niveles { padding: 8px 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; transition: background 0.15s; margin-top: 15px; }
        .btn-agregar-nivel { background: #3b82f6; color: white; }
        .btn-agregar-nivel:hover { background: #2563eb; }
        .btn-guardar-niveles { background: #10b981; color: white; }
        .btn-guardar-niveles:hover { background: #059669; }

        .btn-esc { padding: 5px 12px; border: none; border-radius: 6px; cursor: pointer; font-size: 0.75rem; font-weight: 600; transition: all 0.15s; white-space: nowrap; }
        .btn-esc.marcar { background: #10b981; color: white; }
        .btn-esc.marcar:hover { background: #059669; }
        .btn-esc.quitar { background: #fee2e2; color: #b91c1c; }
        .btn-esc.quitar:hover { background: #fecaca; }
        .esc-actions { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
        .esc-actions input[type="datetime-local"] { padding: 4px 8px; border: 1px solid #e8edf4; border-radius: 6px; font-size: 0.78rem; width: 170px; }

        /* ===== PLACEHOLDERS ===== */
        .placeholder-box { text-align: center; padding: 60px 20px; color: #64748b; background: white; border-radius: 12px; border: 1px dashed #cbd5e1; }
        .placeholder-box i { font-size: 4rem; color: #d1d5db; margin-bottom: 15px; display: block; }
        .placeholder-box h3 { color: #0f172a; margin-bottom: 10px; }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .admin-container { padding: 15px; }
            .admin-header-bar { flex-direction: column; align-items: stretch; }
            .asesores-grid, .captadores-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .tabs { flex-direction: column; }
            .tab-btn { text-align: center; }
        }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<?php include '../modulos/sidebar.php'; ?>

<main class="main-content">
    <div class="admin-container">
        <!-- HEADER -->
        <div class="admin-header-bar">
            <h1><i class="fas fa-crown"></i> Panel de Administración</h1>
            <div class="admin-user-info">
                <div class="admin-avatar"><?php echo strtoupper(substr($usuario['name'] ?? 'A', 0, 1)); ?></div>
                <a href="/actualizar_comisiones.php" class="btn-primary" style="text-decoration: none;">
                    <i class="fas fa-sync-alt"></i> Recalcular Comisiones
                </a>
                <span class="admin-name"><?php echo sanitizar($usuario['name'] ?? 'Administrador'); ?></span>
                <span class="admin-role"><i class="fas fa-user-tag"></i> Admin</span>
                <a href="../logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Salir</a>
            </div>
        </div>

        <!-- MENSAJES -->
        <?php if ($mensaje): ?>
            <div class="message-box <?php echo $tipo_mensaje; ?>">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <!-- STATS -->
        <div class="stats-grid">
            <div class="stat-card green">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-number"><?php echo $stats['total_asesores']; ?></div>
                <div class="stat-label">Total Asesores</div>
            </div>
            <div class="stat-card blue">
                <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
                <div class="stat-number"><?php echo formatearMoneda($stats['total_comisiones']); ?></div>
                <div class="stat-label">Total Comisiones</div>
            </div>
            <div class="stat-card purple">
                <div class="stat-icon"><i class="fas fa-home"></i></div>
                <div class="stat-number"><?php echo $stats['total_vendidas']; ?></div>
                <div class="stat-label">Propiedades Vendidas</div>
            </div>
            <div class="stat-card orange">
                <div class="stat-icon"><i class="fas fa-sync"></i></div>
                <div class="stat-number"><?php echo $stats['en_proceso']; ?></div>
                <div class="stat-label">En Proceso</div>
            </div>
        </div>

        <!-- ===== TABS ===== -->
        <div class="tabs">
            <button class="tab-btn <?php echo $tab_activo === 'tab-asesores' ? 'active' : ''; ?>" data-tab="tab-asesores">
                <i class="fas fa-users"></i> Asesores
            </button>
            <button class="tab-btn <?php echo $tab_activo === 'tab-captadores' ? 'active' : ''; ?>" data-tab="tab-captadores">
                <i class="fas fa-hand-holding-heart"></i> Captadores
            </button>
            <button class="tab-btn <?php echo $tab_activo === 'tab-niveles' ? 'active' : ''; ?>" data-tab="tab-niveles">
                <i class="fas fa-layer-group"></i> Niveles (Referencia)
            </button>
            <button class="tab-btn <?php echo $tab_activo === 'tab-escrituracion' ? 'active' : ''; ?>" data-tab="tab-escrituracion">
                <i class="fas fa-file-contract"></i> Escrituración
            </button>
            <button class="tab-btn <?php echo $tab_activo === 'tab-historial' ? 'active' : ''; ?>" data-tab="tab-historial">
                <i class="fas fa-history"></i> Historial
            </button>
            <button class="tab-btn <?php echo $tab_activo === 'tab-analiticos' ? 'active' : ''; ?>" data-tab="tab-analiticos">
                <i class="fas fa-chart-bar"></i> Analíticos
            </button>
        </div>

        <!-- ===== TAB 1: ASESORES ===== -->
        <div class="tab-content <?php echo $tab_activo === 'tab-asesores' ? 'active' : ''; ?>" id="tab-asesores">
            <div class="asesores-grid">
                <?php foreach ($asesores as $asesor): ?>
                <div class="asesor-card">
                    <div class="asesor-header">
                        <div class="asesor-avatar" style="background: <?php echo $asesor['color']; ?>;">
                            <?php echo strtoupper(substr($asesor['name'], 0, 1)); ?>
                        </div>
                        <div class="asesor-info">
                            <h3><?php echo sanitizar($asesor['name']); ?></h3>
                            <div class="asesor-email"><i class="fas fa-envelope"></i> <?php echo sanitizar($asesor['email']); ?></div>
                            <span class="nivel-badge" style="background: <?php echo $asesor['color']; ?>20; color: <?php echo $asesor['color']; ?>;">
                                <?php echo $asesor['nivel_label']; ?>
                            </span>
                        </div>
                    </div>

                    <div class="stats-row">
                        <div class="stat-mini">
                            <div class="number"><?php echo $asesor['propiedades_vendidas']; ?></div>
                            <div class="label">Vendidas</div>
                        </div>
                        <div class="stat-mini">
                            <div class="number"><?php echo $asesor['propiedades_en_proceso']; ?></div>
                            <div class="label">En Proceso</div>
                        </div>
                        <div class="stat-mini">
                            <div class="number"><?php echo formatearMoneda($asesor['total_comisiones']); ?></div>
                            <div class="label">Comisiones</div>
                        </div>
                    </div>

                    <!-- Comisión Informativa (Solo lectura) -->
                    <div class="comision-info-box">
                        <div class="label">Comisión Asignada</div>
                        <div class="value"><?php echo $asesor['comision_actual']; ?>%</div>
                        <div class="hint">Este valor se gestiona en la base de datos</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ===== TAB 2: CAPTADORES ===== -->
        <div class="tab-content <?php echo $tab_activo === 'tab-captadores' ? 'active' : ''; ?>" id="tab-captadores">
            <?php if (empty($captadores)): ?>
                <div class="placeholder-box">
                    <i class="fas fa-user-slash"></i>
                    <h3>No hay captadores registrados</h3>
                    <p>Los usuarios con rol "captador" aparecerán aquí automáticamente.</p>
                </div>
            <?php else: ?>
                <div class="captadores-grid">
                    <?php foreach ($captadores as $captador): ?>
                    <div class="captador-card">
                        <div class="captador-header">
                            <div class="captador-avatar">
                                <?php echo strtoupper(substr($captador['name'], 0, 1)); ?>
                            </div>
                            <div class="captador-info">
                                <h3><?php echo sanitizar($captador['name']); ?></h3>
                                <div class="email"><i class="fas fa-envelope"></i> <?php echo sanitizar($captador['email']); ?></div>
                                <?php if (!empty($captador['telefono'])): ?>
                                    <div class="email"><i class="fas fa-phone"></i> <?php echo sanitizar($captador['telefono']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #f1f5f9;">
                            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.8rem;">
                                <span style="color: #64748b;">
                                    <i class="fas fa-calendar"></i> 
                                    Desde <?php echo date('d/m/Y', strtotime($captador['created_at'])); ?>
                                </span>
                                <span class="nivel-badge" style="background: <?php echo $captador['activo'] ? '#d1fae5' : '#fee2e2'; ?>; color: <?php echo $captador['activo'] ? '#065f46' : '#991b1b'; ?>;">
                                    <?php echo $captador['activo'] ? 'Activo' : 'Inactivo'; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Placeholder para cuando asocies propiedades -->
                        <div style="margin-top: 12px; padding: 10px; background: #f8fafc; border-radius: 8px; text-align: center;">
                            <div style="font-size: 0.7rem; color: #94a3b8; text-transform: uppercase; font-weight: 600;">
                                Propiedades captadas
                            </div>
                            <div style="font-size: 1.5rem; font-weight: 700; color: #cbd5e1;">—</div>
                            <div style="font-size: 0.65rem; color: #cbd5e1;">
                                Pendiente de asociación
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ===== TAB 3: NIVELES DE COMISIÓN (REFERENCIA) ===== -->
        <div class="tab-content <?php echo $tab_activo === 'tab-niveles' ? 'active' : ''; ?>" id="tab-niveles">
            <div class="table-container">
                <p style="font-size: 0.85rem; color: #64748b; margin-bottom: 15px;">
                    <i class="fas fa-info-circle"></i> Esta tabla es solo de referencia para asignar comisiones manualmente en la base de datos.
                </p>
                <form method="POST" action="">
                    <input type="hidden" name="accion" value="actualizar_niveles">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width: 40px;">#</th>
                                <th>Nombre</th>
                                <th style="width: 80px;">Ventas Min</th>
                                <th style="width: 80px;">Ventas Max</th>
                                <th style="width: 100px;">Comisión %</th>
                                <th style="width: 60px;">Color</th>
                                <th style="width: 60px;">Icono</th>
                                <th style="width: 60px;">Activo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($niveles as $nivel): ?>
                            <tr>
                                <td><?php echo $nivel['nivel_orden']; ?></td>
                                <td><input type="text" name="niveles[<?php echo $nivel['id']; ?>][nombre]" value="<?php echo sanitizar($nivel['nivel_nombre']); ?>" required></td>
                                <td><input type="number" name="niveles[<?php echo $nivel['id']; ?>][min]" value="<?php echo $nivel['ventas_min']; ?>" required></td>
                                <td><input type="number" name="niveles[<?php echo $nivel['id']; ?>][max]" value="<?php echo $nivel['ventas_max']; ?>" required></td>
                                <td><input type="number" name="niveles[<?php echo $nivel['id']; ?>][porcentaje]" value="<?php echo $nivel['comision_porcentaje']; ?>" min="0" max="100" step="0.5" required></td>
                                <td><input type="color" name="niveles[<?php echo $nivel['id']; ?>][color]" value="<?php echo $nivel['color_hex']; ?>"></td>
                                <td><input type="text" name="niveles[<?php echo $nivel['id']; ?>][icono]" value="<?php echo $nivel['icono']; ?>" style="width: 50px; text-align: center;"></td>
                                <td style="text-align: center;"><input type="checkbox" name="niveles[<?php echo $nivel['id']; ?>][activo]" <?php echo $nivel['activo'] ? 'checked' : ''; ?>></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div style="display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap;">
                        <button type="submit" class="btn-guardar-niveles"><i class="fas fa-save"></i> Guardar Niveles</button>
                        <button type="button" class="btn-agregar-nivel" onclick="agregarNivel()"><i class="fas fa-plus"></i> Agregar Nivel</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ===== TAB 4: ESCRITURACIÓN ===== -->
        <div class="tab-content <?php echo $tab_activo === 'tab-escrituracion' ? 'active' : ''; ?>" id="tab-escrituracion">
            <!-- Stats rápidas -->
            <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); margin-bottom: 20px;">
                <div class="stat-card" style="padding: 15px;">
                    <div class="stat-number" style="font-size: 1.5rem;"><?php echo intval($stats_esc['total'] ?? 0); ?></div>
                    <div class="stat-label">Total Propiedades</div>
                </div>
                <div class="stat-card" style="padding: 15px;">
                    <div class="stat-number" style="font-size: 1.5rem; color: #10b981;"><?php echo intval($stats_esc['escrituradas'] ?? 0); ?></div>
                    <div class="stat-label">Escrituradas</div>
                </div>
                <div class="stat-card" style="padding: 15px;">
                    <div class="stat-number" style="font-size: 1.5rem; color: #f59e0b;"><?php echo intval($stats_esc['pendientes'] ?? 0); ?></div>
                    <div class="stat-label">Pendientes</div>
                </div>
            </div>

            <!-- Filtros y búsqueda -->
            <div style="background: white; padding: 14px 18px; border-radius: 12px; border: 1px solid #e8edf4; margin-bottom: 18px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                    <a href="?filtro_esc=todas<?php echo $busqueda_esc ? '&buscar_esc=' . urlencode($busqueda_esc) : ''; ?>" class="tab-btn" style="padding: 6px 14px; border-radius: 20px; font-size: 0.8rem; text-decoration: none; color: <?php echo $filtro_esc === 'todas' ? '#10b981' : '#64748b'; ?>; background: <?php echo $filtro_esc === 'todas' ? '#ecfdf5' : '#f1f5f9'; ?>;">Todas</a>
                    <a href="?filtro_esc=escrituradas<?php echo $busqueda_esc ? '&buscar_esc=' . urlencode($busqueda_esc) : ''; ?>" class="tab-btn" style="padding: 6px 14px; border-radius: 20px; font-size: 0.8rem; text-decoration: none; color: <?php echo $filtro_esc === 'escrituradas' ? '#10b981' : '#64748b'; ?>; background: <?php echo $filtro_esc === 'escrituradas' ? '#ecfdf5' : '#f1f5f9'; ?>;">Escrituradas</a>
                    <a href="?filtro_esc=pendientes<?php echo $busqueda_esc ? '&buscar_esc=' . urlencode($busqueda_esc) : ''; ?>" class="tab-btn" style="padding: 6px 14px; border-radius: 20px; font-size: 0.8rem; text-decoration: none; color: <?php echo $filtro_esc === 'pendientes' ? '#10b981' : '#64748b'; ?>; background: <?php echo $filtro_esc === 'pendientes' ? '#ecfdf5' : '#f1f5f9'; ?>;">Pendientes</a>
                </div>
                <form method="GET" action="" style="display: flex; gap: 6px; flex: 1; min-width: 200px;">
                    <input type="hidden" name="filtro_esc" value="<?php echo sanitizar($filtro_esc); ?>">
                    <input type="text" name="buscar_esc" placeholder="Buscar..." value="<?php echo sanitizar($busqueda_esc); ?>" style="flex: 1; padding: 7px 12px; border: 1px solid #e8edf4; border-radius: 8px; font-size: 0.85rem;">
                    <button type="submit" style="padding: 7px 14px; background: #0f172a; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 0.8rem; font-weight: 600;">Buscar</button>
                </form>
            </div>

            <!-- Tabla -->
            <div class="table-container">
                <?php if (empty($propiedades)): ?>
                    <div class="placeholder-box" style="border: none;">
                        <i class="fas fa-search"></i>
                        <h3>No se encontraron propiedades</h3>
                        <p>Prueba con otros filtros de búsqueda.</p>
                    </div>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Propiedad</th>
                                <th>Asesor</th>
                                <th>Precio</th>
                                <th>Estado</th>
                                <th>Escriturada</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($propiedades as $prop): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600; color: #0f172a;"><?php echo sanitizar($prop['title']); ?></div>
                                    <div style="font-size: 0.75rem; color: #64748b;">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <?php echo sanitizar(implode(', ', array_filter([$prop['estado'] ?? '', $prop['domicilio'] ?? '']))); ?>
                                    </div>
                                </td>
                                <td><?php echo sanitizar($prop['asesor_nombre'] ?? '—'); ?></td>
                                <td><?php echo formatearMoneda($prop['asking_price'] ?? 0); ?></td>
                                <td>
                                    <span class="badge-esc <?php echo $prop['signed_at'] ? 'si' : 'no'; ?>">
                                        <?php if ($prop['signed_at']): ?>
                                            <i class="fas fa-check-circle"></i> Sí
                                        <?php else: ?>
                                            <i class="fas fa-clock"></i> Pendiente
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($prop['signed_at']): ?>
                                        <strong><?php echo date('d/m/Y', strtotime($prop['signed_at'])); ?></strong>
                                        <div style="font-size: 0.7rem; color: #94a3b8;"><?php echo date('H:i', strtotime($prop['signed_at'])); ?> hs</div>
                                    <?php else: ?>
                                        <span style="color: #94a3b8;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="esc-actions">
                                        <?php if ($prop['signed_at']): ?>
                                            <form method="POST" action="" style="margin:0;" onsubmit="return confirm('¿Quitar la marca de escrituración?');">
                                                <input type="hidden" name="accion" value="desmarcar_escriturada">
                                                <input type="hidden" name="property_id" value="<?php echo $prop['id']; ?>">
                                                <button type="submit" class="btn-esc quitar"><i class="fas fa-undo"></i> Quitar</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" action="" style="margin:0; display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                                                <input type="hidden" name="accion" value="marcar_escriturada">
                                                <input type="hidden" name="property_id" value="<?php echo $prop['id']; ?>">
                                                <input type="datetime-local" name="signed_at" title="Dejar vacío para usar la fecha actual">
                                                <button type="submit" class="btn-esc marcar"><i class="fas fa-file-signature"></i> Marcar</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- ===== TAB 5: HISTORIAL (PENDIENTE) ===== -->
        <div class="tab-content <?php echo $tab_activo === 'tab-historial' ? 'active' : ''; ?>" id="tab-historial">
            <div class="placeholder-box">
                <i class="fas fa-history"></i>
                <h3>Historial de Cambios</h3>
                <p>Esta sección está en desarrollo. Aquí se registrarán los cambios de comisiones y niveles.</p>
            </div>
        </div>

        <!-- ===== TAB 6: ANALÍTICOS (PENDIENTE) ===== -->
        <div class="tab-content <?php echo $tab_activo === 'tab-analiticos' ? 'active' : ''; ?>" id="tab-analiticos">
            <div class="placeholder-box">
                <i class="fas fa-chart-pie"></i>
                <h3>Módulo de Analíticos</h3>
                <p>Próximamente: Gráficas de rendimiento, comparativas de ventas y proyecciones.</p>
            </div>
        </div>
    </div>
</main>

<script>
// ===== TABS =====
document.addEventListener('DOMContentLoaded', function() {
    const tabs = document.querySelectorAll('.tab-btn');
    const contents = document.querySelectorAll('.tab-content');

    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            tabs.forEach(t => t.classList.remove('active'));
            contents.forEach(c => c.classList.remove('active'));
            
            this.classList.add('active');
            const target = document.getElementById(this.dataset.tab);
            if (target) target.classList.add('active');
        });
    });

    // ===== MENÚ MÓVIL =====
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (menuToggle && sidebar && overlay) {
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('show');
            document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
        });

        overlay.addEventListener('click', function() {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
            document.body.style.overflow = '';
        });
    }
});

// ===== AGREGAR NUEVO NIVEL =====
function agregarNivel() {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '';
    form.innerHTML = `
        <input type="hidden" name="accion" value="agregar_nivel">
        <div style="background: #f8fafc; padding: 15px; border-radius: 8px; margin-top: 15px; display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; border: 1px solid #e2e8f0;">
            <div><label style="font-size: 0.75rem; font-weight: 600; color: #64748b;">Nombre</label><input type="text" name="nombre" placeholder="Ej: Diamante" required style="width: 100%; padding: 6px 10px; border: 1px solid #e8edf4; border-radius: 6px;"></div>
            <div><label style="font-size: 0.75rem; font-weight: 600; color: #64748b;">Orden</label><input type="number" name="orden" placeholder="6" required style="width: 100%; padding: 6px 10px; border: 1px solid #e8edf4; border-radius: 6px;"></div>
            <div><label style="font-size: 0.75rem; font-weight: 600; color: #64748b;">Ventas Min</label><input type="number" name="min" placeholder="0" required style="width: 100%; padding: 6px 10px; border: 1px solid #e8edf4; border-radius: 6px;"></div>
            <div><label style="font-size: 0.75rem; font-weight: 600; color: #64748b;">Ventas Max</label><input type="number" name="max" placeholder="999" required style="width: 100%; padding: 6px 10px; border: 1px solid #e8edf4; border-radius: 6px;"></div>
            <div><label style="font-size: 0.75rem; font-weight: 600; color: #64748b;">Comisión %</label><input type="number" name="porcentaje" placeholder="10" min="0" max="100" step="0.5" required style="width: 100%; padding: 6px 10px; border: 1px solid #e8edf4; border-radius: 6px;"></div>
            <div><label style="font-size: 0.75rem; font-weight: 600; color: #64748b;">Color</label><input type="color" name="color" value="#10b981" style="width: 100%; padding: 2px; border: 1px solid #e8edf4; border-radius: 6px; height: 36px;"></div>
            <div><label style="font-size: 0.75rem; font-weight: 600; color: #64748b;">Icono</label><input type="text" name="icono" placeholder="🌟" style="width: 100%; padding: 6px 10px; border: 1px solid #e8edf4; border-radius: 6px;"></div>
            <div style="display: flex; align-items: end; gap: 8px;">
                <label style="font-size: 0.75rem; font-weight: 600; color: #64748b;"><input type="checkbox" name="activo" checked> Activo</label>
                <button type="submit" style="padding: 6px 20px; background: #10b981; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;"><i class="fas fa-plus"></i> Crear</button>
                <button type="button" onclick="this.closest('form').remove()" style="padding: 6px 12px; background: #ef4444; color: white; border: none; border-radius: 6px; cursor: pointer;"><i class="fas fa-times"></i></button>
            </div>
        </div>
    `;
    const container = document.querySelector('.table-container');
    const buttons = container.querySelector('div:last-child');
    container.insertBefore(form, buttons);
}
</script>
</body>
</html>