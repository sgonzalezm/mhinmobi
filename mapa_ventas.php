<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// ===== Filtros =====
$filtroZona = trim($_GET['zona'] ?? '');
$filtroDesde = trim($_GET['desde'] ?? '');
$filtroHasta = trim($_GET['hasta'] ?? '');
$filtroOperacion = trim($_GET['operacion'] ?? '');

// Validar fechas
$desdeValido = preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtroDesde) ? $filtroDesde : '';
$hastaValido = preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtroHasta) ? $filtroHasta : '';

// Construir WHERE dinámico para propiedades finalizadas
$where = "WHERE p.status IN ('finalizada','finalizado','vendido','vendida')";
$params = [];

if ($desdeValido !== '') {
    $where .= " AND DATE(f.sold_at) >= :desde";
    $params[':desde'] = $desdeValido;
}
if ($hastaValido !== '') {
    $where .= " AND DATE(f.sold_at) <= :hasta";
    $params[':hasta'] = $hastaValido;
}
if ($filtroZona !== '') {
    $where .= " AND p.address_municipality = :zona";
    $params[':zona'] = $filtroZona;
}
if ($filtroOperacion !== '') {
    $where .= " AND p.operation_type = :operacion";
    $params[':operacion'] = $filtroOperacion;
}

// ===== KPIs globales =====
$kpis = [
    'total_vendidas' => 0,
    'avg_precio' => 0,
    'volumen_total' => 0,
    'dias_promedio' => 0,
    'avg_asking' => 0,
    'avg_descuento' => 0
];

