<?php
session_start();
require_once 'includes/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: vender.php?paso=6');
    exit;
}

$email = trim($_POST['login_email'] ?? '');
$password = $_POST['login_password'] ?? '';

if (empty($email) || empty($password)) {
    $_SESSION['login_error'] = 'Por favor completa todos los campos';
    header('Location: vender.php?paso=6');
    exit;
}

try {
    $stmt = $conn->prepare("SELECT id, nombre, email, password FROM socios WHERE email = ?");
    $stmt->execute([$email]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($usuario && password_verify($password, $usuario['password'])) {
        $_SESSION['usuario_id'] = $usuario['id'];
        $_SESSION['usuario_nombre'] = $usuario['nombre'];
        $_SESSION['usuario_email'] = $usuario['email'];
        
        // Redirigir de vuelta al wizard
        header('Location: vender.php?paso=6');
        exit;
    } else {
        $_SESSION['login_error'] = 'Email o contraseña incorrectos';
        header('Location: vender.php?paso=6');
        exit;
    }
} catch (PDOException $e) {
    $_SESSION['login_error'] = 'Error en el sistema';
    header('Location: vender.php?paso=6');
    exit;
}
?>