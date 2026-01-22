<?php
if (!defined('ABSPATH')) require_once('../../../wp-load.php');
if (!session_id()) session_start();

// Activar errores temporalmente para debug
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
    $colegiado = sanitize_text_field($_POST['colegiado'] ?? '');
    $especialidad = sanitize_text_field($_POST['especialidad'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    
    // Validaciones
    if (empty($nombre) || empty($apellidos) || empty($colegiado) || empty($especialidad) || empty($password)) {
        $error = "Todos los campos obligatorios deben completarse.";
    } elseif (strlen($colegiado) < 6) {
        $error = "El número de colegiado debe tener al menos 6 caracteres.";
    } elseif (strlen($password) < 6) {
        $error = "La contraseña debe tener al menos 6 caracteres (seguridad profesional).";
    } elseif ($password !== $password_confirm) {
        $error = "Las contraseñas no coinciden.";
    } else {
        // Verificar colegiado único
        $existe = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . H2Y_MEDICO . " WHERE colegiado = %s", $colegiado
        ));
        
        if ($existe > 0) {
            $error = "Este número de colegiado ya está registrado.";
        } else {
            // Insertar nuevo médico
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            $resultado = $wpdb->insert(H2Y_MEDICO, [
                'nombre' => $nombre,
                'apellidos' => $apellidos,
                'colegiado' => $colegiado,
                'especialidad' => $especialidad,
                'password_hash' => $password_hash
            ]);
            
            if ($resultado) {
                $registro_exitoso = true;
                $nombre_usuario = $apellidos;
            } else {
                $error = "Error al crear la cuenta. Inténtalo de nuevo.";
            }
        }
    }
}