try {
    $stmtKpi = $conn->prepare("
        SELECT 
            COUNT(*) AS total_vendidas,
            AVG(f.sold_price) AS avg_precio,
            SUM(f.sold_price) AS volumen_total,
            AVG(DATEDIFF(f.sold_at, p.created_at)) AS dias_promedio,
            AVG(f.asking_price) AS avg_asking,
            AVG(f.asking_price - f.sold_price) AS avg_descuento
        FROM properties p
        INNER JOIN property_financials f ON p.id = f.property_id
        $where
          AND f.sold_price IS NOT NULL
          AND f.sold_price > 0
    ");
    $stmtKpi->execute($params);
    $kpis = $stmtKpi->fetch(PDO::FETCH_ASSOC) ?: $kpis;
} catch (PDOException $e) {
    error_log("Error KPIs: " . $e->getMessage());
}

// ===== Lista de zonas disponibles (para el filtro) =====
$zonasDisponibles = [];
try {
    $stmtZonas = $conn->query("
        SELECT DISTINCT address_municipality AS zona
        FROM properties
        WHERE address_municipality IS NOT NULL
          AND address_municipality != ''
          AND status IN ('finalizada','finalizado','vendido','vendida')
        ORDER BY address_municipality ASC
    ");
    $zonasDisponibles = $stmtZonas->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Error zonas: " . $e->getMessage());
}

// ===== Métricas por zona =====
$porZona = [];
try {
    $stmtZona = $conn->prepare("
        SELECT 
            p.address_municipality AS zona,
            COUNT(*) AS ventas,
            AVG(f.sold_price) AS precio_promedio,
            MIN(f.sold_price) AS precio_min,
            MAX(f.sold_price) AS precio_max,
            AVG(DATEDIFF(f.sold_at, p.created_at)) AS dias_promedio,
            SUM(f.sold_price) AS volumen
        FROM properties p
        INNER JOIN property_financials f ON p.id = f.property_id
        $where
          AND f.sold_price IS NOT NULL
          AND f.sold_price > 0
        GROUP BY p.address_municipality
        ORDER BY ventas DESC
    ");
    $stmtZona->execute($params);
    $porZona = $stmtZona->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error por zona: " . $e->getMessage());
}

// ===== Evolución mensual (últimos 24 meses o rango filtrado) =====
$evolucion = [];
try {
    $stmtEvo = $conn->prepare("
        SELECT 
            DATE_FORMAT(f.sold_at, '%Y-%m') AS mes,
            COUNT(*) AS ventas,
            AVG(f.sold_price) AS precio_promedio,
            SUM(f.sold_price) AS volumen
        FROM properties p
        INNER JOIN property_financials f ON p.id = f.property_id
        $where
          AND f.sold_price IS NOT NULL
          AND f.sold_at IS NOT NULL
        GROUP BY DATE_FORMAT(f.sold_at, '%Y-%m')
        ORDER BY mes ASC
    ");
    $stmtEvo->execute($params);
    $evolucion = $stmtEvo->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error evolución: " . $e->getMessage());
}

// ===== Top agentes / vendedores (opcional, si tienes el campo) =====
$porAgente = [];
try {
    // Ajusta 'agent_id' o 'created_by' según tu esquema real
    $checkCol = $conn->query("SHOW COLUMNS FROM properties LIKE 'created_by'");
    if ($checkCol && $checkCol->rowCount() > 0) {
        $stmtAg = $conn->prepare("
            SELECT 
                p.created_by AS agente,
                COUNT(*) AS ventas,
                AVG(f.sold_price) AS precio_promedio,
                SUM(f.sold_price) AS volumen
            FROM properties p
            INNER JOIN property_financials f ON p.id = f.property_id
            $where
              AND f.sold_price IS NOT NULL
              AND p.created_by IS NOT NULL
            GROUP BY p.created_by
            ORDER BY ventas DESC
            LIMIT 10
        ");
        $stmtAg->execute($params);
        $porAgente = $stmtAg->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Error agentes: " . $e->getMessage());
}

// ===== Puntos para el mapa =====
$puntos = [];
try {
    $stmtPuntos = $conn->prepare("
        SELECT 
            p.id,
            p.title,
            p.address_municipality AS zona,
            p.address_lat AS lat,
            p.address_lng AS lng,
            p.operation_type,
            f.asking_price,
            f.sold_price,
            f.sold_at,
            DATEDIFF(f.sold_at, p.created_at) AS dias_mercado
        FROM properties p
        INNER JOIN property_financials f ON p.id = f.property_id
        $where
          AND p.address_lat IS NOT NULL
          AND p.address_lng IS NOT NULL
          AND p.address_lat != ''
          AND p.address_lng != ''
          AND f.sold_price IS NOT NULL
        ORDER BY f.sold_at DESC
    ");
    $stmtPuntos->execute($params);
    $puntos = $stmtPuntos->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error puntos: " . $e->getMessage());
}

// Centrar mapa: promedio de coordenadas si hay puntos, si no, default
$centroLat = 19.4326; // CDMX por defecto
$centroLng = -99.1332;
if (!empty($puntos)) {
    $sumLat = 0; $sumLng = 0; $n = 0;
    foreach ($puntos as $pt) {
        if (is_numeric($pt['lat']) && is_numeric($pt['lng'])) {
            $sumLat += floatval($pt['lat']);
            $sumLng += floatval($pt['lng']);
            $n++;
        }
    }
    if ($n > 0) {
        $centroLat = $sumLat / $n;
        $centroLng = $sumLng / $n;
    }
}

// ===== Helpers =====
function formatoMoneda($v) {
    if ($v === null || $v === '' || $v == 0) return '$0';
    return '$' . number_format(floatval($v), 0, ',', '.');
}
function formatoNumero($v, $dec = 0) {
    if ($v === null || $v === '') return '0';
    return number_format(floatval($v), $dec, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/socios.css">
    <title>Portal de Métricas de Ventas | Inmobiliaria MH</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * { box-sizing: border-box; }

        body {
            background: #f1f5f9;
            margin: 0;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            color: #0f172a;
        }

        /* Reutiliza la estructura del main-content del sidebar existente */
        .main-content { padding: 24px; }

        .portal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }
        .portal-header h1 {
            font-size: 1.4rem;
            margin: 0;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .portal-header h1 i { color: #1d4ed8; }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #1d4ed8;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            padding: 8px 14px;
            border-radius: 8px;
            background: #fff;
            border: 1px solid #e2e8f0;
            transition: all 0.15s;
        }
        .back-link:hover { background: #eff6ff; border-color: #93c5fd; }

        /* ===== Filtros ===== */
        .filtros-panel {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 18px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        .filtro-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 130px;
        }
        .filtro-group label {
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-weight: 700;
            color: #64748b;
        }
        .filtro-group input,
        .filtro-group select {
            padding: 7px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.82rem;
            background: #fff;
            color: #0f172a;
            outline: none;
            transition: border-color 0.15s;
        }
        .filtro-group input:focus,
        .filtro-group select:focus {
            border-color: #1d4ed8;
            box-shadow: 0 0 0 3px rgba(29,78,216,0.1);
        }
        .filtro-actions {
            display: flex;
            gap: 6px;
            margin-left: auto;
        }
        .btn-filter {
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: all 0.15s;
        }
        .btn-filter.primary { background: #1d4ed8; color: #fff; }
        .btn-filter.primary:hover { background: #1e40af; }
        .btn-filter.ghost {
            background: #f1f5f9;
            color: #475569;
        }
        .btn-filter.ghost:hover { background: #e2e8f0; }

        /* ===== KPIs ===== */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }
        .kpi-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px 18px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            transition: all 0.15s;
        }
        .kpi-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transform: translateY(-2px);
        }
        .kpi-card .kpi-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .kpi-card .kpi-icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
        }
        .kpi-card.blue .kpi-icon { background: #dbeafe; color: #1d4ed8; }
        .kpi-card.green .kpi-icon { background: #dcfce7; color: #16a34a; }
        .kpi-card.purple .kpi-icon { background: #ede9fe; color: #7c3aed; }
        .kpi-card.orange .kpi-icon { background: #ffedd5; color: #ea580c; }
        .kpi-card.teal .kpi-icon { background: #ccfbf1; color: #0d9488; }
        .kpi-card.pink .kpi-icon { background: #fce7f3; color: #db2777; }

        .kpi-card .kpi-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #64748b;
            font-weight: 700;
        }
        .kpi-card .kpi-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.1;
            font-variant-numeric: tabular-nums;
        }
        .kpi-card .kpi-sub {
            font-size: 0.72rem;
            color: #94a3b8;
        }

        /* ===== Paneles ===== */
        .panel {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 20px;
        }
        .panel-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 0 0 14px 0;
            font-size: 0.95rem;
            font-weight: 700;
            color: #0f172a;
            gap: 10px;
            flex-wrap: wrap;
        }
        .panel-title i { color: #1d4ed8; margin-right: 6px; }
        .panel-title .badge-info {
            font-size: 0.7rem;
            font-weight: 600;
            color: #64748b;
            background: #f1f5f9;
            padding: 3px 10px;
            border-radius: 12px;
        }

        /* ===== Mapa ===== */
        #map {
            height: 520px;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }
        .map-legend {
            display: flex;
            gap: 16px;
            margin-top: 10px;
            font-size: 0.75rem;
            color: #475569;
            flex-wrap: wrap;
        }
        .map-legend span {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .legend-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
        }
        .legend-dot.blue { background: #3b82f6; }
        .legend-dot.orange { background: #f59e0b; }
        .legend-dot.green { background: #10b981; }

        /* ===== Tabla ===== */
        .table-wrap { overflow-x: auto; }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
            min-width: 640px;
        }
        table.data-table th {
            text-align: left;
            padding: 10px 12px;
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #64748b;
            border-bottom: 2px solid #e2e8f0;
            background: #f8fafc;
            font-weight: 700;
            white-space: nowrap;
        }
        table.data-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            font-variant-numeric: tabular-nums;
        }
        table.data-table tr:hover td { background: #f8fafc; }
        table.data-table td.zona-name {
            font-weight: 700;
            color: #0f172a;
        }
        .price-pill {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.75rem;
        }
        .price-pill.avg { background: #dbeafe; color: #1e40af; }
        .price-pill.min { background: #dcfce7; color: #166534; }
        .price-pill.max { background: #fee2e2; color: #991b1b; }

        /* ===== Gráficos ===== */
        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .chart-box {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px 20px;
        }
        .chart-box h4 {
            margin: 0 0 12px 0;
            font-size: 0.88rem;
            color: #0f172a;
            font-weight: 700;
        }
        .chart-box canvas { max-height: 280px; }

        /* ===== Empty state ===== */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #64748b;
        }
        .empty-state i {
            font-size: 3rem;
            color: #cbd5e1;
            margin-bottom: 12px;
            display: block;
        }
        .empty-state h3 { color: #334155; margin: 0 0 6px 0; }

        /* ===== Responsive ===== */
        @media (max-width: 992px) {
            .charts-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .main-content { padding: 14px; }
            .filtro-actions { margin-left: 0; width: 100%; }
            .filtro-actions .btn-filter { flex: 1; justify-content: center; }
            #map { height: 380px; }
            .kpi-card .kpi-value { font-size: 1.25rem; }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php include 'modulos/sidebar.php'; ?>

<main class="main-content">
    <!-- Header -->
    <div class="portal-header">
        <h1>
            <i class="fas fa-chart-line"></i>
            Portal de Métricas de Ventas
        </h1>
        <a href="inventario_maestro.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Volver al Inventario
        </a>
    </div>

    <!-- Filtros -->
    <form method="GET" class="filtros-panel">
        <div class="filtro-group">
            <label for="desde">Desde</label>
            <input type="date" name="desde" id="desde" value="<?php echo htmlspecialchars($desdeValido); ?>">
        </div>
        <div class="filtro-group">
            <label for="hasta">Hasta</label>
            <input type="date" name="hasta" id="hasta" value="<?php echo htmlspecialchars($hastaValido); ?>">
        </div>
        <div class="filtro-group">
            <label for="zona">Zona / Municipio</label>
            <select name="zona" id="zona">
                <option value="">Todas las zonas</option>
                <?php foreach ($zonasDisponibles as $z): ?>
                    <option value="<?php echo htmlspecialchars($z); ?>" <?php echo $filtroZona === $z ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($z); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filtro-group">
            <label for="operacion">Operación</label>
            <select name="operacion" id="operacion">
                <option value="">Todas</option>
                <option value="venta" <?php echo $filtroOperacion === 'venta' ? 'selected' : ''; ?>>Venta</option>
                <option value="compra" <?php echo $filtroOperacion === 'compra' ? 'selected' : ''; ?>>Compra</option>
                <option value="renta" <?php echo $filtroOperacion === 'renta' ? 'selected' : ''; ?>>Renta</option>
                <option value="alquiler" <?php echo $filtroOperacion === 'alquiler' ? 'selected' : ''; ?>>Alquiler</option>
            </select>
        </div>
        <div class="filtro-actions">
            <button type="submit" class="btn-filter primary">
                <i class="fas fa-filter"></i> Aplicar
            </button>
            <a href="mapa_ventas.php" class="btn-filter ghost">
                <i class="fas fa-times"></i> Limpiar
            </a>
        </div>
    </form>

    <!-- KPIs -->
    <div class="kpi-grid">
        <div class="kpi-card blue">
            <div class="kpi-head">
                <span class="kpi-label">Viviendas vendidas</span>
                <span class="kpi-icon"><i class="fas fa-home"></i></span>
            </div>
            <div class="kpi-value"><?php echo formatoNumero($kpis['total_vendidas'] ?? 0); ?></div>
            <div class="kpi-sub">En el periodo seleccionado</div>
        </div>

        <div class="kpi-card green">
            <div class="kpi-head">
                <span class="kpi-label">Precio promedio</span>
                <span class="kpi-icon"><i class="fas fa-dollar-sign"></i></span>
            </div>
            <div class="kpi-value"><?php echo formatoMoneda($kpis['avg_precio'] ?? 0); ?></div>
            <div class="kpi-sub">Por vivienda vendida</div>
        </div>

        <div class="kpi-card purple">
            <div class="kpi-head">
                <span class="kpi-label">Volumen total</span>
                <span class="kpi-icon"><i class="fas fa-chart-bar"></i></span>
            </div>
            <div class="kpi-value"><?php echo formatoMoneda($kpis['volumen_total'] ?? 0); ?></div>
            <div class="kpi-sub">Suma de todas las ventas</div>
        </div>

        <div class="kpi-card orange">
            <div class="kpi-head">
                <span class="kpi-label">Días prom. en mercado</span>
                <span class="kpi-icon"><i class="fas fa-clock"></i></span>
            </div>
            <div class="kpi-value"><?php echo formatoNumero($kpis['dias_promedio'] ?? 0); ?> días</div>
            <div class="kpi-sub">Desde alta hasta venta</div>
        </div>

        <div class="kpi-card teal">
            <div class="kpi-head">
                <span class="kpi-label">Precio promedio pedido</span>
                <span class="kpi-icon"><i class="fas fa-tag"></i></span>
            </div>
            <div class="kpi-value"><?php echo formatoMoneda($kpis['avg_asking'] ?? 0); ?></div>
            <div class="kpi-sub">Precio de salida promedio</div>
        </div>

        <div class="kpi-card pink">
            <div class="kpi-head">
                <span class="kpi-label">Descuento promedio</span>
                <span class="kpi-icon"><i class="fas fa-percent"></i></span>
            </div>
            <div class="kpi-value"><?php echo formatoMoneda($kpis['avg_descuento'] ?? 0); ?></div>
            <div class="kpi-sub">Diferencia pedido vs venta</div>
        </div>
    </div>

    <!-- Mapa -->
    <div class="panel">
        <div class="panel-title">
            <span><i class="fas fa-map-marked-alt"></i> Mapa de Viviendas Vendidas</span>
            <span class="badge-info"><?php echo count($puntos); ?> punto(s)</span>
        </div>

        <?php if (empty($puntos)): ?>
            <div class="empty-state">
                <i class="fas fa-map-pin"></i>
                <h3>Sin coordenadas registradas</h3>
                <p>Agrega latitud y longitud a tus propiedades vendidas para verlas aquí.</p>
            </div>
        <?php else: ?>
            <div id="map"></div>
            <div class="map-legend">
                <span><span class="legend-dot blue"></span> Venta &lt; $1M</span>
                <span><span class="legend-dot orange"></span> Venta $1M - $3M</span>
                <span><span class="legend-dot green"></span> Venta &gt; $3M</span>
            </div>
        <?php endif; ?>
    </div>

    <!-- Gráficos -->
    <div class="charts-grid">
        <div class="chart-box">
            <h4><i class="fas fa-chart-bar"></i> Precio promedio por zona</h4>
            <canvas id="chartZonas"></canvas>
        </div>
        <div class="chart-box">
            <h4><i class="fas fa-chart-line"></i> Evolución mensual</h4>
            <canvas id="chartEvolucion"></canvas>
        </div>
    </div>

    <!-- Tabla por zona -->
    <div class="panel">
        <div class="panel-title">
            <span><i class="fas fa-table"></i> Métricas por Zona</span>
            <span class="badge-info"><?php echo count($porZona); ?> zona(s)</span>
        </div>

        <?php if (empty($porZona)): ?>
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <h3>Sin datos por zona</h3>
                <p>No hay ventas registradas con los filtros actuales.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Zona</th>
                            <th>Ventas</th>
                            <th>Precio promedio</th>
                            <th>Mínimo</th>
                            <th>Máximo</th>
                            <th>Días prom.</th>
                            <th>Volumen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($porZona as $z): ?>
                            <tr>
                                <td class="zona-name"><?php echo htmlspecialchars($z['zona'] ?? 'Sin zona'); ?></td>
                                <td><strong><?php echo (int)$z['ventas']; ?></strong></td>
                                <td><span class="price-pill avg"><?php echo formatoMoneda($z['precio_promedio']); ?></span></td>
                                <td><span class="price-pill min"><?php echo formatoMoneda($z['precio_min']); ?></span></td>
                                <td><span class="price-pill max"><?php echo formatoMoneda($z['precio_max']); ?></span></td>
                                <td><?php echo formatoNumero($z['dias_promedio']); ?> días</td>
                                <td><?php echo formatoMoneda($z['volumen']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Top agentes (solo si hay datos) -->
    <?php if (!empty($porAgente)): ?>
    <div class="panel">
        <div class="panel-title">
            <span><i class="fas fa-user-tie"></i> Top Agentes por Ventas</span>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Agente</th>
                        <th>Ventas</th>
                        <th>Precio promedio</th>
                        <th>Volumen total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($porAgente as $a): ?>
                        <tr>
                            <td class="zona-name"><?php echo htmlspecialchars($a['agente']); ?></td>
                            <td><strong><?php echo (int)$a['ventas']; ?></strong></td>
                            <td><span class="price-pill avg"><?php echo formatoMoneda($a['precio_promedio']); ?></span></td>
                            <td><?php echo formatoMoneda($a['volumen']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</main>

<script>
// ===== Datos PHP → JS =====
const PUNTOS = <?php echo json_encode($puntos, JSON_UNESCAPED_UNICODE); ?>;
const POR_ZONA = <?php echo json_encode($porZona, JSON_UNESCAPED_UNICODE); ?>;
const EVOLUCION = <?php echo json_encode($evolucion, JSON_UNESCAPED_UNICODE); ?>;
const CENTRO = { lat: <?php echo json_encode($centroLat); ?>, lng: <?php echo json_encode($centroLng); ?> };

// ===== Sidebar móvil (reutilizado) =====
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
});

// ===== Formato moneda JS =====
function fmtMoney(v) {
    if (!v || isNaN(v)) return '$0';
    return '$' + Number(v).toLocaleString('es-MX', { maximumFractionDigits: 0 });
}
function fmtNumber(v, dec = 0) {
    if (!v || isNaN(v)) return '0';
    return Number(v).toLocaleString('es-MX', { minimumFractionDigits: dec, maximumFractionDigits: dec });
}
function fmtDate(s) {
    if (!s) return 'N/D';
    const d = new Date(s.replace(' ', 'T'));
    if (isNaN(d.getTime())) return 'N/D';
    return d.toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' });
}

// ===== MAPA =====
(function initMap() {
    const mapEl = document.getElementById('map');
    if (!mapEl) return;

    const map = L.map('map').setView([CENTRO.lat, CENTRO.lng], 11);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© OpenStreetMap'
    }).addTo(map);

    if (!PUNTOS.length) return;

    const bounds = [];

    PUNTOS.forEach(p => {
        const lat = parseFloat(p.lat);
        const lng = parseFloat(p.lng);
        if (isNaN(lat) || isNaN(lng)) return;

        const precio = parseFloat(p.sold_price) || 0;
        let color = '#3b82f6'; // azul
        if (precio >= 1000000 && precio < 3000000) color = '#f59e0b'; // naranja
        else if (precio >= 3000000) color = '#10b981'; // verde

        const marker = L.circleMarker([lat, lng], {
            radius: 9,
            color: '#fff',
            weight: 2,
            fillColor: color,
            fillOpacity: 0.85
        }).addTo(map);

        const dias = p.dias_mercado ? `${p.dias_mercado} días` : 'N/D';
        const popupHtml = `
            <div style="font-family: system-ui; min-width: 220px;">
                <div style="font-weight: 700; font-size: 0.9rem; margin-bottom: 6px; color:#0f172a;">
                    ${p.title || 'Sin título'}
                </div>
                <div style="font-size: 0.78rem; color:#475569; margin-bottom: 6px;">
                    <i style="color:#10b981;">📍</i> ${p.zona || 'Sin zona'}
                    ${p.direccion ? '<br><span style="color:#94a3b8;">' + p.direccion + '</span>' : ''}
                </div>
                <div style="font-size: 0.78rem; padding-top: 6px; border-top: 1px solid #e2e8f0;">
                    <div style="display:flex; justify-content:space-between; margin-bottom:3px;">
                        <span style="color:#64748b;">Precio pedido:</span>
                        <strong>${fmtMoney(p.asking_price)}</strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; margin-bottom:3px;">
                        <span style="color:#64748b;">Vendida en:</span>
                        <strong style="color:#16a34a;">${fmtMoney(p.sold_price)}</strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; margin-bottom:3px;">
                        <span style="color:#64748b;">Fecha venta:</span>
                        <strong>${fmtDate(p.sold_at)}</strong>
                    </div>
                    <div style="display:flex; justify-content:space-between;">
                        <span style="color:#64748b;">Días mercado:</span>
                        <strong>${dias}</strong>
                    </div>
                </div>
            </div>
        `;
        marker.bindPopup(popupHtml);
        bounds.push([lat, lng]);
    });

    if (bounds.length > 1) {
        map.fitBounds(bounds, { padding: [30, 30] });
    } else if (bounds.length === 1) {
        map.setView(bounds[0], 14);
    }
})();

// ===== GRÁFICO ZONAS =====
(function initChartZonas() {
    const el = document.getElementById('chartZonas');
    if (!el || !POR_ZONA.length) {
        if (el) el.parentElement.innerHTML += '<p style="color:#94a3b8; font-size:0.85rem; text-align:center; padding:20px;">Sin datos para graficar</p>';
        return;
    }

    new Chart(el, {
        type: 'bar',
        data: {
            labels: POR_ZONA.map(z => z.zona || 'Sin zona'),
            datasets: [
                {
                    label: 'Precio promedio',
                    data: POR_ZONA.map(z => parseFloat(z.precio_promedio) || 0),
                    backgroundColor: '#3b82f6',
                    borderRadius: 6
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => ' ' + fmtMoney(ctx.parsed.y)
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: (v) => fmtMoney(v)
                    },
                    grid: { color: '#f1f5f9' }
                },
                x: { grid: { display: false } }
            }
        }
    });
})();

// ===== GRÁFICO EVOLUCIÓN =====
(function initChartEvolucion() {
    const el = document.getElementById('chartEvolucion');
    if (!el || !EVOLUCION.length) {
        if (el) el.parentElement.innerHTML += '<p style="color:#94a3b8; font-size:0.85rem; text-align:center; padding:20px;">Sin datos para graficar</p>';
        return;
    }

    new Chart(el, {
        type: 'line',
        data: {
            labels: EVOLUCION.map(e => e.mes),
            datasets: [
                {
                    label: 'Precio promedio',
                    data: EVOLUCION.map(e => parseFloat(e.precio_promedio) || 0),
                    borderColor: '#7c3aed',
                    backgroundColor: 'rgba(124,58,237,0.1)',
                    tension: 0.35,
                    fill: true,
                    pointBackgroundColor: '#7c3aed',
                    pointRadius: 4,
                    yAxisID: 'y'
                },
                {
                    label: 'Nº ventas',
                    data: EVOLUCION.map(e => parseInt(e.ventas) || 0),
                    borderColor: '#f59e0b',
                    backgroundColor: 'rgba(245,158,11,0.1)',
                    tension: 0.35,
                    pointBackgroundColor: '#f59e0b',
                    pointRadius: 4,
                    borderDash: [5, 5],
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { font: { size: 11 } } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            if (ctx.dataset.label.includes('Precio')) {
                                return ' ' + ctx.dataset.label + ': ' + fmtMoney(ctx.parsed.y);
                            }
                            return ' ' + ctx.dataset.label + ': ' + ctx.parsed.y;
                        }
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    beginAtZero: true,
                    ticks: { callback: (v) => fmtMoney(v) },
                    grid: { color: '#f1f5f9' }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    beginAtZero: true,
                    ticks: { precision: 0 },
                    grid: { drawOnChartArea: false }
                },
                x: { grid: { display: false } }
            }
        }
    });
})();
</script>

</body>
</html>