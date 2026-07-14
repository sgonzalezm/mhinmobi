<?php
session_start();

// ========================================
// INCLUIR FUNCIONES DE BASE DE DATOS
// ========================================
require_once 'guardar_propiedad.php';

// Inicializar sesión si no existe
if (!isset($_SESSION['form_venta'])) {
    $_SESSION['form_venta'] = [];
}

// Determinar el paso actual
$paso = isset($_GET['paso']) ? (int)$_GET['paso'] : 1;
$paso = max(1, min(4, $paso));

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
    
    // ========================================
    // PROCESAR ARCHIVOS (IMÁGENES)
    // ========================================
    if (isset($_FILES['imagenes']) && !empty($_FILES['imagenes']['name'][0])) {
        $upload_dir = 'uploads/propiedades/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $imagenes_subidas = [];
        $max_files = 5;
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $max_size = 5 * 1024 * 1024;
        
        for ($i = 0; $i < min(count($_FILES['imagenes']['name']), $max_files); $i++) {
            if ($_FILES['imagenes']['error'][$i] === UPLOAD_ERR_OK) {
                $file_type = mime_content_type($_FILES['imagenes']['tmp_name'][$i]);
                if (in_array($file_type, $allowed_types) && $_FILES['imagenes']['size'][$i] <= $max_size) {
                    $extension = pathinfo($_FILES['imagenes']['name'][$i], PATHINFO_EXTENSION);
                    $filename = uniqid() . '.' . $extension;
                    $filepath = $upload_dir . $filename;
                    
                    if (move_uploaded_file($_FILES['imagenes']['tmp_name'][$i], $filepath)) {
                        $imagenes_subidas[] = $filepath;
                    }
                }
            }
        }
        
        if (!empty($imagenes_subidas)) {
            $_SESSION['form_venta']['imagenes'] = $imagenes_subidas;
        }
    }
    
    // ========================================
    // VALIDACIONES POR PASO
    // ========================================
    switch ($paso_actual) {
        case 1:
            if (empty($_SESSION['form_venta']['titulo'])) {
                $errores[] = 'El título es obligatorio';
            }
            if (empty($_SESSION['form_venta']['precio']) || !is_numeric($_SESSION['form_venta']['precio'])) {
                $errores[] = 'El precio debe ser un número válido';
            }
            if (empty($_SESSION['form_venta']['tipo_operacion'])) {
                $errores[] = 'Selecciona el tipo de operación';
            }
            break;
            
        case 2:
            if (empty($_SESSION['form_venta']['m2']) || !is_numeric($_SESSION['form_venta']['m2'])) {
                $errores[] = 'Los metros cuadrados deben ser un número válido';
            }
            if (empty($_SESSION['form_venta']['recamaras']) || !is_numeric($_SESSION['form_venta']['recamaras'])) {
                $errores[] = 'El número de recámaras debe ser un número válido';
            }
            if (empty($_SESSION['form_venta']['ubicacion'])) {
                $errores[] = 'La ubicación es obligatoria';
            }
            break;
            
        case 3:
            // Si el usuario no está logueado, validación especial
            if (!isset($_SESSION['usuario_id'])) {
                // No hay error, solo guardamos y redirigimos a login modal
                // Esto se maneja con JavaScript
            }
            break;
            
        case 4:
            if (!isset($_SESSION['usuario_id'])) {
                $errores[] = 'Debes iniciar sesión para publicar';
            }
            break;
    }
    
    // ========================================
    // MANEJAR LOGIN VÍA AJAX O POST NORMAL
    // ========================================
    if (isset($_POST['login_email']) && isset($_POST['login_password'])) {
        $email = $_POST['login_email'];
        $password = $_POST['login_password'];
        
        $usuario = verificarLogin($email, $password);
        if ($usuario) {
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['usuario_nombre'] = $usuario['name'];
            $_SESSION['usuario_email'] = $usuario['email'];
            $_SESSION['usuario_role'] = $usuario['role'];
            
            // Redirigir al paso 4 con mensaje de éxito
            header("Location: vender.php?paso=4&login=success");
            exit();
        } else {
            $errores[] = 'Credenciales incorrectas';
            $_SESSION['errores'] = $errores;
            header("Location: vender.php?paso=" . $paso_actual . "&show_auth=true");
            exit();
        }
    }
    
    // ========================================
    // MANEJAR REGISTRO
    // ========================================
    if (isset($_POST['reg_nombre']) && isset($_POST['reg_email']) && isset($_POST['reg_password'])) {
        $nombre = $_POST['reg_nombre'];
        $email = $_POST['reg_email'];
        $password = $_POST['reg_password'];
        
        // Validaciones de registro
        if (strlen($password) < 6) {
            $errores[] = 'La contraseña debe tener al menos 6 caracteres';
        }
        
        if (empty($errores)) {
            $usuario_id = registrarUsuario($nombre, $email, $password);
            if ($usuario_id) {
                $_SESSION['usuario_id'] = $usuario_id;
                $_SESSION['usuario_nombre'] = $nombre;
                $_SESSION['usuario_email'] = $email;
                $_SESSION['usuario_role'] = 'propietario';
                
                header("Location: vender.php?paso=4&register=success");
                exit();
            } else {
                $errores[] = 'Error al registrar usuario. El email podría estar en uso.';
            }
        }
        
        if (!empty($errores)) {
            $_SESSION['errores'] = $errores;
            header("Location: vender.php?paso=" . $paso_actual . "&show_auth=true");
            exit();
        }
    }
    
    // ========================================
    // SI HAY ERRORES, VOLVER AL PASO ACTUAL
    // ========================================
    if (!empty($errores)) {
        $_SESSION['errores'] = $errores;
        header("Location: vender.php?paso=" . $paso_actual);
        exit();
    }
    
    unset($_SESSION['errores']);
    
    // ========================================
    // NAVEGACIÓN ENTRE PASOS
    // ========================================
    if (isset($_POST['siguiente_paso'])) {
        $siguiente = (int)$_POST['siguiente_paso'];
        
        // Si el usuario intenta ir al paso 4 y no está logueado
        if ($siguiente == 4 && !isset($_SESSION['usuario_id'])) {
            // Guardamos los datos y mostramos modal de login
            header("Location: vender.php?paso=3&show_auth=true");
            exit();
        }
        
        header("Location: vender.php?paso=" . $siguiente);
        exit();
    }
    
    // ========================================
    // CONFIRMAR Y PUBLICAR - CON DEPURACIÓN
    // ========================================
    if (isset($_POST['confirmar'])) {
        if (!isset($_SESSION['usuario_id'])) {
            $_SESSION['errores'] = ['Debes iniciar sesión para publicar'];
            header("Location: vender.php?paso=4&show_auth=true");
            exit();
        }
        
        // ========================================
        // DEPURACIÓN 1: Ver datos antes de guardar
        // ========================================
        error_log("=== CONFIRMAR PUBLICACIÓN ===");
        error_log("Usuario ID: " . $_SESSION['usuario_id']);
        error_log("Datos del formulario: " . print_r($_SESSION['form_venta'], true));
        
        $resultado = guardarPropiedad($_SESSION['form_venta'], $_SESSION['usuario_id']);
        
        // ========================================
        // DEPURACIÓN 2: Ver resultado de guardar
        // ========================================
        error_log("Resultado de guardarPropiedad: " . print_r($resultado, true));
        
        if ($resultado['success']) {
            $_SESSION['ultima_propiedad_id'] = $resultado['property_id'];
            
            // ========================================
            // DEPURACIÓN 3: Verificar que se guardó en sesión
            // ========================================
            error_log("Property ID guardado en sesión: " . $_SESSION['ultima_propiedad_id']);
            error_log("Redirigiendo a vender_exito.php");
            
            // FORZAR redirección
            echo "Redirigiendo a vender_exito.php...";
            echo "<script>window.location.href='vender_exito.php';</script>";
            header("Location: vender_exito.php");
            exit();
        } else {
            $_SESSION['errores'] = ['Error al publicar la propiedad: ' . $resultado['error']];
            error_log("ERROR: " . $resultado['error']);
            header("Location: vender.php?paso=4");
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
$mensaje_exito = $_SESSION['mensaje_exito'] ?? '';
unset($_SESSION['mensaje_exito']);
$show_auth = isset($_GET['show_auth']) ? true : false;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/wizard.css">
    <title>Publicar Propiedad - Wizard</title>
    <style>
        /* ======================================== */
        /* ESTILOS DEL MODAL DE AUTENTICACIÓN */
        /* ======================================== */
        .modal-overlay {
            display: <?php echo $show_auth ? 'flex' : 'none'; ?>;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 40px;
            max-width: 700px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 30px 80px rgba(0,0,0,0.3);
            animation: slideUp 0.4s ease;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(40px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .modal-header h2 {
            margin: 0;
            font-size: 24px;
            color: #1a1a2e;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #999;
            transition: all 0.3s;
            padding: 0 10px;
        }
        
        .modal-close:hover {
            color: #333;
            transform: rotate(90deg);
        }
        
        .auth-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            background: #f8f9fa;
            padding: 5px;
            border-radius: 12px;
        }
        
        .auth-tab {
            flex: 1;
            padding: 12px;
            text-align: center;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            background: transparent;
            color: #666;
        }
        
        .auth-tab.active {
            background: white;
            color: #1a1a2e;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .auth-tab:hover {
            background: rgba(255,255,255,0.5);
        }
        
        .auth-panel {
            display: none;
            animation: fadeIn 0.3s ease;
        }
        
        .auth-panel.active {
            display: block;
        }
        
        .auth-panel .form-group {
            margin-bottom: 18px;
        }
        
        .auth-panel .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #333;
            font-size: 14px;
        }
        
        .auth-panel .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.3s;
            box-sizing: border-box;
        }
        
        .auth-panel .form-group input:focus {
            border-color: #1a1a2e;
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 26, 46, 0.1);
        }
        
        .auth-panel .btn-auth {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
        }
        
        .btn-auth.btn-login {
            background: #1a1a2e;
            color: white;
        }
        
        .btn-auth.btn-login:hover {
            background: #2d2d44;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(26, 26, 46, 0.3);
        }
        
        .btn-auth.btn-register {
            background: #28a745;
            color: white;
        }
        
        .btn-auth.btn-register:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(40, 167, 69, 0.3);
        }
        
        .auth-error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left: 4px solid #dc3545;
            font-size: 14px;
        }
        
        .auth-error ul {
            margin: 0;
            padding-left: 20px;
        }
        
        .auth-success {
            background: #d4edda;
            color: #155724;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left: 4px solid #28a745;
        }
        
        @media (max-width: 640px) {
            .modal-content {
                padding: 25px 20px;
            }
            
            .auth-tabs {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>

<div class="main-layout">
    <!-- Sidebar de Progreso -->
    <aside class="progress-bar">
        <h3>📋 Progreso</h3>
        
        <div class="step <?php echo $paso >= 1 ? 'active' : ''; ?> <?php echo $paso > 1 ? 'completed' : ''; ?>" data-step="1">
            <span class="step-number">1</span> Datos Básicos
        </div>
        
        <div class="step <?php echo $paso >= 2 ? 'active' : ''; ?> <?php echo $paso > 2 ? 'completed' : ''; ?>" data-step="2">
            <span class="step-number">2</span> Detalles
        </div>
        
        <div class="step <?php echo $paso >= 3 ? 'active' : ''; ?> <?php echo $paso > 3 ? 'completed' : ''; ?>" data-step="3">
            <span class="step-number">3</span> Legal
        </div>
        
        <div class="step <?php echo $paso >= 4 ? 'active' : ''; ?> <?php echo $paso > 4 ? 'completed' : ''; ?>" data-step="4">
            <span class="step-number">4</span> Autenticación
        </div>
    </aside>

    <!-- Contenido del Wizard -->
    <div class="wizard-container">
        <?php if (isset($_GET['login']) && $_GET['login'] == 'success'): ?>
            <div class="success-message" style="background: #d4edda; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #28a745;">
                <p style="margin: 0; color: #155724;">✅ ¡Sesión iniciada correctamente! Ahora puedes publicar tu propiedad.</p>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['register']) && $_GET['register'] == 'success'): ?>
            <div class="success-message" style="background: #d4edda; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #28a745;">
                <p style="margin: 0; color: #155724;">✅ ¡Registro exitoso! Ahora puedes publicar tu propiedad.</p>
            </div>
        <?php endif; ?>
        
        <form method="POST" id="wizardForm" class="fade-in" enctype="multipart/form-data" action="">
            <input type="hidden" name="paso_actual" value="<?php echo $paso; ?>">
            
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
            <!-- PASO 1: Datos Básicos -->
            <!-- ======================================== -->
            <?php if ($paso == 1): ?>
                <h2>🏠 Datos Básicos</h2>
                <p class="subtitle">Cuéntanos sobre tu propiedad</p>

                <div class="form-group">
                    <label for="titulo">Título de la Propiedad <span class="required">*</span></label>
                    <input type="text" id="titulo" name="titulo" 
                           value="<?php echo htmlspecialchars($data['titulo'] ?? ''); ?>" 
                           placeholder="Ej: Hermosa casa en zona residencial" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="precio">Precio (USD) <span class="required">*</span></label>
                        <input type="number" id="precio" name="precio" 
                               value="<?php echo htmlspecialchars($data['precio'] ?? ''); ?>" 
                               placeholder="0.00" min="0" step="0.01" required>
                    </div>

                    <div class="form-group">
                        <label for="tipo_operacion">Tipo de Operación <span class="required">*</span></label>
                        <select id="tipo_operacion" name="tipo_operacion" required>
                            <option value="">Seleccionar</option>
                            <option value="venta" <?php echo (isset($data['tipo_operacion']) && $data['tipo_operacion'] == 'venta') ? 'selected' : ''; ?>>Venta</option>
                            <option value="alquiler" <?php echo (isset($data['tipo_operacion']) && $data['tipo_operacion'] == 'alquiler') ? 'selected' : ''; ?>>Alquiler</option>
                            <option value="renta" <?php echo (isset($data['tipo_operacion']) && $data['tipo_operacion'] == 'renta') ? 'selected' : ''; ?>>Renta</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="descripcion">Descripción Breve</label>
                    <textarea id="descripcion" name="descripcion" 
                              placeholder="Describe tu propiedad en pocas palabras"><?php echo htmlspecialchars($data['descripcion'] ?? ''); ?></textarea>
                </div>

                <div class="btn-group">
                    <button type="submit" class="btn btn-dorado" name="siguiente_paso" value="2">
                        Siguiente → 
                    </button>
                </div>

            <!-- ======================================== -->
            <!-- PASO 2: Detalles -->
            <!-- ======================================== -->
            <?php elseif ($paso == 2): ?>
                <h2>📐 Detalles de la Propiedad</h2>
                <p class="subtitle">Especifica las características principales</p>

                <div class="form-row">
                    <div class="form-group">
                        <label for="m2">Metros Cuadrados <span class="required">*</span></label>
                        <input type="number" id="m2" name="m2" 
                               value="<?php echo htmlspecialchars($data['m2'] ?? ''); ?>" 
                               placeholder="m²" min="1" required>
                    </div>

                    <div class="form-group">
                        <label for="recamaras">Número de Recámaras <span class="required">*</span></label>
                        <input type="number" id="recamaras" name="recamaras" 
                               value="<?php echo htmlspecialchars($data['recamaras'] ?? ''); ?>" 
                               placeholder="Ej: 3" min="0" max="20" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="banos">Baños</label>
                        <input type="number" id="banos" name="banos" 
                               value="<?php echo htmlspecialchars($data['banos'] ?? ''); ?>" 
                               placeholder="Ej: 2" min="0" max="10">
                    </div>

                    <div class="form-group">
                        <label for="estacionamiento">Estacionamientos</label>
                        <input type="number" id="estacionamiento" name="estacionamiento" 
                               value="<?php echo htmlspecialchars($data['estacionamiento'] ?? ''); ?>" 
                               placeholder="Ej: 2" min="0" max="10">
                    </div>
                </div>

                <div class="form-group">
                    <label for="ubicacion">Ubicación <span class="required">*</span></label>
                    <input type="text" id="ubicacion" name="ubicacion" 
                           value="<?php echo htmlspecialchars($data['ubicacion'] ?? ''); ?>" 
                           placeholder="Ciudad, colonia, calle" required>
                </div>

                <div class="form-group">
                    <label for="imagenes">Subir Imágenes (máximo 5)</label>
                    <input type="file" id="imagenes" name="imagenes[]" multiple accept="image/*">
                    <small style="color: #666; display: block; margin-top: 0.3rem;">
                        Formatos: JPG, PNG, GIF, WEBP. Tamaño máximo: 5MB por imagen
                    </small>
                    <?php if (!empty($data['imagenes'])): ?>
                        <div style="margin-top: 0.5rem;">
                            <small>✅ <?php echo count($data['imagenes']); ?> imágenes subidas</small>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="btn-group">
                    <a href="?paso=1" class="btn btn-secondary">← Atrás</a>
                    <button type="submit" class="btn btn-dorado" name="siguiente_paso" value="3">
                        Siguiente → 
                    </button>
                </div>

            <!-- ======================================== -->
            <!-- PASO 3: Situación Legal -->
            <!-- ======================================== -->
            <?php elseif ($paso == 3): ?>
                <h2>⚖️ Situación Legal</h2>
                <p class="subtitle">Información sobre la situación legal de la propiedad</p>

                <div class="form-group">
                    <label>¿Tiene algún gravamen o embargo?</label>
                    <div class="radio-group">
                        <label><input type="radio" name="has_lien" value="1" <?php echo (isset($data['has_lien']) && $data['has_lien'] == 1) ? 'checked' : ''; ?>> Sí</label>
                        <label><input type="radio" name="has_lien" value="0" <?php echo (isset($data['has_lien']) && $data['has_lien'] == 0) ? 'checked' : ''; ?> <?php echo !isset($data['has_lien']) ? 'checked' : ''; ?>> No</label>
                    </div>
                </div>

                <div class="form-group" id="lien_group" style="display: <?php echo (isset($data['has_lien']) && $data['has_lien'] == 1) ? 'block' : 'none'; ?>;">
                    <label for="lien_description">Monto de la deuda o descripción del gravamen</label>
                    <input type="text" id="lien_description" name="debt_amount" 
                           value="<?php echo htmlspecialchars($data['debt_amount'] ?? ''); ?>" 
                           placeholder="Ej: $50,000 o descripción del gravamen">
                </div>

                <div class="form-group">
                    <label for="legal_status">Estado Legal de la Propiedad</label>
                    <select id="legal_status" name="legal_status">
                        <option value="libre" <?php echo (isset($data['legal_status']) && $data['legal_status'] == 'libre') ? 'selected' : ''; ?>>Libre de gravamenes</option>
                        <option value="intestado" <?php echo (isset($data['legal_status']) && $data['legal_status'] == 'intestado') ? 'selected' : ''; ?>>Intestado (sin testamento)</option>
                        <option value="sucesion" <?php echo (isset($data['legal_status']) && $data['legal_status'] == 'sucesion') ? 'selected' : ''; ?>>En proceso de sucesión</option>
                        <option value="litigio" <?php echo (isset($data['legal_status']) && $data['legal_status'] == 'litigio') ? 'selected' : ''; ?>>En litigio</option>
                        <option value="otro" <?php echo (isset($data['legal_status']) && $data['legal_status'] == 'otro') ? 'selected' : ''; ?>>Otro</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="legal_status_notes">Notas sobre la situación legal</label>
                    <textarea id="legal_status_notes" name="legal_status_notes" 
                              placeholder="Describe cualquier aspecto legal relevante"><?php echo htmlspecialchars($data['legal_status_notes'] ?? ''); ?></textarea>
                </div>

                <div class="btn-group">
                    <a href="?paso=2" class="btn btn-secondary">← Atrás</a>
                    <?php if (isset($_SESSION['usuario_id'])): ?>
                        <!-- Si ya está logueado, va directamente al paso 4 -->
                        <button type="submit" class="btn btn-dorado" name="siguiente_paso" value="4">
                            Siguiente → 
                        </button>
                    <?php else: ?>
                        <!-- Si no está logueado, abre el modal -->
                        <button type="button" class="btn btn-dorado" id="btnOpenAuth">
                            🔐 Siguiente (Inicia sesión)
                        </button>
                    <?php endif; ?>
                </div>

            <!-- ======================================== -->
            <!-- PASO 4: Autenticación y Resumen Final -->
            <!-- ======================================== -->
            <?php elseif ($paso == 4): ?>
                <h2>🔐 Resumen Final</h2>
                <p class="subtitle">Revisa los datos y confirma la publicación</p>

                <?php if (isset($_SESSION['usuario_id'])): ?>
                    <div style="background: #d4edda; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border-left: 4px solid #28a745;">
                        <p style="margin: 0; color: #155724;">
                            ✅ Publicando como <strong><?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?></strong>
                            <?php if (isset($_SESSION['usuario_role'])): ?>
                                <span style="font-size: 12px; background: #155724; color: white; padding: 2px 10px; border-radius: 12px; margin-left: 10px;">
                                    <?php echo ucfirst($_SESSION['usuario_role']); ?>
                                </span>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div style="background: #fff3cd; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border-left: 4px solid #ffc107;">
                        <p style="margin: 0; color: #856404;">
                            ⚠️ Debes iniciar sesión para publicar. 
                            <a href="?paso=3&show_auth=true" style="color: #1a1a2e; font-weight: 600;">Iniciar sesión</a>
                        </p>
                    </div>
                <?php endif; ?>

                <div class="resumen-card">
                    <h4 style="margin-bottom: 1rem; color: #1a1a2e;">📋 Resumen de la Propiedad</h4>
                    
                    <div class="resumen-item">
                        <span class="label">Título</span>
                        <span class="value"><?php echo htmlspecialchars($data['titulo'] ?? 'No especificado'); ?></span>
                    </div>
                    
                    <div class="resumen-item">
                        <span class="label">Operación</span>
                        <span class="value"><?php echo ucfirst(htmlspecialchars($data['tipo_operacion'] ?? 'No especificado')); ?></span>
                    </div>
                    
                    <div class="resumen-item">
                        <span class="label">Precio</span>
                        <span class="value">$<?php echo number_format($data['precio'] ?? 0, 2); ?></span>
                    </div>
                    
                    <div class="resumen-item">
                        <span class="label">Metros Cuadrados</span>
                        <span class="value"><?php echo htmlspecialchars($data['m2'] ?? 'No especificado'); ?> m²</span>
                    </div>
                    
                    <div class="resumen-item">
                        <span class="label">Recámaras</span>
                        <span class="value"><?php echo htmlspecialchars($data['recamaras'] ?? 'No especificado'); ?></span>
                    </div>
                    
                    <?php if (!empty($data['ubicacion'])): ?>
                        <div class="resumen-item">
                            <span class="label">Ubicación</span>
                            <span class="value"><?php echo htmlspecialchars($data['ubicacion']); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($data['legal_status'])): ?>
                        <div class="resumen-item">
                            <span class="label">Estado Legal</span>
                            <span class="value"><?php echo ucfirst(htmlspecialchars($data['legal_status'])); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($data['imagenes'])): ?>
                        <div class="resumen-item">
                            <span class="label">Imágenes</span>
                            <span class="value"><?php echo count($data['imagenes']); ?> imágenes subidas</span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="resumen-total">
                        Total: $<?php echo number_format($data['precio'] ?? 0, 2); ?>
                    </div>
                </div>

                <div class="btn-group">
                    <a href="?paso=3" class="btn btn-secondary">← Atrás</a>
                    <?php if (isset($_SESSION['usuario_id'])): ?>
                        <button type="submit" class="btn btn-success" name="confirmar" value="1">
                            ✅ Confirmar y Publicar
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn btn-success" id="btnOpenAuthFromPaso4">
                            🔑 Iniciar sesión para publicar
                        </button>
                    <?php endif; ?>
                </div>

            <?php endif; ?>
        </form>
    </div>
