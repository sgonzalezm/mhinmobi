<?php
$titulo = "Solicitud de Eliminación de Datos";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo; ?> | Vera Terra</title>
    <style>
        body { font-family: 'Montserrat', sans-serif; background: #f8f7f4; padding: 60px 20px; text-align: center; }
        .box { max-width: 600px; margin: 0 auto; background: #fff; padding: 50px 40px; border-radius: 10px; box-shadow: 0 8px 30px rgba(11,31,58,0.08); }
        h1 { font-family: 'Playfair Display', serif; color: #0b1f3a; margin-bottom: 20px; }
        p { color: #5a5a5a; margin-bottom: 20px; line-height: 1.7; }
        a.btn { display: inline-block; background: #c5a059; color: #fff; padding: 14px 36px; border-radius: 50px; text-decoration: none; font-weight: 600; }
        a.btn:hover { background: #b08d46; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Solicitud de Eliminación de Datos</h1>
        <p>Si deseas que eliminemos tus datos personales de nuestros sistemas, envía un correo a:</p>
        <p><strong>atencion@veraterra.com</strong></p>
        <p>Indica tu nombre completo, correo electrónico registrado y el motivo de la solicitud. Procesaremos tu petición en un plazo máximo de 20 días hábiles.</p>
        <a href="mailto:atencion@veraterra.com?subject=Solicitud%20de%20eliminación%20de%20datos" class="btn">Solicitar eliminación</a>
    </div>
</body>
</html>