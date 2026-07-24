<?php
// ============================================
// propiedad_detalle_vendedor.php
// ============================================

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

$usuario = obtenerUsuarioActual($conn);
if (!$usuario) {
    cerrarSesion();
    header('Location: login.php');
    exit;
}

// Obtener ID de la propiedad
$property_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($property_id == 0) {
    header('Location: mis_propiedades.php');
    exit;
}

$vendedor_id = $usuario['id'];
$propiedad = null;
$error_msg = '';
$alertas = [];

try {
    // Consulta principal con todos los datos
    $stmt = $conn->prepare("
        SELECT 
            p.id,
            p.owner_id,
            p.title,
            p.operation_type,
            p.address_city,
            p.address_municipality,
            p.address_lat,
            p.address_lng,
            p.status,
            p.created_at,
            p.updated_at,
            DATEDIFF(NOW(), p.created_at) as days_active,
            pd.square_meters,
            pd.bedrooms,
            pd.bathrooms,
            pd.year_built,
            pd.parking_spots,
            pf.asking_price as price,
            pf.min_acceptable_price,
            pf.potential_profit_margin,
            pf.commission_percentage,
            (pf.asking_price * pf.commission_percentage / 100) as commission_amount,
            (pf.asking_price - pf.min_acceptable_price) as profit_margin_amount
        FROM properties p
        LEFT JOIN property_details pd ON p.id = pd.property_id
        LEFT JOIN property_financials pf ON p.id = pf.property_id
        WHERE p.id = ? AND p.owner_id = ?
    ");
    $stmt->execute([$property_id, $vendedor_id]);
    $propiedad = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$propiedad) {
        $error_msg = "Propiedad no encontrada o no tienes acceso a ella.";
    }

    // Obtener imágenes
    $imagenes = [];
    if ($propiedad) {
        $stmtImg = $conn->prepare("
            SELECT id, file_name, file_path, file_size, mime_type, is_primary, sort_order
            FROM property_media
            WHERE property_id = ?
            ORDER BY is_primary DESC, sort_order ASC
        ");
        $stmtImg->execute([$property_id]);
        $imagenes = $stmtImg->fetchAll(PDO::FETCH_ASSOC);
    }

    // Obtener documentos
    $documentos = [];
    if ($propiedad) {
        $stmtDoc = $conn->prepare("
            SELECT id, document_type, file_name, file_path, file_size, mime_type, uploaded_at
            FROM property_documents
            WHERE property_id = ?
            ORDER BY uploaded_at DESC
        ");
        $stmtDoc->execute([$property_id]);
        $documentos = $stmtDoc->fetchAll(PDO::FETCH_ASSOC);
    }

    // Generar alertas para esta propiedad
    if ($propiedad) {
        if (empty($propiedad['price']) || $propiedad['price'] == 0) {
            $alertas[] = ['type' => 'warning', 'message' => 'Esta propiedad no tiene precio asignado'];
        }
        if (empty($imagenes)) {
            $alertas[] = ['type' => 'info', 'message' => 'Esta propiedad no tiene imágenes cargadas'];
        }
        if (($propiedad['days_active'] ?? 0) > 30) {
            $alertas[] = ['type' => 'warning', 'message' => "Esta propiedad lleva {$propiedad['days_active']} días activa"];
        }
        if (empty($propiedad['square_meters'])) {
            $alertas[] = ['type' => 'info', 'message' => 'Faltan datos de superficie (m²)'];
        }
        if (($propiedad['price'] ?? 0) <= ($propiedad['min_acceptable_price'] ?? 0)) {
            $alertas[] = ['type' => 'warning', 'message' => 'El precio está en el límite mínimo aceptable'];
        }
    }

} catch (PDOException $e) {
    $error_msg = "Error al cargar la propiedad: " . $e->getMessage();
    error_log("Error en propiedad_detalle_vendedor.php: " . $e->getMessage());
}

// Funciones auxiliares
function formatearPrecio($precio) {
    if ($precio === null || $precio === '' || $precio == 0) {
        return 'Sin asignar';
    }
    return '$' . number_format(floatval($precio), 0, ',', '.');
}

function getStatusBadge($status) {
    $status = strtolower(trim($status ?? ''));
    $classes = [
        'activo' => 'status-active',
        'pendiente' => 'status-pending',
        'vendido' => 'status-sold',
        'suspendido' => 'status-suspended'
    ];
    return $classes[$status] ?? 'status-other';
}

function getImagePath($imageUrl) {
    if (empty($imageUrl)) {
        return '';
    }
    if (strpos($imageUrl, 'uploads/') === 0) {
        return htmlspecialchars($imageUrl);
    }
    return 'uploads/propiedades/' . htmlspecialchars($imageUrl);
}

function getDocumentIcon($mimeType) {
    if (strpos($mimeType, 'pdf') !== false) return 'fa-file-pdf';
    if (strpos($mimeType, 'image') !== false) return 'fa-file-image';
    if (strpos($mimeType, 'word') !== false) return 'fa-file-word';
    if (strpos($mimeType, 'excel') !== false) return 'fa-file-excel';
    return 'fa-file';
}

function formatFileSize($bytes) {
    if ($bytes === null) return 'Desconocido';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < 3) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 1) . ' ' . $units[$i];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/socios.css">
    <title>Detalle Propiedad | Panel Vendedor</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ===== ESTILOS ===== */
        * { box-sizing: border-box; }

        .detail-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }

        .detail-title {
            flex: 1;
        }

        .detail-title h1 {
            font-size: 1.5rem;
            margin: 0 0 4px 0;
            color: #0f172a;
        }

        .detail-title .subtitle {
            color: #64748b;
            font-size: 0.9rem;
        }

        .detail-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-detail {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-detail.primary {
            background: #1d4ed8;
            color: white;
        }

        .btn-detail.primary:hover {
            background: #1e40af;
        }

        .btn-detail.secondary {
            background: #f1f5f9;
            color: #475569;
        }

        .btn-detail.secondary:hover {
            background: #e2e8f0;
        }

        .btn-detail.success {
            background: #16a34a;
            color: white;
        }

        .btn-detail.success:hover {
            background: #15803d;
        }

        .btn-detail.warning {
            background: #d97706;
            color: white;
        }

        .btn-detail.warning:hover {
            background: #b45309;
        }

        .btn-detail.danger {
            background: #dc2626;
            color: white;
        }

        .btn-detail.danger:hover {
            background: #b91c1c;
        }

        /* Grid de información */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }

        .info-card {
            background: #ffffff;
            border: 1px solid #e8edf4;
            border-radius: 12px;
            padding: 20px;
        }

        .info-card h3 {
            font-size: 0.85rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 16px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-card h3 i {
            color: #64748b;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px solid #f8fafc;
            font-size: 0.9rem;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-row .label {
            color: #64748b;
            font-weight: 500;
        }

        .info-row .value {
            color: #0f172a;
            font-weight: 600;
        }

        .info-row .value.highlight {
            color: #1d4ed8;
            font-size: 1.1rem;
        }

        .info-row .value.success {
            color: #16a34a;
        }

        .info-row .value.warning {
            color: #d97706;
        }

        /* Alertas */
        .alert-list {
            margin-bottom: 20px;
        }

        .alert-item-detail {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 6px;
            font-size: 0.85rem;
        }

        .alert-item-detail.warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fcd34d;
        }

        .alert-item-detail.info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #93c5fd;
        }

        .alert-item-detail i {
            font-size: 1rem;
        }

        /* Galería de imágenes */
        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 12px;
            margin-top: 8px;
        }

        .gallery-item {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            aspect-ratio: 1;
            background: #f1f5f9;
            border: 2px solid transparent;
            transition: all 0.2s;
        }

        .gallery-item.primary {
            border-color: #1d4ed8;
        }

        .gallery-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .gallery-item .gallery-badge {
            position: absolute;
            top: 4px;
            right: 4px;
            background: rgba(0,0,0,0.7);
            color: white;
            font-size: 0.6rem;
            padding: 2px 8px;
            border-radius: 4px;
        }

        .gallery-item .gallery-badge.primary {
            background: #1d4ed8;
        }

        /* Documentos */
        .doc-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .doc-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e8edf4;
            transition: all 0.2s;
        }

        .doc-item:hover {
            background: #f1f5f9;
        }

        .doc-item .doc-icon {
            font-size: 1.2rem;
            color: #64748b;
            width: 32px;
            text-align: center;
        }

        .doc-item .doc-info {
            flex: 1;
        }

        .doc-item .doc-name {
            font-size: 0.85rem;
            font-weight: 500;
            color: #0f172a;
        }

        .doc-item .doc-meta {
            font-size: 0.7rem;
            color: #94a3b8;
        }

        .doc-item .doc-action {
            color: #1d4ed8;
            text-decoration: none;
            font-size: 0.8rem;
        }

        .doc-item .doc-action:hover {
            text-decoration: underline;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-badge.status-active { background: #dcfce7; color: #166534; }
        .status-badge.status-pending { background: #fef3c7; color: #92400e; }
        .status-badge.status-sold { background: #dbeafe; color: #1e40af; }
        .status-badge.status-suspended { background: #fee2e2; color: #991b1b; }
        .status-badge.status-other { background: #f1f5f9; color: #475569; }

        .message-box {
            padding: 12px 16px;
            border-radius: 8px;
            margin: 8px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
        }

        .message-box.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .message-box.info { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }

        .empty-state-small {
            text-align: center;
            padding: 20px;
            color: #94a3b8;
        }

        .empty-state-small i {
            font-size: 2rem;
            margin-bottom: 8px;
            display: block;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }

            .detail-header {
                flex-direction: column;
            }

            .detail-title h1 {
                font-size: 1.2rem;
            }

            .gallery-grid {
                grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php include 'sidebar.php'; ?>

<main class="main-content">
    <div class="detail-container">

        <?php if (!empty($error_msg)): ?>
            <div class="message-box error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error_msg); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($propiedad): ?>
            <!-- Header -->
            <div class="detail-header">
                <div class="detail-title">
                    <h1><?php echo htmlspecialchars($propiedad['title']); ?></h1>
                    <div class="subtitle">
                        <span class="status-badge <?php echo getStatusBadge($propiedad['status']); ?>">
                            <?php echo ucfirst($propiedad['status'] ?? 'Sin estado'); ?>
                        </span>
                        <span style="margin-left: 12px;">
                            <i class="fas fa-calendar-alt"></i> 
                            <?php echo date('d/m/Y', strtotime($propiedad['created_at'])); ?>
                        </span>
                        <span style="margin-left: 12px;">
                            <i class="fas fa-clock"></i> 
                            <?php echo $propiedad['days_active'] ?? 0; ?> días activo
                        </span>
                    </div>
                </div>
                <div class="detail-actions">
                    <a href="mis_propiedades.php" class="btn-detail secondary">
                        <i class="fas fa-arrow-left"></i> Volver
                    </a>
                    <a href="propiedad_editar.php?id=<?php echo $property_id; ?>" class="btn-detail primary">
                        <i class="fas fa-edit"></i> Editar
                    </a>
                    <?php if ($propiedad['status'] === 'activo'): ?>
                        <button class="btn-detail warning" onclick="suspenderPropiedad(<?php echo $property_id; ?>)">
                            <i class="fas fa-pause"></i> Suspender
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Alertas -->
            <?php if (!empty($alertas)): ?>
                <div class="alert-list">
                    <?php foreach ($alertas as $alerta): ?>
                        <div class="alert-item-detail <?php echo $alerta['type']; ?>">
                            <i class="fas <?php echo $alerta['type'] === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-info'; ?>"></i>
                            <span><?php echo htmlspecialchars($alerta['message']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Grid de información -->
            <div class="info-grid">
                <!-- Información General -->
                <div class="info-card">
                    <h3><i class="fas fa-info-circle"></i> Información General</h3>
                    <div class="info-row">
                        <span class="label">Tipo de operación</span>
                        <span class="value"><?php echo ucfirst($propiedad['operation_type'] ?? 'No especificado'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">Municipio</span>
                        <span class="value"><?php echo htmlspecialchars($propiedad['address_municipality'] ?? 'No especificado'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">Ciudad</span>
                        <span class="value"><?php echo htmlspecialchars($propiedad['address_city'] ?? 'No especificado'); ?></span>
                    </div>
                    <?php if (!empty($propiedad['address_lat']) && !empty($propiedad['address_lng'])): ?>
                    <div class="info-row">
                        <span class="label">Ubicación</span>
                        <span class="value" style="font-size: 0.75rem;">
                            <?php echo $propiedad['address_lat']; ?>, <?php echo $propiedad['address_lng']; ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <span class="label">Fecha creación</span>
                        <span class="value"><?php echo date('d/m/Y H:i', strtotime($propiedad['created_at'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">Última actualización</span>
                        <span class="value"><?php echo date('d/m/Y H:i', strtotime($propiedad['updated_at'])); ?></span>
                    </div>
                </div>

                <!-- Información Financiera -->
                <div class="info-card">
                    <h3><i class="fas fa-coins"></i> Información Financiera</h3>
                    <div class="info-row">
                        <span class="label">Precio de venta</span>
                        <span class="value highlight"><?php echo formatearPrecio($propiedad['price']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">Precio mínimo aceptable</span>
                        <span class="value"><?php echo formatearPrecio($propiedad['min_acceptable_price']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">Margen de ganancia</span>
                        <span class="value <?php echo ($propiedad['profit_margin_amount'] ?? 0) > 0 ? 'success' : 'warning'; ?>">
                            <?php echo formatearPrecio($propiedad['profit_margin_amount'] ?? 0); ?>
                            <?php if (!empty($propiedad['potential_profit_margin'])): ?>
                                (<?php echo number_format($propiedad['potential_profit_margin'], 1); ?>%)
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="label">Comisión (%)</span>
                        <span class="value"><?php echo number_format($propiedad['commission_percentage'] ?? 0, 1); ?>%</span>
                    </div>
                    <div class="info-row">
                        <span class="label">Comisión estimada</span>
                        <span class="value highlight"><?php echo formatearPrecio($propiedad['commission_amount'] ?? 0); ?></span>
                    </div>
                </div>

                <!-- Características Físicas -->
                <div class="info-card">
                    <h3><i class="fas fa-ruler-combined"></i> Características Físicas</h3>
                    <div class="info-row">
                        <span class="label">Superficie (m²)</span>
                        <span class="value"><?php echo number_format($propiedad['square_meters'] ?? 0, 0, ',', '.'); ?> m²</span>
                    </div>
                    <div class="info-row">
                        <span class="label">Habitaciones</span>
                        <span class="value"><?php echo $propiedad['bedrooms'] ?? 'No especificado'; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">Baños</span>
                        <span class="value"><?php echo $propiedad['bathrooms'] ?? 'No especificado'; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">Estacionamientos</span>
                        <span class="value"><?php echo $propiedad['parking_spots'] ?? 'No especificado'; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">Año de construcción</span>
                        <span class="value"><?php echo $propiedad['year_built'] ?? 'No especificado'; ?></span>
                    </div>
                </div>

                <!-- Resumen Rápido -->
                <div class="info-card">
                    <h3><i class="fas fa-chart-simple"></i> Resumen Rápido</h3>
                    <div class="info-row">
                        <span class="label">ID Propiedad</span>
                        <span class="value">#<?php echo $propiedad['id']; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">Estado</span>
                        <span class="value">
                            <span class="status-badge <?php echo getStatusBadge($propiedad['status']); ?>">
                                <?php echo ucfirst($propiedad['status'] ?? 'Sin estado'); ?>
                            </span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="label">Días en mercado</span>
                        <span class="value"><?php echo $propiedad['days_active'] ?? 0; ?> días</span>
                    </div>
                    <div class="info-row">
                        <span class="label">Imágenes</span>
                        <span class="value"><?php echo count($imagenes); ?> archivos</span>
                    </div>
                    <div class="info-row">
                        <span class="label">Documentos</span>
                        <span class="value"><?php echo count($documentos); ?> archivos</span>
                    </div>
                    <div class="info-row" style="border-top: 2px solid #e8edf4; padding-top: 10px; margin-top: 4px;">
                        <span class="label" style="font-weight: 700;">Valor total</span>
                        <span class="value highlight" style="font-size: 1.2rem;">
                            <?php echo formatearPrecio($propiedad['price']); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Galería de Imágenes -->
            <div class="info-card" style="margin-bottom: 20px;">
                <h3><i class="fas fa-images"></i> Galería de Imágenes</h3>
                <?php if (empty($imagenes)): ?>
                    <div class="empty-state-small">
                        <i class="fas fa-image"></i>
                        <p>No hay imágenes cargadas para esta propiedad</p>
                    </div>
                <?php else: ?>
                    <div class="gallery-grid">
                        <?php foreach ($imagenes as $img): ?>
                            <div class="gallery-item <?php echo $img['is_primary'] ? 'primary' : ''; ?>">
                                <img src="<?php echo getImagePath($img['file_path']); ?>" 
                                     alt="<?php echo htmlspecialchars($img['file_name']); ?>"
                                     loading="lazy">
                                <?php if ($img['is_primary']): ?>
                                    <span class="gallery-badge primary">Principal</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Documentos -->
            <div class="info-card" style="margin-bottom: 20px;">
                <h3><i class="fas fa-file-alt"></i> Documentos Adjuntos</h3>
                <?php if (empty($documentos)): ?>
                    <div class="empty-state-small">
                        <i class="fas fa-file"></i>
                        <p>No hay documentos adjuntos</p>
                    </div>
                <?php else: ?>
                    <div class="doc-list">
                        <?php foreach ($documentos as $doc): ?>
                            <div class="doc-item">
                                <div class="doc-icon">
                                    <i class="fas <?php echo getDocumentIcon($doc['mime_type']); ?>"></i>
                                </div>
                                <div class="doc-info">
                                    <div class="doc-name"><?php echo htmlspecialchars($doc['file_name']); ?></div>
                                    <div class="doc-meta">
                                        <?php echo ucfirst(str_replace('_', ' ', $doc['document_type'] ?? 'Documento')); ?>
                                        • <?php echo formatFileSize($doc['file_size']); ?>
                                        • <?php echo date('d/m/Y', strtotime($doc['uploaded_at'])); ?>
                                    </div>
                                </div>
                                <a href="<?php echo getImagePath($doc['file_path']); ?>" target="_blank" class="doc-action">
                                    <i class="fas fa-download"></i> Ver
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php endif; ?>
    </div>
</main>

<script>
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

    window.suspenderPropiedad = function(id) {
        if (confirm('¿Estás seguro de que deseas suspender esta propiedad?')) {
            // Aquí iría la lógica para suspender
            alert('Función: Suspender propiedad #' + id);
        }
    };
});
</script>

</body>
</html>