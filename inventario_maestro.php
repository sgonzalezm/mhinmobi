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

// ===== Vista seleccionada =====
$vista = $_GET['vista'] ?? 'activas';
if (!in_array($vista, ['activas', 'vendidas', 'todas'])) {
    $vista = 'activas';
}

// ============================================================
// ===== PROCESAR GUARDADO (POST) =============================
// ============================================================
$mensaje_exito = '';
$error_msg = '';

// --- Asignar asesor (desde modal) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'asignar') {
    // 🔒 CAMBIO PERMISOS: solo admin puede asignar
    if (!$es_admin) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?vista=' . urlencode($vista) . '&err=permiso');
        exit;
    }
    $propertyId = (int)($_POST['property_id'] ?? 0);
    $asesorId   = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;

    if ($propertyId > 0) {
        try {
            $stmt = $conn->prepare("UPDATE properties SET assigned_to = :asesor WHERE id = :id");
            $stmt->execute([':asesor' => $asesorId, ':id' => $propertyId]);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?vista=' . urlencode($vista) . '&ok=asesor');
            exit;
        } catch (PDOException $e) {
            error_log("Error asignando asesor: " . $e->getMessage());
            $error_msg = "Error al asignar asesor: " . $e->getMessage();
        }
    }
}

// --- Cambiar publicación (desde checkbox del grid) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'publicar') {
    // 🔒 CAMBIO PERMISOS: solo admin puede publicar/despublicar
    if (!$es_admin) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?vista=' . urlencode($vista) . '&err=permiso');
        exit;
    }
    $propertyId = (int)($_POST['property_id'] ?? 0);
    $publica    = (int)($_POST['publica'] ?? 0);

    if ($propertyId > 0) {
        try {
            $stmt = $conn->prepare("UPDATE properties SET publica = :publica WHERE id = :id");
            $stmt->execute([':publica' => $publica, ':id' => $propertyId]);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?vista=' . urlencode($vista) . '&ok=publica');
            exit;
        } catch (PDOException $e) {
            error_log("Error cambiando publicación: " . $e->getMessage());
            $error_msg = "Error al cambiar publicación: " . $e->getMessage();
        }
    }
}

// Mensajes de éxito tras redirect
if (isset($_GET['ok'])) {
    if ($_GET['ok'] === 'asesor')   $mensaje_exito = 'Asesor asignado correctamente.';
    if ($_GET['ok'] === 'publica')  $mensaje_exito = 'Estado de publicación actualizado.';
}

// 🔒 CAMBIO PERMISOS: mensaje si intentó hacer algo sin permiso
if (isset($_GET['err']) && $_GET['err'] === 'permiso') {
    $error_msg = 'No tienes permisos para realizar esta acción. Solo administradores.';
}

