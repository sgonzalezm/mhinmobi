<?php
date_default_timezone_set('America/Mexico_City');
// Configuración de la base de datos
define('DB_HOST', 'localhost');
define('DB_NAME', 'u918498641_proptech_db');
define('DB_USER', 'u918498641_proptech_db');
define('DB_PASS', '3Lk28$.n37');

try {
    $conn = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    $conn->exec("SET time_zone = '-06:00'");
    
} catch (PDOException $e) {
    // En desarrollo mostrar error, en producción loguear
    die('Error de conexión: ' . $e->getMessage());
}

// ═══════════════════════════════════════════════════════════
// 🎯 CONTEXTO DE AUDITORÍA
// Pasa usuario + IP + User-Agent de PHP → variables MySQL
// para que los triggers de activity_logs los capturen.
// ═══════════════════════════════════════════════════════════
try {
    // 👤 Usuario logueado (NULL si es visitante)
    $uid = (isset($_SESSION['usuario_id']) && is_numeric($_SESSION['usuario_id']))
        ? (int)$_SESSION['usuario_id']
        : null;
    $conn->prepare("SET @current_user_id = ?")->execute([$uid]);

    // 🌐 IP del cliente (soporta proxies / Cloudflare / balanceadores)
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']       // Cloudflare
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']       // Proxies
        ?? $_SERVER['HTTP_X_REAL_IP']             // Nginx proxy
        ?? $_SERVER['REMOTE_ADDR']                // Directo
        ?? null;

    // Si vienen múltiples IPs separadas por coma, tomar la primera
    if ($ip && strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }
    $conn->prepare("SET @current_ip = ?")->execute([$ip]);

    // 🖥️ User Agent (limitado a 255 chars por el VARCHAR)
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $conn->prepare("SET @current_user_agent = ?")->execute([$ua]);

} catch (PDOException $e) {
    // ⚠️ NUNCA detener la app por un fallo de auditoría
    // Solo loguear silenciosamente y continuar
    error_log('[Audit Context] ' . $e->getMessage());
}
?>