<?php
// ============================================
// audit_logs.php - PORTAL DE AUDITORÍA COMPLETO
// ============================================

session_start();
require_once 'includes/conexion.php';
require_once 'includes/auth.php';

// Verificar autenticación
if (!estaLogueado()) {
    header('Location: login.php');
    exit;
}

// Obtener datos del usuario
$usuario = obtenerUsuarioActual($conn);
if (!$usuario) {
    cerrarSesion();
    header('Location: login.php');
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$es_admin = esAdmin();

// ============================================================
// 1. FUNCIONES DE AUDITORÍA
// ============================================================

/**
 * Obtener KPIs de auditoría
 */
function getAuditKPIs($conn, $usuario_id, $es_admin) {
    $where = $es_admin ? "" : "WHERE al.user_id = ?";
    $params = $es_admin ? [] : [$usuario_id];
    
    // Total de eventos
    $stmt = $conn->prepare("SELECT COUNT(*) FROM activity_logs al $where");
    $stmt->execute($params);
    $total_eventos = $stmt->fetchColumn();
    
    // Eventos hoy
    $where_hoy = $es_admin 
        ? "WHERE DATE(al.created_at) = CURDATE()" 
        : "WHERE al.user_id = ? AND DATE(al.created_at) = CURDATE()";
    $params_hoy = $es_admin ? [] : [$usuario_id];
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM activity_logs al $where_hoy");
    $stmt->execute($params_hoy);
    $eventos_hoy = $stmt->fetchColumn();
    
    // Eventos esta semana
    $where_semana = $es_admin 
        ? "WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" 
        : "WHERE al.user_id = ? AND al.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $params_semana = $es_admin ? [] : [$usuario_id];
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM activity_logs al $where_semana");
    $stmt->execute($params_semana);
    $eventos_semana = $stmt->fetchColumn();
    
    // Usuarios únicos activos (últimos 7 días)
    $where_unicos = $es_admin 
        ? "WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" 
        : "WHERE al.user_id = ? AND al.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $params_unicos = $es_admin ? [] : [$usuario_id];
    
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT al.user_id) FROM activity_logs al $where_unicos");
    $stmt->execute($params_unicos);
    $usuarios_activos = $stmt->fetchColumn();
    
    // Eventos críticos (errores, warnings)
    $where_criticos = $es_admin 
        ? "WHERE al.action_type IN ('error', 'warning', 'delete', 'failed_login')" 
        : "WHERE al.user_id = ? AND al.action_type IN ('error', 'warning', 'delete', 'failed_login')";
    $params_criticos = $es_admin ? [] : [$usuario_id];
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM activity_logs al $where_criticos");
    $stmt->execute($params_criticos);
    $eventos_criticos = $stmt->fetchColumn();
    
    // Eventos de login
    $where_login = $es_admin 
        ? "WHERE al.action_type IN ('login', 'logout', 'login_failed')" 
        : "WHERE al.user_id = ? AND al.action_type IN ('login', 'logout', 'login_failed')";
    $params_login = $es_admin ? [] : [$usuario_id];
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM activity_logs al $where_login");
    $stmt->execute($params_login);
    $eventos_login = $stmt->fetchColumn();
    
    // Eventos de módulos (más reciente)
    $modulos_query = $es_admin
        ? "SELECT al.module, COUNT(*) as total FROM activity_logs al GROUP BY al.module ORDER BY total DESC LIMIT 5"
        : "SELECT al.module, COUNT(*) as total FROM activity_logs al WHERE al.user_id = ? GROUP BY al.module ORDER BY total DESC LIMIT 5";
    $stmt = $conn->prepare($modulos_query);
    $stmt->execute($es_admin ? [] : [$usuario_id]);
    $modulos_top = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'total_eventos' => (int)$total_eventos,
        'eventos_hoy' => (int)$eventos_hoy,
        'eventos_semana' => (int)$eventos_semana,
        'usuarios_activos' => (int)$usuarios_activos,
        'eventos_criticos' => (int)$eventos_criticos,
        'eventos_login' => (int)$eventos_login,
        'modulos_top' => $modulos_top,
    ];
}

/**
 * Obtener logs con filtros y paginación
 */
