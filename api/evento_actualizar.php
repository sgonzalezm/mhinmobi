<?php
session_start();
header('Content-Type: application/json');

require_once '../includes/conexion.php';
require_once '../includes/auth.php';
require_once '../includes/calendar_functions.php';

// Verificar autenticación
if (!estaLogueado()) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

// Obtener datos del POST
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || empty($data['evento_id'])) {
    echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
    exit;
}

$evento_id = $data['evento_id'];
unset($data['evento_id']);

// Actualizar evento
$resultado = actualizarEventoCalendario($conn, $evento_id, $data);

echo json_encode($resultado);
?>