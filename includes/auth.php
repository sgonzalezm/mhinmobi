<?php
/**
 * Funciones de autenticación para el sistema
 */

/**
 * Autenticar usuario por email y password
 * @param string $email Email del usuario
 * @param string $password Contraseña sin encriptar
 * @param PDO $conn Conexión a la base de datos
 * @return array|false Datos del usuario o false si falla
 */
function autenticarUsuario($email, $password, $conn) {
    try {
        // Buscar usuario por email - Incluyendo todos los campos
        $stmt = $conn->prepare("
            SELECT id, name, email, telefono, password_hash, role, activo, created_at
            FROM users 
            WHERE email = ? 
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verificar si existe
        if (!$usuario) {
            return false;
        }

        // Verificar si el usuario está activo
        if (isset($usuario['activo']) && $usuario['activo'] == 0) {
            return false; // Usuario inactivo
        }

        // Verificar contraseña
        if (password_verify($password, $usuario['password_hash'])) {
            // Eliminar el hash antes de devolver
            unset($usuario['password_hash']);
            return $usuario;
        }

        return false;
    } catch (PDOException $e) {
        error_log('Error en autenticación: ' . $e->getMessage());
        return false;
    }
}

/**
 * Iniciar sesión de usuario (guardar datos en sesión)
 * @param array $usuario Datos del usuario autenticado
 */
function iniciarSesion($usuario) {
    $_SESSION['usuario_id'] = $usuario['id'];
    $_SESSION['usuario_nombre'] = $usuario['name'];
    $_SESSION['usuario_email'] = $usuario['email'];
    $_SESSION['usuario_telefono'] = $usuario['telefono'] ?? null;
    $_SESSION['usuario_rol'] = $usuario['role'] ?? 'propietario';
    $_SESSION['usuario_activo'] = $usuario['activo'] ?? 1;
    $_SESSION['created_at'] = $usuario['created_at'] ?? date('Y-m-d H:i:s');
    $_SESSION['autenticado'] = true;
}

/**
 * Verificar si el usuario está logueado
 * @return bool
 */
function estaLogueado() {
    return isset($_SESSION['usuario_id']) && 
           isset($_SESSION['usuario_nombre']) && 
           isset($_SESSION['autenticado']) && 
           $_SESSION['autenticado'] === true;
}

/**
 * Verificar si el usuario tiene un rol específico
 * @param string|array $roles Rol o array de roles permitidos
 * @return bool
 */
function tieneRol($roles) {
    if (!estaLogueado()) {
        return false;
    }
    
    $rol_actual = $_SESSION['usuario_rol'] ?? 'propietario';
    
    if (is_array($roles)) {
        return in_array($rol_actual, $roles);
    }
    
    return $rol_actual === $roles;
}

/**
 * Verificar si el usuario es administrador
 * @return bool
 */
function esAdmin() {
    return tieneRol('admin');
}

/**
 * Obtener datos del usuario logueado
 * @param PDO $conn Conexión a la base de datos
 * @return array|false Datos del usuario o false
 */
function obtenerUsuarioActual($conn) {
    if (!estaLogueado()) {
        return false;
    }
    
    try {
        $stmt = $conn->prepare("
            SELECT id, name, email, telefono, role, activo, created_at
            FROM users 
            WHERE id = ?
        ");
        $stmt->execute([$_SESSION['usuario_id']]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Actualizar sesión con datos frescos
        if ($usuario) {
            $_SESSION['usuario_nombre'] = $usuario['name'];
            $_SESSION['usuario_email'] = $usuario['email'];
            $_SESSION['usuario_telefono'] = $usuario['telefono'] ?? null;
            $_SESSION['usuario_rol'] = $usuario['role'];
            $_SESSION['usuario_activo'] = $usuario['activo'];
        }
        
        return $usuario;
    } catch (PDOException $e) {
        error_log('Error al obtener usuario: ' . $e->getMessage());
        return false;
    }
}

/**
 * Obtener todos los usuarios (para administradores)
 * @param PDO $conn Conexión a la base de datos
 * @return array Lista de usuarios
 */
function obtenerTodosUsuarios($conn) {
    try {
        $stmt = $conn->query("
            SELECT id, name, email, telefono, role, activo, created_at
            FROM users
            ORDER BY created_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Error al obtener usuarios: ' . $e->getMessage());
        return [];
    }
}

/**
 * Crear un nuevo usuario
 * @param PDO $conn Conexión a la base de datos
 * @param array $datos Datos del usuario
 * @return int|false ID del usuario creado o false
 */
function crearUsuario($conn, $datos) {
    try {
        // Verificar si el email ya existe
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$datos['email']]);
        if ($stmt->fetch()) {
            return false;
        }
        
        // Hash de la contraseña
        $password_hash = password_hash($datos['password'], PASSWORD_DEFAULT);
        
        // Insertar usuario
        $stmt = $conn->prepare("
            INSERT INTO users (name, email, telefono, password_hash, role, activo, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $datos['name'],
            $datos['email'],
            $datos['telefono'] ?? null,
            $password_hash,
            $datos['role'] ?? 'propietario',
            $datos['activo'] ?? 1
        ]);
        
        return $conn->lastInsertId();
    } catch (PDOException $e) {
        error_log('Error al crear usuario: ' . $e->getMessage());
        return false;
    }
}

/**
 * Actualizar un usuario
 * @param PDO $conn Conexión a la base de datos
 * @param int $id ID del usuario
 * @param array $datos Datos a actualizar
 * @return bool
 */
function actualizarUsuario($conn, $id, $datos) {
    try {
        $update_fields = [];
        $params = [];
        
        if (isset($datos['name'])) {
            $update_fields[] = "name = ?";
            $params[] = $datos['name'];
        }
        
        if (isset($datos['email'])) {
            // Verificar si el email ya existe (excepto el usuario actual)
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$datos['email'], $id]);
            if ($stmt->fetch()) {
                return false;
            }
            $update_fields[] = "email = ?";
            $params[] = $datos['email'];
        }
        
        if (isset($datos['telefono'])) {
            $update_fields[] = "telefono = ?";
            $params[] = $datos['telefono'];
        }
        
        if (isset($datos['role'])) {
            $update_fields[] = "role = ?";
            $params[] = $datos['role'];
        }
        
        if (isset($datos['activo'])) {
            $update_fields[] = "activo = ?";
            $params[] = $datos['activo'];
        }
        
        if (isset($datos['password']) && !empty($datos['password'])) {
            $password_hash = password_hash($datos['password'], PASSWORD_DEFAULT);
            $update_fields[] = "password_hash = ?";
            $params[] = $password_hash;
        }
        
        if (empty($update_fields)) {
            return true;
        }
        
        $params[] = $id;
        $sql = "UPDATE users SET " . implode(", ", $update_fields) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        return $stmt->execute($params);
    } catch (PDOException $e) {
        error_log('Error al actualizar usuario: ' . $e->getMessage());
        return false;
    }
}

