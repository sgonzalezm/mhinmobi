<?php
session_start();
require_once 'includes/conexion.php';
require_once 'includes/auth.php';

if (!estaLogueado()) {
    header('Location: login.php');
    exit;
}

$usuario = obtenerUsuarioActual($conn);
if (!$usuario) {
    cerrarSesion();
    header('Location: login.php');
    exit;
}

$lead_id = (int)($_GET['id'] ?? 0);

if ($lead_id <= 0) {
    $_SESSION['flash_error'] = 'Lead inválido.';
    header('Location: gestion_leads.php');
    exit;
}

try {
    // Verificar que el lead existe
    $stmt = $conn->prepare("SELECT id FROM leads_meta WHERE id = ?");
    $stmt->execute([$lead_id]);
    if (!$stmt->fetch()) {
        $_SESSION['flash_error'] = 'El lead no existe.';
        header('Location: gestion_leads.php');
        exit;
    }

    // Insertar asignación (ignora si ya existe por el UNIQUE)
    $stmt = $conn->prepare("
        INSERT INTO leads_asignados (lead_id, usuario_id, estado_seguimiento)
        VALUES (?, ?, 'nuevo')
        ON DUPLICATE KEY UPDATE fecha_ultima_actualizacion = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$lead_id, $usuario['id']]);

    // Obtener el id de la asignación para el historial
    $stmt = $conn->prepare("SELECT id FROM leads_asignados WHERE lead_id = ? AND usuario_id = ?");
    $stmt->execute([$lead_id, $usuario['id']]);
    $asignacion = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($asignacion) {
        $stmt = $conn->prepare("
            INSERT INTO leads_seguimiento_historial (asignacion_id, usuario_id, accion, detalle)
            VALUES (?, ?, 'Lead tomado', 'El usuario tomó este lead')
        ");
        $stmt->execute([$asignacion['id'], $usuario['id']]);
    }

    $_SESSION['flash_success'] = '✅ Lead asignado a tu bandeja.';
    header('Location: gestion_leads.php');
    exit;

} catch (PDOException $e) {
    error_log("Error en tomar_lead: " . $e->getMessage());
    $_SESSION['flash_error'] = 'Error al tomar el lead.';
    header('Location: gestion_leads.php');
    exit;
}