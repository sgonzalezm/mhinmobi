<?php
session_start();
require_once 'includes/conexion.php';
require_once 'includes/auth.php';

// Verificar autenticación
if (!estaLogueado()) {
    header('Location: login.php');
    exit;
}

// Obtener datos del usuario actual
$usuario = obtenerUsuarioActual($conn);
if (!$usuario) {
    cerrarSesion();
    header('Location: login.php');
    exit;
}

// Variables para mensajes
$mensaje = '';
$tipo_mensaje = '';
$errores = [];

// Procesar acciones del formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    try {
        switch ($accion) {
            case 'actualizar_perfil':
                $datos = [
                    'name'     => trim($_POST['name'] ?? ''),
                    'email'    => trim($_POST['email'] ?? ''),
                    'telefono' => trim($_POST['telefono'] ?? ''),
                ];

                // Validaciones
                if (empty($datos['name'])) {
                    $errores['name'] = 'El nombre es obligatorio';
                }
                if (empty($datos['email'])) {
                    $errores['email'] = 'El email es obligatorio';
                } elseif (!filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) {
                    $errores['email'] = 'Email no válido';
                }

                // Verificar email duplicado (excluyendo el usuario actual) — PDO
                if (empty($errores['email'])) {
                    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                    $stmt->execute([$datos['email'], $usuario['id']]);
                    if ($stmt->fetch()) {
                        $errores['email'] = 'Este email ya está registrado por otro usuario';
                    }
                }

                if (empty($errores)) {
                    $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, telefono = ? WHERE id = ?");
                    $stmt->execute([$datos['name'], $datos['email'], $datos['telefono'], $usuario['id']]);

                    $mensaje = 'Perfil actualizado correctamente';
                    $tipo_mensaje = 'success';
                    // Recargar datos del usuario
                    $usuario = obtenerUsuarioActual($conn);
                }
                break;

            case 'cambiar_password':
                $password_actual  = $_POST['password_actual'] ?? '';
                $password_nuevo   = $_POST['password_nuevo'] ?? '';
                $password_confirm = $_POST['password_confirm'] ?? '';

                // Validaciones
                if (empty($password_actual)) {
                    $errores['password_actual'] = 'Ingresa tu contraseña actual';
                }
                if (empty($password_nuevo)) {
                    $errores['password_nuevo'] = 'Ingresa la nueva contraseña';
                } elseif (strlen($password_nuevo) < 6) {
                    $errores['password_nuevo'] = 'La contraseña debe tener al menos 6 caracteres';
                }
                if ($password_nuevo !== $password_confirm) {
                    $errores['password_confirm'] = 'Las contraseñas no coinciden';
                }

                // Verificar contraseña actual — PDO
                if (empty($errores['password_actual'])) {
                    $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
                    $stmt->execute([$usuario['id']]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$row) {
                        $errores['password_actual'] = 'Usuario no encontrado';
                    } elseif (empty($row['password_hash'])) {
                        $errores['password_actual'] = 'Tu cuenta no tiene contraseña configurada. Contacta al administrador.';
                    } elseif (!password_verify($password_actual, $row['password_hash'])) {
                        $errores['password_actual'] = 'La contraseña actual no es correcta';
                    }
                }

                if (empty($errores)) {
                    $hash = password_hash($password_nuevo, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $stmt->execute([$hash, $usuario['id']]);

                    // Regenerar sesión solo si no hay output previo
                    if (!headers_sent()) {
                        session_regenerate_id(true);
                    }

                    $mensaje = 'Contraseña actualizada correctamente';
                    $tipo_mensaje = 'success';
                }
                break;

            case 'actualizar_foto':
                // TODO: Implementar subida de foto (placeholder)
                $mensaje = 'Función de foto próximamente';
                $tipo_mensaje = 'error';
                break;
        }
    } catch (Throwable $e) {
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'error';
    }
}

// Iniciales del usuario para el avatar
$nombre_partes = explode(' ', $usuario['name'] ?? 'U');
$iniciales = strtoupper(substr($nombre_partes[0], 0, 1));
if (isset($nombre_partes[1])) {
    $iniciales .= strtoupper(substr($nombre_partes[1], 0, 1));
}

