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
$es_admin = esAdmin();

// Si no es admin, redirigir a dashboard
if (!$es_admin) {
    header('Location: dashboard.php');
    exit;
}

// Variables para mensajes
$mensaje = '';
$tipo_mensaje = '';

// Procesar acciones del formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    try {
        switch ($accion) {
            case 'crear_usuario':
                $datos = [
                    'name' => trim($_POST['name']),
                    'email' => trim($_POST['email']),
                    'telefono' => trim($_POST['telefono'] ?? ''),
                    'password' => $_POST['password'],
                    'role' => $_POST['role'],
                    'activo' => $_POST['activo'] ?? 1
                ];
                
                // Validaciones
                if (empty($datos['name']) || empty($datos['email']) || empty($datos['password'])) {
                    throw new Exception('Todos los campos obligatorios deben estar llenos');
                }
                
                if (!filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Email no válido');
                }
                
                if (strlen($datos['password']) < 6) {
                    throw new Exception('La contraseña debe tener al menos 6 caracteres');
                }
                
                $id = crearUsuario($conn, $datos);
                if (!$id) {
                    throw new Exception('Error al crear usuario. El email podría estar duplicado.');
                }
                
                $mensaje = 'Usuario creado exitosamente';
                $tipo_mensaje = 'success';
                break;
                
            case 'editar_usuario':
                $id = $_POST['usuario_id'];
                $datos = [
                    'name' => trim($_POST['name']),
                    'email' => trim($_POST['email']),
                    'telefono' => trim($_POST['telefono'] ?? ''),
                    'role' => $_POST['role'],
                    'activo' => $_POST['activo'] ?? 1
                ];
                
                if (!empty($_POST['nuevo_password'])) {
                    if (strlen($_POST['nuevo_password']) < 6) {
                        throw new Exception('La contraseña debe tener al menos 6 caracteres');
                    }
                    $datos['password'] = $_POST['nuevo_password'];
                }
                
                if (!actualizarUsuario($conn, $id, $datos)) {
                    throw new Exception('Error al actualizar usuario');
                }
                
                $mensaje = 'Usuario actualizado exitosamente';
                $tipo_mensaje = 'success';
                break;
                
            case 'toggle_usuario':
                $id = $_POST['usuario_id'];
                $nuevo_estado = $_POST['nuevo_estado'];
                
                if (!cambiarEstadoUsuario($conn, $id, $nuevo_estado)) {
                    throw new Exception('Error al cambiar estado');
                }
                
                $mensaje = 'Estado del usuario actualizado';
                $tipo_mensaje = 'success';
                break;
                
            case 'eliminar_usuario':
                $id = $_POST['usuario_id'];
                
                if ($id == $_SESSION['usuario_id']) {
                    throw new Exception('No puedes eliminar tu propia cuenta');
                }
                
                if (!eliminarUsuario($conn, $id)) {
                    throw new Exception('Error al eliminar usuario');
                }
                
                $mensaje = 'Usuario eliminado exitosamente';
                $tipo_mensaje = 'success';
                break;
        }
    } catch (Exception $e) {
        $mensaje = $e->getMessage();
        $tipo_mensaje = 'error';
    }
}

// Obtener lista de usuarios
$usuarios = obtenerTodosUsuarios($conn);

