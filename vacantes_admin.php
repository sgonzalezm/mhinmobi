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
if (!in_array($vista, ['activas', 'inactivas', 'todas'])) {
    $vista = 'activas';
}

// ============================================================
// ===== PROCESAR ACCIONES (POST) =============================
// ============================================================
$mensaje_exito = '';
$error_msg = '';

// --- Guardar (crear o editar) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {
    if (!$es_admin) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?vista=' . urlencode($vista) . '&err=permiso');
        exit;
    }

    $id                  = (int)($_POST['id'] ?? 0);
    $titulo              = trim($_POST['titulo'] ?? '');
    $area                = trim($_POST['area'] ?? '');
    $modalidad           = trim($_POST['modalidad'] ?? '');
    $ubicacion           = trim($_POST['ubicacion'] ?? '');
    $descripcion_corta   = trim($_POST['descripcion_corta'] ?? '');
    $descripcion_completa= trim($_POST['descripcion_completa'] ?? '');
    $responsabilidades   = trim($_POST['responsabilidades'] ?? '');
    $requisitos          = trim($_POST['requisitos'] ?? '');
    $experiencia         = trim($_POST['experiencia'] ?? '');
    $salario_min         = ($_POST['salario_min'] ?? '') !== '' ? (float)$_POST['salario_min'] : null;
    $salario_max         = ($_POST['salario_max'] ?? '') !== '' ? (float)$_POST['salario_max'] : null;
    $activo              = (int)($_POST['activo'] ?? 1);

    if ($titulo === '') {
        $error_msg = 'El título de la vacante es obligatorio.';
    } else {
        try {
            if ($id > 0) {
                $sql = "UPDATE vacantes SET
                            titulo = :titulo,
                            area = :area,
                            modalidad = :modalidad,
                            ubicacion = :ubicacion,
                            descripcion_corta = :descripcion_corta,
                            descripcion_completa = :descripcion_completa,
                            responsabilidades = :responsabilidades,
                            requisitos = :requisitos,
                            experiencia = :experiencia,
                            salario_min = :salario_min,
                            salario_max = :salario_max,
                            activo = :activo
                        WHERE id = :id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':titulo' => $titulo,
                    ':area' => $area,
                    ':modalidad' => $modalidad,
                    ':ubicacion' => $ubicacion,
                    ':descripcion_corta' => $descripcion_corta,
                    ':descripcion_completa' => $descripcion_completa,
                    ':responsabilidades' => $responsabilidades,
                    ':requisitos' => $requisitos,
                    ':experiencia' => $experiencia,
                    ':salario_min' => $salario_min,
                    ':salario_max' => $salario_max,
                    ':activo' => $activo,
                    ':id' => $id
                ]);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?vista=' . urlencode($vista) . '&ok=actualizada');
                exit;
            } else {
                $sql = "INSERT INTO vacantes
                            (titulo, area, modalidad, ubicacion,
                             descripcion_corta, descripcion_completa,
                             responsabilidades, requisitos, experiencia,
                             salario_min, salario_max, activo, created_at)
                        VALUES
                            (:titulo, :area, :modalidad, :ubicacion,
                             :descripcion_corta, :descripcion_completa,
                             :responsabilidades, :requisitos, :experiencia,
                             :salario_min, :salario_max, :activo, NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':titulo' => $titulo,
                    ':area' => $area,
                    ':modalidad' => $modalidad,
                    ':ubicacion' => $ubicacion,
                    ':descripcion_corta' => $descripcion_corta,
                    ':descripcion_completa' => $descripcion_completa,
                    ':responsabilidades' => $responsabilidades,
                    ':requisitos' => $requisitos,
                    ':experiencia' => $experiencia,
                    ':salario_min' => $salario_min,
                    ':salario_max' => $salario_max,
                    ':activo' => $activo
                ]);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?vista=' . urlencode($vista) . '&ok=creada');
                exit;
            }
        } catch (PDOException $e) {
            error_log("Error guardando vacante: " . $e->getMessage());
            $error_msg = 'Error al guardar la vacante: ' . $e->getMessage();
        }
    }
}

// --- Cambiar estado activo/inactivo (toggle) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'toggle') {
    if (!$es_admin) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?vista=' . urlencode($vista) . '&err=permiso');
        exit;
    }
    $id     = (int)($_POST['id'] ?? 0);
    $activo = (int)($_POST['activo'] ?? 0);
    if ($id > 0) {
        try {
            $stmt = $conn->prepare("UPDATE vacantes SET activo = :activo WHERE id = :id");
            $stmt->execute([':activo' => $activo, ':id' => $id]);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?vista=' . urlencode($vista) . '&ok=estado');
            exit;
        } catch (PDOException $e) {
            error_log("Error cambiando estado: " . $e->getMessage());
            $error_msg = 'Error al cambiar el estado de la vacante.';
        }
    }
}

