<?php
session_start();
require_once '../includes/conexion.php';
require_once '../includes/auth.php';

// Verificar autenticación
if (!estaLogueado()) {
    header('Location: login.php');
    exit;
}

// Obtener usuario actual
$usuario = obtenerUsuarioActual($conn);
if (!$usuario) {
    cerrarSesion();
    header('Location: login.php');
    exit;
}

// ============================================
// FUNCIONES DE NOTIFICACIÓN (VACÍAS PARA FUTURA IMPLEMENTACIÓN)
// ============================================
function notificarCambioEtapa($propiedad_id, $etapa_anterior, $etapa_nueva, $usuario_id) {
    // TODO: Implementar con sistema de correos
    // Esta función se llamará cuando una propiedad cambie de etapa
    error_log("NOTIFICACIÓN: Propiedad $propiedad_id cambió de etapa $etapa_anterior a $etapa_nueva");
    return true;
}

function notificarDecisionManagement($propiedad_id, $decision, $comentarios, $usuario_id) {
    // TODO: Implementar notificación al captador sobre la decisión
    error_log("NOTIFICACIÓN: Propiedad $propiedad_id - Decisión: $decision - Comentarios: $comentarios");
    return true;
}

function notificarVisitaAgendada($propiedad_id, $fecha_visita, $gestor_id, $captador_id) {
    // TODO: Implementar notificación al captador sobre la visita
    error_log("NOTIFICACIÓN: Propiedad $propiedad_id - Visita agendada para $fecha_visita");
    return true;
}

// ============================================
// FUNCIÓN PARA EXPORTAR PDF
// ============================================
function generarPDFPropiedad($propiedad, $costos, $usuario) {
    // Verificar si existe la librería TCPDF
    $tcpdf_path = '../vendor/tecnickcom/tcpdf/tcpdf.php';
    if (!file_exists($tcpdf_path)) {
        // Si no existe TCPDF, usar HTML simple
        return generarHTMLReporte($propiedad, $costos, $usuario);
    }
    
    require_once($tcpdf_path);
    
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Inmobiliaria MH');
    $pdf->SetAuthor($usuario['name']);
    $pdf->SetTitle('Reporte Propiedad #' . $propiedad['id']);
    $pdf->SetSubject('Calificación de Propiedad');
    
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();
    
    // Logo o título
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->Cell(0, 10, 'INMOBILIARIA MH', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'Reporte de Calificación de Propiedad', 0, 1, 'C');
    $pdf->Ln(10);
    
    // Encabezado
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'Propiedad #' . $propiedad['id'], 0, 1);
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 6, 'Fecha: ' . date('d/m/Y H:i'), 0, 1);
    $pdf->Ln(5);
    
    // Datos de la propiedad
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'DATOS DE LA PROPIEDAD', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    
    $datos = [
        ['Dirección', $propiedad['direccion']],
        ['Ciudad', $propiedad['ciudad'] ?? 'N/A'],
        ['Colonia', $propiedad['colonia'] ?? 'N/A'],
        ['M² Terreno', $propiedad['m2_terreno'] ?? 'N/A'],
        ['M² Construcción', $propiedad['m2_construccion'] ?? 'N/A'],
        ['Antigüedad', ($propiedad['antiguedad'] ?? 'N/A') . ' años'],
        ['Recámaras', $propiedad['recamaras'] ?? 'N/A'],
        ['Baños', $propiedad['banos'] ?? 'N/A'],
        ['Estacionamientos', $propiedad['estacionamiento'] ?? 'N/A'],
    ];
    
    foreach ($datos as $dato) {
        $pdf->Cell(50, 6, $dato[0] . ':', 0, 0);
        $pdf->Cell(0, 6, $dato[1], 0, 1);
    }
    
    $pdf->Ln(5);
    
    // Análisis Financiero
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'ANÁLISIS FINANCIERO', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    
    $financiero = [
        ['Precio Pretendido', '$' . number_format($propiedad['precio_pretendido'], 2)],
        ['Valor Comercial Estimado', '$' . number_format($propiedad['precio_comercial'] ?? 0, 2)],
        ['Gastos Operativos (fijos)', '$' . number_format($propiedad['gastos_operativos'] ?? 0, 2)],
        ['Beneficio Requerido (fijo)', '$' . number_format($propiedad['ganancia_requerida'] ?? 0, 2)],
        ['Comisiones', '$' . number_format($propiedad['comisiones'] ?? 0, 2)],
    ];
    
    foreach ($financiero as $item) {
        $pdf->Cell(60, 6, $item[0] . ':', 0, 0);
        $pdf->Cell(0, 6, $item[1], 0, 1);
    }
    
    $costo_total = ($propiedad['precio_pretendido'] + 
                   ($propiedad['gastos_operativos'] ?? 0) + 
                   ($propiedad['ganancia_requerida'] ?? 0) + 
                   ($propiedad['comisiones'] ?? 0));
    $margen = ($propiedad['precio_comercial'] ?? 0) - $costo_total;
    
    $pdf->Ln(3);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(60, 6, 'Costo Total:', 0, 0);
    $pdf->Cell(0, 6, '$' . number_format($costo_total, 2), 0, 1);
    $pdf->Cell(60, 6, 'Margen:', 0, 0);
    $pdf->SetTextColor($margen > 0 ? 0 : 255, $margen > 0 ? 128 : 0, 0);
    $pdf->Cell(0, 6, '$' . number_format($margen, 2), 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    
    $pdf->Ln(5);
    
    // Indicadores
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'INDICADORES', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    
    $viabilidad = $propiedad['viabilidad'] ?? 'Sin evaluar';
    $color = $propiedad['indicador_color'] ?? 'pendiente';
    $color_texto = ['verde' => 'VERDE (Viable)', 'amarillo' => 'AMARILLO (Viable con condiciones)', 'rojo' => 'ROJO (No viable)'];
    $pdf->Cell(50, 6, 'Viabilidad:', 0, 0);
    $pdf->Cell(0, 6, $color_texto[$color] ?? ucfirst($viabilidad), 0, 1);
    
    $pdf->Cell(50, 6, 'Decisión Management:', 0, 0);
    $decision = $propiedad['go_no_go'] ?? 'Pendiente';
    $pdf->Cell(0, 6, ucfirst($decision), 0, 1);
    
    if (!empty($propiedad['comentarios_management'])) {
        $pdf->Ln(3);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'Comentarios Management:', 0, 1);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->MultiCell(0, 6, $propiedad['comentarios_management'], 0, 1);
    }
    
    $pdf->Ln(5);
    
    // Visita
    if (!empty($propiedad['fecha_visita'])) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'VISITA', 0, 1);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(50, 6, 'Fecha de Visita:', 0, 0);
        $pdf->Cell(0, 6, date('d/m/Y H:i', strtotime($propiedad['fecha_visita'])), 0, 1);
        
        if (!empty($propiedad['latitud']) && !empty($propiedad['longitud'])) {
            $pdf->Cell(50, 6, 'Ubicación:', 0, 0);
            $pdf->Cell(0, 6, $propiedad['latitud'] . ', ' . $propiedad['longitud'], 0, 1);
        }
        
        $fotos_pub = json_decode($propiedad['fotos_publicacion'] ?? '[]');
        $fotos_danos = json_decode($propiedad['fotos_danos'] ?? '[]');
        $pdf->Cell(50, 6, 'Fotos Publicación:', 0, 0);
        $pdf->Cell(0, 6, count($fotos_pub) . ' imágenes', 0, 1);
        $pdf->Cell(50, 6, 'Fotos Daños:', 0, 0);
        $pdf->Cell(0, 6, count($fotos_danos) . ' imágenes', 0, 1);
    }
    
    // Pie de página
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 6, 'Reporte generado por ' . $usuario['name'] . ' el ' . date('d/m/Y H:i'), 0, 1, 'C');
    
    // Generar el PDF
    $nombre_archivo = 'propiedad_' . $propiedad['id'] . '_' . date('Ymd_His') . '.pdf';
    $ruta = '../uploads/reportes/' . $nombre_archivo;
    
    // Crear directorio si no existe
    if (!is_dir('../uploads/reportes')) {
        mkdir('../uploads/reportes', 0777, true);
    }
    
    $pdf->Output($ruta, 'F');
    return $ruta;
}

