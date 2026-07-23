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

// Datos de ejemplo para estadísticas
$datos = [
    'ventas_mensuales' => [
        'labels' => ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun'],
        'valores' => [12000, 15000, 18000, 22000, 19000, 25000]
    ],
    'propiedades_por_tipo' => [
        'Casa' => 45,
        'Departamento' => 30,
        'Terreno' => 15,
        'Local' => 8,
        'Oficina' => 10
    ],
    'rendimiento' => [
        'total_ventas' => 250000,
        'comisiones' => 12500,
        'propiedades_vendidas' => 12,
        'clientes_nuevos' => 8
    ]
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/socios.css">
    <title>Inteligencia de Negocios | Inmobiliaria MH</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .bi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .bi-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .bi-card .bi-title {
            font-size: 0.9rem;
            color: var(--gray);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .bi-card .bi-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
        }
        .bi-card .bi-change {
            font-size: 0.85rem;
            margin-top: 8px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .bi-card .bi-change.up {
            color: #28a745;
        }
        .bi-card .bi-change.down {
            color: #dc3545;
        }
        .bi-card .bi-icon {
            font-size: 2rem;
            color: var(--primary);
            opacity: 0.3;
        }
        .chart-placeholder {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            color: var(--gray);
            min-height: 200px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .chart-placeholder i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: var(--primary);
            opacity: 0.5;
        }
        .chart-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }
        @media (max-width: 992px) {
            .chart-grid {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 768px) {
            .bi-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        @media (max-width: 480px) {
            .bi-grid {
                grid-template-columns: 1fr;
            }
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
            <h1>Inteligencia de Negocios</h1>
            <p class="welcome">
                <i class="fas fa-chart-line"></i> Análisis y métricas de tu negocio
            </p>
        </div>
        <div class="header-actions">
            <button class="btn-header secondary" onclick="exportarReporte()">
                <i class="fas fa-file-pdf"></i> Exportar Reporte
            </button>
            <button class="btn-header secondary">
                <i class="fas fa-calendar-alt"></i> Últimos 6 meses
            </button>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="bi-grid">
        <div class="bi-card">
            <div class="bi-title"><i class="fas fa-dollar-sign"></i> Total Ventas</div>
            <div class="bi-value">$<?php echo number_format($datos['rendimiento']['total_ventas'], 0, ',', '.'); ?></div>
            <div class="bi-change up"><i class="fas fa-arrow-up"></i> 12.5% vs mes anterior</div>
        </div>
        <div class="bi-card">
            <div class="bi-title"><i class="fas fa-hand-holding-usd"></i> Comisiones Generadas</div>
            <div class="bi-value">$<?php echo number_format($datos['rendimiento']['comisiones'], 0, ',', '.'); ?></div>
            <div class="bi-change up"><i class="fas fa-arrow-up"></i> 8.3% vs mes anterior</div>
        </div>
        <div class="bi-card">
            <div class="bi-title"><i class="fas fa-home"></i> Propiedades Vendidas</div>
            <div class="bi-value"><?php echo $datos['rendimiento']['propiedades_vendidas']; ?></div>
            <div class="bi-change up"><i class="fas fa-arrow-up"></i> 3 más que el mes pasado</div>
        </div>
        <div class="bi-card">
            <div class="bi-title"><i class="fas fa-user-plus"></i> Nuevos Clientes</div>
            <div class="bi-value"><?php echo $datos['rendimiento']['clientes_nuevos']; ?></div>
            <div class="bi-change up"><i class="fas fa-arrow-up"></i> 2 más que el mes pasado</div>
        </div>
    </div>

    <!-- Gráficos -->
    <div class="chart-grid">
        <div class="bi-card">
            <div class="bi-title"><i class="fas fa-chart-bar"></i> Ventas Mensuales</div>
            <div class="chart-placeholder">
                <i class="fas fa-chart-bar"></i>
                <p>Gráfico de ventas mensuales</p>
                <div style="display: flex; gap: 15px; font-size: 0.8rem; color: var(--primary);">
                    <?php foreach ($datos['ventas_mensuales']['labels'] as $i => $label): ?>
                        <div><strong><?php echo $label; ?></strong>: $<?php echo number_format($datos['ventas_mensuales']['valores'][$i] ?? 0, 0, ',', '.'); ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="bi-card">
            <div class="bi-title"><i class="fas fa-chart-pie"></i> Propiedades por Tipo</div>
            <div class="chart-placeholder">
                <i class="fas fa-chart-pie"></i>
                <p>Distribución por tipo de propiedad</p>
                <div style="text-align: left; font-size: 0.9rem;">
                    <?php foreach ($datos['propiedades_por_tipo'] as $tipo => $cantidad): ?>
                        <div style="margin: 4px 0;">
                            <span style="display: inline-block; width: 12px; height: 12px; background: <?php echo 'hsl(' . rand(200, 250) . ', 70%, 50%)'; ?>; border-radius: 2px;"></span>
                            <?php echo $tipo; ?>: <strong><?php echo $cantidad; ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla de rendimiento -->
    <div class="table-container">
        <div class="table-header">
            <h3><i class="fas fa-list"></i> Rendimiento por Propiedad</h3>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Propiedad</th>
                        <th>Precio</th>
                        <th>Comisión</th>
                        <th>Fecha Venta</th>
                        <th>Cliente</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Villa Residencial</strong></td>
                        <td>$350,000</td>
                        <td>$17,500</td>
                        <td>15/06/2024</td>
                        <td>Juan Pérez</td>
                        <td><span class="status-badge completado">Completado</span></td>
                    </tr>
                    <tr>
                        <td><strong>Departamento Centro</strong></td>
                        <td>$180,000</td>
                        <td>$9,000</td>
                        <td>10/06/2024</td>
                        <td>María González</td>
                        <td><span class="status-badge completado">Completado</span></td>
                    </tr>
                    <tr>
                        <td><strong>Casa Jardín</strong></td>
                        <td>$420,000</td>
                        <td>$21,000</td>
                        <td>05/06/2024</td>
                        <td>Carlos Rodríguez</td>
                        <td><span class="status-badge pendiente">Pendiente</span></td>
                    </tr>
                    <tr>
                        <td><strong>Oficina Corporativa</strong></td>
                        <td>$280,000</td>
                        <td>$14,000</td>
                        <td>28/05/2024</td>
                        <td>Ana Martínez</td>
                        <td><span class="status-badge completado">Completado</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</main>

<script>
    // ===== Menú móvil =====
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
            if (window.innerWidth <= 992) {
                toggleSidebar();
            }
        });
    });

    // ===== Funciones =====
    function exportarReporte() {
        alert('Función: Exportar reporte en PDF');
    }
</script>

</body>
</html>