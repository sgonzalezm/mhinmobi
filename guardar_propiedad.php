<?php
// ========================================
// guardar_propiedad.php - CON FUNCIONES COMPLETAS
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
        return $stmt->fetchAll();
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
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error en obtenerBancos: " . $e->getMessage());
        return [];
    }
}

// ========================================
// FUNCIÓN PRINCIPAL PARA GUARDAR PROPIEDAD
// ========================================
function guardarPropiedad($data, $usuario_id) {
    global $conn;
    
    try {
        $conn->beginTransaction();
        
        // ========================================
        // 1. INSERTAR EN LA TABLA properties
        // ========================================
        $sql = "INSERT INTO properties (
            owner_id, 
            title, 
            status, 
            operation_type,
            property_type,
            address_city,
            address_municipality,
            created_at,
            updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $stmt = $conn->prepare($sql);
        
        // Extraer datos
        $titulo = $data['titulo'] ?? 'Propiedad sin título';
        $tipo_operacion = $data['tipo_operacion'] ?? 'venta';
        $tipo_vivienda = $data['tipo_vivienda'] ?? 'casa';
        $ubicacion = $data['ubicacion'] ?? '';
        
        // Separar ciudad y municipio
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
        
        // ========================================
        // 2. INSERTAR EN property_details
        // ========================================
        $sql_details = "INSERT INTO property_details (
            property_id,
            square_meters,
            bedrooms,
            bathrooms,
            parking_spots,
            description,
            legal_status,
            legal_notes,
            tipo_casa,
            nivel_duplex,
            nivel_departamento,
            tiene_escrituras,
            tiene_testamento
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt_details = $conn->prepare($sql_details);
        $m2 = !empty($data['m2']) ? (float)$data['m2'] : 0;
        $recamaras = !empty($data['recamaras']) ? (int)$data['recamaras'] : 0;
        $banos = !empty($data['banos']) ? (int)$data['banos'] : 0;
        $estacionamiento = !empty($data['estacionamiento']) ? (int)$data['estacionamiento'] : 0;
        $descripcion = $data['descripcion'] ?? '';
        $legal_status = $data['legal_status'] ?? 'libre';
        $legal_notes = $data['legal_status_notes'] ?? '';
        $tipo_casa = $data['tipo_casa'] ?? null;
        $nivel_duplex = $data['nivel_duplex'] ?? null;
        $nivel_departamento = $data['nivel_departamento'] ?? null;
        $tiene_escrituras = isset($data['tiene_escrituras']) ? (int)$data['tiene_escrituras'] : 0;
        $tiene_testamento = isset($data['tiene_testamento']) ? (int)$data['tiene_testamento'] : 0;
        
        $stmt_details->execute([
            $property_id,
            $m2,
            $recamaras,
            $banos,
            $estacionamiento,
            $descripcion,
            $legal_status,
            $legal_notes,
            $tipo_casa,
            $nivel_duplex,
            $nivel_departamento,
            $tiene_escrituras,
            $tiene_testamento
        ]);
        
        // ========================================
        // 3. INSERTAR EN property_financials
        // ========================================
        $sql_financials = "INSERT INTO property_financials (
            property_id,
            asking_price,
            min_acceptable_price,
            commission_percentage,
            has_debt,
            debt_type,
            bank_id,
            debt_amount,
            debt_property_type,
            debt_shared_details
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt_financials = $conn->prepare($sql_financials);
        $precio = !empty($data['precio']) ? (float)$data['precio'] : 0;
        $precio_minimo = $precio;
        $comision = 5.00;
        $has_debt = isset($data['tiene_adeudo']) ? (int)$data['tiene_adeudo'] : 0;
        $debt_type = $data['tipo_adeudo'] ?? null;
        $bank_id = !empty($data['banco_id']) ? (int)$data['banco_id'] : null;
        $debt_amount = !empty($data['monto_adeudo']) ? (float)$data['monto_adeudo'] : null;
        $debt_property_type = $data['tipo_adeudo_propiedad'] ?? null;
        $debt_shared_details = $data['adeudo_compartido_detalles'] ?? null;
        
        $stmt_financials->execute([
            $property_id,
            $precio,
            $precio_minimo,
            $comision,
            $has_debt,
            $debt_type,
            $bank_id,
            $debt_amount,
            $debt_property_type,
            $debt_shared_details
        ]);
        
        // ========================================
        // 4. INSERTAR SERVICIOS MUNICIPALES
        // ========================================
        $sql_services = "INSERT INTO property_services (
            property_id,
            service_type,
            is_active,
            has_debt
        ) VALUES (?, ?, ?, ?)";
        
        $stmt_services = $conn->prepare($sql_services);
        
        $servicios = [
            'agua' => [
                'activo' => $data['servicio_agua_activo'] ?? 0,
                'adeudo' => isset($data['servicio_agua_adeudo']) ? 1 : 0
            ],
            'luz' => [
                'activo' => $data['servicio_luz_activo'] ?? 0,
                'adeudo' => isset($data['servicio_luz_adeudo']) ? 1 : 0
            ],
            'gas' => [
                'activo' => $data['servicio_gas_activo'] ?? 0,
                'adeudo' => isset($data['servicio_gas_adeudo']) ? 1 : 0
            ],
            'internet' => [
                'activo' => $data['servicio_internet_activo'] ?? 0,
                'adeudo' => isset($data['servicio_internet_adeudo']) ? 1 : 0
            ],
            'basura' => [
                'activo' => $data['servicio_basura_activo'] ?? 0,
                'adeudo' => isset($data['servicio_basura_adeudo']) ? 1 : 0
            ]
        ];
        
        foreach ($servicios as $tipo => $estado) {
            $stmt_services->execute([
                $property_id,
                $tipo,
                (int)$estado['activo'],
                (int)$estado['adeudo']
            ]);
        }
        
        // ========================================
        // 5. INSERTAR ACCESORIOS
        // ========================================
        if (!empty($data['accesorios']) && is_array($data['accesorios'])) {
            $sql_accesorios = "INSERT INTO property_accesorios (
                property_id,
                accesorio_id
            ) VALUES (?, ?)";
            
            $stmt_accesorios = $conn->prepare($sql_accesorios);
            
            foreach ($data['accesorios'] as $accesorio_id) {
                $stmt_accesorios->execute([
                    $property_id,
                    (int)$accesorio_id
                ]);
            }
        }
        
        // ========================================
        // 6. GUARDAR ACCESORIO "OTRO"
        // ========================================
        if (!empty($data['accesorio_otro'])) {
            $sql_otro = "INSERT INTO property_accesorios_otros (
                property_id,
                nombre
            ) VALUES (?, ?)";
            
            $stmt_otro = $conn->prepare($sql_otro);
            $stmt_otro->execute([
                $property_id,
                $data['accesorio_otro']
            ]);
        }
        
        // ========================================
        // 7. INSERTAR IMÁGENES EN property_media
        // ========================================
        if (!empty($data['imagenes']) && is_array($data['imagenes'])) {
            $sql_media = "INSERT INTO property_media (
                property_id,
                file_name,
                file_path,
                is_primary,
                sort_order,
                uploaded_at,
                uploaded_by
            ) VALUES (?, ?, ?, ?, ?, NOW(), ?)";
            
            $stmt_media = $conn->prepare($sql_media);
            
            foreach ($data['imagenes'] as $index => $imagen_path) {
                $nombre_archivo = basename($imagen_path);
                $es_principal = ($index === 0) ? 1 : 0;
                $orden = $index;
                
                $stmt_media->execute([
                    $property_id,
                    $nombre_archivo,
                    $imagen_path,
                    $es_principal,
                    $orden,
                    $usuario_id
                ]);
            }
        }
        
        // ========================================
        // 8. INSERTAR EN property_history
        // ========================================
        $sql_history = "INSERT INTO property_history (
            property_id,
            user_id,
            action,
            details,
            created_at
        ) VALUES (?, ?, ?, ?, NOW())";
        
        $stmt_history = $conn->prepare($sql_history);
        $detalles_historial = json_encode([
            'titulo' => $titulo,
            'tipo_operacion' => $tipo_operacion,
            'tipo_vivienda' => $tipo_vivienda,
            'precio' => $precio,
            'tiene_adeudo' => $has_debt
        ]);
        
        $stmt_history->execute([
            $property_id,
            $usuario_id,
            'publicacion_inicial',
            $detalles_historial
        ]);
        
        // ========================================
        // CONFIRMAR TRANSACCIÓN
        // ========================================
        $conn->commit();
        
        return [
            'success' => true,
            'property_id' => $property_id,
            'message' => 'Propiedad guardada exitosamente'
        ];
        
    } catch (PDOException $e) {
        $conn->rollBack();
        error_log("Error en guardarPropiedad: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
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
                return [
                    'id' => $row['id'],
                    'name' => $row['nombre'],
                    'email' => $row['email'],
                    'role' => $row['rol']
                ];
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
        $sql = "INSERT INTO socios (nombre, email, password, rol, activo, fecha_registro) 
                VALUES (?, ?, ?, 'socio', 1, NOW())";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([$nombre, $email, $password_hash]);
        
        return $conn->lastInsertId();
        
    } catch (PDOException $e) {
        error_log("Error en registrarUsuario: " . $e->getMessage());
        return false;
    }
}

// ========================================
// FUNCIÓN PARA OBTENER DATOS DEL SOCIO
// ========================================
function obtenerSocioActual($conn) {
    if (!isset($_SESSION['usuario_id'])) {
        return null;
    }
    
    try {
        $sql = "SELECT id, nombre, email, rol FROM socios WHERE id = ? AND activo = 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$_SESSION['usuario_id']]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Error en obtenerSocioActual: " . $e->getMessage());
        return null;
    }
}
?>