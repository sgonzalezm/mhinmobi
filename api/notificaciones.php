<?php
session_start();
require_once '../includes/conexion.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

if (!estaLogueado()) {
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

try {
    // Obtener mensajes no leídos
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_no_leidos,
            SUM(CASE WHEN priority IN ('urgent', 'high') THEN 1 ELSE 0 END) as urgentes
        FROM messages 
        WHERE receiver_id = ? 
        AND is_read = 0 
        AND is_archived = 0
    ");
    $stmt->execute([$_SESSION['usuario_id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Obtener vencimientos próximos
    $stmt2 = $conn->prepare("
        SELECT COUNT(*) as vencimientos
        FROM deadlines d
        JOIN propiedades p ON d.property_id = p.id
        WHERE d.status IN ('pending', 'approaching')
        AND d.deadline_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        AND p.socio_id = ?
    ");
    $stmt2->execute([$_SESSION['usuario_id']]);
    $vencimientos = $stmt2->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'total_no_leidos' => (int)($result['total_no_leidos'] ?? 0),
        'urgentes' => (int)($result['urgentes'] ?? 0),
        'vencimientos' => (int)($vencimientos['vencimientos'] ?? 0)
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['error' => 'Error en la base de datos']);
}
?>