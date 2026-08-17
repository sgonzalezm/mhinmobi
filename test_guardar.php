<?php
session_start();
require_once 'guardar_propiedad.php';
require_once 'includes/conexion.php';

// Datos de prueba
$data = [
    'titulo' => 'Prueba desde test',
    'precio' => 1000000,
    'tipo_operacion' => 'venta',
    'tipo_vivienda' => 'casa',
    'tipo_casa' => 'una_planta',
    'm2' => 150,
    'recamaras' => 3,
    'banos' => 2,
    'estacionamiento' => 2,
    'ubicacion' => 'Ciudad de México, Benito Juárez',
    'descripcion' => 'Casa de prueba',
    'imagenes' => ['uploads/propiedades/test.jpg'],
    'tiene_adeudo' => 0,
    'legal_status' => 'libre'
];

$usuario_id = 2; // ID de usuario existente

$resultado = guardarPropiedad($data, $usuario_id);
echo "<pre>";
print_r($resultado);
echo "</pre>";
?>