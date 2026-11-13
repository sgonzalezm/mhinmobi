<?php
session_start();
require_once 'includes/conexion.php';
require_once 'includes/auth.php';

// ===== VERIFICAR AUTENTICACIÓN =====
if (!estaLogueado()) {
    header('Location: login.php');
    exit;
}

// ===== OBTENER USUARIO ACTUAL =====
$usuario = obtenerUsuarioActual($conn);
if (!$usuario) {
    cerrarSesion();
    header('Location: login.php');
    exit;
}

// ===== VALIDAR ID RECIBIDO =====
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id <= 0) {
    $_SESSION['flash_error'] = 'ID de evaluación inválido.';
    header('Location: php.php');
    exit;
}

// ===== ELIMINAR LA EVALUACIÓN =====
try {
    // Verificar que existe antes de borrar (opcional, para mejor UX)
    $stmt = $conn->prepare("SELECT id FROM evaluaciones_propiedades WHERE id = :id LIMIT 1");
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();

    if (!$stmt->fetch()) {
        $_SESSION['flash_error'] = 'La evaluación no existe o ya fue eliminada.';
        header('Location: php.php');
        exit;
    }

    // Eliminar
    $stmt = $conn->prepare("DELETE FROM evaluaciones_propiedades WHERE id = :id");
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();

    $_SESSION['flash_success'] = 'Evaluación eliminada correctamente.';
} catch (PDOException $e) {
    // Log interno (no mostrar al usuario)
    error_log('Error al eliminar evaluación ID ' . $id . ': ' . $e->getMessage());
    $_SESSION['flash_error'] = 'No se pudo eliminar la evaluación. Inténtalo de nuevo.';
}

// ===== REDIRIGIR DE VUELTA =====
header('Location: mensajes.php');
exit;