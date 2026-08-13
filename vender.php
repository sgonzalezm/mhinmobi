<?php
session_start();

// ========================================
// INCLUIR FUNCIONES DE BASE DE DATOS
// ========================================
require_once 'guardar_propiedad.php';
require_once 'includes/conexion.php';

// Inicializar sesión
if (!isset($_SESSION['form_venta'])) {
    $_SESSION['form_venta'] = [];
}

// Determinar el paso actual
$paso = isset($_GET['paso']) ? (int)$_GET['paso'] : 1;
$paso = max(1, min(5, $paso));

// ========================================
// OBTENER ACCESORIOS Y BANCOS
// ========================================
$accesorios_disponibles = obtenerAccesorios();
$bancos_disponibles = obtenerBancos();

// ========================================
// PROCESAR POST
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errores = [];
    $paso_actual = isset($_POST['paso_actual']) ? (int)$_POST['paso_actual'] : 1;
    
    // Guardar datos del formulario
    foreach ($_POST as $key => $value) {
        $excluir = ['siguiente_paso', 'action', 'paso_actual', 'confirmar', 
                    'login_email', 'login_password', 'reg_nombre', 'reg_email', 'reg_password'];
        
        if (!in_array($key, $excluir)) {
            if (is_array($value)) {
                $_SESSION['form_venta'][$key] = array_map('htmlspecialchars', $value);
            } else {
                $_SESSION['form_venta'][$key] = htmlspecialchars(trim($value));
            }
        }
    }
    
    // Procesar imágenes
    if (isset($_POST['imagenes_guardadas'])) {
        $imagenes = json_decode($_POST['imagenes_guardadas'], true);
        if (is_array($imagenes) && !empty($imagenes)) {
            $_SESSION['form_venta']['imagenes'] = $imagenes;
        }
    }
    
    // Procesar accesorios
    if (isset($_POST['accesorios']) && is_array($_POST['accesorios'])) {
        $_SESSION['form_venta']['accesorios'] = array_map('intval', $_POST['accesorios']);
    } else {
        $_SESSION['form_venta']['accesorios'] = [];
    }
    
    if (isset($_POST['accesorio_otro']) && !empty(trim($_POST['accesorio_otro']))) {
        $_SESSION['form_venta']['accesorio_otro'] = trim($_POST['accesorio_otro']);
    } else {
        unset($_SESSION['form_venta']['accesorio_otro']);
    }
    
    // Validaciones
    switch ($paso_actual) {
        case 1:
            if (empty($_SESSION['form_venta']['titulo'])) $errores[] = 'El título es obligatorio';
            if (empty($_SESSION['form_venta']['precio']) || !is_numeric($_SESSION['form_venta']['precio'])) $errores[] = 'El precio debe ser un número válido';
            if (empty($_SESSION['form_venta']['tipo_operacion'])) $errores[] = 'Selecciona el tipo de operación';
            if (empty($_SESSION['form_venta']['tipo_vivienda'])) $errores[] = 'Selecciona el tipo de vivienda';
            
            if ($_SESSION['form_venta']['tipo_vivienda'] == 'casa') {
                if (empty($_SESSION['form_venta']['tipo_casa'])) $errores[] = 'Selecciona el tipo de casa';
                if ($_SESSION['form_venta']['tipo_casa'] == 'duplex' && empty($_SESSION['form_venta']['nivel_duplex'])) {
                    $errores[] = 'Selecciona el nivel del dúplex';
                }
            }
            if ($_SESSION['form_venta']['tipo_vivienda'] == 'departamento' && empty($_SESSION['form_venta']['nivel_departamento'])) {
                $errores[] = 'Selecciona el nivel del departamento';
            }
            break;
            
        case 2:
            if (empty($_SESSION['form_venta']['m2']) || !is_numeric($_SESSION['form_venta']['m2'])) $errores[] = 'Los metros cuadrados deben ser un número válido';
            if (empty($_SESSION['form_venta']['recamaras']) || !is_numeric($_SESSION['form_venta']['recamaras'])) $errores[] = 'El número de recámaras debe ser un número válido';
            if (empty($_SESSION['form_venta']['ubicacion'])) $errores[] = 'La ubicación es obligatoria';
            break;
            
        case 3:
            if (isset($_SESSION['form_venta']['tiene_adeudo']) && $_SESSION['form_venta']['tiene_adeudo'] == 1) {
                if (empty($_SESSION['form_venta']['tipo_adeudo'])) $errores[] = 'Selecciona el tipo de adeudo';
                if ($_SESSION['form_venta']['tipo_adeudo'] == 'banco' && empty($_SESSION['form_venta']['banco_id'])) {
                    $errores[] = 'Selecciona el banco';
                }
                if (empty($_SESSION['form_venta']['monto_adeudo']) || !is_numeric($_SESSION['form_venta']['monto_adeudo'])) {
                    $errores[] = 'El monto del adeudo debe ser un número válido';
                }
                if (empty($_SESSION['form_venta']['tipo_adeudo_propiedad'])) {
                    $errores[] = 'Selecciona si el adeudo es individual o compartido';
                }
            }
            break;
    }
    
    // Login
    if (isset($_POST['login_email']) && isset($_POST['login_password'])) {
        $socio = verificarLogin($_POST['login_email'], $_POST['login_password']);
        if ($socio) {
            $_SESSION['usuario_id'] = $socio['id'];
            $_SESSION['usuario_nombre'] = $socio['name'];
            $_SESSION['usuario_email'] = $socio['email'];
            $_SESSION['usuario_role'] = $socio['role'];
            header("Location: vender.php?paso=5&login=success");
            exit();
        } else {
            $errores[] = 'Credenciales incorrectas.';
            $_SESSION['errores'] = $errores;
            header("Location: vender.php?paso=" . $paso_actual . "&show_auth=true");
            exit();
        }
    }
    
    // Registro
    if (isset($_POST['reg_nombre']) && isset($_POST['reg_email']) && isset($_POST['reg_password'])) {
        if (strlen($_POST['reg_password']) < 6) {
            $errores[] = 'La contraseña debe tener al menos 6 caracteres';
        }
        
        if (empty($errores)) {
            $socio_id = registrarUsuario($_POST['reg_nombre'], $_POST['reg_email'], $_POST['reg_password']);
            if ($socio_id) {
                $_SESSION['usuario_id'] = $socio_id;
                $_SESSION['usuario_nombre'] = $_POST['reg_nombre'];
                $_SESSION['usuario_email'] = $_POST['reg_email'];
                $_SESSION['usuario_role'] = 'socio';
                header("Location: vender.php?paso=5&register=success");
                exit();
            } else {
                $errores[] = 'Error al registrar. El email ya está en uso.';
            }
        }
        
        if (!empty($errores)) {
            $_SESSION['errores'] = $errores;
            header("Location: vender.php?paso=" . $paso_actual . "&show_auth=true");
            exit();
        }
    }
    
    if (!empty($errores)) {
        $_SESSION['errores'] = $errores;
        header("Location: vender.php?paso=" . $paso_actual);
        exit();
    }
    
    unset($_SESSION['errores']);
    
    // Navegación
    if (isset($_POST['siguiente_paso'])) {
        $siguiente = (int)$_POST['siguiente_paso'];
        if ($siguiente == 5 && !isset($_SESSION['usuario_id'])) {
            header("Location: vender.php?paso=4&show_auth=true");
            exit();
        }
        header("Location: vender.php?paso=" . $siguiente);
        exit();
    }
    
    // Confirmar
    if (isset($_POST['confirmar'])) {
        if (!isset($_SESSION['usuario_id'])) {
            $_SESSION['errores'] = ['Debes iniciar sesión para publicar'];
            header("Location: vender.php?paso=5&show_auth=true");
            exit();
        }
        
        if (isset($_POST['imagenes_guardadas'])) {
            $imagenes = json_decode($_POST['imagenes_guardadas'], true);
            if (is_array($imagenes)) {
                $_SESSION['form_venta']['imagenes'] = $imagenes;
            }
        }
        
        $resultado = guardarPropiedad($_SESSION['form_venta'], $_SESSION['usuario_id']);
        
        if ($resultado['success']) {
            $_SESSION['ultima_propiedad_id'] = $resultado['property_id'];
            $_SESSION['mensaje_exito'] = '¡Propiedad publicada exitosamente!';
            header("Location: vender_exito.php");
            exit();
        } else {
            $_SESSION['errores'] = ['Error al publicar la propiedad: ' . $resultado['error']];
            header("Location: vender.php?paso=5");
            exit();
        }
    }
}

