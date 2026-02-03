<?php
if (!defined('ABSPATH')) require_once('../../../wp-load.php');

// IMPORTANTE: Iniciar sesión ANTES de cualquier output
if (!session_id()) {
    session_start();
}

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

$error = "";

// Debug: Ver qué hay en la sesión (QUITAR EN PRODUCCIÓN)
// error_log("DEBUG 2FA - SESSION: " . print_r($_SESSION, true));

// Verificar que hay datos temporales en sesión
if (!isset($_SESSION['codigo_2fa']) || !isset($_SESSION['paciente_temp_id'])) {
    // error_log("ERROR 2FA: No hay datos temporales en sesión");
    header('Location: ' . get_stylesheet_directory_uri() . '/login.php');
    exit;
}

// Verificar si el código ha expirado
if (isset($_SESSION['codigo_2fa_expira']) && time() > $_SESSION['codigo_2fa_expira']) {
    // error_log("ERROR 2FA: Código expirado");
    // Limpiar datos temporales
    unset($_SESSION['codigo_2fa']);
    unset($_SESSION['codigo_2fa_expira']);
    unset($_SESSION['paciente_temp_id']);
    unset($_SESSION['paciente_temp_nombre']);
    unset($_SESSION['paciente_temp_email']);
    unset($_SESSION['tipo_temp']);
    
    $error = "El código ha expirado. Por favor, inicia sesión nuevamente.";
}

// Procesar verificación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    $codigo_ingresado = sanitize_text_field($_POST['codigo'] ?? '');
    
    if (empty($codigo_ingresado)) {
        $error = "Por favor, introduce el código.";
    } elseif ($codigo_ingresado !== $_SESSION['codigo_2fa']) {
        $error = "Código incorrecto. Verifica e inténtalo de nuevo.";
        // error_log("ERROR 2FA: Código incorrecto. Esperado: " . $_SESSION['codigo_2fa'] . ", Recibido: " . $codigo_ingresado);
    } else {
        // ✅ Código correcto - Establecer sesión definitiva
        
        // Guardar IDs temporales antes de limpiar
        $paciente_id = $_SESSION['paciente_temp_id'];
        $paciente_nombre = $_SESSION['paciente_temp_nombre'];
        
        // Limpiar datos temporales primero
        unset($_SESSION['codigo_2fa']);
        unset($_SESSION['codigo_2fa_expira']);
        unset($_SESSION['paciente_temp_id']);
        unset($_SESSION['paciente_temp_nombre']);
        unset($_SESSION['paciente_temp_email']);
        unset($_SESSION['tipo_temp']);
        
        // Establecer sesión definitiva
        $_SESSION['h2y_tipo'] = 'paciente';
        $_SESSION['h2y_user_id'] = $paciente_id;
        $_SESSION['h2y_user_nombre'] = $paciente_nombre;
        
        // Variables de compatibilidad
        $_SESSION['h2y_pacienteid'] = $paciente_id;
        $_SESSION['h2y_pacientenombre'] = $paciente_nombre;
        
        // IMPORTANTE: Forzar escritura de la sesión
        session_write_close();
        
        // error_log("SUCCESS 2FA: Sesión establecida para paciente ID: " . $paciente_id);
        // error_log("SESSION FINAL: " . print_r($_SESSION, true));
        
        // Redirigir al dashboard usando header directo
        $redirect_url = get_stylesheet_directory_uri() . '/dashboard.php';
        header('Location: ' . $redirect_url);
        exit;
    }
}

