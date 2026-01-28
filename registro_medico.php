<?php
if (!defined('ABSPATH')) require_once '../../../wp-load.php';
if (!session_id()) session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

$error = '';
$registro_exitoso = false;
$nombre_usuario = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre = sanitize_text_field($_POST['nombre'] ?? '');
    $apellidos = sanitize_text_field($_POST['apellidos'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');
    $especialidad = sanitize_text_field($_POST['especialidad'] ?? '');
    $colegiado = sanitize_text_field($_POST['colegiado'] ?? ''); // Corregido a 'colegiado'
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['passwordconfirm'] ?? '';

    if (empty($nombre) || empty($apellidos) || empty($email) || empty($especialidad) || empty($colegiado) || empty($password)) {
        $error = 'Todos los campos obligatorios deben completarse.';
    } elseif (strlen($password) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres.';
    } elseif ($password != $password_confirm) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        // Verificar email único
        $existe_email = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM medico WHERE email %s", $email));
        if ($existe_email > 0) {
            $error = 'Este email ya está registrado como médico.';
        } else {
            // Verificar colegiado único (corregido)
            $existe_colegiado = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM medico WHERE colegiado %s", $colegiado));
            if ($existe_colegiado > 0) {
                $error = 'Este número de colegiado ya está registrado.';
            } else {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $resultado = $wpdb->insert(
                    'medico',
                    array(
                        'nombre' => $nombre,
                        'apellidos' => $apellidos,
                        'email' => $email,
                        'especialidad' => $especialidad,
                        'colegiado' => $colegiado, // Corregido a 'colegiado'
                        'password_hash' => $password_hash
                    )
                );
                if ($resultado) {
                    $registro_exitoso = true;
                    $nombre_usuario = $nombre;
                } else {
                    $error = 'Error al crear la cuenta: ' . $wpdb->last_error;
                }
            }
        }
    }
}

if ($registro_exitoso) {
    $login_url = get_stylesheet_directory_uri() . '/login.php';
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Registro exitoso - Health2You</title>
        <link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/styles.css">
    </head>
    <body>
        <div class="container">
            <div class="left">
                <div class="logo">
                    <span>Registro completado</span>
                </div>
                <h1>Bienvenido/a, Dr/a <?php echo htmlspecialchars($nombre_usuario); ?>!</h1>
                <div class="alert" style="background: #e8f5e9; color: #2e7d32; font-size: 16px; padding: 20px;">
                    Tu cuenta médica ha sido creada correctamente.<br><br>
                    <strong>Redirigiendo al login...</strong><br><br>
                    <a href="<?php echo $login_url; ?>" class="btn" style="margin-top: 16px; display: inline-block;">Ir al login ahora</a>
                </div>
            </div>
        </div>
        <script>
            setTimeout(function() {
                window.location.replace('<?php echo $login_url; ?>');
            }, 2000);
        </script>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro Médico - Health2You</title>
    <link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/styles.css">
    <?php wp_head(); ?>
</head>
<body>
    <div style="padding: 16px; background: #f5f5f5; text-align: center;">
        <a href="<?php echo get_stylesheet_directory_uri(); ?>/index.php" style="color: var(--primary); text-decoration: none; font-weight: 600;">Volver al inicio</a>
    </div>
    <div class="container">
        <div class="left">
            <div class="logo">
                <span>Registro Médicos</span>
            </div>
            <h1>Crear cuenta profesional</h1>
            <p class="tagline">Regístrate para acceder a tu agenda de citas y gestión de pacientes.</p>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="post">
                <div class="form-group">
                    <label for="nombre">Nombre</label>
                    <input type="text" name="nombre" id="nombre" value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="apellidos">Apellidos</label>
                    <input type="text" name="apellidos" id="apellidos" value="<?php echo htmlspecialchars($_POST['apellidos'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="email">Email profesional</label>
                    <input type="email" name="email" id="email" placeholder="doctor@hospital.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="especialidad">Especialidad</label>
                    <input type="text" name="especialidad" id="especialidad" placeholder="Cardiología, Medicina General, Pediatra" value="<?php echo htmlspecialchars($_POST['especialidad'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="colegiado">Colegiado <span style="color: #666; font-size: 0.9em;">(obligatorio y único)</span></label>
                    <input type="text" name="colegiado" id="colegiado" value="<?php echo htmlspecialchars($_POST['colegiado'] ?? ''); ?>" required maxlength="100">
                    <small class="small-muted">Ej: 123456 o CO-78901</small>
                </div>
                <div class="form-group">
                    <label for="password">Contraseña</label>
                    <input type="password" name="password" id="password" minlength="6" required>
                    <small class="small-muted">Mínimo 6 caracteres</small>
                </div>
                <div class="form-group">
                    <label for="passwordconfirm">Confirmar contraseña</label>
                    <input type="password" name="passwordconfirm" id="passwordconfirm" minlength="6" required>
                </div>
                <button type="submit" class="btn">Crear cuenta médica</button>
                <a href="<?php echo get_stylesheet_directory_uri(); ?>/index.php" class="btn btn-secondary" style="margin-left: 10px;">Volver al portal</a>
            </form>
        </div>
        <div class="right">
            <h2>Información importante</h2>
            <p class="small-muted">Al registrarte como médico podrás</p>
            <ul class="helper-list">
                <li>Consultar tu agenda de citas completas</li>
                <li>Marcar citas como asistidas/no asistidas</li>
                <li>Ver detalles de pacientes (TSI, teléfono)</li>
                <li>Recibir notificaciones de nuevas citas</li>
                <li>Acceder desde cualquier dispositivo</li>
            </ul>
            <h3 style="margin-top: 24px;">Acceso restringido</h3>
            <p class="small-muted">Este registro es exclusivo para profesionales sanitarios colegiados. Tu acceso está protegido por LOPD y RGPD.</p>
            <p class="small-muted" style="margin-top: 16px;">
                <strong>¿Ya tienes cuenta médica?</strong><br>
                <a href="<?php echo get_stylesheet_directory_uri(); ?>/login.php" style="color: var(--primary);">Inicia sesión aquí</a>
            </p>
            <p class="small-muted" style="margin-top: 16px;">
                <strong>¿Eres paciente?</strong><br>
                <a href="<?php echo get_stylesheet_directory_uri(); ?>/registro.php" style="color: var(--primary);">Registro de pacientes</a>
            </p>
        </div>
    </div>
    <?php wp_footer(); ?>
</body>
</html>

