<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/conexion.php';
require_once 'includes/auth.php';

// ============================================
// CONFIGURACIÓN DE SEGURIDAD PARA CRON
// ============================================
// Cambia esta clave por una tuya, secreta y larga.
// La usarás en la URL del cron para que no cualquiera pueda ejecutarlo.
define('CRON_TOKEN', 'veraterra_cron_2026_cambia_esto_por_algo_unico');

// ============================================
// VERIFICAR ACCESO
// ============================================
// El archivo se puede ejecutar de 2 formas:
//  1. Como admin logueado desde el navegador
//  2. Como cron job con ?token=TU_TOKEN en la URL
$es_cron_valido = isset($_GET['token']) && $_GET['token'] === CRON_TOKEN;
$es_admin_valido = false;

if (!$es_cron_valido) {
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
    $es_admin_valido = (($_SESSION['role'] ?? '') === 'admin') 
                    || (($usuario['role'] ?? '') === 'admin');
    
    if (!$es_admin_valido) {
        die('Acceso denegado. Solo administradores o cron con token válido.');
    }
}

// ============================================
// FUNCIÓN: Recalcular comisión de un vendedor
// ============================================
function actualizarComisionVendedor($conn, $vendedor_id) {
    try {
        $inicio_mes = date('Y-m-01 00:00:00');
        $fin_mes    = date('Y-m-t 23:59:59');

        // Contar ventas del mes actual
        $stmt = $conn->prepare("
            SELECT COUNT(*) as total_ventas
            FROM properties
            WHERE assigned_to = ?
              AND LOWER(status) IN ('vendido', 'completado')
              AND updated_at BETWEEN ? AND ?
        ");
        $stmt->execute([$vendedor_id, $inicio_mes, $fin_mes]);
        $ventas_mes = (int)$stmt->fetchColumn();

        // Obtener niveles activos
        $stmtNiv = $conn->query("
            SELECT * FROM comision_niveles 
            WHERE activo = 1 
            ORDER BY nivel_orden ASC
        ");
        $niveles = $stmtNiv->fetchAll(PDO::FETCH_ASSOC);

        if (empty($niveles)) {
            return ['error' => 'No hay niveles configurados'];
        }

        // Determinar nivel según ventas del mes
        $nivel_asignado = null;
        foreach ($niveles as $n) {
            if ($ventas_mes >= (int)$n['ventas_min'] && $ventas_mes <= (int)$n['ventas_max']) {
                $nivel_asignado = $n;
                break;
            }
        }

        // Fallback: si supera el máximo o está por debajo del mínimo
        if (!$nivel_asignado) {
            $ultimo = end($niveles);
            $nivel_asignado = ($ventas_mes >= (int)$ultimo['ventas_min']) ? $ultimo : $niveles[0];
        }

        $nuevo_porcentaje = (float)$nivel_asignado['comision_porcentaje'];

        // Obtener porcentaje anterior
        $stmtActual = $conn->prepare("SELECT comision_porcentaje FROM users WHERE id = ?");
        $stmtActual->execute([$vendedor_id]);
        $porcentaje_anterior = (float)$stmtActual->fetchColumn();

        // Actualizar en users
        $stmtUpdate = $conn->prepare("
            UPDATE users 
            SET comision_porcentaje = ? 
            WHERE id = ? AND role = 'asesor'
        ");
        $stmtUpdate->execute([$nuevo_porcentaje, $vendedor_id]);

        return [
            'success'    => true,
            'ventas_mes' => $ventas_mes,
            'nivel'      => $nivel_asignado,
            'anterior'   => $porcentaje_anterior,
            'nuevo'      => $nuevo_porcentaje
        ];

    } catch (PDOException $e) {
        error_log("Error actualizando comisión del vendedor $vendedor_id: " . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}

// ============================================
// PROCESAR ACTUALIZACIÓN
// ============================================
$resultado = null;
$modo = $es_cron_valido ? 'cron' : 'manual';

// Si es cron, se ejecuta siempre. Si es admin, espera POST (botón).
$ejecutar = $es_cron_valido || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'ejecutar');

if ($ejecutar) {
    $resultado = ['detalles' => [], 'exitosos' => 0, 'errores' => 0, 'inicio' => microtime(true)];

    try {
        $stmt = $conn->query("SELECT id, name FROM users WHERE role = 'asesor' ORDER BY name ASC");
        $asesores = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($asesores as $a) {
            $res = actualizarComisionVendedor($conn, $a['id']);
            if (isset($res['success'])) {
                $resultado['exitosos']++;
                $resultado['detalles'][] = [
                    'nombre'   => $a['name'],
                    'ventas'   => $res['ventas_mes'],
                    'nivel'    => $res['nivel']['nivel_nombre'],
                    'icono'    => $res['nivel']['icono'] ?? '',
                    'color'    => $res['nivel']['color_hex'] ?? '#6b7280',
                    'anterior' => $res['anterior'],
                    'nuevo'    => $res['nuevo'],
                    'cambio'   => $res['nuevo'] > $res['anterior'] ? 'subio' : 
                                 ($res['nuevo'] < $res['anterior'] ? 'bajo' : 'igual')
                ];
            } else {
                $resultado['errores']++;
                $resultado['detalles'][] = [
                    'nombre' => $a['name'],
                    'error'  => $res['error'] ?? 'Error desconocido'
                ];
            }
        }
    } catch (PDOException $e) {
        $resultado['errores']++;
        $resultado['detalles'][] = ['nombre' => 'General', 'error' => $e->getMessage()];
    }
    
    $resultado['duracion'] = round((microtime(true) - $resultado['inicio']) * 1000, 2);

    // Si es cron, responder solo con texto plano y salir
    if ($es_cron_valido) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "[" . date('Y-m-d H:i:s') . "] Cron comisiones ejecutado\n";
        echo "Exitosos: {$resultado['exitosos']}\n";
        echo "Errores: {$resultado['errores']}\n";
        echo "Duración: {$resultado['duracion']} ms\n";
        foreach ($resultado['detalles'] as $d) {
            if (isset($d['error'])) {
                echo "  ERROR - {$d['nombre']}: {$d['error']}\n";
            } else {
                echo "  {$d['nombre']}: {$d['ventas']} ventas → {$d['nivel']} ({$d['anterior']}% → {$d['nuevo']}%)\n";
            }
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Actualizar Comisiones | Vera Terra</title>
    <link rel="stylesheet" href="css/socios.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body { background: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .container { max-width: 1100px; margin: 40px auto; padding: 0 20px; }
        
        .card {
            background: #fff;
            border: 1px solid #e8edf4;
            border-radius: 14px;
            padding: 28px 32px;
            margin-bottom: 20px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        
        .header-title {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 10px;
        }
        
        .header-title i {
            font-size: 2rem;
            color: #1d4ed8;
            background: #eff6ff;
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .header-title h1 {
            font-size: 1.5rem;
            color: #0f172a;
            margin: 0;
            line-height: 1.2;
        }
        
        .header-title p {
            color: #64748b;
            font-size: 0.85rem;
            margin: 4px 0 0 0;
        }
        
        .info-box {
            background: #eff6ff;
            border-left: 4px solid #3b82f6;
            padding: 14px 18px;
            border-radius: 8px;
            margin: 20px 0;
            font-size: 0.88rem;
            color: #1e40af;
            line-height: 1.6;
        }
        
        .info-box i { margin-right: 6px; }
        
        .info-box code {
            background: #dbeafe;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.8rem;
        }
        
        .warning-box {
            background: #fef3c7;
            border-left: 4px solid #d97706;
            padding: 14px 18px;
            border-radius: 8px;
            margin: 20px 0;
            font-size: 0.85rem;
            color: #92400e;
            line-height: 1.6;
        }
        
        .warning-box i { margin-right: 6px; }
        
        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 14px 32px;
            background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            box-shadow: 0 4px 14px rgba(29, 78, 216, 0.25);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(29, 78, 216, 0.4);
        }
        
        .btn-secondary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 22px;
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
            text-decoration: none;
            margin-left: 12px;
        }
        
        .btn-secondary:hover { background: #e2e8f0; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 14px;
            margin: 20px 0;
        }
        
        .stat-card {
            padding: 18px 20px;
            border-radius: 12px;
            text-align: center;
        }
        
        .stat-card.exitosos { background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%); }
        .stat-card.errores  { background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); }
        .stat-card.tiempo   { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); }
        
        .stat-card .num {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
        }
        
        .stat-card.exitosos .num { color: #166534; }
        .stat-card.errores  .num { color: #991b1b; }
        .stat-card.tiempo   .num { color: #1e40af; font-size: 1.5rem; }
        
        .stat-card .lbl {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            margin-top: 6px;
        }
        
        .stat-card.exitosos .lbl { color: #166534; }
        .stat-card.errores  .lbl { color: #991b1b; }
        .stat-card.tiempo   .lbl { color: #1e40af; }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88rem;
        }
        
        table thead th {
            background: #f8fafc;
            padding: 12px 14px;
            text-align: left;
            font-size: 0.7rem;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e2e8f0;
            font-weight: 700;
        }
        
        table tbody td {
            padding: 12px 14px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        
        table tbody tr:hover { background: #f8fafc; }
        
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 700;
        }
        
        .badge-nivel {
            background: #f1f5f9;
            color: #334155;
        }
        
        .cambio {
            font-weight: 800;
            font-size: 0.95rem;
        }
        
        .cambio.subio { color: #16a34a; }
        .cambio.bajo  { color: #dc2626; }
        .cambio.igual { color: #64748b; }
        
        .arrow {
            margin: 0 6px;
            font-size: 0.75rem;
            opacity: 0.6;
        }
        
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #64748b;
        }
        
        .empty-state i {
            font-size: 3.5rem;
            color: #cbd5e1;
            margin-bottom: 16px;
            display: block;
        }
        
        .cron-url {
            background: #0f172a;
            color: #4ade80;
            padding: 12px 16px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 0.82rem;
            word-break: break-all;
            margin-top: 10px;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <div class="header-title">
            <i class="fas fa-sync-alt"></i>
            <div>
                <h1>Actualizar Comisiones</h1>
                <p>Recalcula el porcentaje de comisión de todos los asesores según sus ventas del mes actual</p>
            </div>
        </div>

        <div class="info-box">
            <i class="fas fa-info-circle"></i>
            <strong>Cómo funciona:</strong> Se cuentan las propiedades con estado <code>vendido</code> o 
            <code>completado</code> cuya fecha de actualización esté dentro del mes calendario actual. 
            Según ese número, se asigna el nivel correspondiente (Bronce, Plata, Oro, etc.) y su porcentaje 
            se guarda en <code>users.comision_porcentaje</code>.
        </div>

        <div class="warning-box">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Atención:</strong> Esta acción <strong>sobrescribe</strong> el campo 
            <code>comision_porcentaje</code> de todos los asesores. Si tenías ajustes manuales, se perderán.
        </div>

        <?php if (!$resultado): ?>
            <form method="POST" action="" onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').innerHTML='<i class=\'fas fa-spinner fa-spin\'></i> Procesando...';">
                <input type="hidden" name="accion" value="ejecutar">
                <button type="submit" class="btn-primary">
                    <i class="fas fa-play-circle"></i> Ejecutar Actualización
                </button>
                <a href="/admin/administracion.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i> Volver al Panel
                </a>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($resultado): ?>
        <div class="card">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px;">
                <i class="fas fa-check-circle" style="color: #16a34a; font-size: 1.8rem;"></i>
                <div>
                    <h2 style="margin: 0; color: #0f172a; font-size: 1.2rem;">Actualización completada</h2>
                    <p style="margin: 4px 0 0 0; color: #64748b; font-size: 0.82rem;">
                        Modo: <strong><?php echo $modo === 'cron' ? 'Cron Job' : 'Manual'; ?></strong>
                        · <?php echo date('d/m/Y H:i:s'); ?>
                    </p>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card exitosos">
                    <div class="num"><?php echo $resultado['exitosos']; ?></div>
                    <div class="lbl">Actualizados</div>
                </div>
                <div class="stat-card errores">
                    <div class="num"><?php echo $resultado['errores']; ?></div>
                    <div class="lbl">Errores</div>
                </div>
                <div class="stat-card tiempo">
                    <div class="num"><?php echo $resultado['duracion']; ?> ms</div>
                    <div class="lbl">Duración</div>
                </div>
            </div>

            <?php if (!empty($resultado['detalles'])): ?>
            <div style="overflow-x: auto; margin-top: 24px;">
                <table>
                    <thead>
                        <tr>
                            <th>Asesor</th>
                            <th style="text-align:center;">Ventas del mes</th>
                            <th>Nivel asignado</th>
                            <th style="text-align:center;">Antes</th>
                            <th style="text-align:center;">Ahora</th>
                            <th style="text-align:center;">Cambio</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resultado['detalles'] as $d): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($d['nombre']); ?></strong></td>
                            <?php if (isset($d['error'])): ?>
                                <td colspan="5" style="color: #991b1b; font-size: 0.82rem;">
                                    <i class="fas fa-exclamation-circle"></i> 
                                    Error: <?php echo htmlspecialchars($d['error']); ?>
                                </td>
                            <?php else: ?>
                                <td style="text-align:center;"><strong><?php echo $d['ventas']; ?></strong></td>
                                <td>
                                    <span class="badge badge-nivel" style="background: <?php echo htmlspecialchars($d['color']); ?>22; color: <?php echo htmlspecialchars($d['color']); ?>;">
                                        <?php echo htmlspecialchars($d['icono'] . ' ' . $d['nivel']); ?>
                                    </span>
                                </td>
                                <td style="text-align:center; color: #64748b;">
                                    <?php echo number_format($d['anterior'], 2); ?>%
                                </td>
                                <td style="text-align:center;">
                                    <strong style="color: #0f172a;"><?php echo number_format($d['nuevo'], 2); ?>%</strong>
                                </td>
                                <td style="text-align:center;">
                                    <?php if ($d['cambio'] === 'subio'): ?>
                                        <span class="cambio subio">
                                            <i class="fas fa-arrow-up"></i> +<?php echo number_format($d['nuevo'] - $d['anterior'], 2); ?>%
                                        </span>
                                    <?php elseif ($d['cambio'] === 'bajo'): ?>
                                        <span class="cambio bajo">
                                            <i class="fas fa-arrow-down"></i> <?php echo number_format($d['nuevo'] - $d['anterior'], 2); ?>%
                                        </span>
                                    <?php else: ?>
                                        <span class="cambio igual">
                                            <i class="fas fa-equals"></i> Sin cambio
                                        </span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div style="margin-top: 24px; display: flex; gap: 12px; flex-wrap: wrap;">
                <form method="POST" action="" style="margin: 0;">
                    <input type="hidden" name="accion" value="ejecutar">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-redo"></i> Ejecutar de Nuevo
                    </button>
                </form>
                <a href="admin.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i> Volver al Panel
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- ===== INFO PARA CRON ===== -->
    <div class="card" style="background: linear-gradient(135deg, #f0fdfa 0%, #ffffff 100%); border-color: #99f6e4;">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
            <i class="fas fa-clock" style="color: #0d9488; font-size: 1.5rem;"></i>
            <h3 style="margin: 0; color: #0f172a; font-size: 1.05rem;">Automatizar con Cron Job</h3>
        </div>
        <p style="color: #475569; font-size: 0.85rem; line-height: 1.6; margin: 0 0 12px 0;">
            Para que este cálculo se ejecute automáticamente todos los días sin que nadie entre, 
            puedes configurar un <strong>Cron Job</strong> en tu hosting apuntando a esta URL:
        </p>
        <div class="cron-url">
            <?php 
                $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'tudominio.com';
                $path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
                echo htmlspecialchars($proto . '://' . $host . $path . '/actualizar_comisiones.php?token=' . CRON_TOKEN);
            ?>
        </div>
        <p style="color: #94a3b8; font-size: 0.75rem; margin: 10px 0 0 0;">
            <i class="fas fa-shield-alt"></i> 
            El token de seguridad evita que cualquiera pueda ejecutar el cálculo desde fuera.
        </p>
    </div>
</div>

</body>
</html>