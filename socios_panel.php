<?php
// ============================================
// socios_panel.php - DASHBOARD COMPLETO
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
// 1. FUNCIONES DE MÉTRICAS (Todas las consultas reales)
// ============================================================

/**
 * Obtener KPIs principales del dashboard
 */
function getDashboardKPIs($conn, $usuario_id) {
    // 1. Total de propiedades
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM properties WHERE owner_id = ?");
    $stmt->execute([$usuario_id]);
    $total_propiedades = $stmt->fetchColumn();
    
    // 2. Propiedades activas
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM properties WHERE owner_id = ? AND status = 'activo'");
    $stmt->execute([$usuario_id]);
    $propiedades_activas = $stmt->fetchColumn();
    
    // 3. Propiedades vendidas (desde tracking)
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM property_tracking pt
        JOIN properties p ON pt.property_id = p.id
        WHERE pt.initiated_by = ? AND pt.status = 'completado'
    ");
    $stmt->execute([$usuario_id]);
    $propiedades_vendidas = $stmt->fetchColumn();
    
    // 4. Procesos activos
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM property_tracking 
        WHERE initiated_by = ? AND status = 'activo'
    ");
    $stmt->execute([$usuario_id]);
    $procesos_activos = $stmt->fetchColumn();
    
    // 5. Valor total de cartera
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(pf.asking_price), 0) as total
        FROM properties p
        LEFT JOIN property_financials pf ON p.id = pf.property_id
        WHERE p.owner_id = ? AND p.status = 'activo'
    ");
    $stmt->execute([$usuario_id]);
    $valor_cartera = $stmt->fetchColumn();
    
    // 6. Comisiones generadas (de propiedades completadas)
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(pf.asking_price * pf.commission_percentage / 100), 0) as total
        FROM property_tracking pt
        JOIN properties p ON pt.property_id = p.id
        JOIN property_financials pf ON p.id = pf.property_id
        WHERE pt.initiated_by = ? AND pt.status = 'completado'
    ");
    $stmt->execute([$usuario_id]);
    $comisiones_generadas = $stmt->fetchColumn();
    
    // 7. Mensajes no leídos
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM messages 
        WHERE receiver_id = ? AND is_read = 0 AND is_archived = 0
    ");
    $stmt->execute([$usuario_id]);
    $mensajes_no_leidos = $stmt->fetchColumn();
    
    // 8. Vencimientos próximos (7 días)
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM deadlines d
        JOIN properties p ON d.property_id = p.id
        WHERE p.owner_id = ? 
        AND d.deadline_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)
        AND d.status = 'pending'
    ");
    $stmt->execute([$usuario_id]);
    $vencimientos_proximos = $stmt->fetchColumn();
    
    // 9. Tareas pendientes
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM tasks 
        WHERE assigned_to = ? AND status = 'pending'
    ");
    $stmt->execute([$usuario_id]);
    $tareas_pendientes = $stmt->fetchColumn();
    
    // 10. Propiedades con ofertas (si existe la tabla)
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(DISTINCT p.id) as total 
            FROM properties p
            JOIN offers o ON p.id = o.property_id
            WHERE p.owner_id = ? AND o.status = 'pending'
        ");
        $stmt->execute([$usuario_id]);
        $ofertas_pendientes = $stmt->fetchColumn();
    } catch (PDOException $e) {
        $ofertas_pendientes = 0;
    }
    
    // 11. Clientes totales
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) as total 
            FROM clientes 
            WHERE user_id = ? OR created_by = ?
        ");
        $stmt->execute([$usuario_id, $usuario_id]);
        $total_clientes = $stmt->fetchColumn();
    } catch (PDOException $e) {
        $total_clientes = 0;
    }
    
    // 12. Propiedades nuevas este mes
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM properties 
        WHERE owner_id = ? 
        AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
    ");
    $stmt->execute([$usuario_id]);
    $propiedades_nuevas_mes = $stmt->fetchColumn();
    
    return [
        'total_propiedades' => (int)$total_propiedades,
        'propiedades_activas' => (int)$propiedades_activas,
        'propiedades_vendidas' => (int)$propiedades_vendidas,
        'propiedades_nuevas_mes' => (int)$propiedades_nuevas_mes,
        'procesos_activos' => (int)$procesos_activos,
        'valor_cartera' => (float)$valor_cartera,
        'comisiones_generadas' => (float)$comisiones_generadas,
        'mensajes_no_leidos' => (int)$mensajes_no_leidos,
        'vencimientos_proximos' => (int)$vencimientos_proximos,
        'tareas_pendientes' => (int)$tareas_pendientes,
        'ofertas_pendientes' => (int)$ofertas_pendientes,
        'total_clientes' => (int)$total_clientes,
    ];
}

