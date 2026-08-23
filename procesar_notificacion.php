<?php
// procesar_notificacion.php
session_start();
header('Content-Type: application/json');

require_once 'includes/conexion.php';
require_once 'includes/auth.php';
require_once 'includes/notificaciones.php';

// Verificar autenticación
if (!estaLogueado()) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

// Obtener datos del POST
$data = json_decode(file_get_contents('php://input'), true);
$proceso_id = $data['proceso_id'] ?? 0;
$etapa = $data['etapa'] ?? '';

if (!$proceso_id || !$etapa) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
    exit;
}

// Verificar que la etapa existe en el sistema
$etapas_validas = ['iniciado', 'documentacion', 'credito', 'credito_preautorizado', 
                   'contrato_compraventa', 'poder_notarial', 'finalizado'];
                   
if (!in_array($etapa, $etapas_validas)) {
    echo json_encode(['success' => false, 'error' => 'Etapa no válida']);
    exit;
}

// Enviar notificación
$resultado = notificarCambioEtapa($conn, $proceso_id, $etapa);

echo json_encode($resultado);
?>