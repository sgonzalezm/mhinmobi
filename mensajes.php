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

// Obtener mensajes (si existe la tabla)
$mensajes = [];
try {
    $stmt = $conn->prepare("
        SELECT m.*, 
               CASE 
                   WHEN m.remitente_id = ? THEN 'enviado'
                   ELSE 'recibido'
               END as tipo
        FROM mensajes m
        WHERE m.remitente_id = ? OR m.destinatario_id = ?
        ORDER BY m.fecha_envio DESC
        LIMIT 30
    ");
    $stmt->execute([$_SESSION['usuario_id'], $_SESSION['usuario_id'], $_SESSION['usuario_id']]);
    $mensajes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Si no existe la tabla, mostrar mensajes de ejemplo
    $mensajes = [
        [
            'id' => 1,
            'remitente_id' => 1,
            'asunto' => 'Consulta sobre propiedad',
            'mensaje' => 'Hola, estoy interesado en la propiedad que publicaste...',
            'fecha_envio' => '2024-06-15 10:30:00',
            'leido' => 0,
            'tipo' => 'recibido'
        ],
        [
            'id' => 2,
            'remitente_id' => 2,
            'asunto' => 'Confirmación de visita',
            'mensaje' => 'Perfecto, confirmo la visita para el sábado a las 11am...',
            'fecha_envio' => '2024-06-14 16:45:00',
            'leido' => 1,
            'tipo' => 'enviado'
        ],
        [
            'id' => 3,
            'remitente_id' => 1,
            'asunto' => 'Oferta por la propiedad',
            'mensaje' => 'Me gustaría hacer una oferta de $150,000 por la propiedad...',
            'fecha_envio' => '2024-06-14 09:15:00',
            'leido' => 0,
            'tipo' => 'recibido'
        ]
    ];
}

// Estadísticas
$stats = [
    'total' => count($mensajes),
    'no_leidos' => 0,
    'enviados' => 0,
    'recibidos' => 0
];

foreach ($mensajes as $m) {
    if (isset($m['leido']) && $m['leido'] == 0) $stats['no_leidos']++;
    if (isset($m['tipo'])) {
        if ($m['tipo'] === 'enviado') $stats['enviados']++;
        if ($m['tipo'] === 'recibido') $stats['recibidos']++;
    }
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
        .mensaje-item:hover {
            background: #f8f9fa;
        }
        .mensaje-item.unread {
            background: #e8f4fd;
            border-left: 3px solid var(--primary);
        }
        .mensaje-item .avatar-small {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .mensaje-item .mensaje-info {
            flex: 1;
            min-width: 0;
        }
        .mensaje-item .mensaje-info .asunto {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 3px;
        }
        .mensaje-item .mensaje-info .preview {
            font-size: 0.9rem;
            color: var(--gray);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .mensaje-item .mensaje-meta {
            text-align: right;
            flex-shrink: 0;
        }
        .mensaje-item .mensaje-meta .fecha {
            font-size: 0.8rem;
            color: var(--gray);
        }
        .mensaje-item .mensaje-meta .estado {
            font-size: 0.75rem;
            padding: 2px 10px;
            border-radius: 12px;
            margin-top: 4px;
            display: inline-block;
        }
        .mensaje-item .mensaje-meta .estado.enviado {
            background: #d4edda;
            color: #155724;
        }
        .mensaje-item .mensaje-meta .estado.recibido {
            background: #cce5ff;
            color: #004085;
        }
        .badge-unread {
            background: #dc3545;
            color: white;
            border-radius: 50%;
            padding: 2px 8px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .mensaje-actions {
            display: flex;
            gap: 8px;
        }
        .mensaje-actions button {
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 4px;
            transition: all 0.3s ease;
        }
        .mensaje-actions button:hover {
            background: #e9ecef;
            color: var(--dark);
        }
        .mensaje-actions button.danger:hover {
            background: #f8d7da;
            color: #dc3545;
        }
        @media (max-width: 768px) {
            .mensaje-item {
                flex-wrap: wrap;
            }
            .mensaje-item .mensaje-meta {
                width: 100%;
                text-align: left;
                display: flex;
                gap: 10px;
                align-items: center;
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
            <h1>Mensajes</h1>
            <p class="welcome">
                <i class="fas fa-envelope"></i> 
                <?php echo $stats['no_leidos']; ?> no leídos · <?php echo $stats['total']; ?> total
            </p>
        </div>
        <div class="header-actions">
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
            <div class="stat-label">Total Mensajes</div>
        </div>
        <div class="stat-card danger">
            <span class="stat-icon"><i class="fas fa-envelope-open"></i></span>
            <div class="stat-number"><?php echo $stats['no_leidos']; ?></div>
            <div class="stat-label">No Leídos</div>
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
    </div>

    <!-- Listado de mensajes -->
    <div class="table-container">
        <div class="table-header">
            <h3><i class="fas fa-list"></i> Bandeja de Entrada</h3>
            <div class="search-box">
                <input type="text" placeholder="Buscar mensaje..." id="searchTable">
                <select id="filterType">
                    <option value="">Todos</option>
                    <option value="recibido">Recibidos</option>
                    <option value="enviado">Enviados</option>
                </select>
            </div>
        </div>

        <div class="table-responsive">
            <?php if (empty($mensajes)): ?>
                <div class="empty-state">
                    <i class="fas fa-envelope"></i>
                    <h3>No hay mensajes</h3>
                    <p style="color: var(--gray);">Comienza una conversación con tus clientes</p>
                    <button onclick="nuevoMensaje()" class="btn-header primary" style="margin-top: 20px;">
                        <i class="fas fa-plus-circle"></i> Nuevo Mensaje
                    </button>
                </div>
            <?php else: ?>
                <?php foreach ($mensajes as $mensaje): ?>
                    <div class="mensaje-item <?php echo ($mensaje['leido'] == 0 && $mensaje['tipo'] === 'recibido') ? 'unread' : ''; ?>" 
                         onclick="verMensaje(<?php echo $mensaje['id']; ?>)">
                        <div class="avatar-small">
                            <?php echo strtoupper(substr($mensaje['tipo'] === 'enviado' ? 'Yo' : 'Cliente', 0, 1)); ?>
                        </div>
                        <div class="mensaje-info">
                            <div class="asunto">
                                <?php echo htmlspecialchars($mensaje['asunto'] ?? 'Sin asunto'); ?>
                                <?php if ($mensaje['leido'] == 0 && $mensaje['tipo'] === 'recibido'): ?>
                                    <span class="badge-unread">Nuevo</span>
                                <?php endif; ?>
                            </div>
                            <div class="preview">
                                <?php echo htmlspecialchars(substr($mensaje['mensaje'] ?? '', 0, 80)) . '...'; ?>
                            </div>
                        </div>
                        <div class="mensaje-meta">
                            <div class="fecha">
                                <?php echo date('d/m/Y H:i', strtotime($mensaje['fecha_envio'] ?? 'now')); ?>
                            </div>
                            <span class="estado <?php echo $mensaje['tipo']; ?>">
                                <i class="fas <?php echo $mensaje['tipo'] === 'enviado' ? 'fa-arrow-right' : 'fa-arrow-left'; ?>"></i>
                                <?php echo ucfirst($mensaje['tipo']); ?>
                            </span>
                        </div>
                        <div class="mensaje-actions">
                            <button onclick="event.stopPropagation(); eliminarMensaje(<?php echo $mensaje['id']; ?>)" class="danger">
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
        filtrarMensajes();
    });

    document.getElementById('filterType').addEventListener('change', function() {
        filtrarMensajes();
    });

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

    // ===== Funciones =====
    function verMensaje(id) {
        alert('Función: Ver mensaje #' + id);
    }

    function nuevoMensaje() {
        alert('Función: Crear nuevo mensaje');
    }

    function eliminarMensaje(id) {
        if (confirm('¿Estás seguro de que quieres eliminar este mensaje?')) {
            alert('Función: Eliminar mensaje #' + id);
        }
    }

    function marcarTodosLeidos() {
        if (confirm('¿Marcar todos los mensajes como leídos?')) {
            alert('Función: Marcar todos como leídos');
        }
    }
</script>

</body>
</html>