function getAuditLogs($conn, $usuario_id, $es_admin, $filtros = [], $pagina = 1, $por_pagina = 25) {
    $where = [];
    $params = [];
    
    // Filtro por usuario (si no es admin)
    if (!$es_admin) {
        $where[] = "al.user_id = ?";
        $params[] = $usuario_id;
    }
    
    // Filtro por usuario específico (admin)
    if (!empty($filtros['usuario_id']) && $es_admin) {
        $where[] = "al.user_id = ?";
        $params[] = $filtros['usuario_id'];
    }
    
    // Filtro por tipo de acción
    if (!empty($filtros['action_type'])) {
        $where[] = "al.action_type = ?";
        $params[] = $filtros['action_type'];
    }
    
    // Filtro por módulo
    if (!empty($filtros['module'])) {
        $where[] = "al.module = ?";
        $params[] = $filtros['module'];
    }
    
    // Filtro por severidad
    if (!empty($filtros['severity'])) {
        $where[] = "al.severity = ?";
        $params[] = $filtros['severity'];
    }
    
    // Filtro por fecha desde
    if (!empty($filtros['fecha_desde'])) {
        $where[] = "al.created_at >= ?";
        $params[] = $filtros['fecha_desde'] . ' 00:00:00';
    }
    
    // Filtro por fecha hasta
    if (!empty($filtros['fecha_hasta'])) {
        $where[] = "al.created_at <= ?";
        $params[] = $filtros['fecha_hasta'] . ' 23:59:59';
    }
    
    // Filtro por búsqueda
    if (!empty($filtros['busqueda'])) {
        $where[] = "(al.description LIKE ? OR al.entity_type LIKE ? OR al.entity_id LIKE ?)";
        $busqueda = '%' . $filtros['busqueda'] . '%';
        $params[] = $busqueda;
        $params[] = $busqueda;
        $params[] = $busqueda;
    }
    
    $where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    // Contar total
    $stmt = $conn->prepare("SELECT COUNT(*) FROM activity_logs al $where_sql");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    
    // Calcular paginación
    $offset = ($pagina - 1) * $por_pagina;
    $total_paginas = ceil($total / $por_pagina);
    
    // Obtener registros
    $sql = "
        SELECT 
            al.*,
            u.name as usuario_nombre,
            u.email as usuario_email,
            u.role as usuario_rol
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id
        $where_sql
        ORDER BY al.created_at DESC
        LIMIT $por_pagina OFFSET $offset
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'logs' => $logs,
        'total' => (int)$total,
        'pagina' => (int)$pagina,
        'total_paginas' => (int)$total_paginas,
        'por_pagina' => (int)$por_pagina
    ];
}

/**
 * Obtener tipos de acción únicos para filtros
 */