$email_oculto = '';
if (isset($_SESSION['paciente_temp_email'])) {
    $email = $_SESSION['paciente_temp_email'];
    $partes = explode('@', $email);
    if (count($partes) === 2) {
        $usuario = $partes[0];
        $dominio = $partes[1];
        $usuario_oculto = substr($usuario, 0, 2) . str_repeat('*', max(0, strlen($usuario) - 2));
        $email_oculto = $usuario_oculto . '@' . $dominio;
    } else {
        $email_oculto = $email;
    }
}
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
            letter-spacing: 8px;
            font-family: 'Courier New', monospace;
            font-weight: bold;
            padding: 16px;
        }
        .timer {
            font-size: 18px;
            color: #e74c3c;
            font-weight: 600;
            margin-top: 16px;
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
    <div class="left">
        <div class="logo">
            <span>🔐 Verificación 2FA</span>
        </div>
        <h1>Código de Verificación</h1>
        <p class="tagline">
            Hemos enviado un código de 6 dígitos a:<br>
            <strong><?= htmlspecialchars($email_oculto) ?></strong>
        </p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" id="form2fa">
            <div class="form-group">
                <label for="codigo">Introduce el código *</label>
                <input type="text" 
                       name="codigo" 
                       id="codigo" 
                       class="codigo-input"
                       placeholder="000000"
                       maxlength="6" 
                       pattern="[0-9]{6}"
                       inputmode="numeric"
                       autocomplete="off"
                       required 
                       autofocus>
                <small class="small-muted">Código de 6 dígitos</small>
            </div>

            <div id="timer" class="timer"></div>

            <button type="submit" class="btn">✓ Verificar Código</button>
        </form>

        <div style="margin-top: 24px; text-align: center;">
            <p class="small-muted">¿No recibiste el código?</p>
            <a href="<?= get_stylesheet_directory_uri(); ?>/login.php" class="btn btn-secondary">
                Solicitar nuevo código
            </a>
        </div>
    </div>

    <div class="right">
        <h2>Verificación en dos pasos</h2>
        <p class="small-muted">
            Por tu seguridad, necesitamos verificar que eres tú.
        </p>
        <ul class="helper-list">
            <li>✅ Revisa tu bandeja de entrada</li>
            <li>✅ También revisa spam/correo no deseado</li>
            <li>✅ El código expira en 5 minutos</li>
            <li>✅ Código de un solo uso</li>
        </ul>

        <div style="background: #fff3cd; padding: 16px; border-radius: 8px; margin-top: 20px; border-left: 4px solid #ffc107;">
            <p style="margin: 0; font-size: 14px; color: #856404;">
                <strong>💡 Consejo:</strong><br>
                Si no recibes el email en 1-2 minutos, vuelve al login y solicita un nuevo código.
            </p>
        </div>

        <div style="background: #f8d7da; padding: 16px; border-radius: 8px; margin-top: 16px; border-left: 4px solid #dc3545;">
            <p style="margin: 0; font-size: 14px; color: #721c24;">
                <strong>⚠️ Importante:</strong><br>
                Nunca compartas este código con nadie. Health2You nunca te pedirá este código por teléfono o email.
            </p>
        </div>
    </div>
</div>

<script>
// Timer de expiración
<?php if (isset($_SESSION['codigo_2fa_expira'])): ?>
let expiraEn = <?= $_SESSION['codigo_2fa_expira'] ?>;
let timerElement = document.getElementById('timer');

function actualizarTimer() {
    let ahora = Math.floor(Date.now() / 1000);
    let restante = expiraEn - ahora;
    
    if (restante <= 0) {
        timerElement.innerHTML = '⏰ El código ha expirado. <a href="<?= get_stylesheet_directory_uri(); ?>/login.php">Solicitar nuevo código</a>';
        timerElement.style.color = '#e74c3c';
        return;
    }
    
    let minutos = Math.floor(restante / 60);
    let segundos = restante % 60;
    timerElement.innerHTML = `⏱️ El código expira en: ${minutos}:${segundos.toString().padStart(2, '0')}`;
    
    if (restante < 60) {
        timerElement.style.color = '#e74c3c';
    } else {
        timerElement.style.color = '#27ae60';
    }
    
    setTimeout(actualizarTimer, 1000);
}

actualizarTimer();
<?php endif; ?>

// Auto-submit cuando se completan 6 dígitos
document.getElementById('codigo').addEventListener('input', function(e) {
    // Solo permitir números
    this.value = this.value.replace(/[^0-9]/g, '');
    
    // Auto-submit si tiene 6 dígitos
    if (this.value.length === 6) {
        // Pequeño delay para mejor UX
        setTimeout(() => {
            document.getElementById('form2fa').submit();
        }, 300);
    }
});

// Focus automático en el campo
document.getElementById('codigo').focus();
</script>

<?php wp_footer(); ?>
</body>
</html>
