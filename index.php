<?php
ob_start();
if (!defined('ABSPATH')) exit;
if (!session_id()) session_start();

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo = sanitize_text_field($_POST['tipo_usuario'] ?? 'paciente');
    $id   = sanitize_text_field(trim($_POST['identificador'] ?? ''));
    $pass = sanitize_text_field($_POST['password'] ?? '');

    if ($tipo === 'paciente') {
        $paciente = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . H2Y_PACIENTE . " WHERE numero_tsi = %s", $id
        ));

        if ($paciente && password_verify($pass, $paciente->password_hash)) {
            $_SESSION['h2y_tipo'] = 'paciente';
            $_SESSION['h2y_paciente_id'] = $paciente->paciente_id;
            $_SESSION['h2y_paciente_nombre'] = $paciente->nombre . ' ' . $paciente->apellidos;
            
            // REDIRECT DIRECTO AL ARCHIVO
            $redirect_url = get_stylesheet_directory_uri() . '/dashboard_paciente.php';
            header("Location: $redirect_url");
            exit;
        } else {
            $error = "Credenciales de paciente no válidas.";
        }
    } else {
        $medico = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . H2Y_MEDICO . " WHERE colegiado = %s", $id
        ));

        if ($medico && password_verify($pass, $medico->password_hash)) {
            $_SESSION['h2y_tipo'] = 'medico';
            $_SESSION['h2y_medico_id'] = $medico->medico_id;
            $_SESSION['h2y_medico_nombre'] = $medico->nombre . ' ' . $medico->apellidos;
            
            // REDIRECT DIRECTO AL ARCHIVO
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
    <title>Health2You - Acceso</title>
    <link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/style.css">
    <?php wp_head(); ?>
</head>
<body>
<div class="container">
    <div class="left">
        <div class="logo">
            <span>MiSalud@SCS</span>
        </div>
        <h1>Acceso a tu salud online</h1>
        <p class="tagline">
            Gestiona tus citas de Atención Primaria de forma segura, rápida y disponible 24/7.
        </p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="form-group">
                <label for="tipo_usuario">¿Quién accede?</label>
                <select name="tipo_usuario" id="tipo_usuario">
                    <option value="paciente">Paciente</option>
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


    <div class="right">
        <h2>¿Cómo iniciar sesión correctamente?</h2>
        <p class="small-muted">
            Para garantizar la seguridad de tus datos sanitarios sigue estas indicaciones.
        </p>
        <ul class="helper-list">
            <li>Ten a mano tu tarjeta sanitaria física o virtual para consultar el número TSI.</li>
            <li>Escribe el número TSI completo, sin espacios y respetando letras y números.</li>
            <li>Introduce tu contraseña personal exactamente como la creaste al registrarte.</li>
            <li>Si eres profesional, accede con tu número de colegiado y tu clave corporativa.</li>
            <li>Nunca compartas tus credenciales y cierra sesión al terminar, sobre todo en equipos públicos.</li>
        </ul>
        <p class="small-muted">
            Si tienes problemas de acceso, contacta con el servicio de soporte técnico del SCS o con tu centro de salud.
        </p>
    </div>
</div>
<?php wp_footer(); ?>
</body>
</html>

