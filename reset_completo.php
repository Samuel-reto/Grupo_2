<?php
// Destruir TODA la sesión
session_start();
$_SESSION = [];
session_destroy();

// Destruir cookies de sesión
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destruir cookies de WordPress (por si acaso)
foreach ($_COOKIE as $name => $value) {
    if (strpos($name, 'wordpress') !== false || strpos($name, 'wp') !== false) {
        setcookie($name, '', time() - 3600, '/');
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sesión destruida - Health2You</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #e8f5e9, #e1f5fe);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .box {
            background: white;
            padding: 50px 40px;
            border-radius: 16px;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            text-align: center;
        }
        .success-icon {
            font-size: 80px;
            margin-bottom: 20px;
            animation: bounce 1s ease;
        }
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-20px); }
        }
        h1 {
            color: #0f9d58;
            margin: 0 0 16px 0;
            font-size: 28px;
        }
        p {
            color: #666;
            margin: 0 0 30px 0;
            font-size: 16px;
            line-height: 1.6;
        }
        .info-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 16px;
            margin: 20px 0;
            text-align: left;
            border-radius: 4px;
        }
        .info-box p {
            margin: 0;
            font-size: 14px;
            color: #856404;
        }
        .info-box strong {
            display: block;
            margin-bottom: 8px;
            color: #333;
        }
        a {
            display: inline-block;
            padding: 16px 40px;
            background: #0f9d58;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s;
            margin-top: 10px;
        }
        a:hover {
            background: #0d8549;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(15, 157, 88, 0.4);
        }
        .secondary-link {
            display: block;
            margin-top: 20px;
            color: #0288d1;
            font-size: 14px;
            text-decoration: none;
        }
        .secondary-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="box">
        <div class="success-icon">✅</div>
        <h1>Sesión completamente destruida</h1>
        <p>Todas las cookies y datos de sesión han sido eliminados correctamente.</p>
        
        <div class="info-box">
            <strong>✓ Lo que se ha limpiado:</strong>
            <p>
                • Variables de sesión PHP<br>
                • Cookies de autenticación<br>
                • Datos temporales 2FA<br>
                • Sesiones de WordPress
            </p>
        </div>
        
        <p style="font-size: 14px; color: #999; margin-top: 20px;">
            Ahora puedes iniciar sesión con una sesión completamente limpia.
        </p>
        
        <a href="login.php">🔒 Ir a Login</a>
        
        <a href="index.php" class="secondary-link">← Volver al inicio</a>
    </div>
</body>
</html>
