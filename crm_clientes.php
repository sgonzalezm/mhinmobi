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

// Obtener clientes (si existe la tabla)
$clientes = [];
try {
    $stmt = $conn->prepare("
        SELECT c.*, 
               (SELECT COUNT(*) FROM ventas v WHERE v.cliente_id = c.id) as total_compras,
               (SELECT SUM(v.monto) FROM ventas v WHERE v.cliente_id = c.id) as total_gastado
        FROM clientes c
        WHERE c.socio_id = ?
        ORDER BY c.fecha_registro DESC
        LIMIT 20
    ");
    $stmt->execute([$_SESSION['usuario_id']]);
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $clientes = [];
}

// Estadísticas
$stats = [
    'total' => count($clientes),
    'activos' => 0,
    'inactivos' => 0,
    'leads' => 0
];

foreach ($clientes as $c) {
    if (isset($c['estado'])) {
        if ($c['estado'] === 'activo') $stats['activos']++;
        if ($c['estado'] === 'inactivo') $stats['inactivos']++;
        if ($c['estado'] === 'lead') $stats['leads']++;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/socios.css">
    <title>CRM Clientes | Inmobiliaria MH</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .cliente-contacto {
            display: flex;
            flex-direction: column;
            gap: 2px;
            font-size: 0.85rem;
            color: var(--gray);
        }
        .cliente-contacto i {
            width: 16px;
            color: var(--primary);
        }
        .cliente-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }
        .tag {
            padding: 2px 10px;
            background: #e9ecef;
            border-radius: 12px;
            font-size: 0.75rem;
            color: var(--gray);
        }
        .tag.preferred {
            background: #d4edda;
            color: #155724;
        }
        .tag.vip {
            background: #fff3cd;
            color: #856404;
        }
        .client-stats {
            font-size: 0.8rem;
            color: var(--gray);
            display: flex;
            gap: 10px;
        }
        .client-stats span {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .client-stats i {
            color: var(--primary);
        }
        @media (max-width: 768px) {
            .cliente-contacto {
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>

<!-- Overlay para móvil -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ===== SIDEBAR ===== -->
<?php include 'sidebar.php'; ?>

<!-- ===== MAIN CONTENT ===== -->
<main class="main-content">
    <div class="main-header">
        <div class="header-left">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <h1>CRM - Clientes</h1>
            <p class="welcome">
                <i class="fas fa-users"></i> Gestión de relaciones con clientes
            </p>
        </div>
        <div class="header-actions">
            <button class="btn-header primary" onclick="nuevoCliente()">
                <i class="fas fa-user-plus"></i> Nuevo Cliente
            </button>
            <button class="btn-header secondary">
                <i class="fas fa-upload"></i> Importar
            </button>
        </div>
    </div>

    <!-- Estadísticas -->
    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-icon"><i class="fas fa-users"></i></span>
            <div class="stat-number"><?php echo $stats['total']; ?></div>
            <div class="stat-label">Total Clientes</div>
        </div>
        <div class="stat-card success">
            <span class="stat-icon"><i class="fas fa-user-check"></i></span>
            <div class="stat-number"><?php echo $stats['activos']; ?></div>
            <div class="stat-label">Activos</div>
        </div>
        <div class="stat-card warning">
            <span class="stat-icon"><i class="fas fa-user-clock"></i></span>
            <div class="stat-number"><?php echo $stats['leads']; ?></div>
            <div class="stat-label">Leads</div>
        </div>
        <div class="stat-card danger">
            <span class="stat-icon"><i class="fas fa-user-slash"></i></span>
            <div class="stat-number"><?php echo $stats['inactivos']; ?></div>
            <div class="stat-label">Inactivos</div>
        </div>
    </div>

    <!-- Listado de clientes -->
    <div class="table-container">
        <div class="table-header">
            <h3><i class="fas fa-list"></i> Listado de Clientes</h3>
            <div class="search-box">
                <input type="text" placeholder="Buscar cliente..." id="searchTable">
                <select id="filterStatus">
                    <option value="">Todos</option>
                    <option value="activo">Activo</option>
                    <option value="lead">Lead</option>
                    <option value="inactivo">Inactivo</option>
                </select>
            </div>
        </div>

        <div class="table-responsive">
            <?php if (empty($clientes)): ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <h3>No hay clientes registrados</h3>
                    <p style="color: var(--gray);">Agrega tu primer cliente al CRM</p>
                    <button onclick="nuevoCliente()" class="btn-header primary" style="margin-top: 20px;">
                        <i class="fas fa-user-plus"></i> Agregar Cliente
                    </button>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>Contacto</th>
                            <th>Estado</th>
                            <th>Actividad</th>
                            <th>Etiquetas</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clientes as $cliente): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($cliente['nombre'] ?? 'N/A'); ?></strong>
                                    <div style="font-size: 0.8rem; color: var(--gray);">
                                        <?php echo htmlspecialchars($cliente['empresa'] ?? 'Particular'); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="cliente-contacto">
                                        <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($cliente['email'] ?? 'N/A'); ?></span>
                                        <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($cliente['telefono'] ?? 'N/A'); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $cliente['estado'] ?? 'lead'; ?>">
                                        <?php echo ucfirst($cliente['estado'] ?? 'Lead'); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="client-stats">
                                        <span><i class="fas fa-shopping-cart"></i> <?php echo $cliente['total_compras'] ?? 0; ?></span>
                                        <span><i class="fas fa-dollar-sign"></i> $<?php echo number_format($cliente['total_gastado'] ?? 0, 0, ',', '.'); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="cliente-tags">
                                        <?php if (($cliente['total_gastado'] ?? 0) > 100000): ?>
                                            <span class="tag vip">VIP</span>
                                        <?php endif; ?>
                                        <?php if (($cliente['total_compras'] ?? 0) > 3): ?>
                                            <span class="tag preferred">Preferente</span>
                                        <?php endif; ?>
                                        <span class="tag"><?php echo ucfirst($cliente['interes'] ?? 'General'); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <a href="#" class="action-btn view" onclick="verCliente(<?php echo $cliente['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="#" class="action-btn edit" onclick="editarCliente(<?php echo $cliente['id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button class="action-btn delete" onclick="eliminarCliente(<?php echo $cliente['id']; ?>)">
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

    document.querySelectorAll('.sidebar nav a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 992) {
                toggleSidebar();
            }
        });
    });

    // ===== Filtros =====
    document.getElementById('searchTable').addEventListener('keyup', function() {
        filtrarTabla();
    });

    document.getElementById('filterStatus').addEventListener('change', function() {
        filtrarTabla();
    });

    function filtrarTabla() {
        const searchText = document.getElementById('searchTable').value.toLowerCase();
        const filterStatus = document.getElementById('filterStatus').value.toLowerCase();
        const rows = document.querySelectorAll('table tbody tr');
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            const status = row.querySelector('.status-badge');
            const statusText = status ? status.textContent.toLowerCase() : '';
            
            let matchesSearch = text.includes(searchText);
            let matchesStatus = filterStatus === '' || statusText.includes(filterStatus);
            
            row.style.display = (matchesSearch && matchesStatus) ? '' : 'none';
        });
    }

    // ===== Funciones =====
    function nuevoCliente() {
        alert('Función: Agregar nuevo cliente al CRM');
    }

    function verCliente(id) {
        alert('Función: Ver detalles del cliente #' + id);
    }

    function editarCliente(id) {
        alert('Función: Editar cliente #' + id);
    }

    function eliminarCliente(id) {
        if (confirm('¿Estás seguro de que quieres eliminar este cliente?')) {
            alert('Función: Eliminar cliente #' + id);
        }
    }
</script>

</body>
</html>