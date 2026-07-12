<?php
session_start();

// Si ya está logueado, redirigir al panel
if (isset($_SESSION['socio_id']) && isset($_SESSION['socio_nombre'])) {
    header('Location: ../socios/socios_panel.php');
    exit;
}

// Verificar si hay mensaje de error desde login_procesar.php
$error = $_SESSION['login_error'] ?? '';
$email_guardado = $_SESSION['login_email'] ?? '';
unset($_SESSION['login_error']);
unset($_SESSION['login_email']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
    <title>Acceso Socios | Inmobiliaria MH</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
            <p class="login-subtitle">Acceso exclusivo para socios y colaboradores</p>
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

        <?php if (isset($_GET['registro']) && $_GET['registro'] === 'exitoso'): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                ¡Registro exitoso! Ahora puedes iniciar sesión.
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['cerrar']) && $_GET['cerrar'] === 'ok'): ?>
            <div class="alert alert-success">
                <i class="fas fa-sign-out-alt"></i>
                Has cerrado sesión correctamente.
            </div>
        <?php endif; ?>

        <!-- Formulario -->
        <form id="loginForm" action="login_procesar.php" method="POST" novalidate>
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
                <div class="input-error" id="passwordError">La contraseña es obligatoria</div>
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
                ¿No tienes cuenta? <a href="registro_socio.php">Regístrate aquí</a>
            </p>
            <a href="index.php" class="back-home">
                <i class="fas fa-arrow-left"></i> Volver al inicio
            </a>
            <br>
            <small style="color: #999; font-size: 0.75rem; display: block; margin-top: 10px;">
                <i class="fas fa-lock"></i> Conexión segura SSL
            </small>
        </div>
    </div>
</div>

<script>
    // ===== Mostrar/Ocultar Contraseña =====
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

    // ===== Validación en tiempo real =====
    const form = document.getElementById('loginForm');
    const emailInput = document.getElementById('email');
    const passwordInput = document.getElementById('password');
    const emailError = document.getElementById('emailError');
    const passwordError = document.getElementById('passwordError');

    // Validar email
    emailInput.addEventListener('blur', function() {
        if (this.value && !isValidEmail(this.value)) {
            this.classList.add('error');
            emailError.classList.add('show');
        } else {
            this.classList.remove('error');
            emailError.classList.remove('show');
        }
    });

    emailInput.addEventListener('input', function() {
        if (this.classList.contains('error') && isValidEmail(this.value)) {
            this.classList.remove('error');
            emailError.classList.remove('show');
        }
    });

    // Validar password
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

    passwordInput.addEventListener('input', function() {
        if (this.classList.contains('error') && this.value.length >= 6) {
            this.classList.remove('error');
            passwordError.classList.remove('show');
        }
    });

    // ===== Validación al enviar =====
    form.addEventListener('submit', function(e) {
        let hasError = false;

        // Validar email
        if (!emailInput.value || !isValidEmail(emailInput.value)) {
            emailInput.classList.add('error');
            emailError.textContent = 'Por favor ingresa un email válido';
            emailError.classList.add('show');
            hasError = true;
        }

        // Validar password
        if (!passwordInput.value || passwordInput.value.length < 6) {
            passwordInput.classList.add('error');
            passwordError.textContent = 'La contraseña debe tener al menos 6 caracteres';
            passwordError.classList.add('show');
            hasError = true;
        }

        if (hasError) {
            e.preventDefault();
            // Scroll al primer error
            const firstError = document.querySelector('.input-group .error');
            if (firstError) {
                firstError.closest('.form-group').scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        } else {
            // Mostrar loading
            const btn = document.getElementById('btnLogin');
            btn.classList.add('loading');
            btn.disabled = true;
        }
    });

    // ===== Función para validar email =====
    function isValidEmail(email) {
        const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return regex.test(email);
    }

    // ===== Prevenir submit duplicado =====
    let submitted = false;
    form.addEventListener('submit', function() {
        if (submitted) {
            e.preventDefault();
            return;
        }
        submitted = true;
        setTimeout(() => { submitted = false; }, 3000);
    });

    // ===== Recuperar email guardado =====
    document.addEventListener('DOMContentLoaded', function() {
        const savedEmail = localStorage.getItem('remembered_email');
        if (savedEmail && document.getElementById('remember')) {
            document.getElementById('email').value = savedEmail;
            document.getElementById('remember').checked = true;
        }
    });

    // ===== Guardar email si se selecciona "Recordarme" =====
    document.getElementById('remember').addEventListener('change', function() {
        if (this.checked) {
            localStorage.setItem('remembered_email', document.getElementById('email').value);
        } else {
            localStorage.removeItem('remembered_email');
        }
    });

    // ===== Mensaje de bienvenida con animación =====
    document.addEventListener('DOMContentLoaded', function() {
        const card = document.querySelector('.login-card');
        card.style.animation = 'slideUp 0.6s ease';
    });
</script>

</body>
</html>