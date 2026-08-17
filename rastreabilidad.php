<?php
// rastreabilidad.php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// Obtener procesos activos
$procesos = [];
$error_msg = '';

try {
    $stmt = $conn->prepare("
        SELECT 
            pt.*,
            p.title as property_title,
            p.operation_type,
            p.address_municipality as municipality,
            p.address_state as state,
            f.asking_price as price,
            m.file_path as image_url,
            u.name as initiated_by_name,
            (SELECT COUNT(*) FROM tracking_stages ts WHERE ts.tracking_id = pt.id AND ts.status = 'completado') as stages_completed,
            (SELECT COUNT(*) FROM tracking_stages ts WHERE ts.tracking_id = pt.id) as total_stages
        FROM property_tracking pt
        JOIN properties p ON pt.property_id = p.id
        LEFT JOIN property_financials f ON p.id = f.property_id
        LEFT JOIN property_media m ON p.id = m.property_id AND m.is_primary = 1
        LEFT JOIN users u ON pt.initiated_by = u.id
        WHERE pt.status = 'activo'
        ORDER BY pt.updated_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $procesos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($procesos)) {
        $error_msg = "No hay procesos activos en el sistema.";
    }
} catch (PDOException $e) {
    $error_msg = "Error al cargar procesos: " . $e->getMessage();
    error_log("Error en rastreabilidad.php: " . $e->getMessage());
}

// Función para obtener el progreso del proceso
function getProcessProgress($completed, $total) {
    if ($total == 0) return 0;
    return round(($completed / $total) * 100);
}

// Función para obtener el color según la etapa
function getStageColor($stage) {
    $colors = [
        'inventario' => '#64748b',
        'contrato_compraventa' => '#3b82f6',
        'poder_notarial' => '#8b5cf6',
        'credito' => '#f59e0b',
        'compra_venta' => '#10b981',
        'recepcion_recursos' => '#06b6d4',
        'pagos_proveedores' => '#ef4444',
        'finalizado' => '#22c55e'
    ];
    return $colors[$stage] ?? '#64748b';
}

