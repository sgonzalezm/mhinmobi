<?php
// ========================================
// ARCHIVO: guardar_propiedad.php
// ========================================

require_once 'includes/conexion.php';

function verificarLogin($email, $password) {
    global $conn;
    
    try {
        $sql = "SELECT id, name, email, password_hash, role FROM users WHERE email = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$email]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($usuario && password_verify($password, $usuario['password_hash'])) {
            unset($usuario['password_hash']);
            return $usuario;
        }
    } catch (PDOException $e) {
        error_log("Error en verificarLogin: " . $e->getMessage());
    }
    
    return false;
}

function registrarUsuario($nombre, $email, $password) {
    global $conn;
    
    try {
        $sql = "SELECT id FROM users WHERE email = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$email]);
        
        if ($stmt->rowCount() > 0) {
            return false;
        }
        
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $sql = "INSERT INTO users (name, email, password_hash, role, created_at) VALUES (?, ?, ?, 'propietario', NOW())";
        $stmt = $conn->prepare($sql);
        
        if ($stmt->execute([$nombre, $email, $hashed_password])) {
            return $conn->lastInsertId();
        }
    } catch (PDOException $e) {
        error_log("Error en registrarUsuario: " . $e->getMessage());
    }
    
    return false;
}

function guardarPropiedad($data, $usuario_id) {
    global $conn;
    
    if (!$usuario_id) {
        return ['success' => false, 'error' => 'Usuario no autenticado'];
    }
    
    try {
        // Verificar que el usuario existe
        $sql = "SELECT id FROM users WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$usuario_id]);
        
        if ($stmt->rowCount() === 0) {
            return ['success' => false, 'error' => 'El usuario no existe en la base de datos'];
        }
        
        $conn->beginTransaction();
        
        // ========================================
        // 1. INSERTAR EN TABLA properties
        // ========================================
        $sql = "INSERT INTO properties (
                    owner_id, 
                    status, 
                    operation_type, 
                    address_city, 
                    address_municipality,
                    address_lat,
                    address_lng,
                    created_at,
                    updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $stmt = $conn->prepare($sql);
        
        $ubicacion = $data['ubicacion'] ?? '';
        $ciudad = '';
        $municipio = '';
        
        if (strpos($ubicacion, ',') !== false) {
            $partes = array_map('trim', explode(',', $ubicacion));
            $ciudad = $partes[0] ?? '';
            $municipio = $partes[1] ?? '';
        } else {
            $ciudad = $ubicacion;
        }
        
        $status = 'active';
        $operation_type = $data['tipo_operacion'] ?? 'venta';
        $lat = null;
        $lng = null;
        
        $stmt->execute([
            $usuario_id,
            $status,
            $operation_type,
            $ciudad,
            $municipio,
            $lat,
            $lng
        ]);
        
        $property_id = $conn->lastInsertId();
        
        // ========================================
        // 2. INSERTAR EN property_details
        // ========================================
        $sql = "INSERT INTO property_details (
                    property_id,
                    square_meters,
                    bedrooms,
                    bathrooms,
                    year_built,
                    parking_spots
                ) VALUES (?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        
        $square_meters = $data['m2'] ?? 0;
        $bedrooms = $data['recamaras'] ?? 0;
        $bathrooms = $data['banos'] ?? 0;
        $year_built = null;
        $parking_spots = $data['estacionamiento'] ?? 0;
        
        $stmt->execute([
            $property_id,
            $square_meters,
            $bedrooms,
            $bathrooms,
            $year_built,
            $parking_spots
        ]);
        
        // ========================================
        // 3. INSERTAR EN property_financials
        // ========================================
        $sql = "INSERT INTO property_financials (
                    property_id,
                    asking_price,
                    min_acceptable_price,
                    potential_profit_margin,
                    commission_percentage
                ) VALUES (?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        
        $asking_price = $data['precio'] ?? 0;
        $min_acceptable_price = $data['precio'] ?? 0;
        $profit_margin = 0;
        $commission = 5.0;
        
        $stmt->execute([
            $property_id,
            $asking_price,
            $min_acceptable_price,
            $profit_margin,
            $commission
        ]);
        
        // ========================================
        // 4. INSERTAR EN property_legal
        // ========================================
        $sql = "INSERT INTO property_legal (
                    property_id,
                    has_lien,
                    legal_status_notes,
                    documents_status
                ) VALUES (?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        
        $has_lien = isset($data['has_lien']) ? (int)$data['has_lien'] : 0;
        $debt_amount = isset($data['debt_amount']) && !empty($data['debt_amount']) ? $data['debt_amount'] : null;
        $legal_notes = $data['legal_status_notes'] ?? '';
        $documents_status = 'pending';
        
        $stmt->execute([
            $property_id,
            $has_lien,
            $debt_amount,
            $legal_notes
        ]);
        
        // ========================================
        // 5. INSERTAR EN property_media (imágenes)
        // ========================================
        if (!empty($data['imagenes']) && is_array($data['imagenes'])) {
            $sql = "INSERT INTO property_media (
                        property_id,
                        file_name,
                        file_path,
                        file_size,
                        mime_type,
                        is_primary,
                        sort_order,
                        uploaded_at,
                        uploaded_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)";
            
            $stmt = $conn->prepare($sql);
            
            foreach ($data['imagenes'] as $index => $filepath) {
                if (file_exists($filepath)) {
                    $file_name = basename($filepath);
                    $file_size = filesize($filepath);
                    $mime_type = mime_content_type($filepath);
                    $is_primary = ($index === 0) ? 1 : 0;
                    $sort_order = $index;
                    
                    $stmt->execute([
                        $property_id,
                        $file_name,
                        $filepath,
                        $file_size,
                        $mime_type,
                        $is_primary,
                        $sort_order,
                        $usuario_id
                    ]);
                }
            }
        }
        
        $conn->commit();
        
        return [
            'success' => true,
            'property_id' => $property_id,
            'message' => 'Propiedad guardada exitosamente'
        ];
        
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}
?>