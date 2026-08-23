<?php
session_start();
require_once 'includes/conexion.php';
require_once 'includes/auth.php';

// Verificar autenticación
if (!estaLogueado()) {
    header('Location: login.php');
    exit;
}

// ===== FUNCIONES SIMPLES SIN USER_ID =====

// Función para obtener TODOS los clientes (sin filtrar por usuario)
function getClientes($conn) {
    try {
        $stmt = $conn->prepare("
            SELECT * FROM clientes 
            ORDER BY fecha_registro DESC
            LIMIT 50
        ");
        $stmt->execute();
        $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Agregar estadísticas de ventas
        /*foreach ($clientes as &$cliente) {
            $stmt2 = $conn->prepare("SELECT COUNT(*) as total FROM ventas WHERE cliente_id = ?");
            $stmt2->execute([$cliente['id']]);
            $cliente['total_compras'] = $stmt2->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
            
            $stmt3 = $conn->prepare("SELECT SUM(monto) as total FROM ventas WHERE cliente_id = ?");
            $stmt3->execute([$cliente['id']]);
            $cliente['total_gastado'] = $stmt3->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
        }*/
        
        return $clientes;
    } catch (PDOException $e) {
        error_log("Error en getClientes: " . $e->getMessage());
        return [];
    }
}

// Función para obtener estadísticas de TODOS los clientes
function getStats($conn) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN estado = 'activo' THEN 1 ELSE 0 END) as activos,
                SUM(CASE WHEN estado = 'inactivo' THEN 1 ELSE 0 END) as inactivos,
                SUM(CASE WHEN estado = 'lead' THEN 1 ELSE 0 END) as leads,
                SUM(CASE WHEN estado = 'potencial' THEN 1 ELSE 0 END) as potenciales
            FROM clientes
        ");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            return ['total' => 0, 'activos' => 0, 'inactivos' => 0, 'leads' => 0, 'potenciales' => 0];
        }
        
        return $result;
    } catch (PDOException $e) {
        error_log("Error en getStats: " . $e->getMessage());
        return ['total' => 0, 'activos' => 0, 'inactivos' => 0, 'leads' => 0, 'potenciales' => 0];
    }
}

// Función para guardar cliente (SIN user_id)
function guardarCliente($conn, $data) {
    try {
        $id = $data['id'] ?? 0;
        
        if ($id > 0) {
            // ACTUALIZAR
            $sql = "UPDATE clientes SET 
                nombre = :nombre,
                apellidos = :apellidos,
                email = :email,
                telefono = :telefono,
                empresa = :empresa,
                estado = :estado,
                interes = :interes,
                notas = :notas
                WHERE id = :id";
            
            $stmt = $conn->prepare($sql);
            return $stmt->execute([
                ':id' => $id,
                ':nombre' => $data['nombre'],
                ':apellidos' => $data['apellidos'] ?? '',
                ':email' => $data['email'] ?? '',
                ':telefono' => $data['telefono'] ?? '',
                ':empresa' => $data['empresa'] ?? '',
                ':estado' => $data['estado'] ?? 'lead',
                ':interes' => $data['interes'] ?? 'general',
                ':notas' => $data['notas'] ?? ''
            ]);
        } else {
            // CREAR NUEVO (SIN user_id)
            $sql = "INSERT INTO clientes (
                nombre, apellidos, email, telefono, empresa, estado, interes, notas
            ) VALUES (
                :nombre, :apellidos, :email, :telefono, :empresa, :estado, :interes, :notas
            )";
            
            $stmt = $conn->prepare($sql);
            return $stmt->execute([
                ':nombre' => $data['nombre'],
                ':apellidos' => $data['apellidos'] ?? '',
                ':email' => $data['email'] ?? '',
                ':telefono' => $data['telefono'] ?? '',
                ':empresa' => $data['empresa'] ?? '',
                ':estado' => $data['estado'] ?? 'lead',
                ':interes' => $data['interes'] ?? 'general',
                ':notas' => $data['notas'] ?? ''
            ]);
        }
    } catch (PDOException $e) {
        error_log("Error en guardarCliente: " . $e->getMessage());
        return false;
    }
}

// Función para eliminar cliente
function eliminarCliente($conn, $id) {
    try {
        $stmt = $conn->prepare("UPDATE clientes SET estado = 'inactivo' WHERE id = ?");
        return $stmt->execute([$id]);
    } catch (PDOException $e) {
        error_log("Error en eliminarCliente: " . $e->getMessage());
        return false;
    }
}

