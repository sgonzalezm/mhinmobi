<?php
session_start();
require_once '../includes/conexion.php';
require_once '../includes/auth.php';

// Verificar autenticación
if (!estaLogueado()) {
    header('Location: ../login.php');
    exit;
}

// Obtener usuario actual
$usuario = obtenerUsuarioActual($conn);
if (!$usuario) {
    cerrarSesion();
    header('Location: ../login.php');
    exit;
}

// Determinar si es administrador (usando usuario_rol)
$es_administrador = isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'admin';

// ============================================
// FUNCIONES DE NOTIFICACIÓN
// ============================================
function notificarCambioEtapa($propiedad_id, $etapa_anterior, $etapa_nueva, $usuario_id) {
    error_log("NOTIFICACIÓN: Propiedad $propiedad_id cambió de etapa $etapa_anterior a $etapa_nueva");
    return true;
}

function notificarDecisionManagement($propiedad_id, $decision, $comentarios, $usuario_id) {
    error_log("NOTIFICACIÓN: Propiedad $propiedad_id - Decisión: $decision - Comentarios: $comentarios");
    return true;
}

function notificarVisitaAgendada($propiedad_id, $fecha_visita, $gestor_id, $captador_id) {
    error_log("NOTIFICACIÓN: Propiedad $propiedad_id - Visita agendada para $fecha_visita");
    return true;
}

// ============================================
// FUNCIÓN PARA CALCULAR VIABILIDAD
// ============================================
function calcularViabilidad($precio_pretendido, $gastos_operativos, $ganancia_requerida, $comisiones, $precio_comercial) {
    $costo_total = $precio_pretendido + $gastos_operativos + $ganancia_requerida + $comisiones;
    $margen = $precio_comercial - $costo_total;
    
    if ($margen > $precio_comercial * 0.15) {
        return [
            'indicador_color' => 'verde',
            'viabilidad' => 'viable',
            'margen' => $margen,
            'costo_total' => $costo_total
        ];
    } elseif ($margen > 0) {
        return [
            'indicador_color' => 'amarillo',
            'viabilidad' => 'viable_condicionado',
            'margen' => $margen,
            'costo_total' => $costo_total
        ];
    } else {
        return [
            'indicador_color' => 'rojo',
            'viabilidad' => 'no_viable',
            'margen' => $margen,
            'costo_total' => $costo_total
        ];
    }
}

// ============================================
// FUNCIÓN PARA EXPORTAR PDF
// ============================================
function generarPDFPropiedad($propiedad, $costos, $usuario) {
    $tcpdf_path = '../vendor/tecnickcom/tcpdf/tcpdf.php';
    if (!file_exists($tcpdf_path)) {
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
    
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->Cell(0, 10, 'INMOBILIARIA MH', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'Reporte de Calificación de Propiedad', 0, 1, 'C');
    $pdf->Ln(10);
    
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'Propiedad #' . $propiedad['id'], 0, 1);
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 6, 'Fecha: ' . date('d/m/Y H:i'), 0, 1);
    $pdf->Ln(5);
    
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
        ['Baños', number_format($propiedad['banos'] ?? 0, 1)],
        ['Estacionamientos', $propiedad['estacionamiento'] ?? 'N/A'],
    ];
    
    foreach ($datos as $dato) {
        $pdf->Cell(50, 6, $dato[0] . ':', 0, 0);
        $pdf->Cell(0, 6, $dato[1], 0, 1);
    }
    
    $pdf->Ln(5);
    
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
    $decision_texto = [
        'aprobado' => 'Aprobado',
        'rechazado' => 'Rechazado',
        'renegociar' => 'Renegociar',
        'pendiente' => 'Pendiente'
    ];
    $pdf->Cell(0, 6, $decision_texto[$decision] ?? ucfirst($decision), 0, 1);
    
    if (!empty($propiedad['comentarios_management'])) {
        $pdf->Ln(3);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'Comentarios Management:', 0, 1);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->MultiCell(0, 6, $propiedad['comentarios_management'], 0, 1);
    }
    
    $pdf->Ln(5);
    
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
    
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 6, 'Reporte generado por ' . $usuario['name'] . ' el ' . date('d/m/Y H:i'), 0, 1, 'C');
    
    $nombre_archivo = 'propiedad_' . $propiedad['id'] . '_' . date('Ymd_His') . '.pdf';
    $ruta = '../uploads/reportes/' . $nombre_archivo;
    
    if (!is_dir('../uploads/reportes')) {
        mkdir('../uploads/reportes', 0777, true);
    }
    
    $pdf->Output($ruta, 'F');
    return $ruta;
}

