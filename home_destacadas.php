<?php
session_start();

require_once 'includes/conexion.php';
require_once 'includes/auth.php';

// ===== Verificar autenticación =====
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

$es_admin = esAdmin();

// Solo admin puede curar el home
if (!$es_admin) {
    header('Location: inventario_maestro.php?err=permiso');
    exit;
}

// ===== Límite de propiedades en el home =====
define('HOME_LIMIT', 6);

// ============================================================
// ===== PROCESAR ACCIONES (POST / AJAX) ======================
// ============================================================
$esAjax = (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) || (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['accion']) && in_array($_POST['accion'], ['reorder','toggle','reset']));

if ($esAjax) {
    header('Content-Type: application/json; charset=utf-8');
    $accion = $_POST['accion'] ?? '';

    try {
        if ($accion === 'reorder') {
            $ids = json_decode($_POST['ids'] ?? '[]', true);
            if (!is_array($ids)) throw new Exception('Datos inválidos');

            $ids = array_slice($ids, 0, HOME_LIMIT);

            $conn->beginTransaction();
            $stmt = $conn->prepare("UPDATE properties SET display_order = :orden, featured_home = 1 WHERE id = :id");
            foreach ($ids as $i => $id) {
                $stmt->execute([':orden' => $i + 1, ':id' => (int)$id]);
            }
            $conn->commit();
            echo json_encode(['ok' => true, 'message' => 'Orden guardado']);
            exit;
        }

        if ($accion === 'toggle') {
            $id    = (int)($_POST['id'] ?? 0);
            $value = (int)($_POST['value'] ?? 0);
            if (!$id) throw new Exception('ID inválido');

            if ($value === 1) {
                $count = (int)$conn->query("SELECT COUNT(*) FROM properties WHERE featured_home = 1 AND publica = 1")->fetchColumn();
                if ($count >= HOME_LIMIT) {
                    echo json_encode(['ok' => false, 'message' => 'Límite alcanzado. Quita una antes de agregar otra.']);
                    exit;
                }
                $maxOrder = (int)$conn->query("SELECT COALESCE(MAX(display_order),0) FROM properties WHERE featured_home = 1")->fetchColumn();
                $stmt = $conn->prepare("UPDATE properties SET featured_home = 1, display_order = :o WHERE id = :id");
                $stmt->execute([':o' => $maxOrder + 1, ':id' => $id]);
            } else {
                $stmt = $conn->prepare("UPDATE properties SET featured_home = 0, display_order = 0 WHERE id = :id");
                $stmt->execute([':id' => $id]);
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($accion === 'reset') {
            $conn->beginTransaction();
            $conn->exec("UPDATE properties SET display_order = 0 WHERE featured_home = 1");
            $conn->exec("
                UPDATE properties p
                INNER JOIN (
                    SELECT p2.id, @rownum := @rownum + 1 as nuevo_orden
                    FROM properties p2
                    CROSS JOIN (SELECT @rownum := 0) r
                    WHERE p2.featured_home = 1
                    ORDER BY 
                        CASE 
                            WHEN p2.status = 'activo' THEN 0
                            WHEN p2.status = 'apartado' THEN 1
                            WHEN p2.status = 'vendido' THEN 2
                            ELSE 3
                        END,
                        p2.created_at DESC
                ) AS ordenados ON p.id = ordenados.id
                SET p.display_order = ordenados.nuevo_orden
            ");
            $conn->commit();
            echo json_encode(['ok' => true, 'message' => 'Orden restaurado']);
            exit;
        }

        throw new Exception('Acción no reconocida');
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ============================================================
// ===== CARGAR DATOS =========================================
// ============================================================
$destacadas = [];
$disponibles = [];

try {
    $stmt = $conn->prepare("
        SELECT 
            p.id, p.title, p.status, p.operation_type, p.colonia, p.address_city, p.municipio,
            p.display_order,
            f.asking_price AS price,
            (SELECT file_path FROM property_media 
             WHERE property_id = p.id AND is_primary = 1 
             ORDER BY sort_order ASC LIMIT 1) AS imagen_principal
        FROM properties p
        LEFT JOIN property_financials f ON p.id = f.property_id
        WHERE p.featured_home = 1 AND p.publica = 1
        ORDER BY p.display_order ASC, p.created_at DESC
        LIMIT " . HOME_LIMIT
    );
    $stmt->execute();
    $destacadas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $idsYa = array_column($destacadas, 'id');
    $placeholders = !empty($idsYa) ? implode(',', array_fill(0, count($idsYa), '?')) : '0';

    $stmt2 = $conn->prepare("
        SELECT 
            p.id, p.title, p.status, p.operation_type, p.colonia, p.address_city, p.municipio,
            f.asking_price AS price,
            (SELECT file_path FROM property_media 
             WHERE property_id = p.id AND is_primary = 1 
             ORDER BY sort_order ASC LIMIT 1) AS imagen_principal
        FROM properties p
        LEFT JOIN property_financials f ON p.id = f.property_id
        WHERE p.publica = 1 
          AND p.status IN ('activo','apartado','vendido')
          AND p.id NOT IN ($placeholders)
        ORDER BY p.created_at DESC
        LIMIT 100
    ");
    $stmt2->execute($idsYa);
    $disponibles = $stmt2->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error home_destacadas: " . $e->getMessage());
}

// ===== Helpers =====
function formatearPrecioHome($precio) {
    if ($precio === null || $precio == 0) return 'Consultar precio';
    return '$' . number_format(floatval($precio), 0, ',', '.');
}
function getImagenHome($img) {
    if (empty($img)) {
        return 'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?auto=format&fit=crop&w=400&q=80';
    }
    if (strpos($img, 'uploads/') === 0) return htmlspecialchars($img);
    return 'uploads/propiedades/' . htmlspecialchars($img);
}
function getStatusPill($status) {
    $s = strtolower(trim($status ?? ''));
    if ($s === 'activo')   return ['class' => 'status-active',   'label' => 'Activo'];
    if ($s === 'apartado') return ['class' => 'status-sold',     'label' => 'Apartado'];
    if ($s === 'vendido')  return ['class' => 'status-inactive', 'label' => 'Vendido'];
    return ['class' => 'status-other', 'label' => ucfirst($s)];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#1d4ed8">
    <link rel="stylesheet" href="css/socios.css">
    <title>Propiedades del Home | Vera Terra</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        html, body { overflow-x: hidden; max-width: 100vw; }
        img { max-width: 100%; height: auto; }

        /* ===== TABS ===== */
        .view-tabs {
            display: flex; gap: 6px; margin: 0 0 16px 0; padding: 4px;
            background: #f1f5f9; border-radius: 10px; flex-wrap: wrap; align-items: center;
        }
        .view-tab {
            display: flex; align-items: center; gap: 6px; padding: 8px 16px;
            border-radius: 8px; font-size: 0.82rem; font-weight: 600;
            color: #475569; text-decoration: none; transition: all 0.15s; white-space: nowrap;
        }
        .view-tab:hover { background: #e2e8f0; color: #0f172a; }
        .view-tab.active { background: #fff; color: #0f172a; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .view-tab .tab-count {
            background: #cbd5e1; color: #334155; font-size: 0.68rem;
            padding: 1px 7px; border-radius: 10px; font-weight: 700;
            min-width: 18px; text-align: center;
        }
        .view-tab.active .tab-count { background: #1d4ed8; color: #fff; }
        .view-tab.back-link { margin-left: auto; }

        /* ===== LAYOUT ===== */
        .home-layout {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 20px;
            align-items: start;
        }
        @media (max-width: 1100px) {
            .home-layout { grid-template-columns: 1fr 340px; }
        }
        @media (max-width: 992px) {
            .home-layout { grid-template-columns: 1fr; }
        }

        .panel {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
        }
        .panel-header {
            padding: 14px 18px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .panel-header h3 {
            margin: 0; font-size: 0.95rem; color: #0f172a;
            display: flex; align-items: center; gap: 8px;
        }
        .panel-header .counter {
            font-size: 0.75rem; color: #64748b; font-weight: 600;
        }
        .panel-header .counter strong { color: #1d4ed8; font-weight: 700; }
        .panel-header .counter.lleno strong { color: #16a34a; }

        .panel-body { padding: 16px; }

        /* ===== SLOTS ===== */
        .slots {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .slot {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #f8fafc;
            border: 2px dashed #cbd5e1;
            border-radius: 10px;
            padding: 10px 12px;
            min-height: 88px;
            transition: all 0.15s;
        }
        .slot.filled {
            background: #fff;
            border: 2px solid #c5a059;
            box-shadow: 0 2px 8px rgba(197, 160, 89, 0.12);
            cursor: grab;
        }
        .slot.filled:hover { box-shadow: 0 4px 14px rgba(197, 160, 89, 0.22); }
        .slot.filled:active { cursor: grabbing; }
        .slot.dragging { opacity: 0.4; }
        .slot.drag-over {
            border-color: #1d4ed8;
            background: #eff6ff;
        }
        .slot.empty {
            background: repeating-linear-gradient(
                45deg, #fafbfc, #fafbfc 10px, #f1f5f9 10px, #f1f5f9 20px
            );
        }

        .slot-position {
            width: 32px; height: 32px;
            background: #c5a059;
            color: #fff;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 0.85rem;
            flex-shrink: 0;
        }
        .slot.empty .slot-position { background: #cbd5e1; }

        .slot-thumb {
            width: 70px; height: 60px;
            border-radius: 8px; overflow: hidden;
            background: #e2e8f0; flex-shrink: 0;
        }
        .slot-thumb img {
            width: 100%; height: 100%; object-fit: cover; display: block;
        }
        .slot-thumb.placeholder {
            display: flex; align-items: center; justify-content: center;
            color: #94a3b8; font-size: 1.4rem;
        }

        .slot-info { flex: 1; min-width: 0; }
        .slot-title {
            font-size: 0.85rem; font-weight: 600; color: #0f172a;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            margin-bottom: 3px;
        }
        .slot-location {
            font-size: 0.72rem; color: #64748b;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            margin-bottom: 4px;
        }
        .slot-meta {
            display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
        }
        .slot-meta .price {
            font-size: 0.78rem; font-weight: 700; color: #c5a059;
        }
        .status-pill {
            display: inline-block; font-size: 0.6rem; font-weight: 700;
            padding: 1px 8px; border-radius: 10px; text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .status-pill.status-active   { background: #dcfce7; color: #166534; }
        .status-pill.status-inactive { background: #fee2e2; color: #991b1b; }
        .status-pill.status-sold     { background: #fef3c7; color: #92400e; }
        .status-pill.status-other    { background: #f1f5f9; color: #475569; }

        .slot-actions {
            display: flex; gap: 4px; flex-shrink: 0;
        }
        .slot-btn {
            width: 34px; height: 34px;
            border: 1px solid #e2e8f0;
            background: #fff; color: #64748b;
            border-radius: 8px; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.8rem;
            transition: all 0.15s;
            -webkit-tap-highlight-color: transparent;
        }
        .slot-btn:hover { background: #f1f5f9; color: #0f172a; }
        .slot-btn.danger:hover { background: #fee2e2; color: #dc2626; border-color: #fca5a5; }
        .slot-btn:disabled { opacity: 0.3; cursor: not-allowed; }
        .slot-btn:disabled:hover { background: #fff; color: #64748b; }

        .slot-empty-text {
            color: #94a3b8; font-size: 0.82rem;
            font-style: italic; padding-left: 8px;
        }

        /* ===== DISPONIBLES ===== */
        .search-available {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.82rem;
            margin-bottom: 12px;
            outline: none;
        }
        .search-available:focus { border-color: #1d4ed8; }

        .available-list {
            max-height: 480px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .available-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            transition: all 0.15s;
        }
        .available-item:hover {
            background: #f1f5f9;
            border-color: #c5a059;
        }
        .available-item .thumb {
            width: 48px; height: 48px;
            border-radius: 6px; overflow: hidden;
            background: #e2e8f0; flex-shrink: 0;
        }
        .available-item .thumb img {
            width: 100%; height: 100%; object-fit: cover;
        }
        .available-item .info { flex: 1; min-width: 0; }
        .available-item .info .title {
            font-size: 0.78rem; font-weight: 600; color: #0f172a;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .available-item .info .meta {
            font-size: 0.68rem; color: #64748b;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .available-item .add-btn {
            width: 34px; height: 34px;
            background: #16a34a; color: #fff;
            border: none; border-radius: 8px;
            cursor: pointer; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.85rem;
            transition: background 0.15s;
            -webkit-tap-highlight-color: transparent;
        }
        .available-item .add-btn:hover { background: #15803d; }
        .available-item .add-btn:disabled { background: #cbd5e1; cursor: not-allowed; }

        .empty-available {
            text-align: center;
            padding: 30px 10px;
            color: #94a3b8;
            font-size: 0.82rem;
        }
        .empty-available i {
            font-size: 2rem;
            margin-bottom: 8px;
            display: block;
        }

        /* ===== PREVIEW LANDING (ahora en grid real tipo landing) ===== */
        .preview-panel .panel-header {
            background: #0b1f3a;
            color: #fff;
        }
        .preview-panel .panel-header h3 { color: #fff; }
        .preview-tag {
            font-size: 0.65rem;
            background: #c5a059;
            color: #fff;
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: 700;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }
        .preview-body {
            padding: 14px;
            background: #f8f7f4;
        }
        .preview-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }
        .preview-card {
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(11,31,58,0.08);
            border: 1px solid rgba(197,160,89,0.15);
            position: relative;
        }
        .preview-card .img {
            aspect-ratio: 4 / 3;
            background: #e2e8f0;
            overflow: hidden;
            position: relative;
        }
        .preview-card .img img {
            width: 100%; height: 100%; object-fit: cover; display: block;
        }
        .preview-card .img .preview-badge {
            position: absolute;
            top: 4px; right: 4px;
            background: #0b1f3a; color: #c5a059;
            width: 20px; height: 20px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.5rem;
            border: 1px solid #c5a059;
        }
        .preview-card .pos-badge {
            position: absolute;
            top: 4px; left: 4px;
            background: #c5a059; color: #fff;
            width: 20px; height: 20px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.6rem;
            font-weight: 700;
            z-index: 2;
        }
        .preview-card .body {
            padding: 6px 8px 8px;
        }
        .preview-card .title {
            font-size: 0.68rem;
            font-weight: 600;
            color: #0b1f3a;
            margin-bottom: 2px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .preview-card .loc {
            font-size: 0.58rem;
            color: #64748b;
            margin-bottom: 3px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .preview-card .price {
            font-size: 0.7rem;
            font-weight: 700;
            color: #c5a059;
        }
        .preview-empty {
            grid-column: 1 / -1;
            text-align: center;
            padding: 30px 10px;
            color: #94a3b8;
            font-size: 0.8rem;
        }
        .preview-empty i {
            font-size: 2rem;
            display: block;
            margin-bottom: 8px;
            opacity: 0.5;
        }

        /* ===== BARRA ACCIONES ===== */
        .actions-bar {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            flex-wrap: wrap;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid #e2e8f0;
            background: #fff;
            color: #475569;
            transition: all 0.15s;
            -webkit-tap-highlight-color: transparent;
            min-height: 40px;
        }
        .btn-action:hover { background: #f1f5f9; color: #0f172a; }
        .btn-action.primary {
            background: #1d4ed8; color: #fff; border-color: #1d4ed8;
        }
        .btn-action.primary:hover { background: #1e40af; }
        .btn-action:disabled { opacity: 0.4; cursor: not-allowed; }

        /* ===== TOAST ===== */
        .toast {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%) translateY(80px);
            background: #0f172a;
            color: #fff;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 600;
            box-shadow: 0 8px 24px rgba(0,0,0,0.2);
            opacity: 0;
            transition: all 0.3s;
            z-index: 10000;
            display: flex;
            align-items: center;
            gap: 8px;
            max-width: calc(100vw - 40px);
        }
        .toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }
        .toast.success { background: #16a34a; }
        .toast.error   { background: #dc2626; }

        /* ============================================================
           ===== RESPONSIVE MÓVIL (<=768px) ===========================
           ============================================================ */
        @media (max-width: 768px) {

            /* --- TABS --- */
            .view-tabs { padding: 4px; gap: 4px; }
            .view-tab {
                flex: 1 1 calc(50% - 4px);
                justify-content: center;
                padding: 10px 8px;
                font-size: 0.75rem;
            }
            .view-tab.back-link {
                flex: 1 1 100%;
                margin-left: 0;
                margin-top: 4px;
            }

            /* --- SLOT REDISEÑADO EN MÓVIL --- */
            .slot {
                display: grid;
                grid-template-columns: 56px 1fr;
                grid-template-areas:
                    "thumb info"
                    "actions actions";
                gap: 10px;
                padding: 12px;
                align-items: start;
                cursor: default;
            }
            .slot .slot-position {
                display: none; /* en móvil usamos badge dentro de la thumb */
            }
            .slot-thumb {
                grid-area: thumb;
                width: 56px;
                height: 56px;
                border-radius: 8px;
                position: relative;
            }
            .slot-thumb::after {
                content: attr(data-pos);
                position: absolute;
                top: -6px;
                left: -6px;
                width: 22px;
                height: 22px;
                background: #c5a059;
                color: #fff;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 0.7rem;
                font-weight: 700;
                border: 2px solid #fff;
                box-shadow: 0 2px 6px rgba(0,0,0,0.15);
            }
            .slot.empty .slot-thumb::after {
                background: #cbd5e1;
            }
            .slot-info {
                grid-area: info;
                min-width: 0;
            }
            .slot-title { font-size: 0.85rem; }
            .slot-location { font-size: 0.7rem; }

            /* Botones abajo, fila completa, grandes y táctiles */
            .slot-actions {
                grid-area: actions;
                width: 100%;
                justify-content: flex-end;
                gap: 8px;
                margin-top: 2px;
                padding-top: 10px;
                border-top: 1px dashed #e2e8f0;
            }
            .slot-btn {
                width: 44px;
                height: 44px;
                font-size: 0.95rem;
                border-radius: 10px;
            }

            /* Slot vacío también se adapta */
            .slot.empty {
                display: flex;
                flex-direction: row;
                align-items: center;
                gap: 12px;
                min-height: 72px;
            }
            .slot.empty .slot-position { display: flex; }
            .slot.empty .slot-thumb { display: none; }
            .slot.empty .slot-empty-text { font-size: 0.8rem; }

            /* --- ACCIONES --- */
            .actions-bar { margin-top: 14px; }
            .btn-action {
                flex: 1 1 calc(50% - 4px);
                font-size: 0.8rem;
                min-height: 44px;
            }

            /* --- DISPONIBLES --- */
            .available-list { max-height: 360px; }
            .search-available { font-size: 16px; } /* evita zoom iOS */
            .available-item .add-btn {
                width: 40px;
                height: 40px;
                font-size: 0.95rem;
            }
            .available-item .thumb { width: 44px; height: 44px; }

            /* --- PREVIEW: 2 columnas en móvil (no tira vertical) --- */
            .preview-body { padding: 10px; }
            .preview-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }
            .preview-card .title { font-size: 0.65rem; }
            .preview-card .loc { font-size: 0.55rem; }
            .preview-card .price { font-size: 0.68rem; }
            .preview-card .img .preview-badge { display: none; }

            /* Panel del preview deja de ser sticky en móvil */
            .preview-panel { position: static; }
        }

        /* ===== RESPONSIVE TABLET (769px - 992px) ===== */
        @media (min-width: 769px) and (max-width: 992px) {
            .preview-grid {
                grid-template-columns: repeat(3, 1fr);
            }
            .available-list { max-height: 420px; }
        }

        /* ===== RESPONSIVE PC AMPLIO (>=1400px) ===== */
        @media (min-width: 1400px) {
            .home-layout { grid-template-columns: 1fr 420px; }
        }

        /* ===== EXTRA PEQUEÑOS (<=400px) ===== */
        @media (max-width: 400px) {
            .slot-thumb { width: 48px; height: 48px; }
            .slot-title { font-size: 0.8rem; }
            .preview-grid { grid-template-columns: repeat(2, 1fr); }
            .btn-action { flex: 1 1 100%; }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<?php include 'modulos/sidebar.php'; ?>

<main class="main-content">

    <div class="main-header">
        <div class="header-left">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <h1>Propiedades del Home</h1>
            <p class="welcome">
                <i class="fas fa-star"></i> 
                Selecciona y ordena las propiedades que aparecen en el landing
            </p>
        </div>
        <div class="header-actions">
            <button class="btn-header secondary" onclick="restaurarOrden()" title="Ordenar automáticamente por estado y fecha">
                <i class="fas fa-magic"></i> Orden Automático
            </button>
            <button class="btn-header primary" onclick="window.location.href='inventario_maestro.php'">
                <i class="fas fa-arrow-left"></i> Volver al Inventario
            </button>
        </div>
    </div>

    <div class="view-tabs">
        <a href="inventario_maestro.php?vista=activas" class="view-tab">
            <i class="fas fa-home"></i> Activas
        </a>
        <a href="inventario_maestro.php?vista=vendidas" class="view-tab">
            <i class="fas fa-check-circle"></i> Historial / Vendidas
        </a>
        <a href="inventario_maestro.php?vista=todas" class="view-tab">
            <i class="fas fa-list"></i> Todas
        </a>
        <a href="home_destacadas.php" class="view-tab active">
            <i class="fas fa-star"></i> ⭐ Home Destacadas
            <span class="tab-count"><?php echo count($destacadas); ?>/<?php echo HOME_LIMIT; ?></span>
        </a>
        <a href="mapa_ventas.php" class="view-tab back-link">
            <i class="fas fa-chart-line"></i> Portal de Métricas
        </a>
    </div>

    <div class="home-layout">

        <!-- ============ COLUMNA IZQUIERDA: SLOTS ============ -->
        <div>
            <div class="panel">
                <div class="panel-header">
                    <h3>
                        <i class="fas fa-grip-vertical" style="color:#c5a059;"></i>
                        Orden en el Home
                    </h3>
                    <div class="counter <?php echo count($destacadas) >= HOME_LIMIT ? 'lleno' : ''; ?>" id="counterPanel">
                        <strong id="counterActual"><?php echo count($destacadas); ?></strong> 
                        de <?php echo HOME_LIMIT; ?> posiciones
                        <?php if (count($destacadas) < HOME_LIMIT): ?>
                            · <span style="color:#c5a059;">Faltan <?php echo HOME_LIMIT - count($destacadas); ?></span>
                        <?php else: ?>
                            · <span style="color:#16a34a;"><i class="fas fa-check"></i> Completo</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="panel-body">

                    <?php if (empty($destacadas)): ?>
                        <div style="text-align:center; padding: 30px 10px; color:#94a3b8;">
                            <i class="fas fa-star" style="font-size: 2.5rem; margin-bottom: 10px; display:block; opacity:0.4;"></i>
                            <p style="margin: 0 0 4px; font-weight: 600; color:#475569;">
                                No hay propiedades destacadas
                            </p>
                            <p style="font-size: 0.8rem; margin: 0;">
                                Agrega propiedades desde el panel de la derecha
                            </p>
                        </div>
                    <?php endif; ?>

                    <div class="slots" id="slots">
                        <?php for ($i = 0; $i < HOME_LIMIT; $i++): 
                            $prop = $destacadas[$i] ?? null;
                        ?>
                            <?php if ($prop): 
                                $statusPill = getStatusPill($prop['status'] ?? '');
                                $img = getImagenHome($prop['imagen_principal'] ?? '');
                            ?>
                                <div class="slot filled" 
                                     draggable="true"
                                     data-id="<?php echo (int)$prop['id']; ?>"
                                     data-position="<?php echo $i + 1; ?>">
                                    <div class="slot-position"><?php echo $i + 1; ?></div>
                                    <div class="slot-thumb" data-pos="<?php echo $i + 1; ?>">
                                        <img src="<?php echo $img; ?>" 
                                             alt="<?php echo htmlspecialchars($prop['title']); ?>"
                                             onerror="this.src='https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?w=200'">
                                    </div>
                                    <div class="slot-info">
                                        <div class="slot-title"><?php echo htmlspecialchars($prop['title']); ?></div>
                                        <div class="slot-location">
                                            <i class="fas fa-location-dot" style="font-size:0.65rem; color:#c5a059;"></i>
                                            <?php echo htmlspecialchars($prop['colonia'] ?? $prop['municipio'] ?? 'Sin ubicación'); ?>
                                        </div>
                                        <div class="slot-meta">
                                            <span class="price"><?php echo formatearPrecioHome($prop['price'] ?? null); ?></span>
                                            <span class="status-pill <?php echo $statusPill['class']; ?>">
                                                <?php echo $statusPill['label']; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="slot-actions">
                                        <button type="button" class="slot-btn" 
                                                onclick="moverArriba(this)" 
                                                title="Subir"
                                                aria-label="Subir"
                                                <?php echo $i === 0 ? 'disabled' : ''; ?>>
                                            <i class="fas fa-arrow-up"></i>
                                        </button>
                                        <button type="button" class="slot-btn" 
                                                onclick="moverAbajo(this)" 
                                                title="Bajar"
                                                aria-label="Bajar"
                                                <?php echo $i === count($destacadas) - 1 ? 'disabled' : ''; ?>>
                                            <i class="fas fa-arrow-down"></i>
                                        </button>
                                        <button type="button" class="slot-btn danger" 
                                                onclick="quitarDelHome(<?php echo (int)$prop['id']; ?>, this)"
                                                title="Quitar del home"
                                                aria-label="Quitar del home">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="slot empty" data-position="<?php echo $i + 1; ?>">
                                    <div class="slot-position"><?php echo $i + 1; ?></div>
                                    <div class="slot-thumb placeholder">
                                        <i class="fas fa-image"></i>
                                    </div>
                                    <div class="slot-empty-text">
                                        Posición libre — agrega una propiedad de la derecha
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endfor; ?>
                    </div>

                    <div class="actions-bar">
                        <button type="button" class="btn-action primary" onclick="guardarOrden()" id="btnGuardar">
                            <i class="fas fa-save"></i> Guardar Orden
                        </button>
                        <button type="button" class="btn-action" onclick="location.reload()">
                            <i class="fas fa-rotate-left"></i> Recargar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============ COLUMNA DERECHA ============ -->
        <div>
            <!-- DISPONIBLES -->
            <div class="panel" style="margin-bottom: 16px;">
                <div class="panel-header">
                    <h3>
                        <i class="fas fa-plus-circle" style="color:#16a34a;"></i>
                        Agregar Propiedad
                    </h3>
                    <span class="counter"><?php echo count($disponibles); ?> disponibles</span>
                </div>
                <div class="panel-body">
                    <input type="text" 
                           class="search-available" 
                           id="searchAvailable"
                           placeholder="Buscar propiedad...">

                    <div class="available-list" id="availableList">
                        <?php if (empty($disponibles)): ?>
                            <div class="empty-available">
                                <i class="fas fa-check-circle" style="color:#16a34a;"></i>
                                No hay más propiedades publicadas disponibles para agregar
                            </div>
                        <?php else: ?>
                            <?php foreach ($disponibles as $disp): 
                                $statusPill = getStatusPill($disp['status'] ?? '');
                                $img = getImagenHome($disp['imagen_principal'] ?? '');
                            ?>
                                <div class="available-item" 
                                     data-search="<?php echo strtolower(htmlspecialchars($disp['title'] . ' ' . ($disp['colonia'] ?? '') . ' ' . ($disp['municipio'] ?? ''))); ?>">
                                    <div class="thumb">
                                        <img src="<?php echo $img; ?>" 
                                             alt=""
                                             onerror="this.src='https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?w=100'">
                                    </div>
                                    <div class="info">
                                        <div class="title"><?php echo htmlspecialchars($disp['title']); ?></div>
                                        <div class="meta">
                                            <i class="fas fa-location-dot"></i>
                                            <?php echo htmlspecialchars($disp['colonia'] ?? $disp['municipio'] ?? 'Sin ubicación'); ?>
                                            · <?php echo formatearPrecioHome($disp['price'] ?? null); ?>
                                        </div>
                                    </div>
                                    <button type="button" 
                                            class="add-btn"
                                            onclick="agregarAlHome(<?php echo (int)$disp['id']; ?>, this)"
                                            title="Agregar al home"
                                            aria-label="Agregar al home"
                                            <?php echo count($destacadas) >= HOME_LIMIT ? 'disabled' : ''; ?>>
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- PREVIEW (grid tipo landing, 3 col desktop / 2 col móvil) -->
            <div class="panel preview-panel">
                <div class="panel-header">
                    <h3>
                        <i class="fas fa-eye"></i>
                        Vista previa del Home
                    </h3>
                    <span class="preview-tag">Landing</span>
                </div>
                <div class="preview-body">
                    <div class="preview-grid" id="previewGrid">
                        <?php if (empty($destacadas)): ?>
                            <div class="preview-empty">
                                <i class="fas fa-images"></i>
                                Las propiedades que agregues aparecerán aquí
                            </div>
                        <?php else: ?>
                            <?php foreach ($destacadas as $idx => $prev): 
                                $img = getImagenHome($prev['imagen_principal'] ?? '');
                            ?>
                                <div class="preview-card">
                                    <div class="pos-badge"><?php echo $idx + 1; ?></div>
                                    <div class="img">
                                        <img src="<?php echo $img; ?>" 
                                             alt=""
                                             onerror="this.src='https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?w=200'">
                                        <div class="preview-badge">
                                            <i class="fas fa-house"></i>
                                        </div>
                                    </div>
                                    <div class="body">
                                        <div class="title" title="<?php echo htmlspecialchars($prev['title']); ?>">
                                            <?php echo htmlspecialchars($prev['title']); ?>
                                        </div>
                                        <div class="loc">
                                            <i class="fas fa-location-dot" style="font-size:0.55rem;"></i>
                                            <?php echo htmlspecialchars($prev['colonia'] ?? $prev['municipio'] ?? 'Sin ubicación'); ?>
                                        </div>
                                        <div class="price"><?php echo formatearPrecioHome($prev['price'] ?? null); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>
</main>

<div class="toast" id="toast"></div>

<script>
// ===== Menú móvil =====
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
    if (menuToggle) menuToggle.addEventListener('click', toggleSidebar);
    if (overlay) overlay.addEventListener('click', toggleSidebar);

    initDragDrop();

    const searchInput = document.getElementById('searchAvailable');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const q = this.value.toLowerCase().trim();
            document.querySelectorAll('.available-item').forEach(item => {
                const text = item.getAttribute('data-search') || '';
                item.style.display = text.includes(q) ? '' : 'none';
            });
        });
    }
});

// ===== Toast =====
let toastTimer = null;
function showToast(msg, type = 'success') {
    const toast = document.getElementById('toast');
    const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
    toast.innerHTML = `<i class="fas ${icon}"></i> ${msg}`;
    toast.className = 'toast show ' + type;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('show'), 2600);
}

// ===== Detect touch device =====
const isTouch = window.matchMedia('(pointer: coarse)').matches || ('ontouchstart' in window);

// ===== Drag & Drop (solo desktop) =====
let draggedSlot = null;

function initDragDrop() {
    const slotsContainer = document.getElementById('slots');
    if (!slotsContainer) return;

    // En touch, deshabilitar dragstart
    if (isTouch) {
        slotsContainer.querySelectorAll('.slot.filled').forEach(el => {
            el.setAttribute('draggable', 'false');
        });
        return;
    }

    slotsContainer.addEventListener('dragstart', (e) => {
        const slot = e.target.closest('.slot.filled');
        if (!slot) return;
        draggedSlot = slot;
        slot.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
    });

    slotsContainer.addEventListener('dragend', () => {
        if (draggedSlot) draggedSlot.classList.remove('dragging');
        draggedSlot = null;
        document.querySelectorAll('.slot.drag-over').forEach(s => s.classList.remove('drag-over'));
    });

    slotsContainer.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        const target = e.target.closest('.slot');
        if (!target || target === draggedSlot) return;
        document.querySelectorAll('.slot.drag-over').forEach(s => s.classList.remove('drag-over'));
        target.classList.add('drag-over');
    });

    slotsContainer.addEventListener('dragleave', (e) => {
        const target = e.target.closest('.slot');
        if (target) target.classList.remove('drag-over');
    });

    slotsContainer.addEventListener('drop', (e) => {
        e.preventDefault();
        const target = e.target.closest('.slot');
        if (!target || !draggedSlot || target === draggedSlot) return;
        target.classList.remove('drag-over');

        const parent = draggedSlot.parentNode;
        const targetIndex = [...parent.children].indexOf(target);
        const draggedIndex = [...parent.children].indexOf(draggedSlot);

        if (draggedIndex < targetIndex) {
            parent.insertBefore(draggedSlot, target.nextSibling);
        } else {
            parent.insertBefore(draggedSlot, target);
        }
        refrescarPosiciones();
    });
}

// ===== Refrescar números de posición =====
function refrescarPosiciones() {
    const slots = document.querySelectorAll('#slots .slot');
    slots.forEach((slot, idx) => {
        const posEl = slot.querySelector('.slot-position');
        if (posEl) posEl.textContent = idx + 1;
        slot.setAttribute('data-position', idx + 1);
        // Actualiza badge en la thumb (para móvil)
        const thumb = slot.querySelector('.slot-thumb');
        if (thumb && thumb.hasAttribute('data-pos')) {
            thumb.setAttribute('data-pos', idx + 1);
        }
    });
}

// ===== Mover arriba / abajo =====
function moverArriba(btn) {
    const slot = btn.closest('.slot');
    const prev = slot.previousElementSibling;
    if (prev) {
        slot.parentNode.insertBefore(slot, prev);
        refrescarPosiciones();
        actualizarBotonesFlechas();
    }
}

function moverAbajo(btn) {
    const slot = btn.closest('.slot');
    const next = slot.nextElementSibling;
    if (next) {
        slot.parentNode.insertBefore(next, slot);
        refrescarPosiciones();
        actualizarBotonesFlechas();
    }
}

function actualizarBotonesFlechas() {
    const slots = [...document.querySelectorAll('#slots .slot.filled')];
    slots.forEach((slot, idx) => {
        const btns = slot.querySelectorAll('.slot-btn');
        if (btns[0]) btns[0].disabled = idx === 0;
        if (btns[1]) btns[1].disabled = idx === slots.length - 1;
    });
}

// ===== Guardar orden =====
async function guardarOrden() {
    const ids = [...document.querySelectorAll('#slots .slot.filled')]
        .map(slot => slot.getAttribute('data-id'))
        .filter(Boolean);

    const btn = document.getElementById('btnGuardar');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';

    try {
        const fd = new FormData();
        fd.append('accion', 'reorder');
        fd.append('ids', JSON.stringify(ids));

        const res = await fetch(window.location.pathname, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();

        if (data.ok) {
            showToast(data.message || 'Orden guardado', 'success');
        } else {
            showToast(data.message || 'Error al guardar', 'error');
        }
    } catch (err) {
        console.error(err);
        showToast('Error de conexión', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Guardar Orden';
    }
}

// ===== Agregar al home =====
async function agregarAlHome(id, btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    try {
        const fd = new FormData();
        fd.append('accion', 'toggle');
        fd.append('id', id);
        fd.append('value', 1);

        const res = await fetch(window.location.pathname, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();

        if (data.ok) {
            showToast('Propiedad agregada. Recargando...', 'success');
            setTimeout(() => location.reload(), 500);
        } else {
            showToast(data.message || 'Error', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-plus"></i>';
        }
    } catch (err) {
        console.error(err);
        showToast('Error de conexión', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-plus"></i>';
    }
}

// ===== Quitar del home =====
async function quitarDelHome(id, btn) {
    if (!confirm('¿Quitar esta propiedad del home?\n\nSeguirá publicada, solo dejará de aparecer en el landing.')) return;

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    try {
        const fd = new FormData();
        fd.append('accion', 'toggle');
        fd.append('id', id);
        fd.append('value', 0);

        const res = await fetch(window.location.pathname, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();

        if (data.ok) {
            showToast('Propiedad quitada. Recargando...', 'success');
            setTimeout(() => location.reload(), 500);
        } else {
            showToast(data.message || 'Error', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-times"></i>';
        }
    } catch (err) {
        console.error(err);
        showToast('Error de conexión', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-times"></i>';
    }
}

// ===== Orden automático =====
async function restaurarOrden() {
    if (!confirm('¿Restaurar el orden automáticamente?\n\nSe ordenará por: activas primero, luego apartadas, luego vendidas, y por fecha de creación (más recientes primero).')) return;

    try {
        const fd = new FormData();
        fd.append('accion', 'reset');

        const res = await fetch(window.location.pathname, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();

        if (data.ok) {
            showToast('Orden restaurado. Recargando...', 'success');
            setTimeout(() => location.reload(), 500);
        } else {
            showToast(data.message || 'Error', 'error');
        }
    } catch (err) {
        console.error(err);
        showToast('Error de conexión', 'error');
    }
}
</script>

</body>
</html>