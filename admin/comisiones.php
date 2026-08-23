<?php
require_once 'includes/auth.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Comisiones</title>
    <link rel="stylesheet" href="../css/socios.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .card { background: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .stat-box { padding: 20px; border-radius: 10px; color: white; }
        .stat-box.blue { background: linear-gradient(135deg, #667eea, #764ba2); }
        .stat-box.green { background: linear-gradient(135deg, #f093fb, #f5576c); }
        .stat-box.orange { background: linear-gradient(135deg, #4facfe, #00f2fe); }
        .stat-box.purple { background: linear-gradient(135deg, #a8edea, #fed6e3); color: #333; }
        .filters { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; gap: 15px; flex-wrap: wrap; align-items: end; }
        .filters label { display: block; font-size: 12px; margin-bottom: 5px; }
        .filters input, .filters select { padding: 8px; border: 1px solid #ddd; border-radius: 5px; }
        .btn { padding: 8px 20px; border: none; border-radius: 5px; cursor: pointer; color: white; }
        .btn-primary { background: #007bff; }
        .btn-success { background: #28a745; }
        .btn-info { background: #17a2b8; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        .table tr:hover { background: #f8f9fa; }
        .badge { padding: 5px 10px; border-radius: 20px; font-size: 12px; }
        .badge-success { background: #28a745; color: white; }
        .badge-warning { background: #ffc107; color: #333; }
        .badge-info { background: #17a2b8; color: white; }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .row { display: flex; gap: 20px; flex-wrap: wrap; }
        .col { flex: 1; min-width: 200px; }
        .mt-20 { margin-top: 20px; }
        .text-center { text-align: center; }
    </style>
</head>
<body>
<div class="container">
    <h1><i class="fas fa-dollar-sign"></i> Gestión de Comisiones</h1>
    
    <!-- FILTROS -->
    <div class="filters">
        <div>
            <label>Desde</label>
            <input type="date" id="desde" value="<?= date('Y-m-01') ?>">
        </div>
        <div>
            <label>Hasta</label>
            <input type="date" id="hasta" value="<?= date('Y-m-d') ?>">
        </div>
        <button class="btn btn-primary" onclick="cargar()"><i class="fas fa-search"></i> Filtrar</button>
        <button class="btn btn-success" onclick="exportar()"><i class="fas fa-file-excel"></i> Exportar</button>
    </div>

    <!-- ESTADÍSTICAS -->
    <div class="stats" id="stats">
        <div class="stat-box blue"><h4>Total Asesores</h4><h2 id="totalAsesores">0</h2></div>
        <div class="stat-box green"><h4>Total Comisiones</h4><h2 id="totalComisiones">$0</h2></div>
        <div class="stat-box orange"><h4>Propiedades Vendidas</h4><h2 id="totalPropiedades">0</h2></div>
        <div class="stat-box purple"><h4>Comisión Promedio</h4><h2 id="promedioComision">$0</h2></div>
    </div>

    <!-- TABLA -->
    <div class="card">
        <table class="table" id="tabla">
            <thead>
                <tr>
                    <th>Asesor</th>
                    <th>Propiedades</th>
                    <th>Total Comisiones</th>
                    <th>Promedio</th>
                    <th>Más Cara</th>
                    <th>Última Venta</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody id="tbody"></tbody>
        </table>
        <div id="paginacion" style="margin-top:15px;text-align:center;"></div>
    </div>

    <!-- PROYECCIONES -->
    <div class="card">
        <h3><i class="fas fa-chart-line"></i> Proyecciones</h3>
        <div class="filters" style="margin-bottom:15px;">
            <div>
                <label>Tipo</label>
                <select id="tipoProyeccion">
                    <option value="mensual">Mensual</option>
                    <option value="trimestral">Trimestral</option>
                    <option value="anual">Anual</option>
                </select>
            </div>
            <div>
                <label>Mes</label>
                <input type="month" id="mesProyeccion" value="<?= date('Y-m') ?>">
            </div>
            <div>
                <label>Meta ($)</label>
                <input type="number" id="metaProyeccion" value="100000">
            </div>
            <button class="btn btn-primary" onclick="cargarProyecciones()"><i class="fas fa-calculator"></i> Calcular</button>
        </div>
        <div class="stats" id="statsProyeccion"></div>
        <div style="overflow-x:auto;margin-top:15px;">
            <table class="table">
                <thead><tr><th>Asesor</th><th>Propiedades</th><th>Comisión Estimada</th><th>Probabilidad</th><th>Proyección</th></tr></thead>
                <tbody id="tbodyProyeccion"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
// ======== COMISIONES ========
function cargar(page = 1) {
    const desde = document.getElementById('desde').value;
    const hasta = document.getElementById('hasta').value;
    
    fetch(`ajax_comisiones.php?desde=${desde}&hasta=${hasta}&page=${page}`)
        .then(r => r.json())
        .then(d => {
            // Estadísticas
            document.getElementById('totalAsesores').textContent = d.stats.total_asesores || 0;
            document.getElementById('totalComisiones').textContent = '$' + Number(d.stats.total_comisiones || 0).toLocaleString();
            document.getElementById('totalPropiedades').textContent = d.stats.total_propiedades || 0;
            document.getElementById('promedioComision').textContent = '$' + Number(d.stats.promedio_comision || 0).toLocaleString();
            
            // Tabla
            const tbody = document.getElementById('tbody');
            if (!d.data || !d.data.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center">Sin datos</td></tr>';
                return;
            }
            
            tbody.innerHTML = d.data.map(item => `
                <tr>
                    <td><i class="fas fa-user"></i> ${item.asesor_nombre}</td>
                    <td>${item.total_propiedades}</td>
                    <td><strong>$${Number(item.total_comisiones).toLocaleString()}</strong></td>
                    <td>$${Number(item.promedio_comision).toLocaleString()}</td>
                    <td>$${Number(item.precio_maximo).toLocaleString()}</td>
                    <td>${item.ultima_venta ? new Date(item.ultima_venta).toLocaleDateString() : 'N/A'}</td>
                    <td><a href="detalle_asesor.php?id=${item.asesor_id}" class="btn btn-info" style="padding:5px 10px;font-size:12px;"><i class="fas fa-eye"></i></a></td>
                </tr>
            `).join('');
            
            // Paginación
            const totalPages = Math.ceil(d.total / 10);
            let pag = '';
            for (let i = 1; i <= totalPages; i++) {
                pag += `<a href="#" onclick="cargar(${i});return false;" style="padding:5px 10px;background:${i===page?'#007bff':'#ddd'};color:${i===page?'white':'#333'};border-radius:3px;text-decoration:none;">${i}</a> `;
            }
            document.getElementById('paginacion').innerHTML = pag;
        });
}

// ======== PROYECCIONES ========
function cargarProyecciones() {
    const tipo = document.getElementById('tipoProyeccion').value;
    const mes = document.getElementById('mesProyeccion').value;
    const meta = document.getElementById('metaProyeccion').value;
    
    fetch(`ajax_proyecciones.php?tipo=${tipo}&mes=${mes}&meta=${meta}`)
        .then(r => r.json())
        .then(d => {
            // Estadísticas
            document.getElementById('statsProyeccion').innerHTML = `
                <div class="stat-box blue"><h4>Proyección Total</h4><h2>$${Number(d.total).toLocaleString()}</h2></div>
                <div class="stat-box green"><h4>Meta</h4><h2>$${Number(d.meta).toLocaleString()}</h2></div>
                <div class="stat-box orange"><h4>Cumplimiento</h4><h2>${Number(d.cumplimiento).toFixed(1)}%</h2></div>
                <div class="stat-box purple"><h4>En Proceso</h4><h2>${d.propiedades}</h2></div>
            `;
            
            // Tabla
            const tbody = document.getElementById('tbodyProyeccion');
            if (!d.asesores || !d.asesores.length) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center">Sin proyecciones</td></tr>';
                return;
            }
            
            tbody.innerHTML = d.asesores.map(item => `
                <tr>
                    <td>${item.asesor_nombre}</td>
                    <td>${item.total_propiedades}</td>
                    <td>$${Number(item.comision_estimada).toLocaleString()}</td>
                    <td>${item.probabilidad_promedio}%</td>
                    <td><strong>$${Number(item.proyeccion).toLocaleString()}</strong></td>
                </tr>
            `).join('');
        });
}

function exportar() {
    const desde = document.getElementById('desde').value;
    const hasta = document.getElementById('hasta').value;
    window.location.href = `exportar.php?tipo=comisiones&desde=${desde}&hasta=${hasta}`;
}

// Cargar al inicio
cargar();
cargarProyecciones();
</script>
</body>
</html>