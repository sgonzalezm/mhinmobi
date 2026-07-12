<?php
session_start();
require_once 'includes/conexion.php';

// Verificar que sea POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

// Obtener datos del formulario
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

// Validar que no estén vacíos
if (empty($email) || empty($password)) {
    $_SESSION['login_error'] = 'Por favor completa todos los campos.';
    $_SESSION['login_email'] = $email;
    header('Location: login.php');
    exit;
}

try {
    // Buscar usuario por Email en la tabla
    $stmt = $conn->prepare("SELECT id, nombre, email, password, activo FROM socios WHERE email = ?");
    $stmt->execute([$email]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    // Verificar si existe y la contraseña coincide
    if ($usuario && password_verify($password, $usuario['password'])) {
        // Iniciar sesión con los datos del usuario
        $_SESSION['usuario_id'] = $usuario['id'];
        $_SESSION['usuario_nombre'] = $usuario['nombre'];
        $_SESSION['usuario_email'] = $usuario['email'];
        $_SESSION['usuario_activo'] = $usuario['activo'];

        // Limpiar errores y redirigir al panel
        unset($_SESSION['login_error']);
        unset($_SESSION['login_email']);
        header('Location: socios_panel.php');
        exit;
    } else {
        // Credenciales incorrectas
        $_SESSION['login_error'] = 'Email o contraseña incorrectos. Verifica tus datos.';
        $_SESSION['login_email'] = $email;
        header('Location: login.php');
        exit;
    }

} catch (PDOException $e) {
    error_log('Error en login: ' . $e->getMessage());
    $_SESSION['login_error'] = 'Error en el sistema. Por favor intenta más tarde.';
    header('Location: login.php');
    exit;
}
?>