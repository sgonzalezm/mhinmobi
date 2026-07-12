<?php
session_start();

// Verificar que existan datos
if (!isset($_SESSION['form_venta']) || empty($_SESSION['form_venta'])) {
    header('Location: vender.php');
    exit();
}

$data = $_SESSION['form_venta'];

// Aquí procesarías los datos finales (guardar en BD, enviar email, etc.)
// Ejemplo de guardado en base de datos...

// Limpiar sesión después de procesar
// session_destroy();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/wizard.css">
    <title>¡Propiedad Publicada!</title>
</head>
<body>
    <div class="success-container">
        <div class="success-icon">✅</div>
        <h1>¡Propiedad Publicada!</h1>
        <p>Tu propiedad ha sido registrada exitosamente. Pronto será revisada y publicada.</p>
        
        <div class="details">
            <div class="details-item">
                <strong>Título:</strong>
                <span><?php echo htmlspecialchars($data['titulo'] ?? 'N/A'); ?></span>
            </div>
            <div class="details-item">
                <strong>Precio:</strong>
                <span>$<?php echo number_format($data['precio'] ?? 0, 2); ?></span>
            </div>
            <div class="details-item">
                <strong>Operación:</strong>
                <span><?php echo ucfirst(htmlspecialchars($data['tipo_operacion'] ?? 'N/A')); ?></span>
            </div>
            <div class="details-item">
                <strong>Ubicación:</strong>
                <span><?php echo htmlspecialchars($data['ubicacion'] ?? 'N/A'); ?></span>
            </div>
        </div>
        
        <a href="vender.php" class="btn">➕ Publicar otra propiedad</a>
    </div>
</body>
</html>