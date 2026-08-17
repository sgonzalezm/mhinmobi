<?php
session_start();
require_once 'guardar_propiedad.php';
require_once 'includes/conexion.php';

echo "<h1>Diagnóstico de vender.php</h1>";

// 1. Verificar sesión
echo "<h2>1. Sesión:</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

// 2. Verificar POST
echo "<h2>2. POST:</h2>";
echo "<pre>";
print_r($_POST);
echo "</pre>";

// 3. Verificar si hay confirmar
if (isset($_POST['confirmar'])) {
    echo "<h2 style='color:green'>✅ Se recibió confirmar</h2>";
    
    // 4. Verificar datos en sesión
    echo "<h2>3. Datos en form_venta:</h2>";
    echo "<pre>";
    print_r($_SESSION['form_venta'] ?? 'No existe');
    echo "</pre>";
    
    // 5. Intentar guardar
    if (isset($_SESSION['usuario_id'])) {
        echo "<h2>4. Intentando guardar...</h2>";
        $resultado = guardarPropiedad($_SESSION['form_venta'], $_SESSION['usuario_id']);
        echo "<pre>";
        print_r($resultado);
        echo "</pre>";
    } else {
        echo "<h2 style='color:red'>❌ No hay usuario_id en sesión</h2>";
    }
} else {
    echo "<h2 style='color:orange'>⚠️ No se recibió confirmar</h2>";
}

// Mostrar cómo debería ser el formulario
echo "<h2>5. Simulación del formulario:</h2>";
echo '<form method="POST" action="">';
echo '<input type="hidden" name="confirmar" value="1">';
echo '<button type="submit">Probar confirmar</button>';
echo '</form>';
?>