/**
 * Cambiar estado de un usuario (activar/desactivar)
 * @param PDO $conn Conexión a la base de datos
 * @param int $id ID del usuario
 * @param int $estado 1=activo, 0=inactivo
 * @return bool
 */
function cambiarEstadoUsuario($conn, $id, $estado) {
    try {
        $stmt = $conn->prepare("UPDATE users SET activo = ? WHERE id = ?");
        return $stmt->execute([$estado, $id]);
    } catch (PDOException $e) {
        error_log('Error al cambiar estado: ' . $e->getMessage());
        return false;
    }
}

/**
 * Eliminar un usuario
 * @param PDO $conn Conexión a la base de datos
 * @param int $id ID del usuario
 * @return bool
 */
function eliminarUsuario($conn, $id) {
    try {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        return $stmt->execute([$id]);
    } catch (PDOException $e) {
        error_log('Error al eliminar usuario: ' . $e->getMessage());
        return false;
    }
}

/**
 * Cerrar sesión
 */
function cerrarSesion() {
    // Limpiar todas las variables de sesión
    $_SESSION = array();
    
    // Destruir la sesión
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
}

/**
 * Registrar intento de acceso fallido
 */
function registrarIntentoFallido($conn, $email, $ip) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO logs_acceso (usuario_email, ip, fecha, exitoso, accion)
            VALUES (?, ?, NOW(), 0, 'Intento de login fallido')
        ");
        $stmt->execute([$email, $ip]);
    } catch (PDOException $e) {
        error_log('Error al registrar intento fallido: ' . $e->getMessage());
    }
}

/**
 * Registrar acceso exitoso
 */
function registrarAccesoExitoso($conn, $usuario_id, $ip) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO logs_acceso (usuario_id, ip, fecha, exitoso, accion)
            VALUES (?, ?, NOW(), 1, 'Login exitoso')
        ");
        $stmt->execute([$usuario_id, $ip]);
    } catch (PDOException $e) {
        error_log('Error al registrar acceso: ' . $e->getMessage());
    }
}