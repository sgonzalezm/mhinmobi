<?php
// includes/notificaciones.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../funciones/correos.php';

/**
 * Obtener mensajes predefinidos según la etapa del proceso
 */
function obtenerMensajeEtapa($etapa, $nombre_cliente, $proceso_id, $property_title) {
    $mensajes = [
        'iniciado' => [
            'asunto' => "📋 Proceso iniciado - {$property_title}",
            'mensaje' => "
                <h2>¡Hola {$nombre_cliente}!</h2>
                <p>Te informamos que se ha iniciado el proceso para la propiedad <strong>{$property_title}</strong>.</p>
                <p><strong>Número de proceso:</strong> #{$proceso_id}</p>
                <p>Te mantendremos informado sobre los avances.</p>
                <p>📌 <strong>Próximo paso:</strong> Revisión de documentación inicial.</p>
            "
        ],
        'documentacion' => [
            'asunto' => "📄 Documentación requerida - Proceso #{$proceso_id}",
            'mensaje' => "
                <h2>Hola {$nombre_cliente}</h2>
                <p>Para continuar con el proceso de <strong>{$property_title}</strong>, necesitamos los siguientes documentos:</p>
                <ul>
                    <li>Identificación oficial (INE/IFE)</li>
                    <li>Comprobante de domicilio (no mayor a 3 meses)</li>
                    <li>Escrituras de la propiedad (si aplica)</li>
                    <li>Último recibo de predial</li>
                </ul>
                <p>Puedes subirlos directamente desde nuestro sistema.</p>
                <a href='" . SITE_URL . "/subir_documentos.php?proceso={$proceso_id}' style='background: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>
                    📤 Subir documentos
                </a>
            "
        ],
        'credito' => [
            'asunto' => "🏦 Estudio de crédito - Proceso #{$proceso_id}",
            'mensaje' => "
                <h2>Hola {$nombre_cliente}</h2>
                <p>Tu proceso para <strong>{$property_title}</strong> está en la etapa de <strong>estudio de crédito</strong>.</p>
                <p>Nuestro equipo financiero está revisando tu información.</p>
                <p>⚠️ <strong>Tiempo estimado:</strong> 5-7 días hábiles.</p>
                <p>Te notificaremos cuando tengamos una respuesta.</p>
            "
        ],
        'credito_preautorizado' => [
            'asunto' => "✅ Crédito preautorizado - Proceso #{$proceso_id}",
            'mensaje' => "
                <h2>¡Excelentes noticias {$nombre_cliente}!</h2>
                <p>Tu crédito para <strong>{$property_title}</strong> ha sido <strong>PREAUTORIZADO</strong> 🎉</p>
                <p>El siguiente paso es la firma del contrato de compraventa.</p>
                <a href='" . SITE_URL . "/generar_contrato.php?proceso_id={$proceso_id}' style='background: #22c55e; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>
                    📝 Generar contrato
                </a>
            "
        ],
        'contrato_compraventa' => [
            'asunto' => "📝 Contrato de compraventa - Proceso #{$proceso_id}",
            'mensaje' => "
                <h2>Hola {$nombre_cliente}</h2>
                <p>El contrato de compraventa para <strong>{$property_title}</strong> está listo.</p>
                <p>Por favor, revisa el documento y agenda una cita para la firma.</p>
                <a href='" . SITE_URL . "/ver_contrato.php?proceso_id={$proceso_id}' style='background: #f59e0b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>
                    👀 Ver contrato
                </a>
            "
        ],
        'poder_notarial' => [
            'asunto' => "📜 Poder notarial - Proceso #{$proceso_id}",
            'mensaje' => "
                <h2>Hola {$nombre_cliente}</h2>
                <p>Para completar el proceso de <strong>{$property_title}</strong>, necesitamos otorgar el poder notarial.</p>
                <p>Por favor, comunícate con nuestra oficina para agendar la cita notarial.</p>
                <p><strong>📞 Teléfono:</strong> +52 555 123 4567</p>
            "
        ],
        'finalizado' => [
            'asunto' => "🎉 Proceso finalizado - {$property_title}",
            'mensaje' => "
                <h2>¡Felicidades {$nombre_cliente}! 🎊</h2>
                <p>El proceso para <strong>{$property_title}</strong> ha sido <strong>COMPLETADO EXITOSAMENTE</strong>.</p>
                <p>¡La propiedad ya es tuya!</p>
                <div style='background: #f0fdf4; padding: 15px; border-radius: 8px; margin: 15px 0;'>
                    <p><strong>📋 Resumen del proceso:</strong></p>
                    <ul>
                        <li>✅ Documentación completa</li>
                        <li>✅ Crédito aprobado</li>
                        <li>✅ Contrato firmado</li>
                        <li>✅ Escrituración realizada</li>
                    </ul>
                </div>
                <p>¿Qué te pareció nuestro servicio? <a href='" . SITE_URL . "/encuesta.php?proceso={$proceso_id}'>Danos tu opinión</a></p>
            "
        ]
    ];
    
    return $mensajes[$etapa] ?? [
        'asunto' => "Actualización de proceso #{$proceso_id}",
        'mensaje' => "
            <h2>Hola {$nombre_cliente}</h2>
            <p>Tu proceso para <strong>{$property_title}</strong> ha avanzado a la etapa: <strong>{$etapa}</strong>.</p>
            <p>Para más detalles, ingresa al sistema.</p>
        "
    ];
}

