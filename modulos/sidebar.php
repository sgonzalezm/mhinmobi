<!-- ===== SIDEBAR CON CONTROL DE ACCESO ===== -->
<?php
// Obtener el rol de la sesión (establecido en login.php mediante auth.php)
$rol_actual = $_SESSION['usuario_rol'] ?? 'externo';

// Definición de permisos por rol
$permisos = [
    'admin' => ['inicio', 'calendario', 'mis_propiedades', 'publicar_propiedad', 'inventario_maestro', 'crm_clientes', 'rastreabilidad', 'mensajes', 'gestion_acceso', 'administracion', 'audit_log'],
    'asesor' => ['inicio', 'calendario', 'mis_propiedades', 'publicar_propiedad', 'inventario_maestro', 'crm_clientes', 'rastreabilidad', 'mensajes'],
    'propietario' => ['inicio', 'calendario', 'mis_propiedades', 'publicar_propiedad', 'mensajes'],
    'externo' => ['inicio']
];

// Función para verificar si un elemento debe mostrarse
function ver($item) {
    global $rol_actual, $permisos;
    // Si el rol no existe en el array, usar 'externo' por defecto
    $rol = isset($permisos[$rol_actual]) ? $rol_actual : 'externo';
    return in_array($item, $permisos[$rol]);
}

// Roles en español para mostrar
$roles_espanol = [
    'admin' => 'Administrador',
    'asesor' => 'Asesor',
    'propietario' => 'Propietario',
    'externo' => 'Externo'
];
?>

<aside class="sidebar" id="sidebar">
    <a href="index.php" class="logo">
        <i class="fas fa-building"></i>
        <span>INMOBILIARIA MH</span>
    </a>

    <div class="user-info">
        <div class="avatar">
            <?php 
            $nombre_usuario = $_SESSION['usuario_nombre'] ?? 'U';
            echo strtoupper(substr($nombre_usuario, 0, 1)); 
            ?>
        </div>
        <div class="name">
            <?php echo htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Usuario'); ?>
        </div>
        <div class="role">
            <i class="fas fa-user-tag"></i>
            <?php 
            $rol_display = $_SESSION['usuario_rol'] ?? 'externo';
            echo $roles_espanol[$rol_display] ?? 'Usuario';
            ?>
        </div>
        <div class="home-link">
            <a href="../index.php">
                <i class="fas fa-building"></i> Sitio Vera Terra 
            </a>
        </div>
    </div>

    <nav>
        <?php if (ver('inicio')): ?>
        <a href="../socios_panel.php">
            <i class="fas fa-home"></i> Inicio
        </a>
        <?php endif; ?>

        <?php if (ver('calendario')): ?>
        <a href="../calendario.php">
            <i class="fas fa-calendar"></i> Calendario
        </a>
        <?php endif; ?>

        <?php if (ver('mis_propiedades')): ?>
        <a href="../mis_propiedades.php">
            <i class="fas fa-building"></i> Mis Propiedades
        </a>
        <?php endif; ?>

        <?php if (ver('publicar_propiedad')): ?>
        <a href="vender.php">
            <i class="fas fa-plus-circle"></i> Publicar Propiedad
        </a>
        <?php endif; ?>

        <?php if (ver('inventario_maestro')): ?>
        <a href="../inventario_maestro.php">
            <i class="fas fa-warehouse"></i> Inventario Maestro
        </a>
        <?php endif; ?>

        <?php if (ver('crm_clientes')): ?>
        <a href="../crm_clientes.php">
            <i class="fas fa-users"></i> CRM Clientes
        </a>
        <?php endif; ?>

        <?php if (ver('rastreabilidad')): ?>
        <a href="../rastreabilidad.php">
            <i class="fas fa-tasks"></i> Rastreabilidad
        </a>
        <?php endif; ?>

        <?php if (ver('mensajes')): ?>
        <a href="../mensajes.php">
            <i class="fas fa-envelope"></i> Mensajes
            <span class="badge">3</span>
        </a>
        <?php endif; ?>

        <?php if (ver('gestion_acceso')): ?>
        <a href="../accesos.php">
            <i class="fas fa-key"></i> Gestión de acceso
        </a>
        <?php endif; ?>

        <?php if (ver('administracion')): ?>
        <a href="../admin/administracion.php">
            <i class="fas fa-user-cog"></i> Administración
        </a>
        <?php endif; ?>

        <?php if (ver('audit_log')): ?>
        <a href="../visor_audit_log.php">
            <i class="fas fa-envelope-open-text"></i> Audit Log
        </a>
        <?php endif; ?>
    </nav>

    <div class="logout-section">
        <a href="../logout.php">
            <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
        </a>
    </div>
</aside>