// ===== PROCESAR ACCIONES DEL FORMULARIO =====

$mensaje = '';
$mensaje_tipo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    if ($accion === 'guardar') {
        $data = [
            'id' => $_POST['id'] ?? 0,
            'nombre' => trim($_POST['nombre']),
            'apellidos' => trim($_POST['apellidos'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'telefono' => trim($_POST['telefono'] ?? ''),
            'empresa' => trim($_POST['empresa'] ?? ''),
            'estado' => $_POST['estado'] ?? 'lead',
            'interes' => $_POST['interes'] ?? 'general',
            'notas' => trim($_POST['notas'] ?? '')
        ];
        
        if (empty($data['nombre'])) {
            $mensaje = 'El nombre es obligatorio';
            $mensaje_tipo = 'error';
        } else {
            if (guardarCliente($conn, $data)) {
                $mensaje = $data['id'] > 0 ? 'Cliente actualizado correctamente' : 'Cliente creado correctamente';
                $mensaje_tipo = 'success';
                header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?mensaje=" . urlencode($mensaje) . "&tipo=success");
                exit;
            } else {
                $mensaje = 'Error al guardar el cliente';
                $mensaje_tipo = 'error';
            }
        }
    }
    
    if ($accion === 'eliminar') {
        $id = $_POST['id'] ?? 0;
        if (eliminarCliente($conn, $id)) {
            $mensaje = 'Cliente eliminado correctamente';
            $mensaje_tipo = 'success';
            header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?mensaje=" . urlencode($mensaje) . "&tipo=success");
            exit;
        } else {
            $mensaje = 'Error al eliminar el cliente';
            $mensaje_tipo = 'error';
        }
    }
}

// ===== OBTENER DATOS =====

$clientes = getClientes($conn);
$stats = getStats($conn);

// Si hay un ID para editar
$cliente_editar = null;
if (isset($_GET['editar']) && is_numeric($_GET['editar'])) {
    $id_editar = $_GET['editar'];
    try {
        $stmt = $conn->prepare("SELECT * FROM clientes WHERE id = ?");
        $stmt->execute([$id_editar]);
        $cliente_editar = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $cliente_editar = null;
    }
}

if (isset($_GET['mensaje'])) {
    $mensaje = $_GET['mensaje'];
    $mensaje_tipo = $_GET['tipo'] ?? 'success';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/socios.css">
    <title>CRM Clientes | Inmobiliaria MH</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .cliente-contacto {
            display: flex;
            flex-direction: column;
            gap: 2px;
            font-size: 0.85rem;
            color: var(--gray);
        }
        .cliente-contacto i {
            width: 16px;
            color: var(--primary);
        }
        .cliente-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }
        .tag {
            padding: 2px 10px;
            background: #e9ecef;
            border-radius: 12px;
            font-size: 0.75rem;
            color: var(--gray);
        }
        .tag.preferred {
            background: #d4edda;
            color: #155724;
        }
        .tag.vip {
            background: #fff3cd;
            color: #856404;
        }
        .client-stats {
            font-size: 0.8rem;
            color: var(--gray);
            display: flex;
            gap: 10px;
        }
        .client-stats span {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .client-stats i {
            color: var(--primary);
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .alert i {
            font-size: 20px;
        }
        
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-box {
            background: white;
            border-radius: 12px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-box h2 {
            margin-top: 0;
            margin-bottom: 20px;
        }
        .modal-box .form-group {
            margin-bottom: 15px;
        }
        .modal-box label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        .modal-box input,
        .modal-box select,
        .modal-box textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        .modal-box textarea {
            resize: vertical;
            min-height: 60px;
        }
        .modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        .modal-actions button {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
        }
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        @media (max-width: 768px) {
            .cliente-contacto {
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>

<!-- Overlay para móvil -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ===== SIDEBAR ===== -->
<?php include 'sidebar.php'; ?>

<!-- ===== MAIN CONTENT ===== -->
<main class="main-content">
    <div class="main-header">
        <div class="header-left">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <h1>CRM - Clientes</h1>
            <p class="welcome">
                <i class="fas fa-users"></i> Gestión de relaciones con clientes
            </p>
        </div>
        <div class="header-actions">
            <a href="?nuevo=1" class="btn-header primary">
                <i class="fas fa-user-plus"></i> Nuevo Cliente
            </a>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $mensaje_tipo; ?>">
            <i class="fas fa-<?php echo $mensaje_tipo === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>

    <!-- Estadísticas -->
    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-icon"><i class="fas fa-users"></i></span>
            <div class="stat-number"><?php echo $stats['total'] ?? 0; ?></div>
            <div class="stat-label">Total Clientes</div>
        </div>
        <div class="stat-card success">
            <span class="stat-icon"><i class="fas fa-user-check"></i></span>
            <div class="stat-number"><?php echo $stats['activos'] ?? 0; ?></div>
            <div class="stat-label">Activos</div>
        </div>
        <div class="stat-card warning">
            <span class="stat-icon"><i class="fas fa-user-clock"></i></span>
            <div class="stat-number"><?php echo $stats['leads'] ?? 0; ?></div>
            <div class="stat-label">Leads</div>
        </div>
        <div class="stat-card danger">
            <span class="stat-icon"><i class="fas fa-user-slash"></i></span>
            <div class="stat-number"><?php echo $stats['inactivos'] ?? 0; ?></div>
            <div class="stat-label">Inactivos</div>
        </div>
    </div>

    <!-- Listado de clientes -->
    <div class="table-container">
        <div class="table-header">
            <h3><i class="fas fa-list"></i> Listado de Clientes</h3>
            <div class="search-box">
                <input type="text" placeholder="Buscar cliente..." id="searchTable">
                <select id="filterStatus">
                    <option value="">Todos</option>
                    <option value="activo">Activo</option>
                    <option value="lead">Lead</option>
                    <option value="potencial">Potencial</option>
                    <option value="inactivo">Inactivo</option>
                </select>
            </div>
        </div>

        <div class="table-responsive">
            <?php if (empty($clientes)): ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <h3>No hay clientes registrados</h3>
                    <p style="color: var(--gray);">Agrega tu primer cliente al CRM</p>
                    <a href="?nuevo=1" class="btn-header primary" style="margin-top: 20px; display: inline-block;">
                        <i class="fas fa-user-plus"></i> Agregar Cliente
                    </a>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>Contacto</th>
                            <th>Estado</th>
                            <th>Actividad</th>
                            <th>Etiquetas</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clientes as $cliente): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($cliente['nombre'] ?? 'N/A'); ?></strong>
                                    <div style="font-size: 0.8rem; color: var(--gray);">
                                        <?php echo htmlspecialchars($cliente['empresa'] ?? 'Particular'); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="cliente-contacto">
                                        <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($cliente['email'] ?? 'N/A'); ?></span>
                                        <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($cliente['telefono'] ?? 'N/A'); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $cliente['estado'] ?? 'lead'; ?>">
                                        <?php echo ucfirst($cliente['estado'] ?? 'Lead'); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="client-stats">
                                        <span><i class="fas fa-shopping-cart"></i> <?php echo $cliente['total_compras'] ?? 0; ?></span>
                                        <span><i class="fas fa-dollar-sign"></i> $<?php echo number_format($cliente['total_gastado'] ?? 0, 0, ',', '.'); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="cliente-tags">
                                        <?php if (($cliente['total_gastado'] ?? 0) > 100000): ?>
                                            <span class="tag vip">VIP</span>
                                        <?php endif; ?>
                                        <?php if (($cliente['total_compras'] ?? 0) > 3): ?>
                                            <span class="tag preferred">Preferente</span>
                                        <?php endif; ?>
                                        <span class="tag"><?php echo ucfirst($cliente['interes'] ?? 'General'); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <a href="?ver=<?php echo $cliente['id']; ?>" class="action-btn view">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="?editar=<?php echo $cliente['id']; ?>" class="action-btn edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button class="action-btn delete" onclick="confirmarEliminar(<?php echo $cliente['id']; ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- ===== MODAL PARA NUEVO/EDITAR CLIENTE ===== -->
<?php if (isset($_GET['nuevo']) || isset($_GET['editar'])): ?>
<div class="modal-overlay active" onclick="if(event.target===this) window.location.href='<?php echo basename($_SERVER['PHP_SELF']); ?>'">
    <div class="modal-box">
        <h2><?php echo isset($_GET['editar']) ? 'Editar Cliente' : 'Nuevo Cliente'; ?></h2>
        <form method="POST" action="">
            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="id" value="<?php echo $cliente_editar['id'] ?? ''; ?>">
            
            <div class="form-group">
                <label>Nombre *</label>
                <input type="text" name="nombre" required value="<?php echo htmlspecialchars($cliente_editar['nombre'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label>Apellidos</label>
                <input type="text" name="apellidos" value="<?php echo htmlspecialchars($cliente_editar['apellidos'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" value="<?php echo htmlspecialchars($cliente_editar['email'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label>Teléfono</label>
                <input type="text" name="telefono" value="<?php echo htmlspecialchars($cliente_editar['telefono'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label>Empresa</label>
                <input type="text" name="empresa" value="<?php echo htmlspecialchars($cliente_editar['empresa'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label>Estado</label>
                <select name="estado">
                    <option value="lead" <?php echo ($cliente_editar['estado'] ?? '') === 'lead' ? 'selected' : ''; ?>>Lead</option>
                    <option value="potencial" <?php echo ($cliente_editar['estado'] ?? '') === 'potencial' ? 'selected' : ''; ?>>Potencial</option>
                    <option value="activo" <?php echo ($cliente_editar['estado'] ?? '') === 'activo' ? 'selected' : ''; ?>>Activo</option>
                    <option value="inactivo" <?php echo ($cliente_editar['estado'] ?? '') === 'inactivo' ? 'selected' : ''; ?>>Inactivo</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Interés</label>
                <select name="interes">
                    <option value="general" <?php echo ($cliente_editar['interes'] ?? '') === 'general' ? 'selected' : ''; ?>>General</option>
                    <option value="comprar" <?php echo ($cliente_editar['interes'] ?? '') === 'comprar' ? 'selected' : ''; ?>>Comprar</option>
                    <option value="venta" <?php echo ($cliente_editar['interes'] ?? '') === 'venta' ? 'selected' : ''; ?>>Venta</option>
                    <option value="alquiler" <?php echo ($cliente_editar['interes'] ?? '') === 'alquiler' ? 'selected' : ''; ?>>Alquiler</option>
                    <option value="inversion" <?php echo ($cliente_editar['interes'] ?? '') === 'inversion' ? 'selected' : ''; ?>>Inversión</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Notas</label>
                <textarea name="notas" rows="3"><?php echo htmlspecialchars($cliente_editar['notas'] ?? ''); ?></textarea>
            </div>
            
            <div class="modal-actions">
                <button type="submit" class="btn-primary"><?php echo isset($_GET['editar']) ? 'Actualizar' : 'Guardar'; ?></button>
                <a href="<?php echo basename($_SERVER['PHP_SELF']); ?>" class="btn-secondary" style="text-decoration: none; text-align: center; line-height: 40px;">Cancelar</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ===== FORMULARIO PARA ELIMINAR (oculto) ===== -->
<form id="formEliminar" method="POST" style="display:none;">
    <input type="hidden" name="accion" value="eliminar">
    <input type="hidden" name="id" id="eliminar_id">
</form>

<script>
    // ===== Menú móvil =====
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    function toggleSidebar() {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('show');
        document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
    }

    menuToggle.addEventListener('click', toggleSidebar);
    overlay.addEventListener('click', toggleSidebar);

    document.querySelectorAll('.sidebar nav a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 992) {
                toggleSidebar();
            }
        });
    });

    // ===== Filtros =====
    document.getElementById('searchTable').addEventListener('keyup', function() {
        filtrarTabla();
    });

    document.getElementById('filterStatus').addEventListener('change', function() {
        filtrarTabla();
    });

    function filtrarTabla() {
        const searchText = document.getElementById('searchTable').value.toLowerCase();
        const filterStatus = document.getElementById('filterStatus').value.toLowerCase();
        const rows = document.querySelectorAll('table tbody tr');
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            const status = row.querySelector('.status-badge');
            const statusText = status ? status.textContent.toLowerCase() : '';
            
            let matchesSearch = text.includes(searchText);
            let matchesStatus = filterStatus === '' || statusText.includes(filterStatus);
            
            row.style.display = (matchesSearch && matchesStatus) ? '' : 'none';
        });
    }

    // ===== Confirmar eliminación =====
    function confirmarEliminar(id) {
        if (confirm('¿Estás seguro de que quieres eliminar este cliente?')) {
            document.getElementById('eliminar_id').value = id;
            document.getElementById('formEliminar').submit();
        }
    }
</script>

</body>
</html>