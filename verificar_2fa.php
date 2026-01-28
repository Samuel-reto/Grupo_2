<?php
ob_start();
if (!defined('ABSPATH')) {
    require_once('../../../wp-load.php');
}
if (!session_id()) session_start();

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

$error = "";

// Verificar que venga de un login válido
if (!isset($_SESSION['codigo_2fa']) || !isset($_SESSION['paciente_temp_id'])) {
    header("Location: " . get_stylesheet_directory_uri() . "/login.php");
    exit;
}

// Email enmascarado
$email_enmascarado = "";
if (isset($_SESSION['paciente_temp_email'])) {
    $partes = explode('@', $_SESSION['paciente_temp_email']);
    $usuario = $partes[0];
    $dominio = $partes[1];
    $email_enmascarado = substr($usuario, 0, 2) . '***@' . $dominio;
}

// Procesar verificación
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codigo_ingresado = sanitize_text_field($_POST['codigo'] ?? '');
    
    if (time() > $_SESSION['codigo_2fa_expira']) {
        $error = "⏰ Código expirado. Vuelve a iniciar sesión.";
        
        unset($_SESSION['codigo_2fa']);
        unset($_SESSION['codigo_2fa_expira']);
        unset($_SESSION['paciente_temp_id']);
        unset($_SESSION['paciente_temp_nombre']);
        unset($_SESSION['tipo_temp']);
        unset($_SESSION['paciente_temp_email']);
        
    } elseif ($codigo_ingresado === $_SESSION['codigo_2fa']) {
        // ✅ Código correcto
        $_SESSION['h2y_tipo'] = 'paciente';
        $_SESSION['h2y_paciente_id'] = $_SESSION['paciente_temp_id'];
        $_SESSION['h2y_paciente_nombre'] = $_SESSION['paciente_temp_nombre'];
        
        unset($_SESSION['codigo_2fa']);
        unset($_SESSION['codigo_2fa_expira']);
        unset($_SESSION['paciente_temp_id']);
        unset($_SESSION['paciente_temp_nombre']);
        unset($_SESSION['tipo_temp']);
        unset($_SESSION['paciente_temp_email']);
        unset($_SESSION['debug_codigo_2fa']);
        
        $redirect_url = get_stylesheet_directory_uri() . '/dashboard_paciente.php';
        header("Location: $redirect_url");
        exit;
        
    } else {
        $error = "❌ Código incorrecto. Inténtalo de nuevo.";
    }
}

$tiempo_restante = $_SESSION['codigo_2fa_expira'] - time();
$minutos = floor($tiempo_restante / 60);
$segundos = $tiempo_restante % 60;

