<?php
ob_start();
if (!defined('ABSPATH')) {
    require_once('../../../wp-load.php');
}
if (!session_id()) session_start();

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

$error = "";
$mensaje = "";

// Verificar que venga de un login válido
if (!isset($_SESSION['codigo_2fa']) || !isset($_SESSION['paciente_temp_id'])) {
    header("Location: " . get_stylesheet_directory_uri() . "/login.php");
    exit;
}

// Mostrar email enmascarado para seguridad
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
    
    // Verificar expiración
    if (time() > $_SESSION['codigo_2fa_expira']) {
        $error = "⏰ Código expirado. Vuelve a iniciar sesión.";
        
        // Limpiar sesión
        unset($_SESSION['codigo_2fa']);
        unset($_SESSION['codigo_2fa_expira']);
        unset($_SESSION['paciente_temp_id']);
        unset($_SESSION['paciente_temp_nombre']);
        unset($_SESSION['tipo_temp']);
        unset($_SESSION['paciente_temp_email']);
        
    } elseif ($codigo_ingresado === $_SESSION['codigo_2fa']) {
        // ✅ Código correcto - Completar login
        $_SESSION['h2y_tipo'] = 'paciente';
        $_SESSION['h2y_paciente_id'] = $_SESSION['paciente_temp_id'];
        $_SESSION['h2y_paciente_nombre'] = $_SESSION['paciente_temp_nombre'];
        
        // Limpiar datos temporales
        unset($_SESSION['codigo_2fa']);
        unset($_SESSION['codigo_2fa_expira']);
        unset($_SESSION['paciente_temp_id']);
        unset($_SESSION['paciente_temp_nombre']);
        unset($_SESSION['tipo_temp']);
        unset($_SESSION['paciente_temp_email']);
        
        // Redirigir al dashboard
        $redirect_url = get_stylesheet_directory_uri() . '/dashboard_paciente.php';
        header("Location: $redirect_url");
        exit;
        
    } else {
        $error = "❌ Código incorrecto. Inténtalo de nuevo.";
    }
}

// Calcular tiempo restante
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
    <link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/styles.css">
    <?php wp_head(); ?>
    <style>
        .codigo-input {
            font-size: 32px;
            text-align: center;
            letter-spacing: 10px;
            font-family: monospace;
            padding: 20px;
        }
        .temporizador {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            margin: 16px 0;
            font-weight: 600;
        }
        .email-info {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 12px;
            border-radius: 4px;
            margin: 16px 0;
        }
    </style>
</head>
<body>

<div style="padding: 16px; background: #f5f5f5;">
    <a href="<?= get_stylesheet_directory_uri(); ?>/login.php" style="color: var(--primary); text-decoration: none; font-weight: 600;">
        ← Volver al login
    </a>
</div>

<div class="container">
    <div style="max-width: 600px; margin: 40px auto; padding: 40px; background: white; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
        
        <div style="text-align: center; margin-bottom: 30px;">
            <div style="font-size: 64px; margin-bottom: 16px;">🔐</div>
            <h1 style="margin: 0;">Verificación en dos pasos</h1>
        </div>

        <div class="email-info">
            📧 Hemos enviado un código de 6 dígitos a:<br>
            <strong><?= htmlspecialchars($email_enmascarado) ?></strong>
        </div>

        <div class="temporizador" id="temporizador">
            ⏱️ Código válido por: <span id="tiempo"><?= sprintf("%d:%02d", $minutos, $segundos) ?></span>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" style="margin-top: 24px;">
            <div class="form-group">
                <label for="codigo" style="text-align: center; display: block;">Código de verificación</label>
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
                <small style="display: block; text-align: center; margin-top: 8px; color: #666;">
                    Introduce los 6 dígitos recibidos por email
                </small>
            </div>

            <button type="submit" class="btn" style="width: 100%; padding: 16px; font-size: 18px;">
                ✅ Verificar código
            </button>
        </form>

        <div style="margin-top: 30px; text-align: center; padding-top: 20px; border-top: 1px solid #e0e0e0;">
            <p class="small-muted">¿No recibiste el email?</p>
            <ul style="list-style: none; padding: 0; margin: 12px 0; text-align: left; display: inline-block;">
                <li style="margin: 8px 0;">✓ Revisa la carpeta de spam/correo no deseado</li>
                <li style="margin: 8px 0;">✓ Espera 1-2 minutos (puede tardar)</li>
                <li style="margin: 8px 0;">✓ Verifica que el email registrado sea correcto</li>
            </ul>
            <br>
            <a href="<?= get_stylesheet_directory_uri(); ?>/login.php" class="btn btn-secondary" style="margin-top: 12px;">
                🔄 Volver a intentar login
            </a>
        </div>

    </div>
</div>

<script>
// Temporizador countdown
let tiempoRestante = <?= $tiempo_restante ?>;
const temporizadorElement = document.getElementById('tiempo');

setInterval(() => {
    if (tiempoRestante > 0) {
        tiempoRestante--;
        const minutos = Math.floor(tiempoRestante / 60);
        const segundos = tiempoRestante % 60;
        temporizadorElement.textContent = `${minutos}:${segundos.toString().padStart(2, '0')}`;
        
        // Cambiar color cuando queden menos de 30 segundos
        if (tiempoRestante < 30) {
            document.getElementById('temporizador').style.background = '#ffebee';
            document.getElementById('temporizador').style.borderColor = '#f44336';
            document.getElementById('temporizador').style.color = '#c62828';
        }
    } else {
        temporizadorElement.textContent = '⏰ EXPIRADO';
        document.getElementById('temporizador').style.background = '#ffcdd2';
        document.getElementById('codigo').disabled = true;
    }
}, 1000);

// Auto-submit cuando se ingresen 6 dígitos
document.getElementById('codigo').addEventListener('input', function(e) {
    if (this.value.length === 6 && /^\d{6}$/.test(this.value)) {
        this.form.submit();
    }
});
</script>

<?php wp_footer(); ?>
</body>
</html>