/**
 * Notificar al cliente sobre el cambio de etapa
 */
function notificarCambioEtapa($conn, $proceso_id, $etapa_nueva) {
    // Verificar si las notificaciones están activas
    if (!NOTIFICACIONES_ACTIVAS) {
        return ['success' => true, 'mensaje' => 'Notificaciones desactivadas (modo prueba)'];
    }
    
    try {
        // 🔥 CONSULTA SQL PARA OBTENER DATOS DEL CLIENTE
        $stmt = $conn->prepare("
            SELECT 
                pt.id,
                pt.property_id,
                pt.current_stage,
                p.title as property_title,
                u.id as user_id,
                u.email,
                u.name,
                u.telefono
            FROM property_tracking pt
            JOIN properties p ON pt.property_id = p.id
            JOIN users u ON pt.initiated_by = u.id
            WHERE pt.id = ?
        ");
        $stmt->execute([$proceso_id]);
        $datos = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 🔥 VALIDAR QUE EXISTAN DATOS
        if (!$datos) {
            error_log("❌ NOTIFICACION - Proceso {$proceso_id} no encontrado");
            return ['success' => false, 'error' => 'Proceso no encontrado'];
        }
        
        // 🔥 LOG DE INTENTO
        error_log("📧 NOTIFICACION - Proceso: {$proceso_id}, Etapa: {$etapa_nueva}");
        error_log("📧 NOTIFICACION - Email: " . ($datos['email'] ?? 'NO DISPONIBLE'));
        error_log("📧 NOTIFICACION - Cliente: " . ($datos['name'] ?? 'NO DISPONIBLE'));
        
        // 🔥 VALIDAR QUE EL CLIENTE TENGA EMAIL
        if (empty($datos['email'])) {
            $error_msg = "El cliente (ID: {$datos['user_id']}) no tiene email registrado";
            error_log("⚠️ " . $error_msg);
            
            // Guardar log de error
            guardarLogCorreo(
                $conn,
                $proceso_id,
                'SIN_EMAIL',
                $etapa_nueva,
                'Notificación fallida - Cliente sin email',
                'fallido',
                $error_msg
            );
            
            return ['success' => false, 'error' => $error_msg];
        }
        
        // 🔥 VALIDAR FORMATO DE EMAIL
        if (!filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) {
            $error_msg = "Email inválido: " . $datos['email'];
            error_log("⚠️ " . $error_msg);
            
            guardarLogCorreo(
                $conn,
                $proceso_id,
                $datos['email'],
                $etapa_nueva,
                'Notificación fallida - Email inválido',
                'fallido',
                $error_msg
            );
            
            return ['success' => false, 'error' => $error_msg];
        }
        
        // Obtener el mensaje según la etapa
        $mensaje = obtenerMensajeEtapa(
            $etapa_nueva,
            $datos['name'],
            $proceso_id,
            $datos['property_title']
        );
        
        // 🔥 LOG DEL MENSAJE
        error_log("📧 NOTIFICACION - Asunto: " . $mensaje['asunto']);
        
        // Enviar correo
        $enviado = enviarCorreo(
            $datos['email'],
            $datos['name'],
            $mensaje['asunto'],
            $mensaje['mensaje']
        );
        
        // 🔥 LOG DEL RESULTADO
        error_log("📧 NOTIFICACION - Resultado: " . ($enviado ? 'ENVIADO' : 'FALLIDO'));
        
        // Guardar en log (SIEMPRE)
        guardarLogCorreo(
            $conn,
            $proceso_id,
            $datos['email'],
            $etapa_nueva,
            $mensaje['asunto'],
            $enviado ? 'enviado' : 'fallido',
            $enviado ? null : 'Error al enviar correo'
        );
        
        if (!$enviado) {
            // 🔥 OBTENER EL ÚLTIMO ERROR DEL LOG
            $error_log_file = __DIR__ . '/../errores_phpmailer.log';
            $error_mensaje = 'Error al enviar correo (sin detalles)';
            
            if (file_exists($error_log_file)) {
                $contenido = file_get_contents($error_log_file);
                $lineas = explode("\n", $contenido);
                // Buscar la última línea con ERROR
                foreach (array_reverse($lineas) as $linea) {
                    if (strpos($linea, 'ERROR:') !== false) {
                        $error_mensaje = trim(str_replace('ERROR:', '', $linea));
                        break;
                    }
                }
            }
            
            error_log("❌ NOTIFICACION - Error final: " . $error_mensaje);
            return ['success' => false, 'error' => 'Error al enviar correo: ' . $error_mensaje];
        }
        
        return ['success' => true, 'mensaje' => 'Notificación enviada correctamente'];
        
    } catch (Exception $e) {
        $error_msg = "Error en notificarCambioEtapa: " . $e->getMessage();
        error_log("❌ " . $error_msg);
        return ['success' => false, 'error' => $error_msg];
    }
}

/**
 * Guardar log de correos enviados
 */
function guardarLogCorreo($conn, $proceso_id, $email, $etapa, $asunto, $estado, $error_mensaje = null) {
    try {
        $sql = "INSERT INTO logs_correos (
                    proceso_id, 
                    email_cliente, 
                    etapa, 
                    asunto, 
                    estado_envio,
                    error_mensaje,
                    fecha_envio
                ) VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$proceso_id, $email, $etapa, $asunto, $estado, $error_mensaje]);
        return true;
    } catch (PDOException $e) {
        error_log("Error guardando log de correo: " . $e->getMessage());
        return false;
    }
}