// ========================================
// RECUPERAR DATOS DE SESIÓN
// ========================================
$data = $_SESSION['form_venta'];
$errores = $_SESSION['errores'] ?? [];
unset($_SESSION['errores']);
$show_auth = isset($_GET['show_auth']) ? true : false;

// ========================================
// MAPEOS PARA MOSTRAR EN RESUMEN - DEFINIDOS AQUÍ
// ========================================
$tipos = [
    'casa' => 'Casa',
    'departamento' => 'Departamento',
    'terreno' => 'Terreno',
    'local' => 'Local Comercial'
];

$tipos_casa = [
    'una_planta' => 'Una planta',
    'dos_plantas' => 'Dos plantas',
    'duplex' => 'Dúplex'
];

$niveles = [
    'primer_nivel' => 'Primer Nivel',
    'segundo_nivel' => 'Segundo Nivel'
];

$estados = [
    'libre' => 'Libre de gravámenes',
    'intestado' => 'Intestado (sin testamento)',
    'sucesion' => 'En proceso de sucesión',
    'litigio' => 'En litigio',
    'otro' => 'Otro'
];

$socio = null;
if (isset($_SESSION['usuario_id'])) {
    try {
        $sql = "SELECT id, nombre, email, rol FROM socios WHERE id = ? AND activo = 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$_SESSION['usuario_id']]);
        $socio = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Error al obtener socio: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Publicar Propiedad | Inmobiliaria MH</title>
    <link rel="stylesheet" href="css/socios.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .image-upload-container {
            border: 2px dashed #ddd;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            transition: all 0.3s ease;
            background: #fafafa;
            cursor: pointer;
        }
        .image-upload-container:hover {
            border-color: #c9a84c;
            background: #f8f6f0;
        }
        .image-upload-container.dragover {
            border-color: #c9a84c;
            background: #f0eddf;
            transform: scale(1.01);
        }
        .image-upload-icon {
            font-size: 48px;
            color: #c9a84c;
            margin-bottom: 10px;
        }
        .image-upload-text {
            color: #666;
            font-size: 14px;
        }
        .image-upload-text strong {
            color: #1a1a2e;
        }
        .image-preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 12px;
            margin-top: 15px;
        }
        .image-preview-item {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            aspect-ratio: 1;
            background: #f5f5f5;
            border: 2px solid #e0e0e0;
            transition: all 0.3s ease;
        }
        .image-preview-item:hover {
            border-color: #c9a84c;
            transform: scale(1.02);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .image-preview-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .image-preview-item .remove-image {
            position: absolute;
            top: 5px;
            right: 5px;
            background: rgba(255, 0, 0, 0.8);
            color: white;
            border: none;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            opacity: 0;
            z-index: 5;
        }
        .image-preview-item:hover .remove-image {
            opacity: 1;
        }
        .image-preview-item .remove-image:hover {
            background: rgba(200, 0, 0, 0.9);
            transform: scale(1.1);
        }
        .image-preview-item .image-number {
            position: absolute;
            bottom: 5px;
            left: 5px;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
        }
        .image-preview-item .image-main-badge {
            position: absolute;
            top: 5px;
            left: 5px;
            background: #c9a84c;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
            z-index: 5;
        }
        .upload-progress {
            display: none;
            margin-top: 15px;
        }
        .upload-progress .progress-bar {
            width: 100%;
            height: 6px;
            background: #e0e0e0;
            border-radius: 3px;
            overflow: hidden;
        }
        .upload-progress .progress-bar .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #c9a84c, #e8c86a);
            border-radius: 3px;
            width: 0%;
            transition: width 0.5s ease;
        }
        .upload-progress .progress-text {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
            text-align: center;
        }
        .resumen-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }
        .resumen-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .resumen-item:last-child {
            border-bottom: none;
        }
        .resumen-item .label {
            color: #666;
            font-weight: 500;
        }
        .resumen-item .value {
            color: #1a1a2e;
            font-weight: 600;
        }
        .resumen-total {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px solid #c9a84c;
            text-align: right;
            font-size: 20px;
            font-weight: bold;
            color: #1a1a2e;
        }
        .success-message {
            background: #d4edda;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
        }
        .success-message p {
            margin: 0;
            color: #155724;
        }
        .error-list {
            background: #f8d7da;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #dc3545;
        }
        .error-list ul {
            margin: 0;
            padding-left: 20px;
        }
        .error-list li {
            color: #721c24;
        }
        .btn-success {
            background: #28a745;
            color: white;
        }
        .btn-success:hover {
            background: #218838;
        }
        .accesorios-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 12px;
            margin-top: 10px;
        }
        .accesorio-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            background: #f8f8f8;
            border-radius: 8px;
            border: 2px solid #e8e8e8;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .accesorio-item:hover {
            border-color: #c9a84c;
            background: #f8f6f0;
        }
        .accesorio-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #c9a84c;
            cursor: pointer;
        }
        .accesorio-item label {
            cursor: pointer;
            font-weight: 500;
            color: #333;
        }
        .accesorio-item .accesorio-icon {
            color: #c9a84c;
            font-size: 18px;
        }
        .accesorio-otro {
            grid-column: 1 / -1;
        }
        .accesorio-otro input[type="text"] {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            margin-left: 8px;
        }
        .accesorio-otro input[type="text"]:focus {
            border-color: #c9a84c;
            outline: none;
            box-shadow: 0 0 0 3px rgba(201, 168, 76, 0.1);
        }
        .conditional-group {
            padding: 15px 20px;
            background: #f8f8f8;
            border-radius: 8px;
            margin-top: 10px;
            margin-bottom: 15px;
            border-left: 4px solid #c9a84c;
        }
        .conditional-group.hidden {
            display: none;
        }
        .radio-group-inline {
            display: flex;
            gap: 20px;
            margin-top: 5px;
        }
        .radio-group-inline label {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
        }
        .servicios-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }
        .servicio-item {
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding: 15px;
            background: #f8f8f8;
            border-radius: 8px;
            border: 1px solid #e8e8e8;
        }
        .servicio-item label {
            font-weight: 600;
            color: #333;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .servicio-item .servicio-controls {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        .servicio-item .servicio-controls label {
            font-weight: normal;
            font-size: 14px;
        }
        .legal-document-group {
            padding: 15px 20px;
            background: #f8f8f8;
            border-radius: 8px;
            margin-top: 10px;
            border-left: 4px solid #c9a84c;
        }
        .legal-document-group .radio-group {
            display: flex;
            gap: 20px;
            margin-top: 5px;
        }
        .legal-document-group .radio-group label {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
        }
        .wizard-layout {
            display: flex;
            gap: 30px;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .progress-sidebar {
            flex: 0 0 220px;
            background: white;
            border-radius: 12px;
            padding: 25px 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            height: fit-content;
            position: sticky;
            top: 20px;
        }
        .progress-sidebar h3 {
            color: #1a1a2e;
            font-size: 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .step {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 8px;
            color: #999;
            font-size: 14px;
            transition: all 0.3s ease;
            margin-bottom: 4px;
            cursor: default;
        }
        .step .step-number {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #e8e8e8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 13px;
            color: #999;
            flex-shrink: 0;
            transition: all 0.3s ease;
        }
        .step.active {
            color: #1a1a2e;
            font-weight: 600;
            background: #f8f6f0;
        }
        .step.active .step-number {
            background: #c9a84c;
            color: white;
        }
        .step.completed .step-number {
            background: #28a745;
            color: white;
        }
        .step.completed {
            color: #1a1a2e;
        }
        .wizard-content {
            flex: 1;
            background: white;
            border-radius: 12px;
            padding: 30px 35px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        .wizard-content h2 {
            color: #1a1a2e;
            font-size: 24px;
            margin-bottom: 5px;
        }
        .wizard-content .subtitle {
            color: #888;
            margin-bottom: 25px;
            font-size: 14px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            color: #333;
            margin-bottom: 6px;
            font-size: 14px;
        }
        .form-group label .required {
            color: #dc3545;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: inherit;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #c9a84c;
            outline: none;
            box-shadow: 0 0 0 3px rgba(201, 168, 76, 0.1);
        }
        .form-group textarea {
            min-height: 80px;
            resize: vertical;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .btn-group {
            display: flex;
            gap: 12px;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e8e8e8;
            flex-wrap: wrap;
        }
        .btn {
            padding: 10px 28px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-dorado {
            background: #c9a84c;
            color: white;
        }
        .btn-dorado:hover {
            background: #b8963a;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(201, 168, 76, 0.3);
        }
        .btn-secondary {
            background: #e8e8e8;
            color: #333;
        }
        .btn-secondary:hover {
            background: #d5d5d5;
        }
        .btn-success {
            background: #28a745;
            color: white;
        }
        .btn-success:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background: white;
            border-radius: 16px;
            max-width: 480px;
            width: 90%;
            padding: 30px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalFadeIn 0.3s ease;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-20px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .modal-header h2 {
            font-size: 22px;
            color: #1a1a2e;
            margin: 0;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            color: #999;
            cursor: pointer;
            transition: all 0.3s ease;
            padding: 0 5px;
        }
        .modal-close:hover {
            color: #333;
            transform: rotate(90deg);
        }
        .auth-tabs {
            display: flex;
            border-bottom: 2px solid #e8e8e8;
            margin-bottom: 20px;
        }
        .auth-tab {
            padding: 10px 20px;
            background: none;
            border: none;
            font-weight: 600;
            color: #999;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
        }
        .auth-tab.active {
            color: #c9a84c;
            border-bottom-color: #c9a84c;
        }
        .auth-tab:hover {
            color: #c9a84c;
        }
        .auth-panel {
            display: none;
        }
        .auth-panel.active {
            display: block;
        }
        .btn-auth {
            width: 100%;
            padding: 12px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }
        .btn-login {
            background: #c9a84c;
            color: white;
        }
        .btn-login:hover {
            background: #b8963a;
        }
        .btn-register {
            background: #1a1a2e;
            color: white;
        }
        .btn-register:hover {
            background: #2a2a4e;
        }
        @media (max-width: 992px) {
            .wizard-layout { flex-direction: column; }
            .progress-sidebar {
                flex: none;
                position: static;
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 5px;
                padding: 15px;
            }
            .progress-sidebar h3 { grid-column: 1 / -1; margin-bottom: 5px; }
            .step { padding: 8px 12px; font-size: 12px; margin-bottom: 0; }
            .step .step-number { width: 24px; height: 24px; font-size: 11px; }
            .wizard-content { padding: 20px; }
        }
        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; }
            .accesorios-grid { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); }
            .servicios-grid { grid-template-columns: 1fr; }
            .btn-group { flex-direction: column; }
            .btn-group .btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>

<?php include 'sidebar.php'; ?>

<main class="main-content">
    <div class="main-header">
        <div class="header-left">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <h1>Publicar Propiedad</h1>
            <p class="welcome">
                <i class="fas fa-plus-circle"></i> Completa el formulario para publicar tu propiedad
            </p>
        </div>
        <div class="header-actions">
            <a href="mis_propiedades.php" class="btn-header secondary">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
        </div>
    </div>

    <div class="wizard-layout">
        <aside class="progress-sidebar">
            <h3><i class="fas fa-tasks"></i> Progreso</h3>
            <div class="step <?php echo $paso >= 1 ? 'active' : ''; ?> <?php echo $paso > 1 ? 'completed' : ''; ?>" data-step="1">
                <span class="step-number"><span>1</span></span> Datos Básicos
            </div>
            <div class="step <?php echo $paso >= 2 ? 'active' : ''; ?> <?php echo $paso > 2 ? 'completed' : ''; ?>" data-step="2">
                <span class="step-number"><span>2</span></span> Detalles
            </div>
            <div class="step <?php echo $paso >= 3 ? 'active' : ''; ?> <?php echo $paso > 3 ? 'completed' : ''; ?>" data-step="3">
                <span class="step-number"><span>3</span></span> Financiero
            </div>
            <div class="step <?php echo $paso >= 4 ? 'active' : ''; ?> <?php echo $paso > 4 ? 'completed' : ''; ?>" data-step="4">
                <span class="step-number"><span>4</span></span> Legal
            </div>
            <div class="step <?php echo $paso >= 5 ? 'active' : ''; ?> <?php echo $paso > 5 ? 'completed' : ''; ?>" data-step="5">
                <span class="step-number"><span>5</span></span> Confirmar
            </div>
        </aside>

        <div class="wizard-content">
            <?php if (isset($_GET['login']) && $_GET['login'] == 'success'): ?>
                <div class="success-message"><p>✅ ¡Sesión iniciada correctamente! Ahora puedes publicar tu propiedad.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['register']) && $_GET['register'] == 'success'): ?>
                <div class="success-message"><p>✅ ¡Registro exitoso! Ahora puedes publicar tu propiedad.</p></div>
            <?php endif; ?>
            
            <form method="POST" id="wizardForm" enctype="multipart/form-data" action="">
                <input type="hidden" name="paso_actual" value="<?php echo $paso; ?>">
                <input type="hidden" name="imagenes_guardadas" id="imagenesGuardadas" value='<?php echo json_encode($data['imagenes'] ?? []); ?>'>
                
                <?php if (!empty($errores)): ?>
                    <div class="error-list">
                        <ul>
                            <?php foreach ($errores as $error): ?>
                                <li>⚠️ <?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($paso == 1): ?>
                    <h2>🏠 Datos Básicos</h2>
                    <p class="subtitle">Cuéntanos sobre tu propiedad</p>
                    <div class="form-group">
                        <label for="titulo">Título de la Propiedad <span class="required">*</span></label>
                        <input type="text" id="titulo" name="titulo" value="<?php echo htmlspecialchars($data['titulo'] ?? ''); ?>" placeholder="Ej: Hermosa casa en zona residencial" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="precio">Precio (USD) <span class="required">*</span></label>
                            <input type="number" id="precio" name="precio" value="<?php echo htmlspecialchars($data['precio'] ?? ''); ?>" placeholder="0.00" min="0" step="0.01" required>
                        </div>
                        <div class="form-group">
                            <label for="tipo_operacion">Tipo de Operación <span class="required">*</span></label>
                            <select id="tipo_operacion" name="tipo_operacion" required>
                                <option value="">Seleccionar</option>
                                <option value="venta" <?php echo (isset($data['tipo_operacion']) && $data['tipo_operacion'] == 'venta') ? 'selected' : ''; ?>>Venta</option>
                                <option value="alquiler" <?php echo (isset($data['tipo_operacion']) && $data['tipo_operacion'] == 'alquiler') ? 'selected' : ''; ?>>Alquiler</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="tipo_vivienda">Tipo de Vivienda <span class="required">*</span></label>
                        <select id="tipo_vivienda" name="tipo_vivienda" required>
                            <option value="">Seleccionar</option>
                            <option value="casa" <?php echo (isset($data['tipo_vivienda']) && $data['tipo_vivienda'] == 'casa') ? 'selected' : ''; ?>>Casa</option>
                            <option value="departamento" <?php echo (isset($data['tipo_vivienda']) && $data['tipo_vivienda'] == 'departamento') ? 'selected' : ''; ?>>Departamento</option>
                            <option value="terreno" <?php echo (isset($data['tipo_vivienda']) && $data['tipo_vivienda'] == 'terreno') ? 'selected' : ''; ?>>Terreno</option>
                            <option value="local" <?php echo (isset($data['tipo_vivienda']) && $data['tipo_vivienda'] == 'local') ? 'selected' : ''; ?>>Local Comercial</option>
                        </select>
                    </div>
                    <div id="casa_options" class="conditional-group <?php echo (isset($data['tipo_vivienda']) && $data['tipo_vivienda'] == 'casa') ? '' : 'hidden'; ?>">
                        <div class="form-group">
                            <label for="tipo_casa">Tipo de Casa <span class="required">*</span></label>
                            <select id="tipo_casa" name="tipo_casa">
                                <option value="">Seleccionar</option>
                                <option value="una_planta" <?php echo (isset($data['tipo_casa']) && $data['tipo_casa'] == 'una_planta') ? 'selected' : ''; ?>>Una planta</option>
                                <option value="dos_plantas" <?php echo (isset($data['tipo_casa']) && $data['tipo_casa'] == 'dos_plantas') ? 'selected' : ''; ?>>Dos plantas</option>
                                <option value="duplex" <?php echo (isset($data['tipo_casa']) && $data['tipo_casa'] == 'duplex') ? 'selected' : ''; ?>>Dúplex</option>
                            </select>
                        </div>
                        <div id="nivel_duplex_group" class="conditional-group <?php echo (isset($data['tipo_casa']) && $data['tipo_casa'] == 'duplex') ? '' : 'hidden'; ?>">
                            <div class="form-group">
                                <label for="nivel_duplex">Nivel del Dúplex <span class="required">*</span></label>
                                <select id="nivel_duplex" name="nivel_duplex">
                                    <option value="">Seleccionar</option>
                                    <option value="primer_nivel" <?php echo (isset($data['nivel_duplex']) && $data['nivel_duplex'] == 'primer_nivel') ? 'selected' : ''; ?>>Primer Nivel</option>
                                    <option value="segundo_nivel" <?php echo (isset($data['nivel_duplex']) && $data['nivel_duplex'] == 'segundo_nivel') ? 'selected' : ''; ?>>Segundo Nivel</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div id="departamento_options" class="conditional-group <?php echo (isset($data['tipo_vivienda']) && $data['tipo_vivienda'] == 'departamento') ? '' : 'hidden'; ?>">
                        <div class="form-group">
                            <label for="nivel_departamento">Nivel del Departamento <span class="required">*</span></label>
                            <input type="text" id="nivel_departamento" name="nivel_departamento" value="<?php echo htmlspecialchars($data['nivel_departamento'] ?? ''); ?>" placeholder="Ej: Planta baja, 1er nivel, 3er nivel...">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="descripcion">Descripción Breve</label>
                        <textarea id="descripcion" name="descripcion" placeholder="Describe tu propiedad en pocas palabras"><?php echo htmlspecialchars($data['descripcion'] ?? ''); ?></textarea>
                    </div>
                    <div class="btn-group">
                        <button type="submit" class="btn btn-dorado" name="siguiente_paso" value="2">Siguiente <i class="fas fa-arrow-right"></i></button>
                    </div>

                <?php elseif ($paso == 2): ?>
                    <h2>📐 Detalles de la Propiedad</h2>
                    <p class="subtitle">Especifica las características principales</p>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="m2">Metros Cuadrados <span class="required">*</span></label>
                            <input type="number" id="m2" name="m2" value="<?php echo htmlspecialchars($data['m2'] ?? ''); ?>" placeholder="m²" min="1" required>
                        </div>
                        <div class="form-group">
                            <label for="recamaras">Número de Recámaras <span class="required">*</span></label>
                            <input type="number" id="recamaras" name="recamaras" value="<?php echo htmlspecialchars($data['recamaras'] ?? ''); ?>" placeholder="Ej: 3" min="0" max="20" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="banos">Baños</label>
                            <input type="number" id="banos" name="banos" value="<?php echo htmlspecialchars($data['banos'] ?? ''); ?>" placeholder="Ej: 2" min="0" max="10">
                        </div>
                        <div class="form-group">
                            <label for="estacionamiento">Estacionamientos</label>
                            <input type="number" id="estacionamiento" name="estacionamiento" value="<?php echo htmlspecialchars($data['estacionamiento'] ?? ''); ?>" placeholder="Ej: 2" min="0" max="10">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="ubicacion">Ubicación <span class="required">*</span></label>
                        <input type="text" id="ubicacion" name="ubicacion" value="<?php echo htmlspecialchars($data['ubicacion'] ?? ''); ?>" placeholder="Ciudad, colonia, calle" required>
                    </div>
                    
                    <!-- ACCESORIOS -->
                    <div class="form-group">
                        <label>Accesorios de la Propiedad</label>
                        <p style="font-size: 13px; color: #888; margin-bottom: 10px;">Selecciona los accesorios que incluye la propiedad</p>
                        <div class="accesorios-grid">
                            <?php foreach ($accesorios_disponibles as $accesorio): ?>
                                <div class="accesorio-item">
                                    <input type="checkbox" id="acc_<?php echo $accesorio['id']; ?>" name="accesorios[]" value="<?php echo $accesorio['id']; ?>" <?php echo (isset($data['accesorios']) && in_array($accesorio['id'], $data['accesorios'])) ? 'checked' : ''; ?>>
                                    <label for="acc_<?php echo $accesorio['id']; ?>">
                                        <?php if (!empty($accesorio['icono'])): ?>
                                            <i class="<?php echo htmlspecialchars($accesorio['icono']); ?> accesorio-icon"></i>
                                        <?php endif; ?>
                                        <?php echo htmlspecialchars($accesorio['nombre']); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                            <div class="accesorio-item accesorio-otro">
                                <input type="checkbox" id="acc_otro" name="accesorios_otro_check" <?php echo isset($data['accesorio_otro']) ? 'checked' : ''; ?>>
                                <label for="acc_otro"><i class="fas fa-plus-circle accesorio-icon"></i> Otro</label>
                                <input type="text" id="accesorio_otro_input" name="accesorio_otro" value="<?php echo htmlspecialchars($data['accesorio_otro'] ?? ''); ?>" placeholder="Especificar..." <?php echo isset($data['accesorio_otro']) ? '' : 'disabled'; ?>>
                            </div>
                        </div>
                    </div>

                    <!-- SISTEMA DE IMÁGENES -->
                    <div class="form-group">
                        <label>Fotos de la Propiedad <span style="color: #666; font-weight: normal;">(máximo 10 fotos)</span></label>
                        <div class="image-upload-container" id="imageUploadContainer">
                            <div class="image-upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                            <div class="image-upload-text"><strong>Haz clic o arrastra</strong> tus imágenes aquí</div>
                            <div style="font-size: 12px; color: #999; margin-top: 5px;">Formatos: JPG, PNG, GIF, WEBP • Tamaño máximo: 5MB por imagen</div>
                            <input type="file" id="fileInput" name="imagenes[]" multiple accept="image/*" style="display: none;">
                        </div>
                        <div class="upload-progress" id="uploadProgress">
                            <div class="progress-bar"><div class="progress-fill" id="progressFill"></div></div>
                            <div class="progress-text" id="progressText">Subiendo imágenes...</div>
                        </div>
                        <div class="image-preview-grid" id="imagePreviewGrid">
                            <?php if (!empty($data['imagenes'])): ?>
                                <?php foreach ($data['imagenes'] as $index => $imagen): ?>
                                    <div class="image-preview-item" data-index="<?php echo $index; ?>">
                                        <img src="<?php echo htmlspecialchars($imagen); ?>" alt="Imagen <?php echo $index + 1; ?>">
                                        <?php if ($index === 0): ?><span class="image-main-badge">Principal</span><?php endif; ?>
                                        <span class="image-number"><?php echo $index + 1; ?></span>
                                        <button type="button" class="remove-image" data-index="<?php echo $index; ?>"><i class="fas fa-times"></i></button>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="btn-group">
                        <a href="?paso=1" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Atrás</a>
                        <button type="submit" class="btn btn-dorado" name="siguiente_paso" value="3">Siguiente <i class="fas fa-arrow-right"></i></button>
                    </div>

                <?php elseif ($paso == 3): ?>
                    <h2>💰 Situación Financiera</h2>
                    <p class="subtitle">Información sobre adeudos y situación financiera</p>
                    <div class="form-group">
                        <label>¿La propiedad tiene algún adeudo o gravamen?</label>
                        <div class="radio-group-inline">
                            <label><input type="radio" name="tiene_adeudo" value="1" <?php echo (isset($data['tiene_adeudo']) && $data['tiene_adeudo'] == 1) ? 'checked' : ''; ?> onchange="toggleAdeudo(this.value)"> Sí</label>
                            <label><input type="radio" name="tiene_adeudo" value="0" <?php echo (isset($data['tiene_adeudo']) && $data['tiene_adeudo'] == 0) ? 'checked' : ''; ?> <?php echo !isset($data['tiene_adeudo']) ? 'checked' : ''; ?> onchange="toggleAdeudo(this.value)"> No</label>
                        </div>
                    </div>
                    <div id="adeudo_details" class="conditional-group <?php echo (isset($data['tiene_adeudo']) && $data['tiene_adeudo'] == 1) ? '' : 'hidden'; ?>">
                        <div class="form-group">
                            <label for="tipo_adeudo">Tipo de Adeudo <span class="required">*</span></label>
                            <select id="tipo_adeudo" name="tipo_adeudo">
                                <option value="">Seleccionar</option>
                                <option value="banco" <?php echo (isset($data['tipo_adeudo']) && $data['tipo_adeudo'] == 'banco') ? 'selected' : ''; ?>>Banco</option>
                                <option value="particular" <?php echo (isset($data['tipo_adeudo']) && $data['tipo_adeudo'] == 'particular') ? 'selected' : ''; ?>>Particular</option>
                                <option value="gobierno" <?php echo (isset($data['tipo_adeudo']) && $data['tipo_adeudo'] == 'gobierno') ? 'selected' : ''; ?>>Gobierno</option>
                                <option value="otros" <?php echo (isset($data['tipo_adeudo']) && $data['tipo_adeudo'] == 'otros') ? 'selected' : ''; ?>>Otros</option>
                            </select>
                        </div>
                        <div id="banco_group" class="conditional-group <?php echo (isset($data['tipo_adeudo']) && $data['tipo_adeudo'] == 'banco') ? '' : 'hidden'; ?>">
                            <div class="form-group">
                                <label for="banco_id">Banco <span class="required">*</span></label>
                                <select id="banco_id" name="banco_id">
                                    <option value="">Seleccionar banco</option>
                                    <?php foreach ($bancos_disponibles as $banco): ?>
                                        <option value="<?php echo $banco['id']; ?>" <?php echo (isset($data['banco_id']) && $data['banco_id'] == $banco['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($banco['nombre']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="monto_adeudo">Monto Aproximado del Adeudo <span class="required">*</span></label>
                            <input type="number" id="monto_adeudo" name="monto_adeudo" value="<?php echo htmlspecialchars($data['monto_adeudo'] ?? ''); ?>" placeholder="0.00" min="0" step="0.01">
                        </div>
                        <div class="form-group">
                            <label>Tipo de Adeudo sobre la Propiedad <span class="required">*</span></label>
                            <div class="radio-group-inline">
                                <label><input type="radio" name="tipo_adeudo_propiedad" value="individual" <?php echo (isset($data['tipo_adeudo_propiedad']) && $data['tipo_adeudo_propiedad'] == 'individual') ? 'checked' : ''; ?> onchange="toggleAdeudoCompartido(this.value)"> Individual</label>
                                <label><input type="radio" name="tipo_adeudo_propiedad" value="compartido" <?php echo (isset($data['tipo_adeudo_propiedad']) && $data['tipo_adeudo_propiedad'] == 'compartido') ? 'checked' : ''; ?> onchange="toggleAdeudoCompartido(this.value)"> Compartido</label>
                            </div>
                        </div>
                        <div id="adeudo_compartido_details" class="conditional-group <?php echo (isset($data['tipo_adeudo_propiedad']) && $data['tipo_adeudo_propiedad'] == 'compartido') ? '' : 'hidden'; ?>">
                            <div class="form-group">
                                <label for="adeudo_compartido_detalles">Detalles del Adeudo Compartido</label>
                                <textarea id="adeudo_compartido_detalles" name="adeudo_compartido_detalles" placeholder="Describe los detalles del adeudo compartido (ej: con quién se comparte, porcentaje, etc.)"><?php echo htmlspecialchars($data['adeudo_compartido_detalles'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="form-group" style="margin-top: 30px;">
                        <label>Servicios Municipales</label>
                        <p style="font-size: 13px; color: #888; margin-bottom: 10px;">Estado de los servicios de la propiedad</p>
                        <div class="servicios-grid">
                            <div class="servicio-item">
                                <label><i class="fas fa-water"></i> Agua</label>
                                <div class="servicio-controls">
                                    <label><input type="radio" name="servicio_agua_activo" value="1" <?php echo (isset($data['servicio_agua_activo']) && $data['servicio_agua_activo'] == 1) ? 'checked' : ''; ?>> Activo</label>
                                    <label><input type="radio" name="servicio_agua_activo" value="0" <?php echo (isset($data['servicio_agua_activo']) && $data['servicio_agua_activo'] == 0) ? 'checked' : ''; ?>> Inactivo</label>
                                </div>
                                <div style="display: flex; gap: 15px; align-items: center; margin-top: 5px;">
                                    <label style="font-weight: normal; font-size: 13px;"><input type="checkbox" name="servicio_agua_adeudo" value="1" <?php echo (isset($data['servicio_agua_adeudo']) && $data['servicio_agua_adeudo'] == 1) ? 'checked' : ''; ?>> Con adeudo</label>
                                </div>
                            </div>
                            <div class="servicio-item">
                                <label><i class="fas fa-bolt"></i> Electricidad</label>
                                <div class="servicio-controls">
                                    <label><input type="radio" name="servicio_luz_activo" value="1" <?php echo (isset($data['servicio_luz_activo']) && $data['servicio_luz_activo'] == 1) ? 'checked' : ''; ?>> Activo</label>
                                    <label><input type="radio" name="servicio_luz_activo" value="0" <?php echo (isset($data['servicio_luz_activo']) && $data['servicio_luz_activo'] == 0) ? 'checked' : ''; ?>> Inactivo</label>
                                </div>
                                <div style="display: flex; gap: 15px; align-items: center; margin-top: 5px;">
                                    <label style="font-weight: normal; font-size: 13px;"><input type="checkbox" name="servicio_luz_adeudo" value="1" <?php echo (isset($data['servicio_luz_adeudo']) && $data['servicio_luz_adeudo'] == 1) ? 'checked' : ''; ?>> Con adeudo</label>
                                </div>
                            </div>
                            <div class="servicio-item">
                                <label><i class="fas fa-fire"></i> Gas</label>
                                <div class="servicio-controls">
                                    <label><input type="radio" name="servicio_gas_activo" value="1" <?php echo (isset($data['servicio_gas_activo']) && $data['servicio_gas_activo'] == 1) ? 'checked' : ''; ?>> Activo</label>
                                    <label><input type="radio" name="servicio_gas_activo" value="0" <?php echo (isset($data['servicio_gas_activo']) && $data['servicio_gas_activo'] == 0) ? 'checked' : ''; ?>> Inactivo</label>
                                </div>
                                <div style="display: flex; gap: 15px; align-items: center; margin-top: 5px;">
                                    <label style="font-weight: normal; font-size: 13px;"><input type="checkbox" name="servicio_gas_adeudo" value="1" <?php echo (isset($data['servicio_gas_adeudo']) && $data['servicio_gas_adeudo'] == 1) ? 'checked' : ''; ?>> Con adeudo</label>
                                </div>
                            </div>
                            <div class="servicio-item">
                                <label><i class="fas fa-wifi"></i> Internet / TV</label>
                                <div class="servicio-controls">
                                    <label><input type="radio" name="servicio_internet_activo" value="1" <?php echo (isset($data['servicio_internet_activo']) && $data['servicio_internet_activo'] == 1) ? 'checked' : ''; ?>> Activo</label>
                                    <label><input type="radio" name="servicio_internet_activo" value="0" <?php echo (isset($data['servicio_internet_activo']) && $data['servicio_internet_activo'] == 0) ? 'checked' : ''; ?>> Inactivo</label>
                                </div>
                                <div style="display: flex; gap: 15px; align-items: center; margin-top: 5px;">
                                    <label style="font-weight: normal; font-size: 13px;"><input type="checkbox" name="servicio_internet_adeudo" value="1" <?php echo (isset($data['servicio_internet_adeudo']) && $data['servicio_internet_adeudo'] == 1) ? 'checked' : ''; ?>> Con adeudo</label>
                                </div>
                            </div>
                            <div class="servicio-item">
                                <label><i class="fas fa-trash-alt"></i> Recolección de Basura</label>
                                <div class="servicio-controls">
                                    <label><input type="radio" name="servicio_basura_activo" value="1" <?php echo (isset($data['servicio_basura_activo']) && $data['servicio_basura_activo'] == 1) ? 'checked' : ''; ?>> Activo</label>
                                    <label><input type="radio" name="servicio_basura_activo" value="0" <?php echo (isset($data['servicio_basura_activo']) && $data['servicio_basura_activo'] == 0) ? 'checked' : ''; ?>> Inactivo</label>
                                </div>
                                <div style="display: flex; gap: 15px; align-items: center; margin-top: 5px;">
                                    <label style="font-weight: normal; font-size: 13px;"><input type="checkbox" name="servicio_basura_adeudo" value="1" <?php echo (isset($data['servicio_basura_adeudo']) && $data['servicio_basura_adeudo'] == 1) ? 'checked' : ''; ?>> Con adeudo</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="btn-group">
                        <a href="?paso=2" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Atrás</a>
                        <button type="submit" class="btn btn-dorado" name="siguiente_paso" value="4">Siguiente <i class="fas fa-arrow-right"></i></button>
                    </div>

                <?php elseif ($paso == 4): ?>
                    <h2>⚖️ Situación Legal</h2>
                    <p class="subtitle">Información sobre la situación legal de la propiedad</p>
                    <div class="form-group">
                        <label>Documentos en su poder</label>
                        <p style="font-size: 13px; color: #888; margin-bottom: 10px;">Selecciona los documentos que tienes disponibles</p>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div class="legal-document-group">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label style="font-weight: 600;">Escrituras</label>
                                    <div class="radio-group">
                                        <label><input type="radio" name="tiene_escrituras" value="1" <?php echo (isset($data['tiene_escrituras']) && $data['tiene_escrituras'] == 1) ? 'checked' : ''; ?>> Sí</label>
                                        <label><input type="radio" name="tiene_escrituras" value="0" <?php echo (isset($data['tiene_escrituras']) && $data['tiene_escrituras'] == 0) ? 'checked' : ''; ?> <?php echo !isset($data['tiene_escrituras']) ? 'checked' : ''; ?>> No</label>
                                    </div>
                                </div>
                            </div>
                            <div class="legal-document-group">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label style="font-weight: 600;">Testamento / Intestado</label>
                                    <div class="radio-group">
                                        <label><input type="radio" name="tiene_testamento" value="1" <?php echo (isset($data['tiene_testamento']) && $data['tiene_testamento'] == 1) ? 'checked' : ''; ?>> Sí</label>
                                        <label><input type="radio" name="tiene_testamento" value="0" <?php echo (isset($data['tiene_testamento']) && $data['tiene_testamento'] == 0) ? 'checked' : ''; ?> <?php echo !isset($data['tiene_testamento']) ? 'checked' : ''; ?>> No</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="legal_status">Estado Legal de la Propiedad</label>
                        <select id="legal_status" name="legal_status">
                            <option value="libre" <?php echo (isset($data['legal_status']) && $data['legal_status'] == 'libre') ? 'selected' : ''; ?>>Libre de gravámenes</option>
                            <option value="intestado" <?php echo (isset($data['legal_status']) && $data['legal_status'] == 'intestado') ? 'selected' : ''; ?>>Intestado (sin testamento)</option>
                            <option value="sucesion" <?php echo (isset($data['legal_status']) && $data['legal_status'] == 'sucesion') ? 'selected' : ''; ?>>En proceso de sucesión</option>
                            <option value="litigio" <?php echo (isset($data['legal_status']) && $data['legal_status'] == 'litigio') ? 'selected' : ''; ?>>En litigio</option>
                            <option value="otro" <?php echo (isset($data['legal_status']) && $data['legal_status'] == 'otro') ? 'selected' : ''; ?>>Otro</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="legal_status_notes">Notas sobre la situación legal</label>
                        <textarea id="legal_status_notes" name="legal_status_notes" placeholder="Describe cualquier aspecto legal relevante (ej: situación de la escritura, detalles del intestado, etc.)"><?php echo htmlspecialchars($data['legal_status_notes'] ?? ''); ?></textarea>
                    </div>
                    <div class="btn-group">
                        <a href="?paso=3" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Atrás</a>
                        <?php if (isset($_SESSION['usuario_id'])): ?>
                            <button type="submit" class="btn btn-dorado" name="siguiente_paso" value="5">Siguiente <i class="fas fa-arrow-right"></i></button>
                        <?php else: ?>
                            <button type="button" class="btn btn-dorado" id="btnOpenAuth"><i class="fas fa-lock"></i> Siguiente (Inicia sesión)</button>
                        <?php endif; ?>
                    </div>

                <?php elseif ($paso == 5): ?>
                    <h2>🔐 Resumen Final</h2>
                    <p class="subtitle">Revisa los datos y confirma la publicación</p>
                    <?php if (isset($_SESSION['usuario_id'])): ?>
                        <div style="background: #d4edda; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border-left: 4px solid #28a745;">
                            <p style="margin: 0; color: #155724;">✅ Publicando como <strong><?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?></strong></p>
                        </div>
                    <?php else: ?>
                        <div style="background: #fff3cd; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border-left: 4px solid #ffc107;">
                            <p style="margin: 0; color: #856404;">⚠️ Debes iniciar sesión para publicar. <a href="?paso=4&show_auth=true" style="color: #1a1a2e; font-weight: 600;">Iniciar sesión</a></p>
                        </div>
                    <?php endif; ?>
                    <div class="resumen-card">
                        <h4 style="margin-bottom: 1rem; color: #1a1a2e;">📋 Resumen de la Propiedad</h4>
                        <div class="resumen-item"><span class="label">Título</span><span class="value"><?php echo htmlspecialchars($data['titulo'] ?? 'No especificado'); ?></span></div>
                        <div class="resumen-item"><span class="label">Operación</span><span class="value"><?php echo ucfirst(htmlspecialchars($data['tipo_operacion'] ?? 'No especificado')); ?></span></div>
                        <div class="resumen-item"><span class="label">Precio</span><span class="value">$<?php echo number_format($data['precio'] ?? 0, 2); ?></span></div>
                        <div class="resumen-item"><span class="label">Tipo de Vivienda</span><span class="value"><?php echo htmlspecialchars($tipos[$data['tipo_vivienda'] ?? ''] ?? $data['tipo_vivienda'] ?? 'No especificado'); ?></span></div>
                        <?php if (isset($data['tipo_casa']) && !empty($data['tipo_casa'])): ?>
                            <div class="resumen-item"><span class="label">Tipo de Casa</span><span class="value"><?php echo htmlspecialchars($tipos_casa[$data['tipo_casa']] ?? $data['tipo_casa']); ?></span></div>
                        <?php endif; ?>
                        <?php if (isset($data['nivel_duplex']) && !empty($data['nivel_duplex'])): ?>
                            <div class="resumen-item"><span class="label">Nivel del Dúplex</span><span class="value"><?php echo htmlspecialchars($niveles[$data['nivel_duplex']] ?? $data['nivel_duplex']); ?></span></div>
                        <?php endif; ?>
                        <?php if (isset($data['nivel_departamento']) && !empty($data['nivel_departamento'])): ?>
                            <div class="resumen-item"><span class="label">Nivel del Departamento</span><span class="value"><?php echo htmlspecialchars($data['nivel_departamento']); ?></span></div>
                        <?php endif; ?>
                        <div class="resumen-item"><span class="label">Metros Cuadrados</span><span class="value"><?php echo htmlspecialchars($data['m2'] ?? 'No especificado'); ?> m²</span></div>
                        <div class="resumen-item"><span class="label">Recámaras</span><span class="value"><?php echo htmlspecialchars($data['recamaras'] ?? 'No especificado'); ?></span></div>
                        <?php if (!empty($data['banos'])): ?>
                            <div class="resumen-item"><span class="label">Baños</span><span class="value"><?php echo htmlspecialchars($data['banos']); ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($data['estacionamiento'])): ?>
                            <div class="resumen-item"><span class="label">Estacionamientos</span><span class="value"><?php echo htmlspecialchars($data['estacionamiento']); ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($data['ubicacion'])): ?>
                            <div class="resumen-item"><span class="label">Ubicación</span><span class="value"><?php echo htmlspecialchars($data['ubicacion']); ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($data['accesorios']) || !empty($data['accesorio_otro'])): ?>
                            <div class="resumen-item">
                                <span class="label">Accesorios</span>
                                <span class="value"><?php 
                                    $nombres = [];
                                    foreach ($accesorios_disponibles as $acc) {
                                        if (in_array($acc['id'], $data['accesorios'] ?? [])) $nombres[] = $acc['nombre'];
                                    }
                                    if (!empty($data['accesorio_otro'])) $nombres[] = $data['accesorio_otro'];
                                    echo htmlspecialchars(implode(', ', $nombres));
                                ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($data['tiene_adeudo']) && $data['tiene_adeudo'] == 1): ?>
                            <div class="resumen-item" style="border-color: #ffc107;"><span class="label">⚠️ Adeudo</span><span class="value" style="color: #dc3545;">Sí</span></div>
                            <?php if (!empty($data['tipo_adeudo'])): ?>
                                <div class="resumen-item"><span class="label">Tipo de Adeudo</span><span class="value"><?php echo ucfirst(htmlspecialchars($data['tipo_adeudo'])); ?></span></div>
                            <?php endif; ?>
                            <?php if (!empty($data['monto_adeudo'])): ?>
                                <div class="resumen-item"><span class="label">Monto</span><span class="value">$<?php echo number_format($data['monto_adeudo'], 2); ?></span></div>
                            <?php endif; ?>
                            <?php if (!empty($data['tipo_adeudo_propiedad'])): ?>
                                <div class="resumen-item"><span class="label">Tipo de Adeudo</span><span class="value"><?php echo ucfirst(htmlspecialchars($data['tipo_adeudo_propiedad'])); ?></span></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="resumen-item"><span class="label">Adeudos</span><span class="value" style="color: #28a745;">Sin adeudos</span></div>
                        <?php endif; ?>
                        <?php if (isset($data['legal_status'])): ?>
                            <div class="resumen-item"><span class="label">Estado Legal</span><span class="value"><?php echo htmlspecialchars($estados[$data['legal_status']] ?? $data['legal_status']); ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($data['imagenes'])): ?>
                            <div class="resumen-item"><span class="label">Imágenes</span><span class="value"><?php echo count($data['imagenes']); ?> imágenes subidas</span></div>
                            <div style="display: flex; gap: 5px; margin-top: 10px; flex-wrap: wrap;">
                                <?php foreach (array_slice($data['imagenes'], 0, 5) as $imagen): ?>
                                    <img src="<?php echo htmlspecialchars($imagen); ?>" style="width: 60px; height: 60px; object-fit: cover; border-radius: 4px; border: 1px solid #e0e0e0;">
                                <?php endforeach; ?>
                                <?php if (count($data['imagenes']) > 5): ?>
                                    <span style="display: flex; align-items: center; justify-content: center; width: 60px; height: 60px; background: #f5f5f5; border-radius: 4px; font-size: 12px; color: #666;">+<?php echo count($data['imagenes']) - 5; ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="resumen-total">Total: $<?php echo number_format($data['precio'] ?? 0, 2); ?></div>
                    </div>
                    <div class="btn-group">
                        <a href="?paso=4" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Atrás</a>
                        <?php if (isset($_SESSION['usuario_id'])): ?>
                            <button type="submit" class="btn btn-success" name="confirmar" value="1" id="btnPublicar"><i class="fas fa-check-circle"></i> Confirmar y Publicar</button>
                        <?php else: ?>
                            <button type="button" class="btn btn-success" id="btnOpenAuthFromPaso5"><i class="fas fa-lock"></i> Iniciar sesión para publicar</button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
