<?php
// ============================================
// propiedad_detalle_inventario.php
// ============================================

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/conexion.php';
require_once 'includes/auth.php';

// Verificar autenticación
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

// Obtener ID de la propiedad
$property_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($property_id == 0) {
    header('Location: inventario_maestro.php');
    exit;
}

$propiedad = null;
$error_msg = '';
$mensaje_exito = '';
$enlace_generado = '';

// ===== FUNCIÓN PARA OBTENER LA URL BASE CORRECTA =====
function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    
    // Obtener la ruta base del proyecto
    $script_name = $_SERVER['SCRIPT_NAME'];
    $base_path = dirname($script_name);
    
    // Normalizar la ruta base
    if ($base_path == '/' || $base_path == '\\') {
        $base_path = '';
    }
    
    // Asegurar que termina con /
    if (!empty($base_path) && substr($base_path, -1) != '/') {
        $base_path .= '/';
    }
    
    return $protocol . $host . $base_path;
}

// ===== FUNCIÓN PARA OBTENER DOCUMENTOS DE LA PROPIEDAD =====
function getPropertyDocuments($conn, $property_id) {
    $documentos = [
        'generales' => [],
        'clientes' => [],
        'pendientes' => 0,
        'total' => 0
    ];
    
    try {
        // 1. Documentos generales de la propiedad (de property_documents)
        $stmt = $conn->prepare("
            SELECT 
                'general' as origen,
                id,
                document_type,
                file_name,
                file_path,
                file_size,
                mime_type,
                uploaded_at,
                uploaded_by,
                NULL as client_name,
                NULL as client_email,
                NULL as status,
                'general' as tipo_documento
            FROM property_documents
            WHERE property_id = ?
            ORDER BY uploaded_at DESC
        ");
        $stmt->execute([$property_id]);
        $documentos['generales'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 2. Documentos subidos por clientes (de client_uploaded_documents)
        $stmt = $conn->prepare("
            SELECT 
                'cliente' as origen,
                c.id,
                c.document_type,
                c.file_name,
                c.file_path,
                c.file_size,
                c.mime_type,
                c.uploaded_at,
                NULL as uploaded_by,
                t.client_name,
                t.client_email,
                c.status,
                'cliente' as tipo_documento,
                c.status as review_status
            FROM client_uploaded_documents c
            JOIN document_upload_tokens t ON c.token_id = t.id
            WHERE c.property_id = ?
            ORDER BY c.uploaded_at DESC
        ");
        $stmt->execute([$property_id]);
        $documentos['clientes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 3. Contar pendientes de revisión
        $documentos['pendientes'] = count(array_filter($documentos['clientes'], function($doc) {
            return $doc['status'] === 'pending_review';
        }));
        
        $documentos['total'] = count($documentos['generales']) + count($documentos['clientes']);
        
    } catch (PDOException $e) {
        // Si las tablas no existen, ignorar
        error_log("Error al obtener documentos: " . $e->getMessage());
    }
    
    return $documentos;
}

// ===== FUNCIÓN PARA OBTENER ÍCONO SEGÚN TIPO DE DOCUMENTO =====
function getDocumentIcon($document_type) {
    $icons = [
        'ficha_tecnica' => 'fa-clipboard-list',
        'escritura' => 'fa-scroll',
        'avaluo' => 'fa-chart-pie',
        'certificado' => 'fa-certificate',
        'identificacion' => 'fa-id-card',
        'comprobante_domicilio' => 'fa-home',
        'contrato_compraventa' => 'fa-handshake',
        'estado_cuenta' => 'fa-chart-bar',
        'otros' => 'fa-file'
    ];
    return $icons[$document_type] ?? 'fa-file';
}

// ===== FUNCIÓN PARA OBTENER COLOR SEGÚN TIPO DE DOCUMENTO =====
function getDocumentColor($document_type) {
    $colors = [
        'ficha_tecnica' => '#3498db',
        'escritura' => '#2c3e50',
        'avaluo' => '#f39c12',
        'certificado' => '#27ae60',
        'identificacion' => '#9b59b6',
        'comprobante_domicilio' => '#1abc9c',
        'contrato_compraventa' => '#e74c3c',
        'estado_cuenta' => '#e67e22',
        'otros' => '#95a5a6'
    ];
    return $colors[$document_type] ?? '#95a5a6';
}

// ===== FUNCIÓN PARA FORMATEAR TAMAÑO DE ARCHIVO =====
function formatFileSize($bytes) {
    if ($bytes === null || $bytes === 0) return '0 B';
    $k = 1024;
    $sizes = ['B', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return number_format($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

// ===== FUNCIÓN PARA OBTENER ETIQUETA DE ESTADO =====
function getDocumentStatusLabel($status) {
    $labels = [
        'pending_review' => ['label' => '⏳ Pendiente', 'class' => 'status-pending'],
        'approved' => ['label' => '✅ Aprobado', 'class' => 'status-approved'],
        'rejected' => ['label' => '❌ Rechazado', 'class' => 'status-rejected'],
        'pending_correction' => ['label' => '🔄 Corrección', 'class' => 'status-correction']
    ];
    return $labels[$status] ?? ['label' => $status, 'class' => ''];
}

// ============================================
// NUEVAS FUNCIONES PARA GESTIÓN DE PROPIEDAD
// ============================================

// Procesar acciones de gestión (activar/desactivar, apartar, borrar, featuring)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_gestion'])) {
    $accion = $_POST['action_gestion'];
    $propiedad_id = intval($_POST['property_id'] ?? 0);
    
    if ($propiedad_id != $property_id) {
        $error_msg = "❌ Error: ID de propiedad no coincide.";
    } else {
        try {
            switch ($accion) {
                case 'cambiar_estado':
                    $nuevo_estado = $_POST['nuevo_estado'] ?? '';
                    $estados_validos = ['activo', 'pendiente', 'vendido', 'suspendido'];
                    if (in_array($nuevo_estado, $estados_validos)) {
                        $stmt = $conn->prepare("UPDATE properties SET status = ?, updated_at = NOW() WHERE id = ?");
                        $stmt->execute([$nuevo_estado, $propiedad_id]);
                        $mensaje_exito = "✅ Estado actualizado a: " . ucfirst($nuevo_estado);
                    } else {
                        $error_msg = "❌ Estado no válido.";
                    }
                    break;
                    
                case 'apartar':
                    $motivo = trim($_POST['motivo_apartado'] ?? '');
                    $fecha_apartado = date('Y-m-d H:i:s');
                    
                    // Verificar si ya está apartada
                    $stmt = $conn->prepare("SELECT id FROM property_reservations WHERE property_id = ? AND status = 'active'");
                    $stmt->execute([$propiedad_id]);
                    if ($stmt->rowCount() > 0) {
                        $error_msg = "⚠️ Esta propiedad ya está apartada por otro vendedor.";
                    } else {
                        // Insertar reserva
                        $stmt = $conn->prepare("
                            INSERT INTO property_reservations 
                            (property_id, reserved_by, reserved_at, motivo, status) 
                            VALUES (?, ?, ?, ?, 'active')
                        ");
                        $stmt->execute([
                            $propiedad_id,
                            $_SESSION['usuario_id'],
                            $fecha_apartado,
                            $motivo
                        ]);
                        
                        // Cambiar estado a 'apartada' (opcional)
                        $stmt = $conn->prepare("UPDATE properties SET status = 'suspendido', updated_at = NOW() WHERE id = ?");
                        $stmt->execute([$propiedad_id]);
                        
                        $mensaje_exito = "✅ Propiedad apartada exitosamente. Otros vendedores no podrán gestionarla.";
                    }
                    break;
                    
                case 'liberar_apartado':
                    $stmt = $conn->prepare("
                        UPDATE property_reservations 
                        SET status = 'released', released_at = NOW(), released_by = ? 
                        WHERE property_id = ? AND status = 'active'
                    ");
                    $stmt->execute([$_SESSION['usuario_id'], $propiedad_id]);
                    
                    // Restaurar estado anterior (si estaba activa)
                    $stmt = $conn->prepare("UPDATE properties SET status = 'activo', updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$propiedad_id]);
                    
                    $mensaje_exito = "✅ Propiedad liberada. Ya está disponible para otros vendedores.";
                    break;
                    
                case 'borrar':
                    $confirmacion = $_POST['confirmacion_borrar'] ?? '';
                    if ($confirmacion === 'ELIMINAR') {
                        // Verificar si tiene dependencias
                        $stmt = $conn->prepare("
                            SELECT COUNT(*) as total FROM property_media WHERE property_id = ?
                        ");
                        $stmt->execute([$propiedad_id]);
                        $media_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
                        
                        // Eliminar archivos físicos (opcional)
                        if ($media_count > 0) {
                            $stmt = $conn->prepare("SELECT file_path FROM property_media WHERE property_id = ?");
                            $stmt->execute([$propiedad_id]);
                            $archivos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($archivos as $archivo) {
                                $ruta = $archivo['file_path'];
                                if (file_exists($ruta)) {
                                    unlink($ruta);
                                }
                            }
                        }
                        
                        // Eliminar registros
                        $conn->beginTransaction();
                        try {
                            $stmt = $conn->prepare("DELETE FROM property_media WHERE property_id = ?");
                            $stmt->execute([$propiedad_id]);
                            
                            $stmt = $conn->prepare("DELETE FROM property_details WHERE property_id = ?");
                            $stmt->execute([$propiedad_id]);
                            
                            $stmt = $conn->prepare("DELETE FROM property_financials WHERE property_id = ?");
                            $stmt->execute([$propiedad_id]);
                            
                            $stmt = $conn->prepare("DELETE FROM properties WHERE id = ?");
                            $stmt->execute([$propiedad_id]);
                            
                            $conn->commit();
                            
                            // Redirigir al inventario
                            $_SESSION['mensaje_exito'] = "✅ Propiedad eliminada correctamente.";
                            header('Location: inventario_maestro.php');
                            exit;
                            
                        } catch (Exception $e) {
                            $conn->rollBack();
                            $error_msg = "❌ Error al eliminar: " . $e->getMessage();
                        }
                    } else {
                        $error_msg = "❌ Debes escribir 'ELIMINAR' para confirmar el borrado.";
                    }
                    break;
                    
                case 'featuring':
                    $dias_featuring = intval($_POST['dias_featuring'] ?? 7);
                    $precio_featuring = floatval($_POST['precio_featuring'] ?? 0);
                    $fecha_inicio = date('Y-m-d H:i:s');
                    $fecha_fin = date('Y-m-d H:i:s', strtotime("+{$dias_featuring} days"));
                    
                    // Verificar si ya tiene featuring activo
                    $stmt = $conn->prepare("SELECT id FROM property_featuring WHERE property_id = ? AND status = 'active'");
                    $stmt->execute([$propiedad_id]);
                    if ($stmt->rowCount() > 0) {
                        $error_msg = "⚠️ Esta propiedad ya tiene un featuring activo.";
                    } else {
                        $stmt = $conn->prepare("
                            INSERT INTO property_featuring 
                            (property_id, start_date, end_date, dias, precio, status, created_by) 
                            VALUES (?, ?, ?, ?, ?, 'active', ?)
                        ");
                        $stmt->execute([
                            $propiedad_id,
                            $fecha_inicio,
                            $fecha_fin,
                            $dias_featuring,
                            $precio_featuring,
                            $_SESSION['usuario_id']
                        ]);
                        
                        $mensaje_exito = "⭐ Featuring activado por {$dias_featuring} días. La propiedad será destacada en el portal de clientes.";
                    }
                    break;
                    
                case 'desactivar_featuring':
                    $stmt = $conn->prepare("
                        UPDATE property_featuring 
                        SET status = 'inactive', deactivated_at = NOW() 
                        WHERE property_id = ? AND status = 'active'
                    ");
                    $stmt->execute([$propiedad_id]);
                    $mensaje_exito = "⭐ Featuring desactivado.";
                    break;
                    
                default:
                    $error_msg = "❌ Acción no reconocida.";
            }
            
            // Recargar la propiedad para actualizar datos
            recargarPropiedad($conn, $property_id);
            
        } catch (PDOException $e) {
            $error_msg = "❌ Error al procesar la acción: " . $e->getMessage();
        }
    }
}

// Función para recargar datos de la propiedad
function recargarPropiedad($conn, $property_id) {
    global $propiedad;
    try {
        $stmt = $conn->prepare("
            SELECT 
                p.id,
                p.owner_id,
                p.title,
                p.operation_type,
                p.address_city,
                p.address_municipality,
                p.status,
                p.created_at,
                p.updated_at,
                DATEDIFF(NOW(), p.created_at) as days_active,
                pd.square_meters,
                pd.bedrooms,
                pd.bathrooms,
                pd.parking_spots,
                pf.asking_price as price,
                pf.min_acceptable_price,
                pf.potential_profit_margin,
                pf.commission_percentage,
                (pf.asking_price * pf.commission_percentage / 100) as commission_amount,
                s.nombre as owner_name,
                s.email as owner_email
            FROM properties p
            LEFT JOIN property_details pd ON p.id = pd.property_id
            LEFT JOIN property_financials pf ON p.id = pf.property_id
            LEFT JOIN socios s ON p.owner_id = s.id
            WHERE p.id = ?
        ");
        $stmt->execute([$property_id]);
        $propiedad = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error al recargar propiedad: " . $e->getMessage());
    }
}

// Procesar generación de enlace desde el modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generar_enlace') {
    $email = trim($_POST['email'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $dias_validez = (int)($_POST['dias_validez'] ?? 7);
    $max_uploads = (int)($_POST['max_uploads'] ?? 10);
    $token_type = $_POST['token_type'] ?? 'owner';
    $enviar_whatsapp = isset($_POST['enviar_whatsapp']) ? 1 : 0;
    $telefono = trim($_POST['telefono'] ?? '');
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = "❌ Email inválido. Por favor, ingresa un email válido.";
    } else {
        try {
            // Generar token único
            $token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime("+{$dias_validez} days"));
            
            $stmt = $conn->prepare("
                INSERT INTO document_upload_tokens 
                (property_id, client_email, client_name, token, token_type, expires_at, max_uploads, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $property_id, 
                $email, 
                $nombre, 
                $token, 
                $token_type, 
                $expires_at, 
                $max_uploads, 
                $_SESSION['usuario_id']
            ]);
            
            $token_id = $conn->lastInsertId();
            
            // ===== GENERAR URL CORRECTA =====
            $base_url = getBaseUrl();
            $enlace = $base_url . "upload_documentos.php?token=" . $token;
            $enlace_generado = $enlace;
            
            // Enviar email al cliente
            $mensaje_email = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #2c3e50; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                    .content { padding: 20px; background: #f8f9fa; border-radius: 0 0 8px 8px; }
                    .btn { background: #3498db; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; }
                    .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>📄 Subida de Documentos</h2>
                    </div>
                    <div class='content'>
                        <p>Hola " . htmlspecialchars($nombre ?: 'Cliente') . ",</p>
                        <p>Has sido invitado a subir los documentos para la propiedad: <strong>" . htmlspecialchars($propiedad['title'] ?? 'Propiedad') . "</strong></p>
                        <p>Para comenzar, haz clic en el siguiente enlace:</p>
                        <p style='text-align: center; margin: 30px 0;'>
                            <a href='" . $enlace . "' class='btn'>📤 Subir Documentos</a>
                        </p>
                        <p><strong>⏰ Este enlace expirará en " . $dias_validez . " días.</strong></p>
                        <p style='font-size: 12px; color: #666;'>
                            <small>Si el botón no funciona, copia y pega este enlace en tu navegador:</small><br>
                            <span style='word-break: break-all;'>" . $enlace . "</span>
                        </p>
                    </div>
                    <div class='footer'>
                        Este es un mensaje automático de Inmobiliaria MH.
                    </div>
                </div>
            </body>
            </html>
            ";
            
            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: Inmobiliaria MH <no-reply@inmobiliariamh.com>\r\n";
            
            mail($email, "Sube tus documentos - Inmobiliaria MH", $mensaje_email, $headers);
            
            // Si se solicitó enviar por WhatsApp
            if ($enviar_whatsapp && !empty($telefono)) {
                $telefono_limpio = preg_replace('/[^0-9]/', '', $telefono);
                if (strlen($telefono_limpio) >= 10) {
                    // Guardar en sesión para mostrar el enlace de WhatsApp
                    $_SESSION['whatsapp_link'] = "https://wa.me/" . $telefono_limpio . "?text=" . urlencode(
                        "Hola, te comparto el enlace para subir los documentos de la propiedad:\n\n" . 
                        $enlace . "\n\n" .
                        "Este enlace expira en " . $dias_validez . " días.\n" .
                        "Saludos, equipo Inmobiliaria MH."
                    );
                }
            }
            
            $mensaje_exito = "✅ Enlace generado exitosamente y enviado al correo del cliente.";
            
            // Recargar la propiedad para actualizar datos
            recargarPropiedad($conn, $property_id);
            
        } catch (PDOException $e) {
            $error_msg = "❌ Error al generar el enlace: " . $e->getMessage();
        }
    }
}

// Si no se ha recargado después de generar, obtener la propiedad
if (!$propiedad) {
    recargarPropiedad($conn, $property_id);
}

// Obtener imagen principal
$imagen_principal = '';
if ($propiedad) {
    try {
        $stmtImg = $conn->prepare("
            SELECT file_path, is_primary
            FROM property_media
            WHERE property_id = ?
            ORDER BY is_primary DESC, sort_order ASC
            LIMIT 1
        ");
        $stmtImg->execute([$property_id]);
        $img = $stmtImg->fetch(PDO::FETCH_ASSOC);
        if ($img) {
            $imagen_principal = $img['file_path'];
        }
    } catch (PDOException $e) {
        // Ignorar error de imágenes
    }
}

// Obtener tokens generados para esta propiedad (para mostrar historial)
$tokens_generados = [];
if ($propiedad) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                t.*,
                DATEDIFF(t.expires_at, NOW()) as dias_restantes,
                CASE 
                    WHEN t.is_used = 1 THEN 'Usado'
                    WHEN t.expires_at < NOW() THEN 'Expirado'
                    WHEN t.upload_count >= t.max_uploads THEN 'Límite alcanzado'
                    ELSE 'Activo'
                END as estado
            FROM document_upload_tokens t
            WHERE t.property_id = ?
            ORDER BY t.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$property_id]);
        $tokens_generados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Tabla aún no existe
    }
}

// ===== OBTENER INFORMACIÓN DE APARTADO =====
$apartado_info = null;
if ($propiedad) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                r.*,
                u.nombre as reservado_por_nombre,
                u.email as reservado_por_email
            FROM property_reservations r
            LEFT JOIN usuarios u ON r.reserved_by = u.id
            WHERE r.property_id = ? AND r.status = 'active'
            ORDER BY r.reserved_at DESC
            LIMIT 1
        ");
        $stmt->execute([$property_id]);
        $apartado_info = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Tabla no existe
    }
}

// ===== OBTENER INFORMACIÓN DE FEATURING =====
$featuring_info = null;
if ($propiedad) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                f.*,
                u.nombre as creado_por_nombre
            FROM property_featuring f
            LEFT JOIN usuarios u ON f.created_by = u.id
            WHERE f.property_id = ? AND f.status = 'active'
            ORDER BY f.start_date DESC
            LIMIT 1
        ");
        $stmt->execute([$property_id]);
        $featuring_info = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Tabla no existe
    }
}

// Obtener documentos de la propiedad
$documentos_propiedad = [];
if ($propiedad) {
    $documentos_propiedad = getPropertyDocuments($conn, $property_id);
}

// Funciones auxiliares
function formatearPrecio($precio) {
    if ($precio === null || $precio === '' || $precio == 0) {
        return 'Sin asignar';
    }
    return '$' . number_format(floatval($precio), 0, ',', '.');
}

function getStatusBadge($status) {
    $status = strtolower(trim($status ?? ''));
    $classes = [
        'activo' => 'status-active',
        'pendiente' => 'status-pending',
        'vendido' => 'status-sold',
        'suspendido' => 'status-suspended',
        'apartada' => 'status-apartada'
    ];
    return $classes[$status] ?? 'status-other';
}

function getImagePath($imageUrl) {
    if (empty($imageUrl)) {
        return '';
    }
    if (strpos($imageUrl, 'uploads/') === 0) {
        return htmlspecialchars($imageUrl);
    }
    return 'uploads/propiedades/' . htmlspecialchars($imageUrl);
}

function getOperationBadge($operationType) {
    $opType = strtolower(trim($operationType ?? ''));
    if ($opType === 'venta') {
        return ['class' => 'venta', 'label' => 'Venta'];
    } elseif ($opType === 'compra') {
        return ['class' => 'compra', 'label' => 'Compra'];
    } else {
        return ['class' => 'general', 'label' => 'General'];
    }
}

// Obtener teléfono del propietario si existe
$telefono_propietario = '';
if ($propiedad && !empty($propiedad['owner_id'])) {
    try {
        $stmt = $conn->prepare("SELECT telefono FROM socios WHERE id = ?");
        $stmt->execute([$propiedad['owner_id']]);
        $socio = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($socio) {
            $telefono_propietario = $socio['telefono'] ?? '';
        }
    } catch (PDOException $e) {
        // Ignorar
    }
}

// Obtener la URL base para usar en el historial
$base_url = getBaseUrl();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/socios.css">
    <title>Detalle Propiedad | Inventario</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }

        .detail-container {
            max-width: 1100px;
            margin: 0 auto;
        }

        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }

        .detail-title h1 {
            font-size: 1.4rem;
            margin: 0 0 4px 0;
            color: #0f172a;
        }

        .detail-title .subtitle {
            color: #64748b;
            font-size: 0.85rem;
        }

        .detail-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-detail {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-detail.secondary {
            background: #f1f5f9;
            color: #475569;
        }

        .btn-detail.secondary:hover {
            background: #e2e8f0;
        }

        .btn-detail.primary {
            background: #1d4ed8;
            color: white;
        }

        .btn-detail.primary:hover {
            background: #1e40af;
        }

        .btn-detail.success {
            background: #16a34a;
            color: white;
        }

        .btn-detail.success:hover {
            background: #15803d;
        }

        .btn-detail.whatsapp {
            background: #25D366;
            color: white;
        }

        .btn-detail.whatsapp:hover {
            background: #1da851;
        }

        .btn-detail.danger {
            background: #dc2626;
            color: white;
        }

        .btn-detail.danger:hover {
            background: #b91c1c;
        }

        .btn-detail.warning {
            background: #f59e0b;
            color: white;
        }

        .btn-detail.warning:hover {
            background: #d97706;
        }

        .btn-detail.featured {
            background: #8b5cf6;
            color: white;
        }

        .btn-detail.featured:hover {
            background: #7c3aed;
        }

        .main-card {
            background: #ffffff;
            border: 1px solid #e8edf4;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .main-card .card-body {
            padding: 20px;
        }

        .main-card .card-header {
            padding: 16px 20px;
            background: #f8fafc;
            border-bottom: 1px solid #e8edf4;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .main-card .card-header h3 {
            margin: 0;
            font-size: 0.95rem;
            color: #0f172a;
        }

        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px solid #f8fafc;
            font-size: 0.9rem;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-row .label {
            color: #64748b;
            font-weight: 500;
        }

        .info-row .value {
            color: #0f172a;
            font-weight: 600;
        }

        .info-row .value.highlight {
            color: #1d4ed8;
            font-size: 1.1rem;
        }

        .info-row .value.success {
            color: #16a34a;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-badge.status-active { background: #dcfce7; color: #166534; }
        .status-badge.status-pending { background: #fef3c7; color: #92400e; }
        .status-badge.status-sold { background: #dbeafe; color: #1e40af; }
        .status-badge.status-suspended { background: #fee2e2; color: #991b1b; }
        .status-badge.status-apartada { background: #fef3c7; color: #92400e; }
        .status-badge.status-other { background: #f1f5f9; color: #475569; }

        .prop-image {
            width: 100%;
            max-height: 300px;
            object-fit: cover;
            background: #f1f5f9;
        }

        .prop-image-placeholder {
            width: 100%;
            height: 200px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
            color: #94a3b8;
            font-size: 3rem;
        }

        .info-tag {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            background: #f1f5f9;
            color: #475569;
        }

        .info-tag.venta { background: #dcfce7; color: #166534; }
        .info-tag.compra { background: #dbeafe; color: #1e40af; }
        .info-tag.general { background: #f1f5f9; color: #475569; }

        .owner-info {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e8edf4;
        }

        .owner-info .owner-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #1d4ed8;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .owner-info .owner-details {
            flex: 1;
        }

        .owner-info .owner-name {
            font-weight: 600;
            color: #0f172a;
            font-size: 0.9rem;
        }

        .owner-info .owner-contact {
            font-size: 0.75rem;
            color: #64748b;
        }

        .message-box {
            padding: 12px 16px;
            border-radius: 8px;
            margin: 8px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
        }

        .message-box.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .message-box.success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .message-box.info { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }

        /* ===== MODAL ESTILOS ===== */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-box {
            background: white;
            border-radius: 16px;
            max-width: 580px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalIn 0.3s ease-out;
            padding: 0;
        }

        @keyframes modalIn {
            from { transform: scale(0.95) translateY(-20px); opacity: 0; }
            to { transform: scale(1) translateY(0); opacity: 1; }
        }

        .modal-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e8edf4;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
            border-radius: 16px 16px 0 0;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.1rem;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            color: #94a3b8;
            cursor: pointer;
            padding: 0 8px;
            transition: color 0.2s;
        }

        .modal-close:hover {
            color: #0f172a;
        }

        .modal-body {
            padding: 25px;
        }

        .modal-body .form-group {
            margin-bottom: 18px;
        }

        .modal-body .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #0f172a;
            font-size: 0.9rem;
        }

        .modal-body .form-group label .required {
            color: #dc2626;
        }

        .modal-body .form-group .help-text {
            font-size: 0.75rem;
            color: #94a3b8;
            margin-top: 4px;
        }

        .modal-body .form-group input,
        .modal-body .form-group select,
        .modal-body .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: border-color 0.2s;
            font-family: inherit;
        }

        .modal-body .form-group input:focus,
        .modal-body .form-group select:focus,
        .modal-body .form-group textarea:focus {
            border-color: #1d4ed8;
            outline: none;
        }

        .modal-body .form-group .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 0;
        }

        .modal-body .form-group .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .modal-body .form-group .checkbox-group label {
            margin: 0;
            cursor: pointer;
            font-weight: 400;
        }

        .modal-footer {
            padding: 16px 25px;
            border-top: 1px solid #e8edf4;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            background: #f8fafc;
            border-radius: 0 0 16px 16px;
        }

        .btn-modal {
            padding: 10px 24px;
            border: none;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-modal.secondary {
            background: #f1f5f9;
            color: #475569;
        }

        .btn-modal.secondary:hover {
            background: #e2e8f0;
        }

        .btn-modal.primary {
            background: #1d4ed8;
            color: white;
        }

        .btn-modal.primary:hover {
            background: #1e40af;
        }

        .btn-modal.success {
            background: #16a34a;
            color: white;
        }

        .btn-modal.success:hover {
            background: #15803d;
        }

        .btn-modal.danger {
            background: #dc2626;
            color: white;
        }

        .btn-modal.danger:hover {
            background: #b91c1c;
        }

        .btn-modal.warning {
            background: #f59e0b;
            color: white;
        }

        .btn-modal.warning:hover {
            background: #d97706;
        }

        .btn-modal.featured {
            background: #8b5cf6;
            color: white;
        }

        .btn-modal.featured:hover {
            background: #7c3aed;
        }

        /* Enlace generado */
        .link-generated {
            background: #f0fdf4;
            border: 1px solid #86efac;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
        }

        .link-generated .link-url {
            word-break: break-all;
            font-size: 0.85rem;
            color: #166534;
            background: white;
            padding: 10px;
            border-radius: 6px;
            margin: 8px 0;
            border: 1px dashed #86efac;
        }

        .link-generated .btn-copy {
            background: #f1f5f9;
            border: none;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: background 0.2s;
        }

        .link-generated .btn-copy:hover {
            background: #e2e8f0;
        }

        .link-generated .whatsapp-link {
            display: inline-block;
            background: #25D366;
            color: white;
            padding: 6px 14px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.8rem;
            margin-left: 8px;
        }

        .link-generated .whatsapp-link:hover {
            background: #1da851;
        }

        /* Badge de tokens */
        .token-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .token-badge.active { background: #dcfce7; color: #166534; }
        .token-badge.expired { background: #fee2e2; color: #991b1b; }
        .token-badge.used { background: #dbeafe; color: #1e40af; }
        .token-badge.limit { background: #fef3c7; color: #92400e; }

        .tokens-list {
            margin-top: 8px;
        }

        .token-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            background: #f8fafc;
            border-radius: 6px;
            margin-bottom: 4px;
            font-size: 0.8rem;
        }

        .token-item .token-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .token-item .token-info .email {
            color: #475569;
        }

        .token-item .token-info .date {
            color: #94a3b8;
            font-size: 0.7rem;
        }

        /* ===== ESTILOS PARA DOCUMENTOS ===== */
        .doc-item {
            transition: all 0.2s ease;
        }

        .doc-item:hover {
            background: #f1f5f9 !important;
        }

        .doc-status {
            font-size: 0.65rem;
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: 600;
            white-space: nowrap;
        }

        .doc-status.status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .doc-status.status-approved {
            background: #dcfce7;
            color: #166534;
        }

        .doc-status.status-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .doc-status.status-correction {
            background: #dbeafe;
            color: #1e40af;
        }

        .documentos-section .doc-item {
            border-left: 3px solid;
        }

        /* ===== BADGE DE FEATURING ===== */
        .featuring-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            background: #ede9fe;
            color: #7c3aed;
        }

        .featuring-badge.active {
            background: #ede9fe;
            color: #7c3aed;
            animation: pulse-featuring 2s infinite;
        }

        @keyframes pulse-featuring {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }

        /* ===== BADGE DE APARTADO ===== */
        .apartado-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            background: #fef3c7;
            color: #92400e;
        }

        /* ===== TOAST ===== */
        .toast-container {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 99999;
            pointer-events: none;
        }

        .toast {
            background: #0f172a;
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 0.9rem;
            opacity: 0;
            transition: opacity 0.3s;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            pointer-events: none;
        }

        .toast.show {
            opacity: 1;
        }

        @media (max-width: 768px) {
            .two-col {
                grid-template-columns: 1fr;
            }

            .detail-title h1 {
                font-size: 1.1rem;
            }

            .detail-header {
                flex-direction: column;
            }

            .detail-actions {
                width: 100%;
            }

            .detail-actions .btn-detail {
                flex: 1;
                justify-content: center;
                font-size: 0.75rem;
                padding: 6px 10px;
            }

            .modal-box {
                max-width: 100%;
                margin: 10px;
            }

            .modal-body {
                padding: 15px;
            }

            .token-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 4px;
            }

            .doc-item {
                flex-wrap: wrap;
                gap: 6px;
            }
            
            .doc-item .doc-status {
                font-size: 0.6rem;
                padding: 1px 6px;
            }
            
            .doc-item .btn-detail {
                padding: 2px 6px;
                font-size: 0.65rem;
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php include 'sidebar.php'; ?>

<main class="main-content">
    <div class="detail-container">

        <?php if (!empty($error_msg)): ?>
            <div class="message-box error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error_msg); ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($mensaje_exito)): ?>
            <div class="message-box success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($mensaje_exito); ?></span>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['whatsapp_link'])): ?>
            <div class="message-box info">
                <i class="fab fa-whatsapp"></i>
                <span>
                    Enlace generado. 
                    <a href="<?php echo $_SESSION['whatsapp_link']; ?>" target="_blank" style="color: #25D366; font-weight: 600;">
                        <i class="fab fa-whatsapp"></i> Enviar por WhatsApp ahora
                    </a>
                </span>
            </div>
            <?php unset($_SESSION['whatsapp_link']); ?>
        <?php endif; ?>

        <?php if ($propiedad): 
            $badgeOp = getOperationBadge($propiedad['operation_type'] ?? '');
            $esta_apartada = $apartado_info !== null;
            $tiene_featuring = $featuring_info !== null;
        ?>
            <!-- Header -->
            <div class="detail-header">
                <div class="detail-title">
                    <h1>
                        <?php echo htmlspecialchars($propiedad['title']); ?>
                        <?php if ($tiene_featuring): ?>
                            <span class="featuring-badge active">
                                <i class="fas fa-star"></i> Destacada
                            </span>
                        <?php endif; ?>
                        <?php if ($esta_apartada): ?>
                            <span class="apartado-badge">
                                <i class="fas fa-lock"></i> Apartada
                            </span>
                        <?php endif; ?>
                    </h1>
                    <div class="subtitle">
                        <span class="status-badge <?php echo getStatusBadge($propiedad['status']); ?>">
                            <?php echo ucfirst($propiedad['status'] ?? 'Sin estado'); ?>
                        </span>
                        <span class="info-tag <?php echo $badgeOp['class']; ?>">
                            <?php echo $badgeOp['label']; ?>
                        </span>
                        <span style="margin-left: 12px;">
                            <i class="fas fa-calendar-alt"></i> 
                            <?php echo date('d/m/Y', strtotime($propiedad['created_at'])); ?>
                        </span>
                        <span style="margin-left: 12px;">
                            <i class="fas fa-hashtag"></i> ID: <?php echo $propiedad['id']; ?>
                        </span>
                    </div>
                </div>
                <div class="detail-actions">
                    <a href="inventario_maestro.php" class="btn-detail secondary">
                        <i class="fas fa-arrow-left"></i> Volver
                    </a>
                    <button class="btn-detail success" onclick="abrirModalEnlace()">
                        <i class="fas fa-link"></i> Generar Enlace
                    </button>
                    <button class="btn-detail warning" onclick="abrirModalGestion()">
                        <i class="fas fa-cog"></i> Gestionar
                    </button>
                    <a href="propiedad_editar.php?id=<?php echo $property_id; ?>" class="btn-detail primary">
                        <i class="fas fa-edit"></i> Editar
                    </a>
                </div>
            </div>

            <!-- Imagen Principal -->
            <div class="main-card">
                <?php if (!empty($imagen_principal)): ?>
                    <img src="<?php echo getImagePath($imagen_principal); ?>" 
                         alt="<?php echo htmlspecialchars($propiedad['title']); ?>"
                         class="prop-image">
                <?php else: ?>
                    <div class="prop-image-placeholder">
                        <i class="fas fa-building"></i>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Información -->
            <div class="two-col">
                <!-- Columna Izquierda -->
                <div>
                    <div class="main-card">
                        <div class="card-header">
                            <h3><i class="fas fa-info-circle"></i> Información General</h3>
                        </div>
                        <div class="card-body">
                            <div class="info-row">
                                <span class="label">Título</span>
                                <span class="value"><?php echo htmlspecialchars($propiedad['title']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="label">Tipo de operación</span>
                                <span class="value"><?php echo ucfirst($propiedad['operation_type'] ?? 'No especificado'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="label">Municipio</span>
                                <span class="value"><?php echo htmlspecialchars($propiedad['address_municipality'] ?? 'No especificado'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="label">Ciudad</span>
                                <span class="value"><?php echo htmlspecialchars($propiedad['address_city'] ?? 'No especificado'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="label">Estado</span>
                                <span class="value">
                                    <span class="status-badge <?php echo getStatusBadge($propiedad['status']); ?>">
                                        <?php echo ucfirst($propiedad['status'] ?? 'Sin estado'); ?>
                                    </span>
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="label">Días en mercado</span>
                                <span class="value"><?php echo $propiedad['days_active'] ?? 0; ?> días</span>
                            </div>
                        </div>
                    </div>

                    <!-- Características -->
                    <div class="main-card">
                        <div class="card-header">
                            <h3><i class="fas fa-ruler-combined"></i> Características</h3>
                        </div>
                        <div class="card-body">
                            <div class="info-row">
                                <span class="label">Superficie</span>
                                <span class="value"><?php echo number_format($propiedad['square_meters'] ?? 0, 0, ',', '.'); ?> m²</span>
                            </div>
                            <div class="info-row">
                                <span class="label">Habitaciones</span>
                                <span class="value"><?php echo $propiedad['bedrooms'] ?? 'No especificado'; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="label">Baños</span>
                                <span class="value"><?php echo $propiedad['bathrooms'] ?? 'No especificado'; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="label">Estacionamientos</span>
                                <span class="value"><?php echo $propiedad['parking_spots'] ?? 'No especificado'; ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Columna Derecha -->
                <div>
                    <!-- Financiero -->
                    <div class="main-card">
                        <div class="card-header">
                            <h3><i class="fas fa-coins"></i> Información Financiera</h3>
                        </div>
                        <div class="card-body">
                            <div class="info-row">
                                <span class="label">Precio de venta</span>
                                <span class="value highlight"><?php echo formatearPrecio($propiedad['price']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="label">Precio mínimo</span>
                                <span class="value"><?php echo formatearPrecio($propiedad['min_acceptable_price']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="label">Margen potencial</span>
                                <span class="value <?php echo ($propiedad['potential_profit_margin'] ?? 0) > 0 ? 'success' : ''; ?>">
                                    <?php echo number_format($propiedad['potential_profit_margin'] ?? 0, 1); ?>%
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="label">Comisión</span>
                                <span class="value"><?php echo number_format($propiedad['commission_percentage'] ?? 0, 1); ?>%</span>
                            </div>
                            <div class="info-row" style="border-top: 2px solid #e8edf4; padding-top: 10px; margin-top: 4px;">
                                <span class="label" style="font-weight: 700;">Comisión estimada</span>
                                <span class="value highlight"><?php echo formatearPrecio($propiedad['commission_amount'] ?? 0); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Propietario / Vendedor -->
                    <div class="main-card">
                        <div class="card-header">
                            <h3><i class="fas fa-user"></i> Propietario / Vendedor</h3>
                            <?php if (!empty($telefono_propietario)): ?>
                                <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $telefono_propietario); ?>" 
                                   target="_blank" class="btn-detail whatsapp" style="padding: 4px 12px; font-size: 0.75rem;">
                                    <i class="fab fa-whatsapp"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($propiedad['owner_name'])): ?>
                                <div class="owner-info">
                                    <div class="owner-avatar">
                                        <?php echo strtoupper(substr($propiedad['owner_name'], 0, 1)); ?>
                                    </div>
                                    <div class="owner-details">
                                        <div class="owner-name"><?php echo htmlspecialchars($propiedad['owner_name']); ?></div>
                                        <div class="owner-contact">
                                            <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($propiedad['owner_email'] ?? 'No disponible'); ?>
                                            <?php if (!empty($telefono_propietario)): ?>
                                                <br><i class="fas fa-phone"></i> <?php echo htmlspecialchars($telefono_propietario); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="info-row">
                                    <span class="label">Propietario ID</span>
                                    <span class="value">#<?php echo $propiedad['owner_id'] ?? 'No asignado'; ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="label">Email</span>
                                    <span class="value" style="color: #94a3b8; font-weight: 400;">No disponible</span>
                                </div>
                            <?php endif; ?>
                            <div class="info-row" style="margin-top: 8px; border-top: 1px solid #f1f5f9; padding-top: 8px;">
                                <span class="label">ID Propiedad</span>
                                <span class="value">#<?php echo $propiedad['id']; ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- ===== SECCIÓN: ENLACES GENERADOS ===== -->
                    <?php if (!empty($tokens_generados)): ?>
                    <div class="main-card">
                        <div class="card-header">
                            <h3><i class="fas fa-history"></i> Enlaces Generados</h3>
                            <span style="font-size: 0.75rem; color: #94a3b8;">Últimos 5</span>
                        </div>
                        <div class="card-body">
                            <div class="tokens-list">
                                <?php foreach ($tokens_generados as $token): ?>
                                    <div class="token-item">
                                        <div class="token-info">
                                            <span class="token-badge <?php echo strtolower($token['estado']); ?>">
                                                <?php echo $token['estado']; ?>
                                            </span>
                                            <span class="email">
                                                <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($token['client_email']); ?>
                                            </span>
                                            <span class="date">
                                                <i class="fas fa-calendar"></i> <?php echo date('d/m/Y', strtotime($token['created_at'])); ?>
                                            </span>
                                            <span style="font-size: 0.7rem; color: #94a3b8;">
                                                <?php echo $token['upload_count']; ?>/<?php echo $token['max_uploads']; ?> docs
                                            </span>
                                            <?php if ($token['estado'] === 'Activo'): ?>
                                                <span style="font-size: 0.7rem; color: #16a34a;">
                                                    <?php echo $token['dias_restantes']; ?> días
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($token['estado'] === 'Activo'): ?>
                                            <button onclick="copiarTexto('<?php echo $base_url; ?>upload_documentos.php?token=<?php echo $token['token']; ?>')" 
                                                    style="background: none; border: none; color: #1d4ed8; cursor: pointer; font-size: 0.8rem;">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ===== SECCIÓN: DOCUMENTOS DE LA PROPIEDAD ===== -->
            <div class="main-card documentos-section">
                <div class="card-header">
                    <h3>
                        <i class="fas fa-file-alt"></i> Documentos de la Propiedad
                        <span style="font-size: 0.75rem; font-weight: 400; color: #94a3b8; margin-left: 8px;">
                            (<?php echo $documentos_propiedad['total']; ?> archivos)
                            <?php if ($documentos_propiedad['pendientes'] > 0): ?>
                                <span style="background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 10px; margin-left: 5px;">
                                    <?php echo $documentos_propiedad['pendientes']; ?> pendientes
                                </span>
                            <?php endif; ?>
                        </span>
                    </h3>
                    <div style="display: flex; gap: 8px;">
                        <!-- BOTÓN SUBIR - COMENTADO PARA REUTILIZAR CÓDIGO EXISTENTE -->
                        <!-- 
                        <a href="subir_documento.php?property_id=<?php echo $property_id; ?>" class="btn-detail primary" style="padding: 4px 12px; font-size: 0.75rem;">
                            <i class="fas fa-upload"></i> Subir
                        </a>
                        -->
                        <!-- NUEVO BOTÓN PARA ADMIN -->
                        <a href="upload_documentos.php?id=<?php echo $property_id; ?>" 
                        class="btn-detail primary" 
                        style="padding: 4px 12px; font-size: 0.75rem; background: #8b5cf6;" 
                        target="_blank">
                            <i class="fas fa-user-shield"></i> Subir (Admin)
                        </a>
                    </div>
                </div>
                <div class="card-body" style="padding: 10px 20px;">
                    <?php if ($documentos_propiedad['total'] === 0): ?>
                        <div style="text-align: center; padding: 30px 20px; color: #94a3b8;">
                            <i class="fas fa-file" style="font-size: 40px; display: block; margin-bottom: 10px; opacity: 0.5;"></i>
                            <p style="margin: 0;">No hay documentos asociados a esta propiedad.</p>
                            <p style="font-size: 0.85rem; margin-top: 5px;">
                                <a href="upload_documentos.php?id=<?php echo $property_id; ?>" style="color: #1d4ed8; text-decoration: none;">
                                    Subir el primer documento
                                </a>
                            </p>
                        </div>
                    <?php else: ?>
                        <!-- Documentos Generales -->
                        <?php if (!empty($documentos_propiedad['generales'])): ?>
                            <div style="margin-bottom: 15px;">
                                <h4 style="font-size: 0.8rem; color: #64748b; margin: 0 0 8px 0; text-transform: uppercase; letter-spacing: 0.5px;">
                                    <i class="fas fa-folder-open"></i> Documentos Generales
                                    <span style="font-weight: 400; font-size: 0.7rem; color: #94a3b8;">(<?php echo count($documentos_propiedad['generales']); ?>)</span>
                                </h4>
                                <div style="display: grid; grid-template-columns: 1fr; gap: 4px;">
                                    <?php foreach ($documentos_propiedad['generales'] as $doc): ?>
                                        <div class="doc-item" style="display: flex; align-items: center; padding: 8px 12px; background: #f8fafc; border-radius: 6px; border-left-color: <?php echo getDocumentColor($doc['document_type']); ?>; transition: background 0.2s;">
                                            <div style="display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0;">
                                                <i class="fas <?php echo getDocumentIcon($doc['document_type']); ?>" style="color: <?php echo getDocumentColor($doc['document_type']); ?>; font-size: 18px; width: 20px;"></i>
                                                <div style="flex: 1; min-width: 0;">
                                                    <div style="font-size: 0.85rem; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                        <?php echo htmlspecialchars($doc['file_name']); ?>
                                                    </div>
                                                    <div style="font-size: 0.7rem; color: #94a3b8;">
                                                        <span style="text-transform: capitalize;"><?php echo str_replace('_', ' ', $doc['document_type']); ?></span>
                                                        • <?php echo formatFileSize($doc['file_size']); ?>
                                                        • <?php echo date('d/m/Y H:i', strtotime($doc['uploaded_at'])); ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div style="display: flex; gap: 4px; flex-shrink: 0; margin-left: 8px;">
                                                <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" class="btn-detail secondary" style="padding: 2px 8px; font-size: 0.7rem;" title="Ver documento">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" download class="btn-detail secondary" style="padding: 2px 8px; font-size: 0.7rem;" title="Descargar">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Documentos de Clientes -->
                        <?php if (!empty($documentos_propiedad['clientes'])): ?>
                            <div>
                                <h4 style="font-size: 0.8rem; color: #64748b; margin: 0 0 8px 0; text-transform: uppercase; letter-spacing: 0.5px;">
                                    <i class="fas fa-users"></i> Documentos de Clientes
                                    <span style="font-weight: 400; font-size: 0.7rem; color: #94a3b8;">(<?php echo count($documentos_propiedad['clientes']); ?>)</span>
                                    <?php if ($documentos_propiedad['pendientes'] > 0): ?>
                                        <span style="background: #fef3c7; color: #92400e; padding: 1px 8px; border-radius: 10px; font-size: 0.65rem; font-weight: 600;">
                                            <?php echo $documentos_propiedad['pendientes']; ?> pendientes de revisión
                                        </span>
                                    <?php endif; ?>
                                </h4>
                                <div style="display: grid; grid-template-columns: 1fr; gap: 4px;">
                                    <?php foreach ($documentos_propiedad['clientes'] as $doc): 
                                        $status = getDocumentStatusLabel($doc['status']);
                                    ?>
                                        <div class="doc-item" style="display: flex; align-items: center; padding: 8px 12px; background: <?php echo $doc['status'] === 'pending_review' ? '#fefce8' : '#f8fafc'; ?>; border-radius: 6px; border-left-color: <?php echo $doc['status'] === 'pending_review' ? '#f59e0b' : ($doc['status'] === 'approved' ? '#22c55e' : '#ef4444'); ?>; transition: background 0.2s;">
                                            <div style="display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0;">
                                                <i class="fas <?php echo getDocumentIcon($doc['document_type']); ?>" style="color: <?php echo getDocumentColor($doc['document_type']); ?>; font-size: 18px; width: 20px;"></i>
                                                <div style="flex: 1; min-width: 0;">
                                                    <div style="font-size: 0.85rem; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                        <?php echo htmlspecialchars($doc['file_name']); ?>
                                                    </div>
                                                    <div style="font-size: 0.7rem; color: #94a3b8;">
                                                        <span style="text-transform: capitalize;"><?php echo str_replace('_', ' ', $doc['document_type']); ?></span>
                                                        • <?php echo formatFileSize($doc['file_size']); ?>
                                                        • <?php echo date('d/m/Y H:i', strtotime($doc['uploaded_at'])); ?>
                                                        <?php if (!empty($doc['client_name'])): ?>
                                                            • <i class="fas fa-user"></i> <?php echo htmlspecialchars($doc['client_name']); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div style="display: flex; align-items: center; gap: 6px; flex-shrink: 0; margin-left: 8px;">
                                                <span class="doc-status <?php echo $status['class']; ?>" style="font-size: 0.65rem; padding: 2px 8px; border-radius: 10px; <?php 
                                                    echo match($doc['status']) {
                                                        'pending_review' => 'background: #fef3c7; color: #92400e;',
                                                        'approved' => 'background: #dcfce7; color: #166534;',
                                                        'rejected' => 'background: #fee2e2; color: #991b1b;',
                                                        'pending_correction' => 'background: #dbeafe; color: #1e40af;',
                                                        default => 'background: #f1f5f9; color: #475569;'
                                                    };
                                                ?>">
                                                    <?php echo $status['label']; ?>
                                                </span>
                                                <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" class="btn-detail secondary" style="padding: 2px 8px; font-size: 0.7rem;" title="Ver documento">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" download class="btn-detail secondary" style="padding: 2px 8px; font-size: 0.7rem;" title="Descargar">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

        <?php endif; ?>
    </div>
</main>

<!-- ===== MODAL PARA GENERAR ENLACE ===== -->
<div class="modal-overlay" id="modalEnlace">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-link" style="color: #16a34a;"></i> Generar Enlace para Documentos</h3>
            <button class="modal-close" onclick="cerrarModalEnlace()">&times;</button>
        </div>
        <form method="POST" action="" id="formEnlace">
            <input type="hidden" name="action" value="generar_enlace">
            <div class="modal-body">
                <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 20px;">
                    Genera un enlace seguro para que el cliente pueda subir documentos desde su dispositivo.
                </p>

                <div class="form-group">
                    <label>Email del Cliente <span class="required">*</span></label>
                    <input type="email" name="email" required 
                           placeholder="cliente@email.com"
                           value="<?php echo htmlspecialchars($propiedad['owner_email'] ?? ''); ?>">
                    <div class="help-text">El enlace se enviará automáticamente a este correo.</div>
                </div>

                <div class="form-group">
                    <label>Nombre del Cliente</label>
                    <input type="text" name="nombre" 
                           placeholder="Nombre completo"
                           value="<?php echo htmlspecialchars($propiedad['owner_name'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label>Tipo de Usuario</label>
                    <select name="token_type">
                        <option value="owner">Propietario</option>
                        <option value="buyer">Comprador</option>
                        <option value="agent">Agente</option>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Días de Validez</label>
                        <select name="dias_validez">
                            <option value="1">1 día</option>
                            <option value="3">3 días</option>
                            <option value="7" selected>7 días</option>
                            <option value="15">15 días</option>
                            <option value="30">30 días</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Máximo de Archivos</label>
                        <select name="max_uploads">
                            <option value="5">5 archivos</option>
                            <option value="10" selected>10 archivos</option>
                            <option value="20">20 archivos</option>
                            <option value="50">50 archivos</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" name="enviar_whatsapp" id="enviarWhatsapp" value="1">
                        <label for="enviarWhatsapp">
                            <i class="fab fa-whatsapp" style="color: #25D366;"></i> 
                            Enviar también por WhatsApp
                        </label>
                    </div>
                </div>

                <div class="form-group" id="telefonoGroup" style="display: none;">
                    <label>Número de WhatsApp <span class="required">*</span></label>
                    <input type="tel" name="telefono" id="telefonoInput"
                           placeholder="Ej: 5215512345678"
                           value="<?php echo htmlspecialchars($telefono_propietario); ?>">
                    <div class="help-text">Incluye código de país (ej: 521 para México).</div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-modal secondary" onclick="cerrarModalEnlace()">
                    Cancelar
                </button>
                <button type="submit" class="btn-modal success">
                    <i class="fas fa-link"></i> Generar y Enviar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ===== MODAL PARA GESTIÓN DE PROPIEDAD ===== -->
<div class="modal-overlay" id="modalGestion">
    <div class="modal-box" style="max-width: 650px;">
        <div class="modal-header">
            <h3><i class="fas fa-cog" style="color: #f59e0b;"></i> Gestionar Propiedad</h3>
            <button class="modal-close" onclick="cerrarModalGestion()">&times;</button>
        </div>
        <form method="POST" action="" id="formGestion">
            <input type="hidden" name="property_id" value="<?php echo $property_id; ?>">
            <div class="modal-body">
                <!-- ===== CAMBIAR ESTADO ===== -->
                <div style="border-bottom: 1px solid #e8edf4; padding-bottom: 15px; margin-bottom: 15px;">
                    <h4 style="margin: 0 0 10px 0; font-size: 0.95rem; color: #0f172a;">
                        <i class="fas fa-exchange-alt" style="color: #1d4ed8;"></i> Cambiar Estado
                    </h4>
                    <div class="form-group" style="margin-bottom: 0;">
                        <select name="nuevo_estado" style="width: 100%; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 0.9rem;">
                            <option value="activo" <?php echo ($propiedad['status'] ?? '') === 'activo' ? 'selected' : ''; ?>>Activo</option>
                            <option value="pendiente" <?php echo ($propiedad['status'] ?? '') === 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
                            <option value="vendido" <?php echo ($propiedad['status'] ?? '') === 'vendido' ? 'selected' : ''; ?>>Vendido</option>
                            <option value="suspendido" <?php echo ($propiedad['status'] ?? '') === 'suspendido' ? 'selected' : ''; ?>>Suspendido</option>
                        </select>
                    </div>
                    <button type="submit" name="action_gestion" value="cambiar_estado" class="btn-modal primary" style="margin-top: 8px; width: 100%;">
                        <i class="fas fa-save"></i> Actualizar Estado
                    </button>
                </div>

                <!-- ===== APARTAR / LIBERAR ===== -->
                <div style="border-bottom: 1px solid #e8edf4; padding-bottom: 15px; margin-bottom: 15px;">
                    <h4 style="margin: 0 0 10px 0; font-size: 0.95rem; color: #0f172a;">
                        <i class="fas fa-lock" style="color: #f59e0b;"></i> Apartar / Reservar
                    </h4>
                    <?php if ($esta_apartada): ?>
                        <div style="background: #fef3c7; padding: 10px 14px; border-radius: 8px; margin-bottom: 10px; font-size: 0.85rem;">
                            <strong>⚠️ Esta propiedad está apartada</strong>
                            <?php if ($apartado_info): ?>
                                <br>Por: <strong><?php echo htmlspecialchars($apartado_info['reservado_por_nombre'] ?? 'Usuario'); ?></strong>
                                <br>Motivo: <?php echo htmlspecialchars($apartado_info['motivo'] ?? 'Sin motivo'); ?>
                                <br>Fecha: <?php echo date('d/m/Y H:i', strtotime($apartado_info['reserved_at'] ?? 'now')); ?>
                            <?php endif; ?>
                        </div>
                        <button type="submit" name="action_gestion" value="liberar_apartado" class="btn-modal warning" style="width: 100%;">
                            <i class="fas fa-unlock"></i> Liberar Propiedad
                        </button>
                    <?php else: ?>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Motivo del apartado</label>
                            <textarea name="motivo_apartado" rows="2" placeholder="Ej: Negociación en curso con cliente interesado" style="width: 100%; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 0.9rem; font-family: inherit;"></textarea>
                        </div>
                        <button type="submit" name="action_gestion" value="apartar" class="btn-modal warning" style="width: 100%; margin-top: 8px;">
                            <i class="fas fa-lock"></i> Apartar Propiedad
                        </button>
                        <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 4px;">
                            Al apartar, la propiedad se bloqueará para otros vendedores.
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ===== FEATURING ===== -->
                <div style="border-bottom: 1px solid #e8edf4; padding-bottom: 15px; margin-bottom: 15px;">
                    <h4 style="margin: 0 0 10px 0; font-size: 0.95rem; color: #0f172a;">
                        <i class="fas fa-star" style="color: #8b5cf6;"></i> Featuring (Destacar)
                    </h4>
                    <?php if ($tiene_featuring): ?>
                        <div style="background: #ede9fe; padding: 10px 14px; border-radius: 8px; margin-bottom: 10px; font-size: 0.85rem;">
                            <strong>⭐ Featuring activo</strong>
                            <?php if ($featuring_info): ?>
                                <br>Duración: <?php echo $featuring_info['dias']; ?> días
                                <br>Precio: $<?php echo number_format($featuring_info['precio'] ?? 0, 0, ',', '.'); ?>
                                <br>Vence: <?php echo date('d/m/Y', strtotime($featuring_info['end_date'])); ?>
                            <?php endif; ?>
                        </div>
                        <button type="submit" name="action_gestion" value="desactivar_featuring" class="btn-modal secondary" style="width: 100%;">
                            <i class="fas fa-star-half-alt"></i> Desactivar Featuring
                        </button>
                    <?php else: ?>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Días</label>
                                <select name="dias_featuring">
                                    <option value="3">3 días</option>
                                    <option value="7" selected>7 días</option>
                                    <option value="15">15 días</option>
                                    <option value="30">30 días</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Precio</label>
                                <input type="number" name="precio_featuring" placeholder="0.00" step="0.01" value="0.00">
                            </div>
                        </div>
                        <button type="submit" name="action_gestion" value="featuring" class="btn-modal featured" style="width: 100%; margin-top: 8px;">
                            <i class="fas fa-star"></i> Activar Featuring
                        </button>
                        <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 4px;">
                            La propiedad aparecerá destacada en el portal de clientes.
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ===== ELIMINAR ===== -->
                <div>
                    <h4 style="margin: 0 0 10px 0; font-size: 0.95rem; color: #dc2626;">
                        <i class="fas fa-trash-alt" style="color: #dc2626;"></i> Eliminar Propiedad
                    </h4>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="color: #dc2626;">
                            Confirma escribiendo <strong>"ELIMINAR"</strong> <span class="required">*</span>
                        </label>
                        <input type="text" name="confirmacion_borrar" placeholder="Escribe ELIMINAR para confirmar" style="border-color: #fca5a5;">
                        <div class="help-text" style="color: #dc2626;">
                            ⚠️ Esta acción es irreversible. Se eliminarán todos los datos y archivos asociados.
                        </div>
                    </div>
                    <button type="submit" name="action_gestion" value="borrar" class="btn-modal danger" style="width: 100%; margin-top: 8px;">
                        <i class="fas fa-trash-alt"></i> Eliminar Permanentemente
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Toast container -->
<div class="toast-container" id="toastContainer">
    <div class="toast" id="toast">Mensaje</div>
</div>

<script>
// ===== MODAL ENLACE =====
function abrirModalEnlace() {
    document.getElementById('modalEnlace').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function cerrarModalEnlace() {
    document.getElementById('modalEnlace').classList.remove('active');
    document.body.style.overflow = '';
}

// Cerrar modal al hacer clic fuera
document.getElementById('modalEnlace').addEventListener('click', function(e) {
    if (e.target === this) {
        cerrarModalEnlace();
    }
});

// Mostrar/ocultar campo de teléfono
document.getElementById('enviarWhatsapp').addEventListener('change', function() {
    const telefonoGroup = document.getElementById('telefonoGroup');
    if (this.checked) {
        telefonoGroup.style.display = 'block';
        document.getElementById('telefonoInput').required = true;
    } else {
        telefonoGroup.style.display = 'none';
        document.getElementById('telefonoInput').required = false;
    }
});

// ===== MODAL GESTIÓN =====
function abrirModalGestion() {
    document.getElementById('modalGestion').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function cerrarModalGestion() {
    document.getElementById('modalGestion').classList.remove('active');
    document.body.style.overflow = '';
}

document.getElementById('modalGestion').addEventListener('click', function(e) {
    if (e.target === this) {
        cerrarModalGestion();
    }
});

// ===== COPIAR TEXTO =====
function copiarTexto(texto) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(texto).then(() => {
            mostrarToast('✅ Enlace copiado al portapapeles');
        }).catch(() => {
            copiarTextoAlternativo(texto);
        });
    } else {
        copiarTextoAlternativo(texto);
    }
}

function copiarTextoAlternativo(texto) {
    const textarea = document.createElement('textarea');
    textarea.value = texto;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand('copy');
    document.body.removeChild(textarea);
    mostrarToast('✅ Enlace copiado al portapapeles');
}

// ===== TOAST =====
function mostrarToast(mensaje) {
    const toast = document.getElementById('toast');
    const container = document.getElementById('toastContainer');
    
    toast.textContent = mensaje;
    toast.classList.add('show');
    
    container.style.pointerEvents = 'none';
    
    clearTimeout(toast._timeout);
    toast._timeout = setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}

// ===== MENÚ MÓVIL =====
document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    function toggleSidebar() {
        if (sidebar && overlay) {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('show');
            document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
        }
    }

    if(menuToggle) {
        menuToggle.addEventListener('click', toggleSidebar);
    }
    
    if(overlay) {
        overlay.addEventListener('click', toggleSidebar);
    }

    // Mostrar toast si hay mensaje de éxito
    <?php if (!empty($enlace_generado)): ?>
        setTimeout(() => {
            mostrarToast('✅ Enlace generado y enviado al cliente');
        }, 500);
    <?php endif; ?>
});
</script>

</body>
</html>