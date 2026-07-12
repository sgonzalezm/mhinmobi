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
        // Buscar usuario por email
        $stmt = $conn->prepare("
            SELECT id, nombre, email, password, activo 
            FROM socios 
            WHERE email = ? 
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verificar si existe y la contraseña es correcta
        if ($usuario && password_verify($password, $usuario['password'])) {
            // Eliminar la contraseña antes de devolver
            unset($usuario['password']);
            return $usuario;
        }

        return false;
    } catch (PDOException $e) {
        error_log('Error en autenticación: ' . $e->getMessage());
        return false;
    }
}

/**
 * Verificar si el usuario está logueado
 * @return bool
 */
function estaLogueado() {
    return isset($_SESSION['usuario_id']) && isset($_SESSION['usuario_nombre']);
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
    
    $rol_actual = $_SESSION['usuario_rol'] ?? 'socio';
    
    if (is_array($roles)) {
        return in_array($rol_actual, $roles);
    }
    
    return $rol_actual === $roles;
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
            SELECT id, nombre, email, activo 
            FROM socios 
            WHERE id = ?
        ");
        $stmt->execute([$_SESSION['usuario_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Cerrar sesión
 */
function cerrarSesion() {
    // Eliminar cookie de remember
    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 3600, '/');
    }
    
    // Destruir sesión
    session_destroy();
}

/**
 * Verificar token de remember (para auto-login)
 * @param PDO $conn Conexión a la base de datos
 * @return bool
 */
function verificarRememberToken($conn) {
    if (!isset($_COOKIE['remember_token'])) {
        return false;
    }
    
    $token = $_COOKIE['remember_token'];
    
    try {
        $stmt = $conn->prepare("
            SELECT id, nombre, email, rol 
            FROM socios 
            WHERE remember_token = ? AND token_expiry > NOW()
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($usuario) {
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['usuario_nombre'] = $usuario['nombre'];
            $_SESSION['usuario_email'] = $usuario['email'];
            return true;
        }
        
        return false;
    } catch (PDOException $e) {
        return false;
    }
}
?>