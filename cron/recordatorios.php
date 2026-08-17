<?php
// cron/recordatorios.php
// Script para procesar recordatorios de eventos - Ejecutar cada 5 minutos

// Configurar para ejecución por línea de comandos
if (php_sapi_name() !== 'cli') {
    // Si se accede desde navegador, mostrar mensaje
    die('Este script solo puede ejecutarse por línea de comandos o cron job');
}

// Configurar logging
date_default_timezone_set('America/Santiago'); // Ajusta según tu zona horaria

// Ruta base - ajusta según tu estructura
$base_path = dirname(__DIR__);
require_once $base_path . '/includes/conexion.php';
require_once $base_path . '/includes/calendar_functions.php';
require_once $base_path . '/includes/email_functions.php'; // Si tienes funciones de email

// Archivo de log
$log_file = $base_path . '/logs/recordatorios.log';

// Función para escribir en el log
function escribirLog($mensaje, $log_file) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $mensaje\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

try {
    escribirLog("=== INICIANDO PROCESO DE RECORDATORIOS ===", $log_file);
    
    // 1. Procesar recordatorios pendientes
    $recordatorios_enviados = procesarRecordatorios($conn);
    escribirLog("Recordatorios enviados: $recordatorios_enviados", $log_file);
    
    // 2. Verificar eventos que vencen hoy (opcional)
    verificarEventosHoy($conn, $log_file);
    
    // 3. Limpiar recordatorios antiguos (opcional)
    limpiarRecordatoriosAntiguos($conn, $log_file);
    
    escribirLog("=== PROCESO COMPLETADO ===", $log_file);
    echo date('Y-m-d H:i:s') . " - Proceso completado. Recordatorios enviados: $recordatorios_enviados\n";
    
} catch (Exception $e) {
    $error_msg = "ERROR: " . $e->getMessage();
    escribirLog($error_msg, $log_file);
    echo date('Y-m-d H:i:s') . " - " . $error_msg . "\n";
}

/**
 * Verificar eventos que vencen hoy (funcionalidad adicional)
 */
function verificarEventosHoy($conn, $log_file) {
    try {
        $sql = "SELECT e.*, p.title as property_title 
                FROM calendar_events e
                LEFT JOIN properties p ON e.property_id = p.id
                WHERE e.event_type = 'expiration'
                AND DATE(e.start_datetime) = CURDATE()
                AND e.status NOT IN ('cancelled', 'completed')";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($eventos)) {
            $mensaje = "Eventos que vencen HOY (" . count($eventos) . "):";
            escribirLog($mensaje, $log_file);
            foreach ($eventos as $evento) {
                escribirLog("  - {$evento['title']} (Propiedad: {$evento['property_title']})", $log_file);
            }
        }
        
        return count($eventos);
        
    } catch (PDOException $e) {
        escribirLog("Error al verificar eventos de hoy: " . $e->getMessage(), $log_file);
        return 0;
    }
}

/**
 * Limpiar recordatorios antiguos (más de 30 días)
 */
function limpiarRecordatoriosAntiguos($conn, $log_file) {
    try {
        $sql = "DELETE FROM event_reminders 
                WHERE sent = 1 
                AND sent_at < DATE_SUB(NOW(), INTERVAL 30 DAY)";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $eliminados = $stmt->rowCount();
        
        if ($eliminados > 0) {
            escribirLog("Recordatorios antiguos eliminados: $eliminados", $log_file);
        }
        
        return $eliminados;
        
    } catch (PDOException $e) {
        escribirLog("Error al limpiar recordatorios: " . $e->getMessage(), $log_file);
        return 0;
    }
}
?>