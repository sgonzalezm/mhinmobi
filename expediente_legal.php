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

// Obtener expedientes (si existe la tabla)
$expedientes = [];
try {
    $stmt = $conn->prepare("
        SELECT e.*, p.titulo as propiedad_titulo 
        FROM expedientes e
        LEFT JOIN propiedades p ON e.propiedad_id = p.id
        WHERE e.socio_id = ?
        ORDER BY e.fecha_creacion DESC
        LIMIT 20
    ");
    $stmt->execute([$_SESSION['usuario_id']]);
    $expedientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $expedientes = [];
}

// Estadísticas
$stats = [
    'total' => count($expedientes),
    'completos' => 0,
    'incompletos' => 0,
    'archivados' => 0
];

foreach ($expedientes as $exp) {
    if (isset($exp['estado'])) {
        if ($exp['estado'] === 'completo') $stats['completos']++;
        if ($exp['estado'] === 'incompleto') $stats['incompletos']++;
        if ($exp['estado'] === 'archivado') $stats['archivados']++;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/socios.css">
    <title>Expediente Legal | Inmobiliaria MH</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .expediente-doc {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 8px;
            flex-wrap: wrap;
        }
        .doc-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            background: #f1f3f5;
            border-radius: 20px;
            font-size: 0.8rem;
            color: var(--gray);
        }
        .doc-badge.present {
            background: #d4edda;
            color: #155724;
        }
        .doc-badge.missing {
            background: #f8d7da;
            color: #721c24;
        }
        .doc-badge i {
            font-size: 0.8rem;
        }
        .progress-bar {
            width: 100%;
            height: 6px;
            background: #e9ecef;
            border-radius: 3px;
            margin-top: 8px;
            overflow: hidden;
        }
        .progress-bar .fill {
            height: 100%;
            background: var(--primary);
            border-radius: 3px;
            transition: width 0.3s ease;
        }
        .completion-text {
            font-size: 0.8rem;
            color: var(--gray);
            margin-top: 4px;
        }
        @media (max-width: 768px) {
            .expediente-doc {
                flex-direction: column;
                align-items: flex-start;
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
            <h1>Expediente Legal</h1>
            <p class="welcome">
                <i class="fas fa-file-alt"></i> Gestión documental y legal
            </p>
        </div>
        <div class="header-actions">
            <button class="btn-header primary" onclick="nuevoExpediente()">
                <i class="fas fa-plus-circle"></i> Nuevo Expediente
            </button>
        </div>
    </div>

    <!-- Estadísticas -->
    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-icon"><i class="fas fa-folder-open"></i></span>
            <div class="stat-number"><?php echo $stats['total']; ?></div>
            <div class="stat-label">Total Expedientes</div>
        </div>
        <div class="stat-card success">
            <span class="stat-icon"><i class="fas fa-check-circle"></i></span>
            <div class="stat-number"><?php echo $stats['completos']; ?></div>
            <div class="stat-label">Completos</div>
        </div>
        <div class="stat-card warning">
            <span class="stat-icon"><i class="fas fa-hourglass-half"></i></span>
            <div class="stat-number"><?php echo $stats['incompletos']; ?></div>
            <div class="stat-label">Incompletos</div>
        </div>
        <div class="stat-card info">
            <span class="stat-icon"><i class="fas fa-archive"></i></span>
            <div class="stat-number"><?php echo $stats['archivados']; ?></div>
            <div class="stat-label">Archivados</div>
        </div>
    </div>

    <!-- Listado de expedientes -->
    <div class="table-container">
        <div class="table-header">
            <h3><i class="fas fa-list"></i> Expedientes</h3>
            <div class="search-box">
                <input type="text" placeholder="Buscar expediente..." id="searchTable">
                <select id="filterStatus">
                    <option value="">Todos</option>
                    <option value="completo">Completo</option>
                    <option value="incompleto">Incompleto</option>
                    <option value="archivado">Archivado</option>
                </select>
            </div>
        </div>

        <div class="table-responsive">
            <?php if (empty($expedientes)): ?>
                <div class="empty-state">
                    <i class="fas fa-file-alt"></i>
                    <h3>No hay expedientes</h3>
                    <p style="color: var(--gray);">Crea un nuevo expediente para comenzar</p>
                    <button onclick="nuevoExpediente()" class="btn-header primary" style="margin-top: 20px;">
                        <i class="fas fa-plus-circle"></i> Nuevo Expediente
                    </button>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Expediente</th>
                            <th>Propiedad</th>
                            <th>Estado</th>
                            <th>Documentación</th>
                            <th>Progreso</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expedientes as $exp): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($exp['numero_expediente'] ?? 'N/A'); ?></strong>
                                    <div style="font-size: 0.8rem; color: var(--gray);">
                                        Creado: <?php echo date('d/m/Y', strtotime($exp['fecha_creacion'] ?? 'now')); ?>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($exp['propiedad_titulo'] ?? 'N/A'); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $exp['estado'] ?? 'incompleto'; ?>">
                                        <?php echo ucfirst($exp['estado'] ?? 'Incompleto'); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="expediente-doc">
                                        <span class="doc-badge present">
                                            <i class="fas fa-check-circle"></i> 5/8
                                        </span>
                                        <span class="doc-badge missing">
                                            <i class="fas fa-times-circle"></i> 3 faltantes
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div style="width: 120px;">
                                        <div class="progress-bar">
                                            <div class="fill" style="width: 62%;"></div>
                                        </div>
                                        <div class="completion-text">62% completo</div>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <a href="#" class="action-btn view" onclick="verExpediente(<?php echo $exp['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="#" class="action-btn edit" onclick="editarExpediente(<?php echo $exp['id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button class="action-btn delete" onclick="eliminarExpediente(<?php echo $exp['id']; ?>)">
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
    function nuevoExpediente() {
        alert('Función: Crear nuevo expediente legal');
    }

    function verExpediente(id) {
        alert('Función: Ver detalles del expediente #' + id);
    }

    function editarExpediente(id) {
        alert('Función: Editar expediente #' + id);
    }

    function eliminarExpediente(id) {
        if (confirm('¿Estás seguro de que quieres eliminar este expediente?')) {
            alert('Función: Eliminar expediente #' + id);
        }
    }
</script>

</body>
</html>