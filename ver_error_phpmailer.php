<?php
// ver_log_phpmailer.php
session_start();

require_once 'includes/conexion.php';
require_once 'includes/auth.php';

if (!estaLogueado()) {
    header('Location: login.php');
    exit;
}

echo "<h2>🔍 Ver Logs de PHPMailer</h2>";

// Ver archivo de errores
$log_file = __DIR__ . '/errores_phpmailer.log';
echo "<h3>📄 errores_phpmailer.log:</h3>";
if (file_exists($log_file)) {
    echo "<pre style='background: #f5f5f5; padding: 15px; border-radius: 5px; max-height: 400px; overflow: auto; border: 1px solid #ddd;'>";
    echo htmlspecialchars(file_get_contents($log_file));
    echo "</pre>";
} else {
    echo "<p style='color: orange;'>⚠️ No existe el archivo errores_phpmailer.log</p>";
}

// Ver logs de la base de datos con errores
echo "<h3>📊 Logs de Correos con Errores:</h3>";
$stmt = $conn->prepare("
    SELECT * FROM logs_correos 
    WHERE estado_envio = 'fallido'
    ORDER BY fecha_envio DESC 
    LIMIT 10
");
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($logs) {
    echo "<table border='1' cellpadding='8' style='border-collapse:collapse;'>";
    echo "<tr style='background:#f0f0f0;'>";
    echo "<th>ID</th><th>Proceso</th><th>Email</th><th>Etapa</th><th>Fecha</th>";
    echo "</tr>";
    foreach ($logs as $log) {
        echo "<tr>";
        echo "<td>" . $log['id'] . "</td>";
        echo "<td>" . $log['proceso_id'] . "</td>";
        echo "<td>" . $log['email_cliente'] . "</td>";
        echo "<td>" . $log['etapa'] . "</td>";
        echo "<td>" . $log['fecha_envio'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No hay logs con errores</p>";
}

// Ver error_log de PHP
echo "<h3>📄 Error Log de PHP (últimas 20 líneas):</h3>";
$php_error_log = ini_get('error_log');
if ($php_error_log && file_exists($php_error_log)) {
    $contenido = file($php_error_log);
    $ultimas = array_slice($contenido, -20);
    echo "<pre style='background: #f5f5f5; padding: 15px; border-radius: 5px; max-height: 300px; overflow: auto; border: 1px solid #ddd;'>";
    echo htmlspecialchars(implode('', $ultimas));
    echo "</pre>";
} else {
    echo "<p style='color: orange;'>⚠️ No se pudo leer el error_log de PHP</p>";
}

// 🔥 BOTÓN PARA LIMPIAR LOGS
echo "<h3>🛠️ Acciones:</h3>";
echo "<form method='POST'>";
echo "<button type='submit' name='limpiar_logs' style='padding: 10px 20px; background: #ef4444; color: white; border: none; border-radius: 5px; cursor: pointer;'>";
echo "🧹 Limpiar logs de error";
echo "</button>";
echo "</form>";

if (isset($_POST['limpiar_logs'])) {
    if (file_exists($log_file)) {
        unlink($log_file);
        echo "<p style='color: green;'>✅ Logs limpiados</p>";
    }
    // También limpiar logs de la BD
    $stmt = $conn->prepare("DELETE FROM logs_correos WHERE estado_envio = 'fallido'");
    $stmt->execute();
    echo "<p style='color: green;'>✅ Logs de la BD limpiados</p>";
}
?>