ob_end_flush();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación 2FA - Health2You</title>
    <link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/style.css">
    <?php wp_head(); ?>
    <style>
        body {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
        }
        
        .verification-container {
            max-width: 550px;
            width: 100%;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(15, 157, 88, 0.15);
            overflow: hidden;
        }
        
        .header-2fa {
            background: linear-gradient(135deg, #0f9d58, #0d8549);
            padding: 40px 30px;
            text-align: center;
            color: white;
        }
        
        .header-2fa .icon {
            font-size: 64px;
            margin-bottom: 16px;
            animation: pulse 2s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .header-2fa h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 600;
        }
        
        .header-2fa p {
            margin: 8px 0 0 0;
            font-size: 14px;
            opacity: 0.9;
        }
        
        .content-2fa {
            padding: 40px 30px;
        }
        
        .email-info {
            background: linear-gradient(135deg, #e8f5e9, #f1f8f4);
            border-left: 4px solid #0f9d58;
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 24px;
        }
        
        .email-info p {
            margin: 0;
            color: #2e7d32;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .email-info strong {
            color: #0d8549;
            font-size: 15px;
        }
        
        .temporizador {
            background: linear-gradient(135deg, #fff9e6, #fff3cd);
            border: 2px solid #ffc107;
            padding: 16px;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 24px;
        }
        
        .temporizador p {
            margin: 0;
            color: #856404;
            font-size: 15px;
            font-weight: 600;
        }
        
        .temporizador #tiempo {
            font-size: 24px;
            font-family: monospace;
            color: #f57c00;
            font-weight: bold;
        }
        
        .codigo-input {
            width: 100%;
            font-size: 36px;
            text-align: center;
            letter-spacing: 16px;
            font-family: 'Courier New', monospace;
            padding: 20px;
            border: 3px solid #e0e0e0;
            border-radius: 12px;
            margin-bottom: 24px;
            transition: all 0.3s;
            background: #f8f9fa;
        }
        
        .codigo-input:focus {
            outline: none;
            border-color: #0f9d58;
            background: white;
            box-shadow: 0 0 0 4px rgba(15, 157, 88, 0.1);
        }
        
        .btn-verificar {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #0f9d58, #0d8549);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-verificar:hover {
            background: linear-gradient(135deg, #0d8549, #0a6b3a);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(15, 157, 88, 0.3);
        }
        
        .btn-verificar:active {
            transform: translateY(0);
        }
        
        .alert-error {
            background: linear-gradient(135deg, #ffebee, #ffcdd2);
            border-left: 4px solid #f44336;
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 24px;
            color: #c62828;
            font-weight: 500;
        }
        
        .help-section {
            margin-top: 30px;
            padding-top: 24px;
            border-top: 2px solid #e8f5e9;
            text-align: center;
        }
        
        .help-section p {
            margin: 0 0 12px 0;
            color: #666;
            font-size: 14px;
        }
        
        .btn-secondary {
            display: inline-block;
            padding: 12px 24px;
            background: white;
            color: #0f9d58;
            border: 2px solid #0f9d58;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            font-size: 14px;
        }
        
        .btn-secondary:hover {
            background: #0f9d58;
            color: white;
        }
        
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #0f9d58;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .back-link:hover {
            color: #0d8549;
            transform: translateX(-5px);
        }
        
        .instructions {
            background: #f8f9fa;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
        }
        
        .instructions ul {
            margin: 0;
            padding-left: 20px;
            color: #666;
            font-size: 13px;
            line-height: 1.8;
        }
        
        .instructions li {
            margin: 4px 0;
        }
    </style>
</head>
<body>

<div style="position: absolute; top: 20px; left: 20px;">
    <a href="<?= get_stylesheet_directory_uri(); ?>/login.php" class="back-link">
        ← Volver al login
    </a>
</div>

<div class="verification-container">
    <div class="header-2fa">
        <div class="icon">🔐</div>
        <h1>Verificación en dos pasos</h1>
        <p>Introduce el código que enviamos a tu email</p>
    </div>
    
    <div class="content-2fa">
        
        <div class="email-info">
            <p>
                📧 Hemos enviado un código de 6 dígitos a:<br>
                <strong><?= htmlspecialchars($email_enmascarado) ?></strong>
            </p>
            <p style="font-size: 12px; margin-top: 8px; opacity: 0.8;">
                Si no lo encuentras, revisa la carpeta de spam
            </p>
        </div>

        <div class="temporizador" id="temporizador">
            <p>⏱️ Código válido por: <span id="tiempo"><?= sprintf("%d:%02d", $minutos, $segundos) ?></span></p>
        </div>

        <?php if ($error): ?>
            <div class="alert-error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <label for="codigo" style="display: block; text-align: center; margin-bottom: 12px; color: #666; font-weight: 600; font-size: 14px;">
                Código de verificación
            </label>
            
            <input type="text" 
                   name="codigo" 
                   id="codigo" 
                   class="codigo-input" 
                   maxlength="6" 
                   pattern="[0-9]{6}" 
                   placeholder="000000"
                   required 
                   autofocus
                   autocomplete="off">
            
            <button type="submit" class="btn-verificar">
                ✅ Verificar código
            </button>
        </form>

        <div class="instructions">
            <ul>
                <li>El código tiene 6 dígitos numéricos</li>
                <li>Es válido solo durante 5 minutos</li>
                <li>Puedes copiarlo directamente del email</li>
                <li>Si expira, deberás volver a iniciar sesión</li>
            </ul>
        </div>

        <div class="help-section">
            <p>¿No recibiste el email?</p>
            <a href="<?= get_stylesheet_directory_uri(); ?>/login.php" class="btn-secondary">
                🔄 Intentar de nuevo
            </a>
        </div>

    </div>
</div>

<script>
// Temporizador countdown
let tiempoRestante = <?= $tiempo_restante ?>;
const temporizadorElement = document.getElementById('tiempo');
const temporizadorBox = document.getElementById('temporizador');

const countdown = setInterval(() => {
    if (tiempoRestante > 0) {
        tiempoRestante--;
        const minutos = Math.floor(tiempoRestante / 60);
        const segundos = tiempoRestante % 60;
        temporizadorElement.textContent = `${minutos}:${segundos.toString().padStart(2, '0')}`;
        
        // Cambiar a rojo cuando queden menos de 30 segundos
        if (tiempoRestante < 30) {
            temporizadorBox.style.background = 'linear-gradient(135deg, #ffebee, #ffcdd2)';
            temporizadorBox.style.borderColor = '#f44336';
            temporizadorElement.style.color = '#c62828';
        }
    } else {
        temporizadorElement.textContent = 'EXPIRADO';
        temporizadorBox.style.background = '#ffcdd2';
        document.getElementById('codigo').disabled = true;
        document.querySelector('.btn-verificar').disabled = true;
        document.querySelector('.btn-verificar').style.opacity = '0.5';
        document.querySelector('.btn-verificar').style.cursor = 'not-allowed';
        clearInterval(countdown);
    }
}, 1000);

// Auto-submit cuando se completen 6 dígitos
document.getElementById('codigo').addEventListener('input', function(e) {
    // Solo permitir números
    this.value = this.value.replace(/[^0-9]/g, '');
    
    // Auto-enviar cuando llegue a 6 dígitos
    if (this.value.length === 6) {
        setTimeout(() => {
            this.form.submit();
        }, 300);
    }
});

// Prevenir pegar texto no numérico
document.getElementById('codigo').addEventListener('paste', function(e) {
    setTimeout(() => {
        this.value = this.value.replace(/[^0-9]/g, '').substring(0, 6);
    }, 10);
});
</script>

<?php wp_footer(); ?>
</body>
</html>
