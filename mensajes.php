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

// ===== EVALUACIONES DE PROPIEDADES (bandeja principal) =====
$mensajes = [];
try {
    $stmt = $conn->prepare("
        SELECT 
            id,
            nombre         AS remitente,
            email,
            telefono,
            tipo_propiedad AS asunto,
            ciudad,
            precio_libre_gastos,
            urgencia_venta,
            comentarios    AS mensaje,
            fecha_registro AS fecha_envio
        FROM evaluaciones_propiedades
        ORDER BY fecha_registro DESC
        LIMIT 50
    ");
    $stmt->execute();
    $mensajes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Si no existe la tabla, dejar array vacío
}

// Estadísticas
$stats = [
    'total'     => count($mensajes),
    'no_leidos' => 0,
    'enviados'  => 0,
    'recibidos' => count($mensajes),
];

// Contar urgentes como "no leídos"
foreach ($mensajes as $m) {
    if (in_array($m['urgencia_venta'], ['inmediata', 'alta'])) {
        $stats['no_leidos']++;
    }
}

// ===== CONTACTOS NUEVOS (solo admin) =====
$contactos_nuevos = 0;
$total_contactos = 0;
$leads_nuevos = 0;
if (esAdmin()) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM contactos WHERE status = 'nuevo'");
        $stmt->execute();
        $contactos_nuevos = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM contactos");
        $stmt->execute();
        $total_contactos = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    } catch (PDOException $e) {}

    try {
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM evaluaciones_propiedades");
        $stmt->execute();
        $leads_nuevos = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    } catch (PDOException $e) {}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/socios.css">
    <title>Mensajes | Inmobiliaria MH</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .mensaje-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            border-bottom: 1px solid #f1f3f5;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .mensaje-item:hover { background: #f8f9fa; }
        .mensaje-item.unread {
            background: #e8f4fd;
            border-left: 3px solid var(--primary);
        }
        .mensaje-item .avatar-small {
            width: 40px; height: 40px; border-radius: 50%;
            background: var(--primary); color: white;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 1rem; flex-shrink: 0;
        }
        .mensaje-item .mensaje-info { flex: 1; min-width: 0; }
        .mensaje-item .mensaje-info .asunto {
            font-weight: 600; color: var(--dark); margin-bottom: 3px;
        }
        .mensaje-item .mensaje-info .preview {
            font-size: 0.9rem; color: var(--gray);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .mensaje-item .mensaje-meta { text-align: right; flex-shrink: 0; }
        .mensaje-item .mensaje-meta .fecha { font-size: 0.8rem; color: var(--gray); }
        .mensaje-item .mensaje-meta .estado {
            font-size: 0.75rem; padding: 2px 10px; border-radius: 12px;
            margin-top: 4px; display: inline-block;
        }
        .mensaje-item .mensaje-meta .estado.inmediata { background: #f8d7da; color: #721c24; }
        .mensaje-item .mensaje-meta .estado.alta      { background: #ffe5d0; color: #8a4b08; }
        .mensaje-item .mensaje-meta .estado.media     { background: #fff3cd; color: #856404; }
        .mensaje-item .mensaje-meta .estado.baja      { background: #d4edda; color: #155724; }
        .mensaje-item .mensaje-meta .estado.solo_informacion { background: #e2e3e5; color: #383d41; }

        .badge-unread {
            background: #dc3545; color: white; border-radius: 50%;
            padding: 2px 8px; font-size: 0.75rem; font-weight: 700;
        }
        .mensaje-actions { display: flex; gap: 8px; }
        .mensaje-actions button {
            background: none; border: none; color: var(--gray);
            cursor: pointer; padding: 4px 8px; border-radius: 4px;
            transition: all 0.3s ease;
        }
        .mensaje-actions button:hover { background: #e9ecef; color: var(--dark); }
        .mensaje-actions button.danger:hover { background: #f8d7da; color: #dc3545; }

        .btn-header .badge-contacts {
            position: absolute; top: -8px; right: -8px;
            background: #dc3545; color: #fff; border-radius: 50%;
            padding: 2px 8px; font-size: 0.7rem; font-weight: 700; line-height: 1.4;
        }
        .stat-card.contactos {
            border-left: 4px solid #6f42c1; cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card.contactos:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 20px rgba(111, 66, 193, 0.15);
        }

        @media (max-width: 768px) {
            .mensaje-item { flex-wrap: wrap; }
            .mensaje-item .mensaje-meta {
                width: 100%; text-align: left;
                display: flex; gap: 10px; align-items: center;
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php include 'modulos/sidebar.php'; ?>

<main class="main-content">
    <div class="main-header">
        <div class="header-left">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <h1>Mensajes</h1>
            <p class="welcome">
                <i class="fas fa-envelope"></i>
                <?php echo $stats['no_leidos']; ?> urgentes · <?php echo $stats['total']; ?> total
            </p>
        </div>
        <div class="header-actions">
            <?php if (esAdmin()): ?>
                <a href="gestion_contactos.php" class="btn-header secondary" style="position:relative; background: #6f42c1; color: #fff;">
                    <i class="fas fa-address-book"></i> Contactos
                    <?php if ($contactos_nuevos > 0): ?>
                        <span class="badge-contacts"><?php echo $contactos_nuevos; ?></span>
                    <?php endif; ?>
                </a>
                <a href="gestion_leads.php" class="btn-header secondary" style="position:relative; background: #6f42c1; color: #fff;">
                    <i class="fas fa-address-book"></i> Leads
                    <?php if ($leads_nuevos > 0): ?>
                        <span class="badge-contacts"><?php echo $leads_nuevos; ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
            <button class="btn-header primary" onclick="nuevoMensaje()">
                <i class="fas fa-plus-circle"></i> Nuevo Mensaje
            </button>
            <button class="btn-header secondary" onclick="marcarTodosLeidos()">
                <i class="fas fa-check-double"></i> Marcar todos como leídos
            </button>
        </div>
    </div>

    <!-- Estadísticas -->
    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-icon"><i class="fas fa-envelope"></i></span>
            <div class="stat-number"><?php echo $stats['total']; ?></div>
            <div class="stat-label">Total Evaluaciones</div>
        </div>
        <div class="stat-card danger">
            <span class="stat-icon"><i class="fas fa-fire"></i></span>
            <div class="stat-number"><?php echo $stats['no_leidos']; ?></div>
            <div class="stat-label">Urgentes</div>
        </div>
        <div class="stat-card success">
            <span class="stat-icon"><i class="fas fa-paper-plane"></i></span>
            <div class="stat-number"><?php echo $stats['enviados']; ?></div>
            <div class="stat-label">Enviados</div>
        </div>
        <div class="stat-card info">
            <span class="stat-icon"><i class="fas fa-inbox"></i></span>
            <div class="stat-number"><?php echo $stats['recibidos']; ?></div>
            <div class="stat-label">Recibidos</div>
        </div>

        <?php if (esAdmin()): ?>
            <a href="gestion_contactos.php" style="text-decoration: none; color: inherit; display: block;">
                <div class="stat-card contactos" style="border-left-color: #6f42c1;">
                    <span class="stat-icon" style="color: #6f42c1;"><i class="fas fa-users"></i></span>
                    <div class="stat-number"><?php echo $total_contactos; ?></div>
                    <div class="stat-label">
                        Contactos totales
                        <?php if ($contactos_nuevos > 0): ?>
                            <span style="display: inline-block; background: #dc3545; color: white; border-radius: 50%; padding: 0 8px; font-size: 0.7rem; margin-left: 5px;">
                                <?php echo $contactos_nuevos; ?> nuevos
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        <?php endif; ?>
    </div>

    <!-- Bandeja -->
    <div class="table-container">
        <div class="table-header">
            <h3><i class="fas fa-list"></i> Bandeja de Entrada</h3>
            <div class="search-box">
                <input type="text" placeholder="Buscar..." id="searchTable">
                <select id="filterType">
                    <option value="">Todas</option>
                    <option value="inmediata">Urgencia inmediata</option>
                    <option value="alta">Urgencia alta</option>
                    <option value="media">Urgencia media</option>
                    <option value="baja">Urgencia baja</option>
                </select>
            </div>
        </div>

        <div class="table-responsive">
            <?php if (empty($mensajes)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No hay evaluaciones</h3>
                    <p style="color: var(--gray);">Aquí aparecerán las propiedades enviadas desde el formulario público</p>
                </div>
            <?php else: ?>
                <?php foreach ($mensajes as $m): 
                    $urgente = in_array($m['urgencia_venta'], ['inmediata', 'alta']);
                ?>
                    <div class="mensaje-item <?php echo $urgente ? 'unread' : ''; ?>"
                         onclick="verMensaje(<?php echo (int)$m['id']; ?>)">
                        <div class="avatar-small">
                            <?php echo strtoupper(substr($m['remitente'] ?? 'X', 0, 1)); ?>
                        </div>
                        <div class="mensaje-info">
                            <div class="asunto">
                                <?php echo htmlspecialchars($m['remitente'] ?? 'Sin nombre'); ?>
                                — <?php echo htmlspecialchars(ucfirst($m['asunto'] ?? '')); ?>
                                <?php if ($urgente): ?>
                                    <span class="badge-unread">Urgente</span>
                                <?php endif; ?>
                            </div>
                            <div class="preview">
                                <i class="fas fa-map-marker-alt"></i>
                                <?php echo htmlspecialchars($m['ciudad'] ?? ''); ?>
                                · $<?php echo number_format((float)($m['precio_libre_gastos'] ?? 0), 0); ?>
                                · <?php echo htmlspecialchars(substr($m['mensaje'] ?? 'Sin comentarios', 0, 60)); ?>
                            </div>
                        </div>
                        <div class="mensaje-meta">
                            <div class="fecha">
                                <?php echo date('d/m/Y H:i', strtotime($m['fecha_envio'] ?? 'now')); ?>
                            </div>
                            <span class="estado <?php echo htmlspecialchars($m['urgencia_venta'] ?? ''); ?>">
                                <i class="fas fa-bolt"></i>
                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $m['urgencia_venta'] ?? ''))); ?>
                            </span>
                        </div>
                        <div class="mensaje-actions">
                            <button onclick="event.stopPropagation(); eliminarMensaje(<?php echo (int)$m['id']; ?>)" class="danger">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
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
            if (window.innerWidth <= 992) toggleSidebar();
        });
    });

    document.getElementById('searchTable').addEventListener('keyup', filtrarMensajes);
    document.getElementById('filterType').addEventListener('change', filtrarMensajes);

    function filtrarMensajes() {
        const searchText = document.getElementById('searchTable').value.toLowerCase();
        const filterType = document.getElementById('filterType').value.toLowerCase();
        const items = document.querySelectorAll('.mensaje-item');

        items.forEach(item => {
            const text = item.textContent.toLowerCase();
            const tipo = item.querySelector('.estado')?.textContent.toLowerCase() || '';
            let matchesSearch = text.includes(searchText);
            let matchesType = filterType === '' || tipo.includes(filterType);
            item.style.display = (matchesSearch && matchesType) ? 'flex' : 'none';
        });
    }

    function verMensaje(id) {
        window.location.href = 'ver_evaluacion.php?id=' + id;
    }

    function nuevoMensaje() {
        alert('Función: Crear nuevo mensaje');
    }

    function eliminarMensaje(id) {
        if (confirm('¿Eliminar esta evaluación?')) {
            window.location.href = 'eliminar_evaluacion.php?id=' + id;
        }
    }

    function marcarTodosLeidos() {
        alert('Función: Marcar todos como leídos');
    }
</script>

</body>
</html>