function getTiposAccion($conn) {
    $stmt = $conn->prepare("SELECT DISTINCT action_type FROM activity_logs WHERE action_type IS NOT NULL AND action_type != '' ORDER BY action_type");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Obtener módulos únicos para filtros
 */
function getModulos($conn) {
    $stmt = $conn->prepare("SELECT DISTINCT module FROM activity_logs WHERE module IS NOT NULL AND module != '' ORDER BY module");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Obtener lista de usuarios (solo admin)
 */
function getUsuariosLista($conn) {
    $stmt = $conn->prepare("SELECT id, name, email, role FROM users ORDER BY name");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Obtener actividad por día (últimos 14 días) para gráfico
 */
function getActividadPorDia($conn, $usuario_id, $es_admin, $dias = 14) {
    $where = $es_admin ? "" : "AND al.user_id = ?";
    $params = $es_admin ? [$dias] : [$usuario_id, $dias];
    
    $stmt = $conn->prepare("
        SELECT 
            DATE(al.created_at) as dia,
            COUNT(*) as total,
            SUM(CASE WHEN al.severity IN ('error', 'critical') THEN 1 ELSE 0 END) as criticos
        FROM activity_logs al
        WHERE al.created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        $where
        GROUP BY DATE(al.created_at)
        ORDER BY dia ASC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Obtener distribución por tipo de acción para gráfico
 */
function getDistribucionAcciones($conn, $usuario_id, $es_admin) {
    $where = $es_admin ? "" : "WHERE al.user_id = ?";
    $params = $es_admin ? [] : [$usuario_id];
    
    $stmt = $conn->prepare("
        SELECT 
            al.action_type,
            COUNT(*) as total
        FROM activity_logs al
        $where
        GROUP BY al.action_type
        ORDER BY total DESC
        LIMIT 8
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Obtener detalle de un log (para modal)
 */
function getLogDetalle($conn, $log_id, $usuario_id, $es_admin) {
    $where = $es_admin ? "WHERE al.id = ?" : "WHERE al.id = ? AND al.user_id = ?";
    $params = $es_admin ? [$log_id] : [$log_id, $usuario_id];
    
    $stmt = $conn->prepare("
        SELECT al.*, u.name as usuario_nombre, u.email as usuario_email
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id
        $where
    ");
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// ============================================================
// 2. PROCESAR FILTROS Y PAGINACIÓN
// ============================================================

$filtros = [
    'usuario_id' => $_GET['usuario_id'] ?? '',
    'action_type' => $_GET['action_type'] ?? '',
    'module' => $_GET['module'] ?? '',
    'severity' => $_GET['severity'] ?? '',
    'fecha_desde' => $_GET['fecha_desde'] ?? '',
    'fecha_hasta' => $_GET['fecha_hasta'] ?? '',
    'busqueda' => $_GET['busqueda'] ?? '',
];

$pagina = max(1, (int)($_GET['pagina'] ?? 1));
$por_pagina = (int)($_GET['por_pagina'] ?? 25);

// ============================================================
// 3. OBTENER DATOS
// ============================================================

$kpis = getAuditKPIs($conn, $usuario_id, $es_admin);
$resultado = getAuditLogs($conn, $usuario_id, $es_admin, $filtros, $pagina, $por_pagina);
$tipos_accion = getTiposAccion($conn);
$modulos = getModulos($conn);
$usuarios_lista = $es_admin ? getUsuariosLista($conn) : [];
$actividad_diaria = getActividadPorDia($conn, $usuario_id, $es_admin, 14);
$distribucion_acciones = getDistribucionAcciones($conn, $usuario_id, $es_admin);

// Preparar datos para gráficos
$dias_labels = array_map(function($d) {
    return date('d/m', strtotime($d['dia']));
}, $actividad_diaria);
$dias_values = array_column($actividad_diaria, 'total');
$dias_criticos = array_column($actividad_diaria, 'criticos');

$acciones_labels = array_column($distribucion_acciones, 'action_type');
$acciones_values = array_column($distribucion_acciones, 'total');

// ============================================================
// 4. FUNCIONES AUXILIARES
// ============================================================

function getAccionIcono($accion) {
    $iconos = [
        'create' => 'fa-plus-circle',
        'update' => 'fa-edit',
        'delete' => 'fa-trash-alt',
        'login' => 'fa-sign-in-alt',
        'logout' => 'fa-sign-out-alt',
        'login_failed' => 'fa-exclamation-triangle',
        'view' => 'fa-eye',
        'download' => 'fa-download',
        'upload' => 'fa-upload',
        'export' => 'fa-file-export',
        'import' => 'fa-file-import',
        'send' => 'fa-paper-plane',
        'approve' => 'fa-check-circle',
        'reject' => 'fa-times-circle',
        'error' => 'fa-bug',
        'warning' => 'fa-exclamation-circle',
        'status_change' => 'fa-exchange-alt',
        'assign' => 'fa-user-tag',
        'payment' => 'fa-credit-card',
        'sign' => 'fa-file-signature',
        'comment' => 'fa-comment',
        'share' => 'fa-share-alt',
    ];
    return $iconos[$accion] ?? 'fa-info-circle';
}

function getAccionColor($accion) {
    $colores = [
        'create' => '#10b981',
        'update' => '#f59e0b',
        'delete' => '#ef4444',
        'login' => '#3b82f6',
        'logout' => '#6b7280',
        'login_failed' => '#dc2626',
        'view' => '#8b5cf6',
        'download' => '#06b6d4',
        'upload' => '#06b6d4',
        'export' => '#8b5cf6',
        'import' => '#8b5cf6',
        'send' => '#3b82f6',
        'approve' => '#10b981',
        'reject' => '#ef4444',
        'error' => '#dc2626',
        'warning' => '#f59e0b',
        'status_change' => '#f59e0b',
        'payment' => '#10b981',
        'sign' => '#4f46e5',
    ];
    return $colores[$accion] ?? '#6b7280';
}

function getSeveridadBadge($severidad) {
    $badges = [
        'info' => ['bg' => '#dbeafe', 'color' => '#2563eb', 'texto' => 'Info'],
        'success' => ['bg' => '#dcfce7', 'color' => '#16a34a', 'texto' => 'Éxito'],
        'warning' => ['bg' => '#fef3c7', 'color' => '#d97706', 'texto' => 'Advertencia'],
        'error' => ['bg' => '#fee2e2', 'color' => '#dc2626', 'texto' => 'Error'],
        'critical' => ['bg' => '#fecaca', 'color' => '#991b1b', 'texto' => 'Crítico'],
    ];
    return $badges[$severidad] ?? $badges['info'];
}

function tiempoRelativoAudit($fecha) {
    $timestamp = strtotime($fecha);
    $diff = time() - $timestamp;
    
    if ($diff < 60) return 'hace ' . $diff . ' seg';
    if ($diff < 3600) return 'hace ' . round($diff / 60) . ' min';
    if ($diff < 86400) return 'hace ' . round($diff / 3600) . ' h';
    if ($diff < 604800) return 'hace ' . round($diff / 86400) . ' d';
    return date('d/m/Y H:i', $timestamp);
}

// Construir URL de paginación preservando filtros
function buildUrl($params = []) {
    $base = $_GET;
    foreach ($params as $k => $v) {
        if ($v === null) unset($base[$k]);
        else $base[$k] = $v;
    }
    return '?' . http_build_query($base);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/socios.css">
    <title>Audit Logs | Inmobiliaria MH</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        /* ===== AUDIT LOGS STYLES ===== */
        .audit-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .audit-header h1 {
            font-size: 22px;
            margin: 0;
            color: #0f172a;
        }
        
        .audit-header .subtitle {
            color: #64748b;
            font-size: 13px;
            margin-top: 4px;
        }
        
        .audit-header .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .btn-audit {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 16px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: white;
            color: #475569;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .btn-audit:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }
        
        .btn-audit.primary {
            background: #4f46e5;
            border-color: #4f46e5;
            color: white;
        }
        
        .btn-audit.primary:hover {
            background: #4338ca;
        }
        
        .btn-audit.danger {
            background: #ef4444;
            border-color: #ef4444;
            color: white;
        }
        
        .btn-audit.danger:hover {
            background: #dc2626;
        }
        
        /* KPIs */
        .audit-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 25px;
        }
        
        .audit-kpi-card {
            background: white;
            border-radius: 12px;
            padding: 18px 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border-left: 4px solid #4f46e5;
            transition: all 0.3s ease;
        }
        
        .audit-kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.08);
        }
        
        .audit-kpi-card .kpi-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        
        .audit-kpi-card .kpi-icon {
            font-size: 22px;
            opacity: 0.7;
        }
        
        .audit-kpi-card .kpi-value {
            font-size: 26px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.2;
        }
        
        .audit-kpi-card .kpi-label {
            font-size: 12px;
            color: #64748b;
            margin-top: 2px;
        }
        
        .audit-kpi-card.blue { border-left-color: #4f46e5; }
        .audit-kpi-card.blue .kpi-icon { color: #4f46e5; }
        .audit-kpi-card.green { border-left-color: #10b981; }
        .audit-kpi-card.green .kpi-icon { color: #10b981; }
        .audit-kpi-card.orange { border-left-color: #f59e0b; }
        .audit-kpi-card.orange .kpi-icon { color: #f59e0b; }
        .audit-kpi-card.red { border-left-color: #ef4444; }
        .audit-kpi-card.red .kpi-icon { color: #ef4444; }
        .audit-kpi-card.purple { border-left-color: #8b5cf6; }
        .audit-kpi-card.purple .kpi-icon { color: #8b5cf6; }
        .audit-kpi-card.teal { border-left-color: #06b6d4; }
        .audit-kpi-card.teal .kpi-icon { color: #06b6d4; }
        
        /* Filtros */
        .filtros-card {
            background: white;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        
        .filtros-card h3 {
            font-size: 14px;
            font-weight: 600;
            color: #0f172a;
            margin: 0 0 14px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .filtros-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 12px;
        }
        
        .filtro-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .filtro-group label {
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .filtro-group input,
        .filtro-group select {
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 13px;
            color: #0f172a;
            background: white;
            transition: border-color 0.2s;
            font-family: inherit;
        }
        
        .filtro-group input:focus,
        .filtro-group select:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .filtros-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }
        
        /* Gráficos */
        .audit-charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .audit-chart-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        
        .audit-chart-card h4 {
            font-size: 14px;
            font-weight: 600;
            color: #0f172a;
            margin: 0 0 15px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .audit-chart-card h4 i {
            color: #64748b;
        }
        
        .audit-chart-card canvas {
            max-height: 220px;
        }
        
        /* Tabla de logs */
        .logs-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            overflow: hidden;
        }
        
        .logs-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 20px;
            border-bottom: 1px solid #f1f5f9;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .logs-header h3 {
            font-size: 15px;
            font-weight: 600;
            color: #0f172a;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .logs-header .badge-count {
            background: #f1f5f9;
            color: #475569;
            padding: 3px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .logs-table-wrapper {
            overflow-x: auto;
        }
        
        .logs-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        
        .logs-table thead {
            background: #f8fafc;
        }
        
        .logs-table th {
            text-align: left;
            padding: 12px 16px;
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .logs-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            color: #1e293b;
            vertical-align: middle;
        }
        
        .logs-table tbody tr {
            transition: background 0.15s;
            cursor: pointer;
        }
        
        .logs-table tbody tr:hover {
            background: #f8fafc;
        }
        
        .logs-table .col-icon {
            width: 40px;
        }
        
        .log-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            color: white;
            flex-shrink: 0;
        }
        
        .log-desc {
            font-weight: 500;
            color: #0f172a;
            max-width: 400px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .log-desc-sub {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 400px;
        }
        
        .log-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .log-user {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .log-user-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #4f46e5;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
            flex-shrink: 0;
        }
        
        .log-user-info {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }
        
        .log-user-name {
            font-weight: 600;
            color: #0f172a;
            font-size: 12px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .log-user-email {
            font-size: 11px;
            color: #94a3b8;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .log-fecha {
            font-size: 12px;
            color: #475569;
            white-space: nowrap;
        }
        
        .log-fecha-rel {
            font-size: 11px;
            color: #94a3b8;
        }
        
        .log-ip {
            font-family: 'Courier New', monospace;
            font-size: 11px;
            color: #64748b;
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 4px;
            white-space: nowrap;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }
        
        .empty-state i {
            font-size: 48px;
            display: block;
            margin-bottom: 12px;
            opacity: 0.4;
        }
        
        .empty-state p {
            margin: 0;
            font-size: 14px;
        }
        
        /* Paginación */
        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            border-top: 1px solid #f1f5f9;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .pagination-info {
            font-size: 12px;
            color: #64748b;
        }
        
        .pagination-controls {
            display: flex;
            gap: 6px;
            align-items: center;
        }
        
        .pagination-controls a,
        .pagination-controls span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            height: 34px;
            padding: 0 10px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: white;
            color: #475569;
            font-size: 13px;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .pagination-controls a:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }
        
        .pagination-controls .active {
            background: #4f46e5;
            border-color: #4f46e5;
            color: white;
            font-weight: 600;
        }
        
        .pagination-controls .disabled {
            opacity: 0.4;
            pointer-events: none;
        }
        
        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(2px);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .modal-overlay.show {
            display: flex;
        }
        
        .modal-box {
            background: white;
            border-radius: 14px;
            width: 100%;
            max-width: 640px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: modalIn 0.2s ease;
        }
        
        @keyframes modalIn {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 24px;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .modal-header h3 {
            font-size: 16px;
            font-weight: 600;
            color: #0f172a;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 20px;
            color: #94a3b8;
            cursor: pointer;
            padding: 4px;
            line-height: 1;
        }
        
        .modal-close:hover {
            color: #475569;
        }
        
        .modal-body {
            padding: 20px 24px;
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 12px 16px;
            font-size: 13px;
        }
        
        .detail-grid dt {
            color: #64748b;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .detail-grid dd {
            color: #0f172a;
            margin: 0;
            word-break: break-word;
        }
        
        .detail-json {
            background: #0f172a;
            color: #e2e8f0;
            padding: 14px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            overflow-x: auto;
            margin-top: 8px;
            white-space: pre-wrap;
            word-break: break-word;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 16px 24px;
            border-top: 1px solid #f1f5f9;
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .audit-charts-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .audit-kpi-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .filtros-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .logs-table th,
            .logs-table td {
                padding: 10px 12px;
            }
            
            .log-desc,
            .log-desc-sub {
                max-width: 200px;
            }
            
            .detail-grid {
                grid-template-columns: 1fr;
            }
            
            .detail-grid dt {
                margin-top: 8px;
            }
        }
        
        @media (max-width: 480px) {
            .audit-kpi-grid {
                grid-template-columns: 1fr;
            }
            
            .filtros-grid {
                grid-template-columns: 1fr;
            }
            
            .audit-header {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>

<!-- Overlay para móvil -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php include 'modulos/sidebar.php'; ?>

<!-- ===== MAIN CONTENT ===== -->
<main class="main-content">
    <div class="audit-header">
        <div>
            <h1><i class="fas fa-clipboard-list" style="color: #4f46e5;"></i> Audit Logs</h1>
            <p class="subtitle">
                Registro de actividad del sistema 
                <?php if (!$es_admin): ?>
                    — mostrando solo tus acciones
                <?php else: ?>
                    — vista global de administrador
                <?php endif; ?>
            </p>
        </div>
        <div class="header-actions">
            <a href="<?php echo buildUrl(['pagina' => 1]); ?>" class="btn-audit">
                <i class="fas fa-sync-alt"></i> Actualizar
            </a>
            <a href="export_audit_logs.php?<?php echo http_build_query($filtros); ?>" class="btn-audit primary">
                <i class="fas fa-file-export"></i> Exportar CSV
            </a>
        </div>
    </div>

    <!-- ===== KPIS ===== -->
    <div class="audit-kpi-grid">
        <div class="audit-kpi-card blue">
            <div class="kpi-top">
                <div>
                    <div class="kpi-value"><?php echo number_format($kpis['total_eventos']); ?></div>
                    <div class="kpi-label">Total Eventos</div>
                </div>
                <div class="kpi-icon"><i class="fas fa-database"></i></div>
            </div>
        </div>
        
        <div class="audit-kpi-card green">
            <div class="kpi-top">
                <div>
                    <div class="kpi-value"><?php echo number_format($kpis['eventos_hoy']); ?></div>
                    <div class="kpi-label">Eventos Hoy</div>
                </div>
                <div class="kpi-icon"><i class="fas fa-calendar-day"></i></div>
            </div>
        </div>
        
        <div class="audit-kpi-card purple">
            <div class="kpi-top">
                <div>
                    <div class="kpi-value"><?php echo number_format($kpis['eventos_semana']); ?></div>
                    <div class="kpi-label">Esta Semana</div>
                </div>
                <div class="kpi-icon"><i class="fas fa-calendar-week"></i></div>
            </div>
        </div>
        
        <div class="audit-kpi-card teal">
            <div class="kpi-top">
                <div>
                    <div class="kpi-value"><?php echo number_format($kpis['usuarios_activos']); ?></div>
                    <div class="kpi-label">Usuarios Activos</div>
                </div>
                <div class="kpi-icon"><i class="fas fa-users"></i></div>
            </div>
        </div>
        
        <div class="audit-kpi-card orange">
            <div class="kpi-top">
                <div>
                    <div class="kpi-value"><?php echo number_format($kpis['eventos_login']); ?></div>
                    <div class="kpi-label">Eventos Login</div>
                </div>
                <div class="kpi-icon"><i class="fas fa-sign-in-alt"></i></div>
            </div>
        </div>
        
        <div class="audit-kpi-card red" style="cursor:pointer;" onclick="location.href='?severity=error'">
            <div class="kpi-top">
                <div>
                    <div class="kpi-value"><?php echo number_format($kpis['eventos_criticos']); ?></div>
                    <div class="kpi-label">Eventos Críticos</div>
                </div>
                <div class="kpi-icon"><i class="fas fa-exclamation-triangle"></i></div>
            </div>
        </div>
    </div>

    <!-- ===== GRÁFICOS ===== -->
    <div class="audit-charts-grid">
        <div class="audit-chart-card">
            <h4><i class="fas fa-chart-line"></i> Actividad de los Últimos 14 Días</h4>
            <canvas id="actividadChart"></canvas>
        </div>
        
        <div class="audit-chart-card">
            <h4><i class="fas fa-chart-pie"></i> Top Acciones</h4>
            <canvas id="accionesChart"></canvas>
        </div>
    </div>

    <!-- ===== FILTROS ===== -->
    <div class="filtros-card">
        <h3><i class="fas fa-filter" style="color: #64748b;"></i> Filtros</h3>
        <form method="GET" action="">
            <div class="filtros-grid">
                <?php if ($es_admin): ?>
                <div class="filtro-group">
                    <label>Usuario</label>
                    <select name="usuario_id">
                        <option value="">Todos los usuarios</option>
                        <?php foreach ($usuarios_lista as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo ($filtros['usuario_id'] == $u['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['name']); ?> (<?php echo $u['role']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="filtro-group">
                    <label>Tipo de Acción</label>
                    <select name="action_type">
                        <option value="">Todas</option>
                        <?php foreach ($tipos_accion as $tipo): ?>
                            <option value="<?php echo htmlspecialchars($tipo); ?>" <?php echo ($filtros['action_type'] === $tipo) ? 'selected' : ''; ?>>
                                <?php echo ucfirst(str_replace('_', ' ', $tipo)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filtro-group">
                    <label>Módulo</label>
                    <select name="module">
                        <option value="">Todos</option>
                        <?php foreach ($modulos as $mod): ?>
                            <option value="<?php echo htmlspecialchars($mod); ?>" <?php echo ($filtros['module'] === $mod) ? 'selected' : ''; ?>>
                                <?php echo ucfirst(str_replace('_', ' ', $mod)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filtro-group">
                    <label>Severidad</label>
                    <select name="severity">
                        <option value="">Todas</option>
                        <option value="info" <?php echo ($filtros['severity'] === 'info') ? 'selected' : ''; ?>>Info</option>
                        <option value="success" <?php echo ($filtros['severity'] === 'success') ? 'selected' : ''; ?>>Éxito</option>
                        <option value="warning" <?php echo ($filtros['severity'] === 'warning') ? 'selected' : ''; ?>>Advertencia</option>
                        <option value="error" <?php echo ($filtros['severity'] === 'error') ? 'selected' : ''; ?>>Error</option>
                        <option value="critical" <?php echo ($filtros['severity'] === 'critical') ? 'selected' : ''; ?>>Crítico</option>
                    </select>
                </div>
                
                <div class="filtro-group">
                    <label>Desde</label>
                    <input type="date" name="fecha_desde" value="<?php echo htmlspecialchars($filtros['fecha_desde']); ?>">
                </div>
                
                <div class="filtro-group">
                    <label>Hasta</label>
                    <input type="date" name="fecha_hasta" value="<?php echo htmlspecialchars($filtros['fecha_hasta']); ?>">
                </div>
                
                <div class="filtro-group" style="grid-column: span 2;">
                    <label>Búsqueda</label>
                    <input type="text" name="busqueda" placeholder="Buscar en descripción, entidad, ID..." value="<?php echo htmlspecialchars($filtros['busqueda']); ?>">
                </div>
            </div>
            
            <div class="filtros-actions">
                <a href="audit_logs.php" class="btn-audit">
                    <i class="fas fa-times"></i> Limpiar
                </a>
                <button type="submit" class="btn-audit primary">
                    <i class="fas fa-search"></i> Aplicar Filtros
                </button>
            </div>
        </form>
    </div>

    <!-- ===== TABLA DE LOGS ===== -->
    <div class="logs-card">
        <div class="logs-header">
            <h3>
                <i class="fas fa-list" style="color: #64748b;"></i> 
                Registros de Actividad
                <span class="badge-count"><?php echo number_format($resultado['total']); ?> resultados</span>
            </h3>
            <div style="display: flex; align-items: center; gap: 8px;">
                <label style="font-size: 12px; color: #64748b;">Mostrar:</label>
                <select onchange="location.href=this.value" style="padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 12px;">
                    <?php foreach ([10, 25, 50, 100] as $n): ?>
                        <option value="<?php echo buildUrl(['por_pagina' => $n, 'pagina' => 1]); ?>" <?php echo ($por_pagina == $n) ? 'selected' : ''; ?>>
                            <?php echo $n; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <?php if (empty($resultado['logs'])): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <p>No se encontraron registros con los filtros aplicados</p>
            </div>
        <?php else: ?>
            <div class="logs-table-wrapper">
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th class="col-icon"></th>
                            <th>Acción / Descripción</th>
                            <?php if ($es_admin): ?>
                                <th>Usuario</th>
                            <?php endif; ?>
                            <th>Módulo</th>
                            <th>Severidad</th>
                            <th>IP</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resultado['logs'] as $log): 
                            $sev = getSeveridadBadge($log['severity'] ?? 'info');
                            $color = getAccionColor($log['action_type'] ?? 'info');
                            $icono = getAccionIcono($log['action_type'] ?? 'info');
                            $inicial = strtoupper(substr($log['usuario_nombre'] ?? 'S', 0, 1));
                        ?>
                            <tr onclick="verDetalle(<?php echo $log['id']; ?>)">
                                <td class="col-icon">
                                    <div class="log-icon" style="background: <?php echo $color; ?>;">
                                        <i class="fas <?php echo $icono; ?>"></i>
                                    </div>
                                </td>
                                <td>
                                    <div class="log-desc">
                                        <?php echo htmlspecialchars($log['description'] ?? $log['action_type']); ?>
                                    </div>
                                    <?php if (!empty($log['entity_type']) || !empty($log['entity_id'])): ?>
                                        <div class="log-desc-sub">
                                            <i class="fas fa-link"></i>
                                            <?php echo htmlspecialchars($log['entity_type'] ?? ''); ?>
                                            <?php if (!empty($log['entity_id'])): ?>
                                                #<?php echo htmlspecialchars($log['entity_id']); ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <?php if ($es_admin): ?>
                                    <td>
                                        <div class="log-user">
                                            <div class="log-user-avatar"><?php echo $inicial; ?></div>
                                            <div class="log-user-info">
                                                <span class="log-user-name"><?php echo htmlspecialchars($log['usuario_nombre'] ?? 'Sistema'); ?></span>
                                                <span class="log-user-email"><?php echo htmlspecialchars($log['usuario_email'] ?? ''); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                <?php endif; ?>
                                <td>
                                    <span style="font-size: 12px; color: #475569;">
                                        <?php echo ucfirst(str_replace('_', ' ', $log['module'] ?? 'general')); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="log-badge" style="background: <?php echo $sev['bg']; ?>; color: <?php echo $sev['color']; ?>;">
                                        <?php echo $sev['texto']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="log-ip"><?php echo htmlspecialchars($log['ip_address'] ?? '—'); ?></span>
                                </td>
                                <td>
                                    <div class="log-fecha">
                                        <?php echo date('d/m/Y H:i', strtotime($log['created_at'])); ?>
                                    </div>
                                    <div class="log-fecha-rel"><?php echo tiempoRelativoAudit($log['created_at']); ?></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Paginación -->
            <div class="pagination">
                <div class="pagination-info">
                    Mostrando 
                    <strong><?php echo (($pagina - 1) * $por_pagina) + 1; ?></strong> 
                    - 
                    <strong><?php echo min($pagina * $por_pagina, $resultado['total']); ?></strong> 
                    de 
                    <strong><?php echo number_format($resultado['total']); ?></strong> registros
                </div>
                
                <div class="pagination-controls">
                    <?php if ($pagina > 1): ?>
                        <a href="<?php echo buildUrl(['pagina' => 1]); ?>" title="Primera">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a href="<?php echo buildUrl(['pagina' => $pagina - 1]); ?>" title="Anterior">
                            <i class="fas fa-angle-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled"><i class="fas fa-angle-double-left"></i></span>
                        <span class="disabled"><i class="fas fa-angle-left"></i></span>
                    <?php endif; ?>
                    
                    <?php 
                    $inicio = max(1, $pagina - 2);
                    $fin = min($resultado['total_paginas'], $pagina + 2);
                    
                    if ($inicio > 1): ?>
                        <a href="<?php echo buildUrl(['pagina' => 1]); ?>">1</a>
                        <?php if ($inicio > 2): ?>
                            <span style="border: none; background: none;">...</span>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php for ($i = $inicio; $i <= $fin; $i++): ?>
                        <?php if ($i == $pagina): ?>
                            <span class="active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="<?php echo buildUrl(['pagina' => $i]); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($fin < $resultado['total_paginas']): ?>
                        <?php if ($fin < $resultado['total_paginas'] - 1): ?>
                            <span style="border: none; background: none;">...</span>
                        <?php endif; ?>
                        <a href="<?php echo buildUrl(['pagina' => $resultado['total_paginas']]); ?>">
                            <?php echo $resultado['total_paginas']; ?>
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($pagina < $resultado['total_paginas']): ?>
                        <a href="<?php echo buildUrl(['pagina' => $pagina + 1]); ?>" title="Siguiente">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="<?php echo buildUrl(['pagina' => $resultado['total_paginas']]); ?>" title="Última">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled"><i class="fas fa-angle-right"></i></span>
                        <span class="disabled"><i class="fas fa-angle-double-right"></i></span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<!-- ===== MODAL DE DETALLE ===== -->
<div class="modal-overlay" id="modalDetalle">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-info-circle" style="color: #4f46e5;"></i> Detalle del Evento</h3>
            <button class="modal-close" onclick="cerrarModal()">&times;</button>
        </div>
        <div class="modal-body" id="modalContenido">
            <div style="text-align: center; padding: 30px; color: #94a3b8;">
                <i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i>
                <p>Cargando...</p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-audit" onclick="cerrarModal()">Cerrar</button>
        </div>
    </div>
</div>

<script>
// ===== MENÚ MÓVIL =====
document.addEventListener('DOMContentLoaded', function() {
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

    document.querySelectorAll('.sidebar nav a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 992) toggleSidebar();
        });
    });

    // ===== GRÁFICO DE ACTIVIDAD =====
    const ctxAct = document.getElementById('actividadChart');
    if (ctxAct) {
        const ctx = ctxAct.getContext('2d');
        const diasLabels = <?php echo json_encode($dias_labels); ?>;
        const diasValues = <?php echo json_encode($dias_values); ?>;
        const diasCriticos = <?php echo json_encode($dias_criticos); ?>;
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: diasLabels,
                datasets: [
                    {
                        label: 'Total Eventos',
                        data: diasValues,
                        borderColor: '#4f46e5',
                        backgroundColor: 'rgba(79, 70, 229, 0.1)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 2,
                        pointRadius: 3,
                        pointBackgroundColor: '#4f46e5'
                    },
                    {
                        label: 'Críticos',
                        data: diasCriticos,
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 2,
                        pointRadius: 3,
                        pointBackgroundColor: '#ef4444'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { font: { size: 11 }, boxWidth: 12, padding: 10 }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, font: { size: 10 } }
                    },
                    x: {
                        ticks: { font: { size: 10 } }
                    }
                }
            }
        });
    }

    // ===== GRÁFICO DE ACCIONES =====
    const ctxAcc = document.getElementById('accionesChart');
    if (ctxAcc) {
        const ctx = ctxAcc.getContext('2d');
        const accLabels = <?php echo json_encode(array_map(function($a) { return ucfirst(str_replace('_', ' ', $a)); }, $acciones_labels)); ?>;
        const accValues = <?php echo json_encode($acciones_values); ?>;
        
        if (accValues.length > 0) {
            const colores = ['#4f46e5', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#64748b'];
            
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: accLabels,
                    datasets: [{
                        data: accValues,
                        backgroundColor: colores.slice(0, accLabels.length),
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { font: { size: 10 }, boxWidth: 10, padding: 6 }
                        }
                    },
                    cutout: '60%'
                }
            });
        } else {
            ctxAcc.parentElement.innerHTML = `
                <div style="text-align: center; padding: 30px; color: #94a3b8;">
                    <i class="fas fa-chart-pie" style="font-size: 30px; display: block; margin-bottom: 10px;"></i>
                    <p>No hay datos disponibles</p>
                </div>
            `;
        }
    }
});

// ===== MODAL DE DETALLE =====
function verDetalle(logId) {
    const modal = document.getElementById('modalDetalle');
    const contenido = document.getElementById('modalContenido');
    
    modal.classList.add('show');
    contenido.innerHTML = `
        <div style="text-align: center; padding: 30px; color: #94a3b8;">
            <i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i>
            <p>Cargando detalle...</p>
        </div>
    `;
    
    fetch('get_audit_log_detail.php?id=' + logId)
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                contenido.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>${data.error}</p></div>`;
                return;
            }
            
            const log = data.log;
            let html = '<dl class="detail-grid">';
            
            html += `<dt>ID</dt><dd>#${log.id}</dd>`;
            html += `<dt>Acción</dt><dd><strong>${log.action_type || '—'}</strong></dd>`;
            html += `<dt>Descripción</dt><dd>${escapeHtml(log.description || '—')}</dd>`;
            html += `<dt>Severidad</dt><dd>${log.severity || 'info'}</dd>`;
            html += `<dt>Módulo</dt><dd>${log.module || 'general'}</dd>`;
            html += `<dt>Entidad</dt><dd>${log.entity_type || '—'} ${log.entity_id ? '#' + log.entity_id : ''}</dd>`;
            html += `<dt>Usuario</dt><dd>${escapeHtml(log.usuario_nombre || 'Sistema')} <small>(${escapeHtml(log.usuario_email || '—')})</small></dd>`;
            html += `<dt>IP</dt><dd><code>${log.ip_address || '—'}</code></dd>`;
            html += `<dt>User Agent</dt><dd style="font-size:11px;">${escapeHtml(log.user_agent || '—')}</dd>`;
            html += `<dt>Fecha</dt><dd>${log.created_at}</dd>`;
            
            html += '</dl>';
            
            // Datos adicionales (JSON)
            if (log.old_values || log.new_values || log.metadata) {
                html += '<div style="margin-top: 18px;">';
                
                if (log.old_values) {
                    html += '<dt style="color:#64748b;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.3px;margin-bottom:6px;">Valores Anteriores</dt>';
                    html += `<div class="detail-json">${escapeHtml(formatJson(log.old_values))}</div>`;
                }
                
                if (log.new_values) {
                    html += '<dt style="color:#64748b;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.3px;margin:12px 0 6px;">Valores Nuevos</dt>';
                    html += `<div class="detail-json">${escapeHtml(formatJson(log.new_values))}</div>`;
                }
                
                if (log.metadata) {
                    html += '<dt style="color:#64748b;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.3px;margin:12px 0 6px;">Metadata</dt>';
                    html += `<div class="detail-json">${escapeHtml(formatJson(log.metadata))}</div>`;
                }
                
                html += '</div>';
            }
            
            contenido.innerHTML = html;
        })
        .catch(err => {
            contenido.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error al cargar el detalle</p></div>`;
        });
}

function cerrarModal() {
    document.getElementById('modalDetalle').classList.remove('show');
}

// Cerrar modal con ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') cerrarModal();
});

// Cerrar modal al hacer click fuera
document.getElementById('modalDetalle').addEventListener('click', function(e) {
    if (e.target === this) cerrarModal();
});

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function formatJson(str) {
    try {
        const obj = typeof str === 'string' ? JSON.parse(str) : str;
        return JSON.stringify(obj, null, 2);
    } catch (e) {
        return str;
    }
}
</script>

</body>
</html>