<?php
if (!defined('ABSPATH')) require_once('../../../wp-load.php');
if (!session_id()) session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

$error = "";

/*==========================================================
   2FA ACTIVADO (PHPMailer + funciones)
==========================================================*/

$phpmailer_loaded = false;

$autoload_path = get_stylesheet_directory() . '/vendor/autoload.php';
if (file_exists($autoload_path)) {
    require_once $autoload_path;
    $phpmailer_loaded = true;
} else {
    require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
    require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
    require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
    $phpmailer_loaded = true;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

function generar_codigo_2fa() {
    return str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
}

function enviar_codigo_email($email, $codigo, $nombre) {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($email, $nombre);
        $mail->isHTML(true);
        $mail->Subject = '🔐 Tu código de verificación - Health2You';

        $mail->Body = '
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Arial, sans-serif; background-color: #f5f7fa;">
            <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f5f7fa; padding: 40px 20px;">
                <tr>
                    <td align="center">
                        <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); overflow: hidden;">

                            <tr>
                                <td style="background: linear-gradient(135deg, #0f9d58 0%, #0d8549 100%); padding: 40px 30px; text-align: center;">
                                    <h1 style="margin: 0; color: #ffffff; font-size: 32px; font-weight: 600; letter-spacing: -0.5px;">
                                        🏥 Health2You
                                    </h1>
                                    <p style="margin: 10px 0 0 0; color: rgba(255,255,255,0.9); font-size: 16px;">
                                        Verificación de seguridad
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <td style="padding: 40px 30px;">
                                    <p style="margin: 0 0 20px 0; font-size: 18px; color: #2c3e50; line-height: 1.6;">
                                        Hola <strong style="color: #0f9d58;">' . htmlspecialchars($nombre) . '</strong>,
                                    </p>

                                    <p style="margin: 0 0 30px 0; font-size: 15px; color: #5a6c7d; line-height: 1.6;">
                                        Has solicitado acceder a tu cuenta de Health2You. Para completar el inicio de sesión, utiliza el siguiente código de verificación:
                                    </p>

                                    <table width="100%" cellpadding="0" cellspacing="0">
                                        <tr>
                                            <td align="center" style="padding: 30px 0;">
                                                <div style="background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%); border: 3px solid #0f9d58; border-radius: 12px; padding: 30px 40px; display: inline-block;">
                                                    <p style="margin: 0 0 8px 0; font-size: 14px; color: #2e7d32; font-weight: 600; text-transform: uppercase; letter-spacing: 1px;">
                                                        Tu código de verificación
                                                    </p>
                                                    <p style="margin: 0; font-size: 42px; font-weight: bold; color: #0f9d58; letter-spacing: 4px; font-family: \'Courier New\', monospace;">
                                                        ' . $codigo . '
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                    </table>

                                    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #fff9e6; border-left: 4px solid #ffc107; border-radius: 8px; margin-top: 30px;">
                                        <tr>
                                            <td style="padding: 20px;">
                                                <p style="margin: 0 0 10px 0; font-size: 14px; color: #856404; font-weight: 600;">
                                                    ⏱️ Importante:
                                                </p>
                                                <ul style="margin: 0; padding-left: 20px; font-size: 14px; color: #856404; line-height: 1.8;">
                                                    <li>Este código es <strong>válido por 5 minutos</strong></li>
                                                    <li>No compartas este código con nadie</li>
                                                    <li>Si no solicitaste este código, ignora este email</li>
                                                </ul>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>

                            <tr>
                                <td style="background-color: #f8f9fa; padding: 30px; text-align: center; border-top: 1px solid #e9ecef;">
                                    <p style="margin: 0 0 10px 0; font-size: 16px; color: #2c3e50; font-weight: 600;">
                                        Health2You - Tu salud, nuestra prioridad
                                    </p>
                                    <p style="margin: 0; font-size: 13px; color: #95a5a6; line-height: 1.6;">
                                        © ' . date('Y') . ' Health2You. Todos los derechos reservados.
                                    </p>
                                </td>
                            </tr>

                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>';

        $mail->AltBody = "Hola $nombre,\n\nTu código de verificación de Health2You es: $codigo\n\nEste código es válido por 5 minutos.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("2FA Error: " . $mail->ErrorInfo);
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo = sanitize_text_field($_POST['tipo_usuario'] ?? 'paciente');
    $id   = sanitize_text_field(trim($_POST['identificador'] ?? ''));
    $pass = $_POST['password'] ?? '';

    if (empty($id) || empty($pass)) {
        $error = "Completa todos los campos.";

    } elseif ($tipo === 'paciente') {
        // =========================================================
        // LOGIN PACIENTE (HÍBRIDO: CON 2FA SI HAY MAIL, DIRECTO SI NO)
        // =========================================================
        $paciente = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . H2Y_PACIENTE . " WHERE numero_tsi = %s",
            $id
        ));

        if (!$paciente) {
            $error = "TSI no encontrado.";
        } elseif (!password_verify($pass, $paciente->password_hash)) {
            $error = "Contraseña incorrecta.";
        } else {
            // Contraseña correcta. Ahora decidimos si usar 2FA o no.
            
            if (!empty($paciente->email)) {
                // ------------------------------------------
                // TIENE EMAIL -> USAR 2FA
                // ------------------------------------------
                $codigo = generar_codigo_2fa();

                $_SESSION['codigo_2fa'] = $codigo;
                $_SESSION['codigo_2fa_expira'] = time() + 300;
                $_SESSION['paciente_temp_id'] = $paciente->paciente_id;
                $_SESSION['paciente_temp_nombre'] = $paciente->nombre . ' ' . $paciente->apellidos;
                $_SESSION['paciente_temp_email'] = $paciente->email;
                $_SESSION['tipo_temp'] = 'paciente';

                // Enviar código por email
                if (enviar_codigo_email($paciente->email, $codigo, $_SESSION['paciente_temp_nombre'])) {
                    wp_safe_redirect(get_stylesheet_directory_uri() . '/verificar_2fa.php');
                    exit;
                } else {
                    $error = "Error al enviar email 2FA. Revisa spam o contacta soporte.";
                }

            } else {
                // ------------------------------------------
                // NO TIENE EMAIL -> LOGIN DIRECTO (SKIP 2FA)
                // ------------------------------------------
                $_SESSION['h2y_tipo'] = 'paciente';
                $_SESSION['h2y_user_id'] = $paciente->paciente_id;
                $_SESSION['h2y_user_nombre'] = $paciente->nombre . ' ' . $paciente->apellidos;
                
                // Variables de compatibilidad
                $_SESSION['h2y_pacienteid'] = $paciente->paciente_id;
                $_SESSION['h2y_pacientenombre'] = $paciente->nombre . ' ' . $paciente->apellidos;

                wp_safe_redirect(get_stylesheet_directory_uri() . '/dashboard.php');
                exit;
            }
        }

    } elseif ($tipo === 'medico') {
        // =========================
        // LOGIN MÉDICO (sin 2FA)
        // =========================
        $medico = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . H2Y_MEDICO . " WHERE colegiado = %s",
            $id
        ));

        if ($medico && password_verify($pass, $medico->password_hash)) {
            $_SESSION['h2y_tipo'] = 'medico';
            $_SESSION['h2y_user_id'] = $medico->medico_id;
            $_SESSION['h2y_user_nombre'] = $medico->nombre . ' ' . $medico->apellidos;
            $_SESSION['h2y_medico_id'] = $medico->medico_id; // Compatibilidad
            $_SESSION['h2y_medico_nombre'] = $medico->nombre . ' ' . $medico->apellidos; // Compatibilidad
            $_SESSION['h2y_especialidad'] = $medico->especialidad;

            wp_safe_redirect(get_stylesheet_directory_uri() . '/dashboard.php');
            exit;
        } else {
            $error = "Colegiado/contraseña incorrectos.";
        }

    } elseif ($tipo === 'administrativo') {
        // =========================
        // LOGIN ADMINISTRATIVO (sin 2FA)
        // =========================
        $admin = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . H2Y_ADMINISTRATIVO . " WHERE email = %s",
            $id
        ));

        if ($admin && password_verify($pass, $admin->password_hash)) {
            $_SESSION['h2y_tipo'] = 'administrativo';
            $_SESSION['h2y_user_id'] = $admin->administrativo_id;
            $_SESSION['h2y_user_nombre'] = $admin->nombre . ' ' . $admin->apellidos;
            $_SESSION['h2y_admin_id'] = $admin->administrativo_id; // Compatibilidad
            $_SESSION['h2y_admin_nombre'] = $admin->nombre . ' ' . $admin->apellidos; // Compatibilidad

            wp_safe_redirect(get_stylesheet_directory_uri() . '/dashboard.php');
            exit;
        } else {
            $error = "Email/contraseña incorrectos.";
        }

    } else {
        $error = "Tipo de usuario no válido.";
    }
}
?>

