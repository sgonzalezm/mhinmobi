<?php
// archivo: gracias_documentos.php
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>¡Gracias! - Inmobiliaria MH</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh; 
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .card {
            background: white;
            border-radius: 16px;
            padding: 50px;
            max-width: 550px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1);
        }
        .icon { font-size: 80px; color: #27ae60; margin-bottom: 20px; }
        h1 { color: #2c3e50; font-size: 28px; margin-bottom: 10px; }
        p { color: #666; font-size: 16px; line-height: 1.6; }
        .btn { 
            display: inline-block;
            margin-top: 25px;
            padding: 12px 35px;
            background: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn:hover { background: #2980b9; transform: translateY(-2px); }
    </style>
</head>
<body>
<div class="card">
    <div class="icon"><i class="fas fa-check-circle"></i></div>
    <h1>¡Gracias por tu colaboración!</h1>
    <p>
        Todos tus documentos han sido recibidos correctamente.
        <br><br>
        El equipo inmobiliario los revisará y te contactará si necesitan 
        algún documento adicional o aclaración.
        <br><br>
        <small style="color: #999;">
            <i class="fas fa-lock" style="color: #3498db;"></i> 
            Tus documentos están seguros y encriptados.
        </small>
    </p>
    <a href="index.php" class="btn">
        <i class="fas fa-home"></i> Volver al Inicio
    </a>
</div>
</body>
</html>