/**
 * Obtener ventas por mes para gráfico
 */
function getVentasPorMes($conn, $usuario_id, $meses = 6) {
    $stmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(pt.initiated_at, '%Y-%m') as mes,
            COUNT(*) as cantidad,
            COALESCE(SUM(pf.asking_price), 0) as total_ventas,
            COALESCE(SUM(pf.asking_price * pf.commission_percentage / 100), 0) as comisiones
        FROM property_tracking pt
        JOIN properties p ON pt.property_id = p.id
        LEFT JOIN property_financials pf ON p.id = pf.property_id
        WHERE pt.initiated_by = ? 
        AND pt.status = 'completado'
        AND pt.initiated_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)
        GROUP BY DATE_FORMAT(pt.initiated_at, '%Y-%m')
        ORDER BY mes ASC
    ");
    $stmt->execute([$usuario_id, $meses]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Obtener distribución de propiedades por tipo
 */
function getDistribucionPropiedades($conn, $usuario_id) {
    $stmt = $conn->prepare("
        SELECT 
            p.operation_type,
            COUNT(*) as cantidad,
            ROUND(COUNT(*) * 100.0 / NULLIF((SELECT COUNT(*) FROM properties WHERE owner_id = ?), 0), 1) as porcentaje
        FROM properties p
        WHERE p.owner_id = ?
        GROUP BY p.operation_type
    ");
    $stmt->execute([$usuario_id, $usuario_id]);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Si no hay datos, devolver valores por defecto
    if (empty($result)) {
        return [
            ['operation_type' => 'venta', 'cantidad' => 0, 'porcentaje' => 0],
            ['operation_type' => 'alquiler', 'cantidad' => 0, 'porcentaje' => 0]
        ];
    }
    return $result;
}

/**
 * Obtener distribución por estado de propiedades
 */
function getDistribucionEstados($conn, $usuario_id) {
    $stmt = $conn->prepare("
        SELECT 
            status,
            COUNT(*) as cantidad,
            ROUND(COUNT(*) * 100.0 / NULLIF((SELECT COUNT(*) FROM properties WHERE owner_id = ?), 0), 1) as porcentaje
        FROM properties p
        WHERE p.owner_id = ?
        GROUP BY status
        ORDER BY cantidad DESC
    ");
    $stmt->execute([$usuario_id, $usuario_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Obtener procesos activos con progreso
 */
function getProcesosActivos($conn, $usuario_id) {
    $stmt = $conn->prepare("
        SELECT 
            pt.id,
            p.title as propiedad,
            pt.current_stage,
            pt.initiated_at,
            (
                SELECT COUNT(*) 
                FROM tracking_stages ts 
                WHERE ts.tracking_id = pt.id 
                AND ts.status = 'completado'
            ) as etapas_completadas,
            (
                SELECT COUNT(*) 
                FROM tracking_stages ts 
                WHERE ts.tracking_id = pt.id
            ) as total_etapas,
            DATEDIFF(NOW(), pt.initiated_at) as dias_en_proceso
        FROM property_tracking pt
        JOIN properties p ON pt.property_id = p.id
        WHERE pt.status = 'activo'
        AND pt.initiated_by = ?
        ORDER BY pt.updated_at DESC
        LIMIT 5
    ");
    $stmt->execute([$usuario_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Obtener actividad reciente unificada
 */
function getActividadReciente($conn, $usuario_id, $limite = 8) {
    // Propiedades recientes
    $sql_propiedades = "
        SELECT 
            'propiedad' as tipo,
            title as descripcion,
            'creada' as accion,
            created_at as fecha,
            'fa-home' as icono
        FROM properties 
        WHERE owner_id = ? 
        ORDER BY created_at DESC 
        LIMIT 3
    ";
    
    // Procesos actualizados
    $sql_procesos = "
        SELECT 
            'proceso' as tipo,
            CONCAT(p.title, ' - ', pt.current_stage) as descripcion,
            'actualizado' as accion,
            pt.updated_at as fecha,
            'fa-tasks' as icono
        FROM property_tracking pt
        JOIN properties p ON pt.property_id = p.id
        WHERE pt.initiated_by = ? 
        ORDER BY pt.updated_at DESC 
        LIMIT 3
    ";
    
    // Documentos subidos
    $sql_documentos = "
        SELECT 
            'documento' as tipo,
            file_name as descripcion,
            'subido' as accion,
            uploaded_at as fecha,
            'fa-file-alt' as icono
        FROM client_uploaded_documents
        WHERE property_id IN (SELECT id FROM properties WHERE owner_id = ?)
        ORDER BY uploaded_at DESC 
        LIMIT 2
    ";
    
    // Unir todo
    $sql = "($sql_propiedades) UNION ALL ($sql_procesos) UNION ALL ($sql_documentos) ORDER BY fecha DESC LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$usuario_id, $usuario_id, $usuario_id, $limite]);
    
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatear fechas
    foreach ($result as &$item) {
        $item['fecha_formateada'] = date('d/m/Y H:i', strtotime($item['fecha']));
        $item['tiempo_relativo'] = tiempoRelativo($item['fecha']);
    }
    
    return $result;
}

/**
 * Función auxiliar para tiempo relativo
 */
function tiempoRelativo($fecha) {
    $timestamp = strtotime($fecha);
    $diff = time() - $timestamp;
    
    if ($diff < 60) return 'hace ' . $diff . ' segundos';
    if ($diff < 3600) return 'hace ' . round($diff / 60) . ' minutos';
    if ($diff < 86400) return 'hace ' . round($diff / 3600) . ' horas';
    if ($diff < 604800) return 'hace ' . round($diff / 86400) . ' días';
    return date('d/m/Y', $timestamp);
}

/**
 * Obtener alertas inteligentes
 */
function getAlertasDashboard($conn, $usuario_id) {
    $alertas = [];
    
    // 1. Propiedades sin precio
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM properties p
        LEFT JOIN property_financials pf ON p.id = pf.property_id
        WHERE p.owner_id = ? 
        AND (pf.asking_price IS NULL OR pf.asking_price = 0)
    ");
    $stmt->execute([$usuario_id]);
    $sin_precio = $stmt->fetchColumn();
    if ($sin_precio > 0) {
        $alertas[] = [
            'tipo' => 'warning',
            'icono' => 'fa-exclamation-triangle',
            'mensaje' => "{$sin_precio} propiedad(es) sin precio asignado",
            'url' => 'mis_propiedades.php?filtro=sin_precio'
        ];
    }
    
    // 2. Propiedades sin imágenes
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM properties p
        LEFT JOIN property_media pm ON p.id = pm.property_id
        WHERE p.owner_id = ? 
        AND pm.id IS NULL
    ");
    $stmt->execute([$usuario_id]);
    $sin_imagenes = $stmt->fetchColumn();
    if ($sin_imagenes > 0) {
        $alertas[] = [
            'tipo' => 'info',
            'icono' => 'fa-image',
            'mensaje' => "{$sin_imagenes} propiedad(es) sin imágenes",
            'url' => 'mis_propiedades.php?filtro=sin_imagenes'
        ];
    }
    
    // 3. Vencimientos próximos
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM deadlines d
        JOIN properties p ON d.property_id = p.id
        WHERE p.owner_id = ? 
        AND d.deadline_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)
        AND d.status = 'pending'
    ");
    $stmt->execute([$usuario_id]);
    $vencimientos = $stmt->fetchColumn();
    if ($vencimientos > 0) {
        $dias_texto = $vencimientos == 1 ? 'día' : 'días';
        $alertas[] = [
            'tipo' => 'danger',
            'icono' => 'fa-clock',
            'mensaje' => "{$vencimientos} vencimiento(s) en los próximos 7 {$dias_texto}",
            'url' => 'vencimientos.php'
        ];
    }
    
    // 4. Documentos pendientes de revisión
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) as total 
            FROM client_uploaded_documents c
            JOIN properties p ON c.property_id = p.id
            WHERE p.owner_id = ? 
            AND c.status = 'pending_review'
        ");
        $stmt->execute([$usuario_id]);
        $docs_pendientes = $stmt->fetchColumn();
        if ($docs_pendientes > 0) {
            $alertas[] = [
                'tipo' => 'info',
                'icono' => 'fa-file-alt',
                'mensaje' => "{$docs_pendientes} documento(s) pendientes de revisión",
                'url' => 'documentos_pendientes.php'
            ];
        }
    } catch (PDOException $e) {
        // La tabla puede no existir
    }
    
    // 5. Mensajes no leídos (si son muchos)
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM messages 
        WHERE receiver_id = ? AND is_read = 0 AND is_archived = 0
    ");
    $stmt->execute([$usuario_id]);
    $mensajes = $stmt->fetchColumn();
    if ($mensajes > 5) {
        $alertas[] = [
            'tipo' => 'warning',
            'icono' => 'fa-envelope',
            'mensaje' => "Tienes {$mensajes} mensajes sin leer",
            'url' => 'mensajes.php'
        ];
    }
    
    // 6. Propiedades antiguas (más de 30 días sin actualizar)
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM properties 
        WHERE owner_id = ? 
        AND status = 'activo'
        AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        AND updated_at < DATE_SUB(NOW(), INTERVAL 15 DAY)
    ");
    $stmt->execute([$usuario_id]);
    $antiguas = $stmt->fetchColumn();
    if ($antiguas > 0) {
        $alertas[] = [
            'tipo' => 'warning',
            'icono' => 'fa-clock',
            'mensaje' => "{$antiguas} propiedad(es) llevan más de 30 días sin actualizar",
            'url' => 'mis_propiedades.php?filtro=antiguas'
        ];
    }
    
    return $alertas;
}

/**
 * Obtener estadísticas de comisiones por asesor (solo admin)
 */
function getEstadisticasAsesores($conn) {
    $stmt = $conn->prepare("
        SELECT 
            u.id,
            u.name,
            u.comision_porcentaje as comision_actual,
            COUNT(pt.id) as total_propiedades,
            SUM(CASE WHEN pt.status = 'completado' THEN 1 ELSE 0 END) as vendidas,
            SUM(CASE WHEN pt.status = 'completado' THEN pf.asking_price * pf.commission_percentage / 100 ELSE 0 END) as comisiones_total,
            AVG(CASE WHEN pt.status = 'completado' THEN DATEDIFF(pt.updated_at, pt.initiated_at) ELSE NULL END) as dias_promedio
        FROM users u
        LEFT JOIN property_tracking pt ON pt.initiated_by = u.id
        LEFT JOIN properties p ON pt.property_id = p.id
        LEFT JOIN property_financials pf ON p.id = pf.property_id
        WHERE u.role = 'asesor'
        GROUP BY u.id
        ORDER BY comisiones_total DESC
        LIMIT 5
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// 2. OBTENER TODOS LOS DATOS PARA EL DASHBOARD
// ============================================================

$kpis = getDashboardKPIs($conn, $usuario_id);
$ventas_mensuales = getVentasPorMes($conn, $usuario_id, 6);
$distribucion = getDistribucionPropiedades($conn, $usuario_id);
$distribucion_estados = getDistribucionEstados($conn, $usuario_id);
$procesos_activos = getProcesosActivos($conn, $usuario_id);
$actividad_reciente = getActividadReciente($conn, $usuario_id);
$alertas = getAlertasDashboard($conn, $usuario_id);

// Estadísticas adicionales (solo admin)
$estadisticas_asesores = $es_admin ? getEstadisticasAsesores($conn) : [];

// ============================================================
// 3. FUNCIONES AUXILIARES PARA VISTA
// ============================================================

function formatearMoneda($monto) {
    if ($monto === null || $monto === '') {
        return '$0';
    }
    return '$' . number_format(floatval($monto), 0, ',', '.');
}

function getColorEstado($estado) {
    $colores = [
        'activo' => '#10b981',
        'pendiente' => '#f59e0b',
        'vendido' => '#3b82f6',
        'suspendido' => '#ef4444',
        'completado' => '#10b981'
    ];
    return $colores[$estado] ?? '#6b7280';
}

// Preparar datos para gráficos (JSON)
$ventas_labels = array_column($ventas_mensuales, 'mes');
$ventas_values = array_column($ventas_mensuales, 'total_ventas');
$comisiones_values = array_column($ventas_mensuales, 'comisiones');

// Si no hay datos, poner valores por defecto
if (empty($ventas_labels)) {
    $meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun'];
    $ventas_labels = $meses;
    $ventas_values = array_fill(0, 6, 0);
    $comisiones_values = array_fill(0, 6, 0);
}

$dist_labels = array_column($distribucion, 'operation_type');
$dist_values = array_column($distribucion, 'cantidad');
// Traducir etiquetas
$dist_labels = array_map(function($label) {
    return $label === 'venta' ? 'Venta' : ($label === 'alquiler' ? 'Alquiler' : ucfirst($label));
}, $dist_labels);

$estado_labels = array_column($distribucion_estados, 'status');
$estado_values = array_column($distribucion_estados, 'cantidad');
$estado_labels = array_map('ucfirst', $estado_labels);

// Colores para gráficos
$colores_chart = ['#4f46e5', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/socios.css">
    <title>Dashboard | Inmobiliaria MH</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        /* ===== ESTILOS ADICIONALES PARA EL DASHBOARD ===== */
        
        /* KPIs */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 25px;
        }
        
        .kpi-card {
            background: white;
            border-radius: 12px;
            padding: 18px 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border-left: 4px solid #4f46e5;
            transition: all 0.3s ease;
        }
        
        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.08);
        }
        
        .kpi-card .kpi-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        
        .kpi-card .kpi-icon {
            font-size: 22px;
            color: #4f46e5;
            opacity: 0.7;
        }
        
        .kpi-card .kpi-value {
            font-size: 28px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.2;
        }
        
        .kpi-card .kpi-label {
            font-size: 13px;
            color: #64748b;
            margin-top: 2px;
        }
        
        .kpi-card .kpi-change {
            font-size: 12px;
            font-weight: 600;
            margin-top: 6px;
            padding: 2px 10px;
            border-radius: 12px;
            display: inline-block;
        }
        
        .kpi-card .kpi-change.positive {
            background: #dcfce7;
            color: #16a34a;
        }
        
        .kpi-card .kpi-change.negative {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .kpi-card .kpi-change.neutral {
            background: #f1f5f9;
            color: #475569;
        }
        
        .kpi-card.blue { border-left-color: #4f46e5; }
        .kpi-card.green { border-left-color: #10b981; }
        .kpi-card.orange { border-left-color: #f59e0b; }
        .kpi-card.red { border-left-color: #ef4444; }
        .kpi-card.purple { border-left-color: #8b5cf6; }
        .kpi-card.teal { border-left-color: #06b6d4; }
        
        /* Alertas */
        .alertas-container {
            background: white;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        
        .alertas-container .alertas-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .alertas-container .alertas-header h3 {
            font-size: 15px;
            font-weight: 600;
            color: #0f172a;
            margin: 0;
        }
        
        .alertas-container .alertas-header .badge-alertas {
            background: #f1f5f9;
            padding: 2px 12px;
            border-radius: 12px;
            font-size: 12px;
            color: #64748b;
        }
        
        .alerta-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 6px;
            transition: all 0.2s;
        }
        
        .alerta-item:last-child {
            margin-bottom: 0;
        }
        
        .alerta-item:hover {
            background: #f8fafc;
        }
        
        .alerta-item .alerta-icon {
            font-size: 18px;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .alerta-item.warning .alerta-icon {
            background: #fef3c7;
            color: #d97706;
        }
        
        .alerta-item.danger .alerta-icon {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .alerta-item.info .alerta-icon {
            background: #dbeafe;
            color: #2563eb;
        }
        
        .alerta-item.success .alerta-icon {
            background: #dcfce7;
            color: #16a34a;
        }
        
        .alerta-item .alerta-mensaje {
            flex: 1;
            font-size: 14px;
            color: #1e293b;
        }
        
        .alerta-item .alerta-link {
            font-size: 13px;
            color: #4f46e5;
            text-decoration: none;
            font-weight: 500;
        }
        
        .alerta-item .alerta-link:hover {
            text-decoration: underline;
        }
        
        .no-alertas {
            text-align: center;
            padding: 20px;
            color: #94a3b8;
        }
        
        .no-alertas i {
            font-size: 30px;
            display: block;
            margin-bottom: 8px;
        }
        
        /* Gráficos */
        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .chart-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        
        .chart-card h4 {
            font-size: 14px;
            font-weight: 600;
            color: #0f172a;
            margin: 0 0 15px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .chart-card h4 i {
            color: #64748b;
        }
        
        .chart-card canvas {
            max-height: 220px;
            max-width: 100%;
        }
        
        /* Procesos activos */
        .procesos-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        
        .procesos-container .procesos-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .procesos-container .procesos-header h3 {
            font-size: 15px;
            font-weight: 600;
            color: #0f172a;
            margin: 0;
        }
        
        .procesos-container .procesos-header a {
            font-size: 13px;
            color: #4f46e5;
            text-decoration: none;
        }
        
        .proceso-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 12px 14px;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.2s;
        }
        
        .proceso-item:last-child {
            border-bottom: none;
        }
        
        .proceso-item:hover {
            background: #f8fafc;
        }
        
        .proceso-item .proceso-info {
            flex: 1;
            min-width: 0;
        }
        
        .proceso-item .proceso-titulo {
            font-weight: 600;
            color: #0f172a;
            font-size: 14px;
        }
        
        .proceso-item .proceso-etapa {
            font-size: 12px;
            color: #64748b;
            display: block;
        }
        
        .proceso-item .proceso-dias {
            font-size: 12px;
            color: #94a3b8;
            white-space: nowrap;
        }
        
        .proceso-item .progreso-wrapper {
            width: 120px;
            flex-shrink: 0;
        }
        
        .proceso-item .progress-bar {
            height: 6px;
            background: #f1f5f9;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .proceso-item .progress-bar .progress-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.6s ease;
            background: #4f46e5;
        }
        
        .proceso-item .progreso-texto {
            font-size: 11px;
            color: #94a3b8;
            text-align: right;
            margin-top: 2px;
        }
        
        .proceso-item .btn-ver-proceso {
            padding: 4px 12px;
            background: #f1f5f9;
            color: #475569;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .proceso-item .btn-ver-proceso:hover {
            background: #e2e8f0;
        }
        
        /* Actividad reciente */
        .actividad-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        
        .actividad-container h3 {
            font-size: 15px;
            font-weight: 600;
            color: #0f172a;
            margin: 0 0 15px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .actividad-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 10px 0;
            border-bottom: 1px solid #f8fafc;
        }
        
        .actividad-item:last-child {
            border-bottom: none;
        }
        
        .actividad-item .act-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .actividad-item .act-icon.propiedad {
            background: #dbeafe;
            color: #2563eb;
        }
        
        .actividad-item .act-icon.proceso {
            background: #dcfce7;
            color: #16a34a;
        }
        
        .actividad-item .act-icon.documento {
            background: #fef3c7;
            color: #d97706;
        }
        
        .actividad-item .act-icon.notificacion {
            background: #ede9fe;
            color: #7c3aed;
        }
        
        .actividad-item .act-contenido {
            flex: 1;
            min-width: 0;
        }
        
        .actividad-item .act-descripcion {
            font-size: 14px;
            color: #0f172a;
        }
        
        .actividad-item .act-descripcion .accion {
            color: #64748b;
        }
        
        .actividad-item .act-fecha {
            font-size: 12px;
            color: #94a3b8;
            white-space: nowrap;
        }
        
        .no-actividad {
            text-align: center;
            padding: 20px;
            color: #94a3b8;
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .kpi-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .kpi-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .kpi-card .kpi-value {
                font-size: 22px;
            }
            
            .proceso-item {
                flex-wrap: wrap;
                gap: 10px;
            }
            
            .proceso-item .progreso-wrapper {
                width: 100%;
            }
        }
        
        @media (max-width: 480px) {
            .kpi-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Badge de notificaciones en sidebar (ya existe) */
        .notification-badge .badge-count {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #ef4444;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 10px;
            font-weight: bold;
            min-width: 18px;
            text-align: center;
        }
    </style>
</head>
<body>

<!-- Overlay para móvil -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php include 'modulos/sidebar.php'; ?>

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
            <a href="vender.php" class="btn-header primary">
                <i class="fas fa-plus-circle"></i> Publicar Propiedad
            </a>
        </div>
    </div>

    <!-- ===== KPIS ===== -->
    <div class="kpi-grid">
        <div class="kpi-card blue">
            <div class="kpi-top">
                <div>
                    <div class="kpi-value"><?php echo $kpis['total_propiedades']; ?></div>
                    <div class="kpi-label">Total Propiedades</div>
                </div>
                <div class="kpi-icon"><i class="fas fa-building"></i></div>
            </div>
            <div class="kpi-change <?php echo $kpis['propiedades_nuevas_mes'] > 0 ? 'positive' : 'neutral'; ?>">
                <?php echo $kpis['propiedades_nuevas_mes']; ?> nuevas este mes
            </div>
        </div>
        
        <div class="kpi-card green">
            <div class="kpi-top">
                <div>
                    <div class="kpi-value"><?php echo $kpis['propiedades_activas']; ?></div>
                    <div class="kpi-label">Activas</div>
                </div>
                <div class="kpi-icon"><i class="fas fa-check-circle"></i></div>
            </div>
        </div>
        
        <div class="kpi-card orange">
            <div class="kpi-top">
                <div>
                    <div class="kpi-value"><?php echo $kpis['procesos_activos']; ?></div>
                    <div class="kpi-label">Procesos Activos</div>
                </div>
                <div class="kpi-icon"><i class="fas fa-tasks"></i></div>
            </div>
        </div>
        
        <div class="kpi-card purple">
            <div class="kpi-top">
                <div>
                    <div class="kpi-value"><?php echo formatearMoneda($kpis['valor_cartera']); ?></div>
                    <div class="kpi-label">Valor Cartera</div>
                </div>
                <div class="kpi-icon"><i class="fas fa-coins"></i></div>
            </div>
        </div>
        
        <div class="kpi-card teal">
            <div class="kpi-top">
                <div>
                    <div class="kpi-value"><?php echo formatearMoneda($kpis['comisiones_generadas']); ?></div>
                    <div class="kpi-label">Comisiones Generadas</div>
                </div>
                <div class="kpi-icon"><i class="fas fa-hand-holding-usd"></i></div>
            </div>
            <div class="kpi-change neutral"><?php echo $kpis['propiedades_vendidas']; ?> propiedades vendidas</div>
        </div>
        
        <div class="kpi-card red" style="cursor: pointer;" onclick="location.href='mensajes.php'">
            <div class="kpi-top">
                <div>
                    <div class="kpi-value">
                        <?php echo $kpis['mensajes_no_leidos']; ?>
                        <?php if ($kpis['mensajes_no_leidos'] > 0): ?>
                            <span style="font-size: 14px; color: #ef4444; font-weight: 400;">📩</span>
                        <?php endif; ?>
                    </div>
                    <div class="kpi-label">Mensajes no leídos</div>
                </div>
                <div class="kpi-icon"><i class="fas fa-envelope"></i></div>
            </div>
        </div>
    </div>

    <!-- ===== ALERTAS ===== -->
    <div class="alertas-container">
        <div class="alertas-header">
            <h3><i class="fas fa-bell" style="color: #f59e0b;"></i> Alertas y Pendientes</h3>
            <span class="badge-alertas"><?php echo count($alertas); ?> alertas</span>
        </div>
        
        <?php if (empty($alertas)): ?>
            <div class="no-alertas">
                <i class="fas fa-check-circle" style="color: #10b981;"></i>
                <p>¡Todo en orden! No hay alertas pendientes.</p>
            </div>
        <?php else: ?>
            <?php foreach ($alertas as $alerta): ?>
                <div class="alerta-item <?php echo $alerta['tipo']; ?>">
                    <div class="alerta-icon">
                        <i class="fas <?php echo $alerta['icono']; ?>"></i>
                    </div>
                    <span class="alerta-mensaje"><?php echo $alerta['mensaje']; ?></span>
                    <a href="<?php echo $alerta['url']; ?>" class="alerta-link">Ver <i class="fas fa-arrow-right"></i></a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ===== GRÁFICOS ===== -->
    <div class="charts-grid">
        <div class="chart-card">
            <h4><i class="fas fa-chart-bar"></i> Ventas Mensuales</h4>
            <canvas id="ventasChart"></canvas>
        </div>
        
        <div class="chart-card">
            <h4><i class="fas fa-chart-pie"></i> Distribución por Tipo</h4>
            <canvas id="distribucionChart"></canvas>
        </div>
    </div>

    <!-- ===== PROCESOS ACTIVOS ===== -->
    <div class="procesos-container">
        <div class="procesos-header">
            <h3><i class="fas fa-route" style="color: #4f46e5;"></i> Procesos Activos</h3>
            <a href="rastreabilidad.php">Ver todos <i class="fas fa-arrow-right"></i></a>
        </div>
        
        <?php if (empty($procesos_activos)): ?>
            <div class="no-actividad">
                <i class="fas fa-check-circle" style="color: #10b981;"></i>
                <p>No hay procesos activos en este momento.</p>
            </div>
        <?php else: ?>
            <?php foreach ($procesos_activos as $proceso): 
                $progreso = $proceso['total_etapas'] > 0 ? round(($proceso['etapas_completadas'] / $proceso['total_etapas']) * 100) : 0;
                $color_progreso = $progreso >= 80 ? '#10b981' : ($progreso >= 50 ? '#f59e0b' : '#4f46e5');
            ?>
                <div class="proceso-item">
                    <div class="proceso-info">
                        <span class="proceso-titulo"><?php echo htmlspecialchars($proceso['propiedad']); ?></span>
                        <span class="proceso-etapa">
                            <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: <?php echo getColorEstado($proceso['current_stage']); ?>; margin-right: 4px;"></span>
                            <?php echo ucfirst(str_replace('_', ' ', $proceso['current_stage'])); ?>
                        </span>
                    </div>
                    
                    <div class="proceso-dias">
                        <i class="far fa-calendar-alt"></i> <?php echo $proceso['dias_en_proceso']; ?> días
                    </div>
                    
                    <div class="progreso-wrapper">
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $progreso; ?>%; background: <?php echo $color_progreso; ?>;"></div>
                        </div>
                        <div class="progreso-texto"><?php echo $progreso; ?>% (<?php echo $proceso['etapas_completadas']; ?>/<?php echo $proceso['total_etapas']; ?>)</div>
                    </div>
                    
                    <a href="proceso_detalle.php?id=<?php echo $proceso['id']; ?>" class="btn-ver-proceso">
                        <i class="fas fa-eye"></i> Ver
                    </a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ===== ACTIVIDAD RECIENTE ===== -->
    <div class="actividad-container">
        <h3><i class="fas fa-history" style="color: #64748b;"></i> Actividad Reciente</h3>
        
        <?php if (empty($actividad_reciente)): ?>
            <div class="no-actividad">
                <i class="fas fa-inbox"></i>
                <p>No hay actividad reciente</p>
            </div>
        <?php else: ?>
            <?php foreach ($actividad_reciente as $actividad): ?>
                <div class="actividad-item">
                    <div class="act-icon <?php echo $actividad['tipo']; ?>">
                        <i class="fas <?php echo $actividad['icono']; ?>"></i>
                    </div>
                    <div class="act-contenido">
                        <div class="act-descripcion">
                            <?php echo htmlspecialchars($actividad['descripcion']); ?>
                            <span class="accion"><?php echo $actividad['accion']; ?></span>
                        </div>
                    </div>
                    <div class="act-fecha" title="<?php echo $actividad['fecha_formateada']; ?>">
                        <?php echo $actividad['tiempo_relativo']; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</main>

<!-- ===== SCRIPTS PARA GRÁFICOS ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== MENÚ MÓVIL =====
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    function toggleSidebar() {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('show');
        document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
    }

    if (menuToggle) {
        menuToggle.addEventListener('click', toggleSidebar);
    }
    if (overlay) {
        overlay.addEventListener('click', toggleSidebar);
    }

    document.querySelectorAll('.sidebar nav a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 992) toggleSidebar();
        });
    });

    // ===== GRÁFICO DE VENTAS =====
    const ctxVentas = document.getElementById('ventasChart').getContext('2d');
    const ventasLabels = <?php echo json_encode($ventas_labels); ?>;
    const ventasValues = <?php echo json_encode($ventas_values); ?>;
    const comisionesValues = <?php echo json_encode($comisiones_values); ?>;
    
    // Si no hay datos, mostrar mensaje
    if (ventasValues.every(v => v === 0)) {
        ctxVentas.canvas.parentElement.innerHTML = `
            <div style="text-align: center; padding: 30px; color: #94a3b8;">
                <i class="fas fa-chart-simple" style="font-size: 30px; display: block; margin-bottom: 10px;"></i>
                <p>No hay datos de ventas aún</p>
                <small>Las propiedades vendidas aparecerán aquí</small>
            </div>
        `;
    } else {
        new Chart(ctxVentas, {
            type: 'bar',
            data: {
                labels: ventasLabels,
                datasets: [
                    {
                        label: 'Ventas ($)',
                        data: ventasValues,
                        backgroundColor: 'rgba(79, 70, 229, 0.7)',
                        borderColor: '#4f46e5',
                        borderWidth: 1,
                        borderRadius: 4,
                        order: 1
                    },
                    {
                        label: 'Comisiones ($)',
                        data: comisionesValues,
                        backgroundColor: 'rgba(16, 185, 129, 0.7)',
                        borderColor: '#10b981',
                        borderWidth: 1,
                        borderRadius: 4,
                        order: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            font: { size: 11 },
                            boxWidth: 12,
                            padding: 10
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toLocaleString();
                            },
                            font: { size: 10 }
                        }
                    },
                    x: {
                        ticks: { font: { size: 10 } }
                    }
                }
            }
        });
    }

    // ===== GRÁFICO DE DISTRIBUCIÓN =====
    const ctxDist = document.getElementById('distribucionChart').getContext('2d');
    const distLabels = <?php echo json_encode($dist_labels); ?>;
    const distValues = <?php echo json_encode($dist_values); ?>;
    
    if (distValues.every(v => v === 0)) {
        ctxDist.canvas.parentElement.innerHTML = `
            <div style="text-align: center; padding: 30px; color: #94a3b8;">
                <i class="fas fa-chart-pie" style="font-size: 30px; display: block; margin-bottom: 10px;"></i>
                <p>No hay propiedades para distribuir</p>
            </div>
        `;
    } else {
        const colores = ['#4f46e5', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4'];
        
        new Chart(ctxDist, {
            type: 'doughnut',
            data: {
                labels: distLabels,
                datasets: [{
                    data: distValues,
                    backgroundColor: colores.slice(0, distLabels.length),
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
                        labels: {
                            font: { size: 11 },
                            boxWidth: 12,
                            padding: 10
                        }
                    }
                },
                cutout: '65%'
            }
        });
    }
});

// ===== ACTUALIZACIÓN AUTOMÁTICA CADA 30 SEGUNDOS =====
setInterval(function() {
    fetch(window.location.href + '?refresh=1')
        .then(response => response.text())
        .then(html => {
            // Actualizar solo las partes dinámicas
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            
            // Actualizar KPIs
            document.querySelector('.kpi-grid').innerHTML = doc.querySelector('.kpi-grid').innerHTML;
            
            // Actualizar alertas
            document.querySelector('.alertas-container').innerHTML = doc.querySelector('.alertas-container').innerHTML;
            
            // Actualizar procesos
            document.querySelector('.procesos-container').innerHTML = doc.querySelector('.procesos-container').innerHTML;
            
            // Actualizar actividad
            document.querySelector('.actividad-container').innerHTML = doc.querySelector('.actividad-container').innerHTML;
        })
        .catch(error => console.log('Error refreshing dashboard:', error));
}, 30000);
</script>

</body>
</html>