<?php
// logs_correos.php
session_start();

require_once 'includes/conexion.php';
require_once 'includes/auth.php';
require_once 'includes/notificaciones.php';

if (!estaLogueado()) {
    header('Location: login.php');
    exit;
}

$proceso_id = isset($_GET['proceso_id']) ? intval($_GET['proceso_id']) : 0;

if ($proceso_id <= 0) {
    header('Location: rastreabilidad.php');
    exit;
}

$historial = obtenerHistorialNotificaciones($conn, $proceso_id);

// Obtener datos del proceso para mostrar título
$stmt = $conn->prepare("
    SELECT p.title 
    FROM property_tracking pt
    JOIN properties p ON pt.property_id = p.id
    WHERE pt.id = ?
");
$stmt->execute([$proceso_id]);
$proceso = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Notificaciones | Inmobiliaria MH</title>
    <link rel="stylesheet" href="css/socios.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .logs-container {
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .logs-header {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .logs-table {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow-x: auto;
        }
        
        .logs-table table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .logs-table th {
            background: #f8fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #0f172a;
            border-bottom: 2px solid #e8edf4;
        }
        
        .logs-table td {
            padding: 12px;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .logs-table tr:hover {
            background: #f8fafc;
        }
        
        .badge-success {
            background: #dcfce7;
            color: #166534;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .btn-back {
            background: #3b82f6;
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-back:hover {
            background: #2563eb;
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="main-header">
            <div class="header-left">
                <h1>Historial de Notificaciones</h1>
                <p class="welcome">
                    <i class="fas fa-envelope"></i> 
                    Proceso: <?php echo htmlspecialchars($proceso['title'] ?? 'Sin título'); ?>
                </p>
            </div>
            <div class="header-actions">
                <a href="proceso_detalle.php?id=<?php echo $proceso_id; ?>" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Volver al Proceso
                </a>
            </div>
        </div>
        
        <div class="logs-container">
            <div class="logs-table">
                <h3 style="margin-bottom: 20px;">
                    <i class="fas fa-list"></i> Registro de Correos Enviados
                    <span style="font-size: 0.9rem; color: #64748b; font-weight: normal;">
                        (Total: <?php echo count($historial); ?>)
                    </span>
                </h3>
                
                <?php if (empty($historial)): ?>
                    <div style="text-align: center; padding: 40px; color: #94a3b8;">
                        <i class="fas fa-envelope-open" style="font-size: 3rem; display: block; margin-bottom: 15px;"></i>
                        <p>No hay notificaciones enviadas para este proceso</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Etapa</th>
                                <th>Asunto</th>
                                <th>Destinatario</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historial as $log): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y H:i:s', strtotime($log['fecha_envio'])); ?></td>
                                    <td>
                                        <span style="background: #dbeafe; color: #1e40af; padding: 2px 10px; border-radius: 12px; font-size: 0.8rem;">
                                            <?php echo ucfirst(str_replace('_', ' ', $log['etapa'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['asunto']); ?></td>
                                    <td><?php echo htmlspecialchars($log['email_cliente']); ?></td>
                                    <td>
                                        <?php if ($log['estado_envio'] == 'enviado'): ?>
                                            <span class="badge-success">
                                                <i class="fas fa-check"></i> Enviado
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-danger">
                                                <i class="fas fa-times"></i> Fallido
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>