// Función para obtener el icono según la etapa
function getStageIcon($stage) {
    $icons = [
        'inventario' => 'fa-building',
        'contrato_compraventa' => 'fa-file-contract',
        'poder_notarial' => 'fa-gavel',
        'credito' => 'fa-credit-card',
        'compra_venta' => 'fa-handshake',
        'recepcion_recursos' => 'fa-money-bill-wave',
        'pagos_proveedores' => 'fa-truck',
        'finalizado' => 'fa-check-circle'
    ];
    return $icons[$stage] ?? 'fa-circle';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rastreabilidad de Procesos | Inmobiliaria MH</title>
    <link rel="stylesheet" href="css/socios.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* Estilos específicos para rastreabilidad */
        .tracking-container {
            padding: 20px;
        }
        
        .tracking-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .tracking-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #0f172a;
        }
        
        .tracking-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 20px;
        }
        
        .tracking-card {
            background: white;
            border: 1px solid #e8edf4;
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .tracking-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .tracking-card-header {
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .tracking-card-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .tracking-card-property {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-bottom: 15px;
        }
        
        .progress-bar-container {
            background: rgba(255,255,255,0.2);
            border-radius: 10px;
            height: 8px;
            overflow: hidden;
        }
        
        .progress-bar {
            height: 100%;
            background: #22c55e;
            border-radius: 10px;
            transition: width 0.3s ease;
        }
        
        .tracking-card-body {
            padding: 20px;
        }
        
        .stage-timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .stage-timeline::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e8edf4;
        }
        
        .stage-item {
            position: relative;
            margin-bottom: 15px;
            padding-left: 10px;
        }
        
        .stage-dot {
            position: absolute;
            left: -25px;
            top: 0;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 3px solid #e8edf4;
            background: white;
            z-index: 1;
        }
        
        .stage-dot.completed {
            background: #22c55e;
            border-color: #22c55e;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
        }
        
        .stage-dot.in-progress {
            border-color: #3b82f6;
            background: #3b82f6;
            animation: pulse 1.5s infinite;
        }
        
        .stage-name {
            font-size: 0.9rem;
            font-weight: 500;
            color: #0f172a;
            margin-bottom: 2px;
        }
        
        .stage-status {
            font-size: 0.75rem;
            color: #64748b;
        }
        
        .tracking-card-footer {
            padding: 15px 20px;
            background: #f8fafc;
            border-top: 1px solid #e8edf4;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .tracking-date {
            font-size: 0.8rem;
            color: #64748b;
        }
        
        .tracking-actions {
            display: flex;
            gap: 8px;
        }
        
        .btn-track {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-track.primary {
            background: #3b82f6;
            color: white;
        }
        
        .btn-track.primary:hover {
            background: #2563eb;
        }
        
        .btn-track.secondary {
            background: #e8edf4;
            color: #0f172a;
        }
        
        .btn-track.secondary:hover {
            background: #d1d5db;
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.5); }
            70% { box-shadow: 0 0 0 10px rgba(59, 130, 246, 0); }
            100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); }
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #cbd5e1;
            margin-bottom: 20px;
        }
        
        .empty-state h3 {
            font-size: 1.2rem;
            color: #0f172a;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: #64748b;
            margin-bottom: 20px;
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
            <h1>Rastreabilidad de Procesos</h1>
            <p class="welcome">
                <i class="fas fa-route"></i> Seguimiento de procesos inmobiliarios
            </p>
        </div>
        <div class="header-actions">
            <button class="btn-header primary" onclick="iniciarProceso()">
                <i class="fas fa-plus"></i> Iniciar Proceso
            </button>
        </div>
    </div>

    <div class="tracking-container">
        <?php if (!empty($error_msg)): ?>
            <div class="empty-state">
                <i class="fas fa-route"></i>
                <h3><?php echo htmlspecialchars($error_msg); ?></h3>
                <p>Comienza un nuevo proceso de seguimiento para una propiedad</p>
                <button class="btn-header primary" onclick="iniciarProceso()">
                    <i class="fas fa-plus"></i> Iniciar Proceso
                </button>
            </div>
        <?php else: ?>
            <div class="tracking-grid">
                <?php foreach ($procesos as $proceso): 
                    $progress = getProcessProgress($proceso['stages_completed'], $proceso['total_stages']);
                    $currentStage = $proceso['current_stage'];
                    $stageIcon = getStageIcon($currentStage);
                    $stageColor = getStageColor($currentStage);
                ?>
                    <div class="tracking-card" onclick="verProceso(<?php echo $proceso['id']; ?>)">
                        <div class="tracking-card-header">
                            <div class="tracking-card-title">
                                <?php echo htmlspecialchars($proceso['property_title']); ?>
                            </div>
                            <div class="tracking-card-property">
                                <i class="fas fa-map-marker-alt"></i> 
                                <?php echo htmlspecialchars($proceso['municipality'] . ', ' . $proceso['state']); ?>
                            </div>
                            <div class="progress-bar-container">
                                <div class="progress-bar" style="width: <?php echo $progress; ?>%"></div>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-top: 8px; font-size: 0.8rem;">
                                <span><?php echo $progress; ?>% completado</span>
                                <span><?php echo $proceso['stages_completed']; ?>/<?php echo $proceso['total_stages']; ?> etapas</span>
                            </div>
                        </div>
                        
                        <div class="tracking-card-body">
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 20px;">
                                <i class="fas <?php echo $stageIcon; ?>" style="color: <?php echo $stageColor; ?>; font-size: 1.2rem;"></i>
                                <div>
                                    <div style="font-size: 0.9rem; font-weight: 600;">Etapa actual</div>
                                    <div style="font-size: 0.8rem; color: #64748b;">
                                        <?php echo ucfirst(str_replace('_', ' ', $currentStage)); ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="stage-timeline">
                                <?php
                                // Obtener etapas del proceso
                                $stmtStages = $conn->prepare("
                                    SELECT stage_name, status, completed_at
                                    FROM tracking_stages
                                    WHERE tracking_id = ?
                                    ORDER BY stage_order
                                ");
                                $stmtStages->execute([$proceso['id']]);
                                $stages = $stmtStages->fetchAll(PDO::FETCH_ASSOC);
                                
                                foreach ($stages as $stage):
                                    $isCompleted = $stage['status'] == 'completado';
                                    $isInProgress = $stage['status'] == 'en_progreso';
                                    $stageDotClass = $isCompleted ? 'completed' : ($isInProgress ? 'in-progress' : '');
                                    $stageIconClass = getStageIcon($stage['stage_name']);
                                ?>
                                    <div class="stage-item">
                                        <div class="stage-dot <?php echo $stageDotClass; ?>">
                                            <?php if ($isCompleted): ?>
                                                <i class="fas fa-check"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="stage-name">
                                            <i class="fas <?php echo $stageIconClass; ?>" style="margin-right: 5px; font-size: 0.8rem;"></i>
                                            <?php echo ucfirst(str_replace('_', ' ', $stage['stage_name'])); ?>
                                        </div>
                                        <div class="stage-status">
                                            <?php 
                                            if ($isCompleted) {
                                                echo 'Completado el ' . date('d/m/Y', strtotime($stage['completed_at']));
                                            } elseif ($isInProgress) {
                                                echo 'En progreso';
                                            } else {
                                                echo 'Pendiente';
                                            }
                                            ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="tracking-card-footer">
                            <div class="tracking-date">
                                <i class="fas fa-calendar"></i> 
                                Iniciado: <?php echo date('d/m/Y', strtotime($proceso['initiated_at'])); ?>
                            </div>
                            <div class="tracking-actions">
                                <button class="btn-track secondary" onclick="event.stopPropagation(); verDetalles(<?php echo $proceso['id']; ?>)">
                                    <i class="fas fa-info-circle"></i> Detalles
                                </button>
                                <button class="btn-track primary" onclick="event.stopPropagation(); actualizarProceso(<?php echo $proceso['id']; ?>)">
                                    <i class="fas fa-arrow-right"></i> Avanzar
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

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

function iniciarProceso() {
    window.location.href = 'proceso_nuevo.php';
}

function verProceso(id) {
    window.location.href = 'proceso_detalle.php?id=' + id;
}

function verDetalles(id) {
    window.location.href = 'proceso_detalle.php?id=' + id;
}

function actualizarProceso(id) {
    window.location.href = 'proceso_avanzar.php?id=' + id;
}
</script>

</body>
</html>