// ============================================================
// ===== CARGAR ASESORES (una sola vez) =======================
// ============================================================
$asesores = [];
try {
    $stmt = $conn->prepare("
        SELECT id, name, email 
        FROM users 
        WHERE LOWER(role) IN ('asesor','asesores','agente','agentes')
        ORDER BY name ASC
    ");
    $stmt->execute();
    $asesores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error cargando asesores: " . $e->getMessage());
}

// ============================================================
// ===== CARGAR PROPIEDADES ===================================
// ============================================================
$propiedades = [];

try {
    $checkProperties = $conn->query("SHOW TABLES LIKE 'properties'");
    $checkFinancial  = $conn->query("SHOW TABLES LIKE 'property_financials'");

    if ($checkProperties->rowCount() == 0) {
        $error_msg = "La tabla 'properties' no existe en la base de datos.";
    } elseif ($checkFinancial->rowCount() == 0) {
        $error_msg = "La tabla 'property_financials' no existe en la base de datos.";
    } else {
        $whereSql = '';
        if ($vista === 'activas') {
            $whereSql = "WHERE p.status = 'activo'";
        } elseif ($vista === 'vendidas') {
            $whereSql = "WHERE p.status = 'vendido'";
        }

        $stmt = $conn->prepare("
            SELECT 
                p.id,
                p.title,
                p.operation_type,
                p.municipio,
                p.estado,
                p.colonia,
                p.domicilio,
                p.status,
                p.created_at,
                p.assigned_to,
                p.publica,
                u.name AS asesor_nombre,
                f.asking_price as price,
                f.min_acceptable_price,
                f.potential_profit_margin,
                m.file_path as image_url,
                m.is_primary as is_primary_image
            FROM properties p
            LEFT JOIN property_financials f ON p.id = f.property_id
            LEFT JOIN property_media m ON p.id = m.property_id AND m.is_primary = 1
            LEFT JOIN users u ON p.assigned_to = u.id
            $whereSql
            ORDER BY p.created_at DESC
            LIMIT 100
        ");
        $stmt->execute();
        $propiedades = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fallback de imagen
        if (!empty($propiedades)) {
            foreach ($propiedades as $key => $prop) {
                if (empty($prop['image_url'])) {
                    $stmtImg = $conn->prepare("SELECT file_path FROM property_media WHERE property_id = ? LIMIT 1");
                    $stmtImg->execute([$prop['id']]);
                    $img = $stmtImg->fetch(PDO::FETCH_ASSOC);
                    if ($img) $propiedades[$key]['image_url'] = $img['file_path'];
                }
            }
        }

        if (empty($propiedades) && empty($error_msg)) {
            if ($vista === 'activas')       $error_msg = "No hay propiedades activas en el sistema.";
            elseif ($vista === 'vendidas')  $error_msg = "Aún no hay propiedades vendidas en el historial.";
            else                            $error_msg = "No hay propiedades registradas en el sistema.";
        }
    }
} catch (PDOException $e) {
    $error_msg = "Error al cargar propiedades: " . $e->getMessage();
    error_log("Error en inventario_maestro.php: " . $e->getMessage());
}

// ===== Contadores =====
$countActivas = $countVendidas = $countTodas = 0;
try {
    $countActivas  = (int)$conn->query("SELECT COUNT(*) FROM properties WHERE status = 'activo'")->fetchColumn();
    $countVendidas = (int)$conn->query("SELECT COUNT(*) FROM properties WHERE status = 'vendido'")->fetchColumn();
    $countTodas    = (int)$conn->query("SELECT COUNT(*) FROM properties")->fetchColumn();
} catch (PDOException $e) {
    error_log("Error contadores: " . $e->getMessage());
}

// ===== Estadísticas =====
$stats = [
    'total' => count($propiedades),
    'venta' => 0, 'renta' => 0,
    'con_precio' => 0, 'sin_precio' => 0,
    'con_imagen' => 0, 'sin_imagen' => 0
];
foreach ($propiedades as $p) {
    if (isset($p['price']) && $p['price'] > 0) $stats['con_precio']++; else $stats['sin_precio']++;
    if (!empty($p['image_url'])) $stats['con_imagen']++; else $stats['sin_imagen']++;
    if (isset($p['operation_type'])) {
        $opType = strtolower(trim($p['operation_type']));
        if ($opType === 'venta' || $opType === 'compra') $stats['venta']++;
        if ($opType === 'renta' || $opType === 'alquiler') $stats['renta']++;
    }
}

// ===== Helpers =====
function formatearPrecio($precio) {
    if ($precio === null || $precio === '' || $precio == 0) return 'Precio no disponible';
    return '$' . number_format(floatval($precio), 0, ',', '.');
}
function getOperationBadge($operationType) {
    $opType = strtolower(trim($operationType ?? ''));
    if ($opType === 'venta' || $opType === 'compra')   return ['class' => 'venta',   'label' => 'Venta'];
    if ($opType === 'renta' || $opType === 'alquiler') return ['class' => 'renta',   'label' => 'Renta'];
    return ['class' => 'general', 'label' => 'General'];
}
function getImagePath($imageUrl) {
    if (empty($imageUrl)) return '';
    if (strpos($imageUrl, 'uploads/') === 0) return htmlspecialchars($imageUrl);
    return 'uploads/propiedades/' . htmlspecialchars($imageUrl);
}
function getStatusBadge($status) {
    $status = strtolower(trim($status ?? ''));
    if ($status === 'activo')   return ['class' => 'status-active',   'label' => 'Activo'];
    if ($status === 'inactivo') return ['class' => 'status-inactive', 'label' => 'Inactivo'];
    if (in_array($status, ['finalizada','finalizado','vendido','vendida'])) {
        return ['class' => 'status-sold', 'label' => 'Finalizada'];
    }
    return ['class' => 'status-other', 'label' => ucfirst($status)];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#1d4ed8">
    <link rel="stylesheet" href="css/socios.css">
    <title>Inventario de Propiedades | Vera Terra</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.4.0/exceljs.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>

    <style>
        * { box-sizing: border-box; }

        html, body { overflow-x: hidden; max-width: 100vw; }
        img { max-width: 100%; height: auto; }

        /* ========== ESTILOS BASE (desktop) ========== */
        .properties-table-wrapper {
            overflow-x: auto; border-radius: 10px;
            border: 1px solid #e2e8f0; background: #fff;
        }
        .properties-table {
            width: 100%; border-collapse: collapse; font-size: 0.82rem; min-width: 1100px;
        }
        .properties-table thead th {
            background: #f8fafc; color: #334155; font-weight: 700;
            text-transform: uppercase; font-size: 0.68rem; letter-spacing: 0.5px;
            padding: 10px 12px; text-align: left; border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }
        .properties-table tbody td {
            padding: 10px 12px; border-bottom: 1px solid #f1f5f9;
            color: #0f172a; vertical-align: middle;
        }
        .properties-table tbody tr:hover { background: #f8faff; }
        .properties-table tbody tr:last-child td { border-bottom: none; }

        .col-img { width: 60px; text-align: center; }
        .col-img .thumb {
            width: 48px; height: 48px; border-radius: 6px; overflow: hidden;
            background: #f1f5f9; display: flex; align-items: center;
            justify-content: center; margin: 0 auto; position: relative;
        }
        .col-img .thumb img { width: 100%; height: 100%; object-fit: cover; }
        .col-img .thumb .no-image { color: #94a3b8; font-size: 1.1rem; }

        .col-title { min-width: 180px; max-width: 260px; font-weight: 600; }
        .col-title .op-badge {
            display: inline-block; font-size: 0.55rem; font-weight: 700;
            padding: 1px 6px; border-radius: 4px; text-transform: uppercase;
            letter-spacing: 0.3px; color: #fff; margin-right: 6px; vertical-align: middle;
        }
        .op-badge.venta { background: #10b981; }
        .op-badge.renta { background: #3b82f6; }
        .op-badge.general { background: #6b7280; }

        .status-pill {
            display: inline-block; font-size: 0.65rem; font-weight: 700;
            padding: 2px 10px; border-radius: 12px; text-transform: uppercase;
            letter-spacing: 0.3px; white-space: nowrap;
        }
        .status-pill.status-active { background: #dcfce7; color: #166534; }
        .status-pill.status-inactive { background: #fee2e2; color: #991b1b; }
        .status-pill.status-sold { background: #fef3c7; color: #92400e; }
        .status-pill.status-other { background: #f1f5f9; color: #475569; }

        .col-price {
            text-align: right; font-weight: 700; font-variant-numeric: tabular-nums;
            white-space: nowrap; color: #0f172a;
        }
        .col-price.no-price { color: #94a3b8; font-weight: 500; font-size: 0.75rem; }

        .col-actions { text-align: center; white-space: nowrap; }
        .action-btn {
            width: 34px; height: 34px; border: none; background: transparent;
            color: #64748b; border-radius: 8px; cursor: pointer;
            transition: all 0.15s; font-size: 0.85rem;
            -webkit-tap-highlight-color: transparent;
        }
        .action-btn:hover, .action-btn:focus { background: #f1f5f9; color: #0f172a; }
        .action-btn.view:hover { background: #dbeafe; color: #1d4ed8; }
        .action-btn.assign:hover { background: #e0e7ff; color: #4338ca; }

        .table-toolbar {
            display: flex; justify-content: space-between; align-items: center;
            gap: 12px; padding: 12px 16px; border-bottom: 1px solid #e2e8f0;
            background: #fff; flex-wrap: wrap;
        }
        .table-toolbar .toolbar-left { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .table-toolbar .toolbar-left h3 { margin: 0; font-size: 0.9rem; color: #0f172a; }
        .table-toolbar .search-box { display: flex; gap: 8px; flex-wrap: wrap; }
        .table-toolbar .search-box input,
        .table-toolbar .search-box select {
            padding: 7px 12px; border: 1px solid #cbd5e1; border-radius: 8px;
            font-size: 0.8rem; outline: none; transition: border 0.15s;
        }
        .table-toolbar .search-box input:focus,
        .table-toolbar .search-box select:focus { border-color: #1d4ed8; }
        .btn-excel {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px; background: #16a34a; color: #fff; border: none;
            border-radius: 8px; font-size: 0.78rem; font-weight: 600;
            cursor: pointer; transition: background 0.15s;
        }
        .btn-excel:hover { background: #15803d; }
        .btn-excel:disabled { background: #94a3b8; cursor: not-allowed; }

        .message-box {
            padding: 12px 16px; border-radius: 8px; margin: 8px 0;
            display: flex; align-items: center; gap: 10px; font-size: 0.85rem;
        }
        .message-box.info { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
        .message-box.warning { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
        .message-box.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .message-box.success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .message-box i { font-size: 1rem; }

        .view-tabs {
            display: flex; gap: 6px; margin: 0 0 12px 0; padding: 4px;
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
        .view-tab.portal-link { margin-left: auto; background: #1d4ed8; color: #fff; }
        .view-tab.portal-link:hover { background: #1e40af; color: #fff; }

        .export-overlay {
            position: fixed; inset: 0; background: rgba(15, 23, 42, 0.5);
            display: none; align-items: center; justify-content: center; z-index: 9999;
        }
        .export-overlay.show { display: flex; }
        .export-box {
            background: #fff; padding: 24px 32px; border-radius: 12px;
            display: flex; align-items: center; gap: 14px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            margin: 0 20px;
        }
        .export-box i { font-size: 1.6rem; color: #16a34a; }
        .export-box span { font-size: 0.9rem; font-weight: 600; color: #0f172a; }

        .table-container { overflow-x: auto; }

        .asesor-badge {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 0.72rem; font-weight: 600; padding: 3px 9px;
            border-radius: 12px; background: #e0e7ff; color: #3730a3;
            white-space: nowrap;
        }
        .asesor-badge.empty { background: #f1f5f9; color: #94a3b8; font-weight: 500; }

        .col-publica { text-align: center; }
        .check-publica-form { display: inline-block; margin: 0; }
        .check-publica {
            appearance: none; -webkit-appearance: none;
            width: 40px; height: 22px; background: #cbd5e1;
            border-radius: 11px; position: relative; cursor: pointer;
            transition: background 0.2s; outline: none; border: none; vertical-align: middle;
            -webkit-tap-highlight-color: transparent;
        }
        .check-publica::after {
            content: ''; position: absolute; top: 2px; left: 2px;
            width: 18px; height: 18px; background: #fff; border-radius: 50%;
            transition: transform 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.15);
        }
        .check-publica:checked { background: #16a34a; }
        .check-publica:checked::after { transform: translateX(18px); }
        .check-publica:disabled { opacity: 0.5; cursor: wait; }

        .publica-locked {
            display: inline-flex; align-items: center; justify-content: center;
            width: 40px; height: 22px; background: #f1f5f9;
            border-radius: 11px; color: #94a3b8; font-size: 0.75rem;
            cursor: not-allowed; opacity: 0.6;
        }

        /* ========== MODAL ========== */
        .modal-overlay {
            position: fixed; inset: 0; background: rgba(15, 23, 42, 0.55);
            display: none; align-items: center; justify-content: center;
            z-index: 10000; padding: 20px;
        }
        .modal-overlay.show { display: flex; }
        .modal-box {
            background: #fff; border-radius: 14px; width: 100%; max-width: 440px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.25);
            animation: modalIn 0.2s ease;
            max-height: calc(100vh - 40px); overflow-y: auto;
        }
        @keyframes modalIn {
            from { transform: translateY(-20px); opacity: 0; }
            to   { transform: translateY(0);     opacity: 1; }
        }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 16px 20px; border-bottom: 1px solid #e2e8f0;
            position: sticky; top: 0; background: #fff; z-index: 2;
            border-radius: 14px 14px 0 0;
        }
        .modal-header h3 { margin: 0; font-size: 1rem; color: #0f172a; }
        .modal-close {
            background: transparent; border: none; color: #94a3b8;
            font-size: 1.1rem; cursor: pointer; padding: 8px 12px; border-radius: 6px;
            min-width: 44px; min-height: 44px;
            -webkit-tap-highlight-color: transparent;
        }
        .modal-close:hover { background: #f1f5f9; color: #0f172a; }

        .modal-body { padding: 20px; }
        .modal-property-title {
            font-size: 0.82rem; color: #475569; background: #f8fafc;
            padding: 8px 12px; border-radius: 8px; margin: 0 0 16px 0;
            border-left: 3px solid #1d4ed8; word-break: break-word;
        }
        .form-group { margin-bottom: 0; }
        .form-group label {
            display: block; font-size: 0.78rem; font-weight: 600;
            color: #334155; margin-bottom: 6px;
        }
        .form-group select {
            width: 100%; padding: 12px 12px; border: 1px solid #cbd5e1;
            border-radius: 8px; font-size: 0.95rem; outline: none; background: #fff;
        }
        .form-group select:focus { border-color: #1d4ed8; }
        .form-help { display: block; font-size: 0.72rem; color: #94a3b8; margin-top: 6px; }

        .modal-footer {
            display: flex; justify-content: flex-end; gap: 10px;
            padding: 14px 20px; border-top: 1px solid #e2e8f0;
            position: sticky; bottom: 0; background: #fff;
            border-radius: 0 0 14px 14px;
        }
        .btn-modal {
            padding: 12px 20px; border-radius: 8px; font-size: 0.85rem;
            font-weight: 600; cursor: pointer; border: none; transition: all 0.15s;
            min-height: 44px; -webkit-tap-highlight-color: transparent;
        }
        .btn-modal.secondary { background: #f1f5f9; color: #334155; }
        .btn-modal.secondary:hover { background: #e2e8f0; }
        .btn-modal.primary { background: #1d4ed8; color: #fff; }
        .btn-modal.primary:hover { background: #1e40af; }

        /* ========== RESPONSIVE (TABLET) ========== */
        @media (max-width: 992px) {
            .table-toolbar { flex-direction: column; align-items: stretch; }
            .table-toolbar .search-box { width: 100%; }
            .table-toolbar .search-box input { flex: 1; min-width: 0; }
        }

        /* ========== RESPONSIVE (MÓVIL) — Tabla → Cards ========== */
        @media (max-width: 768px) {
            .properties-table-wrapper { border: none; background: transparent; }

            /* Ocultar cabecera */
            .properties-table thead { display: none; }

            /* Cada fila es una tarjeta */
            .properties-table,
            .properties-table tbody,
            .properties-table tr,
            .properties-table td {
                display: block;
                width: 100%;
                min-width: 0;
            }
            .properties-table { min-width: 0; font-size: 0.85rem; }

            .properties-table tbody tr {
                background: #fff;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                margin-bottom: 12px;
                padding: 12px;
                position: relative;
                box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
            }
            .properties-table tbody tr:hover { background: #fff; }

            .properties-table tbody td {
                border-bottom: none;
                padding: 6px 0;
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 12px;
                text-align: right;
            }
            .properties-table tbody td::before {
                content: attr(data-label);
                font-size: 0.7rem;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.4px;
                color: #64748b;
                text-align: left;
                flex: 0 0 auto;
                padding-right: 8px;
            }

            /* Imagen destacada arriba, ancho completo */
            .properties-table td.col-img {
                justify-content: center;
                padding: 0 0 12px 0;
                border-bottom: 1px solid #f1f5f9;
                margin-bottom: 8px;
            }
            .properties-table td.col-img::before { display: none; }
            .properties-table td.col-img .thumb {
                width: 100%;
                height: 160px;
                border-radius: 10px;
            }
            .properties-table td.col-img .thumb .no-image { font-size: 2rem; }

            /* Título sin label, destacado */
            .properties-table td.col-title {
                display: block;
                text-align: left;
                font-size: 0.95rem;
                padding-bottom: 10px;
            }
            .properties-table td.col-title::before { display: none; }

            /* Precio alineado a la derecha, sin label */
            .properties-table td.col-price {
                display: block;
                text-align: right;
                font-size: 1.05rem;
                padding-top: 6px;
                padding-bottom: 10px;
                border-top: 1px dashed #e2e8f0;
                margin-top: 6px;
            }
            .properties-table td.col-price::before { display: none; }

            /* Publicada: el toggle a la derecha con label */
            .properties-table td.col-publica { justify-content: space-between; }

            /* Acciones en fila completa, botones grandes */
            .properties-table td.col-actions {
                display: flex;
                justify-content: flex-end;
                gap: 10px;
                padding-top: 10px;
                border-top: 1px solid #f1f5f9;
                margin-top: 8px;
            }
            .properties-table td.col-actions::before { display: none; }
            .action-btn {
                width: 44px; height: 44px;
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                font-size: 1rem;
            }
            .action-btn.view { color: #1d4ed8; }
            .action-btn.assign { color: #4338ca; }

            /* Mensajes */
            .message-box { font-size: 0.8rem; padding: 10px 12px; }

            /* Tabs adaptadas */
            .view-tabs { padding: 4px; gap: 4px; }
            .view-tab { flex: 1 1 calc(50% - 4px); justify-content: center; padding: 10px 8px; font-size: 0.78rem; }
            .view-tab.portal-link { flex: 1 1 100%; margin-left: 0; margin-top: 4px; }

            /* Toolbar */
            .table-toolbar { padding: 10px 12px; }
            .table-toolbar .search-box input,
            .table-toolbar .search-box select { font-size: 16px; /* evita zoom iOS */ width: 100%; }
            .btn-excel { width: 100%; justify-content: center; padding: 12px; min-height: 44px; }

            /* Modal ocupa más pantalla */
            .modal-overlay { padding: 0; align-items: flex-end; }
            .modal-box {
                max-width: 100%;
                border-radius: 16px 16px 0 0;
                max-height: 92vh;
                animation: modalInMobile 0.25s ease;
            }
            @keyframes modalInMobile {
                from { transform: translateY(100%); }
                to   { transform: translateY(0); }
            }
            .modal-header { border-radius: 16px 16px 0 0; }
            .modal-footer { border-radius: 0; }
            .modal-footer .btn-modal { flex: 1; }
        }

        /* ========== EXTRA PEQUEÑOS ========== */
        @media (max-width: 400px) {
            .properties-table tbody td { font-size: 0.82rem; }
            .properties-table td.col-title { font-size: 0.9rem; }
            .properties-table td.col-price { font-size: 1rem; }
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
            <h1>Inventario Maestro - Propiedades</h1>
            <p class="welcome">
                <i class="fas fa-building"></i> Gestión y disponibilidad general de inmuebles
            </p>
        </div>
        <div class="header-actions">
            <button class="btn-header primary" onclick="nuevaPropiedad()">
                <i class="fas fa-plus"></i> Nueva Propiedad
            </button>
        </div>
    </div>

    <div class="view-tabs">
        <a href="?vista=activas" class="view-tab <?php echo $vista === 'activas' ? 'active' : ''; ?>">
            <i class="fas fa-home"></i> Activas
            <span class="tab-count"><?php echo $countActivas; ?></span>
        </a>
        <a href="?vista=vendidas" class="view-tab <?php echo $vista === 'vendidas' ? 'active' : ''; ?>">
            <i class="fas fa-check-circle"></i> Historial / Vendidas
            <span class="tab-count"><?php echo $countVendidas; ?></span>
        </a>
        <a href="?vista=todas" class="view-tab <?php echo $vista === 'todas' ? 'active' : ''; ?>">
            <i class="fas fa-list"></i> Todas
            <span class="tab-count"><?php echo $countTodas; ?></span>
        </a>
        <a href="mapa_ventas.php" class="view-tab portal-link">
            <i class="fas fa-map-marked-alt"></i> Portal de Métricas
        </a>
    </div>

    <div class="table-container">
        <div class="table-toolbar">
            <div class="toolbar-left">
                <h3><i class="fas fa-list-ul"></i> Listado de Inmuebles</h3>
            </div>
            <div class="search-box">
                <input type="text" placeholder="Buscar por municipio o título..." id="searchTable">
                <select id="filterOperation">
                    <option value="">Todas las operaciones</option>
                    <option value="venta">Venta</option>
                    <option value="compra">Compra</option>
                </select>
                <button class="btn-excel" id="btnExportar" onclick="exportarExcel()">
                    <i class="fas fa-file-excel"></i> Exportar Excel
                </button>
            </div>
        </div>

        <div style="padding: 16px 20px;">

            <?php if (!empty($mensaje_exito)): ?>
                <div class="message-box success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($mensaje_exito); ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_msg)): ?>
                <div class="message-box error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error_msg); ?></span>
                </div>
            <?php endif; ?>

            <!-- 🔒 CAMBIO PERMISOS: aviso modo lectura para no-admins -->
            <?php if (!$es_admin): ?>
                <div class="message-box info">
                    <i class="fas fa-lock"></i>
                    <span>
                        Estás viendo el inventario en modo lectura. Solo administradores pueden 
                        <strong>asignar asesores</strong> y <strong>cambiar la publicación</strong> de propiedades.
                    </span>
                </div>
            <?php endif; ?>

            <?php if (!empty($propiedades) && $stats['sin_precio'] > 0): ?>
                <div class="message-box warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Hay <?php echo $stats['sin_precio']; ?> propiedad(es) sin precio asignado.</span>
                </div>
            <?php endif; ?>

            <?php if (!empty($propiedades) && $stats['sin_imagen'] > 0): ?>
                <div class="message-box info">
                    <i class="fas fa-info-circle"></i>
                    <span>Hay <?php echo $stats['sin_imagen']; ?> propiedad(es) sin imagen principal.</span>
                </div>
            <?php endif; ?>

            <?php if (empty($propiedades) && empty($error_msg)): ?>
                <div class="empty-state" style="text-align: center; padding: 40px;">
                    <i class="fas fa-home" style="font-size: 3rem; color: #94a3b8; margin-bottom: 15px;"></i>
                    <h3>No hay propiedades registradas</h3>
                </div>
            <?php elseif (!empty($propiedades)): ?>
                <div class="properties-table-wrapper">
                    <table class="properties-table" id="propertiesTable">
                        <thead>
                            <tr>
                                <th class="col-img">Imagen</th>
                                <th class="col-title">Título</th>
                                <th>Status</th>
                                <th>Estado Geo.</th>
                                <th>Municipio</th>
                                <th>Colonia</th>
                                <th>Dirección</th>
                                <th style="text-align:right;">Precio</th>
                                <th>Asesor</th>
                                <th style="text-align:center;">Publicada</th>
                                <th class="col-actions">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($propiedades as $propiedad): 
                                $badge       = getOperationBadge($propiedad['operation_type'] ?? '');
                                $statusBadge = getStatusBadge($propiedad['status'] ?? '');
                                $price       = $propiedad['price'] ?? null;
                                $hasPrice    = ($price !== null && $price > 0);
                                $priceClass  = $hasPrice ? '' : 'no-price';
                                $priceText   = $hasPrice ? formatearPrecio($price) : 'Sin precio';
                                $imagePath   = getImagePath($propiedad['image_url'] ?? '');
                                $hasImage    = !empty($imagePath);
                                $title       = htmlspecialchars($propiedad['title'] ?? 'Sin título');
                                $domicilio   = htmlspecialchars($propiedad['domicilio'] ?? 'No especificado');
                                $estadoGeo   = htmlspecialchars($propiedad['estado'] ?? 'No especificado');
                                $municipio   = htmlspecialchars($propiedad['municipio'] ?? 'No especificado');
                                $colonia     = htmlspecialchars($propiedad['colonia'] ?? 'No especificada');
                                $opType      = strtolower(trim($propiedad['operation_type'] ?? ''));
                                $statusLower = strtolower(trim($propiedad['status'] ?? ''));
                                $esVendida   = in_array($statusLower, ['vendido', 'vendida']);

                                $assignedTo   = (int)($propiedad['assigned_to'] ?? 0);
                                $asesorNombre = $propiedad['asesor_nombre'] ?? '';
                                $publica      = (int)($propiedad['publica'] ?? 0);

                                // Título escapado para JS
                                $tituloJs = str_replace(["\\", "'"], ["\\\\", "\\'"], $propiedad['title'] ?? 'Sin título');
                            ?>
                                <tr data-text="<?php echo strtolower($title . ' ' . $municipio . ' ' . $domicilio . ' ' . $colonia . ' ' . $estadoGeo); ?>"
                                    data-operation="<?php echo $opType; ?>">
                                    <td class="col-img" data-label="">
                                        <div class="thumb">
                                            <?php if ($hasImage): ?>
                                                <img src="<?php echo $imagePath; ?>" 
                                                     alt="<?php echo $title; ?>" 
                                                     loading="lazy"
                                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                <div class="no-image" style="display:none;"><i class="fas fa-image"></i></div>
                                            <?php else: ?>
                                                <div class="no-image"><i class="fas fa-building"></i></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="col-title" data-label="Título" data-raw-title="<?php echo $title; ?>">
                                        <span class="op-badge <?php echo $badge['class']; ?>" data-exclude="true"><?php echo $badge['label']; ?></span>
                                        <?php echo $title; ?>
                                        <?php if ($esVendida): ?>
                                            <i class="fas fa-flag-checkered" style="color:#92400e; margin-left:4px;" title="Histórica"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Status">
                                        <span class="status-pill <?php echo $statusBadge['class']; ?>">
                                            <?php echo $statusBadge['label']; ?>
                                        </span>
                                    </td>
                                    <td data-label="Estado"><?php echo $estadoGeo; ?></td>
                                    <td data-label="Municipio"><?php echo $municipio; ?></td>
                                    <td data-label="Colonia"><?php echo $colonia; ?></td>
                                    <td data-label="Dirección"><?php echo $domicilio; ?></td>
                                    <td class="col-price <?php echo $priceClass; ?>" 
                                        data-label="Precio"
                                        data-raw-price="<?php echo $hasPrice ? floatval($price) : ''; ?>">
                                        <?php echo $priceText; ?>
                                    </td>
                                    <td class="col-asesor" data-label="Asesor">
                                        <?php if (!empty($asesorNombre)): ?>
                                            <span class="asesor-badge">
                                                <i class="fas fa-user-tie"></i>
                                                <?php echo htmlspecialchars($asesorNombre); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="asesor-badge empty">
                                                <i class="fas fa-user-slash"></i> Sin asignar
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 🔒 CAMBIO PERMISOS: checkbox solo para admin -->
                                    <td class="col-publica" data-label="Publicada">
                                        <?php if ($es_admin): ?>
                                            <form method="POST" class="check-publica-form" 
                                                  onsubmit="return confirmarPublicacion(this);">
                                                <input type="hidden" name="accion" value="publicar">
                                                <input type="hidden" name="property_id" value="<?php echo (int)$propiedad['id']; ?>">
                                                <input type="hidden" name="publica" value="<?php echo $publica ? '0' : '1'; ?>">
                                                <input type="checkbox" class="check-publica" 
                                                       <?php echo $publica ? 'checked' : ''; ?>
                                                       onchange="this.form.submit()"
                                                       title="<?php echo $publica ? 'Publicada - clic para despublicar' : 'No publicada - clic para publicar'; ?>">
                                            </form>
                                        <?php else: ?>
                                            <span class="publica-locked" title="Solo administradores pueden cambiar la publicación">
                                                <i class="fas <?php echo $publica ? 'fa-globe' : 'fa-eye-slash'; ?>"></i>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 🔒 CAMBIO PERMISOS: botón asignar solo para admin -->
                                    <td class="col-actions" data-label="">
                                        <button class="action-btn view" title="Ver detalles" 
                                                onclick="verPropiedad('<?php echo (int)$propiedad['id']; ?>')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if ($es_admin): ?>
                                            <button class="action-btn assign" 
                                                    title="Asignar asesor"
                                                    onclick="abrirModalAsignar(<?php echo (int)$propiedad['id']; ?>, '<?php echo $tituloJs; ?>', <?php echo $assignedTo; ?>)">
                                                <i class="fas fa-user-plus"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- ===== MODAL ASIGNAR ASESOR (solo asesor) ===== -->
<?php if ($es_admin): ?>
<div class="modal-overlay" id="modalAsignar">
    <div class="modal-box">
        <form method="POST" id="formAsignar">
            <input type="hidden" name="accion" value="asignar">
            <input type="hidden" name="property_id" id="modalPropertyId">

            <div class="modal-header">
                <h3><i class="fas fa-user-tie"></i> Asignar Asesor</h3>
                <button type="button" class="modal-close" onclick="cerrarModalAsignar()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p class="modal-property-title" id="modalPropertyTitle"></p>

                <div class="form-group">
                    <label for="modalAsesor">Asesor asignado</label>
                    <select id="modalAsesor" name="assigned_to">
                        <option value="">— Sin asignar —</option>
                        <?php foreach ($asesores as $a): ?>
                            <option value="<?php echo (int)$a['id']; ?>">
                                <?php echo htmlspecialchars($a['name']); ?>
                                <?php echo !empty($a['email']) ? '(' . htmlspecialchars($a['email']) . ')' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-help">El asesor verá esta propiedad en su panel y se calculará su comisión.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-modal secondary" onclick="cerrarModalAsignar()">Cancelar</button>
                <button type="submit" class="btn-modal primary">
                    <i class="fas fa-save"></i> Guardar
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Overlay exportación -->
<div class="export-overlay" id="exportOverlay">
    <div class="export-box">
        <i class="fas fa-file-excel fa-spin"></i>
        <span>Generando archivo Excel...</span>
    </div>
</div>

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

    document.querySelectorAll('.sidebar nav a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 992 && sidebar && sidebar.classList.contains('open')) {
                toggleSidebar();
            }
        });
    });

    // Cerrar modal con Escape
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') cerrarModalAsignar();
    });

    const searchInput = document.getElementById('searchTable');
    const filterOperation = document.getElementById('filterOperation');
    if (searchInput && filterOperation) {
        searchInput.addEventListener('keyup', filtrarFilas);
        filterOperation.addEventListener('change', filtrarFilas);
    }
    function filtrarFilas() {
        const searchText = (searchInput.value || '').toLowerCase().trim();
        const operationVal = (filterOperation.value || '').toLowerCase().trim();
        document.querySelectorAll('.properties-table tbody tr').forEach(row => {
            const rowText = (row.getAttribute('data-text') || '').toLowerCase();
            const rowOp = (row.getAttribute('data-operation') || '').toLowerCase();
            const matchesSearch = rowText.includes(searchText);
            const matchesOp = operationVal === '' || rowOp === operationVal;
            row.style.display = (matchesSearch && matchesOp) ? '' : 'none';
        });
    }

    window.nuevaPropiedad = function() { window.location.href = 'vender.php'; };
    window.verPropiedad   = function(id) { window.location.href = 'propiedad_detalle_inventario.php?id=' + id; };
});

// ===== Modal solo para asesor (solo se usa si es admin) =====
function abrirModalAsignar(id, titulo, assignedTo) {
    const modal = document.getElementById('modalAsignar');
    if (!modal) return; // 🔒 CAMBIO PERMISOS: si no es admin, el modal no existe
    document.getElementById('modalPropertyId').value = id;
    document.getElementById('modalPropertyTitle').textContent = '📌 ' + titulo;
    document.getElementById('modalAsesor').value = assignedTo > 0 ? String(assignedTo) : '';
    modal.classList.add('show');
    document.body.style.overflow = 'hidden'; // 🔒 evita scroll de fondo en móvil
}
function cerrarModalAsignar() {
    const modal = document.getElementById('modalAsignar');
    if (modal) {
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
}
document.getElementById('modalAsignar')?.addEventListener('click', function(e) {
    if (e.target === this) cerrarModalAsignar();
});

// ===== Confirmación de publicación =====
function confirmarPublicacion(form) {
    const accion = form.querySelector('input[name="publica"]').value === '1' ? 'publicar' : 'despublicar';
    return confirm(`¿Seguro que quieres ${accion} esta propiedad en el sitio público?`);
}

// ===== Exportar Excel =====
window.exportarExcel = async function() {
    const tabla = document.getElementById('propertiesTable');
    if (!tabla) { alert('No hay datos para exportar.'); return; }
    if (typeof ExcelJS === 'undefined' || typeof saveAs === 'undefined') {
        alert('Las librerías de exportación no se cargaron.'); return;
    }
    const overlay = document.getElementById('exportOverlay');
    const btnExportar = document.getElementById('btnExportar');
    if (overlay) overlay.classList.add('show');
    if (btnExportar) btnExportar.disabled = true;

    try {
        const filas = [];
        const headers = [];
        tabla.querySelectorAll('thead th').forEach((th, idx, arr) => {
            if (idx < arr.length - 1) headers.push(th.innerText.trim().toUpperCase());
        });

        tabla.querySelectorAll('tbody tr').forEach(tr => {
            const celdas = tr.querySelectorAll('td');
            const fila = [];
            celdas.forEach((td, idx) => {
                if (idx < celdas.length - 1) {
                    const rawPrice = td.getAttribute('data-raw-price');
                    if (rawPrice !== null && rawPrice !== '' && !isNaN(parseFloat(rawPrice))) {
                        fila.push({ tipo: 'numero', valor: parseFloat(rawPrice) });
                        return;
                    }
                    if (rawPrice !== null && rawPrice === '') {
                        fila.push({ tipo: 'texto', valor: 'Sin precio' });
                        return;
                    }
                    const rawTitle = td.getAttribute('data-raw-title');
                    if (rawTitle !== null) {
                        fila.push({ tipo: 'texto', valor: rawTitle.trim() });
                        return;
                    }
                    if (td.classList.contains('col-publica')) {
                        const chk = td.querySelector('.check-publica');
                        // 🔒 CAMBIO PERMISOS: si no hay checkbox, leer el ícono bloqueado
                        if (chk) {
                            fila.push({ tipo: 'texto', valor: chk.checked ? 'Sí' : 'No' });
                        } else {
                            const icon = td.querySelector('.publica-locked i');
                            const esPublica = icon && icon.classList.contains('fa-globe');
                            fila.push({ tipo: 'texto', valor: esPublica ? 'Sí' : 'No' });
                        }
                        return;
                    }
                    const clon = td.cloneNode(true);
                    clon.querySelectorAll('[data-exclude="true"]').forEach(el => el.remove());
                    clon.querySelectorAll('i.fa').forEach(el => el.remove());
                    clon.querySelectorAll('form').forEach(el => el.remove());
                    let texto = clon.innerText.trim().replace(/\s+/g, ' ');
                    fila.push({ tipo: 'texto', valor: texto });
                }
            });
            filas.push(fila);
        });

        if (filas.length === 0) { alert('No hay datos para exportar.'); return; }

        const workbook = new ExcelJS.Workbook();
        workbook.creator = 'Vera Terra Inmobiliaria';
        workbook.created = new Date();
        const worksheet = workbook.addWorksheet('Inventario', { views: [{ state: 'frozen', ySplit: 4 }] });

        const anchos = headers.map((h, colIdx) => {
            let maxLen = h.length;
            filas.forEach(fila => {
                const cell = fila[colIdx];
                if (!cell) return;
                let texto = cell.tipo === 'numero' ? '$' + cell.valor.toLocaleString('en-US') : String(cell.valor || '');
                if (texto.length > maxLen) maxLen = texto.length;
            });
            let ancho = maxLen + 4;
            if (ancho < 12) ancho = 12;
            if (ancho > 55) ancho = 55;
            return ancho;
        });
        worksheet.columns = anchos.map(w => ({ width: w }));

        try {
            const logoUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/') + 'css/Logo1_veraterra.png';
            const resp = await fetch(logoUrl);
            if (resp.ok) {
                const blob = await resp.blob();
                const arrayBuffer = await blob.arrayBuffer();
                const imageId = workbook.addImage({ buffer: arrayBuffer, extension: 'png' });
                worksheet.addImage(imageId, { tl: { col: 0, row: 0 }, ext: { width: 110, height: 60 } });
            }
        } catch (e) { console.warn('Logo:', e); }

        const totalCols = headers.length;
        const ultimaColLetra = String.fromCharCode(64 + totalCols);

        worksheet.mergeCells(`B1:${ultimaColLetra}1`);
        worksheet.getCell('B1').value = 'Vera Terra Inmobiliaria';
        worksheet.getCell('B1').font = { name: 'Calibri', size: 18, bold: true, color: { argb: 'FF1D4ED8' } };
        worksheet.getCell('B1').alignment = { vertical: 'middle', horizontal: 'left' };

        worksheet.mergeCells(`B2:${ultimaColLetra}2`);
        worksheet.getCell('B2').value = 'Inventario Maestro de Propiedades';
        worksheet.getCell('B2').font = { name: 'Calibri', size: 12, color: { argb: 'FF475569' } };
        worksheet.getCell('B2').alignment = { vertical: 'middle', horizontal: 'left' };

        worksheet.mergeCells(`B3:${ultimaColLetra}3`);
        const fecha = new Date().toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' });
        worksheet.getCell('B3').value = `Generado el ${fecha}`;
        worksheet.getCell('B3').font = { name: 'Calibri', size: 9, italic: true, color: { argb: 'FF64748B' } };
        worksheet.getCell('B3').alignment = { vertical: 'middle', horizontal: 'left' };

        worksheet.getRow(1).height = 24;
        worksheet.getRow(2).height = 20;
        worksheet.getRow(3).height = 16;
        worksheet.getRow(4).height = 22;

        const headerRow = worksheet.getRow(4);
        headers.forEach((h, i) => {
            const cell = headerRow.getCell(i + 1);
            cell.value = h;
            cell.font = { name: 'Calibri', size: 10, bold: true, color: { argb: 'FFFFFFFF' } };
            cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF1D4ED8' } };
            cell.alignment = { vertical: 'middle', horizontal: 'left' };
            cell.border = {
                top:    { style: 'thin', color: { argb: 'FF1E40AF' } },
                left:   { style: 'thin', color: { argb: 'FF1E40AF' } },
                bottom: { style: 'thin', color: { argb: 'FF1E40AF' } },
                right:  { style: 'thin', color: { argb: 'FF1E40AF' } }
            };
        });

        filas.forEach((fila, rowIdx) => {
            const row = worksheet.getRow(5 + rowIdx);
            const esPar = rowIdx % 2 === 0;
            fila.forEach((celda, colIdx) => {
                const cell = row.getCell(colIdx + 1);
                if (celda.tipo === 'numero') {
                    cell.value = celda.valor;
                    cell.numFmt = '"$"#,##0';
                    cell.alignment = { vertical: 'middle', horizontal: 'right' };
                    cell.font = { name: 'Calibri', size: 10, bold: true, color: { argb: 'FF0F172A' } };
                } else {
                    cell.value = celda.valor || '';
                    if (String(celda.valor).toLowerCase().includes('sin precio')) {
                        cell.font = { name: 'Calibri', size: 10, italic: true, color: { argb: 'FF94A3B8' } };
                        cell.alignment = { vertical: 'middle', horizontal: 'right' };
                    } else {
                        cell.font = { name: 'Calibri', size: 10, color: { argb: 'FF0F172A' } };
                        cell.alignment = { vertical: 'middle', horizontal: 'left', wrapText: false };
                    }
                }
                cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: esPar ? 'FFF8FAFC' : 'FFFFFFFF' } };
                cell.border = {
                    top:    { style: 'thin', color: { argb: 'FFE2E8F0' } },
                    left:   { style: 'thin', color: { argb: 'FFE2E8F0' } },
                    bottom: { style: 'thin', color: { argb: 'FFE2E8F0' } },
                    right:  { style: 'thin', color: { argb: 'FFE2E8F0' } }
                };
            });
            row.height = 20;
        });

        worksheet.autoFilter = { from: { row: 4, column: 1 }, to: { row: 4, column: headers.length } };

        const buffer = await workbook.xlsx.writeBuffer();
        const blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
        saveAs(blob, `Inventario_Propiedades_${new Date().toISOString().slice(0, 10)}.xlsx`);

    } catch (error) {
        console.error('Error al exportar:', error);
        alert('Ocurrió un error al generar el archivo Excel.');
    } finally {
        if (overlay) overlay.classList.remove('show');
        if (btnExportar) btnExportar.disabled = false;
    }
};
</script>

</body>
</html>