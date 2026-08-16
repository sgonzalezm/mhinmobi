<?php
session_start();
require_once 'includes/conexion.php';
require_once 'includes/auth.php';

// Si ya está logueado, redirigir
if (estaLogueado()) {
    if (esAdmin()) {
        header('Location: accesos.php');
    } else {
        header('Location: dashboard.php');
    }
    exit;
}

$error = '';
$email_guardado = '';

// Procesar login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']) ? true : false;
    
    if (empty($email) || empty($password)) {
        $error = 'Por favor, completa todos los campos';
    } else {
        // Autenticar usuario
        $usuario = autenticarUsuario($email, $password, $conn);
        
        if ($usuario) {
            // Iniciar sesión
            iniciarSesion($usuario);
            
            // Registrar acceso exitoso
            registrarAccesoExitoso($conn, $usuario['id'], $_SERVER['REMOTE_ADDR']);
            
            // Redirigir según rol
            if ($usuario['role'] === 'admin') {
                header('Location: accesos.php');
            } else {
                header('Location: dashboard.php');
            }
            exit;
        } else {
            $error = 'Credenciales incorrectas o usuario inactivo';
            registrarIntentoFallido($conn, $email, $_SERVER['REMOTE_ADDR']);
            $email_guardado = $email;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso al Sistema | Inmobiliaria MH</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* Estilos del login (mantén los que ya tenías) */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-wrapper {
            width: 100%;
            max-width: 480px;
        }
        .login-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideUp 0.6s ease;
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .login-logo {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: #2d3748;
            font-weight: 700;
            font-size: 1.5rem;
        }
        .login-logo i {
            color: #667eea;
            font-size: 2rem;
        }
        .login-subtitle {
            color: #718096;
            margin: 10px 0 15px;
            font-size: 0.95rem;
        }
        .status-badge {
            display: inline-block;
            background: #ebf4ff;
            color: #4c51bf;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.95rem;
        }
        .alert-error {
            background: #fed7d7;
            color: #9b2c2c;
            border: 1px solid #feb2b2;
        }
        .alert-success {
            background: #c6f6d5;
            color: #22543d;
            border: 1px solid #9ae6b4;
        }
        .form-group {
            margin-bottom: 22px;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 6px;
            font-size: 0.9rem;
        }
        .required {
            color: #e53e3e;
        }
        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }
        .input-icon {
            position: absolute;
            left: 14px;
            color: #a0aec0;
            font-size: 1rem;
        }
        .input-group input {
            width: 100%;
            padding: 12px 40px 12px 44px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
            background: #f7fafc;
        }
        .input-group input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
        }
        .input-group input.error {
            border-color: #fc8181;
            background: #fff5f5;
        }
        .toggle-password {
            position: absolute;
            right: 12px;
            background: none;
            border: none;
            color: #a0aec0;
            cursor: pointer;
            font-size: 1rem;
            padding: 6px;
        }
        .toggle-password:hover {
            color: #4a5568;
        }
        .input-error {
            color: #e53e3e;
            font-size: 0.85rem;
            margin-top: 5px;
            display: none;
        }
        .input-error.show {
            display: block;
        }
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 20px 0 25px;
            font-size: 0.9rem;
        }
        .form-options label {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #4a5568;
            cursor: pointer;
        }
        .form-options a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        .form-options a:hover {
            text-decoration: underline;
        }
        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .btn-login:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
        }
        .btn-login:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        .btn-login .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        .btn-login.loading .btn-text {
            display: none;
        }
        .btn-login.loading .spinner {
            display: inline-block;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .divider {
            text-align: center;
            color: #a0aec0;
            margin: 25px 0;
            position: relative;
        }
        .divider::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            width: 45%;
            height: 1px;
            background: #e2e8f0;
        }
        .divider::after {
            content: '';
            position: absolute;
            right: 0;
            top: 50%;
            width: 45%;
            height: 1px;
            background: #e2e8f0;
        }
        .login-footer {
            text-align: center;
            color: #4a5568;
            font-size: 0.95rem;
        }
        .login-footer a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        .login-footer a:hover {
            text-decoration: underline;
        }
        .back-home {
            display: inline-block;
            margin-top: 12px;
            color: #718096 !important;
            font-weight: normal !important;
        }
        .back-home:hover {
            color: #4a5568 !important;
        }
        @media (max-width: 640px) {
            .login-card {
                padding: 30px 20px;
            }
            .login-logo {
                font-size: 1.2rem;
            }
            .form-options {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    <div class="login-card">
        <!-- Header -->
        <div class="login-header">
            <a href="index.php" class="login-logo">
                <i class="fas fa-building"></i>
                <span>INMOBILIARIA MH</span>
            </a>
            <p class="login-subtitle">Acceso exclusivo para colaboradores</p>
            <span class="status-badge">
                <i class="fas fa-shield-alt"></i> Área Segura
            </span>
        </div>

        <!-- Mensajes de error -->
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Formulario -->
        <form id="loginForm" method="POST" novalidate>
            <div class="form-group">
                <label for="email">
                    Correo Electrónico <span class="required">*</span>
                </label>
                <div class="input-group">
                    <i class="fas fa-envelope input-icon"></i>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        placeholder="tu@email.com" 
                        value="<?php echo htmlspecialchars($email_guardado); ?>"
                        required
                        autocomplete="email"
                    >
                </div>
                <div class="input-error" id="emailError">Por favor ingresa un email válido</div>
            </div>

            <div class="form-group">
                <label for="password">
                    Contraseña <span class="required">*</span>
                </label>
                <div class="input-group">
                    <i class="fas fa-lock input-icon"></i>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        placeholder="••••••••" 
                        required
                        autocomplete="current-password"
                    >
                    <button type="button" class="toggle-password" id="togglePassword" aria-label="Mostrar contraseña">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <div class="input-error" id="passwordError">La contraseña debe tener al menos 6 caracteres</div>
            </div>

            <div class="form-options">
                <label for="remember">
                    <input type="checkbox" id="remember" name="remember">
                    Recordarme
                </label>
                <a href="recuperar_password.php">
                    <i class="fas fa-key"></i> ¿Olvidaste tu contraseña?
                </a>
            </div>

            <button type="submit" class="btn-login" id="btnLogin">
                <span class="btn-text">
                    <i class="fas fa-sign-in-alt"></i> Iniciar Sesión
                </span>
                <span class="spinner"></span>
            </button>
        </form>

        <div class="divider">o</div>

        <!-- Footer -->
        <div class="login-footer">
            <p>
                ¿No tienes cuenta? <a href="registro.php">Regístrate aquí</a>
            </p>
            <a href="index.php" class="back-home">
                <i class="fas fa-arrow-left"></i> Volver al inicio
            </a>
        </div>
    </div>
</div>

<script>
    // Mostrar/Ocultar Contraseña
    document.getElementById('togglePassword').addEventListener('click', function() {
        const passwordInput = document.getElementById('password');
        const icon = this.querySelector('i');
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            passwordInput.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    });

    // Validación en tiempo real
    const form = document.getElementById('loginForm');
    const emailInput = document.getElementById('email');
    const passwordInput = document.getElementById('password');
    const emailError = document.getElementById('emailError');
    const passwordError = document.getElementById('passwordError');

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    emailInput.addEventListener('blur', function() {
        if (this.value && !isValidEmail(this.value)) {
            this.classList.add('error');
            emailError.classList.add('show');
        } else {
            this.classList.remove('error');
            emailError.classList.remove('show');
        }
    });

    passwordInput.addEventListener('blur', function() {
        if (this.value && this.value.length < 6) {
            this.classList.add('error');
            passwordError.textContent = 'La contraseña debe tener al menos 6 caracteres';
            passwordError.classList.add('show');
        } else if (!this.value) {
            this.classList.add('error');
            passwordError.textContent = 'La contraseña es obligatoria';
            passwordError.classList.add('show');
        } else {
            this.classList.remove('error');
            passwordError.classList.remove('show');
        }
    });

    // Validación al enviar
    form.addEventListener('submit', function(e) {
        let hasError = false;

        if (!emailInput.value || !isValidEmail(emailInput.value)) {
            emailInput.classList.add('error');
            emailError.textContent = 'Por favor ingresa un email válido';
            emailError.classList.add('show');
            hasError = true;
        }

        if (!passwordInput.value || passwordInput.value.length < 6) {
            passwordInput.classList.add('error');
            passwordError.textContent = 'La contraseña debe tener al menos 6 caracteres';
            passwordError.classList.add('show');
            hasError = true;
        }

        if (hasError) {
            e.preventDefault();
            const firstError = document.querySelector('.input-group .error');
            if (firstError) {
                firstError.closest('.form-group').scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        } else {
            const btn = document.getElementById('btnLogin');
            btn.classList.add('loading');
            btn.disabled = true;
        }
    });

    // Guardar email si se selecciona "Recordarme"
    document.getElementById('remember').addEventListener('change', function() {
        if (this.checked) {
            localStorage.setItem('remembered_email', document.getElementById('email').value);
        } else {
            localStorage.removeItem('remembered_email');
        }
    });

    // Recuperar email guardado
    document.addEventListener('DOMContentLoaded', function() {
        const savedEmail = localStorage.getItem('remembered_email');
        if (savedEmail) {
            document.getElementById('email').value = savedEmail;
            document.getElementById('remember').checked = true;
        }
    });
</script>

</body>
</html>