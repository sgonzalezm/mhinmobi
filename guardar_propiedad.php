<?php
// ========================================
// guardar_propiedad.php - VERSIÓN PDO
// ========================================
require_once 'includes/conexion.php';

function guardarPropiedad($data, $usuario_id) {
    global $conn;
    
    try {
        // ========================================
        // 1. INSERTAR EN LA TABLA properties
        // ========================================
        $sql = "INSERT INTO properties (
            owner_id, 
            title, 
            status, 
            operation_type,
            address_city,
            address_municipality,
            created_at,
            updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $stmt = $conn->prepare($sql);
        
        // Extraer datos
        $titulo = $data['titulo'] ?? 'Propiedad sin título';
        $tipo_operacion = $data['tipo_operacion'] ?? 'venta';
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
            parking_spots
        ) VALUES (?, ?, ?, ?, ?)";
        
        $stmt_details = $conn->prepare($sql_details);
        $m2 = !empty($data['m2']) ? (float)$data['m2'] : 0;
        $recamaras = !empty($data['recamaras']) ? (int)$data['recamaras'] : 0;
        $banos = !empty($data['banos']) ? (int)$data['banos'] : 0;
        $estacionamiento = !empty($data['estacionamiento']) ? (int)$data['estacionamiento'] : 0;
        
        $stmt_details->execute([
            $property_id,
            $m2,
            $recamaras,
            $banos,
            $estacionamiento
        ]);
        
        // ========================================
        // 3. INSERTAR EN property_financials
        // ========================================
        $sql_financials = "INSERT INTO property_financials (
            property_id,
            asking_price
        ) VALUES (?, ?)";
        
        $stmt_financials = $conn->prepare($sql_financials);
        $precio = !empty($data['precio']) ? (float)$data['precio'] : 0;
        
        $stmt_financials->execute([
            $property_id,
            $precio
        ]);
        
        // ========================================
        // 4. INSERTAR IMÁGENES EN property_media
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
        // 5. GUARDAR DATOS LEGALES
        // ========================================
        if (isset($data['legal_status']) && !empty($data['legal_status'])) {
            // Verificar si la columna existe
            try {
                $check = $conn->query("SHOW COLUMNS FROM property_details LIKE 'legal_status'");
                if ($check && $check->rowCount() > 0) {
                    $sql_legal = "UPDATE property_details SET 
                        legal_status = ?,
                        legal_notes = ?
                        WHERE property_id = ?";
                    
                    $stmt_legal = $conn->prepare($sql_legal);
                    $legal_status = $data['legal_status'] ?? 'libre';
                    $legal_notes = $data['legal_status_notes'] ?? '';
                    
                    $stmt_legal->execute([
                        $legal_status,
                        $legal_notes,
                        $property_id
                    ]);
                }
            } catch (PDOException $e) {
                // La columna no existe, ignorar
                error_log("Error al guardar datos legales: " . $e->getMessage());
            }
        }
        
        return [
            'success' => true,
            'property_id' => $property_id,
            'message' => 'Propiedad guardada exitosamente'
        ];
        
    } catch (PDOException $e) {
        error_log("Error en guardarPropiedad: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// ========================================
// FUNCIONES DE AUTENTICACIÓN PARA SOCIOS (PDO)
// ========================================
function verificarLogin($email, $password) {
    global $conn;
    
    try {
        $sql = "SELECT id, nombre, email, password, rol FROM socios WHERE email = ? AND activo = 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$email]);
        
        if ($row = $stmt->fetch()) {
            // Verificar contraseña
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
        // Verificar si el email ya existe
        $sql_check = "SELECT id FROM socios WHERE email = ?";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([$email]);
        
        if ($stmt_check->rowCount() > 0) {
            return false; // El email ya está registrado
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
// FUNCIÓN PARA OBTENER DATOS DEL SOCIO (PDO)
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