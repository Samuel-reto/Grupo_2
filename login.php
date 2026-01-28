<?php
ob_start();
if (!defined('ABSPATH')) {
    require_once('../../../wp-load.php');
}
if (!session_id()) session_start();

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

$error = "";

// ============================================
// FUNCIONES 2FA
// ============================================
function generar_codigo_2fa() {
    return str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
}

function enviar_codigo_email($email, $codigo, $nombre) {
    $asunto = "Health2You - Código de verificación";
    $mensaje = "Hola $nombre,\n\n";
    $mensaje .= "Tu código de acceso es: $codigo\n\n";
    $mensaje .= "Este código es válido por 5 minutos.\n\n";
    $mensaje .= "Si no solicitaste este código, ignora este mensaje.\n\n";
    $mensaje .= "Health2You - Sistema de Salud Online";
    
    $headers = "From: Health2You <noreply@health2you.com>\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    return mail($email, $asunto, $mensaje, $headers);
}

// ============================================
// PROCESAR LOGIN
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo = sanitize_text_field($_POST['tipo_usuario'] ?? 'paciente');
    $id   = sanitize_text_field(trim($_POST['identificador'] ?? ''));
    $pass = sanitize_text_field($_POST['password'] ?? '');

    if ($tipo === 'paciente') {
        $paciente = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . H2Y_PACIENTE . " WHERE numero_tsi = %s", $id
        ));

        if ($paciente && password_verify($pass, $paciente->password_hash)) {
            
            // ====== AQUÍ EMPIEZA EL 2FA ======
            
            // Verificar si tiene email
            if (empty($paciente->email)) {
                $error = "Tu cuenta no tiene email registrado. Contacta con soporte.";
            } else {
                // Generar código 2FA
                $codigo = generar_codigo_2fa();
                
                // Guardar en sesión temporalmente
                $_SESSION['codigo_2fa'] = $codigo;
                $_SESSION['codigo_2fa_expira'] = time() + 300; // 5 minutos
                $_SESSION['paciente_temp_id'] = $paciente->paciente_id;
                $_SESSION['paciente_temp_nombre'] = $paciente->nombre . ' ' . $paciente->apellidos;
                $_SESSION['tipo_temp'] = 'paciente';
                $_SESSION['paciente_temp_email'] = $paciente->email;
                
                // Enviar código por email
                $envio_exitoso = enviar_codigo_email($paciente->email, $codigo, $paciente->nombre);
                
                if ($envio_exitoso) {
                    // Redirigir a verificación 2FA
                    $redirect_url = get_stylesheet_directory_uri() . '/verificar_2fa.php';
                    header("Location: $redirect_url");
                    exit;
                } else {
                    $error = "Error al enviar el código. Intenta de nuevo.";
                }
            }
            
        } else {
            $error = "Credenciales de paciente no válidas.";
        }
        
    } else {
        // MÉDICO - Login directo sin 2FA
        $medico = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . H2Y_MEDICO . " WHERE colegiado = %s", $id
        ));

        if ($medico && password_verify($pass, $medico->password_hash)) {
            $_SESSION['h2y_tipo'] = 'medico';
            $_SESSION['h2y_medico_id'] = $medico->medico_id;
            $_SESSION['h2y_medico_nombre'] = $medico->nombre . ' ' . $medico->apellidos;
            
            $redirect_url = get_stylesheet_directory_uri() . '/dashboard_medico.php';
            header("Location: $redirect_url");
            exit;
        } else {
            $error = "Credenciales de profesional no válidas.";
        }
    }
}
ob_end_flush();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health2You - Acceso Seguro (2FA)</title>
    <link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/styles.css">
    <?php wp_head(); ?>
</head>
<body>

<!-- Botón volver -->
<div style="padding: 16px; background: #f5f5f5;">
    <a href="<?= get_stylesheet_directory_uri(); ?>/index.php" style="color: var(--primary); text-decoration: none; font-weight: 600;">
        ← Volver al inicio
    </a>
</div>

<div class="container">
    <div class="left">
        <div class="logo">
            <span>🔐 Health2You</span>
        </div>
        <h1>Acceso seguro con 2FA</h1>
        <p class="tagline">
            Sistema de citas con verificación en dos pasos para proteger tus datos sanitarios.
        </p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="form-group">
                <label for="tipo_usuario">¿Quién accede?</label>
                <select name="tipo_usuario" id="tipo_usuario">
                    <option value="paciente">Paciente (con 2FA)</option>
                    <option value="medico">Profesional sanitario</option>
                </select>
            </div>

            <div class="form-group">
                <label for="identificador">Nº Tarjeta Sanitaria / Nº Colegiado</label>
                <input type="text" name="identificador" id="identificador" required>
            </div>

            <div class="form-group">
                <label for="password">Contraseña / PIN</label>
                <input type="password" name="password" id="password" required>
            </div>

            <button type="submit" class="btn">Iniciar sesión</button>
        </form>

        <div style="margin-top: 20px; text-align: center; padding-top: 16px; border-top: 1px solid #e0e0e0;">
            <p class="small-muted">¿Aún no tienes cuenta?</p>
            
            <div style="display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;">
                <a href="<?= get_stylesheet_directory_uri(); ?>/registro.php" class="btn btn-secondary">
                    👤 Registro paciente
                </a>
                <a href="<?= get_stylesheet_directory_uri(); ?>/registro_medico.php" class="btn btn-secondary">
                    🩺 Registro médico
                </a>
            </div>
        </div>

        <p class="small-muted" style="margin-top: 16px;">
            <strong>Prueba:</strong> TSI <code>CANT390123456789</code> / Password <code>1234</code><br>
            <small>⚠️ Necesitas tener email configurado para recibir el código 2FA</small>
        </p>
    </div>

    <div class="right">
        <h2>🔐 ¿Qué es la verificación en dos pasos (2FA)?</h2>
        <p class="small-muted">
            Para proteger tus datos sanitarios, hemos implementado doble factor de autenticación.
        </p>
        <ul class="helper-list">
            <li><strong>Paso 1:</strong> Introduce tu TSI/Colegiado y contraseña como siempre.</li>
            <li><strong>Paso 2:</strong> Recibirás un código de 6 dígitos en tu email registrado.</li>
            <li><strong>Paso 3:</strong> Introduce el código para acceder (válido 5 minutos).</li>
            <li>Los profesionales sanitarios acceden directamente sin 2FA por eficiencia.</li>
            <li>Nunca compartas el código 2FA con nadie. Health2You nunca te lo pedirá por teléfono.</li>
        </ul>
        <p class="small-muted">
            <strong>Importante:</strong> Asegúrate de tener acceso al email que registraste. Si no lo recuerdas, contacta con soporte.
        </p>
    </div>
</div>
<?php wp_footer(); ?>
</body>
</html>
