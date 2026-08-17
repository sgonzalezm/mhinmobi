<?php
// proceso_nuevo.php
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

// Obtener propiedades disponibles para iniciar proceso
$propiedades = [];
$error_msg = '';

try {
    $stmt = $conn->prepare("
        SELECT p.id, p.title, p.address_municipality, p.address_state
        FROM properties p
        WHERE p.status = 'activo'
        AND p.id NOT IN (
            SELECT property_id FROM property_tracking WHERE status = 'activo'
        )
        ORDER BY p.created_at DESC
    ");
    $stmt->execute();
    $propiedades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($propiedades)) {
        $error_msg = "No hay propiedades disponibles para iniciar un proceso.";
    }
} catch (PDOException $e) {
    $error_msg = "Error al cargar propiedades: " . $e->getMessage();
}

// Definir las etapas del proceso
$etapas_proceso = [
    'inventario' => [
        'nombre' => 'Inventario',
        'descripcion' => 'Propiedad en inventario inicial',
        'orden' => 1,
        'icono' => 'fa-building'
    ],
    'contrato_compraventa' => [
        'nombre' => 'Contrato de Compraventa',
        'descripcion' => 'Generación de contrato de compraventa',
        'orden' => 2,
        'icono' => 'fa-file-contract'
    ],
    'poder_notarial' => [
        'nombre' => 'Poder Notarial',
        'descripcion' => 'Gestión de poder notarial',
        'orden' => 3,
        'icono' => 'fa-gavel'
    ],
    'credito' => [
        'nombre' => 'Crédito',
        'descripcion' => 'Gestión de crédito (Infonavit o bancario)',
        'orden' => 4,
        'icono' => 'fa-credit-card'
    ],
    'compra_venta' => [
        'nombre' => 'Compra-Venta',
        'descripcion' => 'Proceso de compra-venta del inmueble',
        'orden' => 5,
        'icono' => 'fa-handshake'
    ],
    'recepcion_recursos' => [
        'nombre' => 'Recepción de Recursos',
        'descripcion' => 'Recepción de recursos financieros',
        'orden' => 6,
        'icono' => 'fa-money-bill-wave'
    ],
    'pagos_proveedores' => [
        'nombre' => 'Pagos a Proveedores',
        'descripcion' => 'Pagos a proveedores y registro de ingresos',
        'orden' => 7,
        'icono' => 'fa-truck'
    ],
    'finalizado' => [
        'nombre' => 'Finalizado',
        'descripcion' => 'Proceso completado exitosamente',
        'orden' => 8,
        'icono' => 'fa-check-circle'
    ]
];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Nuevo Proceso | Inmobiliaria MH</title>
    <link rel="stylesheet" href="css/socios.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .process-form-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 30px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .process-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-label {
            display: block;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }
        
        .form-select, .form-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        
        .form-select:focus, .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .stages-preview {
            background: #f8fafc;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 25px;
        }
        
        .stage-preview-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px;
            border-bottom: 1px solid #e8edf4;
        }
        
        .stage-preview-item:last-child {
            border-bottom: none;
        }
        
        .stage-preview-icon {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: #e8edf4;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
        }
        
        .btn-submit {
            width: 100%;
            padding: 12px;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-submit:hover {
            background: #2563eb;
        }
        
        .btn-cancel {
            width: 100%;
            padding: 12px;
            background: #e8edf4;
            color: #0f172a;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 10px;
        }
        
        .btn-cancel:hover {
            background: #d1d5db;
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
            <h1>Iniciar Nuevo Proceso</h1>
            <p class="welcome">
                <i class="fas fa-plus-circle"></i> Crear un nuevo seguimiento para una propiedad
            </p>
        </div>
    </div>

    <div class="process-form-container">
        <div class="process-title">
            <i class="fas fa-route" style="margin-right: 10px;"></i>
            Nuevo Proceso de Seguimiento
        </div>
        
        <?php if (!empty($error_msg)): ?>
            <div style="padding: 15px; background: #fee2e2; color: #991b1b; border-radius: 8px; margin-bottom: 20px;">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?>
            </div>
        <?php else: ?>
            <form id="processForm" method="POST" action="proceso_guardar.php">
                <div class="form-group">
                    <label class="form-label">Seleccionar Propiedad</label>
                    <select class="form-select" name="property_id" required>
                        <option value="">-- Seleccionar propiedad --</option>
                        <?php foreach ($propiedades as $propiedad): ?>
                            <option value="<?php echo $propiedad['id']; ?>">
                                <?php echo htmlspecialchars($propiedad['title']); ?> - 
                                <?php echo htmlspecialchars($propiedad['address_municipality'] . ', ' . $propiedad['address_state']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Etapas del Proceso</label>
                    <div class="stages-preview">
                        <?php foreach ($etapas_proceso as $key => $etapa): ?>
                            <div class="stage-preview-item">
                                <div class="stage-preview-icon">
                                    <i class="fas <?php echo $etapa['icono']; ?>"></i>
                                </div>
                                <div>
                                    <div style="font-weight: 600; font-size: 0.9rem;">
                                        <?php echo $etapa['orden']; ?>. <?php echo $etapa['nombre']; ?>
                                    </div>
                                    <div style="font-size: 0.8rem; color: #64748b;">
                                        <?php echo $etapa['descripcion']; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Notas Iniciales (opcional)</label>
                    <textarea class="form-input" name="notas" rows="3" placeholder="Notas adicionales sobre el proceso..."></textarea>
                </div>
                
                <button type="submit" class="btn-submit">
                    <i class="fas fa-check"></i> Iniciar Proceso
                </button>
                <button type="button" class="btn-cancel" onclick="window.location.href='rastreabilidad.php'">
                    <i class="fas fa-times"></i> Cancelar
                </button>
            </form>
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
</script>

</body>
</html>