<!-- ===== SIDEBAR ===== -->
<aside class="sidebar" id="sidebar">
    <a href="index.php" class="logo">
        <i class="fas fa-building"></i>
        <span>INMOBILIARIA MH</span>
    </a>

    <div class="user-info">
        <div class="avatar">
            <?php echo $usuario ? strtoupper(substr($usuario['nombre'] ?? 'U', 0, 1)) : 'U'; ?>
        </div>
        <div class="name"><?php echo $usuario ? htmlspecialchars($usuario['nombre'] ?? 'Usuario') : 'Invitado'; ?></div>
        <div class="role">
            <i class="fas fa-user-tag"></i>
            <?php echo $usuario ? (($usuario['activo'] ?? 1) ? 'Activo' : 'Inactivo') : 'No autenticado'; ?>
        </div>
        <div class="home-link">
            <a href="index.php">
                <i class="fas fa-building"></i> Sitio Vera Terra 
            </a>
        </div>
    </div>

    <nav>
        <a href="socios_panel.php">
            <i class="fas fa-home"></i> Inicio
        </a>
        <a href="mis_propiedades.php">
            <i class="fas fa-building"></i> Mis Propiedades
        </a>
        <a href="vender.php">
            <i class="fas fa-plus-circle"></i> Publicar Propiedad
        </a>
        <a href="inventario_maestro.php">
            <i class="fas fa-warehouse"></i> Inventario Maestro
        </a>
        <a href="expediente_legal.php">
            <i class="fas fa-file-alt"></i> Expediente Legal
        </a>
        <a href="crm_clientes.php">
            <i class="fas fa-users"></i> CRM Clientes
        </a>
        <a href="inteligencia_negocios.php">
            <i class="fas fa-chart-line"></i> Inteligencia de Negocios
        </a>
        <a href="mensajes.php">
            <i class="fas fa-envelope"></i> Mensajes
            <span class="badge">3</span>
        </a>
        <a href="accesos.php">
            <i class="fas fa-user-cog"></i> Configuracion de acceso
        </a>
    </nav>

    <div class="logout-section">
        <a href="logout.php">
            <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
        </a>
    </div>
</aside>