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

// Obtener propiedades del socio (si existe la tabla)
$propiedades = [];
try {
    $stmt = $conn->prepare("
        SELECT * FROM propiedades 
        WHERE socio_id = ? 
        ORDER BY fecha_creacion DESC 
        LIMIT 10
    ");
    $stmt->execute([$_SESSION['usuario_id']]);
    $propiedades = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Si no existe la tabla, mostrar propiedades de ejemplo
    $propiedades = [];
}

// Estadísticas
$stats = [
    'total' => count($propiedades),
    'activas' => 0,
    'vendidas' => 0,
    'destacadas' => 0
];

foreach ($propiedades as $p) {
    if (isset($p['estado'])) {
        if ($p['estado'] === 'activa') $stats['activas']++;
        if ($p['estado'] === 'vendida') $stats['vendidas']++;
        if ($p['estado'] === 'destacada') $stats['destacadas']++;
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
</head>
<body>

<!-- Overlay para móvil -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ===== SIDEBAR ===== -->
<aside class="sidebar" id="sidebar">
    <a href="index.php" class="logo">
        <i class="fas fa-building"></i>
        <span>INMOBILIARIA MH</span>
    </a>

    <div class="user-info">
        <div class="avatar">
            <?php echo strtoupper(substr($usuario['nombre'] ?? 'U', 0, 1)); ?>
        </div>
        <div class="name"><?php echo htmlspecialchars($usuario['nombre'] ?? 'Usuario'); ?></div>
        <div class="role">
            <i class="fas fa-user-tag"></i>
            <?php echo ($usuario['activo'] ?? 1) ? 'Activo' : 'Inactivo'; ?>
        </div>
    </div>

    <nav>
        <a href="socios_panel.php" class="active">
            <i class="fas fa-home"></i> Inicio
        </a>
        <a href="mis_propiedades.php">
            <i class="fas fa-building"></i> Mis Propiedades
            <span class="badge"><?php echo $stats['total']; ?></span>
        </a>
        <a href="vender.php">
            <i class="fas fa-plus-circle"></i> Publicar Propiedad
        </a>
        <a href="inventario_maestro.php">
            <i class="fas fa-warehouse"></i> Inventario Maestro
        </a>
        <a href="expediente_legal.php">
            <i class="fas fa-file-alt"></i> Expediente Legal
        </a>
        <a href="crm_clientes.php">
            <i class="fas fa-users"></i> CRM Clientes
        </a>
        <a href="inteligencia_negocios.php">
            <i class="fas fa-chart-line"></i> Inteligencia de Negocios
        </a>
        <a href="mensajes.php">
            <i class="fas fa-envelope"></i> Mensajes
            <span class="badge">3</span>
        </a>
        <a href="accesos.php">
            <i class="fas fa-user-cog"></i> Configuracion de acceso
        </a>
    </nav>

    <div class="logout-section">
        <a href="logout.php">
            <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
        </a>
    </div>
</aside>

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
            </p>
        </div>
        <div class="header-actions">
            <a href="vender.php" class="btn-header primary">
                <i class="fas fa-plus-circle"></i> Publicar Propiedad
            </a>
        </div>
    </div>

    <!-- Estadísticas -->
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
    </div>

    <!-- Tabla de Propiedades -->
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

    // Cerrar sidebar al hacer clic en un enlace (móvil)
    document.querySelectorAll('.sidebar nav a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 992) {
                toggleSidebar();
            }
        });
    });

    // ===== Buscar en tabla =====
    document.getElementById('searchTable').addEventListener('keyup', function() {
        const searchText = this.value.toLowerCase();
        const rows = document.querySelectorAll('table tbody tr');
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(searchText) ? '' : 'none';
        });
    });

    // ===== Eliminar propiedad (confirmación) =====
    function eliminarPropiedad(id) {
        if (confirm('¿Estás seguro de que quieres eliminar esta propiedad? Esta acción no se puede deshacer.')) {
            window.location.href = 'eliminar_propiedad.php?id=' + id;
        }
    }

    // ===== Cerrar sesión con confirmación (opcional) =====
    document.querySelector('.logout-section a').addEventListener('click', function(e) {
        if (!confirm('¿Seguro que quieres cerrar sesión?')) {
            e.preventDefault();
        }
    });
</script>

</body>
</html>