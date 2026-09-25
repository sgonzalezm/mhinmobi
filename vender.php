<?php
session_start();

require_once 'guardar_propiedad.php';
require_once 'includes/conexion.php';
require_once 'includes/auth.php';

if (!estaLogueado()) {
    header('Location: login.php');
    exit;
}

$usuario = obtenerUsuarioActual($conn);
if (!$usuario) {
    cerrarSesion();
    header('Location: login.php');
    exit;
}

if (!isset($_SESSION['form_venta'])) {
    $_SESSION['form_venta'] = [];
}

$accesorios_disponibles = obtenerAccesorios();
$estados_disponibles = obtenerEstados();
$municipios_disponibles = obtenerMunicipios();

// ========================================
// PROCESAR POST
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errores = [];
    
    foreach ($_POST as $key => $value) {
        $excluir = ['action', 'confirmar', 
                    'login_email', 'login_password', 'reg_nombre', 'reg_email', 'reg_password'];
        
        if (!in_array($key, $excluir)) {
            if (is_array($value)) {
                $_SESSION['form_venta'][$key] = array_map('htmlspecialchars', $value);
            } else {
                $_SESSION['form_venta'][$key] = htmlspecialchars(trim($value));
            }
        }
    }
    
    if (isset($_POST['imagenes_guardadas'])) {
        $imagenes = json_decode($_POST['imagenes_guardadas'], true);
        if (is_array($imagenes) && !empty($imagenes)) {
            $_SESSION['form_venta']['imagenes'] = $imagenes;
        } else {
            if (!isset($_SESSION['form_venta']['imagenes']) || empty($_SESSION['form_venta']['imagenes'])) {
                $_SESSION['form_venta']['imagenes'] = [];
            }
        }
    }
    
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
    
    // Login
    if (isset($_POST['login_email']) && isset($_POST['login_password'])) {
        $socio = verificarLogin($_POST['login_email'], $_POST['login_password']);
        if ($socio) {
            $_SESSION['usuario_id'] = $socio['id'];
            $_SESSION['usuario_nombre'] = $socio['name'];
            $_SESSION['usuario_email'] = $socio['email'];
            $_SESSION['usuario_role'] = $socio['role'];
            header("Location: vender.php?login=success");
            exit();
        } else {
            $_SESSION['errores'] = ['Credenciales incorrectas.'];
            header("Location: vender.php?show_auth=true");
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
                header("Location: vender.php?register=success");
                exit();
            } else {
                $errores[] = 'Error al registrar. El email ya está en uso.';
            }
        }
        
        if (!empty($errores)) {
            $_SESSION['errores'] = $errores;
            header("Location: vender.php?show_auth=true");
            exit();
        }
    }
    
    // Validaciones del formulario completo
    if (empty($errores)) {
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
        
        if (empty($_SESSION['form_venta']['recamaras']) || !is_numeric($_SESSION['form_venta']['recamaras'])) $errores[] = 'El número de recámaras debe ser un número válido';
        if (empty($_SESSION['form_venta']['domicilio'])) $errores[] = 'El domicilio es obligatorio';
        if (empty($_SESSION['form_venta']['colonia'])) $errores[] = 'La colonia es obligatoria';
        if (empty($_SESSION['form_venta']['estado'])) $errores[] = 'Selecciona un estado';
        if (empty($_SESSION['form_venta']['municipio'])) $errores[] = 'Selecciona un municipio';
        
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
        
        if (empty($_SESSION['form_venta']['imagenes'])) {
            $errores[] = 'Debes subir al menos una imagen';
        }
    }
    
    if (!empty($errores)) {
        $_SESSION['errores'] = $errores;
        header("Location: vender.php");
        exit();
    }
    
    unset($_SESSION['errores']);
    
    // Confirmar - guardar propiedad
    if (isset($_POST['confirmar'])) {
        if (!isset($_SESSION['usuario_id'])) {
            $_SESSION['errores'] = ['Debes iniciar sesión para publicar'];
            header("Location: vender.php?show_auth=true");
            exit();
        }
        
        if (isset($_POST['imagenes_guardadas'])) {
            $imagenes = json_decode($_POST['imagenes_guardadas'], true);
            if (is_array($imagenes) && !empty($imagenes)) {
                $_SESSION['form_venta']['imagenes'] = $imagenes;
            }
        }
        
        $resultado = guardarPropiedad($_SESSION['form_venta'], $_SESSION['usuario_id']);
        
        if ($resultado['success']) {
            $_SESSION['ultima_propiedad_id'] = $resultado['property_id'];
            $_SESSION['mensaje_exito'] = '¡Propiedad publicada exitosamente!';
            unset($_SESSION['form_venta']);
            unset($_SESSION['errores']);
            header("Location: vender_exito.php");
            exit();
        } else {
            $_SESSION['errores'] = ['Error al publicar la propiedad: ' . $resultado['error']];
            header("Location: vender.php");
            exit();
        }
    }
}

