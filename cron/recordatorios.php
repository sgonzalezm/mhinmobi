<?php
// cron/recordatorios.php
// Script para procesar recordatorios - Ejecutar cada 5 minutos

// Solo permitir ejecución por CLI
if (php_sapi_name() !== 'cli') {
    die('Este script solo puede ejecutarse por línea de comandos o cron job');
}

// Configuración
date_default_timezone_set('America/Santiago');
$base_path = dirname(__DIR__);

// Cargar archivos necesarios
require_once $base_path . '/includes/conexion.php';
require_once $base_path . '/includes/calendar_functions.php';

// Archivo de log
$log_file = $base_path . '/logs/recordatorios.log';
if (!file_exists(dirname($log_file))) {
    mkdir(dirname($log_file), 0755, true);
}

function escribirLog($mensaje) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $mensaje\n", FILE_APPEND);
}

try {
    escribirLog("=== INICIANDO PROCESO DE RECORDATORIOS ===");
    
    // Procesar recordatorios pendientes
    $enviados = procesarRecordatorios($conn);
    
    escribirLog("Recordatorios enviados en esta ejecución: $enviados");
    escribirLog("=== PROCESO COMPLETADO ===");
    
    echo date('Y-m-d H:i:s') . " - Proceso completado. Recordatorios enviados: $enviados\n";
    
} catch (Exception $e) {
    $error_msg = "ERROR: " . $e->getMessage();
    escribirLog($error_msg);
    echo date('Y-m-d H:i:s') . " - " . $error_msg . "\n";
}
?>