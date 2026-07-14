<?php
session_start();

// ========================================
// VERIFICAR QUE EXISTA UNA PROPIEDAD PUBLICADA
// ========================================
if (!isset($_SESSION['ultima_propiedad_id']) || !isset($_SESSION['form_venta'])) {
    header("Location: vender.php");
    exit();
}

// Obtener datos de la propiedad
$property_id = $_SESSION['ultima_propiedad_id'];
$data = $_SESSION['form_venta'];

// ========================================
// CONEXIÓN A LA BASE DE DATOS CON PDO
// ========================================
require_once 'includes/conexion.php';

// ========================================
// FUNCIÓN PARA OBTENER PROPIEDAD COMPLETA CON IMAGEN PRINCIPAL
// ========================================
function obtenerPropiedadCompleta($property_id) {
    global $conn;
    
    try {
        $sql = "SELECT 
                    p.*,
                    pd.square_meters,
                    pd.bedrooms,
                    pd.bathrooms,
                    pd.parking_spots,
                    pf.asking_price,
                    pl.has_lien_debt_amount,
                    pl.legal_status_notes,
                    COUNT(pm.id) as total_imagenes,
                    (SELECT file_path FROM property_media 
                     WHERE property_id = p.id AND is_primary = 1 
                     LIMIT 1) as imagen_principal
                FROM properties p
                LEFT JOIN property_details pd ON p.id = pd.property_id
                LEFT JOIN property_financials pf ON p.id = pf.property_id
                LEFT JOIN property_legal pl ON p.id = pl.property_id
                LEFT JOIN property_media pm ON p.id = pm.property_id
                WHERE p.id = ?
                GROUP BY p.id";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([$property_id]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error al obtener propiedad: " . $e->getMessage());
        return null;
    }
}

// ========================================
// FUNCIÓN PARA OBTENER NOMBRE DEL PROPIETARIO
// ========================================
function obtenerNombrePropietario($owner_id) {
    global $conn;
    
    try {
        $sql = "SELECT name FROM users WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$owner_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['name'] : 'No especificado';
    } catch (PDOException $e) {
        return 'No especificado';
    }
}

// Obtener datos
$propiedad = obtenerPropiedadCompleta($property_id);

// Si no se encuentra la propiedad, redirigir
if (!$propiedad) {
    header("Location: vender.php");
    exit();
}

$nombre_propietario = obtenerNombrePropietario($propiedad['owner_id'] ?? 0);

// Determinar la imagen principal
$imagen_principal = $propiedad['imagen_principal'] ?? null;
if ($imagen_principal && file_exists($imagen_principal)) {
    $imagen_url = $imagen_principal;
} else {
    // Imagen por defecto si no hay imagen
    $imagen_url = 'img/propiedad-default.jpg';
}

