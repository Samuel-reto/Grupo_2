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
                                    
                                    <p style="margin: 30px 0 0 0; font-size: 14px; color: #7f8c8d; line-height: 1.6;">
                                        Si tienes problemas o no solicitaste este código, contacta con nuestro equipo de soporte.
                                    </p>
                                </td>
                            </tr>
                            
                            <tr>
                                <td style="background-color: #f8f9fa; padding: 30px; text-align: center; border-top: 1px solid #e9ecef;">
                                    <p style="margin: 0 0 10px 0; font-size: 16px; color: #2c3e50; font-weight: 600;">
                                        Health2You - Tu salud, nuestra prioridad
                                    </p>
                                    <p style="margin: 0; font-size: 13px; color: #95a5a6; line-height: 1.6;">
                                        Este es un mensaje automático, por favor no respondas a este correo.<br>
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
        
        $mail->AltBody = "Hola $nombre,\n\nTu código de verificación de Health2You es: $codigo\n\nEste código es válido por 5 minutos.\n\nSi no solicitaste este código, ignora este email.";

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

    // =========================
    // LOGIN PACIENTE CON 2FA
    // =========================
    $paciente = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . H2Y_PACIENTE . " WHERE numero_tsi = %s",
        $id
    ));

    if (!$paciente) {
        $error = "TSI no encontrado.";
    } elseif (!password_verify($pass, $paciente->password_hash)) {
        $error = "Contraseña incorrecta.";
    } elseif (empty($paciente->email)) {
        $error = "Email requerido para 2FA. Regístrate de nuevo.";
    } else {
        // Generar código y guardar en sesión
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
    }

} else {


        // =========================
        // MÉDICO (directo, sin 2FA)
        // =========================
        $medico = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . H2Y_MEDICO . " WHERE colegiado = %s",
            $id
        ));

        if ($medico && password_verify($pass, $medico->password_hash)) {
            $_SESSION['h2y_tipo'] = 'medico';
            $_SESSION['h2y_medico_id'] = $medico->medico_id;
            $_SESSION['h2y_medico_nombre'] = $medico->nombre . ' ' . $medico->apellidos;

            wp_safe_redirect(get_stylesheet_directory_uri() . '/dashboard_medico.php');
            exit;
        } else {
            $error = "Colegiado/contraseña incorrectos.";
        }
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
                    <option value="medico">🩺 Profesional médico</option>
                </select>
            </div>

            <div class="form-group">
                <label for="identificador">Identificador (TSI/Colegiado) *</label>
                <input type="text" name="identificador" id="identificador"
                       placeholder="CANT390123456789"
                       maxlength="20" required>
                <small class="small-muted">TSI para pacientes, Colegiado para médicos</small>
            </div>

            <div class="form-group">
                <label for="password">Contraseña *</label>
                <input type="password" name="password" id="password" minlength="4" required>
            </div>

            <button type="submit" class="btn">🔒 Iniciar sesión</button>
        </form>

        <div style="margin-top: 24px; text-align: center;">
            <p class="small-muted">¿No tienes cuenta?</p>
            <div style="display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;">
                <a href="<?= get_stylesheet_directory_uri(); ?>/registro.php" class="btn btn-secondary">
                    Registro paciente
                </a>
                <a href="<?= get_stylesheet_directory_uri(); ?>/registro_medico.php" class="btn btn-secondary">
                    Registro médico
                </a>
            </div>
        </div>
    </div>

    <div class="right">
        <h2>Acceso seguro</h2>
        <p class="small-muted">Pacientes y profesionales acceden con su identificador y contraseña.</p>
        <ul class="helper-list">
            <li>✅ Paciente: TSI + contraseña (con 2FA)</li>
            <li>✅ Médico: colegiado + contraseña</li>
        </ul>

        <p class="small-muted" style="margin-top: 16px;">
            🔒 Sistema 2FA activo para pacientes
        </p>
    </div>
</div>

<?php wp_footer(); ?>
</body>
</html>