// --- Eliminar ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {
    if (!$es_admin) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?vista=' . urlencode($vista) . '&err=permiso');
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $stmt = $conn->prepare("DELETE FROM vacantes WHERE id = :id");
            $stmt->execute([':id' => $id]);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?vista=' . urlencode($vista) . '&ok=eliminada');
            exit;
        } catch (PDOException $e) {
            error_log("Error eliminando vacante: " . $e->getMessage());
            $error_msg = 'Error al eliminar la vacante.';
        }
    }
}

// Mensajes de éxito
if (isset($_GET['ok'])) {
    switch ($_GET['ok']) {
        case 'creada':      $mensaje_exito = 'Vacante creada correctamente.'; break;
        case 'actualizada': $mensaje_exito = 'Vacante actualizada correctamente.'; break;
        case 'estado':      $mensaje_exito = 'Estado de la vacante actualizado.'; break;
        case 'eliminada':   $mensaje_exito = 'Vacante eliminada correctamente.'; break;
    }
}
if (isset($_GET['err']) && $_GET['err'] === 'permiso') {
    $error_msg = 'No tienes permisos para realizar esta acción. Solo administradores.';
}

// ============================================================
// ===== CARGAR VACANTES ======================================
// ============================================================
$vacantes = [];
try {
    $check = $conn->query("SHOW TABLES LIKE 'vacantes'");
    if ($check->rowCount() == 0) {
        $error_msg = "La tabla 'vacantes' no existe en la base de datos.";
    } else {
        $whereSql = '';
        if ($vista === 'activas')        $whereSql = "WHERE v.activo = 1";
        elseif ($vista === 'inactivas')  $whereSql = "WHERE v.activo = 0";

        $stmt = $conn->prepare("
            SELECT 
                v.id, v.titulo, v.area, v.modalidad, v.ubicacion,
                v.descripcion_corta, v.descripcion_completa,
                v.responsabilidades, v.requisitos,
                v.salario_min, v.salario_max, v.experiencia,
                v.activo, v.created_at, v.updated_at
            FROM vacantes v
            $whereSql
            ORDER BY v.created_at DESC
            LIMIT 200
        ");
        $stmt->execute();
        $vacantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $error_msg = "Error al cargar vacantes: " . $e->getMessage();
    error_log("Error en admin_vacantes.php: " . $e->getMessage());
}

// ===== Contadores =====
$countActivas = $countInactivas = $countTodas = 0;
try {
    $countActivas   = (int)$conn->query("SELECT COUNT(*) FROM vacantes WHERE activo = 1")->fetchColumn();
    $countInactivas = (int)$conn->query("SELECT COUNT(*) FROM vacantes WHERE activo = 0")->fetchColumn();
    $countTodas     = (int)$conn->query("SELECT COUNT(*) FROM vacantes")->fetchColumn();
} catch (PDOException $e) {
    error_log("Error contadores: " . $e->getMessage());
}

// ===== Helpers =====
function getModalidadLabel($modalidad) {
    $labels = [
        'tiempo_completo' => 'Tiempo completo',
        'medio_tiempo'    => 'Medio tiempo',
        'por_proyecto'    => 'Por proyecto',
        'practicante'     => 'Practicante',
        'freelance'       => 'Freelance',
    ];
    return $labels[$modalidad] ?? ucfirst(str_replace('_', ' ', (string)$modalidad));
}
function getAreaLabel($area) {
    $labels = [
        'ventas'         => 'Ventas',
        'administracion' => 'Administración',
        'marketing'      => 'Marketing',
        'operaciones'    => 'Operaciones',
        'atencion'       => 'Atención a clientes',
        'legal'          => 'Legal',
        'tecnologia'     => 'Tecnología',
    ];
    return $labels[$area] ?? ucfirst((string)$area);
}
function formatearSalarioRango($min, $max) {
    if (empty($min) && empty($max)) return 'A convenir';
    if (!empty($min) && !empty($max)) {
        return '$' . number_format((float)$min, 0, ',', '.') . ' - $' . number_format((float)$max, 0, ',', '.');
    }
    if (!empty($min)) return 'Desde $' . number_format((float)$min, 0, ',', '.');
    return 'Hasta $' . number_format((float)$max, 0, ',', '.');
}
function tiempoPublicadoAdmin($fecha) {
    if (empty($fecha)) return '';
    $diff = time() - strtotime($fecha);
    $dias = floor($diff / 86400);
    if ($dias <= 0) return 'Hoy';
    if ($dias == 1) return 'Hace 1 día';
    if ($dias < 30) return "Hace $dias días";
    $meses = floor($dias / 30);
    return $meses == 1 ? 'Hace 1 mes' : "Hace $meses meses";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/socios.css">
    <title>Gestión de Vacantes | Vera Terra</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.4.0/exceljs.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>

    <style>
        * { box-sizing: border-box; }

        /* ===== TABS ===== */
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

        /* ===== TABLA ===== */
        .vacantes-table-wrapper {
            overflow-x: auto; border-radius: 10px;
            border: 1px solid #e2e8f0; background: #fff;
        }
        .vacantes-table {
            width: 100%; border-collapse: collapse; font-size: 0.82rem; min-width: 1200px;
        }
        .vacantes-table thead th {
            background: #f8fafc; color: #334155; font-weight: 700;
            text-transform: uppercase; font-size: 0.68rem; letter-spacing: 0.5px;
            padding: 10px 12px; text-align: left; border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }
        .vacantes-table tbody td {
            padding: 10px 12px; border-bottom: 1px solid #f1f5f9;
            color: #0f172a; vertical-align: middle;
        }
        .vacantes-table tbody tr:hover { background: #f8faff; }
        .vacantes-table tbody tr:last-child td { border-bottom: none; }

        .col-title { min-width: 200px; max-width: 300px; font-weight: 600; }
        .col-title .area-badge {
            display: inline-block; font-size: 0.58rem; font-weight: 700;
            padding: 2px 7px; border-radius: 4px; text-transform: uppercase;
            letter-spacing: 0.3px; color: #fff; margin-right: 6px; vertical-align: middle;
            background: #c5a059;
        }
        .col-ubicacion { max-width: 180px; font-size: 0.78rem; color: #475569; }
        .col-salario {
            text-align: right; font-weight: 700; font-variant-numeric: tabular-nums;
            white-space: nowrap; color: #0f172a;
        }
        .col-salario.no-price { color: #94a3b8; font-weight: 500; font-size: 0.75rem; }
        .col-fecha { font-size: 0.75rem; color: #64748b; white-space: nowrap; }
        .col-actions { text-align: center; white-space: nowrap; }

        .action-btn {
            width: 30px; height: 30px; border: none; background: transparent;
            color: #94a3b8; border-radius: 6px; cursor: pointer;
            transition: all 0.15s; font-size: 0.8rem;
        }
        .action-btn:hover { background: #f1f5f9; color: #0f172a; }
        .action-btn.edit:hover { background: #dbeafe; color: #1d4ed8; }
        .action-btn.delete:hover { background: #fee2e2; color: #dc2626; }
        .action-btn.view:hover { background: #e0e7ff; color: #4338ca; }

        /* ===== TOOLBAR ===== */
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

        /* ===== MENSAJES ===== */
        .message-box {
            padding: 12px 16px; border-radius: 8px; margin: 8px 0;
            display: flex; align-items: center; gap: 10px; font-size: 0.85rem;
        }
        .message-box.info { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
        .message-box.warning { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
        .message-box.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .message-box.success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .message-box i { font-size: 1rem; }

        /* ===== SWITCH ACTIVO ===== */
        .col-activo { text-align: center; }
        .check-activo-form { display: inline-block; margin: 0; }
        .check-activo {
            appearance: none; -webkit-appearance: none;
            width: 40px; height: 22px; background: #cbd5e1;
            border-radius: 11px; position: relative; cursor: pointer;
            transition: background 0.2s; outline: none; border: none;
            vertical-align: middle;
        }
        .check-activo::after {
            content: ''; position: absolute; top: 2px; left: 2px;
            width: 18px; height: 18px; background: #fff; border-radius: 50%;
            transition: transform 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.15);
        }
        .check-activo:checked { background: #16a34a; }
        .check-activo:checked::after { transform: translateX(18px); }
        .check-activo:disabled { opacity: 0.5; cursor: wait; }

        .activo-locked {
            display: inline-flex; align-items: center; justify-content: center;
            width: 40px; height: 22px; background: #f1f5f9; border-radius: 11px;
            color: #94a3b8; font-size: 0.75rem; cursor: not-allowed; opacity: 0.6;
        }

        /* ===== MODAL ===== */
        .modal-overlay {
            position: fixed; inset: 0; background: rgba(15, 23, 42, 0.55);
            display: none; align-items: flex-start; justify-content: center;
            z-index: 10000; padding: 30px 20px; overflow-y: auto;
        }
        .modal-overlay.show { display: flex; }
        .modal-box {
            background: #fff; border-radius: 14px; width: 100%; max-width: 720px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.25);
            animation: modalIn 0.2s ease;
            margin: auto;
        }
        @keyframes modalIn {
            from { transform: translateY(-20px); opacity: 0; }
            to   { transform: translateY(0);     opacity: 1; }
        }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 16px 22px; border-bottom: 1px solid #e2e8f0;
            position: sticky; top: 0; background: #fff; z-index: 1;
            border-radius: 14px 14px 0 0;
        }
        .modal-header h3 { margin: 0; font-size: 1rem; color: #0f172a; }
        .modal-close {
            background: transparent; border: none; color: #94a3b8;
            font-size: 1.1rem; cursor: pointer; padding: 6px 10px; border-radius: 6px;
        }
        .modal-close:hover { background: #f1f5f9; color: #0f172a; }

        .modal-body { padding: 22px; }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }
        .form-grid .full { grid-column: 1 / -1; }

        .form-group { margin-bottom: 0; }
        .form-group label {
            display: block; font-size: 0.78rem; font-weight: 600;
            color: #334155; margin-bottom: 6px;
        }
        .form-group label .req { color: #dc2626; }
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group select,
        .form-group textarea {
            width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1;
            border-radius: 8px; font-size: 0.85rem; outline: none;
            background: #fff; font-family: inherit;
            transition: border 0.15s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus { border-color: #1d4ed8; }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .form-help { display: block; font-size: 0.7rem; color: #94a3b8; margin-top: 5px; }

        .switch-row {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 0;
        }
        .switch-row label { margin: 0; cursor: pointer; font-size: 0.85rem; }

        .modal-footer {
            display: flex; justify-content: flex-end; gap: 10px;
            padding: 14px 22px; border-top: 1px solid #e2e8f0;
            position: sticky; bottom: 0; background: #fff;
            border-radius: 0 0 14px 14px;
        }
        .btn-modal {
            padding: 9px 20px; border-radius: 8px; font-size: 0.82rem;
            font-weight: 600; cursor: pointer; border: none; transition: all 0.15s;
        }
        .btn-modal.secondary { background: #f1f5f9; color: #334155; }
        .btn-modal.secondary:hover { background: #e2e8f0; }
        .btn-modal.primary { background: #1d4ed8; color: #fff; }
        .btn-modal.primary:hover { background: #1e40af; }

        /* ===== EXPORT OVERLAY ===== */
        .export-overlay {
            position: fixed; inset: 0; background: rgba(15, 23, 42, 0.5);
            display: none; align-items: center; justify-content: center; z-index: 9999;
        }
        .export-overlay.show { display: flex; }
        .export-box {
            background: #fff; padding: 24px 32px; border-radius: 12px;
            display: flex; align-items: center; gap: 14px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .export-box i { font-size: 1.6rem; color: #16a34a; }
        .export-box span { font-size: 0.9rem; font-weight: 600; color: #0f172a; }

        @media (max-width: 768px) {
            .table-toolbar { flex-direction: column; align-items: stretch; }
            .table-toolbar .search-box { width: 100%; }
            .table-toolbar .search-box input { flex: 1; }
            .form-grid { grid-template-columns: 1fr; }
            .view-tab.portal-link { margin-left: 0; }
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
            <h1>Gestión de Vacantes</h1>
            <p class="welcome">
                <i class="fas fa-briefcase"></i> Administración de oportunidades laborales
            </p>
        </div>
        <div class="header-actions">
            <?php if ($es_admin): ?>
                <button class="btn-header primary" onclick="abrirModalVacante()">
                    <i class="fas fa-plus"></i> Nueva Vacante
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="view-tabs">
        <a href="?vista=activas" class="view-tab <?php echo $vista === 'activas' ? 'active' : ''; ?>">
            <i class="fas fa-check-circle"></i> Activas
            <span class="tab-count"><?php echo $countActivas; ?></span>
        </a>
        <a href="?vista=inactivas" class="view-tab <?php echo $vista === 'inactivas' ? 'active' : ''; ?>">
            <i class="fas fa-eye-slash"></i> Inactivas
            <span class="tab-count"><?php echo $countInactivas; ?></span>
        </a>
        <a href="?vista=todas" class="view-tab <?php echo $vista === 'todas' ? 'active' : ''; ?>">
            <i class="fas fa-list"></i> Todas
            <span class="tab-count"><?php echo $countTodas; ?></span>
        </a>
        <a href="vacantes.php" target="_blank" class="view-tab portal-link">
            <i class="fas fa-external-link-alt"></i> Ver portal público
        </a>
    </div>

    <div class="table-container">
        <div class="table-toolbar">
            <div class="toolbar-left">
                <h3><i class="fas fa-list-ul"></i> Listado de Vacantes</h3>
            </div>
            <div class="search-box">
                <input type="text" placeholder="Buscar por título o ubicación..." id="searchTable">
                <select id="filterArea">
                    <option value="">Todas las áreas</option>
                    <option value="ventas">Ventas</option>
                    <option value="administracion">Administración</option>
                    <option value="marketing">Marketing</option>
                    <option value="operaciones">Operaciones</option>
                    <option value="atencion">Atención a clientes</option>
                    <option value="legal">Legal</option>
                    <option value="tecnologia">Tecnología</option>
                </select>
                <select id="filterModalidad">
                    <option value="">Todas las modalidades</option>
                    <option value="tiempo_completo">Tiempo completo</option>
                    <option value="medio_tiempo">Medio tiempo</option>
                    <option value="por_proyecto">Por proyecto</option>
                    <option value="practicante">Practicante</option>
                    <option value="freelance">Freelance</option>
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

            <?php if (!$es_admin): ?>
                <div class="message-box info">
                    <i class="fas fa-lock"></i>
                    <span>
                        Estás viendo las vacantes en modo lectura. Solo administradores pueden
                        <strong>crear</strong>, <strong>editar</strong>, <strong>activar/desactivar</strong> o <strong>eliminar</strong> vacantes.
                    </span>
                </div>
            <?php endif; ?>

            <?php if (!empty($vacantes)): ?>
                <?php
                    $sinSalario = 0;
                    $sinDesc    = 0;
                    foreach ($vacantes as $v) {
                        if (empty($v['salario_min']) && empty($v['salario_max'])) $sinSalario++;
                        if (empty($v['descripcion_completa'])) $sinDesc++;
                    }
                ?>
                <?php if ($sinSalario > 0): ?>
                    <div class="message-box warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>Hay <?php echo $sinSalario; ?> vacante(s) sin salario asignado.</span>
                    </div>
                <?php endif; ?>
                <?php if ($sinDesc > 0): ?>
                    <div class="message-box info">
                        <i class="fas fa-info-circle"></i>
                        <span>Hay <?php echo $sinDesc; ?> vacante(s) sin descripción completa.</span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (empty($vacantes) && empty($error_msg)): ?>
                <div class="empty-state" style="text-align: center; padding: 40px;">
                    <i class="fas fa-briefcase" style="font-size: 3rem; color: #94a3b8; margin-bottom: 15px;"></i>
                    <h3>No hay vacantes en esta vista</h3>
                    <p style="color: #64748b;">Crea la primera vacante o cambia de pestaña.</p>
                </div>
            <?php elseif (!empty($vacantes)): ?>
                <div class="vacantes-table-wrapper">
                    <table class="vacantes-table" id="vacantesTable">
                        <thead>
                            <tr>
                                <th class="col-title">Título</th>
                                <th>Área</th>
                                <th>Modalidad</th>
                                <th>Ubicación</th>
                                <th>Experiencia</th>
                                <th style="text-align:right;">Salario</th>
                                <th>Publicada</th>
                                <th style="text-align:center;">Activa</th>
                                <th class="col-actions">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vacantes as $v): 
                                $areaLabel      = getAreaLabel($v['area'] ?? '');
                                $modalidadLabel = getModalidadLabel($v['modalidad'] ?? '');
                                $salario        = formatearSalarioRango($v['salario_min'] ?? null, $v['salario_max'] ?? null);
                                $tieneSalario   = !empty($v['salario_min']) || !empty($v['salario_max']);
                                $salarioClass   = $tieneSalario ? '' : 'no-price';
                                $fechaPub       = tiempoPublicadoAdmin($v['created_at'] ?? '');
                                $activo         = (int)($v['activo'] ?? 0);

                                // Datos para el modal (JSON escapado)
                                $vacanteJson = htmlspecialchars(json_encode([
                                    'id' => (int)$v['id'],
                                    'titulo' => $v['titulo'] ?? '',
                                    'area' => $v['area'] ?? '',
                                    'modalidad' => $v['modalidad'] ?? '',
                                    'ubicacion' => $v['ubicacion'] ?? '',
                                    'descripcion_corta' => $v['descripcion_corta'] ?? '',
                                    'descripcion_completa' => $v['descripcion_completa'] ?? '',
                                    'responsabilidades' => $v['responsabilidades'] ?? '',
                                    'requisitos' => $v['requisitos'] ?? '',
                                    'experiencia' => $v['experiencia'] ?? '',
                                    'salario_min' => $v['salario_min'] ?? '',
                                    'salario_max' => $v['salario_max'] ?? '',
                                    'activo' => $activo
                                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
                            ?>
                                <tr data-text="<?php echo strtolower(htmlspecialchars(($v['titulo'] ?? '') . ' ' . ($v['ubicacion'] ?? '') . ' ' . $areaLabel)); ?>"
                                    data-area="<?php echo htmlspecialchars($v['area'] ?? ''); ?>"
                                    data-modalidad="<?php echo htmlspecialchars($v['modalidad'] ?? ''); ?>">

                                    <td class="col-title" data-raw-title="<?php echo htmlspecialchars($v['titulo'] ?? ''); ?>">
                                        <span class="area-badge"><?php echo htmlspecialchars($areaLabel); ?></span>
                                        <?php echo htmlspecialchars($v['titulo'] ?? 'Sin título'); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($areaLabel); ?></td>
                                    <td><?php echo htmlspecialchars($modalidadLabel); ?></td>
                                    <td class="col-ubicacion"><?php echo htmlspecialchars($v['ubicacion'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars($v['experiencia'] ?? '—'); ?></td>
                                    <td class="col-salario <?php echo $salarioClass; ?>"
                                        data-raw-salario-min="<?php echo !empty($v['salario_min']) ? (float)$v['salario_min'] : ''; ?>"
                                        data-raw-salario-max="<?php echo !empty($v['salario_max']) ? (float)$v['salario_max'] : ''; ?>">
                                        <?php echo $salario; ?>
                                    </td>
                                    <td class="col-fecha"><?php echo htmlspecialchars($fechaPub); ?></td>

                                    <td class="col-activo">
                                        <?php if ($es_admin): ?>
                                            <form method="POST" class="check-activo-form"
                                                  onsubmit="return confirmarEstado(this);">
                                                <input type="hidden" name="accion" value="toggle">
                                                <input type="hidden" name="id" value="<?php echo (int)$v['id']; ?>">
                                                <input type="hidden" name="activo" value="<?php echo $activo ? '0' : '1'; ?>">
                                                <input type="checkbox" class="check-activo"
                                                       <?php echo $activo ? 'checked' : ''; ?>
                                                       onchange="this.form.submit()"
                                                       title="<?php echo $activo ? 'Activa - clic para desactivar' : 'Inactiva - clic para activar'; ?>">
                                            </form>
                                        <?php else: ?>
                                            <span class="activo-locked" title="Solo administradores">
                                                <i class="fas <?php echo $activo ? 'fa-check' : 'fa-times'; ?>"></i>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="col-actions">
                                        <button class="action-btn view" title="Ver en portal"
                                                onclick="verEnPortal(<?php echo (int)$v['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if ($es_admin): ?>
                                            <button class="action-btn edit" title="Editar"
                                                    data-vacante="<?php echo $vacanteJson; ?>"
                                                    onclick="editarVacante(this)">
                                                <i class="fas fa-pen"></i>
                                            </button>
                                            <button class="action-btn delete" title="Eliminar"
                                                    onclick="eliminarVacante(<?php echo (int)$v['id']; ?>, '<?php echo htmlspecialchars(str_replace("'", "\\'", $v['titulo'] ?? ''), ENT_QUOTES); ?>')">
                                                <i class="fas fa-trash"></i>
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

<!-- ===== MODAL CREAR/EDITAR VACANTE (solo admin) ===== -->
<?php if ($es_admin): ?>
<div class="modal-overlay" id="modalVacante">
    <div class="modal-box">
        <form method="POST" id="formVacante">
            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="id" id="vacanteId" value="0">

            <div class="modal-header">
                <h3 id="modalVacanteTitulo"><i class="fas fa-plus-circle"></i> Nueva Vacante</h3>
                <button type="button" class="modal-close" onclick="cerrarModalVacante()">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="modal-body">
                <div class="form-grid">

                    <div class="form-group full">
                        <label for="f_titulo">Título de la vacante <span class="req">*</span></label>
                        <input type="text" id="f_titulo" name="titulo" required maxlength="200"
                               placeholder="Ej. Asesor Inmobiliario Senior">
                    </div>

                    <div class="form-group">
                        <label for="f_area">Área</label>
                        <select id="f_area" name="area">
                            <option value="">— Seleccionar —</option>
                            <option value="ventas">Ventas</option>
                            <option value="administracion">Administración</option>
                            <option value="marketing">Marketing</option>
                            <option value="operaciones">Operaciones</option>
                            <option value="atencion">Atención a clientes</option>
                            <option value="legal">Legal</option>
                            <option value="tecnologia">Tecnología</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="f_modalidad">Modalidad</label>
                        <select id="f_modalidad" name="modalidad">
                            <option value="">— Seleccionar —</option>
                            <option value="tiempo_completo">Tiempo completo</option>
                            <option value="medio_tiempo">Medio tiempo</option>
                            <option value="por_proyecto">Por proyecto</option>
                            <option value="practicante">Practicante</option>
                            <option value="freelance">Freelance</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="f_ubicacion">Ubicación</label>
                        <input type="text" id="f_ubicacion" name="ubicacion" maxlength="150"
                               placeholder="Ej. Guadalajara, Jalisco">
                    </div>

                    <div class="form-group">
                        <label for="f_experiencia">Experiencia requerida</label>
                        <input type="text" id="f_experiencia" name="experiencia" maxlength="100"
                               placeholder="Ej. 3+ años">
                    </div>

                    <div class="form-group">
                        <label for="f_salario_min">Salario mínimo (MXN)</label>
                        <input type="number" id="f_salario_min" name="salario_min" min="0" step="100"
                               placeholder="Ej. 25000">
                    </div>

                    <div class="form-group">
                        <label for="f_salario_max">Salario máximo (MXN)</label>
                        <input type="number" id="f_salario_max" name="salario_max" min="0" step="100"
                               placeholder="Ej. 45000">
                    </div>

                    <div class="form-group full">
                        <label for="f_descripcion_corta">Descripción corta</label>
                        <textarea id="f_descripcion_corta" name="descripcion_corta" maxlength="500" rows="2"
                                  placeholder="Resumen de 1-2 líneas que aparece en el listado público."></textarea>
                        <small class="form-help">Máximo 500 caracteres. Aparece en la tarjeta del listado público.</small>
                    </div>

                    <div class="form-group full">
                        <label for="f_descripcion_completa">Descripción completa</label>
                        <textarea id="f_descripcion_completa" name="descripcion_completa" rows="5"
                                  placeholder="Describe el puesto, el equipo, el contexto, etc."></textarea>
                    </div>

                    <div class="form-group full">
                        <label for="f_responsabilidades">Responsabilidades</label>
                        <textarea id="f_responsabilidades" name="responsabilidades" rows="5"
                                  placeholder="Escribe una responsabilidad por línea. Ejemplo:&#10;Prospectar y captar propiedades&#10;Atender clientes compradores"></textarea>
                        <small class="form-help">Una por línea. Se convierten automáticamente en lista con viñetas.</small>
                    </div>

                    <div class="form-group full">
                        <label for="f_requisitos">Requisitos</label>
                        <textarea id="f_requisitos" name="requisitos" rows="5"
                                  placeholder="Escribe un requisito por línea. Ejemplo:&#10;Licenciatura en Administración&#10;3 años de experiencia"></textarea>
                        <small class="form-help">Uno por línea. Se convierten automáticamente en lista con viñetas.</small>
                    </div>

                    <div class="form-group full">
                        <div class="switch-row">
                            <input type="checkbox" id="f_activo" name="activo" value="1" checked
                                   style="width:auto; transform: scale(1.3); margin-right: 6px;">
                            <label for="f_activo">
                                <strong>Vacante activa</strong> — visible en el portal público
                            </label>
                        </div>
                    </div>

                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-modal secondary" onclick="cerrarModalVacante()">Cancelar</button>
                <button type="submit" class="btn-modal primary">
                    <i class="fas fa-save"></i> Guardar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Formulario oculto para eliminar -->
<form method="POST" id="formEliminar" style="display:none;">
    <input type="hidden" name="accion" value="eliminar">
    <input type="hidden" name="id" id="eliminarId">
</form>
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

    // ===== Filtros =====
    const searchInput     = document.getElementById('searchTable');
    const filterArea      = document.getElementById('filterArea');
    const filterModalidad = document.getElementById('filterModalidad');

    function filtrarFilas() {
        const searchText = (searchInput?.value || '').toLowerCase().trim();
        const areaVal    = (filterArea?.value || '').toLowerCase().trim();
        const modVal     = (filterModalidad?.value || '').toLowerCase().trim();

        document.querySelectorAll('.vacantes-table tbody tr').forEach(row => {
            const rowText    = (row.getAttribute('data-text') || '').toLowerCase();
            const rowArea    = (row.getAttribute('data-area') || '').toLowerCase();
            const rowMod     = (row.getAttribute('data-modalidad') || '').toLowerCase();

            const matchesSearch = rowText.includes(searchText);
            const matchesArea    = areaVal === '' || rowArea === areaVal;
            const matchesMod     = modVal === '' || rowMod === modVal;

            row.style.display = (matchesSearch && matchesArea && matchesMod) ? '' : 'none';
        });
    }

    if (searchInput)     searchInput.addEventListener('keyup', filtrarFilas);
    if (filterArea)      filterArea.addEventListener('change', filtrarFilas);
    if (filterModalidad) filterModalidad.addEventListener('change', filtrarFilas);
});

// ===== Modal crear/editar =====
function abrirModalVacante() {
    const modal = document.getElementById('modalVacante');
    if (!modal) return;
    document.getElementById('formVacante').reset();
    document.getElementById('vacanteId').value = '0';
    document.getElementById('modalVacanteTitulo').innerHTML = '<i class="fas fa-plus-circle"></i> Nueva Vacante';
    document.getElementById('f_activo').checked = true;
    modal.classList.add('show');
}

function editarVacante(btn) {
    const modal = document.getElementById('modalVacante');
    if (!modal) return;

    let data;
    try {
        data = JSON.parse(btn.getAttribute('data-vacante'));
    } catch (e) {
        alert('Error al cargar los datos de la vacante.');
        return;
    }

    document.getElementById('vacanteId').value = data.id || 0;
    document.getElementById('f_titulo').value = data.titulo || '';
    document.getElementById('f_area').value = data.area || '';
    document.getElementById('f_modalidad').value = data.modalidad || '';
    document.getElementById('f_ubicacion').value = data.ubicacion || '';
    document.getElementById('f_experiencia').value = data.experiencia || '';
    document.getElementById('f_salario_min').value = data.salario_min || '';
    document.getElementById('f_salario_max').value = data.salario_max || '';
    document.getElementById('f_descripcion_corta').value = data.descripcion_corta || '';
    document.getElementById('f_descripcion_completa').value = data.descripcion_completa || '';
    document.getElementById('f_responsabilidades').value = data.responsabilidades || '';
    document.getElementById('f_requisitos').value = data.requisitos || '';
    document.getElementById('f_activo').checked = parseInt(data.activo || 0) === 1;

    document.getElementById('modalVacanteTitulo').innerHTML = '<i class="fas fa-pen"></i> Editar Vacante';
    modal.classList.add('show');
}

function cerrarModalVacante() {
    const modal = document.getElementById('modalVacante');
    if (modal) modal.classList.remove('show');
}
document.getElementById('modalVacante')?.addEventListener('click', function(e) {
    if (e.target === this) cerrarModalVacante();
});

// ===== Confirmaciones =====
function confirmarEstado(form) {
    const activar = form.querySelector('input[name="activo"]').value === '1';
    return confirm(`¿Seguro que quieres ${activar ? 'activar' : 'desactivar'} esta vacante?`);
}

function eliminarVacante(id, titulo) {
    if (!confirm(`¿Eliminar definitivamente la vacante "${titulo}"?\nEsta acción no se puede deshacer.`)) return;
    document.getElementById('eliminarId').value = id;
    document.getElementById('formEliminar').submit();
}

function verEnPortal(id) {
    window.open('vacante_detalle.php?id=' + id, '_blank');
}

// ===== Exportar Excel =====
window.exportarExcel = async function() {
    const tabla = document.getElementById('vacantesTable');
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
                    const rawSalMin = td.getAttribute('data-raw-salario-min');
                    const rawSalMax = td.getAttribute('data-raw-salario-max');
                    if (rawSalMin !== null || rawSalMax !== null) {
                        if (rawSalMin === '' && rawSalMax === '') {
                            fila.push({ tipo: 'texto', valor: 'A convenir' });
                        } else {
                            const min = rawSalMin ? parseFloat(rawSalMin) : null;
                            const max = rawSalMax ? parseFloat(rawSalMax) : null;
                            let txt = '';
                            if (min && max) txt = '$' + min.toLocaleString('en-US') + ' - $' + max.toLocaleString('en-US');
                            else if (min)    txt = 'Desde $' + min.toLocaleString('en-US');
                            else             txt = 'Hasta $' + max.toLocaleString('en-US');
                            fila.push({ tipo: 'texto', valor: txt });
                        }
                        return;
                    }
                    const rawTitle = td.getAttribute('data-raw-title');
                    if (rawTitle !== null) {
                        fila.push({ tipo: 'texto', valor: rawTitle.trim() });
                        return;
                    }
                    if (td.classList.contains('col-activo')) {
                        const chk = td.querySelector('.check-activo');
                        if (chk) {
                            fila.push({ tipo: 'texto', valor: chk.checked ? 'Sí' : 'No' });
                        } else {
                            const icon = td.querySelector('.activo-locked i');
                            const esActiva = icon && icon.classList.contains('fa-check');
                            fila.push({ tipo: 'texto', valor: esActiva ? 'Sí' : 'No' });
                        }
                        return;
                    }
                    if (td.classList.contains('col-actions')) {
                        fila.push({ tipo: 'texto', valor: '' });
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
        const worksheet = workbook.addWorksheet('Vacantes', { views: [{ state: 'frozen', ySplit: 4 }] });

        const anchos = headers.map((h, colIdx) => {
            let maxLen = h.length;
            filas.forEach(fila => {
                const cell = fila[colIdx];
                if (!cell) return;
                let texto = String(cell.valor || '');
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
        worksheet.getCell('B2').value = 'Gestión de Vacantes';
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
                cell.value = celda.valor || '';
                cell.font = { name: 'Calibri', size: 10, color: { argb: 'FF0F172A' } };
                cell.alignment = { vertical: 'middle', horizontal: 'left' };
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
        saveAs(blob, `Vacantes_${new Date().toISOString().slice(0, 10)}.xlsx`);

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