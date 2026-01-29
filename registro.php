<?php
if (!defined('ABSPATH')) require_once('../../../wp-load.php');
if (!session_id()) session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

$error = "";
$registro_exitoso = false;
$nombre_usuario = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = sanitize_text_field($_POST['nombre'] ?? '');
    $apellidos = sanitize_text_field($_POST['apellidos'] ?? '');
    $tsi = sanitize_text_field($_POST['numero_tsi'] ?? '');
    $telefono = sanitize_text_field($_POST['telefono'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    if (empty($nombre) || empty($apellidos) || empty($tsi) || empty($password)) {
        $error = "Todos los campos obligatorios deben completarse.";
    } elseif (strlen($tsi) < 12) {
        $error = "El número TSI debe tener al menos 12 caracteres.";
    } elseif (strlen($password) < 4) {
        $error = "La contraseña debe tener al menos 4 caracteres.";
    } elseif ($password !== $password_confirm) {
        $error = "Las contraseñas no coinciden.";
    } else {
        $existe = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . H2Y_PACIENTE . " WHERE numero_tsi = %s", $tsi
        ));

        if ($existe > 0) {
            $error = "Este número TSI ya está registrado.";
        } else {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            $resultado = $wpdb->insert(H2Y_PACIENTE, [
                'nombre' => $nombre,
                'apellidos' => $apellidos,
                'numero_tsi' => $tsi,
                'telefono' => $telefono,
                'email' => $email,
                'password_hash' => $password_hash
            ]);

            if ($resultado) {
                $registro_exitoso = true;
                $nombre_usuario = $nombre;
            } else {
                $error = "Error al crear la cuenta. Inténtalo de nuevo.";
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
        <title>Registro exitoso</title>
        <link rel="stylesheet" href="<?= get_stylesheet_directory_uri(); ?>/styles.css">
    </head>
    <body>
        <div class="container">
            <div class="left">
                <div class="logo">
                    <span>✅ Registro completado</span>
                </div>
                <h1>¡Bienvenido/a, <?= htmlspecialchars($nombre_usuario) ?>!</h1>
                <div class="alert" style="background: #e8f5e9; color: #2e7d32; font-size: 16px; padding: 20px;">
                    ✅ Tu cuenta ha sido creada correctamente.
                    <br><br>
                    <strong>Redirigiendo al login...</strong>
                    <br><br>
                    <a href="<?= $login_url ?>" class="btn" style="margin-top: 16px; display: inline-block;">
                        Ir al login ahora →
                    </a>
                </div>
            </div>
        </div>
        <script>
            setTimeout(function() {
                window.location.replace('<?= $login_url ?>');
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
    <title>Registro - Health2You</title>
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
            <span>📝 Registro Paciente</span>
        </div>
        <h1>Crear cuenta nueva</h1>
        <p class="tagline">
            Regístrate para gestionar tus citas médicas online 24/7.
        </p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="form-group">
                <label for="nombre">Nombre *</label>
                <input type="text" name="nombre" id="nombre"
                       value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label for="apellidos">Apellidos *</label>
                <input type="text" name="apellidos" id="apellidos"
                       value="<?= htmlspecialchars($_POST['apellidos'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label for="numero_tsi">Nº Tarjeta Sanitaria (TSI) *</label>
                <input type="text" name="numero_tsi" id="numero_tsi"
                       placeholder="CANT390123456789"
                       value="<?= htmlspecialchars($_POST['numero_tsi'] ?? '') ?>" required>
                <small class="small-muted">Formato: 16 caracteres (ej. CANT390123456789)</small>
            </div>

            <div class="form-group">
                <label for="telefono">Teléfono</label>
                <input type="tel" name="telefono" id="telefono"
                       placeholder="942123456"
                       value="<?= htmlspecialchars($_POST['telefono'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" name="email" id="email"
                       placeholder="tunombre@email.com"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="password">Contraseña *</label>
                <input type="password" name="password" id="password"
                       minlength="4" required>
                <small class="small-muted">Mínimo 4 caracteres</small>
            </div>

            <div class="form-group">
                <label for="password_confirm">Confirmar contraseña *</label>
                <input type="password" name="password_confirm" id="password_confirm"
                       minlength="4" required>
            </div>

            <button type="submit" class="btn">Crear cuenta</button>
            <a href="<?= get_stylesheet_directory_uri(); ?>/index.php"
               class="btn btn-secondary" style="margin-left: 10px;">
                Volver al login
            </a>
        </form>
    </div>

    <div class="right">
        <h2>Información importante</h2>
        <p class="small-muted">
            Al registrarte en Health2You podrás:
        </p>
        <ul class="helper-list">
            <li>Solicitar citas médicas online 24/7</li>
            <li>Modificar o cancelar citas existentes</li>
            <li>Consultar tu historial de consultas</li>
            <li>Recibir recordatorios de citas</li>
            <li>Acceder desde cualquier dispositivo</li>
        </ul>

        <h3 style="margin-top: 24px;">Protección de datos</h3>
        <p class="small-muted">
            Tus datos están protegidos según la LOPD y RGPD. Solo el personal sanitario autorizado
            de Health2You tendrá acceso a tu información médica.
        </p>

        <p class="small-muted" style="margin-top: 16px;">
            <strong>¿Ya tienes cuenta?</strong><br>
            <a href="<?= get_stylesheet_directory_uri(); ?>/login.php" style="color: var(--primary);">
                Inicia sesión aquí
            </a>
        </p>

        <p class="small-muted" style="margin-top: 16px;">
            <strong>¿Eres profesional sanitario?</strong><br>
            <a href="<?= get_stylesheet_directory_uri(); ?>/registro_medico.php" style="color: var(--primary);">
                Registro de médicos
            </a>
        </p>
    </div>
</div>
<?php wp_footer(); ?>
</body>
</html>


