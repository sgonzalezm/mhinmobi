<?php
session_start();

// ===== DEBUG TEMPORAL =====
$DEBUG = isset($_GET['debug']);
if ($DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

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

$property_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($property_id == 0) {
    header('Location: inventario_maestro.php');
    exit;
}

$accesorios_disponibles = obtenerAccesorios();
$bancos_disponibles = obtenerBancos();

$error_msg = '';
$debug_info = '';

// ========================================
// PROCESAR POST (ANTES de cargar datos)
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($DEBUG) {
        $debug_info .= "<h3>DEBUG POST</h3><pre>" . print_r($_POST, true) . "</pre>";
    }

    try {
        $conn->beginTransaction();

        // 1. properties
        $ubicacion = trim($_POST['ubicacion'] ?? '');
        $partes = explode(',', $ubicacion);
        $ciudad = trim($partes[0] ?? '');
        $municipio = trim($partes[1] ?? $ciudad);

        $stmt = $conn->prepare("
            UPDATE properties SET
                title = ?, operation_type = ?, property_type = ?,
                address_city = ?, address_municipality = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $_POST['titulo'] ?? '',
            $_POST['tipo_operacion'] ?? '',
            $_POST['tipo_vivienda'] ?? '',
            $ciudad,
            $municipio,
            $property_id
        ]);

        if ($DEBUG) {
            $debug_info .= "<p>✅ properties: " . $stmt->rowCount() . " filas afectadas</p>";
        }

        // 2. property_details
        $stmt = $conn->prepare("SELECT id FROM property_details WHERE property_id = ?");
        $stmt->execute([$property_id]);
        $existe = $stmt->fetch();

        $details_params = [
            !empty($_POST['m2']) ? (float)$_POST['m2'] : 0,
            !empty($_POST['recamaras']) ? (int)$_POST['recamaras'] : 0,
            !empty($_POST['banos']) ? (int)$_POST['banos'] : 0,
            !empty($_POST['estacionamiento']) ? (int)$_POST['estacionamiento'] : 0,
            $_POST['descripcion'] ?? '',
            $_POST['legal_status'] ?? 'libre',
            $_POST['legal_status_notes'] ?? '',
            !empty($_POST['tipo_casa']) ? $_POST['tipo_casa'] : null,
            !empty($_POST['nivel_duplex']) ? $_POST['nivel_duplex'] : null,
            !empty($_POST['nivel_departamento']) ? $_POST['nivel_departamento'] : null,
            isset($_POST['tiene_escrituras']) ? (int)$_POST['tiene_escrituras'] : 0,
            isset($_POST['tiene_testamento']) ? (int)$_POST['tiene_testamento'] : 0
        ];

        if ($existe) {
            $stmt = $conn->prepare("
                UPDATE property_details SET
                    square_meters = ?, bedrooms = ?, bathrooms = ?, parking_spots = ?,
                    description = ?, legal_status = ?, legal_notes = ?,
                    tipo_casa = ?, nivel_duplex = ?, nivel_departamento = ?,
                    tiene_escrituras = ?, tiene_testamento = ?
                WHERE property_id = ?
            ");
            $stmt->execute(array_merge($details_params, [$property_id]));
            if ($DEBUG) $debug_info .= "<p>✅ details UPDATE: " . $stmt->rowCount() . " filas</p>";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO property_details 
                (square_meters, bedrooms, bathrooms, parking_spots, description,
                 legal_status, legal_notes, tipo_casa, nivel_duplex, nivel_departamento,
                 tiene_escrituras, tiene_testamento, property_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute(array_merge($details_params, [$property_id]));
            if ($DEBUG) $debug_info .= "<p>✅ details INSERT</p>";
        }

        // 3. property_financials
        $precio = !empty($_POST['precio']) ? (float)$_POST['precio'] : 0;

        $stmt = $conn->prepare("SELECT id FROM property_financials WHERE property_id = ?");
        $stmt->execute([$property_id]);
        $existe = $stmt->fetch();

        $fin_params = [
            $precio,
            $precio,
            isset($_POST['tiene_adeudo']) ? (int)$_POST['tiene_adeudo'] : 0,
            !empty($_POST['tipo_adeudo']) ? $_POST['tipo_adeudo'] : null,
            !empty($_POST['banco_id']) ? (int)$_POST['banco_id'] : null,
            !empty($_POST['monto_adeudo']) ? (float)$_POST['monto_adeudo'] : null,
            !empty($_POST['tipo_adeudo_propiedad']) ? $_POST['tipo_adeudo_propiedad'] : null,
            !empty($_POST['adeudo_compartido_detalles']) ? $_POST['adeudo_compartido_detalles'] : null
        ];

        if ($existe) {
            $stmt = $conn->prepare("
                UPDATE property_financials SET
                    asking_price = ?, min_acceptable_price = ?,
                    has_debt = ?, debt_type = ?, bank_id = ?, debt_amount = ?,
                    debt_property_type = ?, debt_shared_details = ?
                WHERE property_id = ?
            ");
            $stmt->execute(array_merge($fin_params, [$property_id]));
            if ($DEBUG) $debug_info .= "<p>✅ financials UPDATE: " . $stmt->rowCount() . " filas</p>";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO property_financials 
                (asking_price, min_acceptable_price, has_debt, debt_type, bank_id, 
                 debt_amount, debt_property_type, debt_shared_details, commission_percentage, property_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 5.00, ?)
            ");
            $stmt->execute(array_merge($fin_params, [$property_id]));
            if ($DEBUG) $debug_info .= "<p>✅ financials INSERT</p>";
        }

        // 4. property_services
        $stmt = $conn->prepare("DELETE FROM property_services WHERE property_id = ?");
        $stmt->execute([$property_id]);

        $stmt_ins = $conn->prepare("INSERT INTO property_services (property_id, service_type, is_active, has_debt) VALUES (?, ?, ?, ?)");
        foreach (['agua', 'luz', 'gas', 'internet', 'basura'] as $tipo) {
            $activo = isset($_POST["servicio_{$tipo}_activo"]) ? 1 : 0;
            $adeudo = isset($_POST["servicio_{$tipo}_adeudo"]) ? 1 : 0;
            $stmt_ins->execute([$property_id, $tipo, $activo, $adeudo]);
        }
        if ($DEBUG) $debug_info .= "<p>✅ services actualizados</p>";

        // 5. accesorios
        $stmt = $conn->prepare("DELETE FROM property_accesorios WHERE property_id = ?");
        $stmt->execute([$property_id]);

        if (!empty($_POST['accesorios']) && is_array($_POST['accesorios'])) {
            $stmt = $conn->prepare("INSERT INTO property_accesorios (property_id, accesorio_id) VALUES (?, ?)");
            foreach ($_POST['accesorios'] as $acc_id) {
                $stmt->execute([$property_id, (int)$acc_id]);
            }
        }
        if ($DEBUG) $debug_info .= "<p>✅ accesorios actualizados</p>";

        // 6. accesorio otro
        $stmt = $conn->prepare("DELETE FROM property_accesorios_otros WHERE property_id = ?");
        $stmt->execute([$property_id]);

        if (!empty($_POST['accesorio_otro'])) {
            $stmt = $conn->prepare("INSERT INTO property_accesorios_otros (property_id, nombre) VALUES (?, ?)");
            $stmt->execute([$property_id, $_POST['accesorio_otro']]);
        }

        // 7. imágenes
        $imagenes_nuevas = [];
        if (isset($_POST['imagenes_guardadas'])) {
            $imagenes_nuevas = json_decode($_POST['imagenes_guardadas'], true) ?: [];
        }

        if ($DEBUG) $debug_info .= "<p>Imágenes nuevas: " . count($imagenes_nuevas) . "</p>";

        $stmt = $conn->prepare("SELECT file_path FROM property_media WHERE property_id = ?");
        $stmt->execute([$property_id]);
        $imagenes_actuales = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Eliminar las quitadas
        foreach (array_diff($imagenes_actuales, $imagenes_nuevas) as $img) {
            $stmt = $conn->prepare("DELETE FROM property_media WHERE property_id = ? AND file_path = ?");
            $stmt->execute([$property_id, $img]);
            if (file_exists($img)) @unlink($img);
        }

        // Actualizar / insertar
        $stmt_check = $conn->prepare("SELECT id FROM property_media WHERE property_id = ? AND file_path = ?");
        foreach ($imagenes_nuevas as $index => $img_path) {
            $is_primary = ($index === 0) ? 1 : 0;
            $stmt_check->execute([$property_id, $img_path]);
            if ($stmt_check->fetch()) {
                $stmt = $conn->prepare("UPDATE property_media SET is_primary = ?, sort_order = ? WHERE property_id = ? AND file_path = ?");
                $stmt->execute([$is_primary, $index, $property_id, $img_path]);
            } else {
                $stmt = $conn->prepare("INSERT INTO property_media (property_id, file_name, file_path, is_primary, sort_order, uploaded_at, uploaded_by) VALUES (?, ?, ?, ?, ?, NOW(), ?)");
                $stmt->execute([$property_id, basename($img_path), $img_path, $is_primary, $index, $_SESSION['usuario_id']]);
            }
        }
        if ($DEBUG) $debug_info .= "<p>✅ imágenes actualizadas</p>";

        // 8. historial (por si falla, no romper la transacción)
        try {
            $stmt = $conn->prepare("INSERT INTO property_history (property_id, user_id, action, details, created_at) VALUES (?, ?, 'edicion', ?, NOW())");
            $stmt->execute([
                $property_id,
                $_SESSION['usuario_id'],
                json_encode(['titulo' => $_POST['titulo'], 'precio' => $precio])
            ]);
        } catch (PDOException $e) {
            if ($DEBUG) $debug_info .= "<p>⚠️ historial: " . $e->getMessage() . "</p>";
        }

        $conn->commit();

        if ($DEBUG) {
            $debug_info .= "<p style='color:green;font-size:20px'>✅ TODO GUARDADO - Commit exitoso</p>";
            // No redirigir en modo debug
        } else {
            $_SESSION['mensaje_exito'] = '¡Propiedad actualizada correctamente!';
            header("Location: propiedad_detalle_inventario.php?id=" . $property_id);
            exit();
        }

    } catch (PDOException $e) {
        $conn->rollBack();
        $error_msg = 'Error al actualizar: ' . $e->getMessage();
        if ($DEBUG) {
            $debug_info .= "<p style='color:red;font-size:20px'>❌ ERROR: " . $e->getMessage() . "</p>";
            $debug_info .= "<pre>" . $e->getTraceAsString() . "</pre>";
        }
    }
}

// ========================================
// CARGAR DATOS (después de procesar)
// ========================================
try {
    $stmt = $conn->prepare("SELECT * FROM properties WHERE id = ?");
    $stmt->execute([$property_id]);
    $prop = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$prop) {
        header('Location: inventario_maestro.php');
        exit;
    }

    $stmt = $conn->prepare("SELECT * FROM property_details WHERE property_id = ?");
    $stmt->execute([$property_id]);
    $det = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmt = $conn->prepare("SELECT * FROM property_financials WHERE property_id = ?");
    $stmt->execute([$property_id]);
    $fin = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmt = $conn->prepare("SELECT * FROM property_services WHERE property_id = ?");
    $stmt->execute([$property_id]);
    $servicios_db = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $servicios_db[$s['service_type']] = $s;
    }

    $stmt = $conn->prepare("SELECT * FROM property_media WHERE property_id = ? ORDER BY is_primary DESC, sort_order ASC");
    $stmt->execute([$property_id]);
    $imagenes_db = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("SELECT accesorio_id FROM property_accesorios WHERE property_id = ?");
    $stmt->execute([$property_id]);
    $accesorios_db = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $conn->prepare("SELECT nombre FROM property_accesorios_otros WHERE property_id = ? LIMIT 1");
    $stmt->execute([$property_id]);
    $accesorio_otro_db = $stmt->fetchColumn() ?: '';

} catch (PDOException $e) {
    header('Location: inventario_maestro.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Propiedad | Inmobiliaria MH</title>
    <link rel="stylesheet" href="css/socios.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .edit-container { max-width: 900px; margin: 0 auto; padding: 20px; }
        .edit-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 25px; }
        .edit-header h1 { color: #1a1a2e; font-size: 24px; margin: 0; display: flex; align-items: center; gap: 10px; }
        .edit-header p { color: #888; font-size: 14px; margin: 5px 0 0 0; }
        .card { background: white; border-radius: 12px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .card h2 { color: #1a1a2e; font-size: 18px; margin: 0 0 5px 0; display: flex; align-items: center; gap: 10px; }
        .card .card-subtitle { color: #888; font-size: 13px; margin-bottom: 20px; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-weight: 600; color: #333; margin-bottom: 6px; font-size: 14px; }
        .form-group label .required { color: #dc3545; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 14px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; font-family: inherit; transition: all 0.2s; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #c9a84c; outline: none; box-shadow: 0 0 0 3px rgba(201,168,76,0.1); }
        .form-group textarea { min-height: 80px; resize: vertical; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 18px; }
        .conditional-group { padding: 15px 20px; background: #f8f8f8; border-radius: 8px; margin-top: 10px; margin-bottom: 15px; border-left: 4px solid #c9a84c; }
        .conditional-group.hidden { display: none; }
        .radio-group-inline { display: flex; gap: 20px; margin-top: 5px; flex-wrap: wrap; }
        .radio-group-inline label { display: flex; align-items: center; gap: 6px; cursor: pointer; margin: 0; font-weight: normal; }
        .accesorios-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; margin-top: 10px; }
        .accesorio-item { display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #f8f8f8; border-radius: 8px; border: 2px solid #e8e8e8; cursor: pointer; transition: all 0.2s; }
        .accesorio-item:hover { border-color: #c9a84c; background: #f8f6f0; }
        .accesorio-item input[type="checkbox"] { width: 18px; height: 18px; accent-color: #c9a84c; cursor: pointer; }
        .accesorio-item label { cursor: pointer; font-weight: 500; color: #333; margin: 0; }
        .accesorio-item .accesorio-icon { color: #c9a84c; }
        .accesorio-otro { grid-column: 1 / -1; }
        .accesorio-otro input[type="text"] { flex: 1; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; margin-left: 8px; }
        .servicios-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; margin-top: 10px; }
        .servicio-item { padding: 12px 14px; background: #f8f8f8; border-radius: 8px; border: 1px solid #e8e8e8; }
        .servicio-item .servicio-name { font-weight: 600; color: #333; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
        .servicio-item .servicio-controls { display: flex; gap: 15px; flex-wrap: wrap; font-size: 13px; }
        .servicio-item .servicio-controls label { display: flex; align-items: center; gap: 5px; cursor: pointer; margin: 0; font-weight: normal; }
        .image-upload-container { border: 2px dashed #ddd; border-radius: 8px; padding: 30px; text-align: center; background: #fafafa; cursor: pointer; transition: all 0.2s; }
        .image-upload-container:hover, .image-upload-container.dragover { border-color: #c9a84c; background: #f8f6f0; }
        .image-upload-icon { font-size: 42px; color: #c9a84c; margin-bottom: 10px; }
        .image-upload-text { color: #666; font-size: 14px; }
        .image-upload-text strong { color: #1a1a2e; }
        .image-preview-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 12px; margin-top: 15px; }
        .image-preview-item { position: relative; border-radius: 8px; overflow: hidden; aspect-ratio: 1; background: #f5f5f5; border: 2px solid #e0e0e0; cursor: grab; transition: all 0.2s; }
        .image-preview-item.dragging { opacity: 0.4; border-color: #c9a84c; }
        .image-preview-item.drag-over { border-color: #c9a84c; transform: scale(1.05); box-shadow: 0 0 0 3px rgba(201,168,76,0.3); }
        .image-preview-item:hover { border-color: #c9a84c; }
        .image-preview-item img { width: 100%; height: 100%; object-fit: cover; pointer-events: none; }
        .image-preview-item .remove-image { position: absolute; top: 5px; right: 5px; background: rgba(255,0,0,0.8); color: white; border: none; border-radius: 50%; width: 26px; height: 26px; cursor: pointer; font-size: 13px; display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s; z-index: 5; }
        .image-preview-item:hover .remove-image { opacity: 1; }
        .image-preview-item .image-number { position: absolute; bottom: 5px; left: 5px; background: rgba(0,0,0,0.7); color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; }
        .image-preview-item .image-main-badge { position: absolute; top: 5px; left: 5px; background: #c9a84c; color: white; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: bold; z-index: 5; }
        .image-preview-item .drag-handle { position: absolute; bottom: 5px; right: 5px; background: rgba(0,0,0,0.6); color: white; width: 22px; height: 22px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 11px; opacity: 0; transition: opacity 0.2s; }
        .image-preview-item:hover .drag-handle { opacity: 1; }
        .upload-progress { display: none; margin-top: 15px; }
        .upload-progress .progress-bar { width: 100%; height: 6px; background: #e0e0e0; border-radius: 3px; overflow: hidden; }
        .upload-progress .progress-fill { height: 100%; background: linear-gradient(90deg, #c9a84c, #e8c86a); width: 0%; transition: width 0.5s; }
        .upload-progress .progress-text { font-size: 12px; color: #666; margin-top: 5px; text-align: center; }
        .error-msg { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #dc3545; }
        .debug-box { background: #fff3cd; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #ffc107; font-family: monospace; font-size: 12px; max-height: 400px; overflow-y: auto; }
        .btn-group { display: flex; gap: 12px; margin-top: 25px; padding-top: 20px; border-top: 1px solid #e8e8e8; flex-wrap: wrap; justify-content: flex-end; }
        .btn { padding: 11px 28px; border-radius: 8px; font-weight: 600; font-size: 14px; border: none; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; }
        .btn-dorado { background: #c9a84c; color: white; }
        .btn-dorado:hover { background: #b8963a; transform: translateY(-2px); }
        .btn-secondary { background: #e8e8e8; color: #333; }
        .btn-secondary:hover { background: #d5d5d5; }
        .drag-instruction { font-size: 12px; color: #c9a84c; margin-top: 8px; display: flex; align-items: center; gap: 5px; }
        @media (max-width: 768px) {
            .form-row, .form-row-3 { grid-template-columns: 1fr; }
            .accesorios-grid { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); }
            .servicios-grid { grid-template-columns: 1fr; }
            .edit-header { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>

<?php include 'modulos/sidebar.php'; ?>

<main class="main-content">
    <div class="edit-container">
        <div class="edit-header">
            <div>
                <h1><i class="fas fa-edit"></i> Editar Propiedad</h1>
                <p>ID #<?php echo $property_id; ?> • Actualiza los datos que necesites</p>
            </div>
            <a href="propiedad_detalle_inventario.php?id=<?php echo $property_id; ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Cancelar
            </a>
        </div>

        <?php if (!empty($error_msg)): ?>
            <div class="error-msg">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?>
            </div>
        <?php endif; ?>

        <?php if ($DEBUG && $debug_info): ?>
            <div class="debug-box">
                <strong>DEBUG MODE</strong>
                <?php echo $debug_info; ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="editForm" enctype="multipart/form-data">
            <input type="hidden" name="imagenes_guardadas" id="imagenesGuardadas" value='<?php echo htmlspecialchars(json_encode(array_column($imagenes_db, 'file_path')), ENT_QUOTES); ?>'>

            <!-- DATOS BÁSICOS -->
            <div class="card">
                <h2><i class="fas fa-home"></i> Datos Básicos</h2>
                <p class="card-subtitle">Información principal</p>

                <div class="form-group">
                    <label for="titulo">Título <span class="required">*</span></label>
                    <input type="text" id="titulo" name="titulo" value="<?php echo htmlspecialchars($prop['title'] ?? ''); ?>" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="precio">Precio <span class="required">*</span></label>
                        <input type="number" id="precio" name="precio" value="<?php echo htmlspecialchars($fin['asking_price'] ?? ''); ?>" min="0" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label for="tipo_operacion">Tipo de Operación <span class="required">*</span></label>
                        <select id="tipo_operacion" name="tipo_operacion" required>
                            <option value="venta" <?php echo ($prop['operation_type'] ?? '') == 'venta' ? 'selected' : ''; ?>>Venta</option>
                            <option value="alquiler" <?php echo ($prop['operation_type'] ?? '') == 'alquiler' ? 'selected' : ''; ?>>Alquiler</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="tipo_vivienda">Tipo de Vivienda <span class="required">*</span></label>
                    <select id="tipo_vivienda" name="tipo_vivienda" required>
                        <option value="casa" <?php echo ($prop['property_type'] ?? '') == 'casa' ? 'selected' : ''; ?>>Casa</option>
                        <option value="departamento" <?php echo ($prop['property_type'] ?? '') == 'departamento' ? 'selected' : ''; ?>>Departamento</option>
                        <option value="terreno" <?php echo ($prop['property_type'] ?? '') == 'terreno' ? 'selected' : ''; ?>>Terreno</option>
                        <option value="local" <?php echo ($prop['property_type'] ?? '') == 'local' ? 'selected' : ''; ?>>Local Comercial</option>
                    </select>
                </div>

                <div id="casa_options" class="conditional-group <?php echo ($prop['property_type'] ?? '') == 'casa' ? '' : 'hidden'; ?>">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="tipo_casa">Tipo de Casa</label>
                        <select id="tipo_casa" name="tipo_casa">
                            <option value="">Seleccionar</option>
                            <option value="una_planta" <?php echo ($det['tipo_casa'] ?? '') == 'una_planta' ? 'selected' : ''; ?>>Una planta</option>
                            <option value="dos_plantas" <?php echo ($det['tipo_casa'] ?? '') == 'dos_plantas' ? 'selected' : ''; ?>>Dos plantas</option>
                            <option value="duplex" <?php echo ($det['tipo_casa'] ?? '') == 'duplex' ? 'selected' : ''; ?>>Dúplex</option>
                        </select>
                    </div>
                    <div id="nivel_duplex_group" class="conditional-group <?php echo ($det['tipo_casa'] ?? '') == 'duplex' ? '' : 'hidden'; ?>" style="margin-top: 10px; margin-bottom: 0;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="nivel_duplex">Nivel del Dúplex</label>
                            <select id="nivel_duplex" name="nivel_duplex">
                                <option value="">Seleccionar</option>
                                <option value="planta_baja" <?php echo ($det['nivel_duplex'] ?? '') == 'planta_baja' ? 'selected' : ''; ?>>Planta Baja</option>
                                <option value="planta_alta" <?php echo ($det['nivel_duplex'] ?? '') == 'planta_alta' ? 'selected' : ''; ?>>Planta Alta</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="departamento_options" class="conditional-group <?php echo ($prop['property_type'] ?? '') == 'departamento' ? '' : 'hidden'; ?>">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="nivel_departamento">Nivel del Departamento</label>
                        <input type="text" id="nivel_departamento" name="nivel_departamento" value="<?php echo htmlspecialchars($det['nivel_departamento'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="descripcion">Descripción</label>
                    <textarea id="descripcion" name="descripcion"><?php echo htmlspecialchars($det['description'] ?? ''); ?></textarea>
                </div>
            </div>

            <!-- DETALLES -->
            <div class="card">
                <h2><i class="fas fa-ruler-combined"></i> Detalles</h2>
                <p class="card-subtitle">Características y ubicación</p>

                <div class="form-row-3">
                    <div class="form-group">
                        <label for="m2">Metros Cuadrados</label>
                        <input type="number" id="m2" name="m2" value="<?php echo htmlspecialchars($det['square_meters'] ?? ''); ?>" min="0" step="0.01">
                    </div>
                    <div class="form-group">
                        <label for="recamaras">Recámaras</label>
                        <input type="number" id="recamaras" name="recamaras" value="<?php echo htmlspecialchars($det['bedrooms'] ?? ''); ?>" min="0" max="20">
                    </div>
                    <div class="form-group">
                        <label for="banos">Baños</label>
                        <input type="number" id="banos" name="banos" value="<?php echo htmlspecialchars($det['bathrooms'] ?? ''); ?>" min="0" max="10" step="0.5">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="estacionamiento">Estacionamientos</label>
                        <input type="number" id="estacionamiento" name="estacionamiento" value="<?php echo htmlspecialchars($det['parking_spots'] ?? ''); ?>" min="0" max="10">
                    </div>
                    <div class="form-group">
                        <label for="ubicacion">Ubicación</label>
                        <input type="text" id="ubicacion" name="ubicacion" value="<?php echo htmlspecialchars(trim(($prop['address_city'] ?? '') . ', ' . ($prop['address_municipality'] ?? ''), ', ')); ?>">
                    </div>
                </div>
            </div>

            <!-- ACCESORIOS -->
            <div class="card">
                <h2><i class="fas fa-star"></i> Accesorios</h2>
                <p class="card-subtitle">Selecciona los accesorios</p>

                <div class="accesorios-grid">
                    <?php foreach ($accesorios_disponibles as $acc): ?>
                        <div class="accesorio-item">
                            <input type="checkbox" id="acc_<?php echo $acc['id']; ?>" name="accesorios[]" value="<?php echo $acc['id']; ?>" <?php echo in_array($acc['id'], $accesorios_db) ? 'checked' : ''; ?>>
                            <label for="acc_<?php echo $acc['id']; ?>">
                                <?php if (!empty($acc['icono'])): ?>
                                    <i class="<?php echo htmlspecialchars($acc['icono']); ?> accesorio-icon"></i>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($acc['nombre']); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                    <div class="accesorio-item accesorio-otro">
                        <input type="checkbox" id="acc_otro" <?php echo !empty($accesorio_otro_db) ? 'checked' : ''; ?>>
                        <label for="acc_otro"><i class="fas fa-plus-circle accesorio-icon"></i> Otro</label>
                        <input type="text" id="accesorio_otro_input" name="accesorio_otro" value="<?php echo htmlspecialchars($accesorio_otro_db); ?>" placeholder="Especificar..." <?php echo empty($accesorio_otro_db) ? 'disabled' : ''; ?>>
                    </div>
                </div>
            </div>

            <!-- IMÁGENES -->
            <div class="card">
                <h2><i class="fas fa-images"></i> Imágenes</h2>
                <p class="card-subtitle">Máximo 10. Arrastra para reordenar. La primera será la principal.</p>

                <div class="image-upload-container" id="imageUploadContainer">
                    <div class="image-upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                    <div class="image-upload-text"><strong>Haz clic o arrastra</strong> imágenes aquí</div>
                    <div style="font-size: 12px; color: #999; margin-top: 5px;">JPG, PNG, GIF, WEBP • Máx 5MB</div>
                    <input type="file" id="fileInput" multiple accept="image/*" style="display: none;">
                </div>
                <div class="drag-instruction"><i class="fas fa-arrows-alt"></i> Arrastra para reordenar</div>
                <div class="upload-progress" id="uploadProgress">
                    <div class="progress-bar"><div class="progress-fill" id="progressFill"></div></div>
                    <div class="progress-text" id="progressText">Subiendo...</div>
                </div>
                <div class="image-preview-grid" id="imagePreviewGrid"></div>
            </div>

            <!-- LEGAL Y ADEUDOS -->
            <div class="card">
                <h2><i class="fas fa-balance-scale"></i> Situación Legal y Financiera</h2>
                <p class="card-subtitle">Adeudos, escrituras y estado legal</p>

                <div class="form-group">
                    <label>¿Tiene adeudo o gravamen?</label>
                    <div class="radio-group-inline">
                        <label><input type="radio" name="tiene_adeudo" value="1" <?php echo ($fin['has_debt'] ?? 0) == 1 ? 'checked' : ''; ?> onchange="toggleAdeudo(this.value)"> Sí</label>
                        <label><input type="radio" name="tiene_adeudo" value="0" <?php echo ($fin['has_debt'] ?? 0) == 0 ? 'checked' : ''; ?> onchange="toggleAdeudo(this.value)"> No</label>
                    </div>
                </div>

                <div id="adeudo_details" class="conditional-group <?php echo ($fin['has_debt'] ?? 0) == 1 ? '' : 'hidden'; ?>">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="tipo_adeudo">Tipo de Adeudo</label>
                            <select id="tipo_adeudo" name="tipo_adeudo" onchange="toggleBanco(this.value)">
                                <option value="">Seleccionar</option>
                                <option value="banco" <?php echo ($fin['debt_type'] ?? '') == 'banco' ? 'selected' : ''; ?>>Banco</option>
                                <option value="particular" <?php echo ($fin['debt_type'] ?? '') == 'particular' ? 'selected' : ''; ?>>Particular</option>
                                <option value="gobierno" <?php echo ($fin['debt_type'] ?? '') == 'gobierno' ? 'selected' : ''; ?>>Gobierno</option>
                                <option value="otros" <?php echo ($fin['debt_type'] ?? '') == 'otros' ? 'selected' : ''; ?>>Otros</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="monto_adeudo">Monto</label>
                            <input type="number" id="monto_adeudo" name="monto_adeudo" value="<?php echo htmlspecialchars($fin['debt_amount'] ?? ''); ?>" min="0" step="0.01">
                        </div>
                    </div>

                    <div id="banco_group" class="conditional-group <?php echo ($fin['debt_type'] ?? '') == 'banco' ? '' : 'hidden'; ?>" style="margin-top: 0;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="banco_id">Banco</label>
                            <select id="banco_id" name="banco_id">
                                <option value="">Seleccionar banco</option>
                                <?php foreach ($bancos_disponibles as $banco): ?>
                                    <option value="<?php echo $banco['id']; ?>" <?php echo ($fin['bank_id'] ?? '') == $banco['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($banco['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Tipo</label>
                        <div class="radio-group-inline">
                            <label><input type="radio" name="tipo_adeudo_propiedad" value="individual" <?php echo ($fin['debt_property_type'] ?? '') == 'individual' ? 'checked' : ''; ?> onchange="toggleAdeudoCompartido(this.value)"> Individual</label>
                            <label><input type="radio" name="tipo_adeudo_propiedad" value="compartido" <?php echo ($fin['debt_property_type'] ?? '') == 'compartido' ? 'checked' : ''; ?> onchange="toggleAdeudoCompartido(this.value)"> Compartido</label>
                        </div>
                    </div>

                    <div id="adeudo_compartido_details" class="conditional-group <?php echo ($fin['debt_property_type'] ?? '') == 'compartido' ? '' : 'hidden'; ?>" style="margin-bottom: 0;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="adeudo_compartido_detalles">Detalles</label>
                            <textarea id="adeudo_compartido_detalles" name="adeudo_compartido_detalles"><?php echo htmlspecialchars($fin['debt_shared_details'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="form-row" style="margin-top: 20px;">
                    <div class="form-group">
                        <label>¿Tiene escrituras?</label>
                        <div class="radio-group-inline">
                            <label><input type="radio" name="tiene_escrituras" value="1" <?php echo ($det['tiene_escrituras'] ?? 0) == 1 ? 'checked' : ''; ?>> Sí</label>
                            <label><input type="radio" name="tiene_escrituras" value="0" <?php echo ($det['tiene_escrituras'] ?? 0) == 0 ? 'checked' : ''; ?>> No</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>¿Tiene testamento?</label>
                        <div class="radio-group-inline">
                            <label><input type="radio" name="tiene_testamento" value="1" <?php echo ($det['tiene_testamento'] ?? 0) == 1 ? 'checked' : ''; ?>> Sí</label>
                            <label><input type="radio" name="tiene_testamento" value="0" <?php echo ($det['tiene_testamento'] ?? 0) == 0 ? 'checked' : ''; ?>> No</label>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="legal_status">Estado Legal</label>
                    <select id="legal_status" name="legal_status">
                        <option value="libre" <?php echo ($det['legal_status'] ?? '') == 'libre' ? 'selected' : ''; ?>>Libre de gravámenes</option>
                        <option value="intestado" <?php echo ($det['legal_status'] ?? '') == 'intestado' ? 'selected' : ''; ?>>Intestado</option>
                        <option value="sucesion" <?php echo ($det['legal_status'] ?? '') == 'sucesion' ? 'selected' : ''; ?>>En sucesión</option>
                        <option value="litigio" <?php echo ($det['legal_status'] ?? '') == 'litigio' ? 'selected' : ''; ?>>En litigio</option>
                        <option value="otro" <?php echo ($det['legal_status'] ?? '') == 'otro' ? 'selected' : ''; ?>>Otro</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="legal_status_notes">Notas Legales</label>
                    <textarea id="legal_status_notes" name="legal_status_notes"><?php echo htmlspecialchars($det['legal_notes'] ?? ''); ?></textarea>
                </div>
            </div>

            <!-- SERVICIOS -->
            <div class="card">
                <h2><i class="fas fa-bolt"></i> Servicios</h2>
                <p class="card-subtitle">Estado de servicios</p>

                <?php
                $servicios_lista = [
                    'agua' => ['nombre' => 'Agua', 'icono' => 'fa-tint'],
                    'luz' => ['nombre' => 'Luz', 'icono' => 'fa-bolt'],
                    'gas' => ['nombre' => 'Gas', 'icono' => 'fa-fire'],
                    'internet' => ['nombre' => 'Internet', 'icono' => 'fa-wifi'],
                    'basura' => ['nombre' => 'Basura', 'icono' => 'fa-trash']
                ];
                ?>
                <div class="servicios-grid">
                    <?php foreach ($servicios_lista as $key => $srv): 
                        $activo = $servicios_db[$key]['is_active'] ?? 0;
                        $adeudo = $servicios_db[$key]['has_debt'] ?? 0;
                    ?>
                        <div class="servicio-item">
                            <div class="servicio-name">
                                <i class="fas <?php echo $srv['icono']; ?>" style="color: #c9a84c;"></i>
                                <?php echo $srv['nombre']; ?>
                            </div>
                            <div class="servicio-controls">
                                <label><input type="checkbox" name="servicio_<?php echo $key; ?>_activo" value="1" <?php echo $activo ? 'checked' : ''; ?>> Activo</label>
                                <label><input type="checkbox" name="servicio_<?php echo $key; ?>_adeudo" value="1" <?php echo $adeudo ? 'checked' : ''; ?>> Adeudo</label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="btn-group">
                <a href="propiedad_detalle_inventario.php?id=<?php echo $property_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancelar
                </a>
                <button type="submit" class="btn btn-dorado">
                    <i class="fas fa-save"></i> Guardar Cambios
                </button>
            </div>
        </form>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {

    // TIPO DE VIVIENDA
    const tipoVivienda = document.getElementById('tipo_vivienda');
    const casaOptions = document.getElementById('casa_options');
    const departamentoOptions = document.getElementById('departamento_options');
    const tipoCasa = document.getElementById('tipo_casa');
    const nivelDuplexGroup = document.getElementById('nivel_duplex_group');

    function toggleVivienda() {
        const v = tipoVivienda.value;
        casaOptions.classList.toggle('hidden', v !== 'casa');
        departamentoOptions.classList.toggle('hidden', v !== 'departamento');
        if (v === 'casa') toggleDuplex();
    }
    function toggleDuplex() {
        nivelDuplexGroup.classList.toggle('hidden', tipoCasa.value !== 'duplex');
    }
    tipoVivienda.addEventListener('change', toggleVivienda);
    tipoCasa.addEventListener('change', toggleDuplex);

    // ADEUDOS
    window.toggleAdeudo = function(v) {
        document.getElementById('adeudo_details').classList.toggle('hidden', v != 1);
    };
    window.toggleBanco = function(v) {
        document.getElementById('banco_group').classList.toggle('hidden', v !== 'banco');
    };
    window.toggleAdeudoCompartido = function(v) {
        document.getElementById('adeudo_compartido_details').classList.toggle('hidden', v !== 'compartido');
    };

    // ACCESORIO OTRO
    const accOtroCheck = document.getElementById('acc_otro');
    const accOtroInput = document.getElementById('accesorio_otro_input');
    accOtroCheck.addEventListener('change', function() {
        accOtroInput.disabled = !this.checked;
        if (!this.checked) accOtroInput.value = '';
        else accOtroInput.focus();
    });

    // IMÁGENES
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
        imagenes = JSON.parse(imagenesGuardadas.value || '[]');
        renderPreview();
    } catch (e) { imagenes = []; }

    uploadContainer.addEventListener('click', function(e) {
        if (e.target.closest('#imagePreviewGrid')) return;
        if (e.target.closest('.remove-image')) return;
        if (e.target.closest('.image-preview-item')) return;
        fileInput.click();
    });

    uploadContainer.addEventListener('dragover', function(e) {
        if (draggedIndex !== null) return;
        e.preventDefault(); e.stopPropagation();
        this.classList.add('dragover');
    });
    uploadContainer.addEventListener('dragleave', function(e) {
        if (draggedIndex !== null) return;
        e.preventDefault(); e.stopPropagation();
        this.classList.remove('dragover');
    });
    uploadContainer.addEventListener('drop', function(e) {
        if (draggedIndex !== null) { e.preventDefault(); e.stopPropagation(); return; }
        e.preventDefault(); e.stopPropagation();
        this.classList.remove('dragover');
        if (e.dataTransfer && e.dataTransfer.files.length) processFiles(e.dataTransfer.files);
    });

    document.addEventListener('dragover', e => e.preventDefault());
    document.addEventListener('drop', e => e.preventDefault());

    fileInput.addEventListener('change', function() {
        if (this.files.length) { processFiles(this.files); this.value = ''; }
    });

    function processFiles(files) {
        const maxFiles = 10;
        const maxSize = 5 * 1024 * 1024;
        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        if (imagenes.length + files.length > maxFiles) {
            Swal.fire({ icon: 'warning', title: 'Límite', text: `Máximo ${maxFiles} imágenes`, confirmButtonColor: '#c9a84c' });
            return;
        }

        progressContainer.style.display = 'block';
        let processed = 0;
        const total = files.length;

        Array.from(files).forEach((file) => {
            if (!allowedTypes.includes(file.type)) {
                updateProgress(++processed, total, `Formato no soportado`);
                return;
            }
            if (file.size > maxSize) {
                updateProgress(++processed, total, `Archivo muy grande`);
                return;
            }

            const fd = new FormData();
            fd.append('imagen', file);
            fd.append('action', 'upload_image');

            fetch('upload_image_ajax.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        imagenes.push(data.filepath);
                        renderPreview();
                        updateHidden();
                        updateProgress(++processed, total, `${processed}/${total} subida`);
                    } else {
                        updateProgress(++processed, total, `Error: ${data.error}`);
                    }
                })
                .catch(() => updateProgress(++processed, total, 'Error'));
        });
    }

    function updateProgress(p, t, msg) {
        const pct = Math.round((p / t) * 100);
        progressFill.style.width = pct + '%';
        progressText.textContent = msg || `${pct}%`;
        if (p >= t) setTimeout(() => progressContainer.style.display = 'none', 1500);
    }

    function renderPreview() {
        previewGrid.innerHTML = '';
        imagenes.forEach((img, i) => {
            const div = document.createElement('div');
            div.className = 'image-preview-item';
            div.dataset.index = i;
            div.draggable = true;

            const imgEl = document.createElement('img');
            imgEl.src = img;
            imgEl.draggable = false;

            const removeBtn = document.createElement('button');
            removeBtn.className = 'remove-image';
            removeBtn.type = 'button';
            removeBtn.innerHTML = '<i class="fas fa-times"></i>';
            removeBtn.onclick = (e) => { e.stopPropagation(); removeImage(i); };

            const num = document.createElement('span');
            num.className = 'image-number';
            num.textContent = i + 1;

            const handle = document.createElement('span');
            handle.className = 'drag-handle';
            handle.innerHTML = '<i class="fas fa-grip-vertical"></i>';

            div.appendChild(imgEl);
            div.appendChild(removeBtn);
            div.appendChild(num);
            div.appendChild(handle);

            if (i === 0) {
                const badge = document.createElement('span');
                badge.className = 'image-main-badge';
                badge.textContent = 'Principal';
                div.appendChild(badge);
            }

            div.addEventListener('dragstart', e => {
                draggedIndex = i;
                div.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', 'reorder');
            });
            div.addEventListener('dragover', e => { if (draggedIndex !== null) { e.preventDefault(); e.stopPropagation(); } });
            div.addEventListener('dragenter', e => { if (draggedIndex !== null) { e.preventDefault(); div.classList.add('drag-over'); } });
            div.addEventListener('dragleave', () => div.classList.remove('drag-over'));
            div.addEventListener('drop', e => {
                if (draggedIndex === null) return;
                e.preventDefault(); e.stopPropagation();
                div.classList.remove('drag-over');
                if (draggedIndex === i) return;
                const [moved] = imagenes.splice(draggedIndex, 1);
                imagenes.splice(i, 0, moved);
                draggedIndex = i;
                renderPreview();
                updateHidden();
            });
            div.addEventListener('dragend', () => {
                div.classList.remove('dragging');
                document.querySelectorAll('.image-preview-item').forEach(el => el.classList.remove('drag-over'));
                draggedIndex = null;
            });

            previewGrid.appendChild(div);
        });
    }

    function removeImage(i) {
        Swal.fire({
            title: '¿Eliminar imagen?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Eliminar',
            cancelButtonText: 'Cancelar'
        }).then(r => {
            if (r.isConfirmed) {
                imagenes.splice(i, 1);
                renderPreview();
                updateHidden();
            }
        });
    }

    function updateHidden() {
        imagenesGuardadas.value = JSON.stringify(imagenes);
    }
});
</script>

</body>
</html>