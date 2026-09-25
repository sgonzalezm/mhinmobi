<?php
// ============================================
// upload_documentos.php  (v4 - completo)
// Grid + subida AJAX + conversión PDF + borrado
// ============================================
ob_start();

session_start();
require_once 'includes/conexion.php';

if (!$conn) {
    die("Error de conexión a la base de datos");
}

// ============================================================
// CONFIGURACIÓN
// ============================================================
const MAX_FILE_SIZE      = 10 * 1024 * 1024; // 10MB
const MAX_FILES_PER_CARD = 30;
const ALLOWED_EXTENSIONS = ['pdf','jpg','jpeg','png','doc','docx','xls','xlsx'];
const ALLOWED_IMAGE_EXT  = ['jpg','jpeg','png','gif'];
const UPLOAD_BASE_DIR    = 'uploads/clientes/';

// ============================================================
// HELPERS
// ============================================================
function render_error_page(string $icon, string $title, string $msg, string $color = '#e74c3c'): void {
    if (ob_get_level()) ob_end_clean();
    echo <<<HTML
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$title}</title>
<style>
body{font-family:'Segoe UI',Tahoma,sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;background:#f5f7fa;padding:20px;}
.box{background:#fff;padding:40px;border-radius:14px;text-align:center;max-width:480px;box-shadow:0 10px 40px rgba(0,0,0,.08);}
.icon{font-size:60px;margin-bottom:20px;}
h2{color:{$color};margin:0 0 10px;}
p{color:#666;margin:0;line-height:1.5;}
small{color:#999;display:block;margin-top:12px;}
</style></head><body>
<div class="box"><div class="icon">{$icon}</div><h2>{$title}</h2><p>{$msg}</p></div>
</body></html>
HTML;
    exit;
}

function safe_filename(string $name): string {
    $name = pathinfo($name, PATHINFO_FILENAME);
    $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
    return substr($name, 0, 80) ?: 'archivo';
}

function real_mime(string $path): string {
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $m = finfo_file($fi, $path);
        finfo_close($fi);
        return $m ?: 'application/octet-stream';
    }
    return 'application/octet-stream';
}

function mime_is_allowed(string $mime): bool {
    $allowed = [
        'application/pdf',
        'image/jpeg','image/png','image/gif',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/octet-stream'
    ];
    return in_array($mime, $allowed, true);
}

/**
 * Convierte un array de imágenes a un único PDF usando FPDF local.
 */
function images_to_pdf(array $imagePaths, string $outPath): bool {
    $rutas = [
        __DIR__ . '/includes/fpdf/fpdf.php',
        __DIR__ . '/fpdf/fpdf.php',
        __DIR__ . '/../includes/fpdf/fpdf.php',
    ];
    $fpdf_path = null;
    foreach ($rutas as $r) {
        if (file_exists($r)) { $fpdf_path = $r; break; }
    }
    if (!$fpdf_path) {
        return false;
    }
    require_once $fpdf_path;

    if (!class_exists('FPDF')) {
        return false;
    }

    try {
        $pdf = new FPDF('P', 'mm', 'Letter');
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);

        $pageW_P = 216; $pageH_P = 279;
        $pageW_L = 279; $pageH_L = 216;

        $insertadas = 0;
        foreach ($imagePaths as $path) {
            $info = @getimagesize($path);
            if (!$info) {
                throw new Exception("images_to_pdf: imagen no legible: $path");
                continue;
            }
            [$w, $h] = $info;

            // ⬇️ NUEVO: detectar el tipo MIME real para pasárselo a FPDF
            $mime = $info['mime'] ?? '';
            $tipo_fpdf = null;
            switch ($mime) {
                case 'image/jpeg':
                case 'image/jpg':
                    $tipo_fpdf = 'JPG';
                    break;
                case 'image/png':
                    $tipo_fpdf = 'PNG';
                    break;
                case 'image/gif':
                    $tipo_fpdf = 'GIF';
                    break;
                default:
                    continue 2; // saltar esta imagen
            }

            $orientacion = $w > $h ? 'L' : 'P';
            $pageW = $orientacion === 'L' ? $pageW_L : $pageW_P;
            $pageH = $orientacion === 'L' ? $pageH_L : $pageH_P;

            $pdf->AddPage($orientacion, 'Letter');

            $maxW  = $pageW - 20;
            $maxH  = $pageH - 20;
            $ratio = min($maxW / $w, $maxH / $h);
            $newW  = $w * $ratio;
            $newH  = $h * $ratio;
            $x = ($pageW - $newW) / 2;
            $y = ($pageH - $newH) / 2;

            try {
                // ⬇️ AHORA le pasamos el tipo como 3er argumento
                $pdf->Image($path, $x, $y, $newW, $newH, $tipo_fpdf);
                $insertadas++;
            } catch (Throwable $e) {
                error_log("images_to_pdf: error insertando $path: " . $e->getMessage());
            }
        }

        if ($insertadas === 0) return false;

        $pdf->Output('F', $outPath);
        return file_exists($outPath) && filesize($outPath) > 0;

    } catch (Throwable $e) {
        return false;
    }
}

function cargar_requeridos(PDO $conn, int $property_id): array {
    $stmt = $conn->prepare("
        SELECT dt.*, prd.is_required, prd.notes
        FROM property_required_documents prd
        JOIN document_types dt ON dt.id = prd.document_type_id
        WHERE prd.property_id = ?
        ORDER BY dt.sort_order, dt.label
    ");
    $stmt->execute([$property_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        $stmt = $conn->prepare("SELECT *, 1 AS is_required, NULL AS notes 
                                FROM document_types 
                                ORDER BY sort_order, label");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $conn->prepare("
        SELECT document_type, COUNT(*) AS n
        FROM client_uploaded_documents
        WHERE property_id = ? AND status != 'rejected'
        GROUP BY document_type
    ");
    $stmt->execute([$property_id]);
    $counts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $counts[$r['document_type']] = (int)$r['n'];
    }

    foreach ($rows as &$r) {
        $n = $counts[$r['code']] ?? 0;
        $r['uploads_count'] = $n;
        if ($n === 0) {
            $r['estado'] = 'pendiente';
        } elseif (!empty($r['requires_multiple'])) {
            $r['estado'] = 'parcial';
        } else {
            $r['estado'] = 'cargado';
        }
    }
    unset($r);
    return $rows;
}

// ============================================================
// MODO ADMIN vs TOKEN
// ============================================================
$modo_admin           = false;
$admin_property_id    = 0;
$admin_property_title = '';

if (isset($_GET['id']) && is_numeric($_GET['id']) && !isset($_GET['token'])) {
    if (!isset($_SESSION['usuario_id'])) {
        header('Location: login.php');
        exit;
    }
    $admin_property_id = intval($_GET['id']);
    $modo_admin = true;

    try {
        $stmt = $conn->prepare("SELECT id, title FROM properties WHERE id = ?");
        $stmt->execute([$admin_property_id]);
        $prop = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$prop) {
            render_error_page('🔍', 'Propiedad no encontrada',
                'La propiedad que buscas no existe o fue eliminada.', '#e74c3c');
        }
        $admin_property_title = $prop['title'];
    } catch (PDOException $e) {
        render_error_page('❌', 'Error', 'No se pudo cargar la propiedad.', '#e74c3c');
    }
}

$token      = $_GET['token'] ?? '';
$token_data = null;
$usar_token_real = false;

if (!$modo_admin) {
    if (empty($token)) {
        render_error_page('🔒', 'Token no válido',
            'No se ha proporcionado un enlace válido.<br><small>Contacta a tu agente inmobiliario.</small>',
            '#e74c3c');
    }

    try {
        $stmt = $conn->prepare("
            SELECT t.*, p.title AS property_title, p.id AS property_id
            FROM document_upload_tokens t
            JOIN properties p ON t.property_id = p.id
            WHERE t.token = ?
              AND t.is_used = 0
              AND t.expires_at > NOW()
        ");
        $stmt->execute([$token]);
        $token_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$token_data) {
            $stmt2 = $conn->prepare("
                SELECT t.*, p.title AS property_title
                FROM document_upload_tokens t
                JOIN properties p ON t.property_id = p.id
                WHERE t.token = ?
            ");
            $stmt2->execute([$token]);
            $check = $stmt2->fetch(PDO::FETCH_ASSOC);

            if ($check) {
                $msg = "El enlace ya no es válido.";
                if ($check['is_used'] == 1) {
                    $msg = "Este enlace ya fue utilizado.";
                    if ($check['upload_count'] >= $check['max_uploads']) {
                        $msg .= " Se alcanzó el límite de {$check['max_uploads']} archivos.";
                    }
                } elseif (strtotime($check['expires_at']) < time()) {
                    $msg .= " Expiró el " . date('d/m/Y H:i', strtotime($check['expires_at']));
                }
                render_error_page('⏰', 'Enlace No Válido',
                    $msg . '<br><small>Solicita un nuevo enlace.</small>', '#e74c3c');
            } else {
                render_error_page('🔍', 'Token Inválido',
                    'El enlace proporcionado no es válido.', '#e74c3c');
            }
        }

        if ($token_data['upload_count'] >= $token_data['max_uploads']) {
            render_error_page('📁', 'Límite Alcanzado',
                'Has subido el máximo de ' . intval($token_data['max_uploads']) . ' documentos permitidos.',
                '#f39c12');
        }

        $usar_token_real = true;

    } catch (PDOException $e) {
        render_error_page('❌', 'Error del Sistema',
            'No se pudo validar el enlace.', '#e74c3c');
    }
} else {
    $token_data = [
        'id'             => 0,
        'property_id'    => $admin_property_id,
        'property_title' => $admin_property_title,
        'client_name'    => 'Administrador',
        'client_email'   => $_SESSION['usuario_email'] ?? 'admin@inmobiliariamh.com',
        'max_uploads'    => 9999,
        'upload_count'   => 0,
        'expires_at'     => date('Y-m-d H:i:s', strtotime('+1 year')),
        'is_used'        => 0,
        'token'          => 'admin_' . $admin_property_id
    ];
}

$property_id = (int)$token_data['property_id'];

// ============================================================
// ENDPOINT AJAX: SUBIR
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json; charset=utf-8');

    $resp = ['ok' => false, 'msg' => 'Solicitud inválida'];

    try {
        $document_type = trim($_POST['document_type'] ?? '');
        $description   = trim($_POST['description'] ?? '');

        if ($document_type === '') {
            throw new Exception('Tipo de documento no especificado.');
        }

        $stmt = $conn->prepare("SELECT * FROM document_types WHERE code = ?");
        $stmt->execute([$document_type]);
        $doc_type = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc_type) throw new Exception('Tipo de documento inválido.');

        if (!isset($_FILES['documento'])) {
            throw new Exception('No llegó ningún archivo al servidor (FILES vacío).');
        }

        $files = $_FILES['documento'];
        if (!is_array($files['name'])) {
            $files = [
                'name'     => [$files['name']],
                'type'     => [$files['type']],
                'tmp_name' => [$files['tmp_name']],
                'error'    => [$files['error']],
                'size'     => [$files['size']],
            ];
        }

        $count = count($files['name']);
        if ($count === 0 || $files['name'][0] === '') {
            throw new Exception('No se recibió ningún archivo.');
        }
        if (empty($doc_type['requires_multiple']) && $count > 1) {
            throw new Exception('Este tipo de documento solo admite 1 archivo.');
        }
        if ($count > MAX_FILES_PER_CARD) {
            throw new Exception('Máximo ' . MAX_FILES_PER_CARD . ' archivos por documento.');
        }

        $directorio = UPLOAD_BASE_DIR . $property_id . '/';
        if (!file_exists($directorio)) {
            if (!@mkdir($directorio, 0755, true)) {
                throw new Exception('No se pudo crear el directorio: ' . $directorio);
            }
        }
        if (!is_writable($directorio)) {
            throw new Exception('Sin permisos de escritura en: ' . $directorio);
        }

        $recibidos     = [];
        $imagenes_tmp  = [];
        $solo_imagenes = true;

        $errs_msg = [
            UPLOAD_ERR_INI_SIZE   => 'Archivo excede el tamaño máximo del servidor.',
            UPLOAD_ERR_FORM_SIZE  => 'Archivo excede el tamaño máximo del formulario.',
            UPLOAD_ERR_PARTIAL    => 'Archivo subido parcialmente.',
            UPLOAD_ERR_NO_FILE    => 'No se seleccionó archivo.',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta carpeta temporal en el servidor.',
            UPLOAD_ERR_CANT_WRITE => 'Error al escribir el archivo en disco.',
            UPLOAD_ERR_EXTENSION  => 'Extensión no permitida por el servidor.',
        ];

        for ($i = 0; $i < $count; $i++) {
            $err_code = $files['error'][$i] ?? -1;
            $orig     = $files['name'][$i] ?? 'desconocido';

            if ($err_code !== UPLOAD_ERR_OK) {
                $msg = $errs_msg[$err_code] ?? "Error de subida (código $err_code).";
                throw new Exception("{$msg} [{$orig}]");
            }

            $tmp  = $files['tmp_name'][$i];
            $size = (int)$files['size'][$i];
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

            if (!is_uploaded_file($tmp)) throw new Exception("Archivo no válido: {$orig}");
            if ($size <= 0)              throw new Exception("Archivo vacío: {$orig}");
            if ($size > MAX_FILE_SIZE)   throw new Exception("'{$orig}' excede " . (MAX_FILE_SIZE / 1024 / 1024) . "MB.");
            if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
                throw new Exception("Tipo no permitido: .{$ext} en '{$orig}'");
            }

            $mime = real_mime($tmp);
            if (!mime_is_allowed($mime)) {
                throw new Exception("Contenido no válido para '{$orig}' (MIME: {$mime}).");
            }

            $is_img = in_array($ext, ALLOWED_IMAGE_EXT, true) || strpos($mime, 'image/') === 0;
            if (!$is_img) $solo_imagenes = false;
            if ($is_img) $imagenes_tmp[] = $tmp;

            $recibidos[] = [
                'orig' => $orig,
                'tmp'  => $tmp,
                'size' => $size,
                'ext'  => $ext,
                'mime' => $mime,
            ];
        }

        $convertir = false;
        if (!empty($doc_type['convert_to_pdf']) && $solo_imagenes && count($recibidos) > 1) {
            $convertir = true;
        }

        $guardados = [];

        if ($convertir) {
            $ts         = time();
            $nombre_pdf = "{$document_type}_{$ts}.pdf";
            $ruta_pdf   = $directorio . $nombre_pdf;

            if (images_to_pdf($imagenes_tmp, $ruta_pdf)) {
                $orig_names = array_map(fn($r) => $r['orig'], $recibidos);
                $stmt = $conn->prepare("
                    INSERT INTO client_uploaded_documents
                    (property_id, token_id, document_type, document_type_id,
                     file_name, file_path, file_size, mime_type, description,
                     client_ip, user_agent, status, converted_from_images, original_files_json)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ");
                $stmt->execute([
                    $property_id,
                    $usar_token_real ? $token_data['id'] : 0,
                    $document_type,
                    $doc_type['id'],
                    count($orig_names) . " imágenes → " . $nombre_pdf,
                    $ruta_pdf,
                    filesize($ruta_pdf),
                    'application/pdf',
                    $description,
                    $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                    $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'pending_review',
                    1,
                    json_encode($orig_names, JSON_UNESCAPED_UNICODE)
                ]);
                $guardados[] = $conn->lastInsertId();
            } else {
                throw new Exception('La conversión a PDF falló. Revisa el log del servidor.');
            }
        }

        if (!$convertir) {
            $stmt = $conn->prepare("
                INSERT INTO client_uploaded_documents
                (property_id, token_id, document_type, document_type_id,
                 file_name, file_path, file_size, mime_type, description,
                 client_ip, user_agent, status, converted_from_images)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");

            foreach ($recibidos as $r) {
                $ts    = time() . '_' . random_int(100, 999);
                $clean = safe_filename($r['orig']);
                $final = "{$ts}_{$clean}.{$r['ext']}";
                $dest  = $directorio . $final;

                if (!move_uploaded_file($r['tmp'], $dest)) {
                    throw new Exception("No se pudo guardar '{$r['orig']}'.");
                }

                $stmt->execute([
                    $property_id,
                    $usar_token_real ? $token_data['id'] : 0,
                    $document_type,
                    $doc_type['id'],
                    $r['orig'],
                    $dest,
                    filesize($dest),
                    $r['mime'],
                    $description,
                    $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                    $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'pending_review',
                    0
                ]);
                $guardados[] = $conn->lastInsertId();
            }
        }

        if ($usar_token_real) {
            $nuevo = $token_data['upload_count'] + count($guardados);
            $stmt = $conn->prepare("
                UPDATE document_upload_tokens 
                SET upload_count = ?, 
                    is_used  = IF(? >= max_uploads, 1, is_used),
                    used_at  = IF(? >= max_uploads, NOW(), used_at)
                WHERE id = ?
            ");
            $stmt->execute([$nuevo, $nuevo, $nuevo, $token_data['id']]);
        }

        $resp = [
            'ok'     => true,
            'msg'    => 'Documento(s) subido(s) correctamente.',
            'count'  => count($guardados),
            'as_pdf' => $convertir,
        ];

    } catch (Throwable $e) {
        $resp['msg'] = $e->getMessage();
    }

    if (ob_get_level()) ob_end_clean();
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// ENDPOINT AJAX: BORRAR
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json; charset=utf-8');

    $resp = ['ok' => false, 'msg' => 'Solicitud inválida'];

    try {
        $doc_id = (int)($_POST['id'] ?? 0);
        if ($doc_id <= 0) throw new Exception('ID inválido.');

        $stmt = $conn->prepare("
            SELECT * FROM client_uploaded_documents
            WHERE id = ? AND property_id = ?
        ");
        $stmt->execute([$doc_id, $property_id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doc) throw new Exception('Documento no encontrado.');

        // Permisos: el cliente no puede borrar aprobados; el admin sí.
        if (!$modo_admin && in_array($doc['status'], ['approved'], true)) {
            throw new Exception('Este documento ya fue aprobado y no puede eliminarse.');
        }

        // Borrar archivo físico
        $ruta_fisica = __DIR__ . '/' . ltrim($doc['file_path'], '/');
        if (file_exists($ruta_fisica)) {
            if (!@unlink($ruta_fisica)) {
                throw new Exception('No se pudo borrar archivo físico.');
            }
        }

        // Borrar registro
        $stmt = $conn->prepare("DELETE FROM client_uploaded_documents WHERE id = ?");
        $stmt->execute([$doc_id]);

        // Decrementar contador del token
        if ($usar_token_real && !empty($doc['token_id'])) {
            $stmt = $conn->prepare("
                UPDATE document_upload_tokens
                SET upload_count = GREATEST(upload_count - 1, 0),
                    is_used = 0
                WHERE id = ?
            ");
            $stmt->execute([$doc['token_id']]);
        }

        $resp = [
            'ok'   => true,
            'msg'  => 'Documento eliminado.',
            'type' => $doc['document_type'],
            'id'   => $doc_id,
        ];

    } catch (Throwable $e) {
        $resp['msg'] = $e->getMessage();
    }

    if (ob_get_level()) ob_end_clean();
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// DATOS PARA LA VISTA
// ============================================================
$requeridos = cargar_requeridos($conn, $property_id);

$stmt = $conn->prepare("
    SELECT * FROM client_uploaded_documents
    WHERE property_id = ?
    ORDER BY uploaded_at DESC
");
$stmt->execute([$property_id]);
$documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$docs_por_tipo = [];
foreach ($documentos as $d) {
    $docs_por_tipo[$d['document_type']][] = $d;
}

$total_requeridos = count($requeridos);
$total_cargados   = 0;
foreach ($requeridos as $r) {
    if ($r['estado'] === 'cargado') $total_cargados++;
}
$pct = $total_requeridos > 0 ? round(($total_cargados / $total_requeridos) * 100) : 0;

if ($usar_token_real) {
    $archivos_restantes = max(0, $token_data['max_uploads'] - $token_data['upload_count']);
    $expira_en = (int)ceil((strtotime($token_data['expires_at']) - time()) / 86400);
} else {
    $archivos_restantes = 9999;
    $expira_en = 365;
}

$property_title_safe = htmlspecialchars($token_data['property_title'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Documentos · <?= $property_title_safe ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    min-height: 100vh; padding: 20px; color: #2c3e50;
}
.container { max-width: 1080px; margin: 0 auto; }
.admin-bar {
    background: #1d4ed8; color: #fff; padding: 10px 20px;
    border-radius: 12px 12px 0 0;
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 10px;
}
.admin-bar .admin-info { display: flex; align-items: center; gap: 10px; font-size: 14px; }
.admin-bar .admin-actions a {
    color: #fff; text-decoration: none; background: rgba(255,255,255,.2);
    padding: 6px 14px; border-radius: 6px; font-size: 13px;
    display: inline-flex; align-items: center; gap: 6px;
}
.admin-bar .admin-actions a:hover { background: rgba(255,255,255,.3); }

.card {
    background: #fff; border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0,0,0,.08);
    padding: 36px; margin-bottom: 20px;
}
.header { text-align: center; margin-bottom: 24px; }
.header .icon-badge {
    display: inline-flex; align-items: center; justify-content: center;
    width: 80px; height: 80px; border-radius: 50%;
    background: #e8f0fe; color: #2c3e50; font-size: 34px;
    margin-bottom: 12px;
}
.header h1 { font-size: 26px; margin-bottom: 6px; }
.header p { color: #64748b; font-size: 15px; }
.header .property-title { color: #3498db; font-weight: 600; }

.progress-wrap {
    background: #f8fafc; border: 1px solid #e2e8f0;
    border-radius: 12px; padding: 18px 20px; margin-bottom: 28px;
}
.progress-head {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 10px; font-size: 14px;
}
.progress-head .stat { font-weight: 600; color: #2c3e50; }
.progress-head .pct { font-weight: 700; color: #3498db; font-size: 18px; }
.progress-bar { height: 10px; background: #e2e8f0; border-radius: 20px; overflow: hidden; }
.progress-fill {
    height: 100%; background: linear-gradient(90deg, #3498db, #27ae60);
    border-radius: 20px; transition: width .4s ease;
}
.progress-meta {
    display: flex; gap: 18px; flex-wrap: wrap;
    margin-top: 12px; font-size: 13px; color: #64748b;
}
.progress-meta span i { color: #94a3b8; margin-right: 4px; }

.docs-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
    gap: 16px; margin-bottom: 28px;
}
.doc-card {
    background: #fff; border: 2px solid #e5e7eb; border-radius: 14px;
    padding: 22px 18px 18px; text-align: center;
    transition: all .2s; position: relative;
    display: flex; flex-direction: column;
}
.doc-card:hover {
    border-color: #3498db; transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(52,152,219,.12);
}
.doc-card.cargado {
    border-color: #27ae60;
    background: linear-gradient(180deg, #f0fdf4 0%, #fff 100%);
}
.doc-card.parcial {
    border-color: #f39c12;
    background: linear-gradient(180deg, #fffbeb 0%, #fff 100%);
}
.doc-card .badge-status { position: absolute; top: 10px; right: 10px; font-size: 18px; }
.doc-icon {
    width: 56px; height: 56px; margin: 0 auto 12px; border-radius: 50%;
    background: #e8f0fe; color: #3498db;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px; transition: all .2s;
}
.doc-card.cargado .doc-icon { background: #d4edda; color: #27ae60; }
.doc-card.parcial .doc-icon { background: #fff3cd; color: #f39c12; }
.doc-card h4 { font-size: 15px; font-weight: 600; color: #2c3e50; margin-bottom: 6px; line-height: 1.3; }
.doc-card .doc-desc { font-size: 12px; color: #94a3b8; line-height: 1.4; margin-bottom: 12px; min-height: 32px; }
.doc-badge {
    display: inline-block; padding: 4px 12px; border-radius: 20px;
    font-size: 11px; font-weight: 600; margin-bottom: 12px;
    text-transform: uppercase; letter-spacing: .3px;
}
.badge-cargado   { background: #d4edda; color: #155724; }
.badge-parcial   { background: #fff3cd; color: #856404; }
.badge-pendiente { background: #f1f5f9; color: #64748b; }
.doc-upload-btn {
    display: inline-flex; align-items: center; justify-content: center;
    gap: 6px; background: #3498db; color: #fff;
    padding: 9px 14px; border-radius: 8px; font-size: 13px; font-weight: 500;
    cursor: pointer; border: none; transition: background .2s; margin-top: auto;
}
.doc-upload-btn:hover { background: #2980b9; }
.doc-card.cargado .doc-upload-btn { background: #64748b; }
.doc-card.cargado .doc-upload-btn:hover { background: #475569; }
.doc-view-link {
    display: block; margin-top: 8px; font-size: 12px;
    color: #3498db; text-decoration: none;
}
.doc-view-link:hover { text-decoration: underline; }

.modal-backdrop {
    position: fixed; inset: 0; background: rgba(15,23,42,.55);
    display: none; align-items: center; justify-content: center;
    z-index: 1000; padding: 20px;
}
.modal-backdrop.open { display: flex; }
.modal {
    background: #fff; border-radius: 14px;
    max-width: 640px; width: 100%; max-height: 85vh;
    display: flex; flex-direction: column; overflow: hidden;
}
.modal-head {
    padding: 18px 22px; border-bottom: 1px solid #e2e8f0;
    display: flex; justify-content: space-between; align-items: center;
}
.modal-head h3 { font-size: 17px; }
.modal-close { background: none; border: none; font-size: 22px; cursor: pointer; color: #64748b; line-height: 1; }
.modal-body { padding: 18px 22px; overflow-y: auto; }
.modal-file {
    display: flex; justify-content: space-between; align-items: center;
    padding: 12px 14px; background: #f8fafc; border-radius: 8px;
    margin-bottom: 8px; gap: 10px; flex-wrap: wrap;
}
.modal-file .meta { display: flex; gap: 10px; align-items: center; min-width: 0; flex: 1; }
.modal-file .meta i { color: #3498db; font-size: 18px; }
.modal-file .meta .name { font-size: 13px; font-weight: 500; word-break: break-word; }
.modal-file .meta .sub { font-size: 11px; color: #94a3b8; }
.modal-file a {
    font-size: 12px; text-decoration: none; background: #e8f0fe;
    color: #3498db; padding: 4px 10px; border-radius: 5px;
}
.btn-delete-doc {
    background: #fee2e2; color: #991b1b; border: none;
    padding: 4px 10px; border-radius: 5px; font-size: 12px; cursor: pointer;
    transition: background .2s;
}
.btn-delete-doc:hover { background: #fecaca; }
.btn-delete-doc:disabled { opacity: .5; cursor: not-allowed; }

.toast-container {
    position: fixed; top: 20px; right: 20px; z-index: 2000;
    display: flex; flex-direction: column; gap: 10px; max-width: 360px;
}
.toast {
    background: #fff; border-left: 4px solid #3498db;
    border-radius: 8px; padding: 14px 18px;
    box-shadow: 0 8px 24px rgba(0,0,0,.12);
    font-size: 14px; animation: slideIn .3s ease;
    display: flex; gap: 10px; align-items: flex-start;
}
.toast.success { border-left-color: #27ae60; }
.toast.error   { border-left-color: #e74c3c; }
.toast i { font-size: 18px; }
.toast.success i { color: #27ae60; }
.toast.error i   { color: #e74c3c; }
@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to   { transform: translateX(0);    opacity: 1; }
}

.doc-card.uploading { opacity: .7; pointer-events: none; }
.doc-card.uploading::after {
    content: ""; position: absolute; inset: 0;
    background: rgba(255,255,255,.7); border-radius: 14px; display: block;
}
.doc-card.uploading::before {
    content: ""; position: absolute; top: 50%; left: 50%;
    width: 28px; height: 28px; margin: -14px 0 0 -14px;
    border: 3px solid #cbd5e1; border-top-color: #3498db;
    border-radius: 50%; animation: spin .8s linear infinite; z-index: 2;
}
@keyframes spin { to { transform: rotate(360deg); } }

.footer { text-align: center; color: #94a3b8; font-size: 13px; padding: 20px 0; }
.footer i { color: #3498db; }

@media (max-width: 640px) {
    .card { padding: 22px 16px; }
    .header h1 { font-size: 21px; }
    .docs-grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px; }
    .doc-card { padding: 16px 12px 14px; }
    .doc-icon { width: 46px; height: 46px; font-size: 20px; }
    .doc-card h4 { font-size: 13px; }
    .doc-card .doc-desc { font-size: 11px; min-height: 28px; }
    .toast-container { left: 10px; right: 10px; max-width: none; }
    .admin-bar { flex-direction: column; text-align: center; }
}
</style>
</head>
<body>

<div class="toast-container" id="toastContainer"></div>

<div class="container">

    <?php if ($modo_admin): ?>
    <div class="admin-bar">
        <div class="admin-info">
            <i class="fas fa-user-shield"></i>
            <span>
                <strong>Modo Administrador</strong> ·
                <?= htmlspecialchars($token_data['property_title'], ENT_QUOTES, 'UTF-8') ?>
                <span style="opacity:.8; font-size:12px;">· ID: <?= $property_id ?></span>
            </span>
        </div>
        <div class="admin-actions">
            <a href="propiedad_detalle_inventario.php?id=<?= $property_id ?>">
                <i class="fas fa-arrow-left"></i> Volver a detalles
            </a>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="header">
            <div class="icon-badge"><i class="fas fa-folder-open"></i></div>
            <h1>Documentos de la Propiedad</h1>
            <p>
                <span class="property-title">
                    <?= htmlspecialchars($token_data['property_title'], ENT_QUOTES, 'UTF-8') ?>
                </span>
            </p>
        </div>

        <div class="progress-wrap">
            <div class="progress-head">
                <div class="stat">
                    <i class="fas fa-check-circle" style="color:#27ae60;"></i>
                    <?= $total_cargados ?> de <?= $total_requeridos ?> documentos completados
                </div>
                <div class="pct"><?= $pct ?>%</div>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?= $pct ?>%;"></div>
            </div>
            <div class="progress-meta">
                <?php if ($usar_token_real): ?>
                    <span><i class="fas fa-file-upload"></i> <?= $archivos_restantes ?> subidas restantes</span>
                    <span>
                        <i class="fas fa-clock"></i>
                        <?php
                            if ($expira_en <= 0)      echo "Expira hoy";
                            elseif ($expira_en === 1) echo "Expira mañana";
                            else                      echo "Expira en {$expira_en} días";
                        ?>
                    </span>
                <?php else: ?>
                    <span><i class="fas fa-user-shield"></i> Modo admin sin límites</span>
                <?php endif; ?>
                <span><i class="fas fa-lock"></i> Enlace seguro</span>
            </div>
        </div>

        <div class="docs-grid" id="docsGrid">
            <?php foreach ($requeridos as $doc): ?>
                <?php
                    $estado = $doc['estado'];
                    $docs_tipo = $docs_por_tipo[$doc['code']] ?? [];
                ?>
                <div class="doc-card <?= htmlspecialchars($estado) ?>"
                     data-type="<?= htmlspecialchars($doc['code']) ?>"
                     data-requires-multiple="<?= (int)$doc['requires_multiple'] ?>"
                     data-label="<?= htmlspecialchars($doc['label'], ENT_QUOTES, 'UTF-8') ?>">

                    <span class="badge-status">
                        <?php if ($estado === 'cargado'): ?>✅
                        <?php elseif ($estado === 'parcial'): ?>🟡
                        <?php else: ?>⬜<?php endif; ?>
                    </span>

                    <div class="doc-icon">
                        <i class="fas fa-<?= htmlspecialchars($doc['icon']) ?>"></i>
                    </div>

                    <h4><?= htmlspecialchars($doc['label']) ?></h4>
                    <p class="doc-desc"><?= htmlspecialchars($doc['description'] ?? '') ?></p>

                    <span class="doc-badge badge-<?= htmlspecialchars($estado) ?>" data-role="badge">
                        <?php if ($estado === 'cargado'): ?>
                            Cargado
                        <?php elseif ($estado === 'parcial'): ?>
                            <?= $doc['uploads_count'] ?> archivo(s)
                        <?php else: ?>
                            Pendiente
                        <?php endif; ?>
                    </span>

                    <label class="doc-upload-btn">
                        <i class="fas fa-camera"></i>
                        <span data-role="btn-text">
                            <?= $estado === 'pendiente' ? 'Subir' : 'Agregar' ?>
                        </span>
                        <input type="file"
                               class="doc-file-input"
                               accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"
                               <?= $doc['requires_multiple'] ? 'multiple' : '' ?>
                               hidden>
                    </label>

                    <?php if (!empty($docs_tipo)): ?>
                        <a href="#" class="doc-view-link"
                           data-role="view"
                           data-type="<?= htmlspecialchars($doc['code']) ?>">
                            <i class="fas fa-eye"></i>
                            Ver <?= count($docs_tipo) ?> archivo(s)
                        </a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="footer">
        <p>
            <i class="fas fa-lock"></i>
            Enlace seguro de la plataforma Inmobiliaria MH.
            <br>
            ¿Problemas? Contacta a tu agente inmobiliario.
        </p>
    </div>
</div>

<div class="modal-backdrop" id="modalBackdrop">
    <div class="modal">
        <div class="modal-head">
            <h3 id="modalTitle">Documentos</h3>
            <button class="modal-close" id="modalClose">&times;</button>
        </div>
        <div class="modal-body" id="modalBody"></div>
    </div>
</div>

<script>
window.__DOCS_DATA__     = <?= json_encode($docs_por_tipo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.__UPLOAD_URL__    = "?<?= $modo_admin ? 'id=' . $property_id : 'token=' . urlencode($token) ?>&ajax=1";
window.__DELETE_URL__    = "?<?= $modo_admin ? 'id=' . $property_id : 'token=' . urlencode($token) ?>&ajax=delete";
window.__MAX_FILE_SIZE__ = <?= MAX_FILE_SIZE ?>;
window.__IS_ADMIN__      = <?= $modo_admin ? 'true' : 'false' ?>;
</script>

<script>
(function () {
    'use strict';

    var uploadUrl  = window.__UPLOAD_URL__;
    var deleteUrl  = window.__DELETE_URL__;
    var maxSize    = window.__MAX_FILE_SIZE__;
    var docsData   = window.__DOCS_DATA__;
    var isAdmin    = window.__IS_ADMIN__;
    var toastBox   = document.getElementById('toastContainer');
    var grid       = document.getElementById('docsGrid');
    var backdrop   = document.getElementById('modalBackdrop');
    var modalTitle = document.getElementById('modalTitle');
    var modalBody  = document.getElementById('modalBody');

    // ---------- Toast ----------
    function toast(msg, type) {
        type = type || 'success';
        var el = document.createElement('div');
        el.className = 'toast ' + type;
        el.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'exclamation-circle') + '"></i><span>' + escapeHtml(msg) + '</span>';
        toastBox.appendChild(el);
        setTimeout(function () {
            el.style.transition = 'opacity .3s, transform .3s';
            el.style.opacity = '0';
            el.style.transform = 'translateX(100%)';
            setTimeout(function () { el.remove(); }, 300);
        }, 4500);
    }

    // ---------- Subir ----------
    grid.addEventListener('change', async function (e) {
        var input = e.target;
        if (!input.classList.contains('doc-file-input')) return;

        var card     = input.closest('.doc-card');
        var type     = card.dataset.type;
        var multiple = card.dataset.requiresMultiple === '1';
        var files    = input.files;

        if (!files || files.length === 0) return;

        if (!multiple && files.length > 1) {
            toast('Solo puedes subir 1 archivo en este documento.', 'error');
            input.value = '';
            return;
        }

        var validExt = ['pdf','jpg','jpeg','png','doc','docx','xls','xlsx'];
        for (var i = 0; i < files.length; i++) {
            var f = files[i];
            var ext = f.name.split('.').pop().toLowerCase();
            if (validExt.indexOf(ext) === -1) {
                toast('Formato no permitido: ' + f.name, 'error');
                input.value = '';
                return;
            }
            if (f.size > maxSize) {
                toast('"' + f.name + '" excede 10MB', 'error');
                input.value = '';
                return;
            }
        }

        var fd = new FormData();
        fd.append('document_type', type);
        if (!multiple || files.length === 1) {
            fd.append('documento', files[0]);
        } else {
            for (var j = 0; j < files.length; j++) fd.append('documento[]', files[j]);
        }

        card.classList.add('uploading');

        try {
            var res  = await fetch(uploadUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
            var text = await res.text();

            var data;
            try { data = JSON.parse(text); }
            catch (parseErr) {
                console.error('Respuesta no-JSON del servidor:', text);
                throw new Error('Servidor devolvió error inesperado.');
            }

            if (!data.ok) throw new Error(data.msg || 'Error desconocido');

            toast(data.msg || 'Documento subido.', 'success');
            setTimeout(function () { window.location.reload(); }, 900);

        } catch (err) {
            toast(err.message || 'Error al subir.', 'error');
            card.classList.remove('uploading');
        } finally {
            input.value = '';
        }
    });

    // ---------- Ver modal ----------
    grid.addEventListener('click', function (e) {
        var link = e.target.closest('[data-role="view"]');
        if (!link) return;
        e.preventDefault();

        var type  = link.dataset.type;
        var items = docsData[type] || [];
        var cardLabel = link.closest('.doc-card').dataset.label;

        modalTitle.textContent = cardLabel;
        modalBody.innerHTML = '';

        if (items.length === 0) {
            modalBody.innerHTML = '<p style="color:#94a3b8;text-align:center;padding:20px;">Sin documentos.</p>';
        } else {
            items.forEach(function (d) {
                var kb = (d.file_size / 1024).toFixed(1);
                var date = new Date(d.uploaded_at.replace(' ', 'T'));
                var fecha = date.toLocaleDateString('es-MX', { day:'2-digit', month:'short', year:'numeric' });
                var status = ({
                    'pending_review':     '⏳ Pendiente',
                    'approved':           '✅ Aprobado',
                    'rejected':           '❌ Rechazado',
                    'pending_correction': '🔄 Corrección'
                })[d.status] || d.status;

                // Regla: cliente no puede borrar aprobados. Admin sí.
                var puedeBorrar = isAdmin || d.status !== 'approved';

                var div = document.createElement('div');
                div.className = 'modal-file';
                div.innerHTML =
                    '<div class="meta">' +
                        '<i class="fas fa-file"></i>' +
                        '<div>' +
                            '<div class="name">' + escapeHtml(d.file_name) + '</div>' +
                            '<div class="sub">' + kb + ' KB · ' + fecha + ' · ' + status + '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div style="display:flex; gap:6px;">' +
                        '<a href="' + escapeHtml(d.file_path) + '" target="_blank" rel="noopener">' +
                            '<i class="fas fa-eye"></i> Ver' +
                        '</a>' +
                        (puedeBorrar
                            ? '<button class="btn-delete-doc" data-id="' + d.id + '">' +
                              '<i class="fas fa-trash"></i> Borrar</button>'
                            : '') +
                    '</div>';
                modalBody.appendChild(div);
            });
        }

        backdrop.classList.add('open');
    });

    // ---------- Borrar desde modal ----------
    modalBody.addEventListener('click', async function (e) {
        var btn = e.target.closest('.btn-delete-doc');
        if (!btn) return;

        var id = btn.dataset.id;
        if (!id) return;

        if (!confirm('¿Eliminar este documento? Esta acción no se puede deshacer.')) return;

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Borrando...';

        try {
            var fd = new FormData();
            fd.append('id', id);

            var res  = await fetch(deleteUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
            var text = await res.text();

            var data;
            try { data = JSON.parse(text); }
            catch (parseErr) {
                console.error('Respuesta no-JSON:', text);
                throw new Error('Servidor devolvió error inesperado.');
            }

            if (!data.ok) throw new Error(data.msg || 'Error al borrar.');

            toast('Documento eliminado.', 'success');

            var row = btn.closest('.modal-file');
            if (row) row.remove();

            // Si ya no quedan filas, cerrar modal
            if (modalBody.querySelectorAll('.modal-file').length === 0) {
                backdrop.classList.remove('open');
            }

            // Recargar para actualizar las tarjetas y contadores
            setTimeout(function () { window.location.reload(); }, 700);

        } catch (err) {
            toast(err.message || 'Error al borrar.', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash"></i> Borrar';
        }
    });

    // ---------- Cerrar modal ----------
    document.getElementById('modalClose').addEventListener('click', function () {
        backdrop.classList.remove('open');
    });
    backdrop.addEventListener('click', function (e) {
        if (e.target === backdrop) backdrop.classList.remove('open');
    });

    // ---------- Escape HTML ----------
    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c];
        });
    }
})();
</script>

</body>
</html>