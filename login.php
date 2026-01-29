<?php
if (!defined('ABSPATH')) require_once('../../../wp-load.php');
if (!session_id()) session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

$error = "";

/* ==========================================================
   2FA DESACTIVADO (PHPMailer + funciones)
   Si algún día lo reactivas, descomenta todo este bloque.
==========================================================

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
    global $phpmailer_loaded;
    if (!$phpmailer_loaded) return false;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'health2you.asir2@gmail.com';

        // IMPORTANTE: no dejes credenciales aquí, usa config.php o variables de entorno
        // $mail->Password = 'TU_APP_PASSWORD';

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom('health2you.asir2@gmail.com', 'Health2You');
        $mail->addAddress($email, $nombre);
        $mail->isHTML(true);
        $mail->Subject = 'Health2You - Código 2FA';
        $mail->Body = "Tu código: <b>$codigo</b>";
        $mail->AltBody = "Tu código: $codigo";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("2FA Error: " . $mail->ErrorInfo);
        return false;
    }
}

========================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo = sanitize_text_field($_POST['tipo_usuario'] ?? 'paciente');
    $id   = sanitize_text_field(trim($_POST['identificador'] ?? ''));
    $pass = $_POST['password'] ?? '';

    if (empty($id) || empty($pass)) {
        $error = "Completa todos los campos.";

    } elseif ($tipo === 'paciente') {

        // =========================
        // LOGIN NORMAL PACIENTE (SIN 2FA)
        // =========================
        $paciente = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . H2Y_PACIENTE . " WHERE numero_tsi = %s",
            $id
        ));

        if (!$paciente) {
            $error = "TSI no encontrado.";
        } elseif (!password_verify($pass, $paciente->password_hash)) {
            $error = "Contraseña incorrecta.";
        } else {
            // ✅ Sesión paciente (final)
            $_SESSION['h2y_tipo'] = 'paciente';
            $_SESSION['h2y_paciente_id'] = $paciente->paciente_id;
            $_SESSION['h2y_paciente_nombre'] = $paciente->nombre . ' ' . $paciente->apellidos;

            // Redirección a dashboard paciente
            wp_safe_redirect(get_stylesheet_directory_uri() . '/dashboard_paciente.php');
            exit;
        }

        /* =========================
           2FA DESACTIVADO - BLOQUE ORIGINAL
           (si lo reactivas, sustituye el "else" de arriba por esto)
        =========================
        } elseif (empty($paciente->email)) {
            $error = "Email requerido para 2FA. Regístrate de nuevo.";
        } else {
            $codigo = generar_codigo_2fa();
            $_SESSION['h2y_2fa'] = [
                'codigo' => $codigo,
                'expira' => time() + 300,
                'paciente_id' => $paciente->paciente_id,
                'nombre' => $paciente->nombre . ' ' . $paciente->apellidos
            ];

            if (enviar_codigo_email($paciente->email, $codigo, $_SESSION['h2y_2fa']['nombre'])) {
                wp_safe_redirect(get_stylesheet_directory_uri() . '/verificar_2fa.php');
                exit;
            } else {
                $error = "Error email 2FA. Revisa spam.";
            }
        }
        ========================= */

    } else {

        // =========================
        // MÉDICO (directo)
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
        <h2>Acceso</h2>
        <p class="small-muted">Pacientes y profesionales acceden con su identificador y contraseña.</p>
        <ul class="helper-list">
            <li>Paciente: TSI + contraseña</li>
            <li>Médico: colegiado + contraseña</li>
        </ul>

        <p class="small-muted" style="margin-top: 16px;">
            (2FA desactivado temporalmente)
        </p>
    </div>
</div>

<?php wp_footer(); ?>
</body>
</html>


