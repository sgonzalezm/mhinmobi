<?php
session_start();
require_once 'includes/conexion.php';

// Ver qué llegó a MySQL
$r = $conn->query("
    SELECT 
        @current_user_id AS uid,
        @current_ip AS ip,
        @current_user_agent AS ua
")->fetch();

echo '<pre>';
print_r($r);
echo '</pre>';

// Tu query normal sigue funcionando:
$test = $conn->query("SELECT COUNT(*) AS total FROM users")->fetch();
echo "Usuarios: " . $test['total'];