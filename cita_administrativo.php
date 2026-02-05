<?php
/**
 * Health2You - Nueva cita (Administrativo)
 * Permite al administrativo crear citas seleccionando paciente, médico, fecha y hora
 */

if (!defined('ABSPATH')) {
    require_once dirname(__FILE__) . '/../../../wp-load.php';
}

if (!session_id()) session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

/* Seguridad: solo administrativo */
if (!isset($_SESSION['h2y_tipo']) || $_SESSION['h2y_tipo'] !== 'administrativo' || empty($_SESSION['h2y_user_id'])) {
    header('Location: ' . get_stylesheet_directory_uri() . '/login.php');
    exit;
}

$admin_id = (int) $_SESSION['h2y_user_id'];
$admin_nombre = $_SESSION['h2y_user_nombre'] ?? 'Administrativo';

/* Obtener todos los médicos */
$medicos = $wpdb->get_results("SELECT * FROM " . H2Y_MEDICO . " ORDER BY especialidad, apellidos");
if (empty($medicos)) die("No hay médicos registrados.");

/* Obtener todos los pacientes activos */
$pacientes = $wpdb->get_results("SELECT paciente_id, nombre, apellidos, numero_tsi, email FROM " . H2Y_PACIENTE . " ORDER BY apellidos, nombre");
if (empty($pacientes)) die("No hay pacientes registrados.");

/* Médico y paciente por defecto (primeros de la lista) */
$medico_defecto = $medicos[0];
$paciente_defecto = $pacientes[0];

/* Helpers */
function h2y_es_dia_valido($fecha) {
    $ts = strtotime($fecha);
    if ($ts === false) return false;
    $dia = (int) date('N', $ts);
    if ($dia >= 6) return false;
    $festivos = [
        '2026-01-01','2026-01-06','2026-03-19','2026-04-17','2026-05-01',
        '2026-08-15','2026-10-12','2026-11-01','2026-12-08','2026-12-25'
    ];
    return !in_array($fecha, $festivos, true);
}

function h2y_get_franjas($wpdb, $medico_id, $fecha) {
    $franjas = [
        '08:30','08:50','09:10','09:30','09:50','10:10','10:30','10:50',
        '11:10','11:30','11:50','12:10','12:30','12:50','13:10',
        '16:00','16:20','16:40','17:00','17:20','17:40','18:00','18:20','18:40','19:00'
    ];
    $disponibles = [];
    foreach ($franjas as $hora) {
        $inicio = "$fecha $hora:00";
        $fin = date('Y-m-d H:i:s', strtotime("$inicio +20 minutes"));
        $ocupada = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . H2Y_CITA . "
             WHERE medico_id = %d AND estado <> 'cancelada'
               AND fecha_hora_inicio < %s AND fecha_hora_fin > %s",
            $medico_id, $fin, $inicio
        ));
        if ((int)$ocupada === 0) $disponibles[] = $hora;
    }
    return $disponibles;
}

/* Procesar creación de cita */
$mensaje = "";
$tipo_mensaje = "error";
$franjas_disponibles = [];

if ($_POST && isset($_POST['paciente_id']) && isset($_POST['medico_id']) && isset($_POST['fecha']) && isset($_POST['hora'])) {
    $paciente_id = (int) $_POST['paciente_id'];
    $medico_id = (int) $_POST['medico_id'];
    $fecha = sanitize_text_field($_POST['fecha']);
    $hora = sanitize_text_field($_POST['hora']);
    $sintomas = sanitize_textarea_field($_POST['sintomas'] ?? '');

    // Validaciones
    if ($paciente_id <= 0 || $medico_id <= 0) {
        $mensaje = "Paciente o médico inválido";
    } elseif (!h2y_es_dia_valido($fecha)) {
        $mensaje = "Fecha no válida (debe ser día laborable)";
    } else {
        // Verificar que el paciente exista
        $paciente_existe = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . H2Y_PACIENTE . " WHERE paciente_id = %d",
            $paciente_id
        ));

        // Verificar que el médico exista
        $medico_existe = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . H2Y_MEDICO . " WHERE medico_id = %d",
            $medico_id
        ));

        if (!$paciente_existe) {
            $mensaje = "El paciente seleccionado no existe";
        } elseif (!$medico_existe) {
            $mensaje = "El médico seleccionado no existe";
        } else {
            // Verificar disponibilidad de la hora
            $huecos = h2y_get_franjas($wpdb, $medico_id, $fecha);

            if (!in_array($hora, $huecos, true)) {
                $mensaje = "La hora seleccionada ya está ocupada o no es válida";
            } else {
                $inicio = "$fecha $hora:00";
                $fin = date('Y-m-d H:i:s', strtotime("$inicio +20 minutes"));

                $resultado = $wpdb->insert(H2Y_CITA, [
                    'paciente_id' => $paciente_id,
                    'medico_id' => $medico_id,
                    'fecha_hora_inicio' => $inicio,
                    'fecha_hora_fin' => $fin,
                    'estado' => 'pendiente',
                    'sintomas' => $sintomas
                ], ['%d', '%d', '%s', '%s', '%s', '%s']);

                if ($resultado) {
                    header('Location: ' . get_stylesheet_directory_uri() . '/dashboard.php?success=nueva');
                    exit;
                } else {
                    $mensaje = "Error al guardar la cita: " . $wpdb->last_error;
                }
            }
        }
    }
}

