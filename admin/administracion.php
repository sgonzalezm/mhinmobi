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
    
    // === ACTUALIZAR COMISIÓN DE ASESOR ===
    if (isset($_POST['accion']) && $_POST['accion'] === 'actualizar_comision_asesor') {
        try {
            $asesor_id = intval($_POST['asesor_id']);
            $nuevo_porcentaje = floatval($_POST['porcentaje']);
            $motivo = sanitizar($_POST['motivo'] ?? 'Ajuste manual');
            
            if ($nuevo_porcentaje < 0 || $nuevo_porcentaje > 100) {
                throw new Exception('El porcentaje debe estar entre 0 y 100');
            }
            
            // Obtener comisión anterior
            $stmt = $conn->prepare("SELECT comision_porcentaje FROM users WHERE id = ?");
            $stmt->execute([$asesor_id]);
            $anterior = $stmt->fetchColumn();
            
            // Actualizar
            $stmt = $conn->prepare("UPDATE users SET comision_porcentaje = ? WHERE id = ? AND role = 'asesor'");
            $stmt->execute([$nuevo_porcentaje, $asesor_id]);
            
            // Registrar en historial
            if (isset($_POST['registrar_historial']) && $_POST['registrar_historial'] == '1') {
                $stmt = $conn->prepare("
                    INSERT INTO comision_historial (usuario_id, comision_anterior, comision_nueva, motivo, modificado_por) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $asesor_id,
                    $anterior ?: 0,
                    $nuevo_porcentaje,
                    $motivo,
                    $_SESSION['usuario_id']
                ]);
            }
            
            $mensaje = '✅ Comisión actualizada correctamente';
            $tipo_mensaje = 'success';
            
        } catch (Exception $e) {
            $mensaje = '❌ Error: ' . $e->getMessage();
            $tipo_mensaje = 'error';
        }
    }
    
    // === ACTUALIZAR NIVELES DE COMISIÓN ===
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
            $mensaje = '✅ Niveles de comisión actualizados correctamente';
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
}

// ===== OBTENER DATOS =====

// 1. Obtener niveles de comisión
$niveles = [];
try {
    $stmt = $conn->query("
        SELECT * FROM comision_niveles 
        WHERE activo = 1 
        ORDER BY nivel_orden ASC
    ");
    $niveles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error obteniendo niveles: " . $e->getMessage());
}

// 2. Obtener asesores con sus datos
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
            AVG(CASE WHEN pt.status = 'completado' THEN f.asking_price * f.commission_percentage / 100 ELSE NULL END) as promedio_comision,
            MAX(CASE WHEN pt.status = 'completado' THEN pt.updated_at ELSE NULL END) as ultima_venta,
            COUNT(CASE WHEN pt.status != 'completado' THEN 1 ELSE NULL END) as propiedades_en_proceso,
            AVG(CASE WHEN pt.status = 'completado' THEN DATEDIFF(pt.updated_at, pt.initiated_at) ELSE NULL END) as dias_promedio
        FROM users u
        LEFT JOIN property_tracking pt ON pt.initiated_by = u.id
        LEFT JOIN properties p ON pt.property_id = p.id
        LEFT JOIN property_financials f ON p.id = f.property_id
        WHERE u.role = 'asesor'
        GROUP BY u.id
        ORDER BY total_comisiones DESC
    ");
    $asesores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular nivel basado en ventas
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
            $a['nivel'] = $nivel_asignado;
            $a['nivel_label'] = $nivel_asignado['icono'] . ' ' . $nivel_asignado['nivel_nombre'];
            $a['color'] = $nivel_asignado['color_hex'];
            $a['comision_sugerida'] = $nivel_asignado['comision_porcentaje'];
        } else {
            $a['nivel_label'] = 'Sin nivel';
            $a['color'] = '#6b7280';
            $a['comision_sugerida'] = 5;
        }
        
        // Si no tiene comisión asignada, usar la sugerida
        if ($a['comision_actual'] === null) {
            $a['comision_actual'] = $a['comision_sugerida'];
        }
    }
    
} catch (PDOException $e) {
    error_log("Error obteniendo asesores: " . $e->getMessage());
}

