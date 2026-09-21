<?php
// ============================================
// procesar_contacto.php
// Procesa el formulario de contacto del detalle de propiedad
// ============================================

// Mostrar errores en desarrollo (comentar en producción)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'includes/conexion.php';

// ============================================
// CONFIGURACIÓN
// ============================================
$email_asesor    = 'atencion@veraterra.com';   // Email donde llegan los leads
$enviar_email    = true;                        // true para enviar email, false para omitir
$origen_lead     = 'detalle_propiedad';         // Identificador del origen

// ============================================
// VALIDAR MÉTODO POST
// ============================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: propiedades.php');
    exit;
}

// ============================================
// OBTENER Y SANITIZAR DATOS
// ============================================
$property_id     = !empty($_POST['property_id']) ? (int)$_POST['property_id'] : null;
$property_title  = trim($_POST['property_title'] ?? '');
$nombre          = trim($_POST['nombre'] ?? '');
$email           = trim($_POST['email'] ?? '');
$telefono        = trim($_POST['telefono'] ?? '');
$mensaje         = trim($_POST['mensaje'] ?? '');
$asunto          = !empty($property_title) 
                    ? 'Interés en propiedad: ' . $property_title
                    : 'Contacto desde detalle de propiedad';

// Datos de contexto
$ip_address      = $_SERVER['REMOTE_ADDR'] ?? null;
$user_agent      = $_SERVER['HTTP_USER_AGENT'] ?? null;

// ============================================
// VALIDACIONES
// ============================================
$errores = [];

if (empty($nombre) || strlen($nombre) < 2) {
    $errores[] = 'El nombre debe tener al menos 2 caracteres.';
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errores[] = 'Ingresa un correo electrónico válido.';
}

if (empty($telefono) || !preg_match('/^[0-9+\-\s()]{8,20}$/', $telefono)) {
    $errores[] = 'Ingresa un número de teléfono válido.';
}

if (empty($mensaje) || strlen($mensaje) < 5) {
    $errores[] = 'El mensaje debe tener al menos 5 caracteres.';
}

if (empty($property_id) || $property_id <= 0) {
    $errores[] = 'No se identificó la propiedad de interés.';
}

// ============================================
// SI HAY ERRORES → Regresar con mensaje
// ============================================
if (!empty($errores)) {
    $_SESSION['contacto_error'] = implode(' ', $errores);
    $_SESSION['contacto_datos'] = [
        'nombre'   => $nombre,
        'email'    => $email,
        'telefono' => $telefono,
        'mensaje'  => $mensaje
    ];
    
    $redirect = $property_id > 0 
        ? 'propiedad_detalle_portal.php?id=' . $property_id 
        : 'propiedades.php';
    
    header('Location: ' . $redirect);
    exit;
}

