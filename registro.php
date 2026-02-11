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
    $tipo_usuario = sanitize_text_field($_POST['tipo_usuario'] ?? '');
    $nombre = sanitize_text_field($_POST['nombre'] ?? '');
    $apellidos = sanitize_text_field($_POST['apellidos'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    // Campos específicos según tipo
    $tsi = sanitize_text_field($_POST['numero_tsi'] ?? '');
    $telefono = sanitize_text_field($_POST['telefono'] ?? '');
    $colegiado = sanitize_text_field($_POST['colegiado'] ?? '');
    $especialidad = sanitize_text_field($_POST['especialidad'] ?? '');

    // Validaciones comunes
    if (empty($tipo_usuario) || empty($nombre) || empty($apellidos) || empty($password)) {
        $error = "Todos los campos obligatorios deben completarse.";
    } elseif (strlen($password) < 4) {
        $error = "La contraseña debe tener al menos 4 caracteres.";
    } elseif ($password !== $password_confirm) {
        $error = "Las contraseñas no coinciden.";
    } else {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        // REGISTRO SEGÚN TIPO DE USUARIO
        if ($tipo_usuario === 'paciente') {
            // Validaciones específicas paciente
            if (empty($tsi)) {
                $error = "El número TSI es obligatorio para pacientes.";
            } elseif (strlen($tsi) < 12) {
                $error = "El número TSI debe tener al menos 12 caracteres.";
            } else {
                // Verificar TSI único
                $existe = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM " . H2Y_PACIENTE . " WHERE numero_tsi = %s", $tsi
                ));

                if ($existe > 0) {
                    $error = "Este número TSI ya está registrado.";
                } else {
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

        } elseif ($tipo_usuario === 'medico') {
            // Validaciones específicas médico
            if (empty($colegiado) || empty($especialidad) || empty($email)) {
                $error = "Colegiado, especialidad y email son obligatorios para médicos.";
            } else {
                // Verificar email único
                $existe_email = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM " . H2Y_MEDICO . " WHERE email = %s", $email
                ));

                // Verificar colegiado único
                $existe_colegiado = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM " . H2Y_MEDICO . " WHERE colegiado = %s", $colegiado
                ));

                if ($existe_email > 0) {
                    $error = "Este email ya está registrado como médico.";
                } elseif ($existe_colegiado > 0) {
                    $error = "Este número de colegiado ya está registrado.";
                } else {
                    $resultado = $wpdb->insert(H2Y_MEDICO, [
                        'nombre' => $nombre,
                        'apellidos' => $apellidos,
                        'email' => $email,
                        'especialidad' => $especialidad,
                        'colegiado' => $colegiado,
                        'password_hash' => $password_hash
                    ]);

                    if ($resultado) {
                        $registro_exitoso = true;
                        $nombre_usuario = $nombre;
                    } else {
                        $error = "Error al crear la cuenta: " . $wpdb->last_error;
                    }
                }
            }

        } elseif ($tipo_usuario === 'administrativo') {
            // Validaciones específicas administrativo
            if (empty($email)) {
                $error = "El email es obligatorio para administrativos.";
            } else {
                // Verificar email único
                $existe_email = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM " . H2Y_ADMINISTRATIVO . " WHERE email = %s", $email
                ));

                if ($existe_email > 0) {
                    $error = "Este email ya está registrado como administrativo.";
                } else {
                    $resultado = $wpdb->insert(H2Y_ADMINISTRATIVO, [
                        'nombre' => $nombre,
                        'apellidos' => $apellidos,
                        'email' => $email,
                        'password_hash' => $password_hash
                    ]);

                    if ($resultado) {
                        $registro_exitoso = true;
                        $nombre_usuario = $nombre;
                    } else {
                        $error = "Error al crear la cuenta: " . $wpdb->last_error;
                    }
                }
            }
        } else {
            $error = "Tipo de usuario no válido.";
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
    <style>
        .campo-condicional {
            display: none;
        }
        .campo-condicional.activo {
            display: block;
        }
    </style>
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
            <span>📝 Registro</span>
        </div>
        <h1>Crear cuenta nueva</h1>
        <p class="tagline">
            Regístrate para acceder al sistema Health2You.
        </p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" id="formRegistro">
            <!-- TIPO DE USUARIO -->
            <div class="form-group">
                <label for="tipo_usuario">Tipo de usuario *</label>
                <select name="tipo_usuario" id="tipo_usuario" required>
                    <option value="">-- Selecciona una opción --</option>
                    <option value="paciente" <?= (($_POST['tipo_usuario'] ?? '') === 'paciente') ? 'selected' : '' ?>>👤 Paciente</option>
                    <option value="medico" <?= (($_POST['tipo_usuario'] ?? '') === 'medico') ? 'selected' : '' ?>>🩺 Médico</option>
                    <option value="administrativo" <?= (($_POST['tipo_usuario'] ?? '') === 'administrativo') ? 'selected' : '' ?>>💼 Administrativo</option>
                </select>
            </div>

            <!-- CAMPOS COMUNES -->
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

            <!-- CAMPOS ESPECÍFICOS PACIENTE -->
            <div id="campos_paciente" class="campo-condicional">
                <div class="form-group">
                    <label for="numero_tsi">Nº Tarjeta Sanitaria (TSI) *</label>
                    <input type="text" name="numero_tsi" id="numero_tsi"
                           placeholder="CANT390123456789"
                           value="<?= htmlspecialchars($_POST['numero_tsi'] ?? '') ?>">
                    <small class="small-muted">Formato: 16 caracteres (ej. CANT390123456789)</small>
                </div>

                <div class="form-group">
                    <label for="telefono">Teléfono</label>
                    <input type="tel" name="telefono" id="telefono"
                           placeholder="942123456"
                           value="<?= htmlspecialchars($_POST['telefono'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="email_paciente">Email</label>
                    <input type="email" name="email" id="email_paciente"
                           placeholder="tunombre@email.com"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    <small class="small-muted">Opcional para pacientes (necesario para 2FA)</small>
                </div>
            </div>

            <!-- CAMPOS ESPECÍFICOS MÉDICO -->
            <div id="campos_medico" class="campo-condicional">
                <div class="form-group">
                    <label for="email_medico">Email profesional *</label>
                    <input type="email" name="email" id="email_medico"
                           placeholder="doctor@hospital.com"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="especialidad">Especialidad *</label>
                    <input type="text" name="especialidad" id="especialidad"
                           placeholder="Cardiología, Medicina General, Pediatría..."
                           value="<?= htmlspecialchars($_POST['especialidad'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="colegiado">Número de Colegiado *</label>
                    <input type="text" name="colegiado" id="colegiado"
                           placeholder="123456 o CO-78901"
                           value="<?= htmlspecialchars($_POST['colegiado'] ?? '') ?>" maxlength="100">
                    <small class="small-muted">Número único de colegiado</small>
                </div>
            </div>

            <!-- CAMPOS ESPECÍFICOS ADMINISTRATIVO -->
            <div id="campos_administrativo" class="campo-condicional">
                <div class="form-group">
                    <label for="email_admin">Email corporativo *</label>
                    <input type="email" name="email" id="email_admin"
                           placeholder="admin@health2you.com"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
            </div>

            <!-- CONTRASEÑA -->
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
            <a href="<?= get_stylesheet_directory_uri(); ?>/login.php"
               class="btn btn-secondary" style="margin-left: 10px;">
                Ya tengo cuenta
            </a>
        </form>
    </div>

    <div class="right">
        <h2>Información según tu perfil</h2>

        <div id="info_paciente" class="campo-condicional">
            <p class="small-muted">Como <strong>paciente</strong> podrás:</p>
            <ul class="helper-list">
                <li>✅ Solicitar citas médicas online 24/7</li>
                <li>✅ Modificar o cancelar citas existentes</li>
                <li>✅ Consultar tu historial de consultas</li>
                <li>✅ Descargar justificantes de asistencia</li>
                <li>✅ Recibir recordatorios por email (2FA activo)</li>
            </ul>
        </div>

        <div id="info_medico" class="campo-condicional">
            <p class="small-muted">Como <strong>médico</strong> podrás:</p>
            <ul class="helper-list">
                <li>✅ Consultar tu agenda de citas completa</li>
                <li>✅ Marcar citas como asistidas/no asistidas</li>
                <li>✅ Ver detalles de pacientes (TSI, teléfono)</li>
                <li>✅ Generar justificantes automáticamente</li>
                <li>✅ Acceder desde cualquier dispositivo</li>
            </ul>
            <p class="small-muted" style="margin-top: 16px; color: #e74c3c;">
                <strong>⚠️ Acceso restringido:</strong> Solo profesionales sanitarios colegiados.
            </p>
        </div>

        <div id="info_administrativo" class="campo-condicional">
            <p class="small-muted">Como <strong>administrativo</strong> podrás:</p>
            <ul class="helper-list">
                <li>✅ Gestionar citas de todos los pacientes</li>
                <li>✅ Asignar citas a médicos disponibles</li>
                <li>✅ Ver estadísticas globales del sistema</li>
                <li>✅ Consultar agendas de todos los médicos</li>
                <li>✅ Generar reportes y listados</li>
            </ul>
            <p class="small-muted" style="margin-top: 16px; color: #e74c3c;">
                <strong>⚠️ Acceso administrativo:</strong> Personal autorizado del centro.
            </p>
        </div>

        <h3 style="margin-top: 24px;">Protección de datos</h3>
        <p class="small-muted">
            Tus datos están protegidos según la LOPD y RGPD. Solo el personal sanitario autorizado
            tendrá acceso a información médica sensible.
        </p>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tipoSelect = document.getElementById('tipo_usuario');

    function actualizarCampos() {
        const tipo = tipoSelect.value;

        // Ocultar todos los campos condicionales
        document.querySelectorAll('.campo-condicional').forEach(el => {
            el.classList.remove('activo');
        });

        // DESHABILITAR todos los campos específicos para que no se envíen
        document.querySelectorAll('#campos_paciente input, #campos_medico input, #campos_administrativo input').forEach(input => {
            input.removeAttribute('required');
            input.disabled = true; // CLAVE: deshabilitar campos no usados
        });

        // Mostrar y habilitar campos según tipo
        if (tipo === 'paciente') {
            document.getElementById('campos_paciente').classList.add('activo');
            document.getElementById('info_paciente').classList.add('activo');
            
            // Habilitar solo campos de paciente
            document.querySelectorAll('#campos_paciente input').forEach(input => {
                input.disabled = false;
            });
            document.getElementById('numero_tsi').setAttribute('required', 'required');
            
        } else if (tipo === 'medico') {
            document.getElementById('campos_medico').classList.add('activo');
            document.getElementById('info_medico').classList.add('activo');
            
            // Habilitar solo campos de médico
            document.querySelectorAll('#campos_medico input').forEach(input => {
                input.disabled = false;
            });
            document.getElementById('email_medico').setAttribute('required', 'required');
            document.getElementById('especialidad').setAttribute('required', 'required');
            document.getElementById('colegiado').setAttribute('required', 'required');
            
        } else if (tipo === 'administrativo') {
            document.getElementById('campos_administrativo').classList.add('activo');
            document.getElementById('info_administrativo').classList.add('activo');
            
            // Habilitar solo campos de administrativo
            document.querySelectorAll('#campos_administrativo input').forEach(input => {
                input.disabled = false;
            });
            document.getElementById('email_admin').setAttribute('required', 'required');
        }
    }

    tipoSelect.addEventListener('change', actualizarCampos);

    // Ejecutar al cargar si hay un tipo ya seleccionado
    if (tipoSelect.value) {
        actualizarCampos();
    }
});
</script>

<?php wp_footer(); ?>
</body>
</html>