// 3. Estadísticas
$stats = [
    'total_asesores' => count($asesores),
    'total_comisiones' => array_sum(array_column($asesores, 'total_comisiones')),
    'total_vendidas' => array_sum(array_column($asesores, 'propiedades_vendidas')),
    'en_proceso' => array_sum(array_column($asesores, 'propiedades_en_proceso'))
];

// 4. Obtener historial reciente (últimos 10 cambios)
$historial = [];
try {
    $stmt = $conn->query("
        SELECT 
            h.*,
            u1.name as asesor_nombre,
            u2.name as admin_nombre
        FROM comision_historial h
        JOIN users u1 ON h.usuario_id = u1.id
        JOIN users u2 ON h.modificado_por = u2.id
        ORDER BY h.created_at DESC
        LIMIT 10
    ");
    $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error obteniendo historial: " . $e->getMessage());
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
        /* ===== ESTILOS (igual que antes pero con ajustes) ===== */
        * { box-sizing: border-box; }

        .admin-container {
            padding: 20px 30px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .admin-header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .admin-header-bar h1 {
            font-size: 1.6rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }

        .admin-header-bar h1 i {
            color: #10b981;
            margin-right: 10px;
        }

        .admin-user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            background: #f8fafc;
            padding: 8px 16px 8px 12px;
            border-radius: 30px;
            border: 1px solid #e8edf4;
        }

        .admin-avatar {
            width: 36px;
            height: 36px;
            background: #10b981;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 0.9rem;
        }

        .admin-name {
            font-weight: 600;
            color: #0f172a;
            font-size: 0.9rem;
        }

        .admin-role {
            font-size: 0.7rem;
            color: #64748b;
            background: #f1f5f9;
            padding: 2px 10px;
            border-radius: 12px;
        }

        .btn-logout {
            color: #ef4444;
            text-decoration: none;
            font-size: 0.85rem;
            padding: 6px 12px;
            border-radius: 6px;
            transition: background 0.15s;
        }

        .btn-logout:hover {
            background: #fee2e2;
        }

        .message-box {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
        }

        .message-box.success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }

        .message-box.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border: 1px solid #e8edf4;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }

        .stat-card .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #0f172a;
        }

        .stat-card .stat-label {
            font-size: 0.8rem;
            color: #64748b;
            margin-top: 4px;
        }

        .stat-card .stat-icon {
            font-size: 1.5rem;
            margin-bottom: 8px;
            display: inline-block;
        }

        .stat-card.green .stat-icon { color: #10b981; }
        .stat-card.blue .stat-icon { color: #3b82f6; }
        .stat-card.purple .stat-icon { color: #8b5cf6; }
        .stat-card.orange .stat-icon { color: #f59e0b; }

        /* ===== TABS ===== */
        .tabs {
            display: flex;
            gap: 5px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e8edf4;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 10px 20px;
            background: transparent;
            border: none;
            cursor: pointer;
            font-weight: 600;
            color: #64748b;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
            font-size: 0.9rem;
        }

        .tab-btn:hover {
            color: #0f172a;
        }

        .tab-btn.active {
            color: #10b981;
            border-bottom-color: #10b981;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* ===== ASESORES ===== */
        .asesores-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .asesor-card {
            background: white;
            border: 1px solid #e8edf4;
            border-radius: 12px;
            padding: 20px;
            transition: all 0.2s ease;
            position: relative;
        }

        .asesor-card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            transform: translateY(-2px);
        }

        .asesor-card .asesor-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .asesor-card .asesor-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .asesor-card .asesor-info h3 {
            margin: 0;
            font-size: 1rem;
            color: #0f172a;
        }

        .asesor-card .asesor-info .asesor-email {
            font-size: 0.8rem;
            color: #64748b;
        }

        .asesor-card .nivel-badge {
            display: inline-block;
            padding: 2px 12px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            background: #f1f5f9;
            color: #64748b;
            margin-top: 4px;
        }

        .asesor-card .stats-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
            margin: 12px 0;
        }

        .asesor-card .stats-row .stat-mini {
            background: #f8fafc;
            padding: 8px 12px;
            border-radius: 8px;
            text-align: center;
        }

        .asesor-card .stats-row .stat-mini .number {
            font-size: 1.1rem;
            font-weight: 700;
            color: #0f172a;
        }

        .asesor-card .stats-row .stat-mini .label {
            font-size: 0.6rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .asesor-card .comision-form {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #f1f5f9;
            flex-wrap: wrap;
        }

        .asesor-card .comision-form label {
            font-size: 0.8rem;
            font-weight: 600;
            color: #64748b;
        }

        .asesor-card .comision-form input[type="number"] {
            width: 70px;
            padding: 4px 8px;
            border: 1px solid #e8edf4;
            border-radius: 6px;
            font-size: 0.9rem;
            text-align: center;
        }

        .asesor-card .comision-form input[type="number"]:focus {
            outline: none;
            border-color: #10b981;
        }

        .asesor-card .comision-form .sugerida {
            font-size: 0.7rem;
            color: #64748b;
        }

        .asesor-card .comision-form .btn-actualizar {
            padding: 4px 12px;
            background: #10b981;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.75rem;
            font-weight: 600;
            transition: background 0.15s;
        }

        .asesor-card .comision-form .btn-actualizar:hover {
            background: #059669;
        }

        .asesor-card .estado-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .asesor-card .estado-badge.activo {
            background: #d1fae5;
            color: #065f46;
        }

        .asesor-card .estado-badge.inactivo {
            background: #fee2e2;
            color: #991b1b;
        }

        /* ===== NIVELES ===== */
        .niveles-container {
            background: white;
            border: 1px solid #e8edf4;
            border-radius: 12px;
            padding: 20px;
            overflow-x: auto;
        }

        .niveles-table {
            width: 100%;
            border-collapse: collapse;
        }

        .niveles-table th {
            background: #f8fafc;
            padding: 10px 14px;
            text-align: left;
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e8edf4;
        }

        .niveles-table td {
            padding: 10px 14px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.9rem;
            vertical-align: middle;
        }

        .niveles-table tr:hover td {
            background: #f8fafc;
        }

        .niveles-table input[type="text"],
        .niveles-table input[type="number"],
        .niveles-table input[type="color"] {
            padding: 4px 8px;
            border: 1px solid #e8edf4;
            border-radius: 6px;
            font-size: 0.85rem;
            width: 100%;
            min-width: 60px;
        }

        .niveles-table input[type="text"]:focus,
        .niveles-table input[type="number"]:focus,
        .niveles-table input[type="color"]:focus {
            outline: none;
            border-color: #10b981;
        }

        .niveles-table input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .btn-agregar-nivel {
            padding: 8px 20px;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.15s;
            margin-top: 15px;
        }

        .btn-agregar-nivel:hover {
            background: #2563eb;
        }

        .btn-guardar-niveles {
            padding: 8px 24px;
            background: #10b981;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.15s;
        }

        .btn-guardar-niveles:hover {
            background: #059669;
        }

        /* ===== HISTORIAL ===== */
        .historial-container {
            background: white;
            border: 1px solid #e8edf4;
            border-radius: 12px;
            padding: 20px;
            overflow-x: auto;
        }

        .historial-table {
            width: 100%;
            border-collapse: collapse;
        }

        .historial-table th {
            background: #f8fafc;
            padding: 10px 14px;
            text-align: left;
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e8edf4;
        }

        .historial-table td {
            padding: 10px 14px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.9rem;
        }

        .historial-table .cambio {
            font-weight: 700;
        }

        .historial-table .cambio.subio {
            color: #10b981;
        }

        .historial-table .cambio.bajo {
            color: #ef4444;
        }

        /* ===== ANALÍTICOS ===== */
        .analiticos-placeholder {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }

        .analiticos-placeholder i {
            font-size: 4rem;
            color: #d1d5db;
            margin-bottom: 15px;
            display: block;
        }

        .analiticos-placeholder h3 {
            color: #0f172a;
            margin-bottom: 10px;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .admin-container { padding: 15px; }
            .admin-header-bar { flex-direction: column; align-items: stretch; }
            .admin-user-info { justify-content: center; padding: 8px 12px; }
            .asesores-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .tabs { flex-direction: column; }
            .tab-btn { text-align: center; }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .asesor-card .stats-row { grid-template-columns: 1fr 1fr; }
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
            <h1>
                <i class="fas fa-crown"></i> Panel de Administración
            </h1>
            <div class="admin-user-info">
                <div class="admin-avatar">
                    <?php echo strtoupper(substr($usuario['name'] ?? 'A', 0, 1)); ?>
                </div>
                <span class="admin-name"><?php echo sanitizar($usuario['name'] ?? 'Administrador'); ?></span>
                <span class="admin-role">
                    <i class="fas fa-user-tag"></i> Admin
                </span>
                <a href="../logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i> Salir
                </a>
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
            <button class="tab-btn active" data-tab="tab-asesores">
                <i class="fas fa-users"></i> Asesores
            </button>
            <button class="tab-btn" data-tab="tab-niveles">
                <i class="fas fa-layer-group"></i> Niveles de Comisión
            </button>
            <button class="tab-btn" data-tab="tab-historial">
                <i class="fas fa-history"></i> Historial
            </button>
            <button class="tab-btn" data-tab="tab-analiticos">
                <i class="fas fa-chart-bar"></i> Analíticos
            </button>
        </div>

        <!-- ===== TAB 1: ASESORES ===== -->
        <div class="tab-content active" id="tab-asesores">
            <div class="asesores-grid">
                <?php foreach ($asesores as $asesor): ?>
                <div class="asesor-card">
                    <div class="asesor-header">
                        <div class="asesor-avatar" style="background: <?php echo $asesor['color']; ?>;">
                            <?php echo strtoupper(substr($asesor['name'], 0, 1)); ?>
                        </div>
                        <div class="asesor-info">
                            <h3><?php echo sanitizar($asesor['name']); ?></h3>
                            <div class="asesor-email">
                                <i class="fas fa-envelope"></i> <?php echo sanitizar($asesor['email']); ?>
                            </div>
                            <span class="nivel-badge" style="background: <?php echo $asesor['color']; ?>20; color: <?php echo $asesor['color']; ?>;">
                                <?php echo $asesor['nivel_label']; ?>
                            </span>
                            <span class="estado-badge <?php echo $asesor['activo'] ? 'activo' : 'inactivo'; ?>">
                                <?php echo $asesor['activo'] ? 'Activo' : 'Inactivo'; ?>
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

                    <form method="POST" action="" class="comision-form">
                        <input type="hidden" name="accion" value="actualizar_comision_asesor">
                        <input type="hidden" name="asesor_id" value="<?php echo $asesor['id']; ?>">
                        <input type="hidden" name="registrar_historial" value="1">
                        
                        <label for="comision_<?php echo $asesor['id']; ?>">Comisión:</label>
                        <input type="number" 
                               id="comision_<?php echo $asesor['id']; ?>" 
                               name="porcentaje" 
                               value="<?php echo $asesor['comision_actual']; ?>" 
                               min="0" 
                               max="100" 
                               step="0.5">
                        <span>%</span>
                        <span class="sugerida">(sugerido: <?php echo $asesor['comision_sugerida']; ?>%)</span>
                        <button type="submit" class="btn-actualizar">
                            <i class="fas fa-save"></i>
                        </button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ===== TAB 2: NIVELES DE COMISIÓN ===== -->
        <div class="tab-content" id="tab-niveles">
            <div class="niveles-container">
                <form method="POST" action="">
                    <input type="hidden" name="accion" value="actualizar_niveles">
                    
                    <table class="niveles-table">
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
                                <td>
                                    <input type="text" name="niveles[<?php echo $nivel['id']; ?>][nombre]" 
                                           value="<?php echo sanitizar($nivel['nivel_nombre']); ?>" required>
                                </td>
                                <td>
                                    <input type="number" name="niveles[<?php echo $nivel['id']; ?>][min]" 
                                           value="<?php echo $nivel['ventas_min']; ?>" required>
                                </td>
                                <td>
                                    <input type="number" name="niveles[<?php echo $nivel['id']; ?>][max]" 
                                           value="<?php echo $nivel['ventas_max']; ?>" required>
                                </td>
                                <td>
                                    <input type="number" name="niveles[<?php echo $nivel['id']; ?>][porcentaje]" 
                                           value="<?php echo $nivel['comision_porcentaje']; ?>" 
                                           min="0" max="100" step="0.5" required>
                                </td>
                                <td>
                                    <input type="color" name="niveles[<?php echo $nivel['id']; ?>][color]" 
                                           value="<?php echo $nivel['color_hex']; ?>">
                                </td>
                                <td>
                                    <input type="text" name="niveles[<?php echo $nivel['id']; ?>][icono]" 
                                           value="<?php echo $nivel['icono']; ?>" style="width: 50px; text-align: center;">
                                </td>
                                <td style="text-align: center;">
                                    <input type="checkbox" name="niveles[<?php echo $nivel['id']; ?>][activo]" 
                                           <?php echo $nivel['activo'] ? 'checked' : ''; ?>>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div style="display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap;">
                        <button type="submit" class="btn-guardar-niveles">
                            <i class="fas fa-save"></i> Guardar Niveles
                        </button>
                        <button type="button" class="btn-agregar-nivel" onclick="agregarNivel()">
                            <i class="fas fa-plus"></i> Agregar Nivel
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ===== TAB 3: HISTORIAL ===== -->
        <div class="tab-content" id="tab-historial">
            <div class="historial-container">
                <?php if (empty($historial)): ?>
                    <p style="text-align: center; color: #64748b; padding: 20px;">
                        <i class="fas fa-info-circle"></i> No hay cambios registrados aún.
                    </p>
                <?php else: ?>
                    <table class="historial-table">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Asesor</th>
                                <th>Comisión Anterior</th>
                                <th>Comisión Nueva</th>
                                <th>Motivo</th>
                                <th>Modificado Por</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historial as $h): ?>
                            <tr>
                                <td><?php echo date('d/m/Y H:i', strtotime($h['created_at'])); ?></td>
                                <td><?php echo sanitizar($h['asesor_nombre']); ?></td>
                                <td><?php echo $h['comision_anterior']; ?>%</td>
                                <td>
                                    <span class="cambio <?php echo $h['comision_nueva'] > $h['comision_anterior'] ? 'subio' : 'bajo'; ?>">
                                        <?php echo $h['comision_nueva']; ?>%
                                    </span>
                                </td>
                                <td><?php echo sanitizar($h['motivo'] ?? 'N/A'); ?></td>
                                <td><?php echo sanitizar($h['admin_nombre']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- ===== TAB 4: ANALÍTICOS ===== -->
        <div class="tab-content" id="tab-analiticos">
            <div class="analiticos-placeholder">
                <i class="fas fa-chart-pie"></i>
                <h3>Módulo de Analíticos en Desarrollo</h3>
                <p>Próximamente podrás ver gráficas y estadísticas avanzadas de rendimiento.</p>
                <div style="display: flex; gap: 15px; justify-content: center; margin-top: 15px; flex-wrap: wrap;">
                    <span style="background: #f1f5f9; padding: 4px 12px; border-radius: 6px; font-size: 0.8rem;">
                        <i class="fas fa-home" style="color: #3b82f6;"></i> Propiedades
                    </span>
                    <span style="background: #f1f5f9; padding: 4px 12px; border-radius: 6px; font-size: 0.8rem;">
                        <i class="fas fa-dollar-sign" style="color: #10b981;"></i> Comisiones
                    </span>
                    <span style="background: #f1f5f9; padding: 4px 12px; border-radius: 6px; font-size: 0.8rem;">
                        <i class="fas fa-users" style="color: #8b5cf6;"></i> Rendimiento
                    </span>
                </div>
            </div>
        </div>

        <!-- ACCESO RÁPIDO -->
        <?php include '../modulos/accesos_rapidos.php'; ?>
    </div>
</main>

<script>
// ===== TABS =====
document.addEventListener('DOMContentLoaded', function() {
    const tabs = document.querySelectorAll('.tab-btn');
    const contents = document.querySelectorAll('.tab-content');

    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            // Remover active de todos
            tabs.forEach(t => t.classList.remove('active'));
            contents.forEach(c => c.classList.remove('active'));
            
            // Activar el seleccionado
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
    // Crear un formulario para agregar nuevo nivel
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '';
    form.innerHTML = `
        <input type="hidden" name="accion" value="agregar_nivel">
        <div style="background: #f8fafc; padding: 15px; border-radius: 8px; margin-top: 15px; display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px;">
            <div>
                <label style="font-size: 0.75rem; font-weight: 600; color: #64748b;">Nombre</label>
                <input type="text" name="nombre" placeholder="Ej: Diamante" required style="width: 100%; padding: 6px 10px; border: 1px solid #e8edf4; border-radius: 6px;">
            </div>
            <div>
                <label style="font-size: 0.75rem; font-weight: 600; color: #64748b;">Orden</label>
                <input type="number" name="orden" placeholder="6" required style="width: 100%; padding: 6px 10px; border: 1px solid #e8edf4; border-radius: 6px;">
            </div>
            <div>
                <label style="font-size: 0.75rem; font-weight: 600; color: #64748b;">Ventas Min</label>
                <input type="number" name="min" placeholder="0" required style="width: 100%; padding: 6px 10px; border: 1px solid #e8edf4; border-radius: 6px;">
            </div>
            <div>
                <label style="font-size: 0.75rem; font-weight: 600; color: #64748b;">Ventas Max</label>
                <input type="number" name="max" placeholder="999" required style="width: 100%; padding: 6px 10px; border: 1px solid #e8edf4; border-radius: 6px;">
            </div>
            <div>
                <label style="font-size: 0.75rem; font-weight: 600; color: #64748b;">Comisión %</label>
                <input type="number" name="porcentaje" placeholder="10" min="0" max="100" step="0.5" required style="width: 100%; padding: 6px 10px; border: 1px solid #e8edf4; border-radius: 6px;">
            </div>
            <div>
                <label style="font-size: 0.75rem; font-weight: 600; color: #64748b;">Color</label>
                <input type="color" name="color" value="#10b981" style="width: 100%; padding: 2px; border: 1px solid #e8edf4; border-radius: 6px; height: 36px;">
            </div>
            <div>
                <label style="font-size: 0.75rem; font-weight: 600; color: #64748b;">Icono</label>
                <input type="text" name="icono" placeholder="🌟" style="width: 100%; padding: 6px 10px; border: 1px solid #e8edf4; border-radius: 6px;">
            </div>
            <div style="display: flex; align-items: end; gap: 8px;">
                <label style="font-size: 0.75rem; font-weight: 600; color: #64748b;">
                    <input type="checkbox" name="activo" checked> Activo
                </label>
                <button type="submit" style="padding: 6px 20px; background: #10b981; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                    <i class="fas fa-plus"></i> Crear
                </button>
                <button type="button" onclick="this.closest('form').remove()" style="padding: 6px 12px; background: #ef4444; color: white; border: none; border-radius: 6px; cursor: pointer;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
    `;
    
    // Insertar antes del botón de guardar
    const container = document.querySelector('.niveles-container');
    const buttons = container.querySelector('div:last-child');
    container.insertBefore(form, buttons);
}
</script>
</body>
</html>