function generarHTMLReporte($propiedad, $costos, $usuario) {
    // Fallback si TCPDF no está instalado
    $html = '<html><head><title>Reporte Propiedad</title>';
    $html .= '<style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        h1 { color: #1a1a2e; border-bottom: 3px solid #c9a84c; padding-bottom: 10px; }
        .datos { display: grid; grid-template-columns: 1fr 1fr; gap: 5px 30px; margin: 15px 0; }
        .etiqueta { font-weight: bold; color: #555; }
        .semaforo { display: inline-block; padding: 5px 15px; border-radius: 20px; font-weight: bold; }
        .verde { background: #d4edda; color: #155724; }
        .amarillo { background: #fff3cd; color: #856404; }
        .rojo { background: #f8d7da; color: #721c24; }
        .aprobado { background: #d4edda; color: #155724; }
        .rechazado { background: #f8d7da; color: #721c24; }
        .pendiente { background: #e2e3e5; color: #383d41; }
        .footer { margin-top: 30px; font-size: 12px; color: #888; border-top: 1px solid #ddd; padding-top: 15px; }
    </style></head><body>';
    
    $html .= '<h1>🏠 Reporte de Propiedad #' . $propiedad['id'] . '</h1>';
    $html .= '<p><strong>Fecha:</strong> ' . date('d/m/Y H:i') . '</p>';
    
    $html .= '<h2>Datos de la Propiedad</h2>';
    $html .= '<div class="datos">';
    $html .= '<div><span class="etiqueta">Dirección:</span> ' . htmlspecialchars($propiedad['direccion']) . '</div>';
    $html .= '<div><span class="etiqueta">Ciudad:</span> ' . htmlspecialchars($propiedad['ciudad'] ?? 'N/A') . '</div>';
    $html .= '<div><span class="etiqueta">Colonia:</span> ' . htmlspecialchars($propiedad['colonia'] ?? 'N/A') . '</div>';
    $html .= '<div><span class="etiqueta">M² Construcción:</span> ' . ($propiedad['m2_construccion'] ?? 'N/A') . '</div>';
    $html .= '<div><span class="etiqueta">Recámaras:</span> ' . ($propiedad['recamaras'] ?? 'N/A') . '</div>';
    $html .= '<div><span class="etiqueta">Baños:</span> ' . ($propiedad['banos'] ?? 'N/A') . '</div>';
    $html .= '</div>';
    
    $html .= '<h2>Análisis Financiero</h2>';
    $html .= '<div class="datos">';
    $html .= '<div><span class="etiqueta">Precio Pretendido:</span> $' . number_format($propiedad['precio_pretendido'], 2) . '</div>';
    $html .= '<div><span class="etiqueta">Valor Comercial:</span> $' . number_format($propiedad['precio_comercial'] ?? 0, 2) . '</div>';
    $html .= '<div><span class="etiqueta">Gastos Operativos (fijos):</span> $' . number_format($propiedad['gastos_operativos'] ?? 0, 2) . '</div>';
    $html .= '<div><span class="etiqueta">Beneficio Requerido (fijo):</span> $' . number_format($propiedad['ganancia_requerida'] ?? 0, 2) . '</div>';
    $html .= '<div><span class="etiqueta">Comisiones:</span> $' . number_format($propiedad['comisiones'] ?? 0, 2) . '</div>';
    $html .= '</div>';
    
    $costo_total = ($propiedad['precio_pretendido'] + ($propiedad['gastos_operativos'] ?? 0) + ($propiedad['ganancia_requerida'] ?? 0) + ($propiedad['comisiones'] ?? 0));
    $margen = ($propiedad['precio_comercial'] ?? 0) - $costo_total;
    
    $html .= '<p><strong>Costo Total:</strong> $' . number_format($costo_total, 2) . '</p>';
    $html .= '<p><strong>Margen:</strong> $' . number_format($margen, 2) . '</p>';
    
    $html .= '<h2>Indicadores</h2>';
    $color = $propiedad['indicador_color'] ?? 'pendiente';
    $html .= '<p><strong>Viabilidad:</strong> <span class="semaforo ' . $color . '">' . ucfirst($propiedad['viabilidad'] ?? 'Sin evaluar') . '</span></p>';
    $decision = $propiedad['go_no_go'] ?? 'pendiente';
    $html .= '<p><strong>Decisión Management:</strong> <span class="semaforo ' . $decision . '">' . ucfirst($decision) . '</span></p>';
    
    if (!empty($propiedad['comentarios_management'])) {
        $html .= '<p><strong>Comentarios Management:</strong><br>' . nl2br(htmlspecialchars($propiedad['comentarios_management'])) . '</p>';
    }
    
    if (!empty($propiedad['fecha_visita'])) {
        $html .= '<h2>Visita</h2>';
        $html .= '<p><strong>Fecha:</strong> ' . date('d/m/Y H:i', strtotime($propiedad['fecha_visita'])) . '</p>';
        if (!empty($propiedad['latitud']) && !empty($propiedad['longitud'])) {
            $html .= '<p><strong>Ubicación:</strong> ' . $propiedad['latitud'] . ', ' . $propiedad['longitud'] . '</p>';
        }
        $fotos_pub = json_decode($propiedad['fotos_publicacion'] ?? '[]');
        $fotos_danos = json_decode($propiedad['fotos_danos'] ?? '[]');
        $html .= '<p><strong>Fotos Publicación:</strong> ' . count($fotos_pub) . ' imágenes</p>';
        $html .= '<p><strong>Fotos Daños:</strong> ' . count($fotos_danos) . ' imágenes</p>';
    }
    
    $html .= '<div class="footer">Reporte generado por ' . htmlspecialchars($usuario['name']) . ' el ' . date('d/m/Y H:i') . '</div>';
    $html .= '</body></html>';
    
    // Guardar HTML como archivo
    $nombre_archivo = 'propiedad_' . $propiedad['id'] . '_' . date('Ymd_His') . '.html';
    $ruta = '../uploads/reportes/' . $nombre_archivo;
    
    if (!is_dir('../uploads/reportes')) {
        mkdir('../uploads/reportes', 0777, true);
    }
    
    file_put_contents($ruta, $html);
    return $ruta;
}

// ============================================
// OBTENER PARÁMETROS DE COSTOS (FIJOS MANUALES)
// ============================================
$stmt = $conn->prepare("SELECT * FROM parametros_costos WHERE id = 1");
$stmt->execute();
$costos = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$costos) {
    // Crear con valores por defecto
    $conn->exec("INSERT INTO parametros_costos (id, porcentaje_comision, porcentaje_ganancia, gastos_operativos_por_m2, factor_ajuste_comercial) 
                 VALUES (1, 3.00, 20.00, 50.00, 1.15)");
    $stmt = $conn->prepare("SELECT * FROM parametros_costos WHERE id = 1");
    $stmt->execute();
    $costos = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ============================================
// DETERMINAR ETAPA Y PROPIEDAD
// ============================================
$etapa = isset($_GET['etapa']) ? (int)$_GET['etapa'] : 1;
$propiedad_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$propiedad = null;
$es_edicion = false;

if ($propiedad_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM propiedades_calificacion WHERE id = ?");
    $stmt->execute([$propiedad_id]);
    $propiedad = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($propiedad) {
        $es_edicion = true;
        // Determinar etapa según el estado
        if ($propiedad['go_no_go'] == 'pendiente' && $propiedad['viabilidad'] != 'no_viable') {
            $etapa = 2; // Top Management
        } elseif ($propiedad['go_no_go'] == 'aprobado' && empty($propiedad['fecha_visita'])) {
            $etapa = 3; // Visita
        } elseif ($propiedad['go_no_go'] == 'aprobado' && !empty($propiedad['fecha_visita'])) {
            $etapa = 4; // Completado
        }
    }
}

// ============================================
// PROCESAR POST
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // ===== ETAPA 1: CAPTADOR =====
    if ($action === 'guardar_captura') {
        $errores = [];
        
        $direccion = trim($_POST['direccion'] ?? '');
        $precio_pretendido = floatval($_POST['precio_pretendido'] ?? 0);
        $m2_construccion = floatval($_POST['m2_construccion'] ?? 0);
        
        // Validaciones
        if (empty($direccion)) $errores[] = 'La dirección es obligatoria';
        if ($precio_pretendido <= 0) $errores[] = 'El precio pretendido debe ser mayor a 0';
        if ($m2_construccion <= 0) $errores[] = 'Los metros cuadrados deben ser mayores a 0';
        
        if (empty($errores)) {
            // GASTOS OPERATIVOS FIJOS (MANUALES)
            $gastos_operativos = floatval($_POST['gastos_operativos_fijos'] ?? $costos['gastos_operativos_por_m2'] ?? 0);
            
            // BENEFICIO REQUERIDO FIJO (MANUAL)
            $ganancia_requerida = floatval($_POST['beneficio_fijo'] ?? ($precio_pretendido * (($costos['porcentaje_ganancia'] ?? 20) / 100)));
            
            // COMISIONES (calculadas automáticamente)
            $comisiones = $precio_pretendido * (($costos['porcentaje_comision'] ?? 3) / 100);
            
            // VALOR COMERCIAL (calculado automáticamente)
            $precio_comercial = $precio_pretendido * ($costos['factor_ajuste_comercial'] ?? 1.15);
            
            // Determinar viabilidad (semáforo)
            $costo_total = $precio_pretendido + $gastos_operativos + $ganancia_requerida + $comisiones;
            $margen = $precio_comercial - $costo_total;
            
            if ($margen > $precio_comercial * 0.15) {
                $indicador_color = 'verde';
                $viabilidad = 'viable';
            } elseif ($margen > 0) {
                $indicador_color = 'amarillo';
                $viabilidad = 'viable_condicionado';
            } else {
                $indicador_color = 'rojo';
                $viabilidad = 'no_viable';
            }
            
            if ($propiedad_id > 0) {
                // Actualizar
                $sql = "UPDATE propiedades_calificacion SET 
                    direccion = ?, ciudad = ?, colonia = ?, m2_terreno = ?, m2_construccion = ?,
                    antiguedad = ?, recamaras = ?, banos = ?, estacionamiento = ?,
                    precio_pretendido = ?, precio_comercial = ?,
                    gastos_operativos = ?, ganancia_requerida = ?, comisiones = ?,
                    viabilidad = ?, indicador_color = ?,
                    actualizado_por = ?, fecha_actualizacion = NOW()
                    WHERE id = ?";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    $direccion,
                    $_POST['ciudad'] ?? '',
                    $_POST['colonia'] ?? '',
                    floatval($_POST['m2_terreno'] ?? 0),
                    $m2_construccion,
                    intval($_POST['antiguedad'] ?? 0),
                    intval($_POST['recamaras'] ?? 0),
                    intval($_POST['banos'] ?? 0),
                    intval($_POST['estacionamiento'] ?? 0),
                    $precio_pretendido,
                    $precio_comercial,
                    $gastos_operativos,
                    $ganancia_requerida,
                    $comisiones,
                    $viabilidad,
                    $indicador_color,
                    $usuario['id'],
                    $propiedad_id
                ]);
                
                $_SESSION['mensaje'] = '✅ Propiedad actualizada correctamente';
            } else {
                // Insertar nueva
                $sql = "INSERT INTO propiedades_calificacion (
                    direccion, ciudad, colonia, m2_terreno, m2_construccion,
                    antiguedad, recamaras, banos, estacionamiento,
                    precio_pretendido, precio_comercial,
                    gastos_operativos, ganancia_requerida, comisiones,
                    viabilidad, indicador_color,
                    creado_por, fecha_creacion
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    $direccion,
                    $_POST['ciudad'] ?? '',
                    $_POST['colonia'] ?? '',
                    floatval($_POST['m2_terreno'] ?? 0),
                    $m2_construccion,
                    intval($_POST['antiguedad'] ?? 0),
                    intval($_POST['recamaras'] ?? 0),
                    intval($_POST['banos'] ?? 0),
                    intval($_POST['estacionamiento'] ?? 0),
                    $precio_pretendido,
                    $precio_comercial,
                    $gastos_operativos,
                    $ganancia_requerida,
                    $comisiones,
                    $viabilidad,
                    $indicador_color,
                    $usuario['id']
                ]);
                
                $propiedad_id = $conn->lastInsertId();
                
                // Notificar al captador que se registró la propiedad
                notificarCambioEtapa($propiedad_id, 'creacion', 'captura', $usuario['id']);
                
                $_SESSION['mensaje'] = '✅ Propiedad registrada correctamente';
            }
            
            header("Location: calificador_propiedad.php?id=" . $propiedad_id . "&etapa=2");
            exit;
        } else {
            $_SESSION['errores'] = $errores;
        }
    }
    
    // ===== ETAPA 2: TOP MANAGEMENT - GO/NO-GO =====
    if ($action === 'go_no_go') {
        $decision = $_POST['decision'] ?? '';
        $comentarios = trim($_POST['comentarios_management'] ?? '');
        
        if (in_array($decision, ['aprobado', 'rechazado'])) {
            $sql = "UPDATE propiedades_calificacion SET 
                go_no_go = ?,
                comentarios_management = ?,
                usuario_decision_id = ?,
                fecha_decision = NOW()
                WHERE id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$decision, $comentarios, $usuario['id'], $propiedad_id]);
            
            // Notificar al captador sobre la decisión
            notificarDecisionManagement($propiedad_id, $decision, $comentarios, $usuario['id']);
            
            $_SESSION['mensaje'] = $decision == 'aprobado' ? 
                '✅ Propiedad aprobada. Ahora se puede agendar visita.' : 
                '❌ Propiedad rechazada. Se ha notificado al captador.';
            
            // Si es rechazada, notificar también
            if ($decision == 'rechazado') {
                notificarCambioEtapa($propiedad_id, 'management', 'rechazado', $usuario['id']);
            }
            
            header("Location: calificador_propiedad.php?listado=1");
            exit;
        }
    }
    
    // ===== ETAPA 3: GESTOR DE VISITAS =====
    if ($action === 'guardar_visita') {
        $fecha_visita = $_POST['fecha_visita'] ?? '';
        $latitud = floatval($_POST['latitud'] ?? 0);
        $longitud = floatval($_POST['longitud'] ?? 0);
        
        // Procesar fotos de publicación
        $fotos_publicacion = [];
        if (isset($_FILES['fotos_publicacion'])) {
            foreach ($_FILES['fotos_publicacion']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['fotos_publicacion']['error'][$key] == 0) {
                    $nombre = 'pub_' . time() . '_' . $key . '.jpg';
                    $ruta = 'uploads/visitas/' . $nombre;
                    if (!is_dir('uploads/visitas')) mkdir('uploads/visitas', 0777, true);
                    if (move_uploaded_file($tmp_name, $ruta)) {
                        $fotos_publicacion[] = $ruta;
                    }
                }
            }
        }
        
        // Procesar fotos de daños
        $fotos_danos = [];
        if (isset($_FILES['fotos_danos'])) {
            foreach ($_FILES['fotos_danos']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['fotos_danos']['error'][$key] == 0) {
                    $nombre = 'dano_' . time() . '_' . $key . '.jpg';
                    $ruta = 'uploads/visitas/' . $nombre;
                    if (!is_dir('uploads/visitas')) mkdir('uploads/visitas', 0777, true);
                    if (move_uploaded_file($tmp_name, $ruta)) {
                        $fotos_danos[] = $ruta;
                    }
                }
            }
        }
        
        $sql = "UPDATE propiedades_calificacion SET 
            fecha_visita = ?,
            gestor_asignado = ?,
            fotos_publicacion = ?,
            fotos_danos = ?,
            latitud = ?,
            longitud = ?
            WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $fecha_visita,
            $usuario['id'],
            json_encode($fotos_publicacion),
            json_encode($fotos_danos),
            $latitud,
            $longitud,
            $propiedad_id
        ]);
        
        // Notificar al captador sobre la visita agendada
        $stmt_captador = $conn->prepare("SELECT creado_por FROM propiedades_calificacion WHERE id = ?");
        $stmt_captador->execute([$propiedad_id]);
        $captador = $stmt_captador->fetch();
        if ($captador) {
            notificarVisitaAgendada($propiedad_id, $fecha_visita, $usuario['id'], $captador['creado_por']);
        }
        
        $_SESSION['mensaje'] = '✅ Visita registrada exitosamente';
        header("Location: calificador_propiedad.php?listado=1");
        exit;
    }
    
    // ===== EXPORTAR PDF =====
    if ($action === 'exportar_pdf') {
        if ($propiedad_id > 0 && $propiedad) {
            $ruta_pdf = generarPDFPropiedad($propiedad, $costos, $usuario);
            if (file_exists($ruta_pdf)) {
                $_SESSION['mensaje'] = '✅ Reporte PDF generado correctamente: <a href="' . $ruta_pdf . '" target="_blank">Descargar PDF</a>';
            } else {
                $_SESSION['errores'] = ['Error al generar el PDF'];
            }
        }
        header("Location: calificador_propiedad.php?id=" . $propiedad_id);
        exit;
    }
}