// Valores del formulario: repoblar con POST si hubo error, si no con BD
$val_name     = $_POST['name']     ?? $usuario['name']     ?? '';
$val_email    = $_POST['email']    ?? $usuario['email']    ?? '';
$val_telefono = $_POST['telefono'] ?? $usuario['telefono'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Mi Perfil | Inmobiliaria MH</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="css/socios.css">
    <link rel="manifest" href="/manifest.json">
    <style>
        * { box-sizing: border-box; }

        /* ===== HEADER ===== */
        .main-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .header-left h1 {
            font-size: 1.4rem;
            color: #2d3748;
            margin: 0;
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #2d3748;
            cursor: pointer;
            padding: 5px;
            line-height: 1;
        }

        /* ===== MENSAJES ===== */
        .mensaje {
            padding: 14px 16px;
            border-radius: 10px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .mensaje.success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .mensaje.error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        /* ===== TARJETA DE PERFIL ===== */
        .perfil-hero {
            background: linear-gradient(135deg, #4c51bf 0%, #3c41a8 100%);
            border-radius: 16px;
            padding: 28px 22px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 18px;
            color: white;
            box-shadow: 0 6px 20px rgba(76, 81, 191, 0.25);
            flex-wrap: wrap;
        }

        .perfil-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            border: 3px solid rgba(255, 255, 255, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            font-weight: 700;
            flex-shrink: 0;
            position: relative;
            overflow: hidden;
        }

        .perfil-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .perfil-avatar .foto-overlay {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.2s;
            cursor: pointer;
            border-radius: 50%;
        }

        .perfil-avatar:hover .foto-overlay { opacity: 1; }

        .perfil-info { flex: 1; min-width: 0; }

        .perfil-info h2 {
            margin: 0 0 4px;
            font-size: 1.3rem;
            word-break: break-word;
        }

        .perfil-info .email {
            opacity: 0.85;
            font-size: 0.88rem;
            word-break: break-all;
        }

        .perfil-info .rol-badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 8px;
        }

        /* ===== SECCIONES ===== */
        .acceso-section {
            background: white;
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }

        .acceso-section h3 {
            color: #2d3748;
            margin: 0 0 18px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.05rem;
            padding-bottom: 12px;
            border-bottom: 1px solid #f1f5f9;
        }

        .acceso-section h3 i { color: #4c51bf; }

        /* ===== FORMULARIOS ===== */
        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 6px;
        }

        .form-group label .required { color: #e53e3e; }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: border-color 0.3s ease;
            background: #f8fafc;
            min-height: 48px;
            -webkit-appearance: none;
            appearance: none;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #4c51bf;
            background: white;
            box-shadow: 0 0 0 3px rgba(76, 81, 191, 0.1);
        }

        .form-group input.error {
            border-color: #ef4444;
            background: #fef2f2;
        }

        .form-group .error-text {
            color: #ef4444;
            font-size: 0.78rem;
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .form-group input:disabled {
            background: #f1f5f9;
            color: #94a3b8;
            cursor: not-allowed;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .hint-inline {
            font-weight: normal;
            color: #94a3b8;
            font-size: 0.78rem;
        }

        .btn-submit {
            background: linear-gradient(135deg, #4c51bf 0%, #3c41a8 100%);
            color: white;
            border: none;
            padding: 14px 24px;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
            min-height: 52px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(76, 81, 191, 0.3);
        }

        .btn-submit:active {
            transform: scale(0.98);
            box-shadow: 0 2px 6px rgba(76, 81, 191, 0.2);
        }

        .btn-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-submit.btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        /* ===== INFO CARD ===== */
        .info-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px;
            background: #f8fafc;
            border-radius: 10px;
            margin-bottom: 12px;
            border-left: 3px solid #4c51bf;
        }

        .info-card i {
            color: #4c51bf;
            font-size: 1.1rem;
            width: 20px;
            text-align: center;
        }

        .info-card .info-content {
            flex: 1;
            min-width: 0;
        }

        .info-card .info-label {
            font-size: 0.75rem;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-card .info-value {
            font-size: 0.9rem;
            color: #2d3748;
            font-weight: 600;
            word-break: break-word;
        }

        /* ===== ZONA FOTO (placeholder) ===== */
        .foto-upload-zone {
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            color: #94a3b8;
            background: #f8fafc;
            transition: all 0.2s;
            cursor: pointer;
        }

        .foto-upload-zone i {
            font-size: 2rem;
            margin-bottom: 8px;
            color: #cbd5e1;
            display: block;
        }

        .foto-upload-zone p {
            margin: 0;
            font-size: 0.85rem;
        }

        .foto-upload-zone .hint {
            font-size: 0.75rem;
            color: #cbd5e1;
            margin-top: 4px;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .main-content {
                padding: 12px;
                padding-bottom: 90px;
            }

            .menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .header-left h1 { font-size: 1.2rem; }

            .acceso-section { padding: 16px; }

            .form-group input,
            .form-group select { font-size: 16px; }

            .form-row {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .perfil-hero {
                padding: 22px 18px;
                text-align: center;
                flex-direction: column;
            }

            .perfil-info { width: 100%; }

            .perfil-info h2 { font-size: 1.15rem; }
        }

        @media (min-width: 769px) {
            .main-content { padding: 25px; }

            .btn-submit:hover {
                background: #3c41a8;
                transform: translateY(-1px);
                box-shadow: 0 6px 16px rgba(76, 81, 191, 0.35);
            }

            .acceso-section {
                max-width: 720px;
                margin-left: auto;
                margin-right: auto;
            }

            .perfil-hero {
                max-width: 720px;
                margin-left: auto;
                margin-right: auto;
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<?php include 'modulos/sidebar.php'; ?>

<main class="main-content">
    <!-- Header -->
    <div class="main-header">
        <div class="header-left">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <h1>Mi Perfil</h1>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if ($mensaje): ?>
        <div class="mensaje <?php echo $tipo_mensaje; ?>" id="mensajeAlerta">
            <i class="fas <?php echo $tipo_mensaje === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>

    <!-- Hero de perfil -->
    <div class="perfil-hero">
        <div class="perfil-avatar" title="Próximamente: subir foto">
            <?php if (!empty($usuario['foto'])): ?>
                <img src="<?php echo htmlspecialchars($usuario['foto']); ?>" alt="Foto de perfil">
            <?php else: ?>
                <?php echo $iniciales; ?>
            <?php endif; ?>
            <div class="foto-overlay" onclick="avisoFoto()">
                <i class="fas fa-camera"></i>
            </div>
        </div>
        <div class="perfil-info">
            <h2><?php echo htmlspecialchars($usuario['name'] ?? 'Usuario'); ?></h2>
            <div class="email">
                <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($usuario['email'] ?? ''); ?>
            </div>
            <span class="rol-badge">
                <i class="fas fa-user-tag"></i> <?php echo ucfirst($usuario['role'] ?? 'Usuario'); ?>
            </span>
        </div>
    </div>

    <!-- Formulario: Datos personales -->
    <div class="acceso-section">
        <h3><i class="fas fa-user-edit"></i> Datos Personales</h3>

        <form method="POST" action="">
            <input type="hidden" name="accion" value="actualizar_perfil">

            <div class="form-group">
                <label>Nombre Completo <span class="required">*</span></label>
                <input type="text" name="name" value="<?php echo htmlspecialchars($val_name); ?>"
                       class="<?php echo isset($errores['name']) ? 'error' : ''; ?>" required>
                <?php if (isset($errores['name'])): ?>
                    <div class="error-text"><i class="fas fa-exclamation-circle"></i> <?php echo $errores['name']; ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>Email <span class="required">*</span></label>
                <input type="email" name="email" value="<?php echo htmlspecialchars($val_email); ?>"
                       class="<?php echo isset($errores['email']) ? 'error' : ''; ?>" required>
                <?php if (isset($errores['email'])): ?>
                    <div class="error-text"><i class="fas fa-exclamation-circle"></i> <?php echo $errores['email']; ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>Teléfono</label>
                <input type="tel" name="telefono" value="<?php echo htmlspecialchars($val_telefono); ?>"
                       placeholder="Ej: 55 1234 5678">
            </div>

            <div class="form-group">
                <label>Rol</label>
                <input type="text" value="<?php echo ucfirst($usuario['role'] ?? 'Usuario'); ?>" disabled>
                <div class="hint-inline" style="margin-top: 6px;">
                    <i class="fas fa-info-circle"></i> El rol solo puede ser modificado por un administrador.
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fas fa-save"></i> Guardar Cambios
            </button>
        </form>
    </div>

    <!-- Formulario: Cambiar contraseña -->
    <div class="acceso-section">
        <h3><i class="fas fa-lock"></i> Cambiar Contraseña</h3>

        <form method="POST" action="" onsubmit="return validarPassword(this)">
            <input type="hidden" name="accion" value="cambiar_password">

            <div class="form-group">
                <label>Contraseña Actual <span class="required">*</span></label>
                <input type="password" name="password_actual"
                       class="<?php echo isset($errores['password_actual']) ? 'error' : ''; ?>"
                       placeholder="Tu contraseña actual" required>
                <?php if (isset($errores['password_actual'])): ?>
                    <div class="error-text"><i class="fas fa-exclamation-circle"></i> <?php echo $errores['password_actual']; ?></div>
                <?php endif; ?>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Nueva Contraseña <span class="required">*</span></label>
                    <input type="password" name="password_nuevo" id="password_nuevo"
                           class="<?php echo isset($errores['password_nuevo']) ? 'error' : ''; ?>"
                           placeholder="Mínimo 6 caracteres" minlength="6" required>
                    <?php if (isset($errores['password_nuevo'])): ?>
                        <div class="error-text"><i class="fas fa-exclamation-circle"></i> <?php echo $errores['password_nuevo']; ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label>Confirmar Contraseña <span class="required">*</span></label>
                    <input type="password" name="password_confirm" id="password_confirm"
                           class="<?php echo isset($errores['password_confirm']) ? 'error' : ''; ?>"
                           placeholder="Repite la contraseña" required>
                    <?php if (isset($errores['password_confirm'])): ?>
                        <div class="error-text"><i class="fas fa-exclamation-circle"></i> <?php echo $errores['password_confirm']; ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <button type="submit" class="btn-submit btn-danger">
                <i class="fas fa-key"></i> Actualizar Contraseña
            </button>
        </form>
    </div>

    <!-- Sección: Foto de perfil (placeholder) -->
    <div class="acceso-section">
        <h3><i class="fas fa-camera"></i> Foto de Perfil</h3>

        <div class="foto-upload-zone" onclick="avisoFoto()">
            <i class="fas fa-cloud-upload-alt"></i>
            <p>Subir foto de perfil</p>
            <div class="hint">Próximamente disponible</div>
        </div>
    </div>

    <!-- Info adicional -->
    <div class="acceso-section">
        <h3><i class="fas fa-info-circle"></i> Información de la Cuenta</h3>

        <div class="info-card">
            <i class="fas fa-hashtag"></i>
            <div class="info-content">
                <div class="info-label">ID de Usuario</div>
                <div class="info-value">#<?php echo (int)$usuario['id']; ?></div>
            </div>
        </div>

        <div class="info-card">
            <i class="fas fa-calendar-alt"></i>
            <div class="info-content">
                <div class="info-label">Fecha de Registro</div>
                <div class="info-value">
                    <?php echo date('d/m/Y H:i', strtotime($usuario['created_at'] ?? 'now')); ?>
                </div>
            </div>
        </div>

        <div class="info-card">
            <i class="fas fa-shield-alt"></i>
            <div class="info-content">
                <div class="info-label">Estado</div>
                <div class="info-value">
                    <?php echo ($usuario['activo'] ?? 1) ? 'Activo' : 'Inactivo'; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
    // ===== MENÚ MÓVIL =====
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    function toggleSidebar() {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('show');
        document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
    }

    if (menuToggle) menuToggle.addEventListener('click', toggleSidebar);
    if (overlay) overlay.addEventListener('click', toggleSidebar);

    document.querySelectorAll('.sidebar nav a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 992) toggleSidebar();
        });
    });

    // ===== VALIDACIÓN DE CONTRASEÑA =====
    function validarPassword(form) {
        const nuevo = form.querySelector('#password_nuevo');
        const confirm = form.querySelector('#password_confirm');

        if (nuevo.value.length < 6) {
            alert('La nueva contraseña debe tener al menos 6 caracteres');
            nuevo.focus();
            return false;
        }

        if (nuevo.value !== confirm.value) {
            alert('Las contraseñas no coinciden');
            confirm.focus();
            return false;
        }

        return true;
    }

    // ===== AVISO FOTO (placeholder) =====
    function avisoFoto() {
        alert('La subida de foto estará disponible próximamente.');
    }

    // ===== AUTO-CERRAR MENSAJES =====
    const mensajeAlerta = document.getElementById('mensajeAlerta');
    if (mensajeAlerta) {
        setTimeout(() => {
            mensajeAlerta.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            mensajeAlerta.style.opacity = '0';
            mensajeAlerta.style.transform = 'translateY(-10px)';
            setTimeout(() => mensajeAlerta.remove(), 300);
        }, 5000);
    }

    // ===== PREVENIR ZOOM EN DOBLE TAP =====
    let lastTouchEnd = 0;
    document.addEventListener('touchend', (e) => {
        const now = Date.now();
        if (now - lastTouchEnd <= 300) {
            e.preventDefault();
        }
        lastTouchEnd = now;
    }, { passive: false });
</script>

</body>
</html>