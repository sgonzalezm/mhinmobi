<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// Verificar sesión
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'Sesión expirada. Recarga la página.']);
    exit;
}

// Solo aceptar POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

$input = trim($_POST['ubicacion'] ?? '');
if ($input === '') {
    echo json_encode(['success' => false, 'error' => 'Pega un enlace primero.']);
    exit;
}

// Si el usuario pegó texto con el enlace mezclado (ej: "Mira la casa: https://maps...")
// extraer solo la URL
if (preg_match('#https?://\S+#i', $input, $m)) {
    $input = $m[0];
}

// Validar que sea una URL
if (!preg_match('#^https?://#i', $input)) {
    echo json_encode([
        'success' => false,
        'error'   => 'Eso no es un enlace válido. Pide al cliente que comparta el mapa por WhatsApp.'
    ]);
    exit;
}

// Seguridad: solo dominios de Google Maps permitidos (evita SSRF)
$host = strtolower(parse_url($input, PHP_URL_HOST) ?: '');
$permitidos = [
    'maps.app.goo.gl',
    'goo.gl',
    'google.com',
    'www.google.com',
    'maps.google.com',
    'maps.google.com.mx',
    'www.google.com.mx',
    'google.com.mx',
];

if (!in_array($host, $permitidos, true)) {
    echo json_encode([
        'success' => false,
        'error'   => 'Eso no es un enlace de Google Maps. Pide al cliente que comparta el mapa por WhatsApp.'
    ]);
    exit;
}

// Verificar que cURL esté disponible
if (!function_exists('curl_init')) {
    echo json_encode([
        'success' => false,
        'error'   => 'El servidor no tiene cURL habilitado. Contacta al administrador.'
    ]);
    exit;
}

// Seguir redirecciones (necesario para enlaces cortos maps.app.goo.gl)
$ch = curl_init($input);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 10,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
]);

$html     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$urlFinal = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    echo json_encode([
        'success' => false,
        'error'   => 'No pude abrir el enlace. Verifica tu conexión e intenta de nuevo.'
    ]);
    exit;
}

if ($httpCode >= 400) {
    echo json_encode([
        'success' => false,
        'error'   => 'El enlace expiró o no es válido. Pide al cliente que lo comparta de nuevo.'
    ]);
    exit;
}

// Extraer lat/lng de la URL final
// Probamos varios patrones porque Google Maps usa formatos distintos
$lat = null;
$lng = null;

$patrones = [
    // /@19.4326,-99.1332,17z  (formato más común)
    '#@(-?\d{1,3}\.\d+),(-?\d{1,3}\.\d+)#',
    // /place/19.4326,-99.1332
    '#/place/(-?\d{1,3}\.\d+),(-?\d{1,3}\.\d+)#',
    // ?q=19.4326,-99.1332  o  ?query=  o  ?ll=
    '#[?&](?:q|query|ll|center)=(-?\d{1,3}\.\d+),(-?\d{1,3}\.\d+)#',
    // !3d19.4326!4d-99.1332  (formato interno de Google Maps)
    '#!3d(-?\d{1,3}\.\d+)!4d(-?\d{1,3}\.\d+)#',
];

foreach ($patrones as $patron) {
    if (preg_match($patron, $urlFinal, $m)) {
        $lat = (float)$m[1];
        $lng = (float)$m[2];
        break;
    }
}

// Si no encontramos en la URL final, intentar en el HTML (último recurso)
if ($lat === null && !empty($html)) {
    // Buscar patrones tipo: [null,null,19.4326,-99.1332]
    if (preg_match('#\[null,null,(-?\d{1,3}\.\d+),(-?\d{1,3}\.\d+)\]#', $html, $m)) {
        $lat = (float)$m[1];
        $lng = (float)$m[2];
    }
    // O: "latitude":19.4326,"longitude":-99.1332
    elseif (preg_match('#"latitude":\s*(-?\d{1,3}\.\d+).*?"longitude":\s*(-?\d{1,3}\.\d+)#s', $html, $m)) {
        $lat = (float)$m[1];
        $lng = (float)$m[2];
    }
}

if ($lat === null || $lng === null) {
    echo json_encode([
        'success' => false,
        'error'   => 'No pude leer la ubicación de ese enlace. Pide al cliente que lo comparta de nuevo.'
    ]);
    exit;
}

// Validar rangos válidos de coordenadas
if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    echo json_encode([
        'success' => false,
        'error'   => 'Las coordenadas del enlace no son válidas.'
    ]);
    exit;
}

// Todo OK
echo json_encode([
    'success' => true,
    'lat'     => $lat,
    'lng'     => $lng,
]);