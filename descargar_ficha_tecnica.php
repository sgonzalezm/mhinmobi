<?php
// ============================================
// ficha_tecnica.php  (v5 — portada + galería limpia)
// Uso: ficha_tecnica.php?id=123
// ============================================
session_start();
date_default_timezone_set('America/Mexico_City');

require_once 'includes/conexion.php';
require_once 'includes/auth.php';

if (!estaLogueado()) { header('Location: login.php'); exit; }
$usuario = obtenerUsuarioActual($conn);
if (!$usuario) { cerrarSesion(); header('Location: login.php'); exit; }

$property_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($property_id <= 0) { http_response_code(400); die('ID inválido.'); }

// ---- FPDF ----
$rutas_fpdf = [
    __DIR__ . '/includes/fpdf/fpdf.php',
    __DIR__ . '/fpdf/fpdf.php',
    __DIR__ . '/../includes/fpdf/fpdf.php',
];
$fpdf_path = null;
foreach ($rutas_fpdf as $r) { if (file_exists($r)) { $fpdf_path = $r; break; } }
if (!$fpdf_path) { http_response_code(500); die('FPDF no encontrado.'); }
require_once $fpdf_path;
if (!class_exists('FPDF')) { http_response_code(500); die('Clase FPDF no disponible.'); }

// ============================================
// HELPERS
// ============================================
function ruta_fisica_imagen(string $filePath): ?string {
    $filePath = trim($filePath);
    if ($filePath === '') return null;
    $candidatas = [];
    if (strpos($filePath, 'uploads/') === 0) {
        $candidatas[] = __DIR__ . '/' . $filePath;
    } else {
        $candidatas[] = __DIR__ . '/uploads/propiedades/' . $filePath;
        $candidatas[] = __DIR__ . '/uploads/' . $filePath;
    }
    foreach ($candidatas as $r) if (file_exists($r)) return $r;
    return null;
}

function recortar_a_ratio(string $rutaOriginal, float $ratio, int $anchoSalida = 1200): ?string {
    $info = @getimagesize($rutaOriginal);
    if (!$info) return null;

    $mime = $info['mime'] ?? '';
    $src = match ($mime) {
        'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($rutaOriginal),
        'image/png'                => @imagecreatefrompng($rutaOriginal),
        'image/gif'                => @imagecreatefromgif($rutaOriginal),
        'image/webp'               => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($rutaOriginal) : null,
        default                    => null,
    };
    if (!$src) return null;

    $w = imagesx($src);
    $h = imagesy($src);
    if ($w <= 0 || $h <= 0) { imagedestroy($src); return null; }

    $ratioActual = $w / $h;

    if ($ratioActual > $ratio) {
        $nuevoH = $h;
        $nuevoW = (int)($h * $ratio);
        $srcX = (int)(($w - $nuevoW) / 2);
        $srcY = 0;
    } else {
        $nuevoW = $w;
        $nuevoH = (int)($w / $ratio);
        $srcX = 0;
        $srcY = (int)(($h - $nuevoH) / 2);
    }

    $altoSalida = (int)($anchoSalida / $ratio);
    $dst = imagecreatetruecolor($anchoSalida, $altoSalida);
    $blanco = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $anchoSalida, $altoSalida, $blanco);

    imagecopyresampled(
        $dst, $src,
        0, 0, $srcX, $srcY,
        $anchoSalida, $altoSalida,
        $nuevoW, $nuevoH
    );

    $tmp = sys_get_temp_dir() . '/ficha_crop_' . uniqid() . '.jpg';
    imagejpeg($dst, $tmp, 90);
    imagedestroy($src);
    imagedestroy($dst);

    return file_exists($tmp) ? $tmp : null;
}

function fmt_precio($p): string {
    if ($p === null || $p === '' || (float)$p === 0.0) return 'Precio a consultar';
    return '$' . number_format((float)$p, 0, ',', '.');
}

function t(?string $s): string {
    $s = (string)$s;
    $c = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
    return $c !== false ? $c : $s;
}

