<?php
session_start();
require_once 'includes/conexion.php';
require_once 'includes/auth.php';

// Verificar autenticación
if (!estaLogueado()) {
    header('Location: login.php');
    exit;
}

// Obtener datos del usuario
$usuario = obtenerUsuarioActual($conn);
if (!$usuario) {
    cerrarSesion();
    header('Location: login.php');
    exit;
}

// Verificar si el usuario es administrador
$es_admin = esAdmin();

// Si no es admin, redirigir a dashboard
if (!$es_admin) {
    header('Location: vender.php');
    exit;
}

// Variables para mensajes
$mensaje = '';
$tipo_mensaje = '';

// Procesar acciones del formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    try {
        switch ($accion) {
            case 'crear_usuario':
                $datos = [
                    'name' => trim($_POST['name']),
                    'email' => trim($_POST['email']),
                    'telefono' => trim($_POST['telefono'] ?? ''),
                    'password' => $_POST['password'],
                    'role' => $_POST['role'],
                    'activo' => $_POST['activo'] ?? 1
                ];
                
                if (empty($datos['name']) || empty($datos['email']) || empty($datos['password'])) {
                    throw new Exception('Todos los campos obligatorios deben estar llenos');
                }
                
                if (!filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Email no válido');
                }
                
                if (strlen($datos['password']) < 6) {
                    throw new Exception('La contraseña debe tener al menos 6 caracteres');
                }
                
                $id = crearUsuario($conn, $datos);
                if (!$id) {
                    throw new Exception('Error al crear usuario. El email podría estar duplicado.');
                }
                
                $mensaje = 'Usuario creado exitosamente';
                $tipo_mensaje = 'success';
                break;
                
            case 'editar_usuario':
                $id = $_POST['usuario_id'];
                $datos = [
                    'name' => trim($_POST['name']),
                    'email' => trim($_POST['email']),
                    'telefono' => trim($_POST['telefono'] ?? ''),
                    'role' => $_POST['role'],
                    'activo' => $_POST['activo'] ?? 1
                ];
                
                if (!empty($_POST['nuevo_password'])) {
                    if (strlen($_POST['nuevo_password']) < 6) {
                        throw new Exception('La contraseña debe tener al menos 6 caracteres');
                    }
                    $datos['password'] = $_POST['nuevo_password'];
                }
                
                if (!actualizarUsuario($conn, $id, $datos)) {
                    throw new Exception('Error al actualizar usuario');
                }
                
                $mensaje = 'Usuario actualizado exitosamente';
                $tipo_mensaje = 'success';
                break;
                
            case 'toggle_usuario':
                $id = $_POST['usuario_id'];
                $nuevo_estado = $_POST['nuevo_estado'];
                
                if (!cambiarEstadoUsuario($conn, $id, $nuevo_estado)) {
                    throw new Exception('Error al cambiar estado');
                }
                
                $mensaje = 'Estado del usuario actualizado';
                $tipo_mensaje = 'success';
                break;
                
            case 'eliminar_usuario':
                $id = $_POST['usuario_id'];
                
                if ($id == $_SESSION['usuario_id']) {
                    throw new Exception('No puedes eliminar tu propia cuenta');
                }
                
                if (!eliminarUsuario($conn, $id)) {
                    throw new Exception('Error al eliminar usuario');
                }
                
                $mensaje = 'Usuario eliminado exitosamente';
                $tipo_mensaje = 'success';
                break;
        }
    } catch (Exception $e) {
        $mensaje = $e->getMessage();
        $tipo_mensaje = 'error';
    }
}

// Obtener lista de usuarios
$usuarios = obtenerTodosUsuarios($conn);