</main>

<!-- MODAL DE AUTENTICACIÓN -->
<div class="modal-overlay" id="authModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>🔐 Inicia sesión o regístrate</h2>
            <button class="modal-close" id="closeModal">&times;</button>
        </div>
        <div class="auth-tabs">
            <button class="auth-tab active" data-tab="login">Iniciar Sesión</button>
            <button class="auth-tab" data-tab="register">Registrarse</button>
        </div>
        <div class="auth-panel active" id="panelLogin">
            <form method="POST" action="">
                <input type="hidden" name="paso_actual" value="<?php echo $paso; ?>">
                <div class="form-group">
                    <label for="modal_login_email">Correo Electrónico</label>
                    <input type="email" id="modal_login_email" name="login_email" placeholder="tu@email.com" required>
                </div>
                <div class="form-group">
                    <label for="modal_login_password">Contraseña</label>
                    <input type="password" id="modal_login_password" name="login_password" placeholder="••••••••" required>
                </div>
                <button type="submit" class="btn-auth btn-login">Iniciar Sesión</button>
            </form>
        </div>
        <div class="auth-panel" id="panelRegister">
            <form method="POST" action="">
                <input type="hidden" name="paso_actual" value="<?php echo $paso; ?>">
                <div class="form-group">
                    <label for="modal_reg_nombre">Nombre Completo</label>
                    <input type="text" id="modal_reg_nombre" name="reg_nombre" placeholder="Tu nombre completo" required>
                </div>
                <div class="form-group">
                    <label for="modal_reg_email">Correo Electrónico</label>
                    <input type="email" id="modal_reg_email" name="reg_email" placeholder="tu@email.com" required>
                </div>
                <div class="form-group">
                    <label for="modal_reg_password">Contraseña</label>
                    <input type="password" id="modal_reg_password" name="reg_password" placeholder="Mínimo 6 caracteres" required minlength="6">
                </div>
                <button type="submit" class="btn-auth btn-register">Crear Cuenta</button>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ========================================
    // MENÚ MÓVIL
    // ========================================
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    function toggleSidebar() {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('show');
        document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
    }

    if (menuToggle) {
        menuToggle.addEventListener('click', toggleSidebar);
    }
    if (overlay) {
        overlay.addEventListener('click', toggleSidebar);
    }

    document.querySelectorAll('.sidebar nav a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 992) {
                toggleSidebar();
            }
        });
    });

    // ========================================
    // TIPO DE VIVIENDA
    // ========================================
    const tipoVivienda = document.getElementById('tipo_vivienda');
    const casaOptions = document.getElementById('casa_options');
    const departamentoOptions = document.getElementById('departamento_options');
    const tipoCasa = document.getElementById('tipo_casa');
    const nivelDuplexGroup = document.getElementById('nivel_duplex_group');

    function toggleViviendaOptions() {
        const selected = tipoVivienda ? tipoVivienda.value : '';
        if (casaOptions) casaOptions.classList.toggle('hidden', selected !== 'casa');
        if (departamentoOptions) departamentoOptions.classList.toggle('hidden', selected !== 'departamento');
        if (selected === 'casa') toggleDuplexOptions();
    }

    function toggleDuplexOptions() {
        if (tipoCasa && nivelDuplexGroup) {
            nivelDuplexGroup.classList.toggle('hidden', tipoCasa.value !== 'duplex');
        }
    }

    if (tipoVivienda) tipoVivienda.addEventListener('change', toggleViviendaOptions);
    if (tipoCasa) tipoCasa.addEventListener('change', toggleDuplexOptions);
    toggleViviendaOptions();

    // ========================================
    // ADEUDOS
    // ========================================
    window.toggleAdeudo = function(value) {
        const details = document.getElementById('adeudo_details');
        if (details) details.classList.toggle('hidden', value != 1);
    };

    window.toggleAdeudoCompartido = function(value) {
        const details = document.getElementById('adeudo_compartido_details');
        if (details) details.classList.toggle('hidden', value != 'compartido');
    };

    const tipoAdeudo = document.getElementById('tipo_adeudo');
    if (tipoAdeudo) {
        tipoAdeudo.addEventListener('change', function() {
            const bancoGroup = document.getElementById('banco_group');
            if (bancoGroup) bancoGroup.classList.toggle('hidden', this.value !== 'banco');
        });
    }

    // ========================================
    // ACCESORIOS - "OTRO"
    // ========================================
    const accOtroCheck = document.getElementById('acc_otro');
    const accOtroInput = document.getElementById('accesorio_otro_input');
    
    if (accOtroCheck && accOtroInput) {
        accOtroCheck.addEventListener('change', function() {
            accOtroInput.disabled = !this.checked;
            if (!this.checked) accOtroInput.value = '';
            else accOtroInput.focus();
        });
    }

    // ========================================
    // SISTEMA DE IMÁGENES - CORREGIDO
    // ========================================
    const uploadContainer = document.getElementById('imageUploadContainer');
    const fileInput = document.getElementById('fileInput');
    const previewGrid = document.getElementById('imagePreviewGrid');
    const progressContainer = document.getElementById('uploadProgress');
    const progressFill = document.getElementById('progressFill');
    const progressText = document.getElementById('progressText');
    const imagenesGuardadas = document.getElementById('imagenesGuardadas');
    
    let imagenes = [];
    
    // Cargar imágenes existentes
    try {
        const existentes = JSON.parse(imagenesGuardadas.value || '[]');
        if (existentes.length > 0) {
            imagenes = existentes;
            renderPreview();
        }
    } catch (e) {
        console.error('Error al cargar imágenes:', e);
    }
    
    // CLICK EN EL CONTENEDOR - SOLUCIÓN DEFINITIVA
    if (uploadContainer && fileInput) {
        uploadContainer.addEventListener('click', function(e) {
            // Si el click es en el botón de eliminar, no hacer nada
            if (e.target.closest('.remove-image')) return;
            // Si el click es en el checkbox o label de "Otro", no hacer nada
            if (e.target.closest('.accesorio-otro')) return;
            // Si el click es en un input o label dentro del contenedor de accesorios, no hacer nada
            if (e.target.closest('.accesorio-item')) return;
            // Si el click es en el contenedor o en los iconos/textos, abrir selector
            if (e.target.closest('#imageUploadContainer')) {
                e.preventDefault();
                fileInput.click();
            }
        });
    }
    
    // Drag and drop
    if (uploadContainer) {
        uploadContainer.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('dragover');
        });
        
        uploadContainer.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
        });
        
        uploadContainer.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
            const files = e.dataTransfer.files;
            if (files.length > 0) processFiles(files);
        });
    }
    
    // Selección de archivos
    if (fileInput) {
        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                processFiles(this.files);
                this.value = '';
            }
        });
    }
    
    function processFiles(files) {
        const maxFiles = 10;
        const maxSize = 5 * 1024 * 1024;
        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        
        const totalFiles = imagenes.length + files.length;
        if (totalFiles > maxFiles) {
            Swal.fire({
                icon: 'warning',
                title: 'Límite de imágenes',
                text: `Solo puedes subir hasta ${maxFiles} imágenes. Ya tienes ${imagenes.length} imágenes.`,
                confirmButtonColor: '#c9a84c'
            });
            return;
        }
        
        if (progressContainer) progressContainer.style.display = 'block';
        let processed = 0;
        const total = files.length;
        
        Array.from(files).forEach((file) => {
            if (!allowedTypes.includes(file.type)) {
                updateProgress(++processed, total, `Formato no soportado: ${file.name}`);
                return;
            }
            
            if (file.size > maxSize) {
                updateProgress(++processed, total, `Archivo demasiado grande: ${file.name}`);
                return;
            }
            
            const formData = new FormData();
            formData.append('imagen', file);
            formData.append('action', 'upload_image');
            
            fetch('upload_image_ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    imagenes.push(data.filepath);
                    renderPreview();
                    updateImagenesGuardadas();
                    updateProgress(++processed, total, `Imagen ${processed}/${total} subida`);
                } else {
                    updateProgress(++processed, total, `Error: ${data.error}`);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                updateProgress(++processed, total, 'Error al subir imagen');
            });
        });
    }
    
    function updateProgress(processed, total, message) {
        const percent = Math.round((processed / total) * 100);
        if (progressFill) progressFill.style.width = percent + '%';
        if (progressText) progressText.textContent = message || `Subiendo imágenes... ${percent}%`;
        
        if (processed >= total) {
            setTimeout(() => {
                if (progressContainer) progressContainer.style.display = 'none';
            }, 1500);
        }
    }
    
    function renderPreview() {
        if (!previewGrid) return;
        previewGrid.innerHTML = '';
        if (imagenes.length === 0) return;
        
        imagenes.forEach((imagen, index) => {
            const div = document.createElement('div');
            div.className = 'image-preview-item';
            div.dataset.index = index;
            
            const img = document.createElement('img');
            img.src = imagen;
            img.alt = `Imagen ${index + 1}`;
            img.loading = 'lazy';
            
            const removeBtn = document.createElement('button');
            removeBtn.className = 'remove-image';
            removeBtn.type = 'button';
            removeBtn.dataset.index = index;
            removeBtn.innerHTML = '<i class="fas fa-times"></i>';
            removeBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                removeImage(index);
            });
            
            const numberBadge = document.createElement('span');
            numberBadge.className = 'image-number';
            numberBadge.textContent = index + 1;
            
            div.appendChild(img);
            div.appendChild(removeBtn);
            div.appendChild(numberBadge);
            
            if (index === 0) {
                const mainBadge = document.createElement('span');
                mainBadge.className = 'image-main-badge';
                mainBadge.textContent = 'Principal';
                div.appendChild(mainBadge);
            }
            
            previewGrid.appendChild(div);
        });
    }
    
    function removeImage(index) {
        Swal.fire({
            title: '¿Eliminar imagen?',
            text: 'Esta acción no se puede deshacer',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Eliminar',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                imagenes.splice(index, 1);
                renderPreview();
                updateImagenesGuardadas();
            }
        });
    }
    
    function updateImagenesGuardadas() {
        if (imagenesGuardadas) {
            imagenesGuardadas.value = JSON.stringify(imagenes);
        }
    }

    // ========================================
    // MODAL DE AUTENTICACIÓN
    // ========================================
    function openAuthModal() {
        const modal = document.getElementById('authModal');
        if (modal) {
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
    }
    
    function closeAuthModal() {
        const modal = document.getElementById('authModal');
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }
    }
    
    const btnOpenAuth = document.getElementById('btnOpenAuth');
    if (btnOpenAuth) btnOpenAuth.addEventListener('click', function(e) { e.preventDefault(); openAuthModal(); });
    
    const btnOpenAuthFromPaso5 = document.getElementById('btnOpenAuthFromPaso5');
    if (btnOpenAuthFromPaso5) btnOpenAuthFromPaso5.addEventListener('click', function(e) { e.preventDefault(); openAuthModal(); });
    
    const closeModalBtn = document.getElementById('closeModal');
    if (closeModalBtn) closeModalBtn.addEventListener('click', closeAuthModal);
    
    const authModal = document.getElementById('authModal');
    if (authModal) authModal.addEventListener('click', function(e) { if (e.target === this) closeAuthModal(); });
    
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeAuthModal(); });
    
    // Tabs del modal
    const tabs = document.querySelectorAll('.auth-tab');
    const panels = {
        login: document.getElementById('panelLogin'),
        register: document.getElementById('panelRegister')
    };
    
    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            tabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            const tabName = this.dataset.tab;
            Object.keys(panels).forEach(key => {
                if (panels[key]) panels[key].classList.toggle('active', key === tabName);
            });
        });
    });
    
    // ========================================
    // CONFIRMACIÓN AL PUBLICAR
    // ========================================
    const btnPublicar = document.getElementById('btnPublicar');
    if (btnPublicar) {
        btnPublicar.addEventListener('click', function(e) {
            e.preventDefault();
            
            const imagenesActuales = JSON.parse(imagenesGuardadas.value || '[]');
            if (imagenesActuales.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Faltan imágenes',
                    text: 'Debes subir al menos una imagen de la propiedad',
                    confirmButtonColor: '#c9a84c'
                });
                return;
            }
            
            Swal.fire({
                title: '¿Confirmar publicación?',
                text: 'Una vez publicada, la propiedad estará visible para todos los usuarios',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Sí, publicar',
                cancelButtonText: 'Cancelar',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Publicando propiedad...',
                        text: 'Por favor espera un momento',
                        allowOutsideClick: false,
                        didOpen: () => { Swal.showLoading(); }
                    });
                    document.getElementById('wizardForm').submit();
                }
            });
        });
    }
    
    <?php if ($show_auth && !isset($_SESSION['usuario_id'])): ?>
        openAuthModal();
    <?php endif; ?>
});
</script>

</body>
</html>