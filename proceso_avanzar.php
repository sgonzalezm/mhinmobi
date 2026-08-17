<?php
// proceso_avanzar.php
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
// Obtener ID del proceso
$proceso_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($proceso_id <= 0) {
    header('Location: rastreabilidad.php');
    exit;
}

try {
    // Obtener información del proceso
    $stmt = $conn->prepare("
        SELECT current_stage
        FROM property_tracking
        WHERE id = ?
    ");
    $stmt->execute([$proceso_id]);
    $proceso = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$proceso) {
        $_SESSION['error'] = 'Proceso no encontrado.';
        header('Location: rastreabilidad.php');
        exit;
    }
    
    // Definir el orden de las etapas
    $orden_etapas = [
        'inventario' => 1,
        'contrato_compraventa' => 2,
        'poder_notarial' => 3,
        'credito' => 4,
        'compra_venta' => 5,
        'recepcion_recursos' => 6,
        'pagos_proveedores' => 7,
        'finalizado' => 8
    ];
    
    // Obtener siguiente etapa
    $current_stage = $proceso['current_stage'];
    $current_order = $orden_etapas[$current_stage];
    $next_order = $current_order + 1;
    
    // Buscar el nombre de la siguiente etapa
    $next_stage = array_search($next_order, $orden_etapas);
    
    if (!$next_stage) {
        $_SESSION['error'] = 'El proceso ya está en la etapa final.';
        header('Location: proceso_detalle.php?id=' . $proceso_id);
        exit;
    }
    
    // Iniciar transacción
    $conn->beginTransaction();
    
    // Marcar etapa actual como completada
    $stmtUpdate = $conn->prepare("
        UPDATE tracking_stages
        SET status = 'completado', completed_at = NOW()
        WHERE tracking_id = ? AND stage_name = ?
    ");
    $stmtUpdate->execute([$proceso_id, $current_stage]);
    
    // Actualizar la siguiente etapa a "en_progreso"
    $stmtNext = $conn->prepare("
        UPDATE tracking_stages
        SET status = 'en_progreso'
        WHERE tracking_id = ? AND stage_name = ?
    ");
    $stmtNext->execute([$proceso_id, $next_stage]);
    
    // Actualizar el proceso
    $stmtProcess = $conn->prepare("
        UPDATE property_tracking
        SET current_stage = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmtProcess->execute([$next_stage, $proceso_id]);
    
    // Si es la etapa final, marcar el proceso como completado
    if ($next_stage == 'finalizado') {
        $stmtFinal = $conn->prepare("
            UPDATE property_tracking
            SET status = 'completado'
            WHERE id = ?
        ");
        $stmtFinal->execute([$proceso_id]);
        
        // Actualizar el estado de la propiedad
        $stmtProperty = $conn->prepare("
            UPDATE properties p
            SET p.status = 'vendido'
            WHERE p.id = (
                SELECT property_id FROM property_tracking WHERE id = ?
            )
        ");
        $stmtProperty->execute([$proceso_id]);
    }
    
    // Confirmar transacción
    $conn->commit();
    
    $_SESSION['success'] = 'Etapa avanzada correctamente.';
    header('Location: proceso_detalle.php?id=' . $proceso_id);
    exit;
    
} catch (PDOException $e) {
    $conn->rollBack();
    error_log("Error al avanzar etapa: " . $e->getMessage());
    $_SESSION['error'] = 'Error al avanzar la etapa: ' . $e->getMessage();
    header('Location: proceso_detalle.php?id=' . $proceso_id);
    exit;
}
?>