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

// Función para sanitizar (igual que en tu otro archivo)
function sanitizar($texto) {
    return htmlspecialchars(trim($texto ?? ''));
}

// Obtener estadísticas rápidas
$stats = [];
try {
    $stmt = $conn->query("
        SELECT 
            (SELECT COUNT(*) FROM users WHERE role = 'asesor') as asesores,
            (SELECT COUNT(*) FROM property_tracking WHERE status = 'completado') as completadas,
            (SELECT COUNT(*) FROM property_tracking WHERE status != 'completado') as en_proceso,
            (SELECT COUNT(*) FROM users WHERE role = 'cliente') as clientes
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$stats) {
        $stats = ['asesores' => 0, 'completadas' => 0, 'en_proceso' => 0, 'clientes' => 0];
    }
} catch (PDOException $e) {
    $stats = ['asesores' => 0, 'completadas' => 0, 'en_proceso' => 0, 'clientes' => 0];
    error_log("Error en estadísticas de administración: " . $e->getMessage());
}

// Función para formatear moneda (igual que en tu sistema)
function formatearMoneda($monto) {
    if ($monto === null || $monto === '') {
        return '$0';
    }
    return '$' . number_format(floatval($monto), 0, ',', '.');
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
        /* ===== ESTILOS CORPORATIVOS (igual que tu sistema) ===== */
        * {
            box-sizing: border-box;
        }

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

        /* ===== WELCOME CARD ===== */
        .welcome-card {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: white;
            padding: 30px 35px;
            border-radius: 12px;
            margin-bottom: 30px;
            border: 1px solid #334155;
        }

        .welcome-card h2 {
            font-size: 1.5rem;
            margin: 0 0 5px 0;
        }

        .welcome-card p {
            opacity: 0.7;
            font-size: 0.95rem;
            margin: 0 0 20px 0;
        }

        .stats-mini {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .stats-mini .stat-item {
            background: rgba(255,255,255,0.08);
            padding: 10px 20px;
            border-radius: 8px;
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255,255,255,0.05);
        }

        .stats-mini .stat-item span {
            display: block;
            font-size: 0.7rem;
            opacity: 0.6;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stats-mini .stat-item strong {
            font-size: 1.4rem;
            font-weight: 700;
        }

        /* ===== GRID DE MÓDULOS ===== */
        .modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .module-card {
            background: white;
            border: 1px solid #e8edf4;
            border-radius: 12px;
            padding: 25px;
            text-decoration: none;
            color: #0f172a;
            transition: all 0.2s ease;
            position: relative;
            display: block;
        }

        .module-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.08);
            border-color: #10b981;
        }

        .module-card .module-icon {
            font-size: 2rem;
            margin-bottom: 12px;
            display: inline-block;
        }

        .module-card .module-icon.comisiones { color: #10b981; }
        .module-card .module-icon.leaderboard { color: #f59e0b; }
        .module-card .module-icon.propiedades { color: #3b82f6; }
        .module-card .module-icon.clientes { color: #8b5cf6; }
        .module-card .module-icon.reportes { color: #ef4444; }
        .module-card .module-icon.config { color: #6b7280; }

        .module-card h3 {
            font-size: 1.05rem;
            margin: 0 0 8px 0;
        }

        .module-card p {
            font-size: 0.85rem;
            color: #64748b;
            margin: 0 0 12px 0;
            line-height: 1.5;
        }

        .module-card .module-badge {
            display: inline-block;
            background: #f1f5f9;
            padding: 2px 12px;
            border-radius: 12px;
            font-size: 0.7rem;
            color: #64748b;
        }

        .module-card .module-arrow {
            position: absolute;
            right: 20px;
            bottom: 20px;
            color: #d1d5db;
            font-size: 1.2rem;
            transition: all 0.2s;
        }

        .module-card:hover .module-arrow {
            color: #10b981;
            transform: translateX(4px);
        }

        /* ===== ACCESO RÁPIDO ===== */
        .quick-access {
            background: white;
            border: 1px solid #e8edf4;
            border-radius: 12px;
            padding: 20px 25px;
        }

        .quick-access h3 {
            font-size: 1rem;
            margin: 0 0 15px 0;
            color: #0f172a;
        }

        .quick-access h3 i {
            color: #10b981;
            margin-right: 8px;
        }

        .quick-links {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .quick-links a {
            background: #f8fafc;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            color: #0f172a;
            font-size: 0.85rem;
            border: 1px solid #e8edf4;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .quick-links a:hover {
            background: #f1f5f9;
            border-color: #10b981;
            color: #10b981;
        }

        .quick-links a i {
            font-size: 0.8rem;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .admin-container {
                padding: 15px;
            }

            .admin-header-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .admin-user-info {
                justify-content: center;
                padding: 8px 12px;
            }

            .welcome-card {
                padding: 20px;
            }

            .welcome-card h2 {
                font-size: 1.2rem;
            }

            .stats-mini {
                gap: 10px;
            }

            .stats-mini .stat-item {
                padding: 8px 14px;
            }

            .stats-mini .stat-item strong {
                font-size: 1.1rem;
            }

            .modules-grid {
                grid-template-columns: 1fr;
            }

            .quick-links a {
                font-size: 0.8rem;
                padding: 6px 12px;
            }
        }

        @media (max-width: 480px) {
            .admin-user-info .admin-name {
                font-size: 0.8rem;
            }

            .admin-user-info .admin-role {
                font-size: 0.6rem;
                padding: 1px 8px;
            }

            .btn-logout {
                font-size: 0.75rem;
                padding: 4px 10px;
            }
        }
    </style>
</head>
<body>

<!-- SIDEBAR (tu sidebar existente) -->
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

        <!-- WELCOME CARD -->
        <div class="welcome-card">
            <h2>¡Bienvenido, <?php echo sanitizar($usuario['name'] ?? 'Administrador'); ?>!</h2>
            
            <div class="stats-mini">
                <div class="stat-item">
                    <span>👨‍💼 Asesores</span>
                    <strong><?php echo $stats['asesores'] ?? 0; ?></strong>
                </div>
            </div>
        </div>

        <!-- MÓDULOS -->
        <div class="modules-grid">
            <a href="comisiones.php" class="module-card">
                <div class="module-icon comisiones"><i class="fas fa-dollar-sign"></i></div>
                <h3>💰 Comisiones</h3>
                <p>Gestiona las comisiones de los asesores, proyecciones y reportes financieros.</p>
                <span class="module-badge"><i class="fas fa-chart-line"></i> Proyecciones incluidas</span>
                <span class="module-arrow"><i class="fas fa-chevron-right"></i></span>
            </a>

            <a href="leaderboard.php" class="module-card">
                <div class="module-icon leaderboard"><i class="fas fa-trophy"></i></div>
                <h3>🏆 Leaderboard</h3>
                <p>Ranking de asesores por rendimiento, comisiones y eficiencia.</p>
                <span class="module-badge"><i class="fas fa-medal"></i> Top 10 + insignias</span>
                <span class="module-arrow"><i class="fas fa-chevron-right"></i></span>
            </a>

            <a href="analiticos.php" class="module-card">
                <div class="module-icon reportes"><i class="fas fa-file-alt"></i></div>
                <h3>📊 Analíticos</h3>
                <p>Análisis y estadísticas del negocio.</p>
                <span class="module-badge"><i class="fas fa-print"></i> Exportables a Excel</span>
                <span class="module-arrow"><i class="fas fa-chevron-right"></i></span>
            </a>
        </div>
        <?php include '../modulos/accesos_rapidos.php'; ?>
    </div>
</main>

<script>
// ===== MENÚ MÓVIL (igual que en tu sistema) =====
document.addEventListener('DOMContentLoaded', function() {
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

    // Actualizar estadísticas cada 30 segundos
    setInterval(() => {
        fetch('ajax_dashboard.php')
            .then(response => response.json())
            .then(data => {
                const items = document.querySelectorAll('.stats-mini .stat-item strong');
                const keys = ['asesores', 'completadas', 'en_proceso', 'clientes'];
                items.forEach((el, i) => {
                    if (data[keys[i]] !== undefined) {
                        el.textContent = data[keys[i]];
                    }
                });
            })
            .catch(() => {});
    }, 30000);
});
</script>
</body>
</html>