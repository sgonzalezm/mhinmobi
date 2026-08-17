<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/conexion.php';
require_once 'includes/auth.php';
require_once 'includes/calendar_functions.php';

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

// Variables para filtros
$mes_actual = isset($_GET['mes']) ? intval($_GET['mes']) : date('m');
$anio_actual = isset($_GET['anio']) ? intval($_GET['anio']) : date('Y');

// Si se solicitó cambio de mes
if (isset($_GET['mes']) && isset($_GET['anio'])) {
    $mes_actual = intval($_GET['mes']);
    $anio_actual = intval($_GET['anio']);
}

// Obtener eventos del mes
$eventos_mes = obtenerEventosPorMes($conn, $mes_actual, $anio_actual);

// Obtener eventos próximos para dashboard
$eventos_proximos = obtenerEventosProximos($conn, 10);

// Obtener vencimientos próximos
$vencimientos = obtenerVencimientosProximos($conn, 30);

// Obtener todas las propiedades para filtros
$propiedades_usuario = [];
try {
    $stmt = $conn->prepare("SELECT id, title FROM properties WHERE user_id = ? AND status = 'activo' ORDER BY title");
    $stmt->execute([$_SESSION['user_id']]);
    $propiedades_usuario = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al obtener propiedades: " . $e->getMessage());
}

// Navegación entre meses
$mes_anterior = $mes_actual == 1 ? 12 : $mes_actual - 1;
$anio_anterior = $mes_actual == 1 ? $anio_actual - 1 : $anio_actual;
$mes_siguiente = $mes_actual == 12 ? 1 : $mes_actual + 1;
$anio_siguiente = $mes_actual == 12 ? $anio_actual + 1 : $anio_actual;

// Nombres de meses
$nombres_meses = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
];

// Función para obtener el nombre del mes
function nombreMes($mes) {
    $nombres = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
    ];
    return $nombres[$mes] ?? 'Mes';
}

// Función para obtener días del mes
function diasDelMes($mes, $anio) {
    return cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
}

// Función para obtener el día de la semana del primer día del mes (0=Domingo)
function primerDiaMes($mes, $anio) {
    return date('w', strtotime("$anio-$mes-01"));
}

// Función para formatear fecha para el calendario
function formatearFechaEvento($fecha) {
    $timestamp = strtotime($fecha);
    return date('H:i', $timestamp);
}

// Función para verificar si hay eventos en un día específico
function hayEventosEnDia($eventos, $dia) {
    foreach ($eventos as $evento) {
        if (date('d', strtotime($evento['start_datetime'])) == $dia) {
            return true;
        }
    }
    return false;
}

// Función para obtener eventos de un día específico
function obtenerEventosDia($eventos, $dia) {
    $result = [];
    foreach ($eventos as $evento) {
        if (date('d', strtotime($evento['start_datetime'])) == $dia) {
            $result[] = $evento;
        }
    }
    return $result;
}

// Estadísticas del calendario
$stats = [
    'total' => count($eventos_mes),
    'visitas' => 0,
    'vencimientos' => 0,
    'mantenimiento' => 0,
    'contratos' => 0,
    'reuniones' => 0,
    'pendientes' => 0,
    'confirmados' => 0,
    'completados' => 0
];

