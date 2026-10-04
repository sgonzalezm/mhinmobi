<?php
// ============================================
// DEMO: Efecto CamScanner con Imagick (compatible v3.8+)
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!extension_loaded('imagick')) {
    die('❌ La extensión Imagick NO está instalada en este servidor.');
}

@ini_set('memory_limit', '256M');

$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

$mensaje    = '';
$errorReal  = '';
$imagenOrig = '';
$imagenProc = '';
$debugInfo  = [];

// Info del entorno
$debugInfo[] = 'Imagick version: ' . phpversion('imagick');
$debugInfo[] = 'ImageMagick version: ' . (class_exists('Imagick') ? Imagick::getVersion()['versionString'] : 'N/A');
$debugInfo[] = 'Formats JPEG soportado: ' . (class_exists('Imagick') && in_array('JPEG', (new Imagick())->queryFormats('JPEG')) ? 'SÍ' : 'NO');
$debugInfo[] = 'Formats PNG soportado: '  . (class_exists('Imagick') && in_array('PNG',  (new Imagick())->queryFormats('PNG'))  ? 'SÍ' : 'NO');
$debugInfo[] = 'Carpeta uploads escribible: ' . (is_writable($uploadDir) ? 'SÍ' : 'NO');
$debugInfo[] = 'statisticImage existe: ' . (method_exists('Imagick', 'statisticImage') ? 'SÍ' : 'NO');

// ============================================================
// PROCESAR SUBIDA
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['foto'])) {

    $file = $_FILES['foto'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $mensaje = '❌ Error al subir el archivo (código ' . $file['error'] . ').';
    } else {
        $mime = mime_content_type($file['tmp_name']);
        $permitidos = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        if (!in_array($mime, $permitidos, true)) {
            $mensaje = '❌ Formato no permitido (' . $mime . '). Usa JPG, PNG, GIF o WEBP.';
        } else {
            $ts         = time();
            $nombreOrig = "orig_{$ts}.jpg";
            $nombreProc = "proc_{$ts}.jpg";
            $rutaOrig   = $uploadDir . $nombreOrig;
            $rutaProc   = $uploadDir . $nombreProc;

            try {
                // Guardar original normalizado a JPG
                $img = new Imagick($file['tmp_name']);
                $img->setImageFormat('jpeg');
                $img->setImageCompressionQuality(90);
                $img->writeImage($rutaOrig);
                $img->destroy();

                // Aplicar efecto escáner
                $resultado = aplicarEfectoEscaner($rutaOrig, $rutaProc);

                if ($resultado['ok']) {
                    $imagenOrig = 'uploads/' . $nombreOrig;
                    $imagenProc = 'uploads/' . $nombreProc;
                    $mensaje    = '✅ Imagen procesada correctamente.';
                } else {
                    $mensaje   = '❌ Falló el procesamiento con Imagick.';
                    $errorReal = $resultado['error'];
                }

            } catch (Throwable $e) {
                $mensaje   = '❌ Error general al procesar.';
                $errorReal = $e->getMessage();
            }
        }
    }
}

