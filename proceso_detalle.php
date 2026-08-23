<?php
// proceso_detalle.php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/conexion.php';
require_once 'includes/auth.php';
require_once 'includes/notificaciones.php'; // 🔥 NUEVO - Sistema de notificaciones

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

// Obtener ID del proceso
$proceso_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($proceso_id <= 0) {
    header('Location: rastreabilidad.php');
    exit;
}

// Obtener datos del proceso
$proceso = null;
$etapas = [];
$documentos = [];
$pagos = [];
$historial_notificaciones = []; // 🔥 NUEVO
$estadisticas_notificaciones = []; // 🔥 NUEVO
$error_msg = '';

try {
    // Obtener información del proceso - RESPETANDO TUS CAMPOS
    $stmt = $conn->prepare("
        SELECT 
            pt.*,
            p.title as property_title,
            p.operation_type,
            p.address_municipality,
            p.address_state,
            p.address_city,
            f.asking_price,
            f.min_acceptable_price,
            f.commission_percentage,
            u.name as initiated_by_name,
            u.email as cliente_email,
            u.telefono as cliente_telefono
        FROM property_tracking pt
        JOIN properties p ON pt.property_id = p.id
        LEFT JOIN property_financials f ON p.id = f.property_id
        LEFT JOIN users u ON pt.initiated_by = u.id
        WHERE pt.id = ?
    ");
    $stmt->execute([$proceso_id]);
    $proceso = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$proceso) {
        $error_msg = "Proceso no encontrado.";
    } else {
        // Obtener etapas del proceso
        $stmtEtapas = $conn->prepare("
            SELECT *
            FROM tracking_stages
            WHERE tracking_id = ?
            ORDER BY stage_order
        ");
        $stmtEtapas->execute([$proceso_id]);
        $etapas = $stmtEtapas->fetchAll(PDO::FETCH_ASSOC);
        
        // Obtener documentos
        $stmtDocs = $conn->prepare("
            SELECT *
            FROM tracking_documents
            WHERE tracking_id = ?
            ORDER BY generated_at DESC
        ");
        $stmtDocs->execute([$proceso_id]);
        $documentos = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);
        
        // Obtener pagos
        $stmtPagos = $conn->prepare("
            SELECT *
            FROM tracking_payments
            WHERE tracking_id = ?
            ORDER BY payment_date DESC
        ");
        $stmtPagos->execute([$proceso_id]);
        $pagos = $stmtPagos->fetchAll(PDO::FETCH_ASSOC);
        
        // 🔥 NUEVO: Obtener historial de notificaciones
        $historial_notificaciones = obtenerHistorialNotificaciones($conn, $proceso_id);
        
        // 🔥 NUEVO: Obtener estadísticas de notificaciones
        $estadisticas_notificaciones = obtenerEstadisticasNotificaciones($conn);
    }
} catch (PDOException $e) {
    $error_msg = "Error al cargar proceso: " . $e->getMessage();
    error_log("Error en proceso_detalle.php: " . $e->getMessage());
}

// Función para obtener estado de etapa
function getStageStatusClass($status) {
    switch ($status) {
        case 'completado':
            return 'completed';
        case 'en_progreso':
            return 'in-progress';
        case 'pendiente':
            return 'pending';
        default:
            return '';
    }
}

// Función para calcular progreso
function getProgress($etapas) {
    if (empty($etapas)) return 0;
    $completed = array_filter($etapas, function($etapa) {
        return $etapa['status'] == 'completado';
    });
    return round((count($completed) / count($etapas)) * 100);
}

// 🔥 NUEVO: Función para obtener el nombre legible de la etapa
function getEtapaNombre($etapa) {
    $nombres = [
        'iniciado' => 'Iniciado',
        'documentacion' => 'Documentación',
        'credito' => 'Estudio de Crédito',
        'credito_preautorizado' => 'Crédito Preautorizado',
        'contrato_compraventa' => 'Contrato de Compraventa',
        'poder_notarial' => 'Poder Notarial',
        'finalizado' => 'Finalizado'
    ];
    return $nombres[$etapa] ?? ucfirst(str_replace('_', ' ', $etapa));
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalle del Proceso | Inmobiliaria MH</title>
    <link rel="stylesheet" href="css/socios.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .process-detail-container {
            padding: 20px;
        }
        
        .process-header {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .process-header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }
        
        .process-header-left {
            flex: 1;
        }
        
        .process-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 10px;
        }
        
        .process-property {
            font-size: 1rem;
            color: #64748b;
            margin-bottom: 5px;
        }
        
        .process-address {
            font-size: 0.9rem;
            color: #94a3b8;
        }
        
        .process-status {
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
        }
        
        .process-status.active {
            background: #dcfce7;
            color: #166534;
        }
        
        .progress-section {
            margin-bottom: 30px;
        }
        
        .progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .progress-bar {
            height: 10px;
            background: #f1f5f9;
            border-radius: 5px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #22c55e);
            border-radius: 5px;
            transition: width 0.5s ease;
        }
        
        .timeline-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .section-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .timeline {
            position: relative;
            padding-left: 40px;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 20px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e8edf4;
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 30px;
        }
        
        .timeline-dot {
            position: absolute;
            left: -30px;
            top: 0;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            border: 3px solid #e8edf4;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
        }
        
        .timeline-dot.completed {
            background: #22c55e;
            border-color: #22c55e;
            color: white;
        }
        
        .timeline-dot.in-progress {
            background: #3b82f6;
            border-color: #3b82f6;
            color: white;
            animation: pulse 1.5s infinite;
        }
        
        .timeline-content {
            background: #f8fafc;
            border-radius: 8px;
            padding: 20px;
        }
        
        .timeline-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        
        .timeline-title {
            font-size: 1rem;
            font-weight: 600;
            color: #0f172a;
        }
        
        .timeline-status {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .timeline-status.completed {
            background: #dcfce7;
            color: #166534;
        }
        
        .timeline-status.in-progress {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .timeline-status.pending {
            background: #f1f5f9;
            color: #64748b;
        }
        
        .timeline-notes {
            font-size: 0.85rem;
            color: #64748b;
            margin-top: 10px;
        }
        
        .documents-section, .payments-section, .notifications-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .document-item, .payment-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .document-item:last-child, .payment-item:last-child {
            border-bottom: none;
        }
        
        .document-info, .payment-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .document-icon, .payment-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: #64748b;
        }
        
        .btn-action {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-action.primary {
            background: #3b82f6;
            color: white;
        }
        
        .btn-action.primary:hover {
            background: #2563eb;
        }
        
        .btn-action.success {
            background: #22c55e;
            color: white;
        }
        
        .btn-action.success:hover {
            background: #16a34a;
        }
        
        .btn-action.warning {
            background: #f59e0b;
            color: white;
        }
        
        .btn-action.warning:hover {
            background: #d97706;
        }
        
        .btn-action.danger {
            background: #ef4444;
            color: white;
        }
        
        .btn-action.danger:hover {
            background: #dc2626;
        }
        
        .btn-action.info {
            background: #8b5cf6;
            color: white;
        }
        
        .btn-action.info:hover {
            background: #7c3aed;
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.5); }
            70% { box-shadow: 0 0 0 10px rgba(59, 130, 246, 0); }
            100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); }
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        
        .success-message {
            padding: 15px;
            background: #dcfce7;
            color: #166534;
            border-radius: 8px;
            margin: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .error-message {
            padding: 15px;
            background: #fee2e2;
            color: #991b1b;
            border-radius: 8px;
            margin: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* 🔥 NUEVO: Estilos para notificaciones */
        .notification-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.2s;
        }
        
        .notification-item:hover {
            background: #f8fafc;
        }
        
        .notification-item:last-child {
            border-bottom: none;
        }
        
        .notification-info {
            display: flex;
            align-items: center;
            gap: 15px;
            flex: 1;
        }
        
        .notification-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
        }
        
        .notification-icon.success {
            background: #dcfce7;
            color: #166534;
        }
        
        .notification-icon.failed {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .notification-details {
            flex: 1;
        }
        
        .notification-details .subject {
            font-weight: 600;
            font-size: 0.95rem;
            color: #0f172a;
        }
        
        .notification-details .meta {
            font-size: 0.8rem;
            color: #94a3b8;
            margin-top: 2px;
        }
        
        .notification-status {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            white-space: nowrap;
        }
        
        .notification-status.enviado {
            background: #dcfce7;
            color: #166534;
        }
        
        .notification-status.fallido {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .notification-date {
            font-size: 0.8rem;
            color: #94a3b8;
            margin-left: 15px;
            white-space: nowrap;
        }
        
        /* Badge de estadísticas */
        .stats-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .stats-badge.total {
            background: #f1f5f9;
            color: #475569;
        }
        
        .stats-badge.sent {
            background: #dcfce7;
            color: #166534;
        }
        
        .stats-badge.failed {
            background: #fee2e2;
            color: #991b1b;
        }
        
        /* Modal de enviar notificación */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        
        .modal-overlay.show {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-header h3 {
            margin: 0;
            color: #0f172a;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #94a3b8;
        }
        
        .modal-close:hover {
            color: #0f172a;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
            color: #0f172a;
            font-size: 0.9rem;
        }
        
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.9rem;
            font-family: inherit;
        }
        
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        .btn-modal {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-modal.cancel {
            background: #f1f5f9;
            color: #475569;
        }
        
        .btn-modal.cancel:hover {
            background: #e2e8f0;
        }
        
        .btn-modal.send {
            background: #3b82f6;
            color: white;
        }
        
        .btn-modal.send:hover {
            background: #2563eb;
        }
        
        .btn-modal.send:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php include 'sidebar.php'; ?>

<main class="main-content">
    <div class="main-header">
        <div class="header-left">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <h1>Detalle del Proceso</h1>
            <p class="welcome">
                <i class="fas fa-clipboard-list"></i> Seguimiento detallado del proceso inmobiliario
            </p>
        </div>
        <div class="header-actions">
            <button class="btn-header secondary" onclick="window.location.href='rastreabilidad.php'">
                <i class="fas fa-arrow-left"></i> Volver
            </button>
        </div>
    </div>

    <div class="process-detail-container">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i> 
                <?php 
                echo htmlspecialchars($_SESSION['success']);
                unset($_SESSION['success']);
                ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> 
                <?php 
                echo htmlspecialchars($_SESSION['error']);
                unset($_SESSION['error']);
                ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error_msg)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> 
                <?php echo htmlspecialchars($error_msg); ?>
            </div>
        <?php elseif ($proceso): 
            $progress = getProgress($etapas);
        ?>
            <!-- Header del Proceso -->
            <div class="process-header">
                <div class="process-header-top">
                    <div class="process-header-left">
                        <div class="process-title">
                            <?php echo htmlspecialchars($proceso['property_title']); ?>
                        </div>
                        <div class="process-property">
                            <i class="fas fa-tag"></i> 
                            <?php echo htmlspecialchars($proceso['operation_type'] ?? 'No especificado'); ?>
                        </div>
                        <div class="process-address">
                            <i class="fas fa-map-marker-alt"></i> 
                            <?php echo htmlspecialchars($proceso['address_city'] . ', ' . $proceso['address_municipality'] . ', ' . $proceso['address_state']); ?>
                        </div>
                        <?php if ($proceso['asking_price']): ?>
                            <div class="process-address" style="margin-top: 5px;">
                                <i class="fas fa-dollar-sign"></i> 
                                Precio: $<?php echo number_format($proceso['asking_price'], 2); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($proceso['cliente_email']): ?>
                            <div class="process-address" style="margin-top: 5px;">
                                <i class="fas fa-envelope"></i> 
                                Cliente: <?php echo htmlspecialchars($proceso['cliente_email']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="process-status active">
                        <i class="fas fa-circle"></i> Activo
                    </div>
                </div>
                
                <div class="progress-section">
                    <div class="progress-header">
                        <span>Progreso del Proceso</span>
                        <span><?php echo $progress; ?>%</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo $progress; ?>%;"></div>
                    </div>
                </div>
                
                <div style="display: flex; justify-content: space-between; font-size: 0.9rem; color: #64748b; flex-wrap: wrap; gap: 10px;">
                    <span>
                        <i class="fas fa-user"></i> 
                        Iniciado por: <?php echo htmlspecialchars($proceso['initiated_by_name']); ?>
                    </span>
                    <span>
                        <i class="fas fa-calendar"></i> 
                        Fecha: <?php echo date('d/m/Y', strtotime($proceso['initiated_at'])); ?>
                    </span>
                    <span>
                        <i class="fas fa-step-forward"></i> 
                        Etapa actual: <strong><?php echo getEtapaNombre($proceso['current_stage']); ?></strong>
                    </span>
                </div>
            </div>
            
            <!-- Timeline de Etapas -->
            <div class="timeline-section">
                <div class="section-title">
                    <i class="fas fa-tasks"></i> Etapas del Proceso
                </div>
                
                <div class="timeline">
                    <?php foreach ($etapas as $etapa): 
                        $statusClass = getStageStatusClass($etapa['status']);
                        $isCurrentStage = $etapa['stage_name'] == $proceso['current_stage'];
                    ?>
                        <div class="timeline-item">
                            <div class="timeline-dot <?php echo $statusClass; ?>">
                                <?php if ($etapa['status'] == 'completado'): ?>
                                    <i class="fas fa-check"></i>
                                <?php elseif ($etapa['status'] == 'en_progreso'): ?>
                                    <i class="fas fa-arrow-right"></i>
                                <?php endif; ?>
                            </div>
                            <div class="timeline-content">
                                <div class="timeline-header">
                                    <div class="timeline-title">
                                        <?php echo $etapa['stage_order']; ?>. 
                                        <?php echo ucfirst(str_replace('_', ' ', $etapa['stage_name'])); ?>
                                        <?php if ($isCurrentStage): ?>
                                            <span style="color: #3b82f6; font-size: 0.8rem;">
                                                <i class="fas fa-star"></i> Actual
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="timeline-status <?php echo $statusClass; ?>">
                                        <?php echo ucfirst($etapa['status']); ?>
                                    </div>
                                </div>
                                
                                <?php if ($etapa['notes']): ?>
                                    <div class="timeline-notes">
                                        <i class="fas fa-sticky-note"></i> 
                                        <?php echo htmlspecialchars($etapa['notes']); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($etapa['completed_at']): ?>
                                    <div class="timeline-notes">
                                        <i class="fas fa-clock"></i> 
                                        Completado: <?php echo date('d/m/Y H:i', strtotime($etapa['completed_at'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if ($proceso['current_stage'] != 'finalizado'): ?>
                    <div class="action-buttons">
                        <button class="btn-action primary" onclick="avanzarEtapa(<?php echo $proceso_id; ?>)">
                            <i class="fas fa-arrow-right"></i> Avanzar a Siguiente Etapa
                        </button>
                        <?php if ($proceso['current_stage'] == 'credito'): ?>
                            <button class="btn-action success" onclick="marcarCreditoPreautorizado(<?php echo $proceso_id; ?>)">
                                <i class="fas fa-check-circle"></i> Crédito Preautorizado
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Documentos -->
            <div class="documents-section">
                <div class="section-title">
                    <i class="fas fa-file-pdf"></i> Documentos del Proceso
                </div>
                
                <?php if (empty($documentos)): ?>
                    <div style="text-align: center; padding: 20px; color: #94a3b8;">
                        <i class="fas fa-file-upload" style="font-size: 2rem; display: block; margin-bottom: 10px;"></i>
                        No hay documentos generados
                    </div>
                <?php else: ?>
                    <?php foreach ($documentos as $documento): ?>
                        <div class="document-item">
                            <div class="document-info">
                                <div class="document-icon">
                                    <i class="fas fa-file-pdf"></i>
                                </div>
                                <div>
                                    <div style="font-weight: 600;">
                                        <?php echo ucfirst(str_replace('_', ' ', $documento['document_type'])); ?>
                                    </div>
                                    <div style="font-size: 0.8rem; color: #64748b;">
                                        <?php echo date('d/m/Y H:i', strtotime($documento['generated_at'])); ?>
                                    </div>
                                </div>
                            </div>
                            <button class="btn-action primary" onclick="descargarDocumento(<?php echo $documento['id']; ?>)">
                                <i class="fas fa-download"></i> Descargar
                            </button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <?php if ($proceso['current_stage'] == 'contrato_compraventa' || $proceso['current_stage'] == 'poder_notarial'): ?>
                    <div class="action-buttons">
                        <?php if ($proceso['current_stage'] == 'contrato_compraventa'): ?>
                            <a href="generar_contrato.php?proceso_id=<?php echo $proceso_id; ?>" 
                               class="btn-action primary">
                                <i class="fas fa-file-contract"></i> Generar Contrato de Compraventa
                            </a>
                        <?php endif; ?>
                        <?php if ($proceso['current_stage'] == 'poder_notarial'): ?>
                            <a href="generar_poder_notarial.php?proceso_id=<?php echo $proceso_id; ?>" 
                               class="btn-action warning">
                                <i class="fas fa-gavel"></i> Generar Poder Notarial
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Pagos -->
            <div class="payments-section">
                <div class="section-title">
                    <i class="fas fa-money-bill-wave"></i> Pagos del Proceso
                </div>
                
                <?php if (empty($pagos)): ?>
                    <div style="text-align: center; padding: 20px; color: #94a3b8;">
                        <i class="fas fa-wallet" style="font-size: 2rem; display: block; margin-bottom: 10px;"></i>
                        No hay pagos registrados
                    </div>
                <?php else: ?>
                    <?php foreach ($pagos as $pago): ?>
                        <div class="payment-item">
                            <div class="payment-info">
                                <div class="payment-icon">
                                    <i class="fas fa-receipt"></i>
                                </div>
                                <div>
                                    <div style="font-weight: 600;">
                                        <?php echo ucfirst(str_replace('_', ' ', $pago['payment_type'])); ?>
                                    </div>
                                    <div style="font-size: 0.8rem; color: #64748b;">
                                        <?php echo date('d/m/Y', strtotime($pago['payment_date'])); ?>
                                    </div>
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <div style="font-weight: 700; color: #0f172a;">
                                    $<?php echo number_format($pago['amount'], 2); ?>
                                </div>
                                <div style="font-size: 0.75rem; color: #64748b;">
                                    <?php echo ucfirst($pago['status']); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- 🔥 NUEVO: Historial de Notificaciones -->
            <div class="notifications-section">
                <div class="section-title" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-envelope"></i> Notificaciones Enviadas
                        <span class="stats-badge total">
                            <i class="fas fa-list"></i> Total: <?php echo count($historial_notificaciones); ?>
                        </span>
                        <span class="stats-badge sent">
                            <i class="fas fa-check"></i> Enviados: <?php echo $estadisticas_notificaciones['enviados'] ?? 0; ?>
                        </span>
                        <span class="stats-badge failed">
                            <i class="fas fa-times"></i> Fallidos: <?php echo $estadisticas_notificaciones['fallidos'] ?? 0; ?>
                        </span>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn-action info" onclick="abrirModalNotificacion(<?php echo $proceso_id; ?>)">
                            <i class="fas fa-paper-plane"></i> Enviar Notificación
                        </button>
                        <button class="btn-action warning" onclick="verHistorialCompleto(<?php echo $proceso_id; ?>)">
                            <i class="fas fa-history"></i> Ver Todo
                        </button>
                    </div>
                </div>
                
                <?php if (empty($historial_notificaciones)): ?>
                    <div style="text-align: center; padding: 30px; color: #94a3b8;">
                        <i class="fas fa-envelope-open" style="font-size: 2.5rem; display: block; margin-bottom: 10px;"></i>
                        <p>No hay notificaciones enviadas para este proceso</p>
                        <p style="font-size: 0.85rem;">Usa el botón "Enviar Notificación" para notificar al cliente</p>
                    </div>
                <?php else: ?>
                    <div style="max-height: 400px; overflow-y: auto;">
                        <?php foreach ($historial_notificaciones as $notificacion): ?>
                            <div class="notification-item">
                                <div class="notification-info">
                                    <div class="notification-icon <?php echo $notificacion['estado_envio'] == 'enviado' ? 'success' : 'failed'; ?>">
                                        <i class="fas <?php echo $notificacion['estado_envio'] == 'enviado' ? 'fa-check' : 'fa-times'; ?>"></i>
                                    </div>
                                    <div class="notification-details">
                                        <div class="subject">
                                            <?php echo htmlspecialchars($notificacion['asunto']); ?>
                                        </div>
                                        <div class="meta">
                                            <i class="fas fa-tag"></i> Etapa: <?php echo getEtapaNombre($notificacion['etapa']); ?>
                                            &nbsp;•&nbsp;
                                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($notificacion['email_cliente']); ?>
                                        </div>
                                    </div>
                                </div>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <span class="notification-status <?php echo $notificacion['estado_envio']; ?>">
                                        <?php echo $notificacion['estado_envio'] == 'enviado' ? '✅ Enviado' : '❌ Fallido'; ?>
                                    </span>
                                    <span class="notification-date">
                                        <?php echo date('d/m/Y H:i', strtotime($notificacion['fecha_envio'])); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<!-- 🔥 NUEVO: Modal para enviar notificación manual -->
<div class="modal-overlay" id="modalNotificacion">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-paper-plane"></i> Enviar Notificación</h3>
            <button class="modal-close" onclick="cerrarModalNotificacion()">&times;</button>
        </div>
        <form id="formNotificacion" onsubmit="enviarNotificacionManual(event)">
            <input type="hidden" id="proceso_id_modal" value="<?php echo $proceso_id; ?>">
            
            <div class="form-group">
                <label for="etapa_modal">Etapa a notificar:</label>
                <select id="etapa_modal" required>
                    <option value="iniciado">Iniciado</option>
                    <option value="documentacion">Documentación</option>
                    <option value="credito">Estudio de Crédito</option>
                    <option value="credito_preautorizado">Crédito Preautorizado</option>
                    <option value="contrato_compraventa">Contrato de Compraventa</option>
                    <option value="poder_notarial">Poder Notarial</option>
                    <option value="finalizado">Finalizado</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="mensaje_adicional">Mensaje adicional (opcional):</label>
                <textarea id="mensaje_adicional" placeholder="Agrega un mensaje personalizado..."></textarea>
            </div>
            
            <div style="background: #f8fafc; padding: 12px; border-radius: 6px; margin-bottom: 15px; font-size: 0.85rem; color: #64748b;">
                <i class="fas fa-info-circle"></i> 
                El correo se enviará al cliente: <strong><?php echo htmlspecialchars($proceso['cliente_email'] ?? 'No disponible'); ?></strong>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn-modal cancel" onclick="cerrarModalNotificacion()">Cancelar</button>
                <button type="submit" class="btn-modal send" id="btnEnviarNotificacion">
                    <i class="fas fa-paper-plane"></i> Enviar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    function toggleSidebar() {
        if (sidebar && overlay) {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('show');
            document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
        }
    }

    if(menuToggle) {
        menuToggle.addEventListener('click', toggleSidebar);
    }
    
    if(overlay) {
        overlay.addEventListener('click', toggleSidebar);
    }
});

function avanzarEtapa(procesoId) {
    if (confirm('¿Está seguro de avanzar a la siguiente etapa del proceso?\n\nSe enviará una notificación al cliente.')) {
        window.location.href = 'proceso_avanzar.php?id=' + procesoId;
    }
}

function marcarCreditoPreautorizado(procesoId) {
    if (confirm('¿Marcar crédito como preautorizado y avanzar directamente?\n\nSe enviará notificación al cliente.')) {
        window.location.href = 'proceso_credito_preautorizado.php?id=' + procesoId;
    }
}

function descargarDocumento(documentoId) {
    window.location.href = 'descargar_documento.php?id=' + documentoId;
}

// 🔥 NUEVO: Funciones para el modal de notificaciones
function abrirModalNotificacion(procesoId) {
    document.getElementById('proceso_id_modal').value = procesoId;
    document.getElementById('modalNotificacion').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function cerrarModalNotificacion() {
    document.getElementById('modalNotificacion').classList.remove('show');
    document.body.style.overflow = '';
    document.getElementById('mensaje_adicional').value = '';
}

function enviarNotificacionManual(event) {
    event.preventDefault();
    
    const procesoId = document.getElementById('proceso_id_modal').value;
    const etapa = document.getElementById('etapa_modal').value;
    const mensajeAdicional = document.getElementById('mensaje_adicional').value;
    const btn = document.getElementById('btnEnviarNotificacion');
    
    // Deshabilitar botón y mostrar loading
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';
    
    // Enviar petición
    fetch('procesar_notificacion.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            proceso_id: procesoId,
            etapa: etapa,
            mensaje_adicional: mensajeAdicional
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ Notificación enviada correctamente al cliente');
            cerrarModalNotificacion();
            location.reload();
        } else {
            alert('❌ Error al enviar notificación: ' + (data.error || 'Desconocido'));
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar';
        }
    })
    .catch(error => {
        alert('❌ Error de conexión: ' + error);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar';
    });
}

function verHistorialCompleto(procesoId) {
    window.location.href = 'logs_correos.php?proceso_id=' + procesoId;
}

// Cerrar modal con Escape
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        cerrarModalNotificacion();
    }
});

// Cerrar modal al hacer clic fuera
document.getElementById('modalNotificacion').addEventListener('click', function(event) {
    if (event.target === this) {
        cerrarModalNotificacion();
    }
});
</script>

</body>
</html>