</div>

<!-- ======================================== -->
<!-- MODAL DE AUTENTICACIÓN -->
<!-- ======================================== -->
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
        
        <!-- Mensajes de error -->
        <?php if (!empty($errores)): ?>
            <div class="auth-error">
                <ul>
                    <?php foreach ($errores as $error): ?>
                        <li>⚠️ <?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <!-- ======================================== -->
        <!-- PANEL DE LOGIN -->
        <!-- ======================================== -->
        <div class="auth-panel active" id="panelLogin">
            <form method="POST" action="">
                <input type="hidden" name="paso_actual" value="3">
                
                <div class="form-group">
                    <label for="modal_login_email">Correo Electrónico</label>
                    <input type="email" id="modal_login_email" name="login_email" 
                           placeholder="tu@email.com" required>
                </div>
                
                <div class="form-group">
                    <label for="modal_login_password">Contraseña</label>
                    <input type="password" id="modal_login_password" name="login_password" 
                           placeholder="••••••••" required>
                </div>
                
                <button type="submit" class="btn-auth btn-login">
                    Iniciar Sesión
                </button>
            </form>
        </div>
        
        <!-- ======================================== -->
        <!-- PANEL DE REGISTRO -->
        <!-- ======================================== -->
        <div class="auth-panel" id="panelRegister">
            <form method="POST" action="">
                <input type="hidden" name="paso_actual" value="3">
                
                <div class="form-group">
                    <label for="modal_reg_nombre">Nombre Completo</label>
                    <input type="text" id="modal_reg_nombre" name="reg_nombre" 
                           placeholder="Tu nombre completo" required>
                </div>
                
                <div class="form-group">
                    <label for="modal_reg_email">Correo Electrónico</label>
                    <input type="email" id="modal_reg_email" name="reg_email" 
                           placeholder="tu@email.com" required>
                </div>
                
                <div class="form-group">
                    <label for="modal_reg_password">Contraseña</label>
                    <input type="password" id="modal_reg_password" name="reg_password" 
                           placeholder="Mínimo 6 caracteres" required minlength="6">
                </div>
                
                <button type="submit" class="btn-auth btn-register">
                    Crear Cuenta
                </button>
            </form>
        </div>
    </div>
