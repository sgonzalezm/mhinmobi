<?php
session_start();
require_once 'includes/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: vender.php?paso=6');
    exit;
}

$nombre = trim($_POST['reg_nombre'] ?? '');
$email = trim($_POST['reg_email'] ?? '');
$password = $_POST['reg_password'] ?? '';

if (empty($nombre) || empty($email) || empty($password)) {
    $_SESSION['login_error'] = 'Por favor completa todos los campos';
    header('Location: vender.php?paso=6');
    exit;
}

if (strlen($password) < 6) {
    $_SESSION['login_error'] = 'La contraseña debe tener al menos 6 caracteres';
    header('Location: vender.php?paso=6');
    exit;
}

try {
    // Verificar si el email ya existe
    $stmt = $conn->prepare("SELECT id FROM socios WHERE email = ?");
    $stmt->execute([$email]);
    
    if ($stmt->fetch()) {
        $_SESSION['login_error'] = 'Este email ya está registrado. Inicia sesión.';
        header('Location: vender.php?paso=6');
        exit;
    }
    
    // Registrar usuario
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO socios (nombre, email, password, activo) VALUES (?, ?, ?, 1)");
    $stmt->execute([$nombre, $email, $hash]);
    
    // Obtener el ID del usuario registrado
    $id = $conn->lastInsertId();
    
    // Iniciar sesión automáticamente
    $_SESSION['usuario_id'] = $id;
    $_SESSION['usuario_nombre'] = $nombre;
    $_SESSION['usuario_email'] = $email;
    
    $_SESSION['registro_exitoso'] = '¡Registro exitoso! Ahora puedes publicar tu propiedad.';
    header('Location: vender.php?paso=6');
    exit;
    
} catch (PDOException $e) {
    $_SESSION['login_error'] = 'Error al registrar: ' . $e->getMessage();
    header('Location: vender.php?paso=6');
    exit;
}
?>