// Si registro exitoso, mostrar página de éxito
if ($registro_exitoso) {
    $login_url = get_stylesheet_directory_uri() . '/index.php';
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Registro exitoso</title>
        <link rel="stylesheet" href="<?= get_stylesheet_directory_uri(); ?>/style.css">
    </head>
    <body>
        <div class="container">
            <div class="left">
                <div class="logo">
                    <span>✅ Registro completado</span>
                </div>
                <h1>¡Bienvenido/a, Dr/a. <?= htmlspecialchars($nombre_usuario) ?>!</h1>
                <div class="alert" style="background: #e8f5e9; color: #2e7d32; font-size: 16px; padding: 20px;">
                    ✅ Tu cuenta profesional ha sido creada correctamente.
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
    <title>Registro Profesional - Health2You</title>
    <link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/style.css">
    <?php wp_head(); ?>
</head>
<body>
<div class="container">
    <div class="left">
        <div class="logo">
            <span>🩺 Registro Profesional</span>
        </div>
        <h1>Registro de médico/a</h1>
        <p class="tagline">
            Únete a la plataforma Health2You del Servicio Cántabro de Salud.
        </p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="form-group">
                <label for="nombre">Nombre *</label>
                <input type="text" name="nombre" id="nombre" 
                       placeholder="Dr./Dra."
                       value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label for="apellidos">Apellidos *</label>
                <input type="text" name="apellidos" id="apellidos" 
                       value="<?= htmlspecialchars($_POST['apellidos'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label for="colegiado">Nº Colegiado *</label>
                <input type="text" name="colegiado" id="colegiado" 
                       placeholder="313103795"
                       value="<?= htmlspecialchars($_POST['colegiado'] ?? '') ?>" required>
                <small class="small-muted">Número del Colegio de Médicos de Cantabria</small>
            </div>

            <div class="form-group">
                <label for="especialidad">Especialidad *</label>
                <select name="especialidad" id="especialidad" required>
                    <option value="">Seleccionar especialidad...</option>
                    <option value="Medicina General" <?= ($_POST['especialidad'] ?? '') === 'Medicina General' ? 'selected' : '' ?>>
                        Medicina General
                    </option>
                    <option value="Medicina de Familia" <?= ($_POST['especialidad'] ?? '') === 'Medicina de Familia' ? 'selected' : '' ?>>
                        Medicina de Familia
                    </option>
                    <option value="Pediatría" <?= ($_POST['especialidad'] ?? '') === 'Pediatría' ? 'selected' : '' ?>>
                        Pediatría
                    </option>
                    <option value="Cardiología" <?= ($_POST['especialidad'] ?? '') === 'Cardiología' ? 'selected' : '' ?>>
                        Cardiología
                    </option>
                    <option value="Traumatología" <?= ($_POST['especialidad'] ?? '') === 'Traumatología' ? 'selected' : '' ?>>
                        Traumatología
                    </option>
                    <option value="Ginecología" <?= ($_POST['especialidad'] ?? '') === 'Ginecología' ? 'selected' : '' ?>>
                        Ginecología
                    </option>
                    <option value="Dermatología" <?= ($_POST['especialidad'] ?? '') === 'Dermatología' ? 'selected' : '' ?>>
                        Dermatología
                    </option>
                    <option value="Oftalmología" <?= ($_POST['especialidad'] ?? '') === 'Oftalmología' ? 'selected' : '' ?>>
                        Oftalmología
                    </option>
                    <option value="Otorrinolaringología" <?= ($_POST['especialidad'] ?? '') === 'Otorrinolaringología' ? 'selected' : '' ?>>
                        Otorrinolaringología
                    </option>
                    <option value="Enfermería" <?= ($_POST['especialidad'] ?? '') === 'Enfermería' ? 'selected' : '' ?>>
                        Enfermería
                    </option>
                    <option value="Otra" <?= ($_POST['especialidad'] ?? '') === 'Otra' ? 'selected' : '' ?>>
                        Otra especialidad
                    </option>
                </select>
            </div>

            <div class="form-group">
                <label for="password">Contraseña *</label>
                <input type="password" name="password" id="password" 
                       minlength="6" required>
                <small class="small-muted">Mínimo 6 caracteres (recomendado 8+)</small>
            </div>

            <div class="form-group">
                <label for="password_confirm">Confirmar contraseña *</label>
                <input type="password" name="password_confirm" id="password_confirm" 
                       minlength="6" required>
            </div>

            <button type="submit" class="btn">Crear cuenta profesional</button>
            <a href="<?= get_stylesheet_directory_uri(); ?>/index.php" 
               class="btn btn-secondary" style="margin-left: 10px;">
                Volver al login
            </a>
        </form>
    </div>

    <div class="right">
        <h2>Acceso profesional sanitario</h2>
        <p class="small-muted">
            Al registrarte en Health2You como profesional sanitario tendrás acceso a:
        </p>
        <ul class="helper-list">
            <li>Agenda clínica completa en tiempo real</li>
            <li>Historial de citas pasadas, presentes y futuras</li>
            <li>Datos de contacto de pacientes asignados</li>
            <li>Gestión de disponibilidad de consultas</li>
            <li>Información sanitaria protegida según normativa</li>
        </ul>
        
        <h3 style="margin-top: 24px;">Verificación profesional</h3>
        <p class="small-muted">
            Tu número de colegiado será validado por el sistema. Solo profesionales sanitarios 
            colegiados en Cantabria pueden registrarse.
        </p>
        
        <h3 style="margin-top: 24px;">Seguridad y LOPD</h3>
        <p class="small-muted">
            Como profesional sanitario, eres responsable del tratamiento adecuado de los datos 
            de pacientes según la LOPD, RGPD y normativa del SCS.
        </p>
        
        <p class="small-muted" style="margin-top: 16px;">
            <strong>¿Ya tienes cuenta?</strong><br>
            <a href="<?= get_stylesheet_directory_uri(); ?>/index.php" style="color: var(--primary);">
                Inicia sesión aquí
            </a>
        </p>
        
        <p class="small-muted" style="margin-top: 16px;">
            <strong>¿Eres paciente?</strong><br>
            <a href="<?= get_stylesheet_directory_uri(); ?>/registro.php" style="color: var(--primary);">
                Registro de pacientes
            </a>
        </p>
    </div>
</div>
<?php wp_footer(); ?>
</body>
</html>