</div>

<script>
    // ========================================
    // MANEJAR TABS DEL MODAL
    // ========================================
    document.addEventListener('DOMContentLoaded', function() {
        const tabs = document.querySelectorAll('.auth-tab');
        const panels = {
            login: document.getElementById('panelLogin'),
            register: document.getElementById('panelRegister')
        };
        
        tabs.forEach(tab => {
            tab.addEventListener('click', function() {
                // Quitar active de todos los tabs
                tabs.forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                // Mostrar panel correspondiente
                const tabName = this.dataset.tab;
                Object.keys(panels).forEach(key => {
                    panels[key].classList.toggle('active', key === tabName);
                });
            });
        });
        
        // ========================================
        // ABRIR MODAL
        // ========================================
        function openModal() {
            const modal = document.getElementById('authModal');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal() {
            const modal = document.getElementById('authModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        // ========================================
        // EVENTOS PARA ABRIR MODAL
        // ========================================
        const btnOpenAuth = document.getElementById('btnOpenAuth');
        if (btnOpenAuth) {
            btnOpenAuth.addEventListener('click', function(e) {
                e.preventDefault();
                openModal();
            });
        }
        
        const btnOpenAuthFromPaso4 = document.getElementById('btnOpenAuthFromPaso4');
        if (btnOpenAuthFromPaso4) {
            btnOpenAuthFromPaso4.addEventListener('click', function(e) {
                e.preventDefault();
                openModal();
            });
        }
        
        // ========================================
        // CERRAR MODAL
        // ========================================
        document.getElementById('closeModal').addEventListener('click', closeModal);
        
        // Cerrar al hacer clic fuera del modal
        document.getElementById('authModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        // Cerrar con tecla ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
        
        // ========================================
        // MOSTRAR/OCULTAR CAMPOS DE GRAVAMEN
        // ========================================
        const lienRadios = document.querySelectorAll('input[name="has_lien"]');
        const lienGroup = document.getElementById('lien_group');
        
        if (lienRadios.length > 0) {
            lienRadios.forEach(radio => {
                radio.addEventListener('change', function() {
                    if (this.value == 1) {
                        lienGroup.style.display = 'block';
                    } else {
                        lienGroup.style.display = 'none';
                    }
                });
            });
        }
        
        // ========================================
        // SI HAY ERRORES, ABRIR MODAL AUTOMÁTICAMENTE
        // ========================================
        <?php if ($show_auth && !isset($_SESSION['usuario_id'])): ?>
            openModal();
        <?php endif; ?>
    });
</script>

</body>
</html>