// ========================================
// RECUPERAR DATOS
// ========================================
$data = $_SESSION['form_venta'] ?? [];
$errores = $_SESSION['errores'] ?? [];
unset($_SESSION['errores']);
$show_auth = isset($_GET['show_auth']) ? true : false;

// Si hay estado seleccionado, filtrar municipios para el render inicial
$municipios_filtrados = [];
$municipios_filtrados = obtenerMunicipios();

$socio = null;
if (isset($_SESSION['usuario_id'])) {
    try {
        $sql = "SELECT id, name, email, rol FROM users WHERE id = ? AND activo = 1";
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
            cursor: grab;
        }
        .image-preview-item:active {
            cursor: grabbing;
        }
        .image-preview-item.dragging {
            opacity: 0.4;
            border-color: #c9a84c;
            transform: scale(0.95);
        }
        .image-preview-item.drag-over {
            border-color: #c9a84c;
            transform: scale(1.05);
            box-shadow: 0 0 0 3px rgba(201, 168, 76, 0.3);
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
            pointer-events: none;
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
        .image-preview-item .drag-handle {
            position: absolute;
            bottom: 5px;
            right: 5px;
            background: rgba(0, 0, 0, 0.6);
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            z-index: 5;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .image-preview-item:hover .drag-handle {
            opacity: 1;
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
        .wizard-content {
            max-width: 900px;
            margin: 0 auto;
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
        .form-section {
            margin-bottom: 35px;
            padding-bottom: 25px;
            border-bottom: 1px solid #e8e8e8;
        }
        .form-section:last-of-type {
            border-bottom: none;
        }
        .form-section h3 {
            color: #1a1a2e;
            font-size: 18px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
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
        .drag-instruction {
            font-size: 12px;
            color: #c9a84c;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .loading-municipios {
            font-size: 12px;
            color: #c9a84c;
            margin-left: 8px;
            display: none;
        }
        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; }
            .accesorios-grid { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); }
            .btn-group { flex-direction: column; }
            .btn-group .btn { width: 100%; justify-content: center; }
            .wizard-content { padding: 20px; }
        }
    </style>
</head>
<body>

<?php include 'modulos/sidebar.php'; ?>

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

    <div class="wizard-content">
        <?php if (isset($_GET['login']) && $_GET['login'] == 'success'): ?>
            <div class="success-message"><p>✅ ¡Sesión iniciada correctamente! Ahora puedes publicar tu propiedad.</p></div>
        <?php endif; ?>
        <?php if (isset($_GET['register']) && $_GET['register'] == 'success'): ?>
            <div class="success-message"><p>✅ ¡Registro exitoso! Ahora puedes publicar tu propiedad.</p></div>
        <?php endif; ?>
        
        <form method="POST" id="wizardForm" enctype="multipart/form-data" action="">
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

            <!-- ======================================== -->
            <!-- SECCIÓN 1: DATOS BÁSICOS -->
            <!-- ======================================== -->
            <div class="form-section">
                <h3>🏠 Datos Básicos</h3>
                
                <div class="form-group">
                    <label for="titulo">Título de la Propiedad <span class="required">*</span></label>
                    <input type="text" id="titulo" name="titulo" value="<?php echo htmlspecialchars($data['titulo'] ?? ''); ?>" placeholder="Ej: Hermosa casa en zona residencial" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="precio">Precio <span class="required">*</span></label>
                        <input type="number" id="precio" name="precio" value="<?php echo htmlspecialchars($data['precio'] ?? ''); ?>" placeholder="0.00" min="0" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label for="tipo_operacion">Tipo de Operación <span class="required">*</span></label>
                        <select id="tipo_operacion" name="tipo_operacion" required>
                            <option value="">Seleccionar</option>
                            <option value="venta" <?php echo (isset($data['tipo_operacion']) && $data['tipo_operacion'] == 'venta') ? 'selected' : ''; ?>>Venta</option>
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
                                <option value="planta_baja" <?php echo (isset($data['nivel_duplex']) && $data['nivel_duplex'] == 'planta_baja') ? 'selected' : ''; ?>>Planta Baja</option>
                                <option value="planta_alta" <?php echo (isset($data['nivel_duplex']) && $data['nivel_duplex'] == 'planta_alta') ? 'selected' : ''; ?>>Planta Alta</option>
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
            </div>

            <!-- ======================================== -->
            <!-- SECCIÓN 2: DETALLES -->
            <!-- ======================================== -->
            <div class="form-section">
                <h3>📐 Detalles de la Propiedad</h3>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="m2">Metros Cuadrados <span style="color: #999; font-weight: normal;">(opcional)</span></label>
                        <input type="number" id="m2" name="m2" value="<?php echo htmlspecialchars($data['m2'] ?? ''); ?>" placeholder="m² (opcional)" min="0">
                    </div>
                    <div class="form-group">
                        <label for="recamaras">Número de Recámaras <span class="required">*</span></label>
                        <input type="number" id="recamaras" name="recamaras" value="<?php echo htmlspecialchars($data['recamaras'] ?? ''); ?>" placeholder="Ej: 3" min="0" max="20" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="banos">Baños</label>
                        <input type="number" id="banos" name="banos" value="<?php echo number_format($data['banos'] ?? 2, 1, '.', ''); ?>" placeholder="Ej: 2" min="0" max="10" step="0.5">
                    </div>
                    <div class="form-group">
                        <label for="estacionamiento">Estacionamientos</label>
                        <input type="number" id="estacionamiento" name="estacionamiento" value="<?php echo htmlspecialchars($data['estacionamiento'] ?? ''); ?>" placeholder="Ej: 2" min="0" max="10">
                    </div>
                </div>
                
                <!-- UBICACIÓN CON ESTADOS Y MUNICIPIOS DESDE BD -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="estado">Estado <span class="required">*</span></label>
                        <select id="estado" name="estado" required>
                            <option value="">Seleccionar Estado</option>
                            <?php foreach ($estados_disponibles as $estado_item): ?>
                                <option value="<?php echo htmlspecialchars($estado_item['nombre']); ?>" 
                                    <?php echo (isset($data['estado']) && $data['estado'] == $estado_item['nombre']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($estado_item['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="municipio">Municipio <span class="required">*</span></label>
                        <select id="municipio" name="municipio" required>
                            <option value="">Seleccionar Municipio</option>
                            <?php foreach ($municipios_filtrados as $municipio_item): ?>
                                <option value="<?php echo htmlspecialchars($municipio_item['nombre_municipio']); ?>" 
                                    <?php echo (isset($data['municipio']) && $data['municipio'] == $municipio_item['nombre_municipio']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($municipio_item['nombre_municipio']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="colonia">Colonia <span class="required">*</span></label>
                    <input type="text" id="colonia" name="colonia" value="<?php echo htmlspecialchars($data['colonia'] ?? ''); ?>" placeholder="Ej: Colonia Centro" required>
                </div>
                <div class="form-group">
                    <label for="domicilio">Domicilio (Calle y número) <span class="required">*</span></label>
                    <input type="text" id="domicilio" name="domicilio" value="<?php echo htmlspecialchars($data['domicilio'] ?? ''); ?>" placeholder="Ej: Av. Juárez #123" required>
                </div>

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

                <div class="form-group">
                    <label>Fotos de la Propiedad <span style="color: #666; font-weight: normal;">(máximo 10 fotos)</span></label>
                    <div class="image-upload-container" id="imageUploadContainer">
                        <div class="image-upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                        <div class="image-upload-text"><strong>Haz clic o arrastra</strong> tus imágenes aquí</div>
                        <div style="font-size: 12px; color: #999; margin-top: 5px;">Formatos: JPG, PNG, GIF, WEBP • Tamaño máximo: 5MB por imagen</div>
                        <input type="file" id="fileInput" name="imagenes[]" multiple accept="image/*" style="display: none;">
                    </div>
                    <div class="drag-instruction">
                        <i class="fas fa-arrows-alt"></i> Arrastra las imágenes para cambiar el orden. La primera será la principal.
                    </div>
                    <div class="upload-progress" id="uploadProgress">
                        <div class="progress-bar"><div class="progress-fill" id="progressFill"></div></div>
                        <div class="progress-text" id="progressText">Subiendo imágenes...</div>
                    </div>
                    <div class="image-preview-grid" id="imagePreviewGrid">
                        <?php if (!empty($data['imagenes'])): ?>
                            <?php foreach ($data['imagenes'] as $index => $imagen): ?>
                                <div class="image-preview-item" data-index="<?php echo $index; ?>" draggable="true">
                                    <img src="<?php echo htmlspecialchars($imagen); ?>" alt="Imagen <?php echo $index + 1; ?>">
                                    <?php if ($index === 0): ?><span class="image-main-badge">Principal</span><?php endif; ?>
                                    <span class="image-number"><?php echo $index + 1; ?></span>
                                    <span class="drag-handle"><i class="fas fa-grip-vertical"></i></span>
                                    <button type="button" class="remove-image" data-index="<?php echo $index; ?>"><i class="fas fa-times"></i></button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ======================================== -->
            <!-- SECCIÓN 3: LEGAL Y FINANCIERO -->
            <!-- ======================================== -->
            <div class="form-section">
                <h3>⚖️ Situación Legal y Financiera</h3>
                
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
            </div>

            <!-- ======================================== -->
            <!-- BOTONES DE ACCIÓN -->
            <!-- ======================================== -->
            <div class="btn-group">
                <?php if (isset($_SESSION['usuario_id'])): ?>
                    <button type="submit" class="btn btn-success" name="confirmar" value="1" id="btnPublicar"><i class="fas fa-check-circle"></i> Confirmar y Publicar</button>
                <?php else: ?>
                    <button type="button" class="btn btn-success" id="btnOpenAuth"><i class="fas fa-lock"></i> Iniciar sesión para publicar</button>
                <?php endif; ?>
            </div>
        </form>
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
    // SISTEMA DE IMÁGENES CON DRAG & DROP PARA REORDENAR
    // ========================================
    const uploadContainer = document.getElementById('imageUploadContainer');
    const fileInput = document.getElementById('fileInput');
    const previewGrid = document.getElementById('imagePreviewGrid');
    const progressContainer = document.getElementById('uploadProgress');
    const progressFill = document.getElementById('progressFill');
    const progressText = document.getElementById('progressText');
    const imagenesGuardadas = document.getElementById('imagenesGuardadas');
    
    let imagenes = [];
    let draggedIndex = null;
    
    try {
        const existentes = JSON.parse(imagenesGuardadas.value || '[]');
        if (existentes.length > 0) {
            imagenes = existentes;
            renderPreview();
        }
    } catch (e) {
        console.error('Error al cargar imágenes:', e);
    }
    
    if (uploadContainer && fileInput) {
        uploadContainer.addEventListener('click', function(e) {
            if (e.target.closest('#imagePreviewGrid')) return;
            if (e.target.closest('.remove-image')) return;
            if (e.target.closest('.image-preview-item')) return;
            fileInput.click();
        });
    }
    
    if (uploadContainer) {
        uploadContainer.addEventListener('dragover', function(e) {
            if (draggedIndex !== null) return;
            e.preventDefault();
            e.stopPropagation();
            this.classList.add('dragover');
        });
        
        uploadContainer.addEventListener('dragleave', function(e) {
            if (draggedIndex !== null) return;
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('dragover');
        });
        
        uploadContainer.addEventListener('drop', function(e) {
            if (draggedIndex !== null) {
                e.preventDefault();
                e.stopPropagation();
                return;
            }
            
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('dragover');
            
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                processFiles(e.dataTransfer.files);
            }
        });
    }
    
    document.addEventListener('dragover', function(e) { e.preventDefault(); });
    document.addEventListener('drop', function(e) { e.preventDefault(); });
    
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
            div.draggable = true;
            
            const img = document.createElement('img');
            img.src = imagen;
            img.alt = `Imagen ${index + 1}`;
            img.loading = 'lazy';
            img.draggable = false;
            
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
            
            const dragHandle = document.createElement('span');
            dragHandle.className = 'drag-handle';
            dragHandle.innerHTML = '<i class="fas fa-grip-vertical"></i>';
            
            div.appendChild(img);
            div.appendChild(removeBtn);
            div.appendChild(numberBadge);
            div.appendChild(dragHandle);
            
            if (index === 0) {
                const mainBadge = document.createElement('span');
                mainBadge.className = 'image-main-badge';
                mainBadge.textContent = 'Principal';
                div.appendChild(mainBadge);
            }
            
            div.addEventListener('dragstart', handleDragStart);
            div.addEventListener('dragover', handleDragOver);
            div.addEventListener('dragenter', handleDragEnter);
            div.addEventListener('dragleave', handleDragLeave);
            div.addEventListener('drop', handleDrop);
            div.addEventListener('dragend', handleDragEnd);
            
            previewGrid.appendChild(div);
        });
    }
    
    function handleDragStart(e) {
        draggedIndex = parseInt(this.dataset.index);
        this.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', 'reorder-' + draggedIndex);
    }
    
    function handleDragOver(e) {
        if (draggedIndex === null) return;
        e.preventDefault();
        e.stopPropagation();
        e.dataTransfer.dropEffect = 'move';
    }
    
    function handleDragEnter(e) {
        if (draggedIndex === null) return;
        e.preventDefault();
        e.stopPropagation();
        this.classList.add('drag-over');
    }
    
    function handleDragLeave(e) {
        if (draggedIndex === null) return;
        e.preventDefault();
        e.stopPropagation();
        this.classList.remove('drag-over');
    }
    
    function handleDrop(e) {
        if (draggedIndex === null) return;
        e.preventDefault();
        e.stopPropagation();
        this.classList.remove('drag-over');
        
        const targetIndex = parseInt(this.dataset.index);
        if (draggedIndex === targetIndex) return;
        
        const [movedItem] = imagenes.splice(draggedIndex, 1);
        imagenes.splice(targetIndex, 0, movedItem);
        
        draggedIndex = targetIndex;
        
        renderPreview();
        updateImagenesGuardadas();
    }
    
    function handleDragEnd(e) {
        this.classList.remove('dragging');
        document.querySelectorAll('.image-preview-item').forEach(item => {
            item.classList.remove('drag-over');
        });
        draggedIndex = null;
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
    
    const closeModalBtn = document.getElementById('closeModal');
    if (closeModalBtn) closeModalBtn.addEventListener('click', closeAuthModal);
    
    const authModal = document.getElementById('authModal');
    if (authModal) authModal.addEventListener('click', function(e) { if (e.target === this) closeAuthModal(); });
    
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeAuthModal(); });
    
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
            const imagenesGuardadas = document.getElementById('imagenesGuardadas');
            let imagenes = [];
            try {
                imagenes = JSON.parse(imagenesGuardadas.value || '[]');
            } catch (e) {
                imagenes = [];
            }
            
            if (imagenes.length === 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Faltan imágenes',
                    text: 'Debes subir al menos una imagen de la propiedad',
                    confirmButtonColor: '#c9a84c'
                });
                return;
            }
            
            e.preventDefault();
            
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
                    const form = document.getElementById('wizardForm');
                    if (form) {
                        imagenesGuardadas.value = JSON.stringify(imagenes);
                        
                        const confirmInput = document.createElement('input');
                        confirmInput.type = 'hidden';
                        confirmInput.name = 'confirmar';
                        confirmInput.value = '1';
                        form.appendChild(confirmInput);
                        
                        form.submit();
                    }
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