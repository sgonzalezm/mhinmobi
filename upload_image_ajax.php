<?php
// ========================================
// upload_image_ajax.php
// ========================================
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_image') {
    
    if (!isset($_FILES['imagen']) || $_FILES['imagen']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'No se recibió ninguna imagen o hubo un error']);
        exit();
    }
    
    $file = $_FILES['imagen'];
    $upload_dir = 'uploads/propiedades/';
    
    // Crear directorio si no existe
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    // Validar tipo
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $file_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($file_type, $allowed_types)) {
        echo json_encode(['success' => false, 'error' => 'Formato de imagen no soportado. Usa JPG, PNG, GIF o WEBP']);
        exit();
    }
    
    // Validar tamaño
    if ($file['size'] > $max_size) {
        echo json_encode(['success' => false, 'error' => 'La imagen excede el tamaño máximo de 5MB']);
        exit();
    }
    
    // Generar nombre único
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '.' . $extension;
    $filepath = $upload_dir . $filename;
    
    // Mover archivo
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        echo json_encode(['success' => true, 'filepath' => $filepath]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Error al mover el archivo']);
    }
    exit();
}

echo json_encode(['success' => false, 'error' => 'Solicitud inválida']);
exit();
?>