// Limpiar datos de sesión si deseas que sea una sola vez
// $_SESSION['form_venta'] = [];
// $_SESSION['ultima_propiedad_id'] = null;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>✅ Propiedad Publicada Exitosamente</title>
    <link rel="stylesheet" href="css/wizard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ========================================
           ESTILOS MEJORADOS
           ======================================== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .success-wrapper {
            width: 100%;
            max-width: 900px;
            animation: slideUp 0.6s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(40px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .success-card {
            background: white;
            border-radius: 24px;
            box-shadow: 0 30px 80px rgba(0,0,0,0.25);
            padding: 0;
            overflow: hidden;
            position: relative;
        }
        
        .success-header {
            background: linear-gradient(135deg, #28a745, #20c997);
            padding: 40px 40px 30px;
            text-align: center;
            color: white;
            position: relative;
        }
        
        .success-header::after {
            content: '';
            position: absolute;
            bottom: -20px;
            left: 0;
            right: 0;
            height: 40px;
            background: white;
            border-radius: 50% 50% 0 0 / 100% 100% 0 0;
        }
        
        .success-icon {
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 40px;
            animation: pulse 2s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .success-title {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .success-subtitle {
            font-size: 16px;
            opacity: 0.9;
        }
        
        .success-body {
            padding: 30px 40px 40px;
        }
        
        /* ========================================
           BADGES
           ======================================== */
        .badges-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: center;
            margin-bottom: 25px;
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 18px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .badge-folio {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .badge-owner {
            background: #e3f2fd;
            color: #0d47a1;
        }
        
        .badge-status {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .badge-status i {
            font-size: 10px;
        }
        
        /* ========================================
           TARJETA DE PROPIEDAD (IMAGEN + DATOS)
           ======================================== */
        .property-card {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 25px;
            background: #f8f9fa;
            border-radius: 16px;
            overflow: hidden;
            margin: 20px 0 30px;
            border: 1px solid #e9ecef;
        }
        
        .property-image {
            height: 100%;
            min-height: 250px;
            background: #dee2e6;
            position: relative;
            overflow: hidden;
        }
        
        .property-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        
        .property-image .no-image {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #6c757d;
            font-size: 14px;
            background: #e9ecef;
            flex-direction: column;
            gap: 10px;
        }
        
        .property-image .no-image i {
            font-size: 48px;
            opacity: 0.3;
        }
        
        .property-info {
            padding: 20px 25px 20px 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .property-title-card {
            font-size: 22px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 5px;
        }
        
        .property-location {
            color: #6c757d;
            font-size: 14px;
            margin-bottom: 12px;
        }
        
        .property-location i {
            margin-right: 5px;
        }
        
        .property-price-card {
            font-size: 28px;
            font-weight: 700;
            color: #28a745;
            margin-bottom: 12px;
        }
        
        .property-features {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 12px;
        }
        
        .property-features .feature {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #495057;
            font-size: 14px;
        }
        
        .property-features .feature i {
            color: #6c757d;
            font-size: 16px;
        }
        
        .property-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 5px 20px;
            margin-top: 5px;
            font-size: 14px;
        }
        
        .property-details-grid .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            border-bottom: 1px dashed #e9ecef;
        }
        
        .property-details-grid .detail-item .label {
            color: #6c757d;
        }
        
        .property-details-grid .detail-item .value {
            color: #1a1a2e;
            font-weight: 500;
        }
        
        /* ========================================
           BOTONES DE ACCIÓN
           ======================================== */
        .action-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 15px;
        }
        
        .btn-action {
            padding: 14px 32px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: inherit;
        }
        
        .btn-action:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
        }
        
        .btn-primary {
            background: #1a1a2e;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2d2d44;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-outline {
            background: transparent;
            color: #1a1a2e;
            border: 2px solid #dee2e6;
        }
        
        .btn-outline:hover {
            background: #f8f9fa;
            border-color: #1a1a2e;
        }
        
        .btn-whatsapp {
            background: #25d366;
            color: white;
        }
        
        .btn-whatsapp:hover {
            background: #1da851;
        }
        
        /* ========================================
           REDES SOCIALES
           ======================================== */
        .social-share {
            margin-top: 25px;
            padding-top: 20px;
            border-top: 2px solid #f0f0f0;
            text-align: center;
        }
        
        .social-share p {
            color: #6c757d;
            font-size: 14px;
            margin-bottom: 12px;
        }
        
        .social-icons {
            display: flex;
            gap: 12px;
            justify-content: center;
        }
        
        .social-icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .social-icon:hover {
            transform: translateY(-3px) scale(1.05);
        }
        
        .social-icon.facebook { background: #1877f2; }
        .social-icon.twitter { background: #1da1f2; }
        .social-icon.whatsapp { background: #25d366; }
        .social-icon.linkedin { background: #0a66c2; }
        
        /* ========================================
           RESPONSIVE
           ======================================== */
        @media (max-width: 768px) {
            .property-card {
                grid-template-columns: 1fr;
            }
            
            .property-image {
                min-height: 200px;
                max-height: 250px;
            }
            
            .property-info {
                padding: 20px;
            }
            
            .success-body {
                padding: 20px;
            }
            
            .success-header {
                padding: 30px 20px 25px;
            }
            
            .success-title {
                font-size: 24px;
            }
            
            .property-price-card {
                font-size: 24px;
            }
            
            .property-details-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: stretch;
            }
            
            .btn-action {
                justify-content: center;
            }
        }
        
        /* ========================================
           CONFETI
           ======================================== */
        .confetti-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 9999;
            overflow: hidden;
        }
        
        .confetti {
            position: absolute;
            width: 10px;
            height: 10px;
            top: -10px;
            animation: confettiFall linear forwards;
        }
        
        @keyframes confettiFall {
            to {
                transform: translateY(100vh) rotate(720deg);
                opacity: 0;
            }
        }
        
        .mensaje-extra {
            text-align: center;
            margin-top: 20px;
            color: #6c757d;
            font-size: 14px;
        }
        
        .mensaje-extra i {
            color: #28a745;
        }
    </style>
</head>
<body>

<!-- ======================================== -->
<!-- CONFETI -->
<!-- ======================================== -->
<div class="confetti-container" id="confettiContainer"></div>

<!-- ======================================== -->
<!-- TARJETA PRINCIPAL -->
<!-- ======================================== -->
<div class="success-wrapper">
    <div class="success-card">
        
        <!-- HEADER -->
        <div class="success-header">
            <div class="success-icon">
                <i class="fas fa-check"></i>
            </div>
            <h1 class="success-title">¡Propiedad Publicada! 🎉</h1>
            <p class="success-subtitle">
                Tu propiedad ya está disponible para que los compradores la vean
            </p>
        </div>
        
        <!-- BODY -->
        <div class="success-body">
            
            <!-- BADGES -->
            <div class="badges-row">
                <span class="badge badge-folio">
                    <i class="fas fa-hashtag"></i>
                    Folio: #<?php echo str_pad($property_id, 8, '0', STR_PAD_LEFT); ?>
                </span>
                <span class="badge badge-owner">
                    <i class="fas fa-user"></i>
                    <?php echo htmlspecialchars($nombre_propietario); ?>
                </span>
                <span class="badge badge-status">
                    <i class="fas fa-circle"></i>
                    Activo
                </span>
                <span class="badge" style="background: #fff3cd; color: #856404;">
                    <i class="fas fa-calendar-alt"></i>
                    <?php echo date('d/m/Y', strtotime($propiedad['created_at'] ?? 'now')); ?>
                </span>
            </div>
            
            <!-- ======================================== -->
            <!-- TARJETA DE PROPIEDAD -->
            <!-- ======================================== -->
            <div class="property-card">
                <!-- Imagen -->
                <div class="property-image">
                    <?php if ($imagen_url && file_exists($imagen_url)): ?>
                        <img src="<?php echo htmlspecialchars($imagen_url); ?>" alt="Imagen principal de la propiedad">
                    <?php else: ?>
                        <div class="no-image">
                            <i class="fas fa-home"></i>
                            <span>Sin imagen principal</span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Información -->
                <div class="property-info">
                    <h2 class="property-title-card">
                        <?php echo htmlspecialchars($propiedad['titulo'] ?? $data['titulo'] ?? 'Propiedad'); ?>
                    </h2>
                    
                    <div class="property-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php 
                        $ubicacion = '';
                        if (!empty($propiedad['address_city'])) {
                            $ubicacion .= $propiedad['address_city'];
                        }
                        if (!empty($propiedad['address_municipality'])) {
                            $ubicacion .= ($ubicacion ? ', ' : '') . $propiedad['address_municipality'];
                        }
                        echo htmlspecialchars($ubicacion ?: ($data['ubicacion'] ?? 'Ubicación no especificada'));
                        ?>
                    </div>
                    
                    <div class="property-price-card">
                        $<?php echo number_format($propiedad['asking_price'] ?? $data['precio'] ?? 0, 0); ?>
                    </div>
                    
                    <div class="property-features">
                        <span class="feature">
                            <i class="fas fa-vector-square"></i>
                            <?php echo htmlspecialchars($propiedad['square_meters'] ?? $data['m2'] ?? 'N/A'); ?> m²
                        </span>
                        <span class="feature">
                            <i class="fas fa-bed"></i>
                            <?php echo htmlspecialchars($propiedad['bedrooms'] ?? $data['recamaras'] ?? 'N/A'); ?> rec.
                        </span>
                        <span class="feature">
                            <i class="fas fa-bath"></i>
                            <?php echo htmlspecialchars($propiedad['bathrooms'] ?? $data['banos'] ?? 'N/A'); ?> baños
                        </span>
                        <span class="feature">
                            <i class="fas fa-car"></i>
                            <?php echo htmlspecialchars($propiedad['parking_spots'] ?? $data['estacionamiento'] ?? 'N/A'); ?> est.
                        </span>
                    </div>
                    
                    <div class="property-details-grid">
                        <div class="detail-item">
                            <span class="label">Tipo de operación</span>
                            <span class="value"><?php echo ucfirst(htmlspecialchars($propiedad['operation_type'] ?? $data['tipo_operacion'] ?? 'N/A')); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="label">Estado legal</span>
                            <span class="value"><?php echo ucfirst(htmlspecialchars($data['legal_status'] ?? 'N/A')); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="label">Imágenes</span>
                            <span class="value"><?php echo $propiedad['total_imagenes'] ?? 0; ?> fotos</span>
                        </div>
                        <div class="detail-item">
                            <span class="label">Publicada</span>
                            <span class="value"><?php echo date('d/m/Y H:i', strtotime($propiedad['created_at'] ?? 'now')); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ======================================== -->
            <!-- BOTONES DE ACCIÓN -->
            <!-- ======================================== -->
            <div class="action-buttons">
                <a href="ver_propiedad.php?id=<?php echo $property_id; ?>" class="btn-action btn-primary">
                    <i class="fas fa-eye"></i>
                    Ver mi propiedad
                </a>
                <a href="vender.php" class="btn-action btn-success">
                    <i class="fas fa-plus-circle"></i>
                    Publicar otra
                </a>
                <a href="index.php" class="btn-action btn-outline">
                    <i class="fas fa-home"></i>
                    Ir al inicio
                </a>
            </div>
            
            <!-- ======================================== -->
            <!-- COMPARTIR EN REDES SOCIALES -->
            <!-- ======================================== -->
            <div class="social-share">
                <p>📣 Comparte tu propiedad en redes sociales</p>
                <div class="social-icons">
                    <?php
                    $url = "https://" . $_SERVER['HTTP_HOST'] . "/ver_propiedad.php?id=" . $property_id;
                    $titulo = urlencode("🏠 " . ($data['titulo'] ?? 'Propiedad en venta') . " - $" . number_format($propiedad['asking_price'] ?? 0, 0));
                    ?>
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($url); ?>" 
                       target="_blank" class="social-icon facebook">
                        <i class="fab fa-facebook-f"></i>
                    </a>
                    <a href="https://twitter.com/intent/tweet?text=<?php echo $titulo; ?>&url=<?php echo urlencode($url); ?>" 
                       target="_blank" class="social-icon twitter">
                        <i class="fab fa-twitter"></i>
                    </a>
                    <a href="https://api.whatsapp.com/send?text=<?php echo $titulo . ' - ' . urlencode($url); ?>" 
                       target="_blank" class="social-icon whatsapp">
                        <i class="fab fa-whatsapp"></i>
                    </a>
                    <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?php echo urlencode($url); ?>" 
                       target="_blank" class="social-icon linkedin">
                        <i class="fab fa-linkedin-in"></i>
                    </a>
                </div>
            </div>
            
            <!-- Mensaje adicional -->
            <div class="mensaje-extra">
                <i class="fas fa-envelope"></i>
                Recibirás un correo de confirmación en los próximos minutos
            </div>
            
        </div>
    </div>
</div>

<!-- ======================================== -->
<!-- SCRIPT PARA CONFETI -->
<!-- ======================================== -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const container = document.getElementById('confettiContainer');
        const colors = ['#28a745', '#20c997', '#ffc107', '#ff6b6b', '#4a9eff', '#ff85c0', '#ffd93d', '#764ba2', '#667eea'];
        const shapes = ['■', '●', '▲', '★', '♦', '♥', '✦'];
        
        function createConfetti() {
            const confetti = document.createElement('div');
            confetti.className = 'confetti';
            
            const size = Math.random() * 8 + 6;
            confetti.style.width = size + 'px';
            confetti.style.height = size + 'px';
            confetti.style.background = colors[Math.floor(Math.random() * colors.length)];
            
            if (Math.random() > 0.5) {
                confetti.textContent = shapes[Math.floor(Math.random() * shapes.length)];
                confetti.style.background = 'transparent';
                confetti.style.color = colors[Math.floor(Math.random() * colors.length)];
                confetti.style.fontSize = size * 1.5 + 'px';
                confetti.style.width = 'auto';
                confetti.style.height = 'auto';
            }
            
            confetti.style.left = Math.random() * 100 + '%';
            const duration = Math.random() * 2 + 2;
            const delay = Math.random() * 2;
            confetti.style.animationDuration = duration + 's';
            confetti.style.animationDelay = delay + 's';
            confetti.style.transform = 'rotate(' + (Math.random() * 360) + 'deg)';
            
            container.appendChild(confetti);
            
            setTimeout(() => {
                confetti.remove();
            }, (duration + delay) * 1000);
        }
        
        // Generar confeti por 3 segundos
        let interval = setInterval(() => {
            createConfetti();
        }, 80);
        
        setTimeout(() => {
            clearInterval(interval);
        }, 3000);
        
        // Confeti extra
        setTimeout(() => {
            let extraInterval = setInterval(() => {
                createConfetti();
            }, 150);
            setTimeout(() => {
                clearInterval(extraInterval);
            }, 2000);
        }, 3000);
    });
</script>

</body>
</html>