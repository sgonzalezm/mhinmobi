<?php
// Token que inventaste arriba. Debe coincidir EXACTAMENTE.
$mi_token_secreto = 'vera_terra_2026_secreto';

// Meta envía estos parámetros por GET para verificar el webhook
$modo = $_GET['hub_mode'] ?? '';
$token_recibido = $_GET['hub_verify_token'] ?? '';
$challenge = $_GET['hub_challenge'] ?? '';

// Verificamos que el modo sea 'subscribe' y el token coincida
if ($modo === 'subscribe' && $token_recibido === $mi_token_secreto) {
    // Meta espera que devolvamos el challenge tal cual
    echo $challenge;
    exit;
}

// Si la verificación falla, devolvemos 403
http_response_code(403);
echo 'Token de verificación inválido';
exit;
?>