// Obtener usuario para editar (si se solicita)
$usuario_editar = null;
if (isset($_GET['editar']) && is_numeric($_GET['editar'])) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_GET['editar']]);
    $usuario_editar = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios | Inmobiliaria MH</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="css/socios.css">
    <style>
        /* Estilos para la gestión de usuarios */
        .usuarios-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-top: 20px;
        }
        @media (max-width: 992px) {
            .usuarios-grid {
                grid-template-columns: 1fr;
            }
        }
        .form-usuario {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        .form-usuario h4 {
            color: #4c51bf;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 6px;
        }
        .form-group label .required {
            color: #e53e3e;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: border-color 0.3s ease;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #4c51bf;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        .btn-submit {
            background: #4c51bf;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s ease;
            width: 100%;
        }
        .btn-submit:hover {
            background: #3c41a8;
        }
        .btn-cancelar {
            background: #e2e8f0;
            color: #2d3748;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 0.95rem;
            cursor: pointer;
            transition: background 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-cancelar:hover {
            background: #cbd5e1;
        }
        .mensaje {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .mensaje.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .mensaje.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .tabla-usuarios {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            overflow: hidden;
            width: 100%;
        }
        .tabla-usuarios table {
            width: 100%;
            border-collapse: collapse;
        }
        .tabla-usuarios th {
            background: #f8fafc;
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            color: #2d3748;
            border-bottom: 2px solid #e2e8f0;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .tabla-usuarios td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.95rem;
        }
        .tabla-usuarios tr:hover {
            background: #f8fafc;
        }
        .action-btns {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .action-btn {
            padding: 6px 10px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .action-btn.edit {
            background: #3b82f6;
        }
        .action-btn.edit:hover {
            background: #2563eb;
        }
        .action-btn.toggle {
            background: #f59e0b;
        }
        .action-btn.toggle:hover {
            background: #d97706;
        }
        .action-btn.delete {
            background: #ef4444;
        }
        .action-btn.delete:hover {
            background: #dc2626;
        }
        .badge-rol {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .badge-rol.admin {
            background: #dbeafe;
            color: #1e40af;
        }
        .badge-rol.propietario {
            background: #d1fae5;
            color: #065f46;
        }
        .badge-rol.vendedor {
            background: #fef3c7;
            color: #92400e;
        }
        .badge-rol.inmobiliaria {
            background: #e0e7ff;
            color: #3730a3;
        }
        .badge-estado {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .badge-estado.activo {
            background: #d1fae5;
            color: #065f46;
        }
        .badge-estado.inactivo {
            background: #fee2e2;
            color: #991b1b;
        }
        .fecha-registro {
            font-size: 0.85rem;
            color: #64748b;
            white-space: nowrap;
        }
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
        }
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 10px;
            display: block;
        }
        .main-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        .header-left h1 {
            font-size: 1.8rem;
            color: #2d3748;
            margin: 0;
        }
        .header-left .welcome {
            color: #718096;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #2d3748;
            cursor: pointer;
        }
        .btn-header {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        .btn-header.primary {
            background: #4c51bf;
            color: white;
        }
        .btn-header.primary:hover {
            background: #3c41a8;
        }
        .acceso-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        .acceso-section h3 {
            color: #2d3748;
            margin-top: 0;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        @media (max-width: 768px) {
            .menu-toggle {
                display: block;
            }
            .header-left h1 {
                font-size: 1.3rem;
            }
            .header-left .welcome {
                font-size: 0.85rem;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
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
            <h1>Gestión de Usuarios</h1>
            <p class="welcome">
                <i class="fas fa-users-cog"></i> Administra los accesos al sistema
            </p>
        </div>
        <div class="header-actions">
            <button class="btn-header primary" onclick="document.getElementById('form-crear').scrollIntoView({behavior: 'smooth'})">
                <i class="fas fa-user-plus"></i> Nuevo Usuario
            </button>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if ($mensaje): ?>
        <div class="mensaje <?php echo $tipo_mensaje; ?>">
            <i class="fas <?php echo $tipo_mensaje === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>

    <!-- Lista de Usuarios -->
    <div class="acceso-section">
        <h3><i class="fas fa-list"></i> Usuarios del Sistema</h3>
        <div style="margin-top: 15px; overflow-x: auto;">
            <div class="tabla-usuarios">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Email</th>
                            <th>Teléfono</th>
                            <th>Rol</th>
                            <th>Estado</th>
                            <th>Fecha Registro</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($usuarios)): ?>
                            <tr>
                                <td colspan="8">
                                    <div class="empty-state">
                                        <i class="fas fa-users"></i>
                                        <p>No hay usuarios registrados</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($usuarios as $u): ?>
                                <tr>
                                    <td><strong>#<?php echo $u['id']; ?></strong></td>
                                    <td><strong><?php echo htmlspecialchars($u['name'] ?? 'N/A'); ?></strong></td>
                                    <td><?php echo htmlspecialchars($u['email'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($u['telefono'] ?? '—'); ?></td>
                                    <td>
                                        <span class="badge-rol <?php echo $u['role'] ?? 'propietario'; ?>">
                                            <?php echo ucfirst($u['role'] ?? 'Propietario'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge-estado <?php echo ($u['activo'] ?? 1) ? 'activo' : 'inactivo'; ?>">
                                            <?php echo ($u['activo'] ?? 1) ? 'Activo' : 'Inactivo'; ?>
                                        </span>
                                    </td>
                                    <td class="fecha-registro">
                                        <?php echo date('d/m/Y H:i', strtotime($u['created_at'] ?? 'now')); ?>
                                    </td>
                                    <td>
                                        <div class="action-btns">
                                            <a href="?editar=<?php echo $u['id']; ?>" class="action-btn edit" title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button class="action-btn toggle" onclick="toggleUsuario(<?php echo $u['id']; ?>, <?php echo $u['activo'] ?? 1; ?>)" title="<?php echo ($u['activo'] ?? 1) ? 'Desactivar' : 'Activar'; ?>">
                                                <i class="fas <?php echo ($u['activo'] ?? 1) ? 'fa-pause' : 'fa-play'; ?>"></i>
                                            </button>
                                            <?php if ($u['id'] != $_SESSION['usuario_id']): ?>
                                                <button class="action-btn delete" onclick="eliminarUsuario(<?php echo $u['id']; ?>)" title="Eliminar">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Formularios -->
    <div class="usuarios-grid">
        <div class="form-usuario" id="form-crear">
            <h4><i class="fas fa-user-plus"></i> Crear Nuevo Usuario</h4>
            <form method="POST" action="" onsubmit="return validarFormulario(this)">
                <input type="hidden" name="accion" value="crear_usuario">
                <div class="form-group">
                    <label>Nombre Completo <span class="required">*</span></label>
                    <input type="text" name="name" required placeholder="Ej: Juan Pérez">
                </div>
                <div class="form-group">
                    <label>Email <span class="required">*</span></label>
                    <input type="email" name="email" required placeholder="ejemplo@correo.com">
                </div>
                <div class="form-group">
                    <label>Teléfono</label>
                    <input type="tel" name="telefono" placeholder="Ej: 55 1234 5678">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Contraseña <span class="required">*</span></label>
                        <input type="password" name="password" required minlength="6" placeholder="Mínimo 6 caracteres">
                    </div>
                    <div class="form-group">
                        <label>Confirmar Contraseña <span class="required">*</span></label>
                        <input type="password" name="confirm_password" required placeholder="Repite la contraseña">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Rol <span class="required">*</span></label>
                        <select name="role" required>
                            <option value="propietario">Propietario</option>
                            <option value="vendedor">Vendedor</option>
                            <option value="admin">Administrador</option>
                            <option value="inmobiliaria">Inmobiliaria</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Estado</label>
                        <select name="activo">
                            <option value="1">Activo</option>
                            <option value="0">Inactivo</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Crear Usuario
                </button>
            </form>
        </div>

        <div class="form-usuario" id="form-editar">
            <h4><i class="fas fa-user-edit"></i> Editar Usuario</h4>
            <?php if ($usuario_editar): ?>
                <form method="POST" action="" onsubmit="return validarFormularioEdicion(this)">
                    <input type="hidden" name="accion" value="editar_usuario">
                    <input type="hidden" name="usuario_id" value="<?php echo $usuario_editar['id']; ?>">
                    <div class="form-group">
                        <label>Nombre Completo <span class="required">*</span></label>
                        <input type="text" name="name" required value="<?php echo htmlspecialchars($usuario_editar['name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Email <span class="required">*</span></label>
                        <input type="email" name="email" required value="<?php echo htmlspecialchars($usuario_editar['email'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Teléfono</label>
                        <input type="tel" name="telefono" value="<?php echo htmlspecialchars($usuario_editar['telefono'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Nueva Contraseña <span style="font-weight: normal; color: #94a3b8; font-size: 0.8rem;">(dejar vacío para mantener)</span></label>
                        <input type="password" name="nuevo_password" minlength="6" placeholder="Mínimo 6 caracteres">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Rol <span class="required">*</span></label>
                            <select name="role" required>
                                <option value="propietario" <?php echo ($usuario_editar['role'] ?? '') === 'propietario' ? 'selected' : ''; ?>>Propietario</option>
                                <option value="vendedor" <?php echo ($usuario_editar['role'] ?? '') === 'vendedor' ? 'selected' : ''; ?>>Vendedor</option>
                                <option value="admin" <?php echo ($usuario_editar['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Administrador</option>
                                <option value="inmobiliaria" <?php echo ($usuario_editar['role'] ?? '') === 'inmobiliaria' ? 'selected' : ''; ?>>Inmobiliaria</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Estado</label>
                            <select name="activo">
                                <option value="1" <?php echo ($usuario_editar['activo'] ?? 1) == 1 ? 'selected' : ''; ?>>Activo</option>
                                <option value="0" <?php echo ($usuario_editar['activo'] ?? 1) == 0 ? 'selected' : ''; ?>>Inactivo</option>
                            </select>
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <a href="accesos.php" class="btn-cancelar">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                        <button type="submit" class="btn-submit" style="flex: 1;">
                            <i class="fas fa-save"></i> Actualizar
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <div class="empty-state" style="padding: 30px;">
                    <i class="fas fa-user-edit"></i>
                    <p>Selecciona un usuario para editarlo</p>
                    <p style="font-size: 0.85rem; color: #94a3b8; margin-top: 5px;">Haz clic en <i class="fas fa-edit"></i> en la tabla</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
    // Menú móvil
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

    // Validaciones
    function validarFormulario(form) {
        const password = form.querySelector('input[name="password"]');
        const confirm = form.querySelector('input[name="confirm_password"]');
        
        if (password.value !== confirm.value) {
            alert('Las contraseñas no coinciden');
            confirm.focus();
            return false;
        }
        
        if (password.value.length < 6) {
            alert('La contraseña debe tener al menos 6 caracteres');
            password.focus();
            return false;
        }
        
        return true;
    }
    
    function validarFormularioEdicion(form) {
        const password = form.querySelector('input[name="nuevo_password"]');
        if (password.value && password.value.length < 6) {
            alert('La contraseña debe tener al menos 6 caracteres');
            password.focus();
            return false;
        }
        return true;
    }

    // Funciones de usuario
    function toggleUsuario(id, estado) {
        const mensaje = estado ? 'desactivar' : 'activar';
        if (confirm(`¿Estás seguro de ${mensaje} este usuario?`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="accion" value="toggle_usuario">
                <input type="hidden" name="usuario_id" value="${id}">
                <input type="hidden" name="nuevo_estado" value="${estado ? 0 : 1}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    function eliminarUsuario(id) {
        if (confirm('¿Eliminar este usuario? Esta acción no se puede deshacer.')) {
            if (confirm('Confirmar eliminación del usuario #' + id + '?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="accion" value="eliminar_usuario">
                    <input type="hidden" name="usuario_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    }
</script>

</body>
</html>