// ============================================
// CONSULTAS
// ============================================
try {
    $stmt = $conn->prepare("
        SELECT 
            p.id, p.owner_id, p.title, p.operation_type, p.municipio, p.colonia,
            p.domicilio, p.status, p.created_at, p.updated_at,
            DATEDIFF(NOW(), p.created_at) as days_active,
            pd.square_meters, pd.bedrooms, pd.bathrooms, pd.parking_spots,
            pf.asking_price as price, pf.min_acceptable_price,
            pf.potential_profit_margin, pf.commission_percentage,
            s.name as owner_name, s.email as owner_email
        FROM properties p
        LEFT JOIN property_details    pd ON p.id = pd.property_id
        LEFT JOIN property_financials pf ON p.id = pf.property_id
        LEFT JOIN users               s  ON p.owner_id = s.id
        WHERE p.id = ?
        LIMIT 1
    ");
    $stmt->execute([$property_id]);
    $propiedad = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500); die('Error: ' . htmlspecialchars($e->getMessage()));
}
if (!$propiedad) { http_response_code(404); die('Propiedad no encontrada.'); }

$telefono_propietario = '';
if (!empty($propiedad['owner_id'])) {
    try {
        $st = $conn->prepare("SELECT telefono FROM users WHERE id = ?");
        $st->execute([$propiedad['owner_id']]);
        $telefono_propietario = (string)($st->fetchColumn() ?: '');
    } catch (PDOException $e) {}
}