/**
 * Obtener historial de notificaciones de un proceso
 */
function obtenerHistorialNotificaciones($conn, $proceso_id) {
    try {
        $stmt = $conn->prepare("
            SELECT *
            FROM logs_correos
            WHERE proceso_id = ?
            ORDER BY fecha_envio DESC
        ");
        $stmt->execute([$proceso_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error obteniendo historial: " . $e->getMessage());
        return [];
    }
}

/**
 * Obtener estadísticas de notificaciones
 */
function obtenerEstadisticasNotificaciones($conn) {
    try {
        $stats = [
            'total' => 0,
            'enviados' => 0,
            'fallidos' => 0
        ];
        
        $stmt = $conn->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN estado_envio = 'enviado' THEN 1 ELSE 0 END) as enviados,
                SUM(CASE WHEN estado_envio = 'fallido' THEN 1 ELSE 0 END) as fallidos
            FROM logs_correos
        ");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $stats['total'] = $result['total'] ?? 0;
            $stats['enviados'] = $result['enviados'] ?? 0;
            $stats['fallidos'] = $result['fallidos'] ?? 0;
        }
        
        return $stats;
    } catch (PDOException $e) {
        error_log("Error obteniendo estadísticas: " . $e->getMessage());
        return $stats;
    }
}
?>