function generarHTMLReporte($propiedad, $costos, $usuario) {
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
        .renegociar { background: #fff3cd; color: #856404; }
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
    $html .= '<div><span class="etiqueta">Baños:</span> ' . number_format($propiedad['banos'] ?? 0, 1) . '</div>';
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
    
    $nombre_archivo = 'propiedad_' . $propiedad['id'] . '_' . date('Ymd_His') . '.html';
    $ruta = '../uploads/reportes/' . $nombre_archivo;
    
    if (!is_dir('../uploads/reportes')) {
        mkdir('../uploads/reportes', 0777, true);
    }
    
    file_put_contents($ruta, $html);
    return $ruta;
}

// ============================================
// OBTENER PARÁMETROS DE COSTOS
// ============================================
$stmt = $conn->prepare("SELECT * FROM parametros_costos WHERE id = 1");
$stmt->execute();
$costos = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$costos) {
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
    $stmt = $conn->prepare("SELECT p.*, u.name as captador_nombre, u2.name as gestor_nombre
                            FROM propiedades_calificacion p
                            LEFT JOIN users u ON p.creado_por = u.id
                            LEFT JOIN users u2 ON p.gestor_asignado = u2.id
                            WHERE p.id = ?");
    $stmt->execute([$propiedad_id]);
    $propiedad = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($propiedad) {
        $es_edicion = true;
        
        // Si el usuario NO es administrador, siempre va a etapa 1 (solo captura)
        if (!$es_administrador) {
            $etapa = 1;
        } else {
            // Administrador: decide la etapa según el estado
            if ($propiedad['go_no_go'] == 'pendiente' || $propiedad['go_no_go'] == null) {
                $etapa = 2; // Revisión financiera
            } elseif ($propiedad['go_no_go'] == 'aprobado' && empty($propiedad['fecha_visita'])) {
                $etapa = 3; // Gestor
            } elseif ($propiedad['go_no_go'] == 'aprobado' && !empty($propiedad['fecha_visita'])) {
                $etapa = 4; // Completada
            } elseif ($propiedad['go_no_go'] == 'renegociar') {
                $etapa = 2; // Vuelve a revisión
            }
        }
    }
}

// ============================================
// PROCESAR POST
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'guardar_captura') {
        $errores = [];
        
        $direccion = trim($_POST['direccion'] ?? '');
        $precio_pretendido = floatval($_POST['precio_pretendido'] ?? 0);
        $m2_construccion = floatval($_POST['m2_construccion'] ?? 0);
        
        if (empty($direccion)) $errores[] = 'La dirección es obligatoria';
        if ($precio_pretendido <= 0) $errores[] = 'El precio pretendido debe ser mayor a 0';
        if ($m2_construccion <= 0) $errores[] = 'Los metros cuadrados deben ser mayores a 0';
        
        if (empty($errores)) {
            // Obtener valores financieros
            // Si es administrador, usa los valores ingresados, si no, usa valores por defecto (0)
            if ($es_administrador) {
                $gastos_operativos = floatval($_POST['gastos_operativos_fijos'] ?? $costos['gastos_operativos_por_m2'] ?? 0);
                $ganancia_requerida = floatval($_POST['beneficio_fijo'] ?? ($precio_pretendido * (($costos['porcentaje_ganancia'] ?? 20) / 100)));
                $comisiones = $precio_pretendido * (($costos['porcentaje_comision'] ?? 3) / 100);
                $precio_comercial = $precio_pretendido * ($costos['factor_ajuste_comercial'] ?? 1.15);
            } else {
                // Calificador: usa valores por defecto pero SIEMPRE calcula
                $gastos_operativos = $costos['gastos_operativos_por_m2'] ?? 0;
                $ganancia_requerida = $precio_pretendido * (($costos['porcentaje_ganancia'] ?? 20) / 100);
                $comisiones = $precio_pretendido * (($costos['porcentaje_comision'] ?? 3) / 100);
                $precio_comercial = $precio_pretendido * ($costos['factor_ajuste_comercial'] ?? 1.15);
            }
            
            // Calcular viabilidad (SIEMPRE se calcula)
            $resultado = calcularViabilidad($precio_pretendido, $gastos_operativos, $ganancia_requerida, $comisiones, $precio_comercial);
            
            if ($propiedad_id > 0) {
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
                    floatval($_POST['banos'] ?? 0),
                    intval($_POST['estacionamiento'] ?? 0),
                    $precio_pretendido,
                    $precio_comercial,
                    $gastos_operativos,
                    $ganancia_requerida,
                    $comisiones,
                    $resultado['viabilidad'],
                    $resultado['indicador_color'],
                    $usuario['id'],
                    $propiedad_id
                ]);
                
                $_SESSION['mensaje'] = '✅ Propiedad actualizada correctamente';
            } else {
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
                    floatval($_POST['banos'] ?? 0),
                    intval($_POST['estacionamiento'] ?? 0),
                    $precio_pretendido,
                    $precio_comercial,
                    $gastos_operativos,
                    $ganancia_requerida,
                    $comisiones,
                    $resultado['viabilidad'],
                    $resultado['indicador_color'],
                    $usuario['id']
                ]);
                
                $propiedad_id = $conn->lastInsertId();
                notificarCambioEtapa($propiedad_id, 'creacion', 'captura', $usuario['id']);
                
                $_SESSION['mensaje'] = '✅ Propiedad registrada correctamente';
            }
            
            // Redirigir según rol
            if ($es_administrador) {
                header("Location: calificador_propiedad.php?id=" . $propiedad_id . "&etapa=2");
            } else {
                header("Location: calificador_propiedad.php?listado=1");
            }
            exit;
        } else {
            $_SESSION['errores'] = $errores;
        }
    }
    
    if ($action === 'go_no_go') {
        $decision = $_POST['decision'] ?? '';
        $comentarios = trim($_POST['comentarios_management'] ?? '');
        
        // Solo administrador puede tomar decisión
        if (!$es_administrador) {
            $_SESSION['errores'] = ['No tienes permisos para tomar esta decisión'];
            header("Location: calificador_propiedad.php?id=" . $propiedad_id);
            exit;
        }
        
        if (in_array($decision, ['aprobado', 'renegociar', 'rechazado'])) {
            $sql = "UPDATE propiedades_calificacion SET 
                go_no_go = ?,
                comentarios_management = ?,
                usuario_decision_id = ?,
                fecha_decision = NOW()
                WHERE id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$decision, $comentarios, $usuario['id'], $propiedad_id]);
            
            notificarDecisionManagement($propiedad_id, $decision, $comentarios, $usuario['id']);
            
            switch ($decision) {
                case 'aprobado':
                    $_SESSION['mensaje'] = '✅ Propiedad aprobada. Ahora se puede agendar visita.';
                    break;
                case 'renegociar':
                    $_SESSION['mensaje'] = '🔄 Propiedad enviada a renegociación. El captador puede ajustar los datos.';
                    notificarCambioEtapa($propiedad_id, 'management', 'renegociar', $usuario['id']);
                    break;
                case 'rechazado':
                    $_SESSION['mensaje'] = '❌ Propiedad rechazada. Se ha notificado al captador.';
                    notificarCambioEtapa($propiedad_id, 'management', 'rechazado', $usuario['id']);
                    break;
            }
            
            header("Location: calificador_propiedad.php?listado=1");
            exit;
        }
    }
    
    if ($action === 'guardar_visita') {
        $fecha_visita = $_POST['fecha_visita'] ?? '';
        $latitud = floatval($_POST['latitud'] ?? 0);
        $longitud = floatval($_POST['longitud'] ?? 0);
        
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

// ============================================
// ESTADÍSTICAS PARA DASHBOARD
// ============================================
$stats = [
    'total' => 0,
    'aprobadas' => 0,
    'rechazadas' => 0,
    'pendientes' => 0,
    'renegociar' => 0,
    'con_visita' => 0
];

$stmt = $conn->prepare("SELECT go_no_go FROM propiedades_calificacion WHERE status = 1");
$stmt->execute();
$todas = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stats['total'] = count($todas);

foreach ($todas as $p) {
    if ($p['go_no_go'] == 'aprobado') $stats['aprobadas']++;
    elseif ($p['go_no_go'] == 'rechazado') $stats['rechazadas']++;
    elseif ($p['go_no_go'] == 'renegociar') $stats['renegociar']++;
    elseif ($p['go_no_go'] == 'pendiente' || $p['go_no_go'] == null) $stats['pendientes']++;
}

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM propiedades_calificacion WHERE fecha_visita IS NOT NULL AND status = 1");
$stmt->execute();
$stats['con_visita'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calificador de Propiedades | Inmobiliaria MH</title>
    <link rel="stylesheet" href="../css/socios.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        /* ===== ESTILOS UNIFICADOS CON MENSAJES ===== */
        :root {
            --primary: #c9a84c;
            --primary-dark: #b8963a;
            --dark: #1a1a2e;
            --gray: #6c757d;
            --light-gray: #f8f9fa;
        }

        .calificador-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px 20px;
        }

        /* ===== HEADER DE ETAPA ===== */
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
        .etapa-header h2 {
            margin: 0;
            font-size: 22px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .etapa-header .badge-etapa {
            background: #c9a84c;
            color: #1a1a2e;
            padding: 6px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 13px;
        }

        /* ===== TARJETAS ESTADÍSTICAS ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: white;
            padding: 15px 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s ease;
            border-left: 4px solid var(--primary);
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.1);
        }
        .stat-card .stat-icon {
            font-size: 28px;
            color: var(--primary);
            width: 50px;
            height: 50px;
            background: #f8f6f0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .stat-card .stat-number {
            font-size: 24px;
            font-weight: 700;
            color: var(--dark);
            line-height: 1.2;
        }
        .stat-card .stat-label {
            font-size: 13px;
            color: var(--gray);
        }
        .stat-card.success { border-left-color: #28a745; }
        .stat-card.success .stat-icon { color: #28a745; background: #d4edda; }
        .stat-card.danger { border-left-color: #dc3545; }
        .stat-card.danger .stat-icon { color: #dc3545; background: #f8d7da; }
        .stat-card.info { border-left-color: #17a2b8; }
        .stat-card.info .stat-icon { color: #17a2b8; background: #d1ecf1; }
        .stat-card.warning { border-left-color: #ffc107; }
        .stat-card.warning .stat-icon { color: #856404; background: #fff3cd; }

        /* ===== CARDS DE CONTENIDO ===== */
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
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

        /* ===== FORMULARIOS ===== */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px 25px;
        }
        .form-grid .full-width { grid-column: 1 / -1; }
        .form-group { margin-bottom: 12px; }
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
            background: white;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: #c9a84c;
            outline: none;
            box-shadow: 0 0 0 3px rgba(201,168,76,0.1);
        }
        .form-group textarea { min-height: 60px; resize: vertical; }

        /* ===== BOTONES ===== */
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
        .btn-success:hover { background: #218838; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; }
        .btn-info { background: #17a2b8; color: white; }
        .btn-info:hover { background: #138496; }
        .btn-warning { background: #ffc107; color: #1a1a2e; }
        .btn-warning:hover { background: #e0a800; }
        .btn-outline {
            background: transparent;
            border: 2px solid #c9a84c;
            color: #c9a84c;
        }
        .btn-outline:hover { background: #c9a84c; color: white; }
        .btn-sm { padding: 4px 12px; font-size: 12px; }

        /* ===== SEMÁFORO ===== */
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

        /* ===== GO/NO-GO ===== */
        .go-nogo-container {
            display: flex;
            gap: 15px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        .go-nogo-container .btn { 
            flex: 1; 
            justify-content: center; 
            padding: 15px 20px; 
            font-size: 16px; 
            min-width: 150px;
        }

        /* ===== MAPA ===== */
        #map {
            height: 350px;
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

        /* ===== FOTOS UPLOAD ===== */
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

        /* ===== TABLA LISTADO ===== */
        .table-container { overflow-x: auto; }
        .table-container table {
            width: 100%;
            border-collapse: collapse;
        }
        .table-container table th {
            background: #f8f6f0;
            padding: 10px 15px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            color: #555;
            border-bottom: 2px solid #e8e8e8;
        }
        .table-container table td {
            padding: 10px 15px;
            border-bottom: 1px solid #eee;
            font-size: 14px;
        }
        .table-container table tr:hover { background: #fafaf8; }
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
        .estado-badge.renegociar { background: #fff3cd; color: #856404; }
        .estado-badge.pendiente { background: #e2e3e5; color: #383d41; }
        .estado-badge.completado { background: #cce5ff; color: #004085; }
        .estado-badge.warning { background: #fff3cd; color: #856404; }

        .acciones-btns { display: flex; gap: 5px; flex-wrap: wrap; }
        .acciones-btns .btn { padding: 3px 8px; font-size: 11px; }

        /* ===== MENSAJES DE ALERTA ===== */
        .alert-success {
            background: #d4edda;
            padding: 12px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
            color: #155724;
        }
        .alert-danger {
            background: #f8d7da;
            padding: 12px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #dc3545;
            color: #721c24;
        }
        .alert-success p, .alert-danger p { margin: 0; }
        .alert-danger ul { margin: 0; padding-left: 20px; }

        /* ===== RESIDUO FINANCIERO ===== */
        .resumen-financiero {
            background: #f8f6f0;
            padding: 12px 18px;
            border-radius: 8px;
            margin: 10px 0;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            align-items: center;
        }
        .resumen-financiero .margen-positivo { color: #28a745; }
        .resumen-financiero .margen-negativo { color: #dc3545; }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
            .semaforo-container { flex-direction: column; }
            .semaforo-info { grid-template-columns: 1fr 1fr; }
            .go-nogo-container { flex-direction: column; }
            .fotos-upload-grid { grid-template-columns: 1fr; }
            .etapa-header { flex-direction: column; text-align: center; gap: 10px; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }

        /* ===== BOTONES HEADER ===== */
        .btn-header {
            padding: 8px 18px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .sidebar {
            background: #1a1a2e !important;
        }
        .btn-header:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .btn-header.primary { background: #c9a84c; color: white; }
        .btn-header.secondary { background: #e9ecef; color: #1a1a2e; }
        .btn-header.danger { background: #dc3545; color: white; }
        .btn-header.danger:hover { background: #c82333; }

        /* ===== ACCESO RESTRINGIDO ===== */
        .acceso-restringido {
            text-align: center;
            padding: 60px 20px;
        }
        .acceso-restringido .icono {
            font-size: 64px;
            color: #dc3545;
            margin-bottom: 20px;
        }
        .acceso-restringido h3 {
            color: #1a1a2e;
            font-size: 24px;
            margin-bottom: 10px;
        }
        .acceso-restringido p {
            color: #888;
            max-width: 500px;
            margin: 0 auto 25px;
        }
        .acceso-restringido .comentarios-admin {
            background: #f8f6f0;
            padding: 15px;
            border-radius: 6px;
            text-align: left;
            margin: 10px auto;
            max-width: 500px;
        }
        .acceso-restringido .comentarios-admin strong {
            color: #1a1a2e;
        }
        .acceso-restringido .alert-renegociar {
            color: #856404;
            background: #fff3cd;
            padding: 10px 15px;
            border-radius: 6px;
            max-width: 500px;
            margin: 10px auto;
        }
    </style>
</head>
<body>

<!-- Overlay para móvil -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php include '../modulos/sidebar.php'; ?>

<!-- ===== MAIN CONTENT ===== -->
<main class="main-content">
    <div class="main-header">
        <div class="header-left">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <h1>🏠 Calificador de Propiedades</h1>
            <p class="welcome">
                <i class="fas fa-clipboard-list"></i>
                <?php echo $stats['total']; ?> propiedades · 
                <?php echo $stats['pendientes']; ?> pendientes · 
                <?php echo $stats['con_visita']; ?> con visita
                <?php if ($es_administrador): ?>
                    · <span style="color: #c9a84c;">👑 Administrador</span>
                <?php else: ?>
                    · <span style="color: #17a2b8;">📋 Calificador</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="header-actions">
            <a href="?listado=1" class="btn-header secondary">
                <i class="fas fa-list"></i> Ver todas
            </a>
            <a href="?nueva=1" class="btn-header primary">
                <i class="fas fa-plus"></i> Nueva
            </a>
            <?php if ($propiedad_id > 0 && $es_administrador): ?>
                <button onclick="document.getElementById('exportPdfForm').submit();" class="btn-header danger">
                    <i class="fas fa-file-pdf"></i> PDF
                </button>
                <form id="exportPdfForm" method="POST" style="display:none;">
                    <input type="hidden" name="action" value="exportar_pdf">
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="calificador-container">
        
        <!-- ===== MENSAJES ===== -->
        <?php if (isset($_SESSION['mensaje'])): ?>
            <div class="alert-success">
                <p><?php echo $_SESSION['mensaje']; unset($_SESSION['mensaje']); ?></p>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['errores'])): ?>
            <div class="alert-danger">
                <ul>
                    <?php foreach ($_SESSION['errores'] as $error): ?>
                        <li><?php echo $error; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php unset($_SESSION['errores']); ?>
        <?php endif; ?>

        <!-- ===== ESTADÍSTICAS ===== -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-icon"><i class="fas fa-building"></i></span>
                <div>
                    <div class="stat-number"><?php echo $stats['total']; ?></div>
                    <div class="stat-label">Total Propiedades</div>
                </div>
            </div>
            <div class="stat-card success">
                <span class="stat-icon"><i class="fas fa-check-circle"></i></span>
                <div>
                    <div class="stat-number"><?php echo $stats['aprobadas']; ?></div>
                    <div class="stat-label">Aprobadas (Go)</div>
                </div>
            </div>
            <div class="stat-card danger">
                <span class="stat-icon"><i class="fas fa-times-circle"></i></span>
                <div>
                    <div class="stat-number"><?php echo $stats['rechazadas']; ?></div>
                    <div class="stat-label">Rechazadas (No-Go)</div>
                </div>
            </div>
            <div class="stat-card warning">
                <span class="stat-icon"><i class="fas fa-handshake"></i></span>
                <div>
                    <div class="stat-number"><?php echo $stats['renegociar']; ?></div>
                    <div class="stat-label">En Renegociación</div>
                </div>
            </div>
            <div class="stat-card warning">
                <span class="stat-icon"><i class="fas fa-clock"></i></span>
                <div>
                    <div class="stat-number"><?php echo $stats['pendientes']; ?></div>
                    <div class="stat-label">Pendientes</div>
                </div>
            </div>
            <div class="stat-card info">
                <span class="stat-icon"><i class="fas fa-calendar-check"></i></span>
                <div>
                    <div class="stat-number"><?php echo $stats['con_visita']; ?></div>
                    <div class="stat-label">Con Visita</div>
                </div>
            </div>
        </div>

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
                                            <?php if ($es_administrador): ?>
                                                <span class="estado-badge <?php echo $p['indicador_color'] ?? 'pendiente'; ?>">
                                                    <?php echo ucfirst($p['viabilidad'] ?? 'Sin evaluar'); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="estado-badge pendiente">
                                                    En revisión
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $decision = $p['go_no_go'] ?? 'pendiente';
                                            $clase = $decision;
                                            $texto = ucfirst($decision);
                                            if ($decision == 'renegociar') {
                                                $texto = '🔄 Renegociar';
                                            }
                                            ?>
                                            <span class="estado-badge <?php echo $clase; ?>">
                                                <?php echo $texto; ?>
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
                                            <?php elseif ($p['go_no_go'] == 'renegociar'): ?>
                                                <span class="estado-badge renegociar">Renegociar</span>
                                            <?php elseif ($p['go_no_go'] == 'pendiente' || $p['go_no_go'] == null): ?>
                                                <span class="estado-badge pendiente">En evaluación</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="acciones-btns">
                                                <a href="?id=<?php echo $p['id']; ?>" class="btn btn-primary btn-sm">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <?php if ($es_administrador && empty($p['fecha_visita']) && $p['go_no_go'] == 'aprobado'): ?>
                                                    <a href="?id=<?php echo $p['id']; ?>&etapa=3" class="btn btn-info btn-sm">
                                                        <i class="fas fa-calendar-plus"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if ($es_administrador && $p['go_no_go'] == 'renegociar'): ?>
                                                    <a href="?id=<?php echo $p['id']; ?>&etapa=2" class="btn btn-warning btn-sm">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if (!$es_administrador && $p['go_no_go'] == 'renegociar'): ?>
                                                    <a href="?id=<?php echo $p['id']; ?>&etapa=1" class="btn btn-warning btn-sm">
                                                        <i class="fas fa-edit"></i>
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
            
        <?php elseif (isset($_GET['nueva']) || ($propiedad_id > 0 && $etapa == 1)): ?>
            <!-- ===== ETAPA 1: CAPTADOR ===== -->
            <div class="etapa-header">
                <div>
                    <h2><i class="fas fa-pen"></i> Etapa 1: Captura de Datos</h2>
                    <small style="opacity:0.8;">
                        <?php echo $es_administrador ? 'Completa la información de la propiedad (incluye análisis financiero)' : 'Completa la información básica de la propiedad'; ?>
                    </small>
                </div>
                <span class="badge-etapa"><?php echo $es_administrador ? 'Administrador' : 'Captador'; ?>: <?php echo htmlspecialchars($usuario['name']); ?></span>
            </div>
            
            <!-- Mostrar mensaje de renegociación si aplica -->
            <?php if (!$es_administrador && $propiedad && $propiedad['go_no_go'] == 'renegociar'): ?>
                <div style="background: #fff3cd; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #ffc107;">
                    <p style="margin: 0; color: #856404;">
                        <i class="fas fa-handshake"></i> <strong>Renegociación solicitada</strong><br>
                        El administrador ha solicitado ajustes en esta propiedad. Por favor, revisa los comentarios y actualiza los datos.
                    </p>
                    <?php if (!empty($propiedad['comentarios_management'])): ?>
                        <div style="background: white; padding: 12px 15px; border-radius: 6px; margin-top: 10px;">
                            <strong style="color: #1a1a2e;">Comentarios del administrador:</strong><br>
                            <?php echo nl2br(htmlspecialchars($propiedad['comentarios_management'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
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
                        <input type="number" id="banos" name="banos" value="<?php echo htmlspecialchars($propiedad['banos'] ?? ''); ?>" step="0.5" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label for="estacionamiento">Estacionamientos</label>
                        <input type="number" id="estacionamiento" name="estacionamiento" value="<?php echo htmlspecialchars($propiedad['estacionamiento'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="precio_pretendido">Precio pretendido <span class="required">*</span></label>
                        <input type="number" id="precio_pretendido" name="precio_pretendido" value="<?php echo htmlspecialchars($propiedad['precio_pretendido'] ?? ''); ?>" step="0.01" required>
                    </div>
                    
                    <!-- CAMPOS SOLO PARA ADMINISTRADOR -->
                    <?php if ($es_administrador): ?>
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
                               value="<?php echo htmlspecialchars($propiedad['ganancia_requerida'] ?? ($propiedad['precio_pretendido'] * (($costos['porcentaje_ganancia'] ?? 20) / 100)) ?? ''); ?>" 
                               step="0.01">
                    </div>
                    <?php else: ?>
                    <!-- Campos ocultos para calificador (se calculan automáticamente) -->
                    <input type="hidden" name="gastos_operativos_fijos" value="<?php echo $costos['gastos_operativos_por_m2'] ?? 0; ?>">
                    <input type="hidden" name="beneficio_fijo" value="<?php echo ($propiedad['precio_pretendido'] ?? 0) * (($costos['porcentaje_ganancia'] ?? 20) / 100); ?>">
                    <?php endif; ?>
                </div>
                
                <!-- SEMÁFORO SOLO PARA ADMINISTRADOR -->
                <?php if ($es_administrador && $propiedad_id > 0 && !empty($propiedad)): ?>
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
                    <div class="resumen-financiero">
                        <span><strong>Costo Total:</strong> $<?php echo number_format($costo_total, 2); ?></span>
                        <span><strong>Margen:</strong> 
                            <strong class="<?php echo $margen > 0 ? 'margen-positivo' : 'margen-negativo'; ?>">
                                $<?php echo number_format($margen, 2); ?>
                            </strong>
                        </span>
                        <span style="font-size: 12px; color: #888;">
                            <i class="fas fa-info-circle"></i> Gastos y beneficio son fijos
                        </span>
                    </div>
                <?php endif; ?>
                
                <div style="display: flex; gap: 10px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?php echo $propiedad_id > 0 ? 'Actualizar' : 'Guardar'; ?>
                    </button>
                    <?php if ($es_administrador && $propiedad_id > 0): ?>
                        <a href="?id=<?php echo $propiedad_id; ?>&etapa=2" class="btn btn-success">
                            <i class="fas fa-arrow-right"></i> Ir a validación
                        </a>
                    <?php endif; ?>
                    <a href="?listado=1" class="btn btn-secondary">
                        <i class="fas fa-list"></i> Ver listado
                    </a>
                </div>
            </form>
            
        <?php elseif ($propiedad_id > 0 && $etapa == 2 && $es_administrador): ?>
            <!-- ===== ETAPA 2: TOP MANAGEMENT (SOLO ADMIN) ===== -->
            <div class="etapa-header">
                <div>
                    <h2><i class="fas fa-gavel"></i> Etapa 2: Decisión Administrativa</h2>
                    <small style="opacity:0.8;">Revisa los indicadores financieros y toma una decisión</small>
                </div>
                <span class="badge-etapa">Administrador: <?php echo htmlspecialchars($usuario['name']); ?></span>
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
                
                <!-- ANÁLISIS FINANCIERO COMPLETO PARA ADMIN -->
                <div class="semaforo-container">
                    <div class="semaforo-indicador">
                        <div class="luz <?php echo $propiedad['indicador_color'] ?? 'pendiente'; ?>"></div>
                        <div>
                            <strong><?php 
                                $viabilidad_texto = [
                                    'viable' => 'Viable',
                                    'viable_condicionado' => 'Viable con condiciones',
                                    'no_viable' => 'No viable',
                                    'pendiente_evaluacion' => 'Pendiente de evaluación'
                                ];
                                echo $viabilidad_texto[$propiedad['viabilidad']] ?? ucfirst($propiedad['viabilidad'] ?? 'Sin evaluar'); 
                            ?></strong>
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
                <div class="resumen-financiero">
                    <span><strong>Costo total estimado:</strong> $<?php echo number_format($costo_total, 2); ?></span>
                    <span><strong>Margen estimado:</strong> 
                        <strong class="<?php echo $margen > 0 ? 'margen-positivo' : 'margen-negativo'; ?>">
                            $<?php echo number_format($margen, 2); ?>
                        </strong>
                    </span>
                    <span style="font-size: 12px; color: #888;">
                        <i class="fas fa-info-circle"></i> Gastos operativos y beneficio son fijos
                    </span>
                </div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="go_no_go">
                    
                    <div class="form-group">
                        <label for="comentarios_management">Comentarios / Observaciones</label>
                        <textarea id="comentarios_management" name="comentarios_management" placeholder="Escribe tus comentarios sobre la viabilidad de esta propiedad (estos serán visibles para el captador si se renegocia)" rows="3"><?php echo htmlspecialchars($propiedad['comentarios_management'] ?? ''); ?></textarea>
                    </div>
                    
                    <!-- 3 OPCIONES DE DECISIÓN - BOTONES GRANDES -->
                    <div class="go-nogo-container">
                        <button type="submit" name="decision" value="aprobado" class="btn btn-success" onclick="return confirm('¿Confirmas APROBAR esta propiedad?');">
                            <i class="fas fa-check-circle" style="font-size: 20px;"></i> ✅ APROBAR
                        </button>
                        <button type="submit" name="decision" value="renegociar" class="btn btn-warning" onclick="return confirm('¿Confirmas RENEGOCIAR esta propiedad? El captador podrá ajustar los datos.');" style="background: #ffc107; color: #1a1a2e;">
                            <i class="fas fa-handshake" style="font-size: 20px;"></i> 🔄 RENEGOCIAR
                        </button>
                        <button type="submit" name="decision" value="rechazado" class="btn btn-danger" onclick="return confirm('¿Confirmas RECHAZAR esta propiedad?');">
                            <i class="fas fa-times-circle" style="font-size: 20px;"></i> ❌ RECHAZAR
                        </button>
                        <a href="?id=<?php echo $propiedad_id; ?>&etapa=1" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Volver a editar
                        </a>
                    </div>
                </form>
            </div>
            
        <?php elseif ($propiedad_id > 0 && $etapa == 2 && !$es_administrador): ?>
            <!-- ===== ACCESO RESTRINGIDO PARA CALIFICADOR ===== -->
            <div class="card">
                <div class="acceso-restringido">
                    <div class="icono">
                        <i class="fas fa-lock"></i>
                    </div>
                    <h3>Acceso Restringido</h3>
                    <p>
                        Esta propiedad está en proceso de revisión administrativa. 
                        No tienes acceso a los detalles financieros ni a la toma de decisiones.
                    </p>
                    <?php if ($propiedad['go_no_go'] == 'renegociar'): ?>
                        <div class="alert-renegociar">
                            <i class="fas fa-handshake"></i> El administrador ha solicitado <strong>renegociar</strong> esta propiedad. 
                            Por favor, ajusta los datos según los comentarios.
                        </div>
                        <?php if (!empty($propiedad['comentarios_management'])): ?>
                            <div class="comentarios-admin">
                                <strong>Comentarios del administrador:</strong><br>
                                <?php echo nl2br(htmlspecialchars($propiedad['comentarios_management'])); ?>
                            </div>
                        <?php endif; ?>
                        <a href="?id=<?php echo $propiedad_id; ?>&etapa=1" class="btn btn-warning" style="margin-top: 10px;">
                            <i class="fas fa-edit"></i> Editar propiedad
                        </a>
                    <?php endif; ?>
                    <div style="margin-top: 20px;">
                        <a href="?listado=1" class="btn btn-primary">
                            <i class="fas fa-arrow-left"></i> Volver al listado
                        </a>
                    </div>
                </div>
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
                    <?php if ($es_administrador): ?>
                        <div><strong>Decisión:</strong> 
                            <span class="estado-badge <?php echo $propiedad['go_no_go'] ?? 'pendiente'; ?>">
                                <?php 
                                $decision_texto = [
                                    'aprobado' => 'Aprobado',
                                    'rechazado' => 'Rechazado',
                                    'renegociar' => 'Renegociar',
                                    'pendiente' => 'Pendiente'
                                ];
                                echo $decision_texto[$propiedad['go_no_go']] ?? ucfirst($propiedad['go_no_go'] ?? 'Pendiente'); 
                                ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($es_administrador): ?>
                <div class="semaforo-container">
                    <div class="semaforo-indicador">
                        <div class="luz <?php echo $propiedad['indicador_color'] ?? 'pendiente'; ?>"></div>
                        <div>
                            <strong><?php 
                                $viabilidad_texto = [
                                    'viable' => 'Viable',
                                    'viable_condicionado' => 'Viable con condiciones',
                                    'no_viable' => 'No viable',
                                    'pendiente_evaluacion' => 'Pendiente de evaluación'
                                ];
                                echo $viabilidad_texto[$propiedad['viabilidad']] ?? ucfirst($propiedad['viabilidad'] ?? 'Sin evaluar'); 
                            ?></strong>
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
                <?php endif; ?>
                
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
                    <button>
                        <a href="../vender.php" class="btn btn-secondary">
                            <i class="fas fa-print"></i> Registrar la propiedad
                        </a>
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

document.querySelectorAll('.sidebar nav a').forEach(link => {
    link.addEventListener('click', () => {
        if (window.innerWidth <= 992) {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
            document.body.style.overflow = '';
        }
    });
});

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
    
    map = L.map('map').setView([initialLat, initialLng], 15);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap'
    }).addTo(map);
    
    if (initialLat && initialLng && initialLat != 0 && initialLng != 0) {
        marker = L.marker([initialLat, initialLng]).addTo(map);
        marker.bindPopup('📍 Ubicación de la propiedad').openPopup();
    }
    
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
// AUTOCOMPLETAR FECHA DE VISITA
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    const fechaInput = document.getElementById('fecha_visita');
    if (fechaInput) {
        const ahora = new Date();
        ahora.setHours(ahora.getHours() + 1);
        const iso = ahora.toISOString().slice(0, 16);
        fechaInput.min = iso;
        
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