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

// Verificar si el usuario es administrador
$es_admin = isset($_SESSION['rol']) && $_SESSION['rol'] === 'admin';

// Obtener lista de usuarios (solo para admin)
$usuarios = [];
if ($es_admin) {
    try {
        $stmt = $conn->query("
            SELECT u.*, 
                   (SELECT COUNT(*) FROM propiedades WHERE socio_id = u.id) as total_propiedades
            FROM usuarios u
            ORDER BY u.fecha_registro DESC
        ");
        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $usuarios = [];
    }
}

// Obtener logs de acceso (si existe la tabla)
$logs = [];
try {
    $stmt = $conn->prepare("
        SELECT * FROM logs_acceso
        WHERE usuario_id = ?
        ORDER BY fecha DESC
        LIMIT 20
    ");
    $stmt->execute([$_SESSION['usuario_id']]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $logs = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración de Acceso | Inmobiliaria MH</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="css/socios.css">
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
            <h1>Configuración de Acceso</h1>
            <p class="welcome">
                <i class="fas fa-user-cog"></i> Gestiona tu perfil y seguridad
            </p>
        </div>
        <div class="header-actions">
            <button class="btn-header secondary" onclick="cambiarContrasena()">
                <i class="fas fa-key"></i> Cambiar Contraseña
            </button>
        </div>
    </div>

    <!-- Perfil -->
    <div class="acceso-section">
        <h3><i class="fas fa-user"></i> Mi Perfil</h3>
        <div class="perfil-info">
            <div class="perfil-avatar-large">
                <?php echo strtoupper(substr($usuario['nombre'] ?? 'U', 0, 1)); ?>
            </div>
            <div class="perfil-datos">
                <div class="campo">
                    <span class="label">Nombre Completo</span>
                    <span class="value"><?php echo htmlspecialchars($usuario['nombre'] ?? 'No definido'); ?></span>
                </div>
                <div class="campo">
                    <span class="label">Email</span>
                    <span class="value"><?php echo htmlspecialchars($usuario['email'] ?? 'No definido'); ?></span>
                </div>
                <div class="campo">
                    <span class="label">Teléfono</span>
                    <span class="value"><?php echo htmlspecialchars($usuario['telefono'] ?? 'No definido'); ?></span>
                </div>
                <div class="campo">
                    <span class="label">Rol</span>
                    <span class="value">
                        <span class="status-badge <?php echo $_SESSION['rol'] ?? 'socio'; ?>">
                            <?php echo ucfirst($_SESSION['rol'] ?? 'Socio'); ?>
                        </span>
                    </span>
                </div>
                <div class="campo">
                    <span class="label">Estado de Cuenta</span>
                    <span class="value">
                        <span class="status-badge <?php echo ($usuario['activo'] ?? 1) ? 'activa' : 'inactiva'; ?>">
                            <?php echo ($usuario['activo'] ?? 1) ? 'Activo' : 'Inactivo'; ?>
                        </span>
                    </span>
                </div>
                <div class="campo">
                    <span class="label">Miembro desde</span>
                    <span class="value"><?php echo date('d/m/Y', strtotime($usuario['fecha_registro'] ?? 'now')); ?></span>
                </div>
                <div class="campo" style="grid-column: 1 / -1; margin-top: 10px;">
                    <button class="btn-cambiar" onclick="editarPerfil()">
                        <i class="fas fa-edit"></i> Editar Perfil
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Permisos (solo para admin) -->
    <?php if ($es_admin): ?>
    <div class="acceso-section">
        <h3><i class="fas fa-shield-alt"></i> Gestión de Usuarios</h3>
        <?php if (empty($usuarios)): ?>
            <div class="empty-state" style="padding: 30px;">
                <i class="fas fa-users"></i>
                <p>No hay usuarios registrados</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Email</th>
                            <th>Rol</th>
                            <th>Propiedades</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $u): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($u['nombre'] ?? 'N/A'); ?></strong></td>
                                <td><?php echo htmlspecialchars($u['email'] ?? 'N/A'); ?></td>
                                <td><span class="status-badge <?php echo $u['rol'] ?? 'socio'; ?>"><?php echo ucfirst($u['rol'] ?? 'Socio'); ?></span></td>
                                <td><?php echo $u['total_propiedades'] ?? 0; ?></td>
                                <td>
                                    <span class="status-badge <?php echo ($u['activo'] ?? 1) ? 'activa' : 'inactiva'; ?>">
                                        <?php echo ($u['activo'] ?? 1) ? 'Activo' : 'Inactivo'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <button class="action-btn edit" onclick="editarUsuario(<?php echo $u['id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="action-btn delete" onclick="toggleUsuario(<?php echo $u['id']; ?>, <?php echo $u['activo'] ?? 1; ?>)">
                                            <i class="fas <?php echo ($u['activo'] ?? 1) ? 'fa-pause' : 'fa-play'; ?>"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Logs de acceso -->
    <div class="acceso-section">
        <h3><i class="fas fa-history"></i> Historial de Accesos</h3>
        <?php if (empty($logs)): ?>
            <div class="empty-state" style="padding: 20px;">
                <i class="fas fa-history"></i>
                <p style="color: var(--gray);">No hay registros de acceso disponibles</p>
            </div>
        <?php else: ?>
            <?php foreach ($logs as $log): ?>
                <div class="log-item">
                    <div class="log-info">
                        <span class="log-icon"><i class="fas <?php echo ($log['exitoso'] ?? 1) ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i></span>
                        <span><?php echo htmlspecialchars($log['accion'] ?? 'Acceso al sistema'); ?></span>
                        <span class="log-estado <?php echo ($log['exitoso'] ?? 1) ? 'exitoso' : 'fallido'; ?>">
                            <?php echo ($log['exitoso'] ?? 1) ? 'Exitoso' : 'Fallido'; ?>
                        </span>
                    </div>
                    <span class="log-fecha">
                        <?php echo date('d/m/Y H:i', strtotime($log['fecha'] ?? 'now')); ?>
                    </span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Seguridad -->
    <div class="acceso-section">
        <h3><i class="fas fa-lock"></i> Seguridad</h3>
        <div class="permiso-item">
            <div class="permiso-info">
                <span class="nombre">Autenticación de dos factores</span>
                <span class="descripcion">Añade una capa extra de seguridad a tu cuenta</span>
            </div>
            <div class="toggle" onclick="toggle2FA(this)">
                <div class="toggle-ball"></div>
            </div>
        </div>
        <div class="permiso-item">
            <div class="permiso-info">
                <span class="nombre">Notificaciones de inicio de sesión</span>
                <span class="descripcion">Recibe alertas cuando se acceda a tu cuenta</span>
            </div>
            <div class="toggle active" onclick="toggleNotificaciones(this)">
                <div class="toggle-ball"></div>
            </div>
        </div>
        <div class="permiso-item">
            <div class="permiso-info">
                <span class="nombre">Cerrar sesión en todos los dispositivos</span>
                <span class="descripcion">Finaliza todas las sesiones activas en otros dispositivos</span>
            </div>
            <button class="btn-cambiar btn-danger" onclick="cerrarTodosDispositivos()">
                <i class="fas fa-sign-out-alt"></i> Cerrar todas las sesiones
            </button>
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

    // ===== Toggles =====
    function toggle2FA(element) {
        element.classList.toggle('active');
        alert('Autenticación de dos factores ' + (element.classList.contains('active') ? 'activada' : 'desactivada'));
    }

    function toggleNotificaciones(element) {
        element.classList.toggle('active');
        alert('Notificaciones de inicio de sesión ' + (element.classList.contains('active') ? 'activadas' : 'desactivadas'));
    }

    // ===== Funciones =====
    function cambiarContrasena() {
        alert('Función: Cambiar contraseña');
    }

    function editarPerfil() {
        alert('Función: Editar perfil');
    }

    function editarUsuario(id) {
        alert('Función: Editar usuario #' + id);
    }

    function toggleUsuario(id, estado) {
        if (confirm('¿' + (estado ? 'Desactivar' : 'Activar') + ' este usuario?')) {
            alert('Función: ' + (estado ? 'Desactivar' : 'Activar') + ' usuario #' + id);
        }
    }

    function cerrarTodosDispositivos() {
        if (confirm('¿Estás seguro de que quieres cerrar todas las sesiones activas?')) {
            alert('Función: Cerrar todas las sesiones');
        }
    }
</script>

</body>
</html>