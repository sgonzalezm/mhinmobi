<?php
// proceso_avanzar.php - VERSIÓN CORREGIDA Y ESTABLE
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/conexion.php';
require_once 'includes/auth.php';
require_once 'includes/notificaciones.php';

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
    $_SESSION['error'] = 'ID de proceso inválido';
    header('Location: rastreabilidad.php');
    exit;
}

// Variable para controlar si hay transacción activa
$transaccion_activa = false;

try {
    // Obtener información del proceso incluyendo datos del cliente
    $stmt = $conn->prepare("
        SELECT 
            pt.current_stage,
            pt.property_id,
            p.title as property_title,
            u.id as user_id,
            u.name as cliente_nombre,
            u.email as cliente_email,
            u.telefono as cliente_telefono
        FROM property_tracking pt
        JOIN properties p ON pt.property_id = p.id
        JOIN users u ON pt.initiated_by = u.id
        WHERE pt.id = ?
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
    
    // Mapeo de etapas a nombres para notificaciones
    $mapa_etapas_notificacion = [
        'inventario' => 'iniciado',
        'contrato_compraventa' => 'contrato_compraventa',
        'poder_notarial' => 'poder_notarial',
        'credito' => 'credito',
        'compra_venta' => 'contrato_compraventa',
        'recepcion_recursos' => 'credito',
        'pagos_proveedores' => 'credito',
        'finalizado' => 'finalizado'
    ];
    
    // Obtener siguiente etapa
    $current_stage = $proceso['current_stage'];
    
    // Verificar que la etapa actual existe en el array
    if (!isset($orden_etapas[$current_stage])) {
        $_SESSION['error'] = 'Etapa actual no válida: ' . $current_stage;
        header('Location: proceso_detalle.php?id=' . $proceso_id);
        exit;
    }
    
    $current_order = $orden_etapas[$current_stage];
    $next_order = $current_order + 1;
    
    // Buscar el nombre de la siguiente etapa
    $next_stage = array_search($next_order, $orden_etapas);
    
    if (!$next_stage) {
        $_SESSION['error'] = 'El proceso ya está en la etapa final.';
        header('Location: proceso_detalle.php?id=' . $proceso_id);
        exit;
    }
    
    // INICIAR TRANSACCIÓN
    $conn->beginTransaction();
    $transaccion_activa = true;
    
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
    $es_final = false;
    if ($next_stage == 'finalizado') {
        $es_final = true;
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
    
    // CONFIRMAR TRANSACCIÓN
    $conn->commit();
    $transaccion_activa = false;
    
    // AHORA ENVIAR NOTIFICACIÓN (FUERA DE LA TRANSACCIÓN)
    $etapa_notificacion = $mapa_etapas_notificacion[$next_stage] ?? 'iniciado';
    
    if ($es_final) {
        $etapa_notificacion = 'finalizado';
    }
    
    // Enviar notificación al cliente
    $resultado_notificacion = notificarCambioEtapa($conn, $proceso_id, $etapa_notificacion);
    
    // Preparar mensaje de éxito
    $nombre_etapa = ucfirst(str_replace('_', ' ', $next_stage));
    $mensaje_exito = '✅ Etapa avanzada correctamente a "' . $nombre_etapa . '".';
    
    if ($resultado_notificacion['success']) {
        $mensaje_exito .= ' 📧 Notificación enviada al cliente.';
    } else {
        $mensaje_exito .= ' ⚠️ La etapa se avanzó pero hubo un error al enviar la notificación: ' . ($resultado_notificacion['error'] ?? 'Desconocido');
        error_log("Error en notificación para proceso {$proceso_id}: " . ($resultado_notificacion['error'] ?? ''));
    }
    
    $_SESSION['success'] = $mensaje_exito;
    header('Location: proceso_detalle.php?id=' . $proceso_id);
    exit;
    
} catch (PDOException $e) {
    // Si hay error y hay transacción activa, hacer rollback
    if ($transaccion_activa && $conn->inTransaction()) {
        try {
            $conn->rollBack();
        } catch (Exception $rollbackError) {
            error_log("Error al hacer rollback: " . $rollbackError->getMessage());
        }
    }
    
    error_log("Error al avanzar etapa: " . $e->getMessage());
    $_SESSION['error'] = 'Error al avanzar la etapa: ' . $e->getMessage();
    header('Location: proceso_detalle.php?id=' . $proceso_id);
    exit;
    
} catch (Exception $e) {
    // Si hay error y hay transacción activa, hacer rollback
    if ($transaccion_activa && $conn->inTransaction()) {
        try {
            $conn->rollBack();
        } catch (Exception $rollbackError) {
            error_log("Error al hacer rollback: " . $rollbackError->getMessage());
        }
    }
    
    error_log("Error general en proceso_avanzar: " . $e->getMessage());
    $_SESSION['error'] = 'Error al procesar la solicitud: ' . $e->getMessage();
    header('Location: proceso_detalle.php?id=' . $proceso_id);
    exit;
}
?>