$imagenes_propiedad = [];
try {
    $stmtImg = $conn->prepare("
        SELECT id, file_path, is_primary, sort_order
        FROM property_media
        WHERE property_id = ?
        ORDER BY is_primary DESC, sort_order ASC, id ASC
    ");
    $stmtImg->execute([$property_id]);
    $imagenes_propiedad = $stmtImg->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// ============================================
// CLASE PDF
// ============================================
class FichaPDF extends FPDF
{
    public int    $propId     = 0;
    public string $propTitulo = '';

    public array $azul      = [29, 78, 216];
    public array $azulClaro = [37, 99, 235];
    public array $azulOsc   = [15, 23, 42];
    public array $grisBg    = [241, 245, 249];
    public array $grisBorde = [226, 232, 240];
    public array $texto     = [15, 23, 42];
    public array $label     = [100, 116, 139];
    public array $verde     = [22, 163, 74];
    public array $ambar     = [245, 158, 11];

    public float $margen = 12;
    public float $anchoUtil = 186;

    function Header(): void
    {
        $this->SetFillColor(...$this->azul);
        $this->Rect(0, 0, 210, 16, 'F');
        $this->SetFillColor(...$this->azulClaro);
        $this->Rect(0, 0, 105, 16, 'F');

        $this->SetTextColor(255);
        $this->SetFont('Arial', 'B', 13);
        $this->SetXY(12, 3.5);
        $this->Cell(120, 9, t('FICHA TÉCNICA'), 0, 0, 'L');

        $this->SetFont('Arial', '', 8.5);
        $this->SetXY(120, 5);
        $this->Cell(78, 6, 'ID: #' . $this->propId, 0, 0, 'R');

        $this->SetY(22);
        $this->SetTextColor(...$this->texto);
    }

    function Footer(): void
    {
        $this->SetDrawColor(...$this->grisBorde);
        $this->Line($this->margen, 285, 210 - $this->margen, 285);

        $this->SetY(-12);
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(150);
        $this->Cell(
            0, 7,
            t('Inmobiliaria MH  ·  ' . date('d/m/Y') . '  ·  Documento informativo'),
            0, 0, 'C'
        );

        $this->SetXY(175, -12);
        $this->Cell(25, 7, 'Pag. ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }

    function ImagenExacta(string $ruta, float $x, float $y, float $w, float $h): void
    {
        try { $this->Image($ruta, $x, $y, $w, $h, 'JPG'); }
        catch (Throwable $e) {}
    }

    function Badge(string $txt, array $color, float $x, float $y): float
    {
        $this->SetFont('Arial', 'B', 8.5);
        $texto = '  ' . $txt . '  ';
        $ancho = $this->GetStringWidth(t($texto)) + 2;
        $this->SetFillColor(...$color);
        $this->SetTextColor(255);
        $this->SetXY($x, $y);
        $this->Cell($ancho, 6, t($texto), 0, 0, 'C', true);
        return $ancho;
    }
}

// ============================================
// PREPARAR IMÁGENES CON GD
// ============================================
$fotos_hero  = null;
$fotos_grid  = [];
$temporales  = [];

foreach ($imagenes_propiedad as $idx => $img) {
    $ruta = ruta_fisica_imagen($img['file_path']);
    if (!$ruta) continue;

    if ($idx === 0) {
        $crop = recortar_a_ratio($ruta, 16/9, 1600);
        if ($crop) { $fotos_hero = $crop; $temporales[] = $crop; }
    } else {
        $crop = recortar_a_ratio($ruta, 4/3, 1000);
        if ($crop) { $fotos_grid[] = $crop; $temporales[] = $crop; }
    }
}

// ============================================
// GENERAR PDF
// ============================================
$pdf = new FichaPDF('P', 'mm', 'A4');
$pdf->propId = (int)$propiedad['id'];
$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(false);
$pdf->SetMargins(12, 10, 12);

$M = 12;
$W = 186;

// ============================================================
// PÁGINA 1 — PORTADA
// ============================================================
$pdf->AddPage();

$yCursor = 22;

// --- FOTO HERO 16:9 ---
if ($fotos_hero) {
    $heroW = $W;
    $heroH = $heroW / (16/9); // ~104mm

    $pdf->SetDrawColor(...$pdf->grisBorde);
    $pdf->Rect($M, $yCursor, $heroW, $heroH, 'D');
    $pdf->ImagenExacta($fotos_hero, $M, $yCursor, $heroW, $heroH);

    $pdf->Badge('VISTA PRINCIPAL', $pdf->azul, $M + 4, $yCursor + 4);

    $yCursor += $heroH + 6;
} else {
    $heroH = 60;
    $pdf->SetFillColor(...$pdf->grisBg);
    $pdf->Rect($M, $yCursor, $W, $heroH, 'F');
    $pdf->SetFont('Arial', 'I', 11);
    $pdf->SetTextColor(...$pdf->label);
    $pdf->SetXY($M, $yCursor + 27);
    $pdf->Cell($W, 6, t('Sin imágenes disponibles'), 0, 0, 'C');
    $yCursor += $heroH + 6;
}

// --- TÍTULO ---
$pdf->SetXY($M, $yCursor);
$pdf->SetFont('Arial', 'B', 20);
$pdf->SetTextColor(...$pdf->texto);
$pdf->MultiCell($W, 9, t($propiedad['title']), 0, 'L');
$yCursor = $pdf->GetY() + 2;

// --- BADGES ---
$pdf->SetY($yCursor);
$xBadge = $M;
$xBadge += $pdf->Badge(ucfirst((string)($propiedad['operation_type'] ?? 'General')), $pdf->azul, $xBadge, $yCursor) + 3;

$status = strtolower((string)($propiedad['status'] ?? ''));
$colorStatus = match ($status) {
    'activo'    => $pdf->verde,
    'vendido'   => [30, 64, 175],
    'pendiente' => $pdf->ambar,
    'suspendido'=> [220, 38, 38],
    default     => [100, 116, 139],
};
$xBadge += $pdf->Badge(ucfirst($status ?: '—'), $colorStatus, $xBadge, $yCursor) + 3;

$ubicacion = trim(($propiedad['colonia'] ?? '') . ', ' . ($propiedad['municipio'] ?? ''), ' ,');
if ($ubicacion !== '') {
    $pdf->Badge($ubicacion, [71, 85, 105], $xBadge, $yCursor);
}

$yCursor += 10;

// --- PRECIO DESTACADO ---
$pdf->SetXY($M, $yCursor);
$pdf->SetFont('Arial', 'B', 26);
$pdf->SetTextColor(...$pdf->azul);
$pdf->Cell($W, 14, t(fmt_precio($propiedad['price'])), 0, 1, 'L');
$yCursor = $pdf->GetY();

if (!empty($propiedad['min_acceptable_price']) && (float)$propiedad['min_acceptable_price'] > 0) {
    $pdf->SetX($M);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(...$pdf->label);
    $pdf->Cell($W, 5, t('Precio mínimo aceptable: ' . fmt_precio($propiedad['min_acceptable_price'])), 0, 1, 'L');
    $yCursor = $pdf->GetY();
}

$yCursor += 4;

// --- 4 DATOS CLAVE ---
$keyW = ($W - 9) / 4;
$keyH = 22;

$keys = [
    ['Superficie', ($propiedad['square_meters'] !== null && $propiedad['square_meters'] !== '')
        ? number_format((float)$propiedad['square_meters'], 0, ',', '.') . ' m²' : '—'],
    ['Recámaras',    (string)($propiedad['bedrooms'] ?? '—')],
    ['Baños',        (string)($propiedad['bathrooms'] ?? '—')],
    ['Estacionam.',  (string)($propiedad['parking_spots'] ?? '—')],
];

foreach ($keys as $i => $k) {
    $x = $M + $i * ($keyW + 3);

    $pdf->SetFillColor(...$pdf->grisBg);
    $pdf->SetDrawColor(...$pdf->grisBorde);
    $pdf->Rect($x, $yCursor, $keyW, $keyH, 'DF');

    $pdf->SetXY($x, $yCursor + 3);
    $pdf->SetFont('Arial', '', 7.5);
    $pdf->SetTextColor(...$pdf->label);
    $pdf->Cell($keyW, 4, t($k[0]), 0, 2, 'C');

    $pdf->SetX($x);
    $pdf->SetFont('Arial', 'B', 13);
    $pdf->SetTextColor(...$pdf->texto);
    $pdf->Cell($keyW, 9, t($k[1]), 0, 2, 'C');
}

// ============================================================
// PÁGINAS DE GALERÍA — solo fotos, sin datos
// ============================================================
if (!empty($fotos_grid)) {
    $porPagina = 4; // 2×2
    $chunks = array_chunk($fotos_grid, $porPagina);
    $totalFotos = count($imagenes_propiedad);
    $contadorFoto = 2; // la #1 está en el hero

    foreach ($chunks as $chunkIdx => $chunk) {
        $pdf->AddPage();

        // Título mínimo de sección
        $pdf->SetXY($M, 22);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetTextColor(...$pdf->label);
        $pdf->Cell($W, 6, t('Galería fotográfica'), 0, 1, 'L');

        $pdf->SetX($M);
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(160);
        $pdf->Cell($W, 4, t('Fotos ' . $contadorFoto . ' a ' . ($contadorFoto + count($chunk) - 1) . ' de ' . $totalFotos), 0, 1, 'L');
        $pdf->Ln(3);

        // Grid 2×2 sin marco general
        $gap = 5;
        $colW = ($W - $gap) / 2;
        $colH = $colW / (4/3);
        $startY = $pdf->GetY();

        foreach ($chunk as $i => $rutaFoto) {
            $col = $i % 2;
            $row = intdiv($i, 2);
            $x = $M + $col * ($colW + $gap);
            $y = $startY + $row * ($colH + $gap);

            // Sombra/borde fino
            $pdf->SetDrawColor(...$pdf->grisBorde);
            $pdf->Rect($x, $y, $colW, $colH, 'D');

            $pdf->ImagenExacta($rutaFoto, $x, $y, $colW, $colH);

            // Número de foto discreto en la esquina
            $pdf->SetFillColor(15, 23, 42);
            $pdf->SetTextColor(255);
            $pdf->SetFont('Arial', 'B', 7);
            $pdf->SetXY($x + $colW - 12, $y + 2);
            $pdf->Cell(10, 4.5, '#' . $contadorFoto, 0, 0, 'C', true);

            $contadorFoto++;
        }
    }
}

// ============================================================
// ÚLTIMA PÁGINA — FICHA RESUMEN
// ============================================================
$pdf->AddPage();

$pdf->SetXY($M, 22);
$pdf->SetFont('Arial', 'B', 16);
$pdf->SetTextColor(...$pdf->texto);
$pdf->Cell($W, 8, t('Ficha Resumen'), 0, 1, 'L');
$pdf->Ln(2);

$bloqueW = ($W - 6) / 2;
$bloqueH = 58;
$gapX = 6;
$gapY = 6;
$startY = $pdf->GetY();

$bloques = [
    [
        'titulo' => 'UBICACIÓN',
        'filas'  => [
            ['Municipio', (string)($propiedad['municipio'] ?? '—')],
            ['Colonia',   (string)($propiedad['colonia'] ?? '—')],
            ['Domicilio', (string)($propiedad['domicilio'] ?? '—')],
        ],
    ],
    [
        'titulo' => 'CARACTERÍSTICAS',
        'filas'  => [
            ['Superficie', ($propiedad['square_meters'] !== null && $propiedad['square_meters'] !== '')
                ? number_format((float)$propiedad['square_meters'], 0, ',', '.') . ' m²' : '—'],
            ['Recámaras',        (string)($propiedad['bedrooms'] ?? '—')],
            ['Baños',            (string)($propiedad['bathrooms'] ?? '—')],
            ['Estacionamientos', (string)($propiedad['parking_spots'] ?? '—')],
        ],
    ],
    [
        'titulo' => 'INFORMACIÓN FINANCIERA',
        'filas'  => array_values(array_filter([
            ['Precio de venta', fmt_precio($propiedad['price'])],
            ['Precio mínimo',   fmt_precio($propiedad['min_acceptable_price'])],
            ($propiedad['potential_profit_margin'] !== null && $propiedad['potential_profit_margin'] !== '')
                ? ['Margen potencial', number_format((float)$propiedad['potential_profit_margin'], 1) . ' %'] : null,
        ])),
    ],
    [
        'titulo' => 'CONTACTO',
        'filas'  => [
            ['Propietario', (string)($propiedad['owner_name'] ?? '—')],
            ['Email',       (string)($propiedad['owner_email'] ?? '—')],
            ['Teléfono',    $telefono_propietario !== '' ? $telefono_propietario : '—'],
        ],
    ],
];

foreach ($bloques as $i => $bloque) {
    $col = $i % 2;
    $row = intdiv($i, 2);
    $x = $M + $col * ($bloqueW + $gapX);
    $y = $startY + $row * ($bloqueH + $gapY);

    $pdf->SetFillColor(...$pdf->azulOsc);
    $pdf->SetTextColor(255);
    $pdf->SetFont('Arial', 'B', 9.5);
    $pdf->SetXY($x, $y);
    $pdf->Cell($bloqueW, 7, t('  ' . $bloque['titulo']), 0, 1, 'L', true);

    $yFila = $y + 9;
    foreach ($bloque['filas'] as $fila) {
        $pdf->SetXY($x + 4, $yFila);
        $pdf->SetFont('Arial', '', 7.5);
        $pdf->SetTextColor(...$pdf->label);
        $pdf->Cell($bloqueW - 8, 3.8, t($fila[0]), 0, 2);
        $pdf->SetX($x + 4);
        $pdf->SetFont('Arial', 'B', 9.5);
        $pdf->SetTextColor(...$pdf->texto);
        $pdf->Cell($bloqueW - 8, 5, t($fila[1]), 0, 2);
        $yFila += 11;
    }

    $pdf->SetDrawColor(...$pdf->grisBorde);
    $pdf->Rect($x, $y, $bloqueW, $bloqueH);
}

$pdf->SetY($startY + 2 * ($bloqueH + $gapY) + 6);

$pdf->SetFont('Arial', 'I', 7);
$pdf->SetTextColor(150);
$pdf->MultiCell(
    $W, 3.5,
    t(
        'Esta ficha técnica es un resumen informativo generado automáticamente. ' .
        'Los datos, precios y superficies están sujetos a verificación y cambios sin previo aviso. ' .
        'Las imágenes son de referencia. Para información oficial contacta a tu agente inmobiliario.'
    ),
    0, 'L'
);

// ============================================
// LIMPIAR Y SALIR
// ============================================
foreach ($temporales as $tmp) {
    if ($tmp && file_exists($tmp)) @unlink($tmp);
}

if (ob_get_level()) { while (ob_get_level()) ob_end_clean(); }

$nombreArchivo = 'ficha_tecnica_' . $property_id . '_' . date('Ymd_His') . '.pdf';
$pdf->Output('I', $nombreArchivo);
exit;