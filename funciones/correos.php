<?php
// funciones/correos.php - VERSIÓN CON DEBUG

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

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
?>