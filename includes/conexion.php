<?php
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
} catch (PDOException $e) {
    // En desarrollo mostrar error, en producción loguear
    die('Error de conexión: ' . $e->getMessage());
}
?>