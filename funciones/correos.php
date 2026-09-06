<?php
// funciones/correos.php - VERSIÓN COMPLETA CON ICS

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

// ===== FUNCIÓN EXISTENTE PARA ENVIAR CORREO =====
function enviarCorreo($email, $nombre, $asunto, $mensaje_html) {
    // 🔥 LOG DE INICIO
    error_log("📧 INTENTANDO ENVIAR CORREO a: " . $email);
    error_log("📧 Asunto: " . $asunto);
    
    try {
        $mail = new PHPMailer(true);
        
        // Configuración SSL
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 30;
        
        // 🔥 DEBUG ACTIVADO (esto escribirá en el log de PHP)
        $mail->SMTPDebug = 2;
        $mail->Debugoutput = function($str, $level) {
            error_log("PHPMailer DEBUG: " . trim($str));
        };
        
        // Opciones SSL
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        // Remitente y destinatario
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($email, $nombre);
        $mail->addReplyTo(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        
        // Contenido
        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body    = $mensaje_html;
        $mail->AltBody = strip_tags($mensaje_html);
        
        // 🔥 LOG ANTES DE ENVIAR
        error_log("📧 Enviando correo...");
        
        $mail->send();
        
        // 🔥 LOG DE ÉXITO
        error_log("✅ CORREO ENVIADO EXITOSAMENTE a: " . $email);
        return true;
        
    } catch (Exception $e) {
        // 🔥 CAPTURAR EL ERROR EXACTO
        $error_detalle = $mail->ErrorInfo ?? $e->getMessage();
        
        // 🔥 LOG DEL ERROR
        error_log("❌ ERROR PHPMailer: " . $error_detalle);
        error_log("❌ Destinatario: " . $email);
        error_log("❌ Asunto: " . $asunto);
        error_log("❌ Trace: " . $e->getTraceAsString());
        
        // 🔥 GUARDAR EN ARCHIVO DE LOG
        $log_file = __DIR__ . '/../errores_phpmailer.log';
        $mensaje_log = date('Y-m-d H:i:s') . " - ERROR: " . $error_detalle . "\n";
        $mensaje_log .= "Destinatario: " . $email . "\n";
        $mensaje_log .= "Asunto: " . $asunto . "\n";
        $mensaje_log .= "---\n";
        file_put_contents($log_file, $mensaje_log, FILE_APPEND);
        
        return false;
    }
}

// ===== NUEVAS FUNCIONES PARA EVENTOS =====

/**
 * Genera contenido de archivo ICS para un evento
 */
function generarICS($evento) {
    date_default_timezone_set('America/Santiago'); // Ajusta tu zona horaria
    
    $uid = uniqid() . '@inmobiliaria-mh.cl';
    $dtstamp = gmdate('Ymd\THis\Z');
    $dtstart = date('Ymd\THis', strtotime($evento['start_datetime'])) . 'Z';
    $dtend = date('Ymd\THis', strtotime($evento['end_datetime'] ?? $evento['start_datetime'] . ' +1 hour')) . 'Z';
    
    // Limpiar caracteres especiales
    $summary = preg_replace('/[;,]/', '', $evento['title']);
    $description = preg_replace('/[;,]/', '', $evento['description'] ?? '');
    $location = preg_replace('/[;,]/', '', $evento['location'] ?? '');
    
    $ics = "BEGIN:VCALENDAR\r\n";
    $ics .= "VERSION:2.0\r\n";
    $ics .= "PRODID:-//Inmobiliaria MH//Calendario//ES\r\n";
    $ics .= "CALSCALE:GREGORIAN\r\n";
    $ics .= "BEGIN:VEVENT\r\n";
    $ics .= "UID:{$uid}\r\n";
    $ics .= "DTSTAMP:{$dtstamp}\r\n";
    $ics .= "DTSTART:{$dtstart}\r\n";
    $ics .= "DTEND:{$dtend}\r\n";
    $ics .= "SUMMARY:{$summary}\r\n";
    if ($description) $ics .= "DESCRIPTION:{$description}\r\n";
    if ($location) $ics .= "LOCATION:{$location}\r\n";
    $ics .= "END:VEVENT\r\n";
    $ics .= "END:VCALENDAR\r\n";
    
    return $ics;
}

/**
 * Envía un correo con los detalles del evento y el ICS adjunto
 */
function enviarCorreoEvento($destinatario, $nombre, $evento, $asunto = 'Recordatorio de evento', $cuerpo = null) {
    // 🔥 LOG
    error_log("📧 Enviando correo de evento a: " . $destinatario);
    error_log("📧 Evento: " . $evento['title']);
    
    try {
        $mail = new PHPMailer(true);
        
        // Configuración SMTP (igual que en enviarCorreo)
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 30;
        
        // Debug (puedes activar/desactivar según necesites)
        // $mail->SMTPDebug = 2;
        // $mail->Debugoutput = function($str, $level) {
        //     error_log("PHPMailer DEBUG: " . trim($str));
        // };
        
        // Opciones SSL
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        // Remitente y destinatario
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($destinatario, $nombre);
        $mail->addReplyTo(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        
        // Asunto
        $mail->Subject = $asunto;
        
        // Cuerpo del mensaje
        if ($cuerpo === null) {
            $fecha = date('d/m/Y H:i', strtotime($evento['start_datetime']));
            $tipoEvento = ucfirst($evento['event_type'] ?? 'evento');
            $color = $evento['color'] ?? '#1d4ed8';
            
            $cuerpo = "
                <!DOCTYPE html>
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: {$color}; color: white; padding: 15px; border-radius: 8px 8px 0 0; }
                        .content { padding: 20px; border: 1px solid #e5e7eb; border-radius: 0 0 8px 8px; }
                        .event-detail { margin: 10px 0; padding: 10px; background: #f8fafc; border-radius: 6px; }
                        .badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: bold; }
                        .badge-pending { background: #fef3c7; color: #92400e; }
                        .badge-confirmed { background: #dbeafe; color: #1e40af; }
                        .badge-completed { background: #dcfce7; color: #166534; }
                        .badge-cancelled { background: #fee2e2; color: #991b1b; }
                        .footer { margin-top: 20px; font-size: 12px; color: #6b7280; text-align: center; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h2 style='margin:0;'>📅 {$tipoEvento}</h2>
                        </div>
                        <div class='content'>
                            <h3 style='margin-top:0;'>{$evento['title']}</h3>
                            
                            <div class='event-detail'>
                                <p><strong>📅 Fecha:</strong> {$fecha}</p>
                                " . (!empty($evento['location']) ? "<p><strong>📍 Ubicación:</strong> {$evento['location']}</p>" : "") . "
                                " . (!empty($evento['description']) ? "<p><strong>📝 Descripción:</strong> {$evento['description']}</p>" : "") . "
                                <p><strong>📌 Estado:</strong> <span class='badge badge-{$evento['status']}'>{$evento['status']}</span></p>
                            </div>
                            
                            <p style='margin-top: 15px;'>
                                <strong>📎 Adjunto:</strong> Archivo <strong>.ics</strong> para agregar a tu calendario.
                            </p>
                            <p style='font-size:14px; color:#6b7280;'>
                                💡 Haz clic en el archivo adjunto para añadirlo a Google Calendar, Outlook u otro calendario.
                            </p>
                        </div>
                        <div class='footer'>
                            <p>Este es un mensaje automático de <strong>Inmobiliaria MH</strong></p>
                            <p>No respondas a este correo.</p>
                        </div>
                    </div>
                </body>
                </html>
            ";
        }
        
        $mail->isHTML(true);
        $mail->Body = $cuerpo;
        $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n"], $cuerpo));
        
        // Adjuntar ICS
        $icsContent = generarICS($evento);
        $mail->addStringAttachment($icsContent, 'evento.ics', 'text/calendar', 'base64');
        
        // Enviar
        $mail->send();
        
        error_log("✅ Correo de evento enviado a: " . $destinatario);
        return true;
        
    } catch (Exception $e) {
        $error_detalle = $mail->ErrorInfo ?? $e->getMessage();
        error_log("❌ ERROR al enviar correo de evento: " . $error_detalle);
        error_log("❌ Destinatario: " . $destinatario);
        error_log("❌ Evento: " . $evento['title']);
        
        // Guardar en log
        $log_file = __DIR__ . '/../errores_eventos.log';
        $mensaje_log = date('Y-m-d H:i:s') . " - ERROR: " . $error_detalle . "\n";
        $mensaje_log .= "Destinatario: " . $destinatario . "\n";
        $mensaje_log .= "Evento: " . $evento['title'] . "\n";
        $mensaje_log .= "---\n";
        file_put_contents($log_file, $mensaje_log, FILE_APPEND);
        
        return false;
    }
}
?>