// ============================================
// OBTENER LISTADO
// ============================================
$propiedades_listado = [];
if (isset($_GET['listado']) || isset($_GET['listado_todos'])) {
    $sql = "SELECT p.*, u.name as captador_nombre, u2.name as gestor_nombre
            FROM propiedades_calificacion p
            LEFT JOIN users u ON p.creado_por = u.id
            LEFT JOIN users u2 ON p.gestor_asignado = u2.id
            WHERE p.status = 1
            ORDER BY p.fecha_creacion DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $propiedades_listado = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calificador de Propiedades</title>
    <link rel="stylesheet" href="css/socios.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        .calificador-container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .etapa-header {
            background: linear-gradient(135deg, #1a1a2e, #2a2a4e);
            color: white;
            padding: 20px 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .etapa-header h2 { margin: 0; font-size: 22px; }
        .etapa-header .badge-etapa {
            background: #c9a84c;
            color: #1a1a2e;
            padding: 6px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 13px;
        }
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px 25px;
        }
        .form-grid .full-width { grid-column: 1 / -1; }
        .form-group { margin-bottom: 15px; }
        .form-group label {
            display: block;
            font-weight: 500;
            font-size: 13px;
            color: #555;
            margin-bottom: 4px;
        }
        .form-group label .required { color: #dc3545; }
        .form-group label .info {
            color: #888;
            font-weight: normal;
            font-size: 12px;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: #c9a84c;
            outline: none;
            box-shadow: 0 0 0 3px rgba(201,168,76,0.1);
        }
        .form-group textarea { min-height: 60px; resize: vertical; }
        .btn {
            padding: 8px 20px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .btn-primary { background: #c9a84c; color: white; }
        .btn-primary:hover { background: #b8963a; }
        .btn-success { background: #28a745; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-info { background: #17a2b8; color: white; }
        .btn-outline {
            background: transparent;
            border: 2px solid #c9a84c;
            color: #c9a84c;
        }
        .btn-outline:hover { background: #c9a84c; color: white; }
        .btn-sm { padding: 5px 12px; font-size: 12px; }
        
        /* SEMÁFORO */
        .semaforo-container {
            display: flex;
            gap: 20px;
            padding: 20px;
            background: #f8f6f0;
            border-radius: 10px;
            margin: 15px 0;
            flex-wrap: wrap;
        }
        .semaforo-indicador {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 18px;
            border-radius: 8px;
            background: white;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
        }
        .semaforo-indicador .luz {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            border: 2px solid #ddd;
            flex-shrink: 0;
        }
        .semaforo-indicador .luz.verde { background: #28a745; border-color: #28a745; }
        .semaforo-indicador .luz.amarillo { background: #ffc107; border-color: #ffc107; }
        .semaforo-indicador .luz.rojo { background: #dc3545; border-color: #dc3545; }
        .semaforo-indicador .luz.pendiente { background: #e2e3e5; border-color: #6c757d; }
        .semaforo-info {
            flex: 1;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
        }
        .semaforo-info .item {
            background: white;
            padding: 10px 15px;
            border-radius: 6px;
            text-align: center;
        }
        .semaforo-info .item .label { font-size: 11px; color: #888; display: block; }
        .semaforo-info .item .value {
            font-size: 18px;
            font-weight: 700;
            color: #1a1a2e;
        }
        .semaforo-info .item .value.positivo { color: #28a745; }
        .semaforo-info .item .value.negativo { color: #dc3545; }
        
        /* GO/NO-GO */
        .go-nogo-container {
            display: flex;
            gap: 15px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        .go-nogo-container .btn { flex: 1; justify-content: center; padding: 15px; font-size: 16px; min-width: 150px; }
        
        /* MAPA */
        #map {
            height: 400px;
            border-radius: 8px;
            border: 2px solid #ddd;
            margin-top: 10px;
        }
        .map-controls {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        .map-controls input {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            min-width: 120px;
        }
        
        /* FOTOS UPLOAD */
        .fotos-upload-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .fotos-upload-box {
            border: 2px dashed #ddd;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .fotos-upload-box:hover { border-color: #c9a84c; background: #f8f6f0; }
        .fotos-upload-box .icon { font-size: 32px; color: #c9a84c; }
        .fotos-upload-box .file-list {
            margin-top: 10px;
            font-size: 12px;
            color: #666;
            text-align: left;
        }
        .fotos-upload-box .file-list .file-item {
            padding: 3px 8px;
            background: #f0f0f0;
            border-radius: 4px;
            margin: 2px 0;
            display: flex;
            justify-content: space-between;
        }
        
        /* TABLA LISTADO */
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        table th {
            background: #f8f6f0;
            padding: 10px 15px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            color: #555;
            border-bottom: 2px solid #e8e8e8;
        }
        table td { padding: 10px 15px; border-bottom: 1px solid #eee; font-size: 14px; }
        table tr:hover { background: #fafaf8; }
        .estado-badge {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .estado-badge.verde { background: #d4edda; color: #155724; }
        .estado-badge.amarillo { background: #fff3cd; color: #856404; }
        .estado-badge.rojo { background: #f8d7da; color: #721c24; }
        .estado-badge.aprobado { background: #d4edda; color: #155724; }
        .estado-badge.rechazado { background: #f8d7da; color: #721c24; }
        .estado-badge.pendiente { background: #e2e3e5; color: #383d41; }
        .estado-badge.completado { background: #cce5ff; color: #004085; }
        
        .acciones-btns { display: flex; gap: 5px; flex-wrap: wrap; }
        .acciones-btns .btn { padding: 3px 8px; font-size: 11px; }
        
        .valor-fijo {
            background: #f0f0f0;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            color: #666;
        }
        
        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
            .semaforo-container { flex-direction: column; }
            .semaforo-info { grid-template-columns: 1fr 1fr; }
            .go-nogo-container { flex-direction: column; }
            .fotos-upload-grid { grid-template-columns: 1fr; }
            .etapa-header { flex-direction: column; text-align: center; gap: 10px; }
        }
    </style>
</head>
<body>

<?php include 'modulos/sidebar.php'; ?>

<main class="main-content">
    <div class="main-header">
        <div class="header-left">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <h1>🏠 Calificador de Propiedades</h1>
        </div>
        <div class="header-actions">
            <a href="?listado=1" class="btn-header secondary">
                <i class="fas fa-list"></i> Ver todas
            </a>
            <a href="?nueva=1" class="btn-header primary">
                <i class="fas fa-plus"></i> Nueva propiedad
            </a>
            <?php if ($propiedad_id > 0): ?>
                <button onclick="document.getElementById('exportPdfForm').submit();" class="btn-header primary" style="background: #dc3545;">
                    <i class="fas fa-file-pdf"></i> Exportar PDF
                </button>
                <form id="exportPdfForm" method="POST" style="display:none;">
                    <input type="hidden" name="action" value="exportar_pdf">
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="calificador-container">
        
        <?php if (isset($_SESSION['mensaje'])): ?>
            <div style="background: #d4edda; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #28a745;">
                <p style="margin: 0; color: #155724;"><?php echo $_SESSION['mensaje']; unset($_SESSION['mensaje']); ?></p>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['errores'])): ?>
            <div style="background: #f8d7da; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #dc3545;">
                <ul style="margin: 0; padding-left: 20px; color: #721c24;">
                    <?php foreach ($_SESSION['errores'] as $error): ?>
                        <li><?php echo $error; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php unset($_SESSION['errores']); ?>
        <?php endif; ?>

        <?php if (isset($_GET['listado']) || isset($_GET['listado_todos'])): ?>
            <!-- ===== LISTADO DE PROPIEDADES ===== -->
            <div class="card">
                <div class="card-title">
                    <i class="fas fa-list"></i> Listado de Propiedades
                    <span style="font-size: 13px; font-weight: normal; color: #888; margin-left: 10px;">
                        <?php echo count($propiedades_listado); ?> registros
                    </span>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Dirección</th>
                                <th>Precio</th>
                                <th>Viabilidad</th>
                                <th>Decisión</th>
                                <th>Captador</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($propiedades_listado)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; color: #888; padding: 30px;">
                                        <i class="fas fa-inbox" style="font-size: 24px; display: block; margin-bottom: 10px;"></i>
                                        No hay propiedades registradas
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($propiedades_listado as $p): ?>
                                    <tr>
                                        <td>#<?php echo $p['id']; ?></td>
                                        <td>
                                            <?php echo htmlspecialchars(substr($p['direccion'], 0, 35)); ?>
                                            <?php if (strlen($p['direccion']) > 35) echo '...'; ?>
                                        </td>
                                        <td>$<?php echo number_format($p['precio_pretendido'], 2); ?></td>
                                        <td>
                                            <span class="estado-badge <?php echo $p['indicador_color'] ?? 'pendiente'; ?>">
                                                <?php echo ucfirst($p['viabilidad'] ?? 'Sin evaluar'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="estado-badge <?php echo $p['go_no_go'] ?? 'pendiente'; ?>">
                                                <?php echo ucfirst($p['go_no_go'] ?? 'Pendiente'); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($p['captador_nombre'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if (!empty($p['fecha_visita'])): ?>
                                                <span class="estado-badge completado">✓ Visita</span>
                                            <?php elseif ($p['go_no_go'] == 'aprobado'): ?>
                                                <span class="estado-badge aprobado">Pendiente Visita</span>
                                            <?php elseif ($p['go_no_go'] == 'rechazado'): ?>
                                                <span class="estado-badge rechazado">Rechazado</span>
                                            <?php elseif ($p['go_no_go'] == 'pendiente'): ?>
                                                <span class="estado-badge pendiente">En evaluación</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="acciones-btns">
                                                <a href="?id=<?php echo $p['id']; ?>" class="btn btn-primary btn-sm">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <?php if (empty($p['fecha_visita']) && $p['go_no_go'] == 'aprobado'): ?>
                                                    <a href="?id=<?php echo $p['id']; ?>&etapa=3" class="btn btn-info btn-sm">
                                                        <i class="fas fa-calendar-plus"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
                    <a href="?nueva=1" class="btn btn-primary"><i class="fas fa-plus"></i> Nueva propiedad</a>
                </div>
            </div>
            
        <?php elseif (isset($_GET['nueva']) || ($propiedad_id > 0 && ($etapa == 1 || $propiedad['go_no_go'] == 'pendiente' && empty($propiedad['fecha_visita'])))): ?>
            <!-- ===== ETAPA 1: CAPTADOR ===== -->
            <div class="etapa-header">
                <div>
                    <h2><i class="fas fa-pen"></i> Etapa 1: Captura de Datos</h2>
                    <small style="opacity:0.8;">Completa la información de la propiedad</small>
                </div>
                <span class="badge-etapa">Captador: <?php echo htmlspecialchars($usuario['name']); ?></span>
            </div>
            
            <form method="POST" class="card">
                <input type="hidden" name="action" value="guardar_captura">
                
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label for="direccion">Dirección completa <span class="required">*</span></label>
                        <input type="text" id="direccion" name="direccion" value="<?php echo htmlspecialchars($propiedad['direccion'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="ciudad">Ciudad</label>
                        <input type="text" id="ciudad" name="ciudad" value="<?php echo htmlspecialchars($propiedad['ciudad'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="colonia">Colonia / Fraccionamiento</label>
                        <input type="text" id="colonia" name="colonia" value="<?php echo htmlspecialchars($propiedad['colonia'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="m2_terreno">M² de Terreno</label>
                        <input type="number" id="m2_terreno" name="m2_terreno" value="<?php echo htmlspecialchars($propiedad['m2_terreno'] ?? ''); ?>" step="0.01">
                    </div>
                    
                    <div class="form-group">
                        <label for="m2_construccion">M² de Construcción <span class="required">*</span></label>
                        <input type="number" id="m2_construccion" name="m2_construccion" value="<?php echo htmlspecialchars($propiedad['m2_construccion'] ?? ''); ?>" step="0.01" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="antiguedad">Antigüedad (años)</label>
                        <input type="number" id="antiguedad" name="antiguedad" value="<?php echo htmlspecialchars($propiedad['antiguedad'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="recamaras">Recámaras</label>
                        <input type="number" id="recamaras" name="recamaras" value="<?php echo htmlspecialchars($propiedad['recamaras'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="banos">Baños</label>
                        <input type="number" id="banos" name="banos" value="<?php echo htmlspecialchars($propiedad['banos'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="estacionamiento">Estacionamientos</label>
                        <input type="number" id="estacionamiento" name="estacionamiento" value="<?php echo htmlspecialchars($propiedad['estacionamiento'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="precio_pretendido">Precio pretendido (USD) <span class="required">*</span></label>
                        <input type="number" id="precio_pretendido" name="precio_pretendido" value="<?php echo htmlspecialchars($propiedad['precio_pretendido'] ?? ''); ?>" step="0.01" required>
                    </div>
                    
                    <!-- CAMPOS FIJOS MANUALES -->
                    <div class="form-group">
                        <label for="gastos_operativos_fijos">
                            Gastos Operativos (fijos) <span class="info">(ingreso manual)</span>
                        </label>
                        <input type="number" id="gastos_operativos_fijos" name="gastos_operativos_fijos" 
                               value="<?php echo htmlspecialchars($propiedad['gastos_operativos'] ?? $costos['gastos_operativos_por_m2'] ?? ''); ?>" 
                               step="0.01">
                    </div>
                    
                    <div class="form-group">
                        <label for="beneficio_fijo">
                            Beneficio Requerido (fijo) <span class="info">(ingreso manual)</span>
                        </label>
                        <input type="number" id="beneficio_fijo" name="beneficio_fijo" 
                               value="<?php echo htmlspecialchars($propiedad['ganancia_requerida'] ?? ($propiedad['precio_pretendido'] * ($costos['porcentaje_ganancia'] / 100)) ?? ''); ?>" 
                               step="0.01">
                    </div>
                </div>
                
                <?php if ($propiedad_id > 0 && !empty($propiedad)): ?>
                    <!-- Mostrar semáforo si ya existe -->
                    <div class="semaforo-container">
                        <div class="semaforo-indicador">
                            <div class="luz <?php echo $propiedad['indicador_color'] ?? 'pendiente'; ?>"></div>
                            <div>
                                <strong><?php echo ucfirst($propiedad['viabilidad'] ?? 'Sin evaluar'); ?></strong>
                                <br><small style="color:#888;">Viabilidad</small>
                            </div>
                        </div>
                        <div class="semaforo-info">
                            <div class="item">
                                <span class="label">Valor Comercial</span>
                                <span class="value">$<?php echo number_format($propiedad['precio_comercial'] ?? 0, 2); ?></span>
                            </div>
                            <div class="item">
                                <span class="label">Gastos Operativos (fijos)</span>
                                <span class="value negativo">$<?php echo number_format($propiedad['gastos_operativos'] ?? 0, 2); ?></span>
                            </div>
                            <div class="item">
                                <span class="label">Beneficio Requerido (fijo)</span>
                                <span class="value negativo">$<?php echo number_format($propiedad['ganancia_requerida'] ?? 0, 2); ?></span>
                            </div>
                            <div class="item">
                                <span class="label">Comisiones</span>
                                <span class="value negativo">$<?php echo number_format($propiedad['comisiones'] ?? 0, 2); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <?php 
                    $costo_total = ($propiedad['precio_pretendido'] + 
                                   ($propiedad['gastos_operativos'] ?? 0) + 
                                   ($propiedad['ganancia_requerida'] ?? 0) + 
                                   ($propiedad['comisiones'] ?? 0));
                    $margen = ($propiedad['precio_comercial'] ?? 0) - $costo_total;
                    ?>
                    <div style="background: #f8f6f0; padding: 12px 18px; border-radius: 8px; margin: 10px 0;">
                        <strong>Resumen:</strong>
                        <span style="margin-left: 15px;">Costo Total: <strong>$<?php echo number_format($costo_total, 2); ?></strong></span>
                        <span style="margin-left: 15px;">Margen: <strong style="color: <?php echo $margen > 0 ? '#28a745' : '#dc3545'; ?>">$<?php echo number_format($margen, 2); ?></strong></span>
                        <span style="margin-left: 15px; font-size: 12px; color: #888;">
                            <i class="fas fa-info-circle"></i> Gastos y beneficio son fijos (ingreso manual)
                        </span>
                    </div>
                <?php endif; ?>
                
                <div style="display: flex; gap: 10px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?php echo $propiedad_id > 0 ? 'Actualizar' : 'Guardar y continuar'; ?>
                    </button>
                    <?php if ($propiedad_id > 0): ?>
                        <a href="?id=<?php echo $propiedad_id; ?>&etapa=2" class="btn btn-success">
                            <i class="fas fa-arrow-right"></i> Ir a validación
                        </a>
                    <?php endif; ?>
                    <a href="?listado=1" class="btn btn-secondary">
                        <i class="fas fa-list"></i> Ver listado
                    </a>
                </div>
            </form>
            
        <?php elseif ($propiedad_id > 0 && $etapa == 2 && $propiedad['go_no_go'] == 'pendiente'): ?>
            <!-- ===== ETAPA 2: TOP MANAGEMENT - GO/NO-GO ===== -->
            <div class="etapa-header">
                <div>
                    <h2><i class="fas fa-gavel"></i> Etapa 2: Decisión Go/No-Go</h2>
                    <small style="opacity:0.8;">Revisa los indicadores y decide si continuar</small>
                </div>
                <span class="badge-etapa">Management: <?php echo htmlspecialchars($usuario['name']); ?></span>
            </div>
            
            <div class="card">
                <div class="card-title">
                    <i class="fas fa-building"></i> Propiedad #<?php echo $propiedad['id']; ?>
                    <span style="font-size: 13px; font-weight: normal; color: #888; margin-left: 10px;">
                        Capturada por: <?php echo htmlspecialchars($propiedad['captador_nombre'] ?? 'N/A'); ?>
                    </span>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                    <div><strong>Dirección:</strong> <?php echo htmlspecialchars($propiedad['direccion']); ?></div>
                    <div><strong>Ciudad:</strong> <?php echo htmlspecialchars($propiedad['ciudad'] ?? 'N/A'); ?></div>
                    <div><strong>M² Construcción:</strong> <?php echo $propiedad['m2_construccion']; ?></div>
                    <div><strong>Precio pretendido:</strong> $<?php echo number_format($propiedad['precio_pretendido'], 2); ?></div>
                </div>
                
                <!-- Semáforo detallado -->
                <div class="semaforo-container">
                    <div class="semaforo-indicador">
                        <div class="luz <?php echo $propiedad['indicador_color'] ?? 'pendiente'; ?>"></div>
                        <div>
                            <strong><?php echo ucfirst($propiedad['viabilidad'] ?? 'Sin evaluar'); ?></strong>
                            <br><small style="color:#888;">Viabilidad calculada</small>
                        </div>
                    </div>
                    <div class="semaforo-info">
                        <div class="item">
                            <span class="label">Valor Comercial</span>
                            <span class="value">$<?php echo number_format($propiedad['precio_comercial'] ?? 0, 2); ?></span>
                        </div>
                        <div class="item">
                            <span class="label">Gastos Operativos (fijos)</span>
                            <span class="value negativo">$<?php echo number_format($propiedad['gastos_operativos'] ?? 0, 2); ?></span>
                        </div>
                        <div class="item">
                            <span class="label">Beneficio Requerido (fijo)</span>
                            <span class="value negativo">$<?php echo number_format($propiedad['ganancia_requerida'] ?? 0, 2); ?></span>
                        </div>
                        <div class="item">
                            <span class="label">Comisiones</span>
                            <span class="value negativo">$<?php echo number_format($propiedad['comisiones'] ?? 0, 2); ?></span>
                        </div>
                    </div>
                </div>
                
                <?php 
                $costo_total = ($propiedad['precio_pretendido'] + 
                               ($propiedad['gastos_operativos'] ?? 0) + 
                               ($propiedad['ganancia_requerida'] ?? 0) + 
                               ($propiedad['comisiones'] ?? 0));
                $margen = ($propiedad['precio_comercial'] ?? 0) - $costo_total;
                ?>
                <div style="background: #f8f6f0; padding: 15px; border-radius: 8px; margin: 15px 0;">
                    <strong>Resumen financiero:</strong>
                    <div style="display: flex; gap: 30px; flex-wrap: wrap; margin-top: 8px;">
                        <span>Costo total estimado: <strong>$<?php echo number_format($costo_total, 2); ?></strong></span>
                        <span>Margen estimado: <strong style="color: <?php echo $margen > 0 ? '#28a745' : '#dc3545'; ?>">
                            $<?php echo number_format($margen, 2); ?>
                        </strong></span>
                        <span style="font-size: 12px; color: #888;">
                            <i class="fas fa-info-circle"></i> Gastos operativos y beneficio son fijos
                        </span>
                    </div>
                </div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="go_no_go">
                    
                    <div class="form-group">
                        <label for="comentarios_management">Comentarios / Observaciones</label>
                        <textarea id="comentarios_management" name="comentarios_management" placeholder="Escribe tus comentarios sobre la viabilidad de esta propiedad" rows="3"><?php echo htmlspecialchars($propiedad['comentarios_management'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="go-nogo-container">
                        <button type="submit" name="decision" value="aprobado" class="btn btn-success" onclick="return confirm('¿Confirmas APROBAR esta propiedad?');">
                            <i class="fas fa-check-circle"></i> ✅ APROBAR (Go)
                        </button>
                        <button type="submit" name="decision" value="rechazado" class="btn btn-danger" onclick="return confirm('¿Confirmas RECHAZAR esta propiedad?');">
                            <i class="fas fa-times-circle"></i> ❌ RECHAZAR (No-Go)
                        </button>
                        <a href="?id=<?php echo $propiedad_id; ?>&etapa=1" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Volver a editar
                        </a>
                    </div>
                </form>
            </div>
            
        <?php elseif ($propiedad_id > 0 && $etapa == 3 && $propiedad['go_no_go'] == 'aprobado'): ?>
            <!-- ===== ETAPA 3: GESTOR DE VISITAS ===== -->
            <div class="etapa-header">
                <div>
                    <h2><i class="fas fa-camera"></i> Etapa 3: Visita y Evidencias</h2>
                    <small style="opacity:0.8;">Registra la visita y sube las evidencias</small>
                </div>
                <span class="badge-etapa">Gestor: <?php echo htmlspecialchars($usuario['name']); ?></span>
            </div>
            
            <div class="card">
                <div class="card-title">
                    <i class="fas fa-home"></i> Agendar visita para Propiedad #<?php echo $propiedad['id']; ?>
                </div>
                
                <div style="background: #d4edda; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #28a745;">
                    <p style="margin: 0; color: #155724;">
                        <i class="fas fa-check-circle"></i> Esta propiedad fue <strong>APROBADA</strong> por el Top Management. 
                        Procede a agendar la visita.
                    </p>
                </div>
                
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="guardar_visita">
                    
                    <div class="form-group">
                        <label for="fecha_visita">Fecha de la Visita <span class="required">*</span></label>
                        <input type="datetime-local" id="fecha_visita" name="fecha_visita" required>
                    </div>
                    
                    <div style="margin: 20px 0;">
                        <label><strong>Ubicación en Mapa</strong></label>
                        <div id="map"></div>
                        <div class="map-controls">
                            <input type="text" id="latitud" name="latitud" placeholder="Latitud" value="<?php echo $propiedad['latitud'] ?? ''; ?>">
                            <input type="text" id="longitud" name="longitud" placeholder="Longitud" value="<?php echo $propiedad['longitud'] ?? ''; ?>">
                            <button type="button" class="btn btn-primary btn-sm" onclick="obtenerUbicacion()">
                                <i class="fas fa-location-dot"></i> Mi ubicación
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="limpiarMapa()">
                                <i class="fas fa-eraser"></i> Limpiar
                            </button>
                        </div>
                    </div>
                    
                    <div class="fotos-upload-grid">
                        <div>
                            <label><strong>Fotos para publicación</strong></label>
                            <div class="fotos-upload-box" onclick="document.getElementById('fotosPub').click()">
                                <div class="icon"><i class="fas fa-camera"></i></div>
                                <p>Haz clic para subir fotos de la propiedad</p>
                                <small style="color:#888;">(Máximo 10 fotos)</small>
                                <input type="file" id="fotosPub" name="fotos_publicacion[]" multiple accept="image/*" style="display:none;" onchange="updateFileList(this, 'fotosPubList')">
                                <div id="fotosPubList" class="file-list"></div>
                            </div>
                        </div>
                        <div>
                            <label><strong>Evidencias de daños</strong></label>
                            <div class="fotos-upload-box" onclick="document.getElementById('fotosDanos').click()">
                                <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
                                <p>Haz clic para subir fotos de daños o problemas</p>
                                <small style="color:#888;">(Máximo 10 fotos)</small>
                                <input type="file" id="fotosDanos" name="fotos_danos[]" multiple accept="image/*" style="display:none;" onchange="updateFileList(this, 'fotosDanosList')">
                                <div id="fotosDanosList" class="file-list"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 25px; padding-top: 20px; border-top: 1px solid #eee; flex-wrap: wrap;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Registrar visita
                        </button>
                        <a href="?listado=1" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                    </div>
                </form>
            </div>
            
        <?php elseif ($propiedad_id > 0 && !empty($propiedad['fecha_visita'])): ?>
            <!-- ===== PROPIEDAD COMPLETADA ===== -->
            <div class="etapa-header">
                <div>
                    <h2><i class="fas fa-check-circle" style="color: #28a745;"></i> Propiedad Completada</h2>
                    <small style="opacity:0.8;">Todos los procesos han sido finalizados</small>
                </div>
                <span class="badge-etapa" style="background: #28a745; color: white;">✓ Completado</span>
            </div>
            
            <div class="card">
                <div class="card-title">
                    <i class="fas fa-home"></i> Propiedad #<?php echo $propiedad['id']; ?>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                    <div><strong>Dirección:</strong> <?php echo htmlspecialchars($propiedad['direccion']); ?></div>
                    <div><strong>Ciudad:</strong> <?php echo htmlspecialchars($propiedad['ciudad'] ?? 'N/A'); ?></div>
                    <div><strong>M² Construcción:</strong> <?php echo $propiedad['m2_construccion']; ?></div>
                    <div><strong>Precio:</strong> $<?php echo number_format($propiedad['precio_pretendido'], 2); ?></div>
                    <div><strong>Fecha visita:</strong> <?php echo date('d/m/Y H:i', strtotime($propiedad['fecha_visita'])); ?></div>
                    <div><strong>Gestor:</strong> <?php echo htmlspecialchars($propiedad['gestor_nombre'] ?? 'N/A'); ?></div>
                </div>
                
                <div class="semaforo-container">
                    <div class="semaforo-indicador">
                        <div class="luz <?php echo $propiedad['indicador_color'] ?? 'pendiente'; ?>"></div>
                        <div>
                            <strong><?php echo ucfirst($propiedad['viabilidad'] ?? 'Sin evaluar'); ?></strong>
                            <br><small style="color:#888;">Viabilidad</small>
                        </div>
                    </div>
                    <div class="semaforo-info">
                        <div class="item">
                            <span class="label">Valor Comercial</span>
                            <span class="value">$<?php echo number_format($propiedad['precio_comercial'] ?? 0, 2); ?></span>
                        </div>
                        <div class="item">
                            <span class="label">Gastos Operativos</span>
                            <span class="value negativo">$<?php echo number_format($propiedad['gastos_operativos'] ?? 0, 2); ?></span>
                        </div>
                        <div class="item">
                            <span class="label">Beneficio Requerido</span>
                            <span class="value negativo">$<?php echo number_format($propiedad['ganancia_requerida'] ?? 0, 2); ?></span>
                        </div>
                        <div class="item">
                            <span class="label">Comisiones</span>
                            <span class="value negativo">$<?php echo number_format($propiedad['comisiones'] ?? 0, 2); ?></span>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($propiedad['fotos_publicacion']) || !empty($propiedad['fotos_danos'])): ?>
                    <div style="margin-top: 15px;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div>
                                <strong><i class="fas fa-image"></i> Fotos Publicación:</strong>
                                <?php 
                                $fotos_pub = json_decode($propiedad['fotos_publicacion'] ?? '[]');
                                if (!empty($fotos_pub)): ?>
                                    <div style="display: flex; gap: 5px; flex-wrap: wrap; margin-top: 5px;">
                                        <?php foreach ($fotos_pub as $foto): ?>
                                            <a href="<?php echo $foto; ?>" target="_blank" style="display: inline-block;">
                                                <img src="<?php echo $foto; ?>" style="width: 60px; height: 60px; object-fit: cover; border-radius: 4px; border: 1px solid #ddd;">
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #888;">Sin fotos</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <strong><i class="fas fa-exclamation-triangle"></i> Evidencias daños:</strong>
                                <?php 
                                $fotos_danos = json_decode($propiedad['fotos_danos'] ?? '[]');
                                if (!empty($fotos_danos)): ?>
                                    <div style="display: flex; gap: 5px; flex-wrap: wrap; margin-top: 5px;">
                                        <?php foreach ($fotos_danos as $foto): ?>
                                            <a href="<?php echo $foto; ?>" target="_blank" style="display: inline-block;">
                                                <img src="<?php echo $foto; ?>" style="width: 60px; height: 60px; object-fit: cover; border-radius: 4px; border: 1px solid #ddd;">
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #888;">Sin fotos</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!empty($propiedad['latitud']) && !empty($propiedad['longitud'])): ?>
                            <div style="margin-top: 10px;">
                                <strong><i class="fas fa-map-pin"></i> Ubicación:</strong>
                                <a href="https://www.openstreetmap.org/?mlat=<?php echo $propiedad['latitud']; ?>&mlon=<?php echo $propiedad['longitud']; ?>&zoom=15" target="_blank">
                                    <?php echo $propiedad['latitud']; ?>, <?php echo $propiedad['longitud']; ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <div style="display: flex; gap: 10px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; flex-wrap: wrap;">
                    <a href="?listado=1" class="btn btn-primary"><i class="fas fa-arrow-left"></i> Volver al listado</a>
                    <button onclick="document.getElementById('exportPdfForm').submit();" class="btn btn-danger">
                        <i class="fas fa-file-pdf"></i> Exportar PDF
                    </button>
                </div>
            </div>
            
        <?php else: ?>
            <!-- ===== SIN PROPIEDAD SELECCIONADA ===== -->
            <div class="card" style="text-align: center; padding: 40px;">
                <div style="font-size: 48px; color: #c9a84c; margin-bottom: 15px;">
                    <i class="fas fa-home"></i>
                </div>
                <h3 style="color: #1a1a2e;">Selecciona o crea una propiedad</h3>
                <p style="color: #888; margin-bottom: 20px;">
                    Puedes crear una nueva propiedad o seleccionar una existente del listado
                </p>
                <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                    <a href="?nueva=1" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Nueva propiedad
                    </a>
                    <a href="?listado=1" class="btn btn-outline">
                        <i class="fas fa-list"></i> Ver listado
                    </a>
                </div>
            </div>
        <?php endif; ?>
        
    </div>
</main>

<script>
// ============================================
// MENÚ MÓVIL
// ============================================
const menuToggle = document.getElementById('menuToggle');
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');

if (menuToggle) {
    menuToggle.addEventListener('click', function() {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('show');
        document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
    });
}

if (overlay) {
    overlay.addEventListener('click', function() {
        sidebar.classList.remove('open');
        overlay.classList.remove('show');
        document.body.style.overflow = '';
    });
}

// ============================================
// FUNCIÓN PARA MOSTRAR ARCHIVOS SELECCIONADOS
// ============================================
function updateFileList(input, listId) {
    const list = document.getElementById(listId);
    if (!list) return;
    
    if (input.files.length > 0) {
        let html = '';
        for (let i = 0; i < input.files.length; i++) {
            html += `<div class="file-item">
                <span>📷 ${input.files[i].name}</span>
                <span style="color: #888; font-size: 10px;">${(input.files[i].size / 1024).toFixed(1)} KB</span>
            </div>`;
        }
        list.innerHTML = html;
    } else {
        list.innerHTML = '';
    }
}

// ============================================
// MAPA CON LEAFLET
// ============================================
let map;
let marker;
let initialLat = <?php echo $propiedad['latitud'] ?? 19.4326; ?>;
let initialLng = <?php echo $propiedad['longitud'] ?? -99.1332; ?>;

document.addEventListener('DOMContentLoaded', function() {
    const mapContainer = document.getElementById('map');
    if (!mapContainer) return;
    
    // Inicializar mapa
    map = L.map('map').setView([initialLat, initialLng], 15);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap'
    }).addTo(map);
    
    // Si hay coordenadas guardadas, mostrar marcador
    if (initialLat && initialLng && initialLat != 0 && initialLng != 0) {
        marker = L.marker([initialLat, initialLng]).addTo(map);
        marker.bindPopup('📍 Ubicación de la propiedad').openPopup();
    }
    
    // Click en el mapa para poner marcador
    map.on('click', function(e) {
        const lat = e.latlng.lat;
        const lng = e.latlng.lng;
        colocarMarcador(lat, lng);
    });
});

function colocarMarcador(lat, lng) {
    if (marker) {
        marker.setLatLng([lat, lng]);
    } else {
        marker = L.marker([lat, lng]).addTo(map);
        marker.bindPopup('📍 Ubicación de la propiedad');
    }
    document.getElementById('latitud').value = lat;
    document.getElementById('longitud').value = lng;
    map.setView([lat, lng], 15);
}

function obtenerUbicacion() {
    if (!navigator.geolocation) {
        Swal.fire({
            icon: 'warning',
            title: 'Geolocalización no soportada',
            text: 'Tu navegador no soporta geolocalización. Ingresa las coordenadas manualmente.',
            confirmButtonColor: '#c9a84c'
        });
        return;
    }
    
    Swal.fire({
        title: 'Obteniendo ubicación...',
        text: 'Por favor espera mientras obtenemos tu ubicación',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    navigator.geolocation.getCurrentPosition(
        function(position) {
            Swal.close();
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            colocarMarcador(lat, lng);
            Swal.fire({
                icon: 'success',
                title: 'Ubicación obtenida',
                text: `Coordenadas: ${lat.toFixed(6)}, ${lng.toFixed(6)}`,
                timer: 2000,
                timerProgressBar: true,
                showConfirmButton: false
            });
        },
        function(error) {
            Swal.close();
            let mensaje = 'No se pudo obtener tu ubicación.';
            switch(error.code) {
                case error.PERMISSION_DENIED:
                    mensaje = 'Permiso denegado. Activa la ubicación en tu dispositivo.';
                    break;
                case error.POSITION_UNAVAILABLE:
                    mensaje = 'Información de ubicación no disponible.';
                    break;
                case error.TIMEOUT:
                    mensaje = 'Tiempo de espera agotado.';
                    break;
            }
            Swal.fire({
                icon: 'error',
                title: 'Error de ubicación',
                text: mensaje + ' Ingresa las coordenadas manualmente.',
                confirmButtonColor: '#c9a84c'
            });
        },
        {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 0
        }
    );
}

function limpiarMapa() {
    if (marker) {
        map.removeLayer(marker);
        marker = null;
    }
    document.getElementById('latitud').value = '';
    document.getElementById('longitud').value = '';
    map.setView([19.4326, -99.1332], 15);
}

// ============================================
// CONFIRMACIONES
// ============================================
// Confirmar antes de rechazar una propiedad
document.querySelectorAll('button[name="decision"][value="rechazado"]').forEach(btn => {
    btn.addEventListener('click', function(e) {
        if (!confirm('⚠️ ¿Estás seguro de RECHAZAR esta propiedad? Esta acción notificará al captador.')) {
            e.preventDefault();
        }
    });
});

// Confirmar antes de aprobar
document.querySelectorAll('button[name="decision"][value="aprobado"]').forEach(btn => {
    btn.addEventListener('click', function(e) {
        if (!confirm('✅ ¿Confirmas APROBAR esta propiedad? Esto permitirá agendar la visita.')) {
            e.preventDefault();
        }
    });
});

// ============================================
// AUTOCOMPLETAR FECHA DE VISITA
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    const fechaInput = document.getElementById('fecha_visita');
    if (fechaInput) {
        // Establecer fecha mínima = ahora + 1 hora
        const ahora = new Date();
        ahora.setHours(ahora.getHours() + 1);
        const iso = ahora.toISOString().slice(0, 16);
        fechaInput.min = iso;
        
        // Si no tiene valor, poner uno por defecto (mañana a las 10am)
        if (!fechaInput.value) {
            const manana = new Date();
            manana.setDate(manana.getDate() + 1);
            manana.setHours(10, 0, 0, 0);
            fechaInput.value = manana.toISOString().slice(0, 16);
        }
    }
});
</script>

</body>
</html>