// Obtener usuario para editar (si se solicita)
$usuario_editar = null;
if (isset($_GET['editar']) && is_numeric($_GET['editar'])) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_GET['editar']]);
    $usuario_editar = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Gestión de Usuarios | Inmobiliaria MH</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="css/socios.css">
    <style>
        /* ===== RESET Y BASE ===== */
        * {
            box-sizing: border-box;
        }

        /* ===== HEADER ===== */
        .main-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            width: 100%;
        }

        .header-left h1 {
            font-size: 1.4rem;
            color: #2d3748;
            margin: 0;
            flex: 1;
        }

        .header-left .welcome {
            color: #718096;
            margin: 0;
            font-size: 0.85rem;
            display: none;
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #2d3748;
            cursor: pointer;
            padding: 5px;
            line-height: 1;
        }

        .header-actions {
            width: 100%;
            display: flex;
            gap: 10px;
        }

        .btn-header {
            padding: 12px 20px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
            width: 100%;
            min-height: 48px;
        }

        .btn-header.primary {
            background: linear-gradient(135deg, #4c51bf 0%, #3c41a8 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(76, 81, 191, 0.3);
        }

        .btn-header.primary:active {
            transform: scale(0.98);
            box-shadow: 0 2px 6px rgba(76, 81, 191, 0.2);
        }

        /* ===== MENSAJES ===== */
        .mensaje {
            padding: 14px 16px;
            border-radius: 10px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .mensaje.success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .mensaje.error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        /* ===== SECCIÓN DE USUARIOS ===== */
        .acceso-section {
            background: white;
            border-radius: 14px;
            padding: 18px;
            margin-bottom: 20px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }

        .acceso-section h3 {
            color: #2d3748;
            margin: 0 0 16px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1rem;
        }

        .acceso-section h3 .badge-count {
            background: #4c51bf;
            color: white;
            font-size: 0.75rem;
            padding: 2px 10px;
            border-radius: 20px;
            margin-left: auto;
            font-weight: 600;
        }

        /* ===== TABLA RESPONSIVE ===== */
        .tabla-usuarios {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            width: 100%;
        }

        .tabla-usuarios table {
            width: 100%;
            border-collapse: collapse;
        }

        .tabla-usuarios th {
            background: #f8fafc;
            padding: 12px 10px;
            text-align: left;
            font-weight: 600;
            color: #2d3748;
            border-bottom: 2px solid #e2e8f0;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .tabla-usuarios td {
            padding: 12px 10px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.85rem;
            vertical-align: middle;
        }

        .tabla-usuarios tr:active {
            background: #f8fafc;
        }

        /* Ocultar columnas en móvil */
        .col-id,
        .col-telefono,
        .col-fecha {
            display: none;
        }

        .user-cell {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .user-cell .name {
            font-weight: 600;
            color: #2d3748;
            font-size: 0.9rem;
        }

        .user-cell .email {
            color: #718096;
            font-size: 0.78rem;
        }

        .badge-rol {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .badge-rol.admin { background: #dbeafe; color: #1e40af; }
        .badge-rol.propietario { background: #d1fae5; color: #065f46; }
        .badge-rol.vendedor,
        .badge-rol.asesor { background: #fef3c7; color: #92400e; }
        .badge-rol.inmobiliaria { background: #e0e7ff; color: #3730a3; }
        .badge-rol.externo,
        .badge-rol.captador { background: #fce7f3; color: #9d174d; }

        .badge-estado {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .badge-estado.activo { background: #d1fae5; color: #065f46; }
        .badge-estado.inactivo { background: #fee2e2; color: #991b1b; }

        /* ===== ACCIONES EN TABLA ===== */
        .action-btns {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .action-btn {
            width: 36px;
            height: 36px;
            padding: 0;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.2s ease;
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .action-btn.edit { background: #3b82f6; }
        .action-btn.edit:active { background: #2563eb; transform: scale(0.95); }

        .action-btn.toggle { background: #f59e0b; }
        .action-btn.toggle:active { background: #d97706; transform: scale(0.95); }

        .action-btn.delete { background: #ef4444; }
        .action-btn.delete:active { background: #dc2626; transform: scale(0.95); }

        /* ===== VISTA DE TARJETAS PARA MÓVIL ===== */
        .usuarios-cards {
            display: none;
            flex-direction: column;
            gap: 12px;
        }

        .usuario-card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border-left: 4px solid #4c51bf;
            transition: all 0.2s ease;
        }

        .usuario-card.inactivo {
            border-left-color: #ef4444;
            opacity: 0.85;
        }

        .usuario-card:active {
            transform: scale(0.99);
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
        }

        .usuario-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            gap: 10px;
        }

        .usuario-card-header .user-info {
            flex: 1;
            min-width: 0;
        }

        .usuario-card-header .name {
            font-weight: 700;
            color: #2d3748;
            font-size: 1rem;
            margin-bottom: 3px;
            word-break: break-word;
        }

        .usuario-card-header .email {
            color: #718096;
            font-size: 0.82rem;
            word-break: break-all;
        }

        .usuario-card-header .id-badge {
            background: #f1f5f9;
            color: #64748b;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            flex-shrink: 0;
        }

        .usuario-card-body {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 14px;
            align-items: center;
        }

        .usuario-card-body .telefono {
            display: flex;
            align-items: center;
            gap: 5px;
            color: #4a5568;
            font-size: 0.82rem;
            background: #f8fafc;
            padding: 4px 10px;
            border-radius: 20px;
        }

        .usuario-card-body .fecha {
            display: flex;
            align-items: center;
            gap: 5px;
            color: #94a3b8;
            font-size: 0.75rem;
        }

        .usuario-card-actions {
            display: flex;
            gap: 8px;
            padding-top: 12px;
            border-top: 1px solid #f1f5f9;
        }

        .usuario-card-actions .action-btn {
            flex: 1;
            width: auto;
            height: 42px;
            font-size: 0.8rem;
            gap: 6px;
            padding: 0 12px;
            border-radius: 10px;
        }

        .usuario-card-actions .action-btn span {
            display: inline;
        }

        /* ===== FORMULARIOS ===== */
        .usuarios-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }

        .form-usuario {
            background: white;
            padding: 20px;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }

        .form-usuario h4 {
            color: #4c51bf;
            margin: 0 0 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.05rem;
            padding-bottom: 12px;
            border-bottom: 2px solid #f1f5f9;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 6px;
        }

        .form-group label .required {
            color: #e53e3e;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: border-color 0.3s ease;
            background: #f8fafc;
            min-height: 48px;
            -webkit-appearance: none;
            appearance: none;
        }

        .form-group select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%234a5568' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            padding-right: 40px;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #4c51bf;
            background: white;
            box-shadow: 0 0 0 3px rgba(76, 81, 191, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .btn-submit {
            background: linear-gradient(135deg, #4c51bf 0%, #3c41a8 100%);
            color: white;
            border: none;
            padding: 14px 24px;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
            min-height: 52px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(76, 81, 191, 0.3);
        }

        .btn-submit:active {
            transform: scale(0.98);
            box-shadow: 0 2px 6px rgba(76, 81, 191, 0.2);
        }

        .btn-cancelar {
            background: #e2e8f0;
            color: #2d3748;
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 0.95rem;
            cursor: pointer;
            transition: background 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 52px;
            font-weight: 600;
        }

        .btn-cancelar:active {
            background: #cbd5e1;
        }

        .form-actions {
            display: flex;
            gap: 10px;
        }

        .form-actions .btn-cancelar {
            flex: 0 0 auto;
        }

        .form-actions .btn-submit {
            flex: 1;
        }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 12px;
            display: block;
            color: #cbd5e1;
        }

        .empty-state p {
            margin: 0;
            font-size: 0.9rem;
        }

        .empty-state .hint {
            font-size: 0.8rem;
            color: #cbd5e1;
            margin-top: 6px;
        }

        /* ===== MODAL MÓVIL ===== */
        .mobile-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: flex-end;
            justify-content: center;
            padding: 0;
        }

        .mobile-modal.show {
            display: flex;
        }

        .mobile-modal-content {
            background: white;
            width: 100%;
            max-height: 90vh;
            border-radius: 20px 20px 0 0;
            overflow-y: auto;
            animation: slideUp 0.3s ease;
            padding-bottom: env(safe-area-inset-bottom, 20px);
        }

        @keyframes slideUp {
            from { transform: translateY(100%); }
            to { transform: translateY(0); }
        }

        .mobile-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 20px;
            border-bottom: 1px solid #f1f5f9;
            position: sticky;
            top: 0;
            background: white;
            z-index: 1;
            border-radius: 20px 20px 0 0;
        }

        .mobile-modal-header h3 {
            margin: 0;
            font-size: 1.1rem;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .mobile-modal-close {
            background: #f1f5f9;
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            font-size: 1rem;
        }

        .mobile-modal-body {
            padding: 20px;
        }

        /* ===== FAB ===== */
        .fab {
            display: none;
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4c51bf 0%, #3c41a8 100%);
            color: white;
            border: none;
            font-size: 1.3rem;
            cursor: pointer;
            box-shadow: 0 4px 16px rgba(76, 81, 191, 0.4);
            z-index: 100;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .fab:active {
            transform: scale(0.92);
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 992px) {
            .usuarios-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            /* OJO: NO sobrescribir margin ni margin-left del .main-content
               porque socios.css lo usa para el sidebar */
            .main-content {
                padding: 12px;
                padding-bottom: 90px;
            }

            .menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .header-left h1 {
                font-size: 1.2rem;
            }

            .header-actions {
                display: none;
            }

            .fab {
                display: flex;
            }

            /* Ocultar tabla, mostrar cards */
            .tabla-usuarios {
                display: none;
            }

            .usuarios-cards {
                display: flex;
            }

            .acceso-section {
                padding: 14px;
                background: transparent;
                box-shadow: none;
            }

            .acceso-section h3 {
                font-size: 1rem;
                padding: 0 4px;
            }

            /* Formularios en móvil */
            .usuarios-grid {
                gap: 16px;
            }

            .form-usuario {
                padding: 16px;
                border-radius: 14px;
            }

            .form-usuario h4 {
                font-size: 1rem;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .form-group input,
            .form-group select {
                font-size: 16px; /* Evita zoom en iOS */
            }

            /* Sección de edición en móvil (se usa modal) */
            #form-editar {
                display: none;
            }

            /* Formulario crear en desktop se oculta en móvil (se usa FAB + modal) */
            #formulariosDesktop {
                display: none;
            }
        }

        @media (min-width: 769px) {
            .main-content {
                padding: 25px;
                /* NO tocar margin-left: lo maneja socios.css para el sidebar */
            }

            .header-left .welcome {
                display: flex;
                align-items: center;
                gap: 6px;
            }

            .header-actions {
                width: auto;
            }

            .btn-header {
                width: auto;
            }

            .col-id,
            .col-telefono,
            .col-fecha {
                display: table-cell;
            }

            .tabla-usuarios th,
            .tabla-usuarios td {
                padding: 14px 16px;
                font-size: 0.9rem;
            }

            .tabla-usuarios th {
                font-size: 0.8rem;
            }

            .action-btn {
                width: 34px;
                height: 34px;
                border-radius: 8px;
            }

            .action-btn:hover {
                transform: translateY(-1px);
            }

            .btn-submit:hover,
            .btn-header.primary:hover {
                background: #3c41a8;
                transform: translateY(-1px);
                box-shadow: 0 6px 16px rgba(76, 81, 191, 0.35);
            }

            .btn-cancelar:hover {
                background: #cbd5e1;
            }

            .usuario-card:hover {
                box-shadow: 0 4px 16px rgba(0,0,0,0.1);
                transform: translateY(-2px);
            }
        }

        @media (max-width: 380px) {
            .header-left h1 {
                font-size: 1.05rem;
            }

            .usuario-card-header .name {
                font-size: 0.95rem;
            }

            .usuario-card-actions .action-btn {
                font-size: 0.75rem;
                padding: 0 8px;
            }

            .form-usuario {
                padding: 14px;
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<?php include 'modulos/sidebar.php'; ?>

<main class="main-content">
    <!-- Header -->
    <div class="main-header">
        <div class="header-left">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <h1>Gestión de Usuarios</h1>
            <p class="welcome">
                <i class="fas fa-users-cog"></i> Administra los accesos
            </p>
        </div>
        <div class="header-actions">
            <button class="btn-header primary" onclick="abrirModalCrear()">
                <i class="fas fa-user-plus"></i> Nuevo Usuario
            </button>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if ($mensaje): ?>
        <div class="mensaje <?php echo $tipo_mensaje; ?>" id="mensajeAlerta">
            <i class="fas <?php echo $tipo_mensaje === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>

    <!-- Lista de Usuarios -->
    <div class="acceso-section">
        <h3>
            <i class="fas fa-list"></i> Usuarios
            <span class="badge-count"><?php echo count($usuarios); ?></span>
        </h3>

        <!-- VISTA TABLA (Desktop) -->
        <div class="tabla-usuarios">
            <table>
                <thead>
                    <tr>
                        <th class="col-id">ID</th>
                        <th>Usuario</th>
                        <th class="col-telefono">Teléfono</th>
                        <th>Rol</th>
                        <th>Estado</th>
                        <th class="col-fecha">Registro</th>
                        <th style="text-align: right;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($usuarios)): ?>
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <i class="fas fa-users"></i>
                                    <p>No hay usuarios registrados</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($usuarios as $u): ?>
                            <tr>
                                <td class="col-id"><strong>#<?php echo $u['id']; ?></strong></td>
                                <td>
                                    <div class="user-cell">
                                        <span class="name"><?php echo htmlspecialchars($u['name'] ?? 'N/A'); ?></span>
                                        <span class="email"><?php echo htmlspecialchars($u['email'] ?? 'N/A'); ?></span>
                                    </div>
                                </td>
                                <td class="col-telefono"><?php echo htmlspecialchars($u['telefono'] ?? '—'); ?></td>
                                <td>
                                    <span class="badge-rol <?php echo $u['role'] ?? 'propietario'; ?>">
                                        <?php echo ucfirst($u['role'] ?? 'Propietario'); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge-estado <?php echo ($u['activo'] ?? 1) ? 'activo' : 'inactivo'; ?>">
                                        <?php echo ($u['activo'] ?? 1) ? 'Activo' : 'Inactivo'; ?>
                                    </span>
                                </td>
                                <td class="col-fecha">
                                    <?php echo date('d/m/Y H:i', strtotime($u['created_at'] ?? 'now')); ?>
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <a href="?editar=<?php echo $u['id']; ?>" class="action-btn edit" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button class="action-btn toggle" onclick="toggleUsuario(<?php echo $u['id']; ?>, <?php echo $u['activo'] ?? 1; ?>)" title="<?php echo ($u['activo'] ?? 1) ? 'Desactivar' : 'Activar'; ?>">
                                            <i class="fas <?php echo ($u['activo'] ?? 1) ? 'fa-pause' : 'fa-play'; ?>"></i>
                                        </button>
                                        <?php if ($u['id'] != $_SESSION['usuario_id']): ?>
                                            <button class="action-btn delete" onclick="eliminarUsuario(<?php echo $u['id']; ?>)" title="Eliminar">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- VISTA CARDS (Móvil) -->
        <div class="usuarios-cards">
            <?php if (empty($usuarios)): ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <p>No hay usuarios registrados</p>
                </div>
            <?php else: ?>
                <?php foreach ($usuarios as $u): ?>
                    <div class="usuario-card <?php echo ($u['activo'] ?? 1) ? '' : 'inactivo'; ?>">
                        <div class="usuario-card-header">
                            <div class="user-info">
                                <div class="name"><?php echo htmlspecialchars($u['name'] ?? 'N/A'); ?></div>
                                <div class="email"><?php echo htmlspecialchars($u['email'] ?? 'N/A'); ?></div>
                            </div>
                            <span class="id-badge">#<?php echo $u['id']; ?></span>
                        </div>

                        <div class="usuario-card-body">
                            <span class="badge-rol <?php echo $u['role'] ?? 'propietario'; ?>">
                                <?php echo ucfirst($u['role'] ?? 'Propietario'); ?>
                            </span>
                            <span class="badge-estado <?php echo ($u['activo'] ?? 1) ? 'activo' : 'inactivo'; ?>">
                                <?php echo ($u['activo'] ?? 1) ? 'Activo' : 'Inactivo'; ?>
                            </span>
                            <?php if (!empty($u['telefono'])): ?>
                                <span class="telefono">
                                    <i class="fas fa-phone"></i> <?php echo htmlspecialchars($u['telefono']); ?>
                                </span>
                            <?php endif; ?>
                            <span class="fecha">
                                <i class="fas fa-calendar"></i> <?php echo date('d/m/Y', strtotime($u['created_at'] ?? 'now')); ?>
                            </span>
                        </div>

                        <div class="usuario-card-actions">
                            <a href="?editar=<?php echo $u['id']; ?>" class="action-btn edit">
                                <i class="fas fa-edit"></i> <span>Editar</span>
                            </a>
                            <button class="action-btn toggle" onclick="toggleUsuario(<?php echo $u['id']; ?>, <?php echo $u['activo'] ?? 1; ?>)">
                                <i class="fas <?php echo ($u['activo'] ?? 1) ? 'fa-pause' : 'fa-play'; ?>"></i>
                                <span><?php echo ($u['activo'] ?? 1) ? 'Desactivar' : 'Activar'; ?></span>
                            </button>
                            <?php if ($u['id'] != $_SESSION['usuario_id']): ?>
                                <button class="action-btn delete" onclick="eliminarUsuario(<?php echo $u['id']; ?>)">
                                    <i class="fas fa-trash"></i> <span>Eliminar</span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Formularios (Desktop) -->
    <div class="usuarios-grid" id="formulariosDesktop">
        <div class="form-usuario" id="form-crear">
            <h4><i class="fas fa-user-plus"></i> Crear Nuevo Usuario</h4>
            <form method="POST" action="" onsubmit="return validarFormulario(this)">
                <input type="hidden" name="accion" value="crear_usuario">
                <div class="form-group">
                    <label>Nombre Completo <span class="required">*</span></label>
                    <input type="text" name="name" required placeholder="Ej: Juan Pérez">
                </div>
                <div class="form-group">
                    <label>Email <span class="required">*</span></label>
                    <input type="email" name="email" required placeholder="ejemplo@correo.com">
                </div>
                <div class="form-group">
                    <label>Teléfono</label>
                    <input type="tel" name="telefono" placeholder="Ej: 55 1234 5678">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Contraseña <span class="required">*</span></label>
                        <input type="password" name="password" required minlength="6" placeholder="Mínimo 6 caracteres">
                    </div>
                    <div class="form-group">
                        <label>Confirmar Contraseña <span class="required">*</span></label>
                        <input type="password" name="confirm_password" required placeholder="Repite la contraseña">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Rol <span class="required">*</span></label>
                        <select name="role" required>
                            <option value="propietario">Propietario</option>
                            <option value="asesor">Asesor</option>
                            <option value="admin">Administrador</option>
                            <option value="externo">Externo</option>
                            <option value="captador">Captador</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Estado</label>
                        <select name="activo">
                            <option value="1">Activo</option>
                            <option value="0">Inactivo</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Crear Usuario
                </button>
            </form>
        </div>

        <div class="form-usuario" id="form-editar">
            <h4><i class="fas fa-user-edit"></i> Editar Usuario</h4>
            <?php if ($usuario_editar): ?>
                <form method="POST" action="" onsubmit="return validarFormularioEdicion(this)">
                    <input type="hidden" name="accion" value="editar_usuario">
                    <input type="hidden" name="usuario_id" value="<?php echo $usuario_editar['id']; ?>">
                    <div class="form-group">
                        <label>Nombre Completo <span class="required">*</span></label>
                        <input type="text" name="name" required value="<?php echo htmlspecialchars($usuario_editar['name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Email <span class="required">*</span></label>
                        <input type="email" name="email" required value="<?php echo htmlspecialchars($usuario_editar['email'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Teléfono</label>
                        <input type="tel" name="telefono" value="<?php echo htmlspecialchars($usuario_editar['telefono'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Nueva Contraseña <span style="font-weight: normal; color: #94a3b8; font-size: 0.8rem;">(dejar vacío para mantener)</span></label>
                        <input type="password" name="nuevo_password" minlength="6" placeholder="Mínimo 6 caracteres">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Rol <span class="required">*</span></label>
                            <select name="role" required>
                                <option value="propietario" <?php echo ($usuario_editar['role'] ?? '') === 'propietario' ? 'selected' : ''; ?>>Propietario</option>
                                <option value="asesor" <?php echo ($usuario_editar['role'] ?? '') === 'asesor' ? 'selected' : ''; ?>>Asesor</option>
                                <option value="admin" <?php echo ($usuario_editar['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Administrador</option>
                                <option value="inmobiliaria" <?php echo ($usuario_editar['role'] ?? '') === 'inmobiliaria' ? 'selected' : ''; ?>>Inmobiliaria</option>
                                <option value="captador" <?php echo ($usuario_editar['role'] ?? '') === 'captador' ? 'selected' : ''; ?>>Captador</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Estado</label>
                            <select name="activo">
                                <option value="1" <?php echo ($usuario_editar['activo'] ?? 1) == 1 ? 'selected' : ''; ?>>Activo</option>
                                <option value="0" <?php echo ($usuario_editar['activo'] ?? 1) == 0 ? 'selected' : ''; ?>>Inactivo</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-actions">
                        <a href="accesos.php" class="btn-cancelar">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                        <button type="submit" class="btn-submit">
                            <i class="fas fa-save"></i> Actualizar
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <div class="empty-state" style="padding: 30px;">
                    <i class="fas fa-user-edit"></i>
                    <p>Selecciona un usuario para editarlo</p>
                    <p class="hint">Haz clic en <i class="fas fa-edit"></i> en la tabla</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- FAB para móvil -->
<button class="fab" id="fabNuevo" onclick="abrirModalCrear()" title="Nuevo Usuario">
    <i class="fas fa-plus"></i>
</button>

<!-- Modal Móvil para Crear Usuario -->
<div class="mobile-modal" id="modalCrear">
    <div class="mobile-modal-content">
        <div class="mobile-modal-header">
            <h3><i class="fas fa-user-plus"></i> Nuevo Usuario</h3>
            <button class="mobile-modal-close" onclick="cerrarModalCrear()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="mobile-modal-body">
            <form method="POST" action="" onsubmit="return validarFormulario(this)">
                <input type="hidden" name="accion" value="crear_usuario">
                <div class="form-group">
                    <label>Nombre Completo <span class="required">*</span></label>
                    <input type="text" name="name" required placeholder="Ej: Juan Pérez">
                </div>
                <div class="form-group">
                    <label>Email <span class="required">*</span></label>
                    <input type="email" name="email" required placeholder="ejemplo@correo.com">
                </div>
                <div class="form-group">
                    <label>Teléfono</label>
                    <input type="tel" name="telefono" placeholder="Ej: 55 1234 5678">
                </div>
                <div class="form-group">
                    <label>Contraseña <span class="required">*</span></label>
                    <input type="password" name="password" required minlength="6" placeholder="Mínimo 6 caracteres">
                </div>
                <div class="form-group">
                    <label>Confirmar Contraseña <span class="required">*</span></label>
                    <input type="password" name="confirm_password" required placeholder="Repite la contraseña">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Rol <span class="required">*</span></label>
                        <select name="role" required>
                            <option value="propietario">Propietario</option>
                            <option value="asesor">Asesor</option>
                            <option value="admin">Administrador</option>
                            <option value="externo">Externo</option>
                            <option value="captador">Captador</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Estado</label>
                        <select name="activo">
                            <option value="1">Activo</option>
                            <option value="0">Inactivo</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Crear Usuario
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Modal Móvil para Editar Usuario -->
<?php if ($usuario_editar): ?>
<div class="mobile-modal" id="modalEditar">
    <div class="mobile-modal-content">
        <div class="mobile-modal-header">
            <h3><i class="fas fa-user-edit"></i> Editar Usuario #<?php echo $usuario_editar['id']; ?></h3>
            <button class="mobile-modal-close" onclick="cerrarModalEditar()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="mobile-modal-body">
            <form method="POST" action="" onsubmit="return validarFormularioEdicion(this)">
                <input type="hidden" name="accion" value="editar_usuario">
                <input type="hidden" name="usuario_id" value="<?php echo $usuario_editar['id']; ?>">
                <div class="form-group">
                    <label>Nombre Completo <span class="required">*</span></label>
                    <input type="text" name="name" required value="<?php echo htmlspecialchars($usuario_editar['name'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Email <span class="required">*</span></label>
                    <input type="email" name="email" required value="<?php echo htmlspecialchars($usuario_editar['email'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Teléfono</label>
                    <input type="tel" name="telefono" value="<?php echo htmlspecialchars($usuario_editar['telefono'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Nueva Contraseña <span style="font-weight: normal; color: #94a3b8; font-size: 0.8rem;">(dejar vacío para mantener)</span></label>
                    <input type="password" name="nuevo_password" minlength="6" placeholder="Mínimo 6 caracteres">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Rol <span class="required">*</span></label>
                        <select name="role" required>
                            <option value="propietario" <?php echo ($usuario_editar['role'] ?? '') === 'propietario' ? 'selected' : ''; ?>>Propietario</option>
                            <option value="asesor" <?php echo ($usuario_editar['role'] ?? '') === 'asesor' ? 'selected' : ''; ?>>Asesor</option>
                            <option value="admin" <?php echo ($usuario_editar['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Administrador</option>
                            <option value="inmobiliaria" <?php echo ($usuario_editar['role'] ?? '') === 'inmobiliaria' ? 'selected' : ''; ?>>Inmobiliaria</option>
                            <option value="captador" <?php echo ($usuario_editar['role'] ?? '') === 'captador' ? 'selected' : ''; ?>>Captador</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Estado</label>
                        <select name="activo">
                            <option value="1" <?php echo ($usuario_editar['activo'] ?? 1) == 1 ? 'selected' : ''; ?>>Activo</option>
                            <option value="0" <?php echo ($usuario_editar['activo'] ?? 1) == 0 ? 'selected' : ''; ?>>Inactivo</option>
                        </select>
                    </div>
                </div>
                <div class="form-actions">
                    <a href="accesos.php" class="btn-cancelar" style="flex: 0 0 auto;">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Actualizar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    // ===== MENÚ MÓVIL =====
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    function toggleSidebar() {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('show');
        document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
    }

    if (menuToggle) menuToggle.addEventListener('click', toggleSidebar);
    if (overlay) overlay.addEventListener('click', toggleSidebar);

    document.querySelectorAll('.sidebar nav a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 992) toggleSidebar();
        });
    });

    // ===== MODAL CREAR =====
    function abrirModalCrear() {
        const modal = document.getElementById('modalCrear');
        if (modal && window.innerWidth <= 768) {
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        } else {
            // Desktop: scroll al formulario
            const form = document.getElementById('form-crear');
            if (form) {
                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                setTimeout(() => {
                    form.querySelector('input[name="name"]')?.focus();
                }, 400);
            }
        }
    }

    function cerrarModalCrear() {
        const modal = document.getElementById('modalCrear');
        if (modal) {
            modal.classList.remove('show');
            document.body.style.overflow = '';
        }
    }

    // ===== MODAL EDITAR =====
    function abrirModalEditar() {
        const modal = document.getElementById('modalEditar');
        if (modal) {
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
    }

    function cerrarModalEditar() {
        const modal = document.getElementById('modalEditar');
        if (modal) {
            modal.classList.remove('show');
            document.body.style.overflow = '';
        }
    }

    // Abrir modal editar si hay usuario_editar en móvil
    <?php if ($usuario_editar): ?>
    if (window.innerWidth <= 768) {
        abrirModalEditar();
    }
    <?php endif; ?>

    // Cerrar modales al hacer clic fuera
    document.querySelectorAll('.mobile-modal').forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('show');
                document.body.style.overflow = '';
            }
        });
    });

    // ===== VALIDACIONES =====
    function validarFormulario(form) {
        const password = form.querySelector('input[name="password"]');
        const confirm = form.querySelector('input[name="confirm_password"]');
        
        if (password.value !== confirm.value) {
            alert('Las contraseñas no coinciden');
            confirm.focus();
            return false;
        }
        
        if (password.value.length < 6) {
            alert('La contraseña debe tener al menos 6 caracteres');
            password.focus();
            return false;
        }
        
        return true;
    }
    
    function validarFormularioEdicion(form) {
        const password = form.querySelector('input[name="nuevo_password"]');
        if (password.value && password.value.length < 6) {
            alert('La contraseña debe tener al menos 6 caracteres');
            password.focus();
            return false;
        }
        return true;
    }

    // ===== ACCIONES =====
    function toggleUsuario(id, estado) {
        const mensaje = estado ? 'desactivar' : 'activar';
        if (confirm(`¿Estás seguro de ${mensaje} este usuario?`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="accion" value="toggle_usuario">
                <input type="hidden" name="usuario_id" value="${id}">
                <input type="hidden" name="nuevo_estado" value="${estado ? 0 : 1}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    function eliminarUsuario(id) {
        if (confirm('¿Eliminar este usuario? Esta acción no se puede deshacer.')) {
            if (confirm('Confirmar eliminación del usuario #' + id + '?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="accion" value="eliminar_usuario">
                    <input type="hidden" name="usuario_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    }

    // ===== AUTO-CERRAR MENSAJES =====
    const mensajeAlerta = document.getElementById('mensajeAlerta');
    if (mensajeAlerta) {
        setTimeout(() => {
            mensajeAlerta.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            mensajeAlerta.style.opacity = '0';
            mensajeAlerta.style.transform = 'translateY(-10px)';
            setTimeout(() => mensajeAlerta.remove(), 300);
        }, 5000);
    }

    // ===== PREVENIR ZOOM EN DOBLE TAP =====
    let lastTouchEnd = 0;
    document.addEventListener('touchend', (e) => {
        const now = Date.now();
        if (now - lastTouchEnd <= 300) {
            e.preventDefault();
        }
        lastTouchEnd = now;
    }, { passive: false });
</script>

</body>
</html>