// ============================================
// GUARDAR EN BASE DE DATOS
// ============================================
try {
    // Verificar que la tabla contactos exista
    $check = $conn->query("SHOW TABLES LIKE 'contactos'");
    if ($check->rowCount() === 0) {
        throw new Exception('La tabla "contactos" no existe en la base de datos.');
    }

    $sql = "INSERT INTO contactos (
        nombre, email, telefono, asunto, mensaje,
        tipo_interes, propiedad_interes, presupuesto, preferencia_contacto,
        ip_address, user_agent, status,
        fecha_contacto, fecha_actualizacion, notas_admin
    ) VALUES (
        :nombre, :email, :telefono, :asunto, :mensaje,
        :tipo_interes, :propiedad_interes, :presupuesto, :preferencia_contacto,
        :ip_address, :user_agent, 'nuevo',
        NOW(), NOW(), NULL
    )";

    $stmt = $conn->prepare($sql);
    $result = $stmt->execute([
        ':nombre'                => $nombre,
        ':email'                 => $email,
        ':telefono'              => $telefono,
        ':asunto'                => $asunto,
        ':mensaje'               => $mensaje,
        ':tipo_interes'          => 'compra',       // Por defecto: interés de compra
        ':propiedad_interes'     => $property_id,
        ':presupuesto'           => null,
        ':preferencia_contacto'  => 'cualquiera',
        ':ip_address'            => $ip_address,
        ':user_agent'            => $user_agent
    ]);

    if (!$result) {
        throw new Exception('No se pudo guardar el contacto.');
    }

    $contacto_id = $conn->lastInsertId();

    // ============================================
    // NOTAS ADMIN: marcar el origen
    // ============================================
    try {
        $stmt_nota = $conn->prepare("
            UPDATE contactos 
            SET notas_admin = CONCAT('Origen: ', :origen, ' | Ref: VT-', LPAD(:pid, 5, '0'))
            WHERE id = :cid
        ");
        $stmt_nota->execute([
            ':origen' => $origen_lead,
            ':pid'    => $property_id,
            ':cid'    => $contacto_id
        ]);
    } catch (PDOException $e) {
        // No es crítico si falla, solo se registra
        error_log("No se pudo actualizar notas_admin: " . $e->getMessage());
    }

    // ============================================
    // ENVIAR EMAIL AL ASESOR (opcional)
    // ============================================
    if ($enviar_email && !empty($email_asesor)) {
        try {
            $ref = 'VT-' . str_pad($property_id, 5, '0', STR_PAD_LEFT);
            
            $html = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #0b1f3a; color: #fff; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                    .header h2 { margin: 0; color: #c5a059; }
                    .content { background: #f8f7f4; padding: 25px; border-radius: 0 0 8px 8px; }
                    .field { padding: 8px 0; border-bottom: 1px solid #e0e0e0; }
                    .field:last-child { border-bottom: none; }
                    .field strong { color: #0b1f3a; display: inline-block; min-width: 130px; }
                    .ref-badge { background: #c5a059; color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 0.8rem; }
                    .footer { text-align: center; padding: 20px; color: #999; font-size: 12px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>🏠 Nuevo Lead desde Portal</h2>
                    </div>
                    <div class='content'>
                        <p style='margin-top:0;'>
                            <span class='ref-badge'>$ref</span>
                            <strong style='color:#0b1f3a; margin-left:10px;'>" . htmlspecialchars($property_title) . "</strong>
                        </p>
                        
                        <div class='field'><strong>Nombre:</strong> " . htmlspecialchars($nombre) . "</div>
                        <div class='field'><strong>Email:</strong> " . htmlspecialchars($email) . "</div>
                        <div class='field'><strong>Teléfono:</strong> " . htmlspecialchars($telefono) . "</div>
                        <div class='field'><strong>Mensaje:</strong><br>" . nl2br(htmlspecialchars($mensaje)) . "</div>
                        <div class='field'><strong>ID Contacto:</strong> #" . $contacto_id . "</div>
                        <div class='field'><strong>IP:</strong> " . htmlspecialchars($ip_address ?? 'No disponible') . "</div>
                    </div>
                    <div class='footer'>
                        Recibido el " . date('d/m/Y H:i') . " · Vera Terra Inmobiliaria
                    </div>
                </div>
            </body>
            </html>
            ";

            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: Vera Terra <no-reply@veraterra.com>\r\n";
            $headers .= "Reply-To: " . $email . "\r\n";

            @mail($email_asesor, "Nuevo Lead: " . $property_title, $html, $headers);
        } catch (Exception $e) {
            // El email no es crítico, solo se registra
            error_log("Error al enviar email del lead: " . $e->getMessage());
        }
    }

    // ============================================
    // ÉXITO → Redirigir con mensaje
    // ============================================
    $_SESSION['contacto_exito'] = '¡Gracias por contactarnos! Te responderemos a la brevedad.';
    
    header('Location: propiedad_detalle_portal.php?id=' . $property_id . '&contacto=ok');
    exit;

} catch (PDOException $e) {
    error_log("Error en procesar_contacto.php (PDO): " . $e->getMessage());
    $_SESSION['contacto_error'] = 'Ocurrió un error al enviar tu mensaje. Intenta nuevamente.';
    header('Location: propiedad_detalle_portal.php?id=' . $property_id . '&contacto=error');
    exit;

} catch (Exception $e) {
    error_log("Error en procesar_contacto.php: " . $e->getMessage());
    $_SESSION['contacto_error'] = 'Error: ' . $e->getMessage();
    header('Location: propiedad_detalle_portal.php?id=' . $property_id . '&contacto=error');
    exit;
}