foreach ($eventos_mes as $e) {
    // Por tipo
    switch ($e['event_type']) {
        case 'visit': $stats['visitas']++; break;
        case 'expiration': $stats['vencimientos']++; break;
        case 'maintenance': $stats['mantenimiento']++; break;
        case 'contract': $stats['contratos']++; break;
        case 'meeting': $stats['reuniones']++; break;
    }
    
    // Por estado
    switch ($e['status']) {
        case 'pending': $stats['pendientes']++; break;
        case 'confirmed': $stats['confirmados']++; break;
        case 'completed': $stats['completados']++; break;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendario | Inmobiliaria MH</title>
    <link rel="stylesheet" href="css/socios.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ===== ESTILOS DEL CALENDARIO ===== */
        .calendar-container {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e8edf4;
            padding: 20px;
            margin-top: 15px;
        }

        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .calendar-header h2 {
            font-size: 1.3rem;
            font-weight: 600;
            color: #0f172a;
            margin: 0;
        }

        .calendar-nav {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .calendar-nav button {
            background: #f1f5f9;
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            color: #475569;
            cursor: pointer;
            transition: all 0.15s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .calendar-nav button:hover {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .calendar-nav .month-label {
            font-size: 1rem;
            font-weight: 500;
            color: #0f172a;
            min-width: 140px;
            text-align: center;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
        }

        .calendar-grid .day-header {
            padding: 10px 5px;
            text-align: center;
            font-weight: 600;
            font-size: 0.75rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .calendar-grid .day-cell {
            min-height: 80px;
            padding: 4px 6px;
            background: #fafcff;
            border: 1px solid #f1f5f9;
            border-radius: 6px;
            cursor: default;
            transition: all 0.1s;
            position: relative;
        }

        .calendar-grid .day-cell:hover {
            background: #f8faff;
            border-color: #dbeafe;
        }

        .calendar-grid .day-cell.empty {
            background: transparent;
            border: none;
            cursor: default;
        }

        .calendar-grid .day-cell .day-number {
            font-size: 0.75rem;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 4px;
            display: block;
        }

        .calendar-grid .day-cell .day-number.other-month {
            color: #94a3b8;
        }

        .calendar-grid .day-cell .day-number.today {
            color: #1d4ed8;
            background: #dbeafe;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .calendar-event-dot {
            display: inline-block;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            margin: 1px;
        }

        .calendar-event-dot.visit { background: #10b981; }
        .calendar-event-dot.expiration { background: #ef4444; }
        .calendar-event-dot.maintenance { background: #f59e0b; }
        .calendar-event-dot.contract { background: #3b82f6; }
        .calendar-event-dot.meeting { background: #8b5cf6; }
        .calendar-event-dot.other { background: #6b7280; }

        .calendar-event-tooltip {
            display: none;
            position: absolute;
            background: #0f172a;
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.7rem;
            min-width: 150px;
            z-index: 100;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            bottom: 100%;
            left: 0;
            margin-bottom: 4px;
        }

        .day-cell:hover .calendar-event-tooltip {
            display: block;
        }

        .calendar-event-tooltip .event-item {
            padding: 3px 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .calendar-event-tooltip .event-item:last-child {
            border-bottom: none;
        }

        .calendar-event-tooltip .event-time {
            color: #94a3b8;
            font-size: 0.65rem;
        }

        /* Estadísticas rápidas */
        .calendar-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 8px;
            margin-bottom: 20px;
        }

        .calendar-stats .stat-item {
            background: #f8fafc;
            padding: 8px 12px;
            border-radius: 8px;
            text-align: center;
        }

        .calendar-stats .stat-item .stat-number {
            font-size: 1rem;
            font-weight: 700;
            color: #0f172a;
        }

        .calendar-stats .stat-item .stat-label {
            font-size: 0.65rem;
            color: #64748b;
        }

        /* Lista de eventos del día */
        .day-events-list {
            margin-top: 20px;
            border-top: 1px solid #e8edf4;
            padding-top: 15px;
        }

        .day-events-list h3 {
            font-size: 0.9rem;
            color: #0f172a;
            margin-bottom: 10px;
        }

        .day-event-item {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            background: #f8fafc;
            border-radius: 6px;
            margin-bottom: 4px;
            gap: 10px;
            font-size: 0.8rem;
        }

        .day-event-item .event-color {
            width: 4px;
            height: 24px;
            border-radius: 2px;
        }

        .day-event-item .event-time {
            color: #64748b;
            font-size: 0.7rem;
            min-width: 60px;
        }

        .day-event-item .event-title {
            flex: 1;
            color: #0f172a;
        }

        .day-event-item .event-badge {
            font-size: 0.6rem;
            padding: 1px 8px;
            border-radius: 10px;
            text-transform: uppercase;
            font-weight: 600;
        }

        .day-event-item .event-badge.pending { background: #fef3c7; color: #92400e; }
        .day-event-item .event-badge.confirmed { background: #dbeafe; color: #1e40af; }
        .day-event-item .event-badge.completed { background: #dcfce7; color: #166534; }
        .day-event-item .event-badge.cancelled { background: #fee2e2; color: #991b1b; }

        /* Botón nuevo evento */
        .btn-nuevo-evento {
            background: #1d4ed8;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.15s;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-nuevo-evento:hover {
            background: #1e40af;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(29, 78, 216, 0.3);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .calendar-grid .day-cell {
                min-height: 60px;
                padding: 2px 4px;
            }
            
            .calendar-grid .day-cell .day-number {
                font-size: 0.65rem;
            }
            
            .calendar-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .calendar-nav {
                justify-content: center;
            }
            
            .calendar-stats {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 480px) {
            .calendar-grid {
                gap: 2px;
            }
            
            .calendar-grid .day-cell {
                min-height: 50px;
                padding: 2px;
            }
            
            .calendar-grid .day-cell .day-number {
                font-size: 0.6rem;
            }
            
            .calendar-event-dot {
                width: 4px;
                height: 4px;
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php include 'sidebar.php'; ?>

<main class="main-content">
    <div class="main-header">
        <div class="header-left">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <h1><i class="fas fa-calendar-alt"></i> Calendario</h1>
            <p class="welcome">
                <i class="fas fa-clock"></i> Gestión de visitas, vencimientos y eventos
            </p>
        </div>
        <div class="header-actions">
            <button class="btn-header primary" onclick="abrirModalEvento()">
                <i class="fas fa-plus"></i> Nuevo Evento
            </button>
        </div>
    </div>

    <!-- Estadísticas rápidas -->
    <div class="stats-grid" style="margin-bottom: 15px;">
        <div class="stat-card">
            <span class="stat-icon"><i class="fas fa-calendar-day"></i></span>
            <div class="stat-number"><?php echo $stats['total']; ?></div>
            <div class="stat-label">Eventos este mes</div>
        </div>
        <div class="stat-card success">
            <span class="stat-icon"><i class="fas fa-calendar-check"></i></span>
            <div class="stat-number"><?php echo $stats['visitas']; ?></div>
            <div class="stat-label">Visitas</div>
        </div>
        <div class="stat-card warning">
            <span class="stat-icon"><i class="fas fa-clock"></i></span>
            <div class="stat-number"><?php echo $stats['vencimientos']; ?></div>
            <div class="stat-label">Vencimientos</div>
        </div>
    </div>

    <!-- Calendario -->
    <div class="calendar-container">
        <div class="calendar-header">
            <h2><?php echo nombreMes($mes_actual) . ' ' . $anio_actual; ?></h2>
            <div class="calendar-nav">
                <button onclick="cambiarMes(-1)" title="Mes anterior">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <span class="month-label"><?php echo nombreMes($mes_actual) . ' ' . $anio_actual; ?></span>
                <button onclick="cambiarMes(1)" title="Mes siguiente">
                    <i class="fas fa-chevron-right"></i>
                </button>
                <button onclick="irHoy()" title="Hoy">
                    <i class="fas fa-calendar-day"></i>
                </button>
            </div>
        </div>

        <div class="calendar-stats">
            <div class="stat-item">
                <div class="stat-number"><?php echo $stats['pendientes']; ?></div>
                <div class="stat-label">Pendientes</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $stats['confirmados']; ?></div>
                <div class="stat-label">Confirmados</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $stats['completados']; ?></div>
                <div class="stat-label">Completados</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $stats['mantenimiento']; ?></div>
                <div class="stat-label">Mantenimiento</div>
            </div>
        </div>

        <div class="calendar-grid">
            <!-- Días de la semana -->
            <div class="day-header">Dom</div>
            <div class="day-header">Lun</div>
            <div class="day-header">Mar</div>
            <div class="day-header">Mié</div>
            <div class="day-header">Jue</div>
            <div class="day-header">Vie</div>
            <div class="day-header">Sáb</div>

            <?php
            $primer_dia = primerDiaMes($mes_actual, $anio_actual);
            $dias_mes = diasDelMes($mes_actual, $anio_actual);
            $hoy = date('d');
            $mes_hoy = date('m');
            $anio_hoy = date('Y');

            // Días del mes anterior para rellenar el inicio
            $dias_mes_anterior = diasDelMes($mes_anterior, $anio_anterior);
            $inicio = $primer_dia;
            $contador = 1;

            // Rellenar días vacíos al inicio
            for ($i = 0; $i < $inicio; $i++) {
                $dia_anterior = $dias_mes_anterior - $inicio + $i + 1;
                echo '<div class="day-cell empty"></div>';
            }

            // Días del mes actual
            for ($dia = 1; $dia <= $dias_mes; $dia++) {
                $es_hoy = ($dia == $hoy && $mes_actual == $mes_hoy && $anio_actual == $anio_hoy);
                $eventos_dia = obtenerEventosDia($eventos_mes, $dia);
                $clase_hoy = $es_hoy ? 'today' : '';
                
                echo '<div class="day-cell" data-dia="' . $dia . '">';
                echo '<span class="day-number ' . $clase_hoy . '">' . $dia . '</span>';
                
                // Mostrar pequeños indicadores de eventos
                if (!empty($eventos_dia)) {
                    echo '<div style="display: flex; flex-wrap: wrap; gap: 2px; margin-top: 2px;">';
                    foreach ($eventos_dia as $evento) {
                        $tipo = $evento['event_type'];
                        echo '<span class="calendar-event-dot ' . $tipo . '" title="' . htmlspecialchars($evento['title']) . '"></span>';
                    }
                    echo '</div>';
                }

                // Tooltip con eventos del día
                if (!empty($eventos_dia)) {
                    echo '<div class="calendar-event-tooltip">';
                    foreach ($eventos_dia as $evento) {
                        echo '<div class="event-item">';
                        echo '<div class="event-time">' . formatearFechaEvento($evento['start_datetime']) . '</div>';
                        echo '<div>' . htmlspecialchars($evento['title']) . '</div>';
                        echo '</div>';
                    }
                    echo '</div>';
                }

                echo '</div>';
            }

            // Rellenar días vacíos al final
            $total_celdas = $inicio + $dias_mes;
            $resto = 7 - ($total_celdas % 7);
            if ($resto < 7) {
                for ($i = 1; $i <= $resto; $i++) {
                    echo '<div class="day-cell empty"></div>';
                }
            }
            ?>
        </div>

        <!-- Leyenda -->
        <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-top: 15px; padding-top: 15px; border-top: 1px solid #e8edf4; font-size: 0.75rem; color: #64748b;">
            <span><span class="calendar-event-dot visit" style="display: inline-block;"></span> Visita</span>
            <span><span class="calendar-event-dot expiration" style="display: inline-block;"></span> Vencimiento</span>
            <span><span class="calendar-event-dot maintenance" style="display: inline-block;"></span> Mantenimiento</span>
            <span><span class="calendar-event-dot contract" style="display: inline-block;"></span> Contrato</span>
            <span><span class="calendar-event-dot meeting" style="display: inline-block;"></span> Reunión</span>
            <span><span class="calendar-event-dot other" style="display: inline-block;"></span> Otro</span>
        </div>
    </div>

    <!-- Próximos eventos -->
    <div style="margin-top: 20px; display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
        <div class="calendar-container" style="padding: 16px;">
            <h3 style="font-size: 0.9rem; margin: 0 0 10px 0; color: #0f172a;">
                <i class="fas fa-calendar-plus" style="color: #3b82f6;"></i> Próximos Eventos
            </h3>
            <?php if (empty($eventos_proximos)): ?>
                <p style="color: #94a3b8; font-size: 0.8rem; text-align: center; padding: 10px 0;">No hay eventos próximos</p>
            <?php else: ?>
                <?php foreach (array_slice($eventos_proximos, 0, 5) as $evento): ?>
                    <div class="day-event-item" style="background: transparent; padding: 6px 0; border-bottom: 1px solid #f1f5f9;">
                        <div class="event-color" style="background: <?php echo $evento['color'] ?? '#6b7280'; ?>;"></div>
                        <div class="event-time"><?php echo date('d/m H:i', strtotime($evento['start_datetime'])); ?></div>
                        <div class="event-title"><?php echo htmlspecialchars($evento['title']); ?></div>
                        <span class="event-badge <?php echo obtenerClaseEstadoEvento($evento['status']); ?>"><?php echo $evento['status']; ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="calendar-container" style="padding: 16px;">
            <h3 style="font-size: 0.9rem; margin: 0 0 10px 0; color: #0f172a;">
                <i class="fas fa-clock" style="color: #ef4444;"></i> Vencimientos Próximos
            </h3>
            <?php if (empty($vencimientos)): ?>
                <p style="color: #94a3b8; font-size: 0.8rem; text-align: center; padding: 10px 0;">No hay vencimientos próximos</p>
            <?php else: ?>
                <?php foreach (array_slice($vencimientos, 0, 5) as $vencimiento): ?>
                    <div class="day-event-item" style="background: transparent; padding: 6px 0; border-bottom: 1px solid #f1f5f9;">
                        <div class="event-color" style="background: #ef4444;"></div>
                        <div class="event-time"><?php echo date('d/m/Y', strtotime($vencimiento['start_datetime'])); ?></div>
                        <div class="event-title"><?php echo htmlspecialchars($vencimiento['title']); ?></div>
                        <?php if (!empty($vencimiento['property_title'])): ?>
                            <span style="font-size: 0.65rem; color: #64748b;"><?php echo htmlspecialchars($vencimiento['property_title']); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- Modal para crear/editar evento -->
<div class="modal" id="eventoModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-content" style="background: white; border-radius: 12px; max-width: 600px; width: 95%; max-height: 90vh; overflow-y: auto; padding: 25px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 id="modalEventoTitle" style="margin: 0; font-size: 1.1rem; color: #0f172a;">
                <i class="fas fa-calendar-plus" style="color: #1d4ed8;"></i> Nuevo Evento
            </h3>
            <button onclick="cerrarModalEvento()" style="background: none; border: none; font-size: 1.2rem; color: #94a3b8; cursor: pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form id="eventoForm" onsubmit="guardarEvento(event)">
            <input type="hidden" id="evento_id" name="evento_id">
            
            <div style="margin-bottom: 12px;">
                <label style="display: block; font-weight: 500; font-size: 0.85rem; margin-bottom: 4px; color: #0f172a;">Título *</label>
                <input type="text" id="evento_title" name="title" required style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.85rem;">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="display: block; font-weight: 500; font-size: 0.85rem; margin-bottom: 4px; color: #0f172a;">Tipo *</label>
                    <select id="evento_type" name="event_type" required style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.85rem;">
                        <option value="visit">Visita</option>
                        <option value="expiration">Vencimiento</option>
                        <option value="maintenance">Mantenimiento</option>
                        <option value="contract">Contrato</option>
                        <option value="meeting">Reunión</option>
                        <option value="other">Otro</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-weight: 500; font-size: 0.85rem; margin-bottom: 4px; color: #0f172a;">Estado</label>
                    <select id="evento_status" name="status" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.85rem;">
                        <option value="pending">Pendiente</option>
                        <option value="confirmed">Confirmado</option>
                        <option value="completed">Completado</option>
                        <option value="cancelled">Cancelado</option>
                    </select>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="display: block; font-weight: 500; font-size: 0.85rem; margin-bottom: 4px; color: #0f172a;">Fecha/Hora Inicio *</label>
                    <input type="datetime-local" id="evento_start" name="start_datetime" required style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.85rem;">
                </div>
                <div>
                    <label style="display: block; font-weight: 500; font-size: 0.85rem; margin-bottom: 4px; color: #0f172a;">Fecha/Hora Fin</label>
                    <input type="datetime-local" id="evento_end" name="end_datetime" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.85rem;">
                </div>
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; font-weight: 500; font-size: 0.85rem; margin-bottom: 4px; color: #0f172a;">Descripción</label>
                <textarea id="evento_description" name="description" rows="2" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.85rem; resize: vertical;"></textarea>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="display: block; font-weight: 500; font-size: 0.85rem; margin-bottom: 4px; color: #0f172a;">Propiedad</label>
                    <select id="evento_property" name="property_id" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.85rem;">
                        <option value="">Sin propiedad</option>
                        <?php foreach ($propiedades_usuario as $prop): ?>
                            <option value="<?php echo $prop['id']; ?>"><?php echo htmlspecialchars($prop['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-weight: 500; font-size: 0.85rem; margin-bottom: 4px; color: #0f172a;">Ubicación</label>
                    <input type="text" id="evento_location" name="location" placeholder="Dirección o lugar" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.85rem;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="display: block; font-weight: 500; font-size: 0.85rem; margin-bottom: 4px; color: #0f172a;">Contacto</label>
                    <input type="text" id="evento_contact" name="contact_name" placeholder="Nombre del contacto" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.85rem;">
                </div>
                <div>
                    <label style="display: block; font-weight: 500; font-size: 0.85rem; margin-bottom: 4px; color: #0f172a;">Teléfono</label>
                    <input type="text" id="evento_phone" name="contact_phone" placeholder="Teléfono de contacto" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.85rem;">
                </div>
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; font-weight: 500; font-size: 0.85rem; margin-bottom: 4px; color: #0f172a;">Recordatorio (minutos antes)</label>
                <select id="evento_reminder" name="reminder_minutes" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.85rem;">
                    <option value="0">Sin recordatorio</option>
                    <option value="15">15 minutos</option>
                    <option value="30" selected>30 minutos</option>
                    <option value="60">1 hora</option>
                    <option value="120">2 horas</option>
                    <option value="1440">1 día</option>
                    <option value="10080">1 semana</option>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="display: block; font-weight: 500; font-size: 0.85rem; margin-bottom: 4px; color: #0f172a;">
                        <input type="checkbox" id="evento_recurring" name="recurring" value="1" onchange="toggleRecurrencia()"> Evento recurrente
                    </label>
                </div>
                <div id="recurrence_options" style="display: none;">
                    <label style="display: block; font-weight: 500; font-size: 0.85rem; margin-bottom: 4px; color: #0f172a;">Frecuencia</label>
                    <select id="evento_recurrence" name="recurrence_pattern" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.85rem;">
                        <option value="daily">Diario</option>
                        <option value="weekly">Semanal</option>
                        <option value="monthly">Mensual</option>
                        <option value="yearly">Anual</option>
                    </select>
                </div>
            </div>

            <div style="display: flex; gap: 10px; margin-top: 15px; padding-top: 15px; border-top: 1px solid #e8edf4;">
                <button type="submit" style="background: #1d4ed8; color: white; border: none; padding: 10px 24px; border-radius: 8px; font-weight: 500; cursor: pointer; flex: 1;">
                    <i class="fas fa-save"></i> Guardar
                </button>
                <button type="button" onclick="cerrarModalEvento()" style="background: #f1f5f9; color: #475569; border: none; padding: 10px 24px; border-radius: 8px; font-weight: 500; cursor: pointer;">
                    Cancelar
                </button>
            </div>
        </form>
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

    if(menuToggle) {
        menuToggle.addEventListener('click', toggleSidebar);
    }
    
    if(overlay) {
        overlay.addEventListener('click', toggleSidebar);
    }

    // Cerrar sidebar en móvil
    document.querySelectorAll('.sidebar nav a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 992 && sidebar && sidebar.classList.contains('open')) {
                toggleSidebar();
            }
        });
    });

    // Inicializar fecha/hora por defecto
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const defaultDateTime = `${year}-${month}-${day}T${hours}:${minutes}`;
    
    const startInput = document.getElementById('evento_start');
    if (startInput) {
        startInput.value = defaultDateTime;
    }

    // Clic en día del calendario para crear evento
    document.querySelectorAll('.day-cell:not(.empty)').forEach(cell => {
        cell.addEventListener('click', function() {
            const dia = this.getAttribute('data-dia');
            if (dia) {
                const fecha = `${year}-${month}-${String(dia).padStart(2, '0')}T${hours}:${minutes}`;
                document.getElementById('evento_start').value = fecha;
                abrirModalEvento();
            }
        });
    });
});

// ===== Funciones del calendario =====
function cambiarMes(direccion) {
    const params = new URLSearchParams(window.location.search);
    let mes = parseInt(params.get('mes') || <?php echo $mes_actual; ?>);
    let anio = parseInt(params.get('anio') || <?php echo $anio_actual; ?>);
    
    mes += direccion;
    if (mes > 12) { mes = 1; anio++; }
    if (mes < 1) { mes = 12; anio--; }
    
    window.location.href = `calendario.php?mes=${mes}&anio=${anio}`;
}

function irHoy() {
    window.location.href = 'calendario.php';
}

// ===== Modal de eventos =====
function abrirModalEvento(eventoData = null) {
    const modal = document.getElementById('eventoModal');
    const form = document.getElementById('eventoForm');
    form.reset();
    document.getElementById('evento_id').value = '';
    document.getElementById('modalEventoTitle').innerHTML = '<i class="fas fa-calendar-plus" style="color: #1d4ed8;"></i> Nuevo Evento';
    document.getElementById('recurrence_options').style.display = 'none';
    document.getElementById('evento_recurring').checked = false;
    
    modal.style.display = 'flex';
}

function cerrarModalEvento() {
    document.getElementById('eventoModal').style.display = 'none';
}

function toggleRecurrencia() {
    const checkbox = document.getElementById('evento_recurring');
    const options = document.getElementById('recurrence_options');
    options.style.display = checkbox.checked ? 'block' : 'none';
}

function guardarEvento(event) {
    event.preventDefault();
    
    const form = document.getElementById('eventoForm');
    const formData = new FormData(form);
    
    // Agregar usuario_id automáticamente
    formData.append('user_id', <?php echo $_SESSION['user_id']; ?>);
    
    // Convertir a objeto para enviar
    const data = {};
    formData.forEach((value, key) => {
        data[key] = value;
    });
    
    // Determinar si es crear o actualizar
    const eventoId = document.getElementById('evento_id').value;
    const url = eventoId ? 'api/evento_actualizar.php' : 'api/evento_crear.php';
    
    fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            alert('Evento guardado exitosamente');
            cerrarModalEvento();
            location.reload();
        } else {
            alert('Error: ' + (result.message || 'No se pudo guardar el evento'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error al guardar el evento');
    });
}

// ===== Acción para ver evento (desde tooltip) =====
function verEvento(id) {
    // Implementar vista de detalle
    window.location.href = `evento_detalle.php?id=${id}`;
}

// ===== Cerrar modal al hacer clic fuera =====
document.addEventListener('click', function(event) {
    const modal = document.getElementById('eventoModal');
    const modalContent = modal.querySelector('.modal-content');
    if (event.target === modal) {
        cerrarModalEvento();
    }
});

// ===== Tecla ESC para cerrar modal =====
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        cerrarModalEvento();
    }
});
</script>

</body>
</html>