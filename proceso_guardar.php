<?php
// proceso_guardar.php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/conexion.php';
require_once 'includes/auth.php';

// Verificar autenticación
if (!estaLogueado()) {
    header('Location: login.php');
    exit;
}

// Obtener datos del usuario
$usuario = obtenerUsuarioActual($conn);
if (!$usuario) {
    cerrarSesion();
    header('Location: login.php');
    exit;
}

// Verificar que es una petición POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: proceso_nuevo.php');
    exit;
}

// Obtener datos del formulario
$property_id = intval($_POST['property_id'] ?? 0);
$notas = trim($_POST['notas'] ?? '');
$user_id = $_SESSION['usuario_id'] ?? 0;

if ($property_id <= 0 || $user_id <= 0) {
    $_SESSION['error'] = 'Datos inválidos para iniciar el proceso.';
    header('Location: proceso_nuevo.php');
    exit;
}

try {
    // Iniciar transacción
    $conn->beginTransaction();
    
    // Verificar que la propiedad no tenga un proceso activo
    $stmtCheck = $conn->prepare("
        SELECT id FROM property_tracking 
        WHERE property_id = ? AND status = 'activo'
    ");
    $stmtCheck->execute([$property_id]);
    
    if ($stmtCheck->fetch()) {
        $conn->rollBack();
        $_SESSION['error'] = 'La propiedad ya tiene un proceso activo.';
        header('Location: proceso_nuevo.php');
        exit;
    }
    
    // Crear el proceso
    $stmt = $conn->prepare("
        INSERT INTO property_tracking 
        (property_id, current_stage, status, initiated_by, initiated_at, updated_at)
        VALUES (?, 'inventario', 'activo', ?, NOW(), NOW())
    ");
    $stmt->execute([$property_id, $user_id]);
    
    $tracking_id = $conn->lastInsertId();
    
    // Definir las etapas del proceso
    $etapas = [
        ['inventario', 1, 'en_progreso'],
        ['contrato_compraventa', 2, 'pendiente'],
        ['poder_notarial', 3, 'pendiente'],
        ['credito', 4, 'pendiente'],
        ['compra_venta', 5, 'pendiente'],
        ['recepcion_recursos', 6, 'pendiente'],
        ['pagos_proveedores', 7, 'pendiente'],
        ['finalizado', 8, 'pendiente']
    ];
    
    // Insertar etapas
    $stmtEtapas = $conn->prepare("
        INSERT INTO tracking_stages 
        (tracking_id, stage_name, stage_order, status, notes, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    
    foreach ($etapas as $etapa) {
        $etapa_notes = ($etapa[0] == 'inventario' && !empty($notas)) ? $notas : '';
        $stmtEtapas->execute([$tracking_id, $etapa[0], $etapa[1], $etapa[2], $etapa_notes]);
    }
    
    // Confirmar transacción
    $conn->commit();
    
    $_SESSION['success'] = 'Proceso iniciado correctamente.';
    header('Location: proceso_detalle.php?id=' . $tracking_id);
    exit;
    
} catch (PDOException $e) {
    $conn->rollBack();
    error_log("Error al crear proceso: " . $e->getMessage());
    $_SESSION['error'] = 'Error al crear el proceso: ' . $e->getMessage();
    header('Location: proceso_nuevo.php');
    exit;
}
?>