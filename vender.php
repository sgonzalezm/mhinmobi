<?php
session_start();

// Inicializar sesión si no existe
if (!isset($_SESSION['form_venta'])) {
    $_SESSION['form_venta'] = [];
}

// Determinar el paso actual
$paso = isset($_GET['paso']) ? (int)$_GET['paso'] : 1;
$paso = max(1, min(3, $paso)); // Limitar entre 1 y 3

// Procesar POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar y sanitizar datos
    $errores = [];
    
    // Guardar todos los datos del formulario
    foreach ($_POST as $key => $value) {
        if ($key !== 'siguiente_paso' && $key !== 'action') {
            // Sanitizar entrada
            $_SESSION['form_venta'][$key] = htmlspecialchars(trim($value));
        }
    }
    
    // Validar según el paso actual
    $paso_actual = isset($_POST['paso_actual']) ? (int)$_POST['paso_actual'] : 1;
    
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
    
    // Si hay errores, guardarlos en sesión
    if (!empty($errores)) {
        $_SESSION['errores'] = $errores;
        header("Location: vender.php?paso=" . $paso_actual);
        exit();
    }
    
    // Limpiar errores
    unset($_SESSION['errores']);
    
    // Redirigir al siguiente paso si se solicitó
    if (isset($_POST['siguiente_paso'])) {
        $siguiente = (int)$_POST['siguiente_paso'];
        header("Location: vender.php?paso=" . $siguiente);
        exit();
    }
    
    // Si se confirmó la publicación
    if (isset($_POST['confirmar'])) {
        // Procesar datos finales
        header("Location: procesar_venta_final.php");
        exit();
    }
}

// Recuperar datos de sesión
$data = $_SESSION['form_venta'];
$errores = $_SESSION['errores'] ?? [];
unset($_SESSION['errores']);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/wizard.css">
    <title>Publicar Propiedad - Wizard</title>
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
            <span class="step-number">3</span> Media & Resumen
        </div>
    </aside>

    <!-- Contenido del Wizard -->
    <div class="wizard-container">
        <form method="POST" id="wizardForm" class="fade-in">
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

            <!-- PASO 1: Datos Básicos -->
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

            <!-- PASO 2: Detalles -->
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

                <div class="btn-group">
                    <a href="?paso=1" class="btn btn-secondary">← Atrás</a>
                    <button type="submit" class="btn btn-dorado" name="siguiente_paso" value="3">
                        Siguiente → 
                    </button>
                </div>

            <!-- PASO 3: Media & Resumen -->
            <?php elseif ($paso == 3): ?>
                <h2>📸 Media y Resumen</h2>
                <p class="subtitle">Agrega imágenes y confirma los datos</p>

                <div class="form-group">
                    <label for="imagenes">Subir Imágenes (máximo 5)</label>
                    <input type="file" id="imagenes" name="imagenes[]" multiple accept="image/*">
                    <small style="color: #666; display: block; margin-top: 0.3rem;">
                        Formatos: JPG, PNG, GIF. Tamaño máximo: 5MB por imagen
                    </small>
                </div>

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
                    
                    <div class="resumen-total">
                        Total: $<?php echo number_format($data['precio'] ?? 0, 2); ?>
                    </div>
                </div>

                <div class="btn-group">
                    <a href="?paso=2" class="btn btn-secondary">← Atrás</a>
                    <button type="submit" class="btn btn-success" name="confirmar" value="1">
                        ✅ Confirmar y Publicar
                    </button>
                    <button type="reset" class="btn btn-danger" onclick="return confirm('¿Seguro que quieres reiniciar el formulario?')">
                        🔄 Reiniciar
                    </button>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<script>
    // Validación en tiempo real
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('wizardForm');
        
        // Validar campos requeridos al enviar
        form.addEventListener('submit', function(e) {
            const required = form.querySelectorAll('[required]');
            let hasError = false;
            
            required.forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('error');
                    hasError = true;
                } else {
                    field.classList.remove('error');
                }
            });
            
            if (hasError) {
                e.preventDefault();
                alert('Por favor completa todos los campos obligatorios');
            }
        });
        
        // Quitar error al escribir
        form.querySelectorAll('input, select, textarea').forEach(field => {
            field.addEventListener('input', function() {
                if (this.value.trim()) {
                    this.classList.remove('error');
                }
            });
        });
    });
</script>

</body>
</html>