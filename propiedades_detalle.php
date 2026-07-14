<?php
session_start();
require_once 'includes/conexion.php';

// ========================================
// OBTENER ID DE LA PROPIEDAD
// ========================================
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header("Location: propiedades_inventario.php");
    exit();
}

// ========================================
// OBTENER DATOS COMPLETOS DE LA PROPIEDAD
// ========================================
try {
    $sql = "SELECT 
                p.*,
                pd.square_meters,
                pd.bedrooms,
                pd.bathrooms,
                pd.year_built,
                pd.parking_spots,
                pf.asking_price,
                pf.min_acceptable_price,
                pf.commission_percentage,
                pl.has_lien_debt_amount,
                pl.legal_status_notes,
                pl.documents_status,
                u.name as propietario_nombre,
                u.email as propietario_email,
                u.phone as propietario_telefono
            FROM properties p
            LEFT JOIN property_details pd ON p.id = pd.property_id
            LEFT JOIN property_financials pf ON p.id = pf.property_id
            LEFT JOIN property_legal pl ON p.id = pl.property_id
            LEFT JOIN users u ON p.owner_id = u.id
            WHERE p.id = ? AND p.status = 'active'";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$id]);
    $propiedad = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$propiedad) {
        header("Location: propiedades_inventario.php");
        exit();
    }

    // ========================================
    // OBTENER IMÁGENES DE LA PROPIEDAD
    // ========================================
    $sql_imagenes = "SELECT * FROM property_media 
                     WHERE property_id = ? 
                     ORDER BY is_primary DESC, sort_order ASC";
    $stmt_imagenes = $conn->prepare($sql_imagenes);
    $stmt_imagenes->execute([$id]);
    $imagenes = $stmt_imagenes->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error al obtener propiedad: " . $e->getMessage());
    header("Location: propiedades_inventario.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($propiedad['titulo'] ?? 'Propiedad'); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ======================================== */
        /* ESTILOS BÁSICOS */
        /* ======================================== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            min-height: 100vh;
        }

        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 20px;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: white;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            text-decoration: none;
            color: #1a1a2e;
            font-weight: 600;
            transition: all 0.3s;
            margin-bottom: 20px;
        }

        .btn-back:hover {
            background: #f8f9fa;
            transform: translateX(-3px);
        }

        /* ======================================== */
        /* TARJETA PRINCIPAL */
        /* ======================================== */
        .property-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 20px rgba(0,0,0,0.08);
        }

        /* ======================================== */
        /* GALERÍA DE IMÁGENES */
        /* ======================================== */
        .gallery {
            position: relative;
        }

        .gallery-main {
            width: 100%;
            height: 500px;
            background: #dee2e6;
            overflow: hidden;
            position: relative;
        }

        .gallery-main img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .gallery-main .no-image {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #6c757d;
            font-size: 18px;
            background: #e9ecef;
            flex-direction: column;
            gap: 15px;
        }

        .gallery-main .no-image i {
            font-size: 64px;
            opacity: 0.3;
        }

        .gallery-thumbs {
            display: flex;
            gap: 10px;
            padding: 15px;
            overflow-x: auto;
            background: #f8f9fa;
        }

        .gallery-thumbs .thumb {
            width: 100px;
            height: 70px;
            flex-shrink: 0;
            border-radius: 8px;
            overflow: hidden;
            cursor: pointer;
            border: 3px solid transparent;
            transition: all 0.3s;
        }

        .gallery-thumbs .thumb:hover {
            border-color: #1a1a2e;
        }

        .gallery-thumbs .thumb.active {
            border-color: #28a745;
        }

        .gallery-thumbs .thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* ======================================== */
        /* INFORMACIÓN DE LA PROPIEDAD */
        /* ======================================== */
        .property-content {
            padding: 30px;
        }

        .property-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }

        .property-title-detail {
            font-size: 28px;
            color: #1a1a2e;
            flex: 1;
        }

        .property-price-detail {
            font-size: 32px;
            font-weight: 700;
            color: #28a745;
            white-space: nowrap;
        }

        .property-location-detail {
            color: #6c757d;
            font-size: 16px;
            margin-bottom: 20px;
        }

        .property-location-detail i {
            margin-right: 8px;
        }

        /* ======================================== */
        /* BADGES */
        /* ======================================== */
        .property-badges-detail {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
        }

        .badge-detail {
            padding: 6px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 13px;
        }

        .badge-detail.venta {
            background: #d4edda;
            color: #155724;
        }

        .badge-detail.alquiler {
            background: #cce5ff;
            color: #004085;
        }

        .badge-detail.renta {
            background: #fff3cd;
            color: #856404;
        }

        .badge-detail.active {
            background: #d4edda;
            color: #155724;
        }

        /* ======================================== */
        /* CARACTERÍSTICAS */
        /* ======================================== */
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
            padding: 20px 0;
            border-top: 1px solid #f0f0f0;
            border-bottom: 1px solid #f0f0f0;
            margin-bottom: 20px;
        }

        .feature-item {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #495057;
        }

        .feature-item i {
            font-size: 20px;
            color: #1a1a2e;
            width: 25px;
        }

        .feature-item .label {
            font-size: 12px;
            color: #6c757d;
        }

        .feature-item .value {
            font-weight: 600;
            font-size: 16px;
        }

        /* ======================================== */
        /* DETALLES ADICIONALES */
        /* ======================================== */
        .details-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin: 20px 0;
        }

        .details-section h4 {
            color: #1a1a2e;
            margin-bottom: 12px;
            font-size: 16px;
        }

        .details-section .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px solid #f8f9fa;
            font-size: 14px;
        }

        .details-section .detail-row .label {
            color: #6c757d;
        }

        .details-section .detail-row .value {
            font-weight: 500;
        }

        /* ======================================== */
        /* BOTONES DE ACCIÓN */
        /* ======================================== */
        .action-buttons-detail {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 2px solid #f0f0f0;
        }

        .btn-action-detail {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-action-detail:hover {
            transform: translateY(-2px);
        }

        .btn-whatsapp {
            background: #25d366;
            color: white;
        }

        .btn-whatsapp:hover {
            background: #1da851;
        }

        .btn-contact {
            background: #1a1a2e;
            color: white;
        }

        .btn-contact:hover {
            background: #2d2d44;
        }

        .btn-print {
            background: #f8f9fa;
            color: #1a1a2e;
            border: 2px solid #dee2e6;
        }

        .btn-print:hover {
            background: #e9ecef;
        }

        /* ======================================== */
        /* PROPIETARIO */
        /* ======================================== */
        .owner-info {
            background: #f8f9fa;
            padding: 15px 20px;
            border-radius: 8px;
            margin-top: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .owner-info .avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #1a1a2e;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: 700;
        }

        .owner-info .owner-details {
            flex: 1;
        }

        .owner-info .owner-details .name {
            font-weight: 600;
            color: #1a1a2e;
        }

        .owner-info .owner-details .email {
            color: #6c757d;
            font-size: 14px;
        }

        /* ======================================== */
        /* RESPONSIVE */
        /* ======================================== */
        @media (max-width: 768px) {
            .gallery-main {
                height: 300px;
            }

            .property-content {
                padding: 20px;
            }

            .property-title-detail {
                font-size: 22px;
            }

            .property-price-detail {
                font-size: 24px;
            }

            .details-section {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .features-grid {
                grid-template-columns: 1fr 1fr;
            }

            .property-header {
                flex-direction: column;
            }

            .action-buttons-detail {
                flex-direction: column;
            }

            .action-buttons-detail .btn-action-detail {
                width: 100%;
                justify-content: center;
            }

            .gallery-thumbs .thumb {
                width: 80px;
                height: 55px;
            }
        }

        @media print {
            .btn-back,
            .action-buttons-detail {
                display: none !important;
            }

            .property-card {
                box-shadow: none !important;
                border: 1px solid #dee2e6;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <!-- ======================================== -->
    <!-- BOTÓN VOLVER -->
    <!-- ======================================== -->
    <a href="propiedades_inventario.php" class="btn-back">
        <i class="fas fa-arrow-left"></i> Volver al listado
    </a>

    <!-- ======================================== -->
    <!-- TARJETA DE PROPIEDAD -->
    <!-- ======================================== -->
    <div class="property-card">
        <!-- ======================================== -->
        <!-- GALERÍA -->
        <!-- ======================================== -->
        <div class="gallery">
            <div class="gallery-main" id="galleryMain">
                <?php if (!empty($imagenes)): 
                    $imagen_principal = $imagenes[0];
                    $imagen_path = $imagen_principal['file_path'];
                ?>
                    <?php if (file_exists($imagen_path)): ?>
                        <img src="<?php echo htmlspecialchars($imagen_path); ?>" 
                             alt="<?php echo htmlspecialchars($propiedad['titulo'] ?? 'Propiedad'); ?>"
                             id="mainImage">
                    <?php endif; ?>
                <?php else: ?>
                    <div class="no-image">
                        <i class="fas fa-home"></i>
                        <span>Esta propiedad no tiene imágenes</span>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (count($imagenes) > 1): ?>
                <div class="gallery-thumbs">
                    <?php foreach ($imagenes as $index => $imagen): ?>
                        <div class="thumb <?php echo $index === 0 ? 'active' : ''; ?>" 
                             data-index="<?php echo $index; ?>"
                             onclick="cambiarImagen(<?php echo $index; ?>)">
                            <?php if (file_exists($imagen['file_path'])): ?>
                                <img src="<?php echo htmlspecialchars($imagen['file_path']); ?>" 
                                     alt="Thumbnail <?php echo $index + 1; ?>">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ======================================== -->
        <!-- CONTENIDO -->
        <!-- ======================================== -->
        <div class="property-content">
            <!-- Header -->
            <div class="property-header">
                <h1 class="property-title-detail">
                    <?php echo htmlspecialchars($propiedad['titulo'] ?? 'Propiedad sin título'); ?>
                </h1>
                <div class="property-price-detail">
                    $<?php echo number_format($propiedad['asking_price'] ?? 0, 0); ?>
                </div>
            </div>

            <!-- Ubicación -->
            <div class="property-location-detail">
                <i class="fas fa-map-marker-alt"></i>
                <?php 
                $ubicacion = '';
                if (!empty($propiedad['address_city'])) {
                    $ubicacion .= $propiedad['address_city'];
                }
                if (!empty($propiedad['address_municipality'])) {
                    $ubicacion .= ($ubicacion ? ', ' : '') . $propiedad['address_municipality'];
                }
                echo htmlspecialchars($ubicacion ?: 'Ubicación no especificada');
                ?>
            </div>

            <!-- Badges -->
            <div class="property-badges-detail">
                <span class="badge-detail <?php echo $propiedad['operation_type']; ?>">
                    <?php echo ucfirst($propiedad['operation_type']); ?>
                </span>
                <span class="badge-detail active">
                    <i class="fas fa-circle" style="font-size: 10px;"></i> Activo
                </span>
                <?php if ($propiedad['documents_status'] ?? false): ?>
                    <span class="badge-detail" style="background: #fff3cd; color: #856404;">
                        📄 <?php echo ucfirst($propiedad['documents_status']); ?>
                    </span>
                <?php endif; ?>
            </div>

            <!-- Características -->
            <div class="features-grid">
                <div class="feature-item">
                    <i class="fas fa-vector-square"></i>
                    <div>
                        <div class="label">Metros cuadrados</div>
                        <div class="value"><?php echo htmlspecialchars($propiedad['square_meters'] ?? 'N/A'); ?> m²</div>
                    </div>
                </div>
                <div class="feature-item">
                    <i class="fas fa-bed"></i>
                    <div>
                        <div class="label">Recámaras</div>
                        <div class="value"><?php echo htmlspecialchars($propiedad['bedrooms'] ?? 'N/A'); ?></div>
                    </div>
                </div>
                <div class="feature-item">
                    <i class="fas fa-bath"></i>
                    <div>
                        <div class="label">Baños</div>
                        <div class="value"><?php echo htmlspecialchars($propiedad['bathrooms'] ?? 'N/A'); ?></div>
                    </div>
                </div>
                <div class="feature-item">
                    <i class="fas fa-car"></i>
                    <div>
                        <div class="label">Estacionamientos</div>
                        <div class="value"><?php echo htmlspecialchars($propiedad['parking_spots'] ?? 'N/A'); ?></div>
                    </div>
                </div>
                <?php if ($propiedad['year_built'] ?? false): ?>
                    <div class="feature-item">
                        <i class="fas fa-calendar"></i>
                        <div>
                            <div class="label">Año de construcción</div>
                            <div class="value"><?php echo htmlspecialchars($propiedad['year_built']); ?></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Detalles adicionales -->
            <div class="details-section">
                <div>
                    <h4>📋 Detalles de la propiedad</h4>
                    <div class="detail-row">
                        <span class="label">Tipo de operación</span>
                        <span class="value"><?php echo ucfirst($propiedad['operation_type']); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="label">Precio de venta</span>
                        <span class="value">$<?php echo number_format($propiedad['asking_price'] ?? 0, 0); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="label">Comisión</span>
                        <span class="value"><?php echo $propiedad['commission_percentage'] ?? 5; ?>%</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">Publicada</span>
                        <span class="value"><?php echo date('d/m/Y', strtotime($propiedad['created_at'] ?? 'now')); ?></span>
                    </div>
                </div>

                <div>
                    <h4>⚖️ Situación legal</h4>
                    <div class="detail-row">
                        <span class="label">Gravamen</span>
                        <span class="value">
                            <?php if ($propiedad['has_lien_debt_amount']): ?>
                                Sí - $<?php echo number_format($propiedad['has_lien_debt_amount'], 0); ?>
                            <?php else: ?>
                                No
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="detail-row">
                        <span class="label">Documentos</span>
                        <span class="value"><?php echo ucfirst($propiedad['documents_status'] ?? 'Pendiente'); ?></span>
                    </div>
                    <?php if ($propiedad['legal_status_notes']): ?>
                        <div class="detail-row" style="flex-direction: column; align-items: flex-start; gap: 5px; padding: 10px 0;">
                            <span class="label">Notas legales</span>
                            <span class="value" style="font-weight: normal; font-size: 13px; color: #6c757d;">
                                <?php echo nl2br(htmlspecialchars($propiedad['legal_status_notes'])); ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Propietario -->
            <div class="owner-info">
                <div class="avatar">
                    <?php 
                    $nombre = $propiedad['propietario_nombre'] ?? 'U';
                    echo strtoupper(substr($nombre, 0, 1));
                    ?>
                </div>
                <div class="owner-details">
                    <div class="name">
                        <?php echo htmlspecialchars($propiedad['propietario_nombre'] ?? 'Propietario no especificado'); ?>
                    </div>
                    <?php if ($propiedad['propietario_email'] ?? false): ?>
                        <div class="email">
                            <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($propiedad['propietario_email']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Botones de acción -->
            <div class="action-buttons-detail">
                <?php 
                $whatsapp_numero = '521XXXXXXXXXX'; // Cambiar por número real
                $mensaje = urlencode("Hola, estoy interesado en la propiedad: " . ($propiedad['titulo'] ?? 'Propiedad') . " - $" . number_format($propiedad['asking_price'] ?? 0, 0));
                ?>
                <a href="https://api.whatsapp.com/send?phone=<?php echo $whatsapp_numero; ?>&text=<?php echo $mensaje; ?>" 
                   target="_blank" class="btn-action-detail btn-whatsapp">
                    <i class="fab fa-whatsapp"></i> Contactar por WhatsApp
                </a>
                <a href="mailto:<?php echo htmlspecialchars($propiedad['propietario_email'] ?? ''); ?>" 
                   class="btn-action-detail btn-contact">
                    <i class="fas fa-envelope"></i> Enviar correo
                </a>
                <button class="btn-action-detail btn-print" onclick="window.print()">
                    <i class="fas fa-print"></i> Imprimir
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ======================================== -->
<!-- SCRIPT PARA GALERÍA -->
<!-- ======================================== -->
<script>
    // ========================================
    // CAMBIAR IMAGEN PRINCIPAL
    // ========================================
    const imagenes = <?php echo json_encode(array_map(function($img) {
        return file_exists($img['file_path']) ? $img['file_path'] : null;
    }, $imagenes)); ?>;

    function cambiarImagen(index) {
        // Cambiar imagen principal
        const mainImage = document.getElementById('mainImage');
        if (mainImage && imagenes[index]) {
            mainImage.src = imagenes[index];
        }

        // Actualizar thumbnails activos
        document.querySelectorAll('.thumb').forEach((thumb, i) => {
            thumb.classList.toggle('active', i === index);
        });
    }

    // ========================================
    // AUTO-ROTACIÓN DE IMÁGENES (OPCIONAL)
    // ========================================
    <?php if (count($imagenes) > 1): ?>
    let currentIndex = 0;
    const totalImages = <?php echo count($imagenes); ?>;

    setInterval(() => {
        currentIndex = (currentIndex + 1) % totalImages;
        cambiarImagen(currentIndex);
    }, 5000);
    <?php endif; ?>
</script>

</body>
</html>