// ============================================================
// FUNCIÓN: Efecto escáner moderado (compatible con Imagick 3.8+)
// ============================================================
function aplicarEfectoEscaner(string $origen, string $destino): array
{
    try {
        $img = new Imagick($origen);

        // 1) Auto-orientar según EXIF
        $img->autoOrient();

        // 2) Redimensionar si es muy grande
        if ($img->getImageWidth() > 1800) {
            $img->resizeImage(1800, 0, Imagick::FILTER_LANCZOS, 1);
        }

        // 3) Escala de grises
        $img->setImageColorspace(Imagick::COLORSPACE_GRAY);

        // 4) Filtro de mediana — compatible con Imagick 3.8+
        if (method_exists($img, 'statisticImage')) {
            // Imagick 3.8+: usar statisticImage con STATISTIC_MEDIAN
            $img->statisticImage(Imagick::STATISTIC_MEDIAN, 3, 3);
        } elseif (method_exists($img, 'medianFilterImage')) {
            // Versiones antiguas
            $img->medianFilterImage(3);
        }
        // Si no existe ninguno, simplemente lo saltamos

        // 5) Ajuste de niveles SUAVE
        $img->levelImage(0, 1.2, Imagick::getQuantum());

        // 6) Contraste moderado (una sola pasada)
        $img->contrastImage(true);

        // 7) Nitidez ligera
        $img->unsharpMaskImage(0, 0.5, 0.8, 0.02);

        // 8) Guardar JPEG
        $img->setImageFormat('jpeg');
        $img->setImageCompressionQuality(90);
        $img->writeImage($destino);
        $img->destroy();

        if (!file_exists($destino) || filesize($destino) === 0) {
            return ['ok' => false, 'error' => 'El archivo destino no se creó o está vacío.'];
        }

        return ['ok' => true, 'error' => ''];

    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Demo Imagick · Efecto Escáner</title>
<style>
    body { font-family:'Segoe UI',sans-serif; background:#f5f7fa; margin:0; padding:30px; color:#2c3e50; }
    .container { max-width:900px; margin:0 auto; background:#fff; padding:30px; border-radius:14px; box-shadow:0 10px 40px rgba(0,0,0,.08); }
    h1 { margin-top:0; font-size:22px; }
    .msg { padding:12px 16px; border-radius:8px; margin-bottom:20px; font-size:14px; background:#eef2ff; border-left:4px solid #6366f1; }
    .msg.ok  { background:#f0fdf4; border-left-color:#27ae60; }
    .msg.err { background:#fef2f2; border-left-color:#e74c3c; }
    .error-box { background:#fff1f2; border:1px solid #fecaca; color:#991b1b; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-size:13px; font-family:monospace; word-break:break-word; }
    .debug { background:#f8fafc; border:1px solid #e2e8f0; padding:14px 18px; border-radius:8px; font-size:12px; font-family:monospace; color:#475569; margin-bottom:20px; }
    .debug strong { color:#1e293b; }
    form { border:2px dashed #cbd5e1; padding:24px; border-radius:12px; text-align:center; margin-bottom:30px; }
    input[type=file] { margin:12px 0; }
    button { background:#3498db; color:#fff; border:none; padding:10px 22px; border-radius:8px; font-size:14px; cursor:pointer; }
    button:hover { background:#2980b9; }
    .grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
    .grid figure { margin:0; text-align:center; }
    .grid figcaption { font-size:13px; font-weight:600; color:#64748b; margin-bottom:8px; }
    .grid img { max-width:100%; border-radius:10px; border:1px solid #e2e8f0; }
    .info { font-size:13px; color:#64748b; margin-top:20px; line-height:1.6; }
    @media (max-width:640px) { .grid { grid-template-columns:1fr; } }
</style>
</head>
<body>

<div class="container">
    <h1>📷 Demo: Efecto Escáner con Imagick</h1>

    <div class="debug">
        <strong>Diagnóstico del entorno:</strong><br>
        <?= implode('<br>', array_map('htmlspecialchars', $debugInfo)) ?>
    </div>

    <?php if ($mensaje): ?>
        <div class="msg <?= $errorReal ? 'err' : 'ok' ?>"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <?php if ($errorReal): ?>
        <div class="error-box">
            <strong>🔍 Error real de Imagick:</strong><br>
            <?= htmlspecialchars($errorReal) ?>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <input type="file" name="foto" accept="image/*" required>
        <br>
        <button type="submit">Procesar imagen</button>
    </form>

    <?php if ($imagenOrig && $imagenProc): ?>
        <div class="grid">
            <figure>
                <figcaption>Original</figcaption>
                <img src="<?= htmlspecialchars($imagenOrig) ?>?t=<?= time() ?>" alt="Original">
            </figure>
            <figure>
                <figcaption>Procesada (efecto escáner)</figcaption>
                <img src="<?= htmlspecialchars($imagenProc) ?>?t=<?= time() ?>" alt="Procesada">
            </figure>
        </div>
    <?php endif; ?>

    <div class="info">
        <strong>Notas:</strong><br>
        · Requiere la extensión <code>php-imagick</code> instalada.<br>
        · Compatible con Imagick 3.8+ (usa <code>statisticImage()</code> en vez del antiguo <code>medianFilterImage()</code>).<br>
        · Si el resultado sale muy claro (pierde texto fino), cambia el tamaño del filtro de mediana de <code>3, 3</code> a <code>2, 2</code>.<br>
        · Si sale muy gris (no aclara el fondo), cambia <code>levelImage(0, 1.2, ...)</code> a <code>levelImage(Imagick::getQuantum()*0.05, 1.35, Imagick::getQuantum())</code>.
    </div>
</div>

</body>
</html>