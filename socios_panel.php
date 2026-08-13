<?php
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

// ===== FUNCIONES PARA MENSAJES Y NOTIFICACIONES =====

function obtenerMensajesNoLeidos($conn, $usuario_id) {
    try {
        $stmt = $conn->prepare("
            SELECT m.*, u.nombre as sender_name 
            FROM messages m
            LEFT JOIN usuarios u ON m.sender_id = u.id
            WHERE m.receiver_id = ? 
            AND m.is_read = 0 
            AND m.is_archived = 0
            ORDER BY m.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$usuario_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function obtenerVencimientosProximos($conn, $usuario_id) {
    try {
        $stmt = $conn->prepare("
            SELECT d.*, p.titulo as property_title 
            FROM deadlines d
            JOIN propiedades p ON d.property_id = p.id
            WHERE d.status IN ('pending', 'approaching')
            AND d.deadline_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            AND p.socio_id = ?
            ORDER BY d.deadline_date ASC
            LIMIT 5
        ");
        $stmt->execute([$usuario_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function obtenerTareasPendientes($conn, $usuario_id) {
    try {
        $stmt = $conn->prepare("
            SELECT * FROM tasks 
            WHERE assigned_to = ? 
            AND status = 'pending'
            ORDER BY due_date ASC
            LIMIT 5
        ");
        $stmt->execute([$usuario_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function obtenerActividadReciente($conn, $usuario_id) {
    try {
        $stmt = $conn->prepare("
            SELECT * FROM activity_logs 
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$usuario_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// ===== OBTENER DATOS PARA ESTADÍSTICAS AVANZADAS =====

function obtenerValorTotalCartera($conn, $usuario_id) {
    try {
        $stmt = $conn->prepare("
            SELECT SUM(precio) as total 
            FROM propiedades 
            WHERE socio_id = ? AND estado IN ('activa', 'destacada')
        ");
        $stmt->execute([$usuario_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['total'] ?? 0;
    } catch (PDOException $e) {
        return 0;
    }
}

function obtenerComisionesGeneradas($conn, $usuario_id) {
    try {
        $stmt = $conn->prepare("
            SELECT SUM(comision) as total 
            FROM ventas 
            WHERE socio_id = ? AND estado = 'pagada'
        ");
        $stmt->execute([$usuario_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['total'] ?? 0;
    } catch (PDOException $e) {
        return 0;
    }
}

function obtenerPropiedadesConOfertas($conn, $usuario_id) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(DISTINCT p.id) as total 
            FROM propiedades p
            JOIN ofertas o ON p.id = o.property_id
            WHERE p.socio_id = ? AND o.estado = 'pendiente'
        ");
        $stmt->execute([$usuario_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['total'] ?? 0;
    } catch (PDOException $e) {
        return 0;
    }
}

function obtenerTasaConversion($conn, $usuario_id) {
    try {
        // Propiedades totales vs propiedades vendidas
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN estado = 'vendida' THEN 1 ELSE 0 END) as vendidas
            FROM propiedades 
            WHERE socio_id = ?
        ");
        $stmt->execute([$usuario_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $total = $result['total'] ?? 0;
        $vendidas = $result['vendidas'] ?? 0;
        
        if ($total == 0) return 0;
        return round(($vendidas / $total) * 100, 1);
    } catch (PDOException $e) {
        return 0;
    }
}

function obtenerDistribucionPropiedades($conn, $usuario_id) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                estado,
                COUNT(*) as cantidad,
                ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM propiedades WHERE socio_id = ?), 1) as porcentaje
            FROM propiedades 
            WHERE socio_id = ?
            GROUP BY estado
        ");
        $stmt->execute([$usuario_id, $usuario_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// ===== RECOLECTAR TODOS LOS DATOS =====

$mensajes_no_leidos = obtenerMensajesNoLeidos($conn, $_SESSION['usuario_id']);
$total_mensajes_no_leidos = count($mensajes_no_leidos);
$mensajes_urgentes = array_filter($mensajes_no_leidos, function($msg) {
    return $msg['priority'] === 'urgent' || $msg['priority'] === 'high';
});

$vencimientos_proximos = obtenerVencimientosProximos($conn, $_SESSION['usuario_id']);
$tareas_pendientes = obtenerTareasPendientes($conn, $_SESSION['usuario_id']);
$actividad_reciente = obtenerActividadReciente($conn, $_SESSION['usuario_id']);

// Datos para métricas rápidas
$valor_total_cartera = obtenerValorTotalCartera($conn, $_SESSION['usuario_id']);
$comisiones_generadas = obtenerComisionesGeneradas($conn, $_SESSION['usuario_id']);
$propiedades_con_ofertas = obtenerPropiedadesConOfertas($conn, $_SESSION['usuario_id']);
$tasa_conversion = obtenerTasaConversion($conn, $_SESSION['usuario_id']);
$distribucion_propiedades = obtenerDistribucionPropiedades($conn, $_SESSION['usuario_id']);

// Obtener propiedades del socio
$propiedades = [];
try {
    $stmt = $conn->prepare("
        SELECT p.*, 
               (SELECT COUNT(*) FROM property_media WHERE property_id = p.id) as media_count,
               (SELECT COUNT(*) FROM property_documents WHERE property_id = p.id) as doc_count,
               (SELECT COUNT(*) FROM deadlines WHERE property_id = p.id AND status != 'completed') as deadlines_count
        FROM propiedades p 
        WHERE p.socio_id = ? 
        ORDER BY p.fecha_creacion DESC 
        LIMIT 10
    ");
    $stmt->execute([$_SESSION['usuario_id']]);
    $propiedades = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $propiedades = [];
}

// Estadísticas básicas
$stats = [
    'total' => count($propiedades),
    'activas' => 0,
    'vendidas' => 0,
    'destacadas' => 0,
    'pendientes' => 0,
    'vencimientos' => count($vencimientos_proximos),
    'mensajes' => $total_mensajes_no_leidos,
    'tareas' => count($tareas_pendientes),
    'ofertas' => $propiedades_con_ofertas,
    'conversion' => $tasa_conversion,
    'valor_cartera' => $valor_total_cartera,
    'comisiones' => $comisiones_generadas
];

foreach ($propiedades as $p) {
    if (isset($p['estado'])) {
        if ($p['estado'] === 'activa') $stats['activas']++;
        if ($p['estado'] === 'vendida') $stats['vendidas']++;
        if ($p['estado'] === 'destacada') $stats['destacadas']++;
        if ($p['estado'] === 'pendiente') $stats['pendientes']++;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/socios.css">
    <title>Panel de Socios | Inmobiliaria MH</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.min.css">
    <style>
        /* ===== TODOS LOS ESTILOS MEJORADOS ===== */
        
        /* Badge de notificaciones */
        .notification-badge { position: relative; }
        .notification-badge .badge-count {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #e74c3c;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 10px;
            font-weight: bold;
            min-width: 18px;
            text-align: center;
        }
        .notification-badge .badge-count.urgent { animation: pulse 1.5s infinite; }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        
        /* Mensajes Popup */
        .messages-popup {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 380px;
            max-height: 500px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            z-index: 1000;
            display: none;
            overflow: hidden;
            border: 1px solid #e0e0e0;
        }
        .messages-popup.active { display: block; animation: slideUp 0.3s ease-out; }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .messages-popup-header {
            padding: 15px 20px;
            background: linear-gradient(135deg, #2c3e50, #34495e);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .messages-popup-header h4 { margin: 0; font-size: 16px; }
        .messages-popup-header .close-popup {
            background: none;
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.3s;
        }
        .messages-popup-header .close-popup:hover { opacity: 1; }
        
        .messages-list {
            max-height: 380px;
            overflow-y: auto;
            padding: 10px 0;
        }
        .message-item {
            padding: 12px 20px;
            border-bottom: 1px solid #f5f5f5;
            cursor: pointer;
            transition: background 0.2s;
            position: relative;
        }
        .message-item:hover { background: #f8f9fa; }
        .message-item.unread { border-left: 4px solid #3498db; background: #f0f7ff; }
        .message-item.urgent { border-left: 4px solid #e74c3c; background: #fff5f5; }
        .message-item .message-sender { font-weight: 600; font-size: 14px; color: #2c3e50; }
        .message-item .message-subject { font-size: 13px; color: #555; margin-top: 2px; }
        .message-item .message-time { font-size: 11px; color: #999; position: absolute; right: 20px; top: 12px; }
        .message-item .message-priority {
            display: inline-block;
            padding: 1px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            margin-top: 4px;
        }
        .message-priority.urgent { background: #e74c3c; color: white; }
        .message-priority.high { background: #f39c12; color: white; }
        .message-priority.medium { background: #3498db; color: white; }
        .message-priority.low { background: #95a5a6; color: white; }
        
        .messages-popup-footer {
            padding: 12px 20px;
            border-top: 1px solid #eee;
            text-align: center;
        }
        .messages-popup-footer a { color: #3498db; text-decoration: none; font-size: 14px; }
        .messages-popup-footer a:hover { text-decoration: underline; }
        
        /* Deadline Widget */
        .deadline-widget {
            background: #fff8e1;
            border-left: 4px solid #f39c12;
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 8px;
        }
        .deadline-widget.urgent { background: #ffebee; border-left-color: #e74c3c; }
        .deadline-widget .deadline-title { font-weight: 600; font-size: 14px; }
        .deadline-widget .deadline-date { font-size: 12px; color: #666; }
        .deadline-widget .deadline-days { font-size: 12px; font-weight: 600; color: #e74c3c; }
        
        /* Widgets de métricas rápidas */
        .metric-card {
            padding: 20px;
            border-radius: 10px;
            color: white;
            transition: transform 0.3s, box-shadow 0.3s;
            cursor: default;
            position: relative;
            overflow: hidden;
        }
        .metric-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        .metric-card .metric-icon {
            position: absolute;
            right: 15px;
            top: 15px;
            font-size: 30px;
            opacity: 0.3;
        }
        .metric-card .metric-label { font-size: 12px; opacity: 0.9; margin-bottom: 5px; }
        .metric-card .metric-value { font-size: 24px; font-weight: bold; }
        .metric-card .metric-sub { font-size: 11px; opacity: 0.8; margin-top: 5px; }
        
        .metric-card.purple { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .metric-card.pink { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .metric-card.blue { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .metric-card.green { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: #2c3e50; }
        .metric-card.orange { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: #2c3e50; }
        .metric-card.red { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        
        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }
        .quick-action-btn {
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            cursor: pointer;
        }
        .quick-action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.15);
        }
        .quick-action-btn .badge {
            background: rgba(255,255,255,0.3);
            border-radius: 50%;
            padding: 2px 8px;
            font-size: 11px;
            font-weight: bold;
        }
        .quick-action-btn.primary { background: #2c3e50; color: white; }
        .quick-action-btn.info { background: #3498db; color: white; }
        .quick-action-btn.success { background: #27ae60; color: white; }
        .quick-action-btn.warning { background: #f39c12; color: white; }
        .quick-action-btn.danger { background: #e74c3c; color: white; }
        
        /* Chart containers */
        .chart-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .chart-container h4 {
            margin: 0 0 15px 0;
            font-size: 15px;
            color: #2c3e50;
        }
        .chart-container canvas { max-height: 250px; }
        
        /* Botón flotante de mensajes */
        .float-message-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2c3e50, #34495e);
            color: white;
            border: none;
            font-size: 24px;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(44, 62, 80, 0.3);
            transition: all 0.3s;
            z-index: 999;
        }
        .float-message-btn:hover { transform: scale(1.1); box-shadow: 0 6px 25px rgba(44, 62, 80, 0.4); }
        .float-message-btn .btn-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #e74c3c;
            color: white;
            border-radius: 50%;
            padding: 3px 8px;
            font-size: 12px;
            font-weight: bold;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .messages-popup {
                width: calc(100% - 20px);
                bottom: 80px;
                right: 10px;
                max-height: 70vh;
            }
            .float-message-btn {
                width: 50px;
                height: 50px;
                font-size: 20px;
                bottom: 20px;
                right: 20px;
            }
            .metric-card .metric-value { font-size: 18px; }
            .quick-actions { flex-direction: column; }
            .quick-action-btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>

<!-- Overlay para móvil -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php include 'sidebar.php'; ?>

<!-- ===== MAIN CONTENT ===== -->
<main class="main-content">
    <div class="main-header">
        <div class="header-left">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <h1>Panel de Control</h1>
            <p class="welcome">
                Bienvenido, <span><?php echo htmlspecialchars($usuario['nombre'] ?? 'Usuario'); ?></span>
                <?php 
                $hora = date('H');
                if ($hora < 12): ?>
                    🌅 Buenos días
                <?php elseif ($hora < 18): ?>
                    ☀️ Buenas tardes
                <?php else: ?>
                    🌙 Buenas noches
                <?php endif; ?>
            </p>
        </div>
        <div class="header-actions">
            <button class="btn-header" onclick="toggleMessagesPopup()" style="background: transparent; border: none; font-size: 20px; cursor: pointer; color: #2c3e50; position: relative;">
                <i class="fas fa-envelope"></i>
                <?php if ($total_mensajes_no_leidos > 0): ?>
                    <span style="position: absolute; top: -5px; right: -8px; background: #e74c3c; color: white; border-radius: 50%; padding: 2px 6px; font-size: 10px; font-weight: bold;">
                        <?php echo $total_mensajes_no_leidos; ?>
                    </span>
                <?php endif; ?>
            </button>
            <a href="vender.php" class="btn-header primary">
                <i class="fas fa-plus-circle"></i> Publicar Propiedad
            </a>
        </div>
    </div>

    <!-- ===== ESTADÍSTICAS BÁSICAS ===== -->
    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-icon"><i class="fas fa-building"></i></span>
            <div class="stat-number"><?php echo $stats['total']; ?></div>
            <div class="stat-label">Total Propiedades</div>
        </div>
        <div class="stat-card success">
            <span class="stat-icon"><i class="fas fa-check-circle"></i></span>
            <div class="stat-number"><?php echo $stats['activas']; ?></div>
            <div class="stat-label">Activas</div>
        </div>
        <div class="stat-card warning">
            <span class="stat-icon"><i class="fas fa-star"></i></span>
            <div class="stat-number"><?php echo $stats['destacadas']; ?></div>
            <div class="stat-label">Destacadas</div>
        </div>
        <div class="stat-card danger">
            <span class="stat-icon"><i class="fas fa-sold-out"></i></span>
            <div class="stat-number"><?php echo $stats['vendidas']; ?></div>
            <div class="stat-label">Vendidas</div>
        </div>
        <div class="stat-card info" style="cursor: pointer;" onclick="location.href='mensajes.php'">
            <span class="stat-icon has-notifications"><i class="fas fa-envelope"></i></span>
            <div class="stat-number"><?php echo $stats['mensajes']; ?></div>
            <div class="stat-label">
                Mensajes No Leídos
                <?php if (count($mensajes_urgentes) > 0): ?>
                    <span style="font-size: 11px; color: #e74c3c; display: block; font-weight: 600;">
                        <?php echo count($mensajes_urgentes); ?> urgentes
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="stat-card warning" style="cursor: pointer;" onclick="location.href='vencimientos.php'">
            <span class="stat-icon"><i class="fas fa-clock"></i></span>
            <div class="stat-number"><?php echo $stats['vencimientos']; ?></div>
            <div class="stat-label">Vencimientos Próximos</div>
        </div>
        <div class="stat-card primary" style="cursor: pointer;" onclick="location.href='tareas.php'">
            <span class="stat-icon"><i class="fas fa-tasks"></i></span>
            <div class="stat-number"><?php echo $stats['tareas']; ?></div>
            <div class="stat-label">Tareas Pendientes</div>
        </div>
        <div class="stat-card">
            <span class="stat-icon"><i class="fas fa-file-alt"></i></span>
            <div class="stat-number"><?php echo $stats['pendientes']; ?></div>
            <div class="stat-label">En Proceso</div>
        </div>
    </div>

    <!-- ===== WIDGET DE MÉTRICAS RÁPIDAS ===== -->
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 25px;">
        <div class="metric-card purple">
            <i class="fas fa-chart-pie metric-icon"></i>
            <div class="metric-label">Valor Total Cartera</div>
            <div class="metric-value">$<?php echo number_format($stats['valor_cartera'], 0, ',', '.'); ?></div>
            <div class="metric-sub"><?php echo $stats['activas']; ?> propiedades activas</div>
        </div>
        <div class="metric-card pink">
            <i class="fas fa-coins metric-icon"></i>
            <div class="metric-label">Comisiones Generadas</div>
            <div class="metric-value">$<?php echo number_format($stats['comisiones'], 0, ',', '.'); ?></div>
            <div class="metric-sub"><?php echo $stats['vendidas']; ?> propiedades vendidas</div>
        </div>
        <div class="metric-card blue">
            <i class="fas fa-hand-holding-usd metric-icon"></i>
            <div class="metric-label">Propiedades con Ofertas</div>
            <div class="metric-value"><?php echo $stats['ofertas']; ?></div>
            <div class="metric-sub">Clientes interesados</div>
        </div>
        <div class="metric-card green">
            <i class="fas fa-percent metric-icon"></i>
            <div class="metric-label">Tasa de Conversión</div>
            <div class="metric-value"><?php echo $stats['conversion']; ?>%</div>
            <div class="metric-sub"><?php echo $stats['vendidas']; ?> de <?php echo $stats['total']; ?> propiedades</div>
        </div>
    </div>

    <!-- ===== ACCESOS DIRECTOS (QUICK ACTIONS) ===== -->
    <div class="quick-actions">
        <a href="nuevo_cliente.php" class="quick-action-btn primary">
            <i class="fas fa-user-plus"></i> Nuevo Cliente
        </a>
        <a href="agendar_cita.php" class="quick-action-btn info">
            <i class="fas fa-calendar-plus"></i> Agendar Cita
        </a>
        <a href="generar_informe.php" class="quick-action-btn success">
            <i class="fas fa-file-pdf"></i> Generar Informe
        </a>
        <a href="recordatorios.php" class="quick-action-btn warning">
            <i class="fas fa-bell"></i> Recordatorios
            <?php if (count($vencimientos_proximos) > 0): ?>
                <span class="badge"><?php echo count($vencimientos_proximos); ?></span>
            <?php endif; ?>
        </a>
        <a href="ofertas_recibidas.php" class="quick-action-btn danger">
            <i class="fas fa-gavel"></i> Ofertas Recibidas
            <?php if ($stats['ofertas'] > 0): ?>
                <span class="badge"><?php echo $stats['ofertas']; ?></span>
            <?php endif; ?>
        </a>
        <a href="analiticas.php" class="quick-action-btn" style="background: #8e44ad; color: white;">
            <i class="fas fa-chart-bar"></i> Ver Analíticas
        </a>
    </div>

    <!-- ===== GRÁFICOS Y VISUALIZACIONES ===== -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px;">
        <!-- Gráfico de distribución de propiedades -->
        <div class="chart-container">
            <h4><i class="fas fa-chart-doughnut"></i> Distribución de Propiedades</h4>
            <canvas id="propertyChart"></canvas>
        </div>
        
        <!-- Gráfico de actividad reciente -->
        <div class="chart-container">
            <h4><i class="fas fa-chart-line"></i> Actividad Últimos 7 Días</h4>
            <canvas id="activityChart"></canvas>
        </div>
    </div>

    <!-- ===== DASHBOARD DE ACTIVIDAD RECIENTE Y VENCIMIENTOS ===== -->
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 30px;">
        <!-- Columna izquierda: Actividad reciente -->
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-history"></i> Actividad Reciente</h3>
            </div>
            <div style="padding: 15px;">
                <?php if (empty($actividad_reciente)): ?>
                    <p style="color: #999; text-align: center; padding: 20px;">
                        <i class="fas fa-inbox" style="font-size: 30px; display: block; margin-bottom: 10px;"></i>
                        No hay actividad reciente
                    </p>
                <?php else: ?>
                    <div style="max-height: 300px; overflow-y: auto;">
                        <?php foreach ($actividad_reciente as $actividad): ?>
                            <div style="display: flex; align-items: center; padding: 10px 0; border-bottom: 1px solid #f0f0f0;">
                                <div style="width: 40px; height: 40px; border-radius: 50%; background: #e8f0fe; display: flex; align-items: center; justify-content: center; margin-right: 12px;">
                                    <i class="fas fa-<?php 
                                        echo match($actividad['action_type'] ?? '') {
                                            'create' => 'plus-circle',
                                            'update' => 'edit',
                                            'delete' => 'trash',
                                            'view' => 'eye',
                                            'message' => 'envelope',
                                            'deadline' => 'clock',
                                            default => 'circle'
                                        };
                                    ?>" style="color: #2c3e50;"></i>
                                </div>
                                <div style="flex: 1;">
                                    <div style="font-size: 14px; color: #333;">
                                        <?php echo htmlspecialchars($actividad['description'] ?? 'Actividad'); ?>
                                    </div>
                                    <div style="font-size: 12px; color: #999;">
                                        <?php echo date('d/m/Y H:i', strtotime($actividad['created_at'] ?? 'now')); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Columna derecha: Vencimientos próximos -->
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-clock"></i> Vencimientos Próximos</h3>
                <a href="vencimientos.php" style="font-size: 13px; color: #3498db; text-decoration: none;">Ver todos</a>
            </div>
            <div style="padding: 15px;">
                <?php if (empty($vencimientos_proximos)): ?>
                    <p style="color: #999; text-align: center; padding: 20px;">
                        <i class="fas fa-check-circle" style="font-size: 30px; color: #27ae60; display: block; margin-bottom: 10px;"></i>
                        No hay vencimientos próximos
                    </p>
                <?php else: ?>
                    <?php foreach ($vencimientos_proximos as $vencimiento): ?>
                        <?php 
                            $dias_restantes = (strtotime($vencimiento['deadline_date']) - time()) / 86400;
                            $dias_restantes = ceil($dias_restantes);
                            $es_urgente = $dias_restantes <= 3;
                        ?>
                        <div class="deadline-widget <?php echo $es_urgente ? 'urgent' : ''; ?>">
                            <div class="deadline-title">
                                <?php echo htmlspecialchars($vencimiento['property_title'] ?? 'Propiedad'); ?>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 4px;">
                                <span class="deadline-date">
                                    <i class="far fa-calendar-alt"></i> 
                                    <?php echo date('d/m/Y', strtotime($vencimiento['deadline_date'])); ?>
                                </span>
                                <span class="deadline-days">
                                    <?php if ($dias_restantes <= 0): ?>
                                        ⚠️ Vencido
                                    <?php elseif ($dias_restantes == 1): ?>
                                        🔴 Último día
                                    <?php else: ?>
                                        <?php echo $dias_restantes; ?> días
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div style="font-size: 12px; color: #666; margin-top: 2px;">
                                <?php echo htmlspecialchars($vencimiento['description']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ===== TABLA DE PROPIEDADES MEJORADA ===== -->
    <div class="table-container">
        <div class="table-header">
            <h3><i class="fas fa-list"></i> Mis Propiedades</h3>
            <div class="search-box">
                <input type="text" placeholder="Buscar propiedad..." id="searchTable">
                <a href="vender.php" class="btn-header primary" style="padding: 8px 15px; font-size: 0.85rem;">
                    <i class="fas fa-plus"></i> Nueva
                </a>
            </div>
        </div>

        <div class="table-responsive">
            <?php if (empty($propiedades)): ?>
                <div class="empty-state">
                    <i class="fas fa-home"></i>
                    <h3>No tienes propiedades publicadas</h3>
                    <p style="color: var(--gray);">Comienza publicando tu primera propiedad</p>
                    <a href="vender.php" class="btn-header primary" style="margin-top: 20px;">
                        <i class="fas fa-plus-circle"></i> Publicar Propiedad
                    </a>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Propiedad</th>
                            <th>Ubicación</th>
                            <th>Precio</th>
                            <th>Estado</th>
                            <th>Docs</th>
                            <th>Vencimientos</th>
                            <th>Fecha</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($propiedades as $propiedad): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($propiedad['titulo'] ?? 'Sin título'); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($propiedad['ubicacion'] ?? 'N/A'); ?></td>
                                <td>$<?php echo number_format($propiedad['precio'] ?? 0, 0, ',', '.'); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $propiedad['estado'] ?? 'pendiente'; ?>">
                                        <?php echo ucfirst($propiedad['estado'] ?? 'Pendiente'); ?>
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <span style="font-size: 12px;">
                                        📄 <?php echo $propiedad['doc_count'] ?? 0; ?>
                                        🖼️ <?php echo $propiedad['media_count'] ?? 0; ?>
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <?php if (($propiedad['deadlines_count'] ?? 0) > 0): ?>
                                        <span style="background: #fff3cd; padding: 2px 8px; border-radius: 10px; font-size: 11px; color: #856404;">
                                            ⚠️ <?php echo $propiedad['deadlines_count']; ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #999; font-size: 12px;">✓</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('d/m/Y', strtotime($propiedad['fecha_creacion'] ?? 'now')); ?></td>
                                <td>
                                    <div class="action-btns">
                                        <a href="propiedad.php?id=<?php echo $propiedad['id']; ?>" class="action-btn view">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="editar_propiedad.php?id=<?php echo $propiedad['id']; ?>" class="action-btn edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button class="action-btn delete" onclick="eliminarPropiedad(<?php echo $propiedad['id']; ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- ===== POPUP DE MENSAJES ===== -->
<div class="messages-popup" id="messagesPopup">
    <div class="messages-popup-header">
        <h4><i class="fas fa-envelope"></i> Mensajes</h4>
        <button class="close-popup" onclick="toggleMessagesPopup()">&times;</button>
    </div>
    <div class="messages-list">
        <?php if (empty($mensajes_no_leidos)): ?>
            <div style="text-align: center; padding: 30px 20px; color: #999;">
                <i class="fas fa-inbox" style="font-size: 40px; display: block; margin-bottom: 10px;"></i>
                No tienes mensajes nuevos
            </div>
        <?php else: ?>
            <?php foreach ($mensajes_no_leidos as $mensaje): ?>
                <div class="message-item <?php echo $mensaje['is_read'] ? '' : 'unread'; ?> <?php echo ($mensaje['priority'] ?? 'medium') === 'urgent' ? 'urgent' : ''; ?>" 
                     onclick="location.href='mensajes.php?ver=<?php echo $mensaje['id']; ?>'">
                    <div class="message-sender">
                        <?php echo htmlspecialchars($mensaje['sender_name'] ?? 'Sistema'); ?>
                        <span class="message-time">
                            <?php echo date('d/m/Y H:i', strtotime($mensaje['created_at'])); ?>
                        </span>
                    </div>
                    <div class="message-subject">
                        <?php echo htmlspecialchars($mensaje['subject'] ?? 'Sin asunto'); ?>
                    </div>
                    <div>
                        <span class="message-priority <?php echo $mensaje['priority'] ?? 'medium'; ?>">
                            <?php echo ucfirst($mensaje['priority'] ?? 'Medio'); ?>
                        </span>
                        <?php if (!$mensaje['is_read']): ?>
                            <span style="background: #3498db; color: white; padding: 1px 8px; border-radius: 10px; font-size: 10px; font-weight: 600;">
                                Nuevo
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <div class="messages-popup-footer">
        <a href="mensajes.php"><i class="fas fa-arrow-right"></i> Ver todos los mensajes</a>
    </div>
</div>

<!-- ===== BOTÓN FLOTANTE DE MENSAJES ===== -->
<button class="float-message-btn" onclick="toggleMessagesPopup()" id="messageFloatBtn">
    <i class="fas fa-envelope"></i>
    <?php if ($total_mensajes_no_leidos > 0): ?>
        <span class="btn-badge"><?php echo $total_mensajes_no_leidos; ?></span>
    <?php endif; ?>
</button>

<!-- ===== SCRIPTS ===== -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    // ===== MENÚ MÓVIL =====
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

    // ===== POPUP DE MENSAJES =====
    let messagesPopupOpen = false;

    function toggleMessagesPopup() {
        const popup = document.getElementById('messagesPopup');
        messagesPopupOpen = !messagesPopupOpen;
        popup.classList.toggle('active', messagesPopupOpen);
    }

    document.addEventListener('click', function(e) {
        const popup = document.getElementById('messagesPopup');
        const btn = document.getElementById('messageFloatBtn');
        const headerBtn = document.querySelector('.header-actions .btn-header');
        
        if (messagesPopupOpen && 
            !popup.contains(e.target) && 
            !btn.contains(e.target) &&
            !headerBtn?.contains(e.target)) {
            toggleMessagesPopup();
        }
    });

    // ===== GRÁFICOS =====
    // Datos de distribución de propiedades
    const distribucionData = <?php 
        $labels = [];
        $data = [];
        $colors = [];
        $colorMap = [
            'activa' => '#2ecc71',
            'destacada' => '#f39c12',
            'vendida' => '#e74c3c',
            'pendiente' => '#3498db',
            'suspendido' => '#95a5a6'
        ];
        foreach ($distribucion_propiedades as $item) {
            $labels[] = ucfirst($item['estado']);
            $data[] = $item['cantidad'];
            $colors[] = $colorMap[$item['estado']] ?? '#95a5a6';
        }
        echo json_encode(['labels' => $labels, 'data' => $data, 'colors' => $colors]);
    ?>;

    // Gráfico de distribución
    if (distribucionData.labels.length > 0) {
        new Chart(document.getElementById('propertyChart'), {
            type: 'doughnut',
            data: {
                labels: distribucionData.labels,
                datasets: [{
                    data: distribucionData.data,
                    backgroundColor: distribucionData.colors,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                },
                cutout: '60%'
            }
        });
    }

    // Gráfico de actividad (simulado con datos de ejemplo)
    new Chart(document.getElementById('activityChart'), {
        type: 'line',
        data: {
            labels: ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'],
            datasets: [{
                label: 'Actividad',
                data: [4, 7, 5, 9, 12, 3, 2],
                borderColor: '#3498db',
                backgroundColor: 'rgba(52, 152, 219, 0.1)',
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1 }
                }
            }
        }
    });

    // ===== BUSCAR EN TABLA =====
    document.getElementById('searchTable').addEventListener('keyup', function() {
        const searchText = this.value.toLowerCase();
        const rows = document.querySelectorAll('table tbody tr');
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(searchText) ? '' : 'none';
        });
    });

    // ===== ELIMINAR PROPIEDAD =====
    function eliminarPropiedad(id) {
        if (confirm('¿Estás seguro de que quieres eliminar esta propiedad? Esta acción no se puede deshacer.')) {
            window.location.href = 'eliminar_propiedad.php?id=' + id;
        }
    }

    // ===== CERRAR SESIÓN =====
    document.querySelector('.logout-section a')?.addEventListener('click', function(e) {
        if (!confirm('¿Seguro que quieres cerrar sesión?')) e.preventDefault();
    });

    // ===== NOTIFICACIONES EN TIEMPO REAL =====
    setInterval(function() {
        fetch('api/notificaciones.php')
            .then(response => response.json())
            .then(data => {
                if (data.total_no_leidos !== undefined) {
                    const badges = document.querySelectorAll('.badge-count, .btn-badge');
                    const statNumber = document.querySelector('.stat-card.info .stat-number');
                    
                    badges.forEach(badge => {
                        if (badge.classList.contains('badge-count') || badge.classList.contains('btn-badge')) {
                            if (data.total_no_leidos > 0) {
                                badge.textContent = data.total_no_leidos;
                                badge.style.display = '';
                            } else {
                                badge.style.display = 'none';
                            }
                        }
                    });
                    
                    if (statNumber) statNumber.textContent = data.total_no_leidos || 0;
                }
            })
            .catch(error => console.log('Error:', error));
    }, 30000);
</script>

</body>
</html>