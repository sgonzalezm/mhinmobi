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
    // PROCESAR IMÁGENES SUBIDAS POR AJAX
    // ========================================
    if (isset($_POST['imagenes_guardadas'])) {
        $imagenes = json_decode($_POST['imagenes_guardadas'], true);
        if (is_array($imagenes) && !empty($imagenes)) {
            $_SESSION['form_venta']['imagenes'] = $imagenes;
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
    }
    
    // ========================================
    // MANEJAR LOGIN DE SOCIOS
    // ========================================
    if (isset($_POST['login_email']) && isset($_POST['login_password'])) {
        $email = $_POST['login_email'];
        $password = $_POST['login_password'];
        
        $socio = verificarLogin($email, $password);
        if ($socio) {
            $_SESSION['usuario_id'] = $socio['id'];
            $_SESSION['usuario_nombre'] = $socio['name'];
            $_SESSION['usuario_email'] = $socio['email'];
            $_SESSION['usuario_role'] = $socio['role'];
            
            header("Location: vender.php?paso=4&login=success");
            exit();
        } else {
            $errores[] = 'Credenciales incorrectas. Verifica tu email y contraseña.';
            $_SESSION['errores'] = $errores;
            header("Location: vender.php?paso=" . $paso_actual . "&show_auth=true");
            exit();
        }
    }
    
    // ========================================
    // MANEJAR REGISTRO DE SOCIOS
    // ========================================
    if (isset($_POST['reg_nombre']) && isset($_POST['reg_email']) && isset($_POST['reg_password'])) {
        $nombre = $_POST['reg_nombre'];
        $email = $_POST['reg_email'];
        $password = $_POST['reg_password'];
        
        if (strlen($password) < 6) {
            $errores[] = 'La contraseña debe tener al menos 6 caracteres';
        }
        
        if (empty($errores)) {
            $socio_id = registrarUsuario($nombre, $email, $password);
            if ($socio_id) {
                $_SESSION['usuario_id'] = $socio_id;
                $_SESSION['usuario_nombre'] = $nombre;
                $_SESSION['usuario_email'] = $email;
                $_SESSION['usuario_role'] = 'socio';
                
                header("Location: vender.php?paso=4&register=success");
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
        
        if ($siguiente == 4 && !isset($_SESSION['usuario_id'])) {
            header("Location: vender.php?paso=3&show_auth=true");
            exit();
        }
        
        header("Location: vender.php?paso=" . $siguiente);
        exit();
    }
    
    // ========================================
    // CONFIRMAR Y PUBLICAR
    // ========================================
    if (isset($_POST['confirmar'])) {
        if (!isset($_SESSION['usuario_id'])) {
            $_SESSION['errores'] = ['Debes iniciar sesión para publicar'];
            header("Location: vender.php?paso=4&show_auth=true");
            exit();
        }
        
        // Asegurar que las imágenes estén en el array
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

// Obtener datos del socio usando PDO
$socio = null;
if (isset($_SESSION['usuario_id'])) {
    try {
        require_once 'includes/conexion.php';
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
        /* ===== ESTILOS PARA EL SISTEMA DE IMÁGENES ===== */
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
    </style>
</head>
<body>

<?php include 'sidebar.php'; ?>

<!-- ===== MAIN CONTENT ===== -->
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

    <!-- ===== WIZARD LAYOUT ===== -->
    <div class="wizard-layout">
        <!-- Sidebar de progreso -->
        <aside class="progress-sidebar">
            <h3><i class="fas fa-tasks"></i> Progreso</h3>
            
            <div class="step <?php echo $paso >= 1 ? 'active' : ''; ?> <?php echo $paso > 1 ? 'completed' : ''; ?>" data-step="1">
                <span class="step-number"><span>1</span></span>
                Datos Básicos
            </div>
            
            <div class="step <?php echo $paso >= 2 ? 'active' : ''; ?> <?php echo $paso > 2 ? 'completed' : ''; ?>" data-step="2">
                <span class="step-number"><span>2</span></span>
                Detalles
            </div>
            
            <div class="step <?php echo $paso >= 3 ? 'active' : ''; ?> <?php echo $paso > 3 ? 'completed' : ''; ?>" data-step="3">
                <span class="step-number"><span>3</span></span>
                Legal
            </div>
            
            <div class="step <?php echo $paso >= 4 ? 'active' : ''; ?> <?php echo $paso > 4 ? 'completed' : ''; ?>" data-step="4">
                <span class="step-number"><span>4</span></span>
                Confirmar
            </div>
        </aside>

        <!-- Contenido del wizard -->
        <div class="wizard-content">
            <?php if (isset($_GET['login']) && $_GET['login'] == 'success'): ?>
                <div class="success-message">
                    <p>✅ ¡Sesión iniciada correctamente! Ahora puedes publicar tu propiedad.</p>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['register']) && $_GET['register'] == 'success'): ?>
                <div class="success-message">
                    <p>✅ ¡Registro exitoso! Ahora puedes publicar tu propiedad.</p>
                </div>
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
                            Siguiente <i class="fas fa-arrow-right"></i>
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

                    <!-- ======================================== -->
                    <!-- SISTEMA DE IMÁGENES -->
                    <!-- ======================================== -->
                    <div class="form-group">
                        <label>Fotos de la Propiedad <span style="color: #666; font-weight: normal;">(máximo 10 fotos)</span></label>
                        <div class="image-upload-container" id="imageUploadContainer">
                            <div class="image-upload-icon">
                                <i class="fas fa-cloud-upload-alt"></i>
                            </div>
                            <div class="image-upload-text">
                                <strong>Haz clic o arrastra</strong> tus imágenes aquí
                            </div>
                            <div style="font-size: 12px; color: #999; margin-top: 5px;">
                                Formatos: JPG, PNG, GIF, WEBP • Tamaño máximo: 5MB por imagen
                            </div>
                            <input type="file" id="fileInput" name="imagenes[]" multiple accept="image/*" style="display: none;">
                        </div>
                        
                        <div class="upload-progress" id="uploadProgress">
                            <div class="progress-bar">
                                <div class="progress-fill" id="progressFill"></div>
                            </div>
                            <div class="progress-text" id="progressText">Subiendo imágenes...</div>
                        </div>
                        
                        <div class="image-preview-grid" id="imagePreviewGrid">
                            <?php if (!empty($data['imagenes'])): ?>
                                <?php foreach ($data['imagenes'] as $index => $imagen): ?>
                                    <div class="image-preview-item" data-index="<?php echo $index; ?>">
                                        <img src="<?php echo htmlspecialchars($imagen); ?>" alt="Imagen <?php echo $index + 1; ?>">
                                        <?php if ($index === 0): ?>
                                            <span class="image-main-badge">Principal</span>
                                        <?php endif; ?>
                                        <span class="image-number"><?php echo $index + 1; ?></span>
                                        <button type="button" class="remove-image" data-index="<?php echo $index; ?>">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="btn-group">
                        <a href="?paso=1" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Atrás</a>
                        <button type="submit" class="btn btn-dorado" name="siguiente_paso" value="3">
                            Siguiente <i class="fas fa-arrow-right"></i>
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
                        <label for="debt_amount">Monto de la deuda o descripción del gravamen</label>
                        <input type="text" id="debt_amount" name="debt_amount" 
                               value="<?php echo htmlspecialchars($data['debt_amount'] ?? ''); ?>" 
                               placeholder="Ej: $50,000 o descripción del gravamen">
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
                        <textarea id="legal_status_notes" name="legal_status_notes" 
                                  placeholder="Describe cualquier aspecto legal relevante"><?php echo htmlspecialchars($data['legal_status_notes'] ?? ''); ?></textarea>
                    </div>

                    <div class="btn-group">
                        <a href="?paso=2" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Atrás</a>
                        <?php if (isset($_SESSION['usuario_id'])): ?>
                            <button type="submit" class="btn btn-dorado" name="siguiente_paso" value="4">
                                Siguiente <i class="fas fa-arrow-right"></i>
                            </button>
                        <?php else: ?>
                            <button type="button" class="btn btn-dorado" id="btnOpenAuth">
                                <i class="fas fa-lock"></i> Siguiente (Inicia sesión)
                            </button>
                        <?php endif; ?>
                    </div>

                <!-- ======================================== -->
                <!-- PASO 4: Resumen Final -->
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
                                        <?php 
                                            $role_labels = [
                                                'admin' => 'Administrador',
                                                'vendedor' => 'Vendedor',
                                                'socio' => 'Socio',
                                                'inmobiliaria' => 'Inmobiliaria'
                                            ];
                                            echo $role_labels[$_SESSION['usuario_role']] ?? ucfirst($_SESSION['usuario_role']); 
                                        ?>
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
                        
                        <?php if (!empty($data['banos'])): ?>
                            <div class="resumen-item">
                                <span class="label">Baños</span>
                                <span class="value"><?php echo htmlspecialchars($data['banos']); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($data['estacionamiento'])): ?>
                            <div class="resumen-item">
                                <span class="label">Estacionamientos</span>
                                <span class="value"><?php echo htmlspecialchars($data['estacionamiento']); ?></span>
                            </div>
                        <?php endif; ?>
                        
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
                            <div style="display: flex; gap: 5px; margin-top: 10px; flex-wrap: wrap;">
                                <?php foreach (array_slice($data['imagenes'], 0, 5) as $imagen): ?>
                                    <img src="<?php echo htmlspecialchars($imagen); ?>" 
                                         style="width: 60px; height: 60px; object-fit: cover; border-radius: 4px; border: 1px solid #e0e0e0;">
                                <?php endforeach; ?>
                                <?php if (count($data['imagenes']) > 5): ?>
                                    <span style="display: flex; align-items: center; justify-content: center; width: 60px; height: 60px; background: #f5f5f5; border-radius: 4px; font-size: 12px; color: #666;">
                                        +<?php echo count($data['imagenes']) - 5; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="resumen-total">
                            Total: $<?php echo number_format($data['precio'] ?? 0, 2); ?>
                        </div>
                    </div>

                    <div class="btn-group">
                        <a href="?paso=3" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Atrás</a>
                        <?php if (isset($_SESSION['usuario_id'])): ?>
                            <button type="submit" class="btn btn-success" name="confirmar" value="1" id="btnPublicar">
                                <i class="fas fa-check-circle"></i> Confirmar y Publicar
                            </button>
                        <?php else: ?>
                            <button type="button" class="btn btn-success" id="btnOpenAuthFromPaso4">
                                <i class="fas fa-lock"></i> Iniciar sesión para publicar
                            </button>
                        <?php endif; ?>
                    </div>

                <?php endif; ?>
            </form>
        </div>
    </div>
</main>

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
        
        <!-- Login Panel -->
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
        
        <!-- Register Panel -->
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
    // SISTEMA DE IMÁGENES
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
    } catch (e) {}
    
    // Click en el contenedor
    if (uploadContainer) {
        uploadContainer.addEventListener('click', function(e) {
            if (e.target.tagName !== 'BUTTON' && !e.target.closest('.remove-image')) {
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
            if (files.length > 0) {
                processFiles(files);
            }
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
    
    // Procesar archivos
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
        
        if (progressContainer) {
            progressContainer.style.display = 'block';
        }
        let processed = 0;
        const total = files.length;
        
        Array.from(files).forEach((file, index) => {
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
    if (btnOpenAuth) {
        btnOpenAuth.addEventListener('click', function(e) {
            e.preventDefault();
            openAuthModal();
        });
    }
    
    const btnOpenAuthFromPaso4 = document.getElementById('btnOpenAuthFromPaso4');
    if (btnOpenAuthFromPaso4) {
        btnOpenAuthFromPaso4.addEventListener('click', function(e) {
            e.preventDefault();
            openAuthModal();
        });
    }
    
    const closeModalBtn = document.getElementById('closeModal');
    if (closeModalBtn) {
        closeModalBtn.addEventListener('click', closeAuthModal);
    }
    
    const authModal = document.getElementById('authModal');
    if (authModal) {
        authModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeAuthModal();
            }
        });
    }
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAuthModal();
        }
    });
    
    // ========================================
    // TABS DEL MODAL
    // ========================================
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
                if (panels[key]) {
                    panels[key].classList.toggle('active', key === tabName);
                }
            });
        });
    });
    
    // ========================================
    // MOSTRAR/OCULTAR CAMPOS DE GRAVAMEN
    // ========================================
    const lienRadios = document.querySelectorAll('input[name="has_lien"]');
    const lienGroup = document.getElementById('lien_group');
    
    if (lienRadios.length > 0 && lienGroup) {
        lienRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                lienGroup.style.display = this.value == 1 ? 'block' : 'none';
            });
        });
    }
    
    // ========================================
    // CONFIRMACIÓN AL PUBLICAR
    // ========================================
    const btnPublicar = document.getElementById('btnPublicar');
    if (btnPublicar) {
        btnPublicar.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Validar que haya imágenes
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
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    document.getElementById('wizardForm').submit();
                }
            });
        });
    }
    
    // ========================================
    // ABRIR MODAL SI HAY ERRORES
    // ========================================
    <?php if ($show_auth && !isset($_SESSION['usuario_id'])): ?>
        openAuthModal();
    <?php endif; ?>
});
</script>

</body>
</html>