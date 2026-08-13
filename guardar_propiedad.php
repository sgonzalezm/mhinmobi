<?php
// ========================================
// guardar_propiedad.php - VERSIÓN CORREGIDA
// ========================================
require_once 'includes/conexion.php';

// ========================================
// FUNCIONES PARA OBTENER CATÁLOGOS
// ========================================
function obtenerAccesorios() {
    global $conn;
    
    try {
        $sql = "SELECT id, nombre, icono FROM accesorios WHERE activo = 1 ORDER BY nombre";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error en obtenerAccesorios: " . $e->getMessage());
        return [];
    }
}

function obtenerBancos() {
    global $conn;
    
    try {
        $sql = "SELECT id, nombre FROM bancos WHERE activo = 1 ORDER BY nombre";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error en obtenerBancos: " . $e->getMessage());
        return [];
    }
}

// ========================================
// FUNCIÓN PARA GUARDAR PROPIEDAD
// ========================================
function guardarPropiedad($data, $usuario_id) {
    global $conn;
    
    try {
        $conn->beginTransaction();
        
        // 1. Insertar en properties
        $sql = "INSERT INTO properties (
            owner_id, title, status, operation_type, property_type,
            address_city, address_municipality, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $stmt = $conn->prepare($sql);
        
        $titulo = $data['titulo'] ?? 'Propiedad sin título';
        $tipo_operacion = $data['tipo_operacion'] ?? 'venta';
        $tipo_vivienda = $data['tipo_vivienda'] ?? 'casa';
        $ubicacion = $data['ubicacion'] ?? '';
        
        $partes_ubicacion = explode(',', $ubicacion);
        $ciudad = trim($partes_ubicacion[0] ?? '');
        $municipio = trim($partes_ubicacion[1] ?? $ciudad);
        
        $stmt->execute([
            $usuario_id,
            $titulo,
            'activo',
            $tipo_operacion,
            $tipo_vivienda,
            $ciudad,
            $municipio
        ]);
        
        $property_id = $conn->lastInsertId();
        
        // 2. Insertar en property_details
        $sql_details = "INSERT INTO property_details (
            property_id, square_meters, bedrooms, bathrooms, parking_spots,
            description, legal_status, legal_notes, tipo_casa, nivel_duplex,
            nivel_departamento, tiene_escrituras, tiene_testamento
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt_details = $conn->prepare($sql_details);
        
        $stmt_details->execute([
            $property_id,
            !empty($data['m2']) ? (float)$data['m2'] : 0,
            !empty($data['recamaras']) ? (int)$data['recamaras'] : 0,
            !empty($data['banos']) ? (int)$data['banos'] : 0,
            !empty($data['estacionamiento']) ? (int)$data['estacionamiento'] : 0,
            $data['descripcion'] ?? '',
            $data['legal_status'] ?? 'libre',
            $data['legal_status_notes'] ?? '',
            $data['tipo_casa'] ?? null,
            $data['nivel_duplex'] ?? null,
            $data['nivel_departamento'] ?? null,
            isset($data['tiene_escrituras']) ? (int)$data['tiene_escrituras'] : 0,
            isset($data['tiene_testamento']) ? (int)$data['tiene_testamento'] : 0
        ]);
        
        // 3. Insertar en property_financials
        $sql_financials = "INSERT INTO property_financials (
            property_id, asking_price, min_acceptable_price, commission_percentage,
            has_debt, debt_type, bank_id, debt_amount, debt_property_type, debt_shared_details
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt_financials = $conn->prepare($sql_financials);
        $precio = !empty($data['precio']) ? (float)$data['precio'] : 0;
        
        $stmt_financials->execute([
            $property_id,
            $precio,
            $precio,
            5.00,
            isset($data['tiene_adeudo']) ? (int)$data['tiene_adeudo'] : 0,
            $data['tipo_adeudo'] ?? null,
            !empty($data['banco_id']) ? (int)$data['banco_id'] : null,
            !empty($data['monto_adeudo']) ? (float)$data['monto_adeudo'] : null,
            $data['tipo_adeudo_propiedad'] ?? null,
            $data['adeudo_compartido_detalles'] ?? null
        ]);
        
        // 4. Insertar servicios municipales
        $sql_services = "INSERT INTO property_services (property_id, service_type, is_active, has_debt) VALUES (?, ?, ?, ?)";
        $stmt_services = $conn->prepare($sql_services);
        
        $servicios = [
            'agua' => ['activo' => $data['servicio_agua_activo'] ?? 0, 'adeudo' => isset($data['servicio_agua_adeudo']) ? 1 : 0],
            'luz' => ['activo' => $data['servicio_luz_activo'] ?? 0, 'adeudo' => isset($data['servicio_luz_adeudo']) ? 1 : 0],
            'gas' => ['activo' => $data['servicio_gas_activo'] ?? 0, 'adeudo' => isset($data['servicio_gas_adeudo']) ? 1 : 0],
            'internet' => ['activo' => $data['servicio_internet_activo'] ?? 0, 'adeudo' => isset($data['servicio_internet_adeudo']) ? 1 : 0],
            'basura' => ['activo' => $data['servicio_basura_activo'] ?? 0, 'adeudo' => isset($data['servicio_basura_adeudo']) ? 1 : 0]
        ];
        
        foreach ($servicios as $tipo => $estado) {
            $stmt_services->execute([$property_id, $tipo, (int)$estado['activo'], (int)$estado['adeudo']]);
        }
        
        // 5. Insertar accesorios
        if (!empty($data['accesorios']) && is_array($data['accesorios'])) {
            $sql_accesorios = "INSERT INTO property_accesorios (property_id, accesorio_id) VALUES (?, ?)";
            $stmt_accesorios = $conn->prepare($sql_accesorios);
            
            foreach ($data['accesorios'] as $accesorio_id) {
                $stmt_accesorios->execute([$property_id, (int)$accesorio_id]);
            }
        }
        
        // 6. Guardar accesorio "otro"
        if (!empty($data['accesorio_otro'])) {
            $sql_otro = "INSERT INTO property_accesorios_otros (property_id, nombre) VALUES (?, ?)";
            $stmt_otro = $conn->prepare($sql_otro);
            $stmt_otro->execute([$property_id, $data['accesorio_otro']]);
        }
        
        // 7. Insertar imágenes
        if (!empty($data['imagenes']) && is_array($data['imagenes'])) {
            $sql_media = "INSERT INTO property_media (property_id, file_name, file_path, is_primary, sort_order, uploaded_at, uploaded_by) VALUES (?, ?, ?, ?, ?, NOW(), ?)";
            $stmt_media = $conn->prepare($sql_media);
            
            foreach ($data['imagenes'] as $index => $imagen_path) {
                $stmt_media->execute([
                    $property_id,
                    basename($imagen_path),
                    $imagen_path,
                    ($index === 0) ? 1 : 0,
                    $index,
                    $usuario_id
                ]);
            }
        }
        
        // 8. Insertar historial
        $sql_history = "INSERT INTO property_history (property_id, user_id, action, details, created_at) VALUES (?, ?, ?, ?, NOW())";
        $stmt_history = $conn->prepare($sql_history);
        $stmt_history->execute([
            $property_id,
            $usuario_id,
            'publicacion_inicial',
            json_encode(['titulo' => $titulo, 'tipo_operacion' => $tipo_operacion, 'precio' => $precio])
        ]);
        
        $conn->commit();
        
        return ['success' => true, 'property_id' => $property_id];
        
    } catch (PDOException $e) {
        $conn->rollBack();
        error_log("Error en guardarPropiedad: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ========================================
// FUNCIONES DE AUTENTICACIÓN
// ========================================
function verificarLogin($email, $password) {
    global $conn;
    
    try {
        $sql = "SELECT id, nombre, email, password, rol FROM socios WHERE email = ? AND activo = 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$email]);
        
        if ($row = $stmt->fetch()) {
            if (password_verify($password, $row['password'])) {
                return ['id' => $row['id'], 'name' => $row['nombre'], 'email' => $row['email'], 'role' => $row['rol']];
            }
        }
        return false;
    } catch (PDOException $e) {
        error_log("Error en verificarLogin: " . $e->getMessage());
        return false;
    }
}

function registrarUsuario($nombre, $email, $password) {
    global $conn;
    
    try {
        $sql_check = "SELECT id FROM socios WHERE email = ?";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([$email]);
        
        if ($stmt_check->rowCount() > 0) {
            return false;
        }
        
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $sql = "INSERT INTO socios (nombre, email, password, rol, activo, fecha_registro) VALUES (?, ?, ?, 'socio', 1, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$nombre, $email, $password_hash]);
        
        return $conn->lastInsertId();
    } catch (PDOException $e) {
        error_log("Error en registrarUsuario: " . $e->getMessage());
        return false;
    }
}

// ========================================
// EL CÓDIGO DE ABAJO SOLO SE EJECUTA SI SE LLAMA DIRECTAMENTE
// ========================================

// Verificar si se está llamando directamente (no desde vender.php)
// Si se llama desde vender.php, NO se ejecuta este código
if (basename($_SERVER['SCRIPT_FILENAME']) === 'guardar_propiedad.php') {
    // Iniciar sesión si no está iniciada
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Verificar si el usuario está autenticado
    if (!isset($_SESSION['usuario_id'])) {
        echo json_encode(['success' => false, 'error' => 'Usuario no autenticado']);
        exit;
    }

    $usuario_id = $_SESSION['usuario_id'];

    // Verificar que el usuario existe en la BD
    try {
        $sql = "SELECT id, nombre, email FROM socios WHERE id = ? AND activo = 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$usuario_id]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$usuario) {
            echo json_encode(['success' => false, 'error' => 'Usuario no válido']);
            exit;
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Error al verificar usuario']);
        exit;
    }

    // Obtener datos de la sesión
    $data = [];
    if (isset($_SESSION['form_venta']) && is_array($_SESSION['form_venta']) && !empty($_SESSION['form_venta'])) {
        $data = $_SESSION['form_venta'];
    }

    // Verificar que tenemos datos
    if (empty($data)) {
        echo json_encode([
            'success' => false, 
            'error' => 'No se encontraron datos para guardar',
            'debug' => [
                'session_form_venta' => isset($_SESSION['form_venta']) ? 'existe' : 'no existe',
                'session_keys' => array_keys($_SESSION),
                'post' => $_POST,
                'get' => $_GET,
                'method' => $_SERVER['REQUEST_METHOD']
            ]
        ]);
        exit;
    }

    // Verificar campos mínimos
    $campos_requeridos = ['titulo', 'tipo_operacion', 'tipo_vivienda'];
    $faltantes = [];
    foreach ($campos_requeridos as $campo) {
        if (empty($data[$campo])) {
            $faltantes[] = $campo;
        }
    }

    if (!empty($faltantes)) {
        echo json_encode([
            'success' => false, 
            'error' => 'Campos requeridos faltantes: ' . implode(', ', $faltantes),
            'datos_recibidos' => $data
        ]);
        exit;
    }

    // Intentar guardar la propiedad
    $resultado = guardarPropiedad($data, $usuario_id);

    // Si se guardó correctamente, limpiar la sesión
    if ($resultado['success']) {
        unset($_SESSION['form_venta']);
    }

    // Devolver respuesta
    header('Content-Type: application/json');
    echo json_encode($resultado);
    exit;
}
?>