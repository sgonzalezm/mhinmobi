<!-- ACCESO RÁPIDO -->
<div class="quick-access">
    <h3><i class="fas fa-bolt"></i> Acceso Rápido</h3>
    <div class="quick-links">
        <a href="comisiones.php?filtro=mes">
            <i class="fas fa-calendar"></i> Comisiones del Mes
        </a>
        <a href="leaderboard.php?orden=eficiencia">
            <i class="fas fa-rocket"></i> Asesores Eficientes
        </a>
        <a href="../inventario_maestro.php?status=activo">
            <i class="fas fa-home"></i> Propiedades Activas
        </a>
        <a href="exportar.php?tipo=comisiones&desde=<?php echo date('Y-m-01'); ?>&hasta=<?php echo date('Y-m-d'); ?>">
            <i class="fas fa-file-excel"></i> Exportar Reporte
        </a>
        <a href="../rastreabilidad.php">
            <i class="fas fa-sync"></i> Procesos Activos
        </a>
    </div>
</div>