<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔐 Login - Health2You</title>
    <link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/styles.css">
    <?php wp_head(); ?>

       <!-- PWA Health2You -->
<link rel="manifest" href="<?= get_stylesheet_directory_uri(); ?>/manifest.json">
<meta name="theme-color" content="#0f9d58">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Health2You">
<link rel="apple-touch-icon" href="<?= get_stylesheet_directory_uri(); ?>/icon-192.png">

<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    navigator.serviceWorker.register('<?= get_stylesheet_directory_uri(); ?>/sw.js')
    .then(function(registration) {
      console.log('PWA ServiceWorker registrado correctamente');
    }).catch(function(error) {
      console.log('Error al registrar PWA ServiceWorker:', error);
    });
  });
}
</script>
   
</head>
<body>

<div style="padding: 16px; background: #f5f5f5;">
    <a href="<?= get_stylesheet_directory_uri(); ?>/index.php" style="color: var(--primary); text-decoration: none; font-weight: 600;">
        ← Volver al inicio
    </a>
</div>

<div class="container">
    <div class="left">
        <div class="logo">
            <span>🔐 Login</span>
        </div>
        <h1>Iniciar sesión</h1>
        <p class="tagline">Accede a tu cuenta de Health2You.</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="form-group">
                <label for="tipo_usuario">¿Quién eres? *</label>
                <select name="tipo_usuario" id="tipo_usuario" required>
                    <option value="paciente">👤 Paciente</option>
                    <option value="medico">🩺 Médico</option>
                    <option value="administrativo">💼 Administrativo</option>
                </select>
            </div>

            <div class="form-group">
                <label for="identificador" id="label_identificador">Identificador (TSI) *</label>
                <input type="text" name="identificador" id="identificador"
                       placeholder="CANT390123456789"
                       maxlength="100" required>
                <small class="small-muted" id="hint_identificador">
                    TSI para pacientes, Colegiado para médicos, Email para administrativos
                </small>
            </div>

            <div class="form-group">
                <label for="password">Contraseña *</label>
                <input type="password" name="password" id="password" minlength="4" required>
            </div>

            <button type="submit" class="btn">🔓 Iniciar sesión</button>
        </form>

        <div style="margin-top: 24px; text-align: center;">
            <p class="small-muted">¿No tienes cuenta?</p>
            <a href="<?= get_stylesheet_directory_uri(); ?>/registro.php" class="btn btn-secondary">
                Crear cuenta nueva
            </a>
        </div>
    </div>

    <div class="right">
        <h2>Acceso seguro</h2>
        <p class="small-muted">Accede al sistema según tu perfil:</p>
        <ul class="helper-list">
            <li>✅ <strong>Paciente:</strong> TSI + contraseña (2FA si tienes email)</li>
            <li>✅ <strong>Médico:</strong> Número colegiado + contraseña</li>
            <li>✅ <strong>Administrativo:</strong> Email corporativo + contraseña</li>
        </ul>

        <h3 style="margin-top: 24px;">Seguridad</h3>
        <p class="small-muted">
            🔒 Autenticación adaptada para personas mayores.<br><br>
            Tus datos están protegidos según LOPD y RGPD.
        </p>

        <div style="background: #fff9e6; padding: 16px; border-radius: 8px; margin-top: 20px; border-left: 4px solid #ffc107;">
            <p style="margin: 0; font-size: 14px; color: #856404;">
                <strong>💡 Nota:</strong><br>
                Si no tienes correo electrónico, entrarás directamente con tu contraseña.
            </p>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tipoSelect = document.getElementById('tipo_usuario');
    const labelId = document.getElementById('label_identificador');
    const inputId = document.getElementById('identificador');
    const hintId = document.getElementById('hint_identificador');

    function actualizarCampos() {
        const tipo = tipoSelect.value;

        if (tipo === 'paciente') {
            labelId.textContent = 'Número TSI *';
            inputId.placeholder = 'CANT390123456789';
            hintId.textContent = 'Introduce tu número de Tarjeta Sanitaria Individual';
        } else if (tipo === 'medico') {
            labelId.textContent = 'Número de Colegiado *';
            inputId.placeholder = '123456 o CO-78901';
            hintId.textContent = 'Introduce tu número de colegiado profesional';
        } else if (tipo === 'administrativo') {
            labelId.textContent = 'Email corporativo *';
            inputId.placeholder = 'admin@health2you.com';
            hintId.textContent = 'Introduce tu email de administrativo';
        }
    }

    tipoSelect.addEventListener('change', actualizarCampos);
    actualizarCampos(); // Ejecutar al cargar
});
</script>

<?php wp_footer(); ?>
</body>
</html>