// Obtener fecha, médico y paciente seleccionados (para el formulario)
$fecha_sel = $_GET['fecha'] ?? date('Y-m-d');
$medico_sel = isset($_GET['medico_id']) ? (int)$_GET['medico_id'] : $medico_defecto->medico_id;
$paciente_sel = isset($_GET['paciente_id']) ? (int)$_GET['paciente_id'] : $paciente_defecto->paciente_id;

// Obtener franjas disponibles si la fecha es válida
if (h2y_es_dia_valido($fecha_sel)) {
    $franjas_disponibles = h2y_get_franjas($wpdb, $medico_sel, $fecha_sel);
}

// Obtener información del paciente seleccionado
$paciente_info = $wpdb->get_row($wpdb->prepare(
    "SELECT nombre, apellidos, numero_tsi, email FROM " . H2Y_PACIENTE . " WHERE paciente_id = %d",
    $paciente_sel
));

// Obtener información del médico seleccionado
$medico_info = $wpdb->get_row($wpdb->prepare(
    "SELECT nombre, apellidos, especialidad FROM " . H2Y_MEDICO . " WHERE medico_id = %d",
    $medico_sel
));
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva cita - Administrativo - Health2You</title>
    <link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/styles.css">
    <?php wp_head(); ?>
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .info-card {
            background: white;
            padding: 16px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .info-card h4 {
            margin: 0 0 12px 0;
            color: #00796b;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .info-card p {
            margin: 6px 0;
            font-size: 15px;
            color: #333;
        }
        .info-card p strong {
            color: #666;
            font-weight: 600;
        }
        .btn-hour {
            display: inline-block;
            margin: 4px 4px 0 0;
            padding: 8px 12px;
            background: #e7f6ee;
            border: 1px solid #bfe6d0;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s;
        }
        .btn-hour:hover {
            background: #d0f0dd;
            transform: translateY(-1px);
        }
        .btn-hour.selected {
            background: #4CAF50;
            color: white;
            font-weight: bold;
            border-color: #4CAF50;
        }
        .admin-badge {
            background: #ff9800;
            color: white;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        .search-box {
            position: relative;
            margin-bottom: 8px;
        }
        .search-box input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        .search-box input:focus {
            border-color: #00796b;
            outline: none;
        }
    </style>
</head>
<body style="background: linear-gradient(135deg, #fff3e0, #f5f5f5); padding: 16px;">

<div style="padding: 16px; background: #f5f5f5;">
    <a href="<?= esc_url(get_stylesheet_directory_uri() . '/dashboard.php'); ?>"
       style="color: var(--primary); text-decoration: none; font-weight: 600;">
        ← Volver al dashboard
    </a>
</div>

<div class="page">
    <div class="page-header">
        <div>
            <h2>💼 Nueva Cita (Administrativo)</h2>
            <p class="small-muted">
                <strong><?= htmlspecialchars($admin_nombre) ?></strong>
                <span class="admin-badge">Administrativo</span>
            </p>
        </div>
    </div>

    <?php if (!empty($mensaje)): ?>
        <div class="alert alert-<?= $tipo_mensaje; ?>" style="margin: 16px 0;">
            <?= htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>

    <div style="background: white; padding: 24px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">

        <!-- Paso 1: Seleccionar Paciente -->
        <div class="form-group">
            <label><strong>1. Selecciona el paciente</strong></label>
            <div class="search-box">
                <input type="text" id="searchPaciente" placeholder="🔍 Buscar por nombre, apellidos o TSI..."
                       onkeyup="filtrarPacientes()">
            </div>
            <select id="selectPaciente" onchange="cambiarPaciente()"
                    style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;font-size:14px;">
                <?php foreach ($pacientes as $p): ?>
                    <option value="<?= $p->paciente_id ?>" <?= $p->paciente_id == $paciente_sel ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p->apellidos . ', ' . $p->nombre) ?>
                        <?= $p->numero_tsi ? '(TSI: ' . htmlspecialchars($p->numero_tsi) . ')' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Paso 2: Seleccionar Médico -->
        <div class="form-group" style="margin-top: 24px;">
            <label><strong>2. Selecciona el médico o especialidad</strong></label>
            <div class="search-box">
                <input type="text" id="searchMedico" placeholder="🔍 Buscar por nombre o especialidad..."
                       onkeyup="filtrarMedicos()">
            </div>
            <select id="selectMedico" onchange="cambiarMedico()"
                    style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;font-size:14px;">
                <?php foreach ($medicos as $m): ?>
                    <option value="<?= $m->medico_id ?>" <?= $m->medico_id == $medico_sel ? 'selected' : '' ?>>
                        <?= htmlspecialchars($m->especialidad . ' - Dr/a. ' . $m->nombre . ' ' . $m->apellidos) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Información del paciente y médico seleccionados -->
        <?php if ($paciente_info && $medico_info): ?>
            <div class="stats-grid" style="margin-top: 24px;">
                <div class="info-card">
                    <h4>👤 Paciente Seleccionado</h4>
                    <p><strong>Nombre:</strong> <?= htmlspecialchars($paciente_info->nombre . ' ' . $paciente_info->apellidos) ?></p>
                    <p><strong>TSI:</strong> <?= htmlspecialchars($paciente_info->numero_tsi ?: 'No registrado') ?></p>
                    <p><strong>Email:</strong> <?= htmlspecialchars($paciente_info->email) ?></p>
                </div>
                <div class="info-card">
                    <h4>👨‍⚕️ Médico Seleccionado</h4>
                    <p><strong>Nombre:</strong> Dr/a. <?= htmlspecialchars($medico_info->nombre . ' ' . $medico_info->apellidos) ?></p>
                    <p><strong>Especialidad:</strong> <?= htmlspecialchars($medico_info->especialidad) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Paso 3: Seleccionar Fecha -->
        <form method="get" id="formFecha" style="margin-top: 24px;">
            <input type="hidden" name="medico_id" id="medicoIdHidden" value="<?= $medico_sel ?>">
            <input type="hidden" name="paciente_id" id="pacienteIdHidden" value="<?= $paciente_sel ?>">
            <div class="form-group">
                <label><strong>3. Selecciona la fecha</strong></label>
                <input type="date" name="fecha" value="<?= htmlspecialchars($fecha_sel); ?>"
                       min="<?= date('Y-m-d'); ?>"
                       onchange="this.form.submit()"
                       style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;font-size:14px;">
                <small class="small-muted">No se permiten fines de semana ni festivos.</small>
            </div>
        </form>

        <!-- Paso 4: Seleccionar Hora -->
        <div style="margin-top: 24px;">
            <h3><strong>4. Elige una hora disponible</strong></h3>

            <?php if (!h2y_es_dia_valido($fecha_sel)): ?>
                <div class="alert alert-error">❌ Fecha no válida. Elige un día laborable.</div>
            <?php elseif (empty($franjas_disponibles)): ?>
                <div class="alert alert-error">
                    ❌ No hay citas disponibles para esta fecha. Selecciona otra.
                </div>
            <?php else: ?>
                <div class="alert" style="background:#fff; border:1px solid #eaeaea; padding: 16px;">
                    <form method="post" id="formReserva">
                        <input type="hidden" name="paciente_id" value="<?= $paciente_sel ?>">
                        <input type="hidden" name="medico_id" value="<?= $medico_sel ?>">
                        <input type="hidden" name="fecha" value="<?= htmlspecialchars($fecha_sel); ?>">
                        <input type="hidden" name="hora" id="horaSeleccionada" value="">

                        <p style="margin-bottom: 12px; font-weight: 600;">Horas disponibles:</p>
                        <div style="margin-bottom: 16px;">
                            <?php foreach ($franjas_disponibles as $hora): ?>
                                <button type="button" class="btn-hour"
                                        onclick="seleccionarHora('<?= htmlspecialchars($hora); ?>')">
                                    <?= htmlspecialchars($hora); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <div class="form-group" id="sintomasGroup" style="display:none;">
                            <label for="sintomas"><strong>5. Síntomas o motivo de consulta</strong> (opcional)</label>
                            <textarea name="sintomas" id="sintomas" rows="4"
                                      style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px;font-size:14px;"
                                      placeholder="Describe brevemente los síntomas del paciente o motivo de la consulta..."></textarea>
                            <small class="small-muted">Esta información ayudará al médico a prepararse mejor para la consulta.</small>

                            <div style="margin-top:16px; display:flex; gap:8px;">
                                <button type="submit" class="btn" style="flex:1;">✓ Confirmar cita</button>
                                <button type="button" class="btn btn-secondary" onclick="cancelarSeleccion()" style="flex:0;">Cancelar</button>
                            </div>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Cambiar paciente y recargar
function cambiarPaciente() {
    const selectPaciente = document.getElementById('selectPaciente');
    const selectMedico = document.getElementById('selectMedico');
    const pacienteId = selectPaciente.value;
    const medicoId = selectMedico.value;

    const url = new URL(window.location.href);
    url.searchParams.set('paciente_id', pacienteId);
    url.searchParams.set('medico_id', medicoId);
    window.location.href = url.toString();
}

// Cambiar médico y recargar
function cambiarMedico() {
    const selectPaciente = document.getElementById('selectPaciente');
    const selectMedico = document.getElementById('selectMedico');
    const pacienteId = selectPaciente.value;
    const medicoId = selectMedico.value;

    document.getElementById('medicoIdHidden').value = medicoId;
    document.getElementById('pacienteIdHidden').value = pacienteId;

    const url = new URL(window.location.href);
    url.searchParams.set('medico_id', medicoId);
    url.searchParams.set('paciente_id', pacienteId);
    window.location.href = url.toString();
}

// Filtrar pacientes en el select
function filtrarPacientes() {
    const input = document.getElementById('searchPaciente');
    const filter = input.value.toUpperCase();
    const select = document.getElementById('selectPaciente');
    const options = select.getElementsByTagName('option');

    for (let i = 0; i < options.length; i++) {
        const txtValue = options[i].textContent || options[i].innerText;
        if (txtValue.toUpperCase().indexOf(filter) > -1) {
            options[i].style.display = "";
        } else {
            options[i].style.display = "none";
        }
    }
}

// Filtrar médicos en el select
function filtrarMedicos() {
    const input = document.getElementById('searchMedico');
    const filter = input.value.toUpperCase();
    const select = document.getElementById('selectMedico');
    const options = select.getElementsByTagName('option');

    for (let i = 0; i < options.length; i++) {
        const txtValue = options[i].textContent || options[i].innerText;
        if (txtValue.toUpperCase().indexOf(filter) > -1) {
            options[i].style.display = "";
        } else {
            options[i].style.display = "none";
        }
    }
}

// Seleccionar hora
function seleccionarHora(hora) {
    document.getElementById('horaSeleccionada').value = hora;
    document.getElementById('sintomasGroup').style.display = 'block';
    document.getElementById('sintomas').focus();

    // Resaltar botón seleccionado
    document.querySelectorAll('.btn-hour').forEach(btn => {
        btn.classList.remove('selected');
    });
    event.target.classList.add('selected');
}

// Cancelar selección
function cancelarSeleccion() {
    document.getElementById('horaSeleccionada').value = '';
    document.getElementById('sintomasGroup').style.display = 'none';
    document.getElementById('sintomas').value = '';
    document.querySelectorAll('.btn-hour').forEach(btn => {
        btn.classList.remove('selected');
    });
}
</script>

<?php wp_footer(); ?>
</body>
</html>
