<?php
session_start();
require_once 'includes/conexion.php';

// Obtener propiedades destacadas de la base de datos
$propiedades_destacadas = [];
try {
    $stmt = $conn->prepare("SELECT * FROM propiedades WHERE destacada = 1 AND estado = 'activa' ORDER BY fecha_creacion DESC LIMIT 6");
    $stmt->execute();
    $propiedades_destacadas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Si no hay tabla aún, usar datos de ejemplo
    $propiedades_destacadas = [];
}

// Obtener propiedades recientes
$propiedades_recientes = [];
try {
    $stmt = $conn->prepare("SELECT * FROM propiedades WHERE estado = 'activa' ORDER BY fecha_creacion DESC LIMIT 4");
    $stmt->execute();
    $propiedades_recientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $propiedades_recientes = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Inmobiliaria MH - Encuentra la propiedad de tus sueños. Compra, vende o alquila con los mejores expertos.">
    <link rel="stylesheet" href="css/style.css">
    <title>Inmobiliaria MH | Exclusividad y Confianza</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

</head>
<body>

    <!-- ===== NAVEGACIÓN ===== -->
    <nav class="navbar" id="navbar">
        <div class="logo">
            <i class="fas fa-building"></i>
            <span>INMOBILIARIA MH</span>
        </div>
        
        <button class="menu-toggle" id="menuToggle" aria-label="Menú">
            <span></span>
            <span></span>
            <span></span>
        </button>

        <ul class="nav-links" id="navLinks">
            <li><a href="#inicio">Inicio</a></li>
            <li><a href="#propiedades">Propiedades</a></li>
            <li><a href="#servicios">Servicios</a></li>
            <li><a href="#testimonios">Testimonios</a></li>
            <li><a href="vender.php" class="btn-nav">
                <i class="fas fa-plus-circle"></i> Vender
            </a></li>
            <li><a href="login.php" class="btn-nav" style="background: transparent; border: 1px solid var(--accent);">
                <i class="fas fa-user"></i> Socios
            </a></li>
        </ul>
    </nav>

    <!-- ===== HERO ===== -->
    <section class="hero" id="inicio">
        <div class="hero-content">
            <div class="hero-badge">
                <i class="fas fa-star"></i> Excelencia desde 2010
            </div>
            
            <h1>
                Tu próximo patrimonio,<br>
                <span>nuestra gestión</span>
            </h1>
            
            <p>
                Expertos en encontrar la propiedad ideal para ti. 
                Más de 500 clientes satisfechos nos respaldan.
            </p>
            
            <div class="hero-buttons">
                <a href="#propiedades" class="btn btn-primary">
                    <i class="fas fa-search"></i> Ver Propiedades
                </a>
                <a href="vender.php" class="btn btn-secondary">
                    <i class="fas fa-home"></i> Quiero Vender
                </a>
            </div>

            <div class="hero-stats">
                <div class="stat">
                    <span class="stat-number" data-count="500">0</span>
                    <span class="stat-label">Propiedades Vendidas</span>
                </div>
                <div class="stat">
                    <span class="stat-number" data-count="98">0</span>
                    <span class="stat-label">% Satisfacción</span>
                </div>
                <div class="stat">
                    <span class="stat-number" data-count="120">0</span>
                    <span class="stat-label">Propiedades Activas</span>
                </div>
            </div>
        </div>
    </section>

    <!-- ===== SERVICIOS ===== -->
    <section class="section" id="servicios">
        <div class="section-header">
            <h2>Nuestros <span>Servicios</span></h2>
            <p>Soluciones integrales para todas tus necesidades inmobiliarias</p>
        </div>

        <div class="services-grid">
            <div class="service-card">
                <div class="icon"><i class="fas fa-home"></i></div>
                <h3>Venta de Propiedades</h3>
                <p>Gestionamos la venta de tu propiedad con estrategias de marketing efectivas.</p>
            </div>

            <div class="service-card">
                <div class="icon"><i class="fas fa-search"></i></div>
                <h3>Búsqueda de Inmuebles</h3>
                <p>Encontramos la propiedad perfecta según tus necesidades y presupuesto.</p>
            </div>

            <div class="service-card">
                <div class="icon"><i class="fas fa-handshake"></i></div>
                <h3>Asesoría Legal</h3>
                <p>Te acompañamos en todo el proceso legal y notarial con total seguridad.</p>
            </div>

            <div class="service-card">
                <div class="icon"><i class="fas fa-chart-line"></i></div>
                <h3>Evaluación de Propiedades</h3>
                <p>Valoramos tu propiedad con precisión para obtener el mejor precio.</p>
            </div>
        </div>
    </section>

    <!-- ===== PROPIEDADES DESTACADAS ===== -->
    <section class="section" id="propiedades" style="background: var(--light-gray); border-radius: 0;">
        <div class="section-header">
            <h2>Propiedades <span>Destacadas</span></h2>
            <p>Las mejores propiedades seleccionadas para ti</p>
        </div>

        <?php if (empty($propiedades_destacadas)): ?>
            <div style="text-align: center; padding: 40px; background: white; border-radius: var(--radius);">
                <i class="fas fa-building" style="font-size: 3rem; color: var(--accent); margin-bottom: 15px;"></i>
                <h3>Próximamente más propiedades</h3>
                <p style="color: var(--gray);">Estamos actualizando nuestro inventario. ¡Vuelve pronto!</p>
                <a href="vender.php" class="btn btn-primary" style="margin-top: 20px;">
                    Publica tu propiedad
                </a>
            </div>
        <?php else: ?>
            <div class="properties-grid">
                <?php foreach ($propiedades_destacadas as $propiedad): ?>
                    <div class="property-card">
                        <div class="property-image">
                            <img src="<?php echo !empty($propiedad['imagen']) ? 'uploads/' . $propiedad['imagen'] : 'assets/img/propiedad-default.jpg'; ?>" 
                                 alt="<?php echo htmlspecialchars($propiedad['titulo']); ?>">
                            <span class="property-badge <?php echo $propiedad['tipo_operacion']; ?>">
                                <?php echo ucfirst($propiedad['tipo_operacion'] ?? 'Venta'); ?>
                            </span>
                        </div>
                        <div class="property-info">
                            <h3><?php echo htmlspecialchars($propiedad['titulo']); ?></h3>
                            <div class="location">
                                <i class="fas fa-map-marker-alt"></i>
                                <?php echo htmlspecialchars($propiedad['ubicacion']); ?>
                            </div>
                            <div class="price">
                                $<?php echo number_format($propiedad['precio'], 0, ',', '.'); ?>
                            </div>
                            <div class="property-features">
                                <span><i class="fas fa-bed"></i> <?php echo $propiedad['habitaciones'] ?? 'N/A'; ?></span>
                                <span><i class="fas fa-bath"></i> <?php echo $propiedad['banos'] ?? 'N/A'; ?></span>
                                <span><i class="fas fa-vector-square"></i> <?php echo $propiedad['metros_cuadrados'] ?? 'N/A'; ?>m²</span>
                            </div>
                            <a href="propiedad.php?id=<?php echo $propiedad['id']; ?>" class="btn btn-primary" style="padding: 10px;">
                                Ver Detalles
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div style="text-align: center; margin-top: 40px;">
            <a href="propiedades.php" class="btn btn-outline">
                Ver todas las propiedades <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    </section>

    <!-- ===== TESTIMONIOS ===== -->
    <section class="section" id="testimonios">
        <div class="section-header">
            <h2>Lo que dicen <span>nuestros clientes</span></h2>
            <p>Historias reales de personas que encontraron su hogar con nosotros</p>
        </div>

        <div class="testimonials-grid">
            <div class="testimonial-card">
                <div class="stars">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                </div>
                <p>"Excelente servicio, encontraron la casa de mis sueños en tiempo récord. Muy profesionales y atentos."</p>
                <div class="testimonial-author">
                    <div class="avatar">MA</div>
                    <div>
                        <div class="name">María A.</div>
                        <div class="role">Compradora</div>
                    </div>
                </div>
            </div>

            <div class="testimonial-card">
                <div class="stars">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                </div>
                <p>"Vendí mi propiedad en menos de un mes. El equipo de marketing digital hizo un trabajo espectacular."</p>
                <div class="testimonial-author">
                    <div class="avatar">JR</div>
                    <div>
                        <div class="name">Juan R.</div>
                        <div class="role">Vendedor</div>
                    </div>
                </div>
            </div>

            <div class="testimonial-card">
                <div class="stars">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                </div>
                <p>"Nos asesoraron en todo el proceso legal. Muy transparentes y confiables. Los recomiendo ampliamente."</p>
                <div class="testimonial-author">
                    <div class="avatar">CP</div>
                    <div>
                        <div class="name">Carlos P.</div>
                        <div class="role">Inversionista</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ===== CTA ===== -->
    <section class="cta-section">
        <div class="cta-content">
            <h2>¿Listo para <span style="color: var(--accent);">comenzar</span>?</h2>
            <p>
                Ya sea que quieras comprar, vender o invertir, estamos aquí para ayudarte 
                a dar el siguiente paso hacia tu patrimonio.
            </p>
            <div class="cta-buttons">
                <a href="vender.php" class="btn btn-primary">
                    <i class="fas fa-home"></i> Quiero Vender
                </a>
                <a href="#propiedades" class="btn btn-secondary">
                    <i class="fas fa-search"></i> Quiero Comprar
                </a>
                <a href="login.php" class="btn btn-secondary" style="border-color: rgba(255,255,255,0.3);">
                    <i class="fas fa-user"></i> Panel Socios
                </a>
            </div>
        </div>
    </section>

    <!-- ===== FOOTER ===== -->
    <footer>
        <div class="footer-grid">
            <div class="footer-col">
                <h4>INMOBILIARIA MH</h4>
                <p>
                    Expertos en gestión inmobiliaria con más de 15 años de experiencia 
                    en el mercado. Confianza y excelencia en cada transacción.
                </p>
                <div class="social-links">
                    <a href="#" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                    <a href="#" aria-label="YouTube"><i class="fab fa-youtube"></i></a>
                    <a href="#" aria-label="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                </div>
            </div>

            <div class="footer-col">
                <h4>Enlaces Rápidos</h4>
                <ul>
                    <li><a href="#propiedades">Propiedades</a></li>
                    <li><a href="vender.php">Vender</a></li>
                    <li><a href="login.php">Acceso Socios</a></li>
                    <li><a href="contacto.php">Contacto</a></li>
                </ul>
            </div>

            <div class="footer-col">
                <h4>Servicios</h4>
                <ul>
                    <li><a href="#">Venta de Propiedades</a></li>
                    <li><a href="#">Búsqueda de Inmuebles</a></li>
                    <li><a href="#">Asesoría Legal</a></li>
                    <li><a href="#">Evaluación de Propiedades</a></li>
                </ul>
            </div>

            <div class="footer-col">
                <h4>Contacto</h4>
                <ul>
                    <li><i class="fas fa-phone" style="color: var(--accent);"></i> +52 55 1234 5678</li>
                    <li><i class="fas fa-envelope" style="color: var(--accent);"></i> info@inmobiliariamh.com</li>
                    <li><i class="fas fa-map-marker-alt" style="color: var(--accent);"></i> Ciudad de México</li>
                </ul>
            </div>
        </div>

        <div class="footer-bottom">
            <p>&copy; 2026 Inmobiliaria MH - Gestión Inmobiliaria Premium. Todos los derechos reservados.</p>
        </div>
    </footer>

    <!-- ===== JAVASCRIPT ===== -->
    <script>
        // ===== Navegación con scroll =====
        const navbar = document.getElementById('navbar');
        let lastScroll = 0;

        window.addEventListener('scroll', () => {
            const currentScroll = window.pageYOffset;
            
            if (currentScroll > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
            
            lastScroll = currentScroll;
        });

        // ===== Menú móvil =====
        const menuToggle = document.getElementById('menuToggle');
        const navLinks = document.getElementById('navLinks');

        menuToggle.addEventListener('click', () => {
            navLinks.classList.toggle('open');
            menuToggle.classList.toggle('active');
        });

        // Cerrar menú al hacer clic en un enlace
        document.querySelectorAll('.nav-links a').forEach(link => {
            link.addEventListener('click', () => {
                navLinks.classList.remove('open');
                menuToggle.classList.remove('active');
            });
        });

        // ===== Animación de contadores =====
        function animateCounters() {
            const counters = document.querySelectorAll('.stat-number');
            
            counters.forEach(counter => {
                const target = parseInt(counter.getAttribute('data-count'));
                const duration = 2000;
                const step = Math.max(1, Math.floor(target / 60));
                let current = 0;
                
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const interval = setInterval(() => {
                                current += step;
                                if (current >= target) {
                                    current = target;
                                    clearInterval(interval);
                                }
                                counter.textContent = current + (target > 100 ? '+' : '%');
                            }, duration / 60);
                            observer.disconnect();
                        }
                    });
                });
                
                observer.observe(counter);
            });
        }

        // ===== Smooth scroll para enlaces internos =====
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // ===== Inicializar =====
        document.addEventListener('DOMContentLoaded', () => {
            animateCounters();
        });
    </script>

</body>
</html>