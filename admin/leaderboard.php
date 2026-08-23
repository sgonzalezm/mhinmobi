<?php
require_once 'includes/auth.php';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Leaderboard</title>
    <link rel="stylesheet" href="../css/socios.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Reutiliza los estilos de comisiones.php */
        .medalla { font-size: 30px; }
        .top-card { text-align: center; padding: 20px; border-radius: 10px; border: 3px solid #ddd; }
        .top-card.gold { border-color: gold; }
        .top-card.silver { border-color: silver; }
        .top-card.bronze { border-color: #CD7F32; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 20px; }
        @media (max-width: 768px) { .grid-3 { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="container">
    <h1><i class="fas fa-trophy"></i> Leaderboard de Asesores</h1>
    
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
        <div>
            <label>Tipo</label>
            <select id="tipo">
                <option value="">Todos</option>
                <option value="venta">Venta</option>
                <option value="renta">Renta</option>
            </select>
        </div>
        <div>
            <label>Estado</label>
            <select id="estado">
                <option value="">Todos</option>
                <option value="completado">Completado</option>
                <option value="en_progreso">En Progreso</option>
            </select>
        </div>
        <div>
            <label>Ordenar</label>
            <select id="orden">
                <option value="comisiones">Comisiones</option>
                <option value="ventas">Volumen</option>
                <option value="propiedades">Propiedades</option>
                <option value="eficiencia">Eficiencia</option>
            </select>
        </div>
        <button class="btn btn-primary" onclick="cargar()"><i class="fas fa-search"></i></button>
        <button class="btn btn-success" onclick="exportar()"><i class="fas fa-file-excel"></i></button>
    </div>

    <!-- TOP 3 -->
    <div class="grid-3" id="top3"></div>

    <!-- TABLA COMPLETA -->
    <div class="card">
        <table class="table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Asesor</th>
                    <th>Propiedades</th>
                    <th>Volumen</th>
                    <th>Comisiones</th>
                    <th>Eficiencia</th>
                    <th>Insignias</th>
                </tr>
            </thead>
            <tbody id="tbody"></tbody>
        </table>
    </div>
</div>

<script>
function cargar() {
    const params = new URLSearchParams({
        desde: document.getElementById('desde').value,
        hasta: document.getElementById('hasta').value,
        tipo: document.getElementById('tipo').value,
        estado: document.getElementById('estado').value,
        orden: document.getElementById('orden').value
    });
    
    fetch(`ajax_leaderboard.php?${params}`)
        .then(r => r.json())
        .then(data => {
            // TOP 3
            const top3 = document.getElementById('top3');
            if (data.length < 1) {
                top3.innerHTML = '<div class="col">Sin datos</div>';
                return;
            }
            
            const medallas = ['🥇', '🥈', '🥉'];
            const clases = ['gold', 'silver', 'bronze'];
            top3.innerHTML = data.slice(0, 3).map((item, i) => `
                <div class="top-card ${clases[i]}">
                    <div class="medalla">${medallas[i]}</div>
                    <h4>${item.asesor_nombre}</h4>
                    <p><strong>$${Number(item.total_comisiones).toLocaleString()}</strong></p>
                    <small>${item.total_propiedades} propiedades</small>
                </div>
            `).join('');
            
            // TABLA COMPLETA
            const tbody = document.getElementById('tbody');
            tbody.innerHTML = data.map((item, i) => {
                const medalla = i < 3 ? medallas[i] : `#${i+1}`;
                const eficiencia = item.eficiencia ? Number(item.eficiencia).toFixed(1) + ' días' : 'N/A';
                let insignias = '';
                if (i === 0) insignias += '🏆 ';
                if (item.eficiencia && item.eficiencia < 30) insignias += '⚡ ';
                
                return `
                    <tr style="${i < 3 ? 'background:#fffbe6;' : ''}">
                        <td><strong>${medalla}</strong></td>
                        <td><i class="fas fa-user"></i> ${item.asesor_nombre}</td>
                        <td>${item.total_propiedades}</td>
                        <td>$${Number(item.volumen_ventas).toLocaleString()}</td>
                        <td><strong>$${Number(item.total_comisiones).toLocaleString()}</strong></td>
                        <td>${eficiencia}</td>
                        <td>${insignias || '—'}</td>
                    </tr>
                `;
            }).join('');
        });
}

function exportar() {
    const params = new URLSearchParams({
        desde: document.getElementById('desde').value,
        hasta: document.getElementById('hasta').value,
        tipo: document.getElementById('tipo').value,
        estado: document.getElementById('estado').value,
        orden: document.getElementById('orden').value
    });
    window.location.href = `exportar.php?tipo=leaderboard&${params}`;
}

cargar();
</script>
</body>
</html>