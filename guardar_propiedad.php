<?php
// ========================================
// guardar_propiedad.php - VERSIÓN CON DEBUG
// ========================================

// ========================================
// CONFIGURACIÓN DE DEBUG
// ========================================
define('DEBUG_MODE', true); // Cambiar a false en producción
define('LOG_FILE', __DIR__ . '/debug_propiedad.log');

function debugLog($mensaje, $datos = null) {
    if (!DEBUG_MODE) return;
    
    $fecha = date('Y-m-d H:i:s');
    $log = "[$fecha] $mensaje";
    if ($datos !== null) {
        $log .= "\n" . print_r($datos, true);
    }
    $log .= "\n" . str_repeat('-', 80) . "\n";
    
    file_put_contents(LOG_FILE, $log, FILE_APPEND);
}

require_once 'includes/conexion.php';

debugLog("=== INICIO DE PROCESO ===");
debugLog("Método HTTP", $_SERVER['REQUEST_METHOD']);
debugLog("POST", $_POST);
debugLog("GET", $_GET);
debugLog("SESSION", isset($_SESSION) ? $_SESSION : 'No iniciada');

// ========================================
// FUNCIONES PARA OBTENER CATÁLOGOS
// ========================================
function obtenerAccesorios() {
    global $conn;
    
    try {
        $sql = "SELECT id, nombre, icono FROM accesorios WHERE activo = 1 ORDER BY nombre";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        debugLog("Accesorios obtenidos", $result);
        return $result;
    } catch (PDOException $e) {
        debugLog("ERROR en obtenerAccesorios", $e->getMessage());
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
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        debugLog("Bancos obtenidos", $result);
        return $result;
    } catch (PDOException $e) {
        debugLog("ERROR en obtenerBancos", $e->getMessage());
        error_log("Error en obtenerBancos: " . $e->getMessage());
        return [];
    }
}

// ========================================
// FUNCIÓN PARA GUARDAR PROPIEDAD CON DEBUG
// ========================================
function guardarPropiedad($data, $usuario_id) {
    global $conn;
    
    debugLog("=== INICIANDO guardarPropiedad ===");
    debugLog("Datos recibidos", $data);
    debugLog("Usuario ID", $usuario_id);
    
    try {
        $conn->beginTransaction();
        debugLog("Transacción iniciada");
        
        // 1. Insertar en properties
        debugLog("=== PASO 1: Insertar en properties ===");
        $sql = "INSERT INTO properties (
            owner_id, title, status, operation_type, property_type,
            address_city, address_municipality, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        debugLog("SQL properties", $sql);
        
        $stmt = $conn->prepare($sql);
        
        $titulo = $data['titulo'] ?? 'Propiedad sin título';
        $tipo_operacion = $data['tipo_operacion'] ?? 'venta';
        $tipo_vivienda = $data['tipo_vivienda'] ?? 'casa';
        $ubicacion = $data['ubicacion'] ?? '';
        
        $partes_ubicacion = explode(',', $ubicacion);
        $ciudad = trim($partes_ubicacion[0] ?? '');
        $municipio = trim($partes_ubicacion[1] ?? $ciudad);
        
        $params = [
            $usuario_id,
            $titulo,
            'activo',
            $tipo_operacion,
            $tipo_vivienda,
            $ciudad,
            $municipio
        ];
        
        debugLog("Parámetros properties", $params);
        
        $stmt->execute($params);
        
        $property_id = $conn->lastInsertId();
        debugLog("Property ID generado", $property_id);
        
        if (!$property_id) {
            throw new Exception("No se pudo obtener el ID de la propiedad");
        }
        
        // 2. Insertar en property_details
        debugLog("=== PASO 2: Insertar en property_details ===");
        $sql_details = "INSERT INTO property_details (
            property_id, square_meters, bedrooms, bathrooms, parking_spots,
            description, legal_status, legal_notes, tipo_casa, nivel_duplex,
            nivel_departamento, tiene_escrituras, tiene_testamento
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        debugLog("SQL details", $sql_details);
        
        $stmt_details = $conn->prepare($sql_details);
        
        $params_details = [
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
        ];
        
        debugLog("Parámetros details", $params_details);
        
        $stmt_details->execute($params_details);
        debugLog("property_details insertado correctamente");
        
        // 3. Insertar en property_financials
        debugLog("=== PASO 3: Insertar en property_financials ===");
        $sql_financials = "INSERT INTO property_financials (
            property_id, asking_price, min_acceptable_price, commission_percentage,
            has_debt, debt_type, bank_id, debt_amount, debt_property_type, debt_shared_details
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        debugLog("SQL financials", $sql_financials);
        
        $stmt_financials = $conn->prepare($sql_financials);
        $precio = !empty($data['precio']) ? (float)$data['precio'] : 0;
        
        $params_financials = [
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
        ];
        
        debugLog("Parámetros financials", $params_financials);
        
        $stmt_financials->execute($params_financials);
        debugLog("property_financials insertado correctamente");
        
        // 4. Insertar servicios municipales
        debugLog("=== PASO 4: Insertar servicios municipales ===");
        $sql_services = "INSERT INTO property_services (property_id, service_type, is_active, has_debt) VALUES (?, ?, ?, ?)";
        $stmt_services = $conn->prepare($sql_services);
        
        $servicios = [
            'agua' => ['activo' => $data['servicio_agua_activo'] ?? 0, 'adeudo' => isset($data['servicio_agua_adeudo']) ? 1 : 0],
            'luz' => ['activo' => $data['servicio_luz_activo'] ?? 0, 'adeudo' => isset($data['servicio_luz_adeudo']) ? 1 : 0],
            'gas' => ['activo' => $data['servicio_gas_activo'] ?? 0, 'adeudo' => isset($data['servicio_gas_adeudo']) ? 1 : 0],
            'internet' => ['activo' => $data['servicio_internet_activo'] ?? 0, 'adeudo' => isset($data['servicio_internet_adeudo']) ? 1 : 0],
            'basura' => ['activo' => $data['servicio_basura_activo'] ?? 0, 'adeudo' => isset($data['servicio_basura_adeudo']) ? 1 : 0]
        ];
        
        debugLog("Servicios a insertar", $servicios);
        
        foreach ($servicios as $tipo => $estado) {
            $params_services = [$property_id, $tipo, (int)$estado['activo'], (int)$estado['adeudo']];
            debugLog("Insertando servicio: $tipo", $params_services);
            $stmt_services->execute($params_services);
        }
        debugLog("Servicios insertados correctamente");
        
        // 5. Insertar accesorios
        debugLog("=== PASO 5: Insertar accesorios ===");
        if (!empty($data['accesorios']) && is_array($data['accesorios'])) {
            $sql_accesorios = "INSERT INTO property_accesorios (property_id, accesorio_id) VALUES (?, ?)";
            $stmt_accesorios = $conn->prepare($sql_accesorios);
            
            debugLog("Accesorios a insertar", $data['accesorios']);
            
            foreach ($data['accesorios'] as $accesorio_id) {
                debugLog("Insertando accesorio ID", $accesorio_id);
                $stmt_accesorios->execute([$property_id, (int)$accesorio_id]);
            }
            debugLog("Accesorios insertados correctamente");
        } else {
            debugLog("No hay accesorios para insertar");
        }
        
        // 6. Guardar accesorio "otro"
        debugLog("=== PASO 6: Guardar accesorio otro ===");
        if (!empty($data['accesorio_otro'])) {
            $sql_otro = "INSERT INTO property_accesorios_otros (property_id, nombre) VALUES (?, ?)";
            $stmt_otro = $conn->prepare($sql_otro);
            debugLog("Insertando accesorio otro", $data['accesorio_otro']);
            $stmt_otro->execute([$property_id, $data['accesorio_otro']]);
            debugLog("Accesorio otro insertado correctamente");
        } else {
            debugLog("No hay accesorio otro para insertar");
        }
        
        // 7. Insertar imágenes
        debugLog("=== PASO 7: Insertar imágenes ===");
        if (!empty($data['imagenes']) && is_array($data['imagenes'])) {
            $sql_media = "INSERT INTO property_media (property_id, file_name, file_path, is_primary, sort_order, uploaded_at, uploaded_by) VALUES (?, ?, ?, ?, ?, NOW(), ?)";
            $stmt_media = $conn->prepare($sql_media);
            
            debugLog("Imágenes a insertar", $data['imagenes']);
            
            foreach ($data['imagenes'] as $index => $imagen_path) {
                $params_media = [
                    $property_id,
                    basename($imagen_path),
                    $imagen_path,
                    ($index === 0) ? 1 : 0,
                    $index,
                    $usuario_id
                ];
                debugLog("Insertando imagen $index", $params_media);
                $stmt_media->execute($params_media);
            }
            debugLog("Imágenes insertadas correctamente");
        } else {
            debugLog("No hay imágenes para insertar");
        }
        
        // 8. Insertar historial
        debugLog("=== PASO 8: Insertar historial ===");
        $sql_history = "INSERT INTO property_history (property_id, user_id, action, details, created_at) VALUES (?, ?, ?, ?, NOW())";
        $stmt_history = $conn->prepare($sql_history);
        $details = json_encode(['titulo' => $titulo, 'tipo_operacion' => $tipo_operacion, 'precio' => $precio]);
        debugLog("Historial details", $details);
        $stmt_history->execute([
            $property_id,
            $usuario_id,
            'publicacion_inicial',
            $details
        ]);
        debugLog("Historial insertado correctamente");
        
        $conn->commit();
        debugLog("=== TRANSACCIÓN COMPLETADA EXITOSAMENTE ===");
        
        return ['success' => true, 'property_id' => $property_id];
        
    } catch (PDOException $e) {
        $conn->rollBack();
        debugLog("=== ERROR PDO EN guardarPropiedad ===");
        debugLog("Código de error", $e->getCode());
        debugLog("Mensaje de error", $e->getMessage());
        debugLog("Trace", $e->getTraceAsString());
        error_log("Error en guardarPropiedad: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    } catch (Exception $e) {
        $conn->rollBack();
        debugLog("=== ERROR GENERAL EN guardarPropiedad ===");
        debugLog("Mensaje de error", $e->getMessage());
        debugLog("Trace", $e->getTraceAsString());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ========================================
// FUNCIONES DE AUTENTICACIÓN
// ========================================
function verificarLogin($email, $password) {
    global $conn;
    
    debugLog("Verificando login para", $email);
    
    try {
        $sql = "SELECT id, name, email, password, rol FROM users WHERE email = ? AND activo = 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$email]);
        
        if ($row = $stmt->fetch()) {
            debugLog("Usuario encontrado", ['id' => $row['id'], 'name' => $row['name']]);
            if (password_verify($password, $row['password'])) {
                debugLog("Contraseña verificada correctamente");
                return ['id' => $row['id'], 'name' => $row['name'], 'email' => $row['email'], 'role' => $row['rol']];
            } else {
                debugLog("Contraseña incorrecta");
            }
        } else {
            debugLog("Usuario no encontrado o inactivo");
        }
        return false;
    } catch (PDOException $e) {
        debugLog("ERROR en verificarLogin", $e->getMessage());
        error_log("Error en verificarLogin: " . $e->getMessage());
        return false;
    }
}

function registrarUsuario($nombre, $email, $password) {
    global $conn;
    
    debugLog("Registrando usuario", ['nombre' => $nombre, 'email' => $email]);
    
    try {
        $sql_check = "SELECT id FROM users WHERE email = ?";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([$email]);
        
        if ($stmt_check->rowCount() > 0) {
            debugLog("Usuario ya existe", $email);
            return false;
        }
        
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $sql = "INSERT INTO users (name, email, password, rol, activo, fecha_registro) VALUES (?, ?, ?, 'propietario', 1, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$nombre, $email, $password_hash]);
        
        $user_id = $conn->lastInsertId();
        debugLog("Usuario registrado correctamente", $user_id);
        return $user_id;
    } catch (PDOException $e) {
        debugLog("ERROR en registrarUsuario", $e->getMessage());
        error_log("Error en registrarUsuario: " . $e->getMessage());
        return false;
    }
}

// ========================================
// VERIFICAR ESTRUCTURA DE TABLAS
// ========================================
function verificarEstructuraTablas() {
    global $conn;
    
    debugLog("=== VERIFICANDO ESTRUCTURA DE TABLAS ===");
    
    $tablas = ['properties', 'property_details', 'property_financials', 'property_services', 'property_accesorios', 'property_media', 'property_history'];
    
    foreach ($tablas as $tabla) {
        try {
            $sql = "DESCRIBE $tabla";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $columnas = $stmt->fetchAll(PDO::FETCH_COLUMN);
            debugLog("Tabla $tabla: columnas", $columnas);
        } catch (PDOException $e) {
            debugLog("ERROR al verificar tabla $tabla", $e->getMessage());
        }
    }
}

// ========================================
// EL CÓDIGO DE ABAJO SOLO SE EJECUTA SI SE LLAMA DIRECTAMENTE
// ========================================

if (basename($_SERVER['SCRIPT_FILENAME']) === 'guardar_propiedad.php') {
    debugLog("=== EJECUTANDO CÓDIGO PRINCIPAL ===");
    
    // Iniciar sesión si no está iniciada
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
        debugLog("Sesión iniciada");
    }

    // Verificar estructura de tablas (solo en modo debug)
    if (DEBUG_MODE) {
        verificarEstructuraTablas();
    }

    // Verificar si el usuario está autenticado
    if (!isset($_SESSION['usuario_id'])) {
        debugLog("ERROR: Usuario no autenticado");
        echo json_encode(['success' => false, 'error' => 'Usuario no autenticado']);
        exit;
    }

    $usuario_id = $_SESSION['usuario_id'];
    debugLog("Usuario ID de sesión", $usuario_id);

    // Verificar que el usuario existe en la BD
    try {
        $sql = "SELECT id, name, email FROM users WHERE id = ? AND activo = 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$usuario_id]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$usuario) {
            debugLog("ERROR: Usuario no válido", $usuario_id);
            echo json_encode(['success' => false, 'error' => 'Usuario no válido']);
            exit;
        }
        
        debugLog("Usuario verificado", $usuario);
    } catch (PDOException $e) {
        debugLog("ERROR al verificar usuario", $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Error al verificar usuario']);
        exit;
    }

    // Obtener datos de la sesión
    $data = [];
    if (isset($_SESSION['form_venta']) && is_array($_SESSION['form_venta']) && !empty($_SESSION['form_venta'])) {
        $data = $_SESSION['form_venta'];
        debugLog("Datos de sesión form_venta", $data);
    } else {
        debugLog("No hay datos en form_venta");
    }

    // Verificar que tenemos datos
    if (empty($data)) {
        debugLog("ERROR: No se encontraron datos");
        echo json_encode([
            'success' => false, 
            'error' => 'No se encontraron datos para guardar',
            'debug' => [
                'session_form_venta' => isset($_SESSION['form_venta']) ? 'existe' : 'no existe',
                'session_keys' => array_keys($_SESSION),
                'post' => $_POST,
                'get' => $_GET,
                'method' => $_SERVER['REQUEST_METHOD'],
                'session_id' => session_id()
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
        debugLog("ERROR: Campos requeridos faltantes", $faltantes);
        echo json_encode([
            'success' => false, 
            'error' => 'Campos requeridos faltantes: ' . implode(', ', $faltantes),
            'datos_recibidos' => $data
        ]);
        exit;
    }

    // Intentar guardar la propiedad
    debugLog("=== INICIANDO GUARDADO DE PROPIEDAD ===");
    $resultado = guardarPropiedad($data, $usuario_id);
    debugLog("Resultado del guardado", $resultado);

    // Si se guardó correctamente, limpiar la sesión
    if ($resultado['success']) {
        unset($_SESSION['form_venta']);
        debugLog("Sesión form_venta limpiada");
    }

    // Devolver respuesta
    header('Content-Type: application/json');
    echo json_encode($resultado);
    exit;
}

debugLog("=== FIN DE PROCESO ===");
?>