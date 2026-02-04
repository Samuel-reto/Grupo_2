<?php
/**
 * Health2You - Nueva cita con síntomas, selección de médico y botón de urgencia
 */

if (!defined('ABSPATH')) {
    require_once dirname(__FILE__) . '/../../../wp-load.php';
}

if (!session_id()) session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

/* Seguridad: solo paciente */
if (!isset($_SESSION['h2y_tipo']) || $_SESSION['h2y_tipo'] !== 'paciente' || empty($_SESSION['h2y_user_id'])) {
    header('Location: ' . get_stylesheet_directory_uri() . '/login.php');
    exit;
}

$paciente_id = (int) $_SESSION['h2y_user_id'];
$paciente_nombre = $_SESSION['h2y_user_nombre'] ?? 'Paciente';

/* Obtener todos los médicos */
$medicos = $wpdb->get_results("SELECT * FROM " . H2Y_MEDICO . " ORDER BY especialidad, apellidos");
if (empty($medicos)) die("No hay médicos registrados.");

/* Médico por defecto (primero de la lista) */
$medico_defecto = $medicos[0];

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

function h2y_primer_hueco_urgente($wpdb, $medico_id, $dias_busqueda = 30) {
    $hoy = date('Y-m-d');
    for ($i = 0; $i <= $dias_busqueda; $i++) {
        $fecha = date('Y-m-d', strtotime("$hoy +$i day"));
        if (!h2y_es_dia_valido($fecha)) continue;
        $huecos = h2y_get_franjas($wpdb, $medico_id, $fecha);
        if (!empty($huecos)) return ['fecha' => $fecha, 'hora' => $huecos[0]];
    }
    return null;
}

function h2y_dias_llenos_mes($wpdb, $medico_id, $year, $month) {
    $dias = [];
    $daysInMonth = (int)cal_days_in_month(CAL_GREGORIAN, $month, $year);
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $fecha = sprintf('%04d-%02d-%02d', $year, $month, $d);
        if (!h2y_es_dia_valido($fecha)) continue;
        $huecos = h2y_get_franjas($wpdb, $medico_id, $fecha);
        if (empty($huecos)) $dias[] = $d;
    }
    return $dias;
}

/* API del chatbot */
if (isset($_GET['h2y_api']) && $_GET['h2y_api'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'mensaje' => 'JSON inválido']);
        exit;
    }
    $accion = sanitize_text_field($data['accion'] ?? '');

    // Obtener médico desde la petición o usar el primero
    $medico_id_api = isset($data['medico_id']) ? (int)$data['medico_id'] : $medico_defecto->medico_id;

    if ($accion === 'buscar_urgente') {
        $slot = h2y_primer_hueco_urgente($wpdb, $medico_id_api, 30);
        echo json_encode(['status' => 'ok', 'slot' => $slot]);
        exit;
    }

    if ($accion === 'buscar_huecos') {
        $fecha = sanitize_text_field($data['fecha'] ?? '');
        if (!$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            echo json_encode(['status' => 'error', 'mensaje' => 'Fecha inválida']);
            exit;
        }
        if (!h2y_es_dia_valido($fecha)) {
            echo json_encode(['status' => 'ok', 'huecos' => []]);
            exit;
        }
        $huecos = h2y_get_franjas($wpdb, $medico_id_api, $fecha);
        echo json_encode(['status' => 'ok', 'huecos' => $huecos]);
        exit;
    }

    if ($accion === 'dias_llenos_mes') {
        $year = (int)($data['year'] ?? date('Y'));
        $month = (int)($data['month'] ?? date('n'));
        if ($month < 1 || $month > 12) {
            echo json_encode(['status' => 'error', 'mensaje' => 'Mes inválido']);
            exit;
        }
        $dias = h2y_dias_llenos_mes($wpdb, $medico_id_api, $year, $month);
        echo json_encode(['status' => 'ok', 'dias_llenos' => $dias]);
        exit;
    }

    if ($accion === 'guardar_cita') {
        $fecha = sanitize_text_field($data['fecha'] ?? '');
        $hora  = sanitize_text_field($data['hora'] ?? '');
        $sintomas = sanitize_textarea_field($data['sintomas'] ?? '');

        if (!$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            echo json_encode(['status' => 'error', 'mensaje' => 'Fecha inválida']);
            exit;
        }
        if (!$hora || !preg_match('/^\d{2}:\d{2}$/', $hora)) {
            echo json_encode(['status' => 'error', 'mensaje' => 'Hora inválida']);
            exit;
        }
        if (!h2y_es_dia_valido($fecha)) {
            echo json_encode(['status' => 'error', 'mensaje' => 'Día no válido']);
            exit;
        }

        // Verificar disponibilidad ANTES de insertar
        $huecos = h2y_get_franjas($wpdb, $medico_id_api, $fecha);
        if (!in_array($hora, $huecos, true)) {
            echo json_encode(['status' => 'error', 'mensaje' => "La hora $hora ya está ocupada"]);
            exit;
        }

        $inicio = "$fecha $hora:00";
        $fin = date('Y-m-d H:i:s', strtotime("$inicio +20 minutes"));

        // Insertar con manejo de errores mejorado
        $resultado = $wpdb->insert(
            H2Y_CITA,
            [
                'paciente_id' => $paciente_id,
                'medico_id' => $medico_id_api,
                'fecha_hora_inicio' => $inicio,
                'fecha_hora_fin' => $fin,
                'estado' => 'pendiente',
                'sintomas' => $sintomas
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s']
        );

        if ($resultado === false) {
            error_log("ERROR INSERCIÓN CITA: " . $wpdb->last_error);
            error_log("DATOS: paciente=$paciente_id, medico=$medico_id_api, inicio=$inicio, fin=$fin");
            echo json_encode(['status' => 'error', 'mensaje' => 'Error en base de datos']);
            exit;
        }

        echo json_encode(['status' => 'ok', 'cita_id' => $wpdb->insert_id]);
        exit;
    }

    echo json_encode(['status' => 'error', 'mensaje' => 'Acción no soportada']);
    exit;
}

/* Reserva manual */
$mensaje = "";
$tipo_mensaje = "error";
$franjas_disponibles = [];

if ($_POST && isset($_POST['fecha']) && isset($_POST['hora'])) {
    $fecha = sanitize_text_field($_POST['fecha']);
    $hora  = sanitize_text_field($_POST['hora']);
    $sintomas = sanitize_textarea_field($_POST['sintomas'] ?? '');
    $medico_id_form = isset($_POST['medico_id']) ? (int)$_POST['medico_id'] : $medico_defecto->medico_id;

    if (h2y_es_dia_valido($fecha)) {
        $inicio = "$fecha $hora:00";
        $fin = date('Y-m-d H:i:s', strtotime("$inicio +20 minutes"));

        $resultado = $wpdb->insert(H2Y_CITA, [
            'paciente_id' => $paciente_id,
            'medico_id' => $medico_id_form,
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
    } else {
        $mensaje = "Fecha no válida";
    }
}

$fecha_sel = $_GET['fecha'] ?? date('Y-m-d');
$medico_sel = isset($_GET['medico_id']) ? (int)$_GET['medico_id'] : $medico_defecto->medico_id;

if (h2y_es_dia_valido($fecha_sel)) {
    $franjas_disponibles = h2y_get_franjas($wpdb, $medico_sel, $fecha_sel);
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva cita - Health2You</title>
    <link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/styles.css">
    <?php wp_head(); ?>
    <style>
        .chat-btn-container{ position: fixed; bottom: 30px; right: 30px; z-index: 1000; display: flex; align-items: center; }
        .chat-btn{
            width: 65px; height: 65px; background-color: #00796b; color: white;
            border-radius: 50%; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            font-size: 32px; cursor: pointer; transition: transform 0.2s;
            display: flex; align-items: center; justify-content: center;
        }
        .chat-btn:hover{ transform: scale(1.1); background-color: #004d40; }
        .chat-tooltip{
            background-color: #333; color: white; padding: 8px 15px; border-radius: 20px;
            margin-right: 15px; font-size: 14px; white-space: nowrap; box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            position: relative; animation: slideInLeft 0.5s ease-out; cursor: pointer;
        }
        .chat-tooltip:after{
            content: ""; position: absolute; top: 50%; right: -6px; margin-top: -6px;
            border-width: 6px; border-style: solid; border-color: transparent transparent transparent #333;
        }
        @keyframes slideInLeft{ from{opacity:0; transform: translateX(-10px);} to{opacity:1; transform: translateX(0);} }
        .chat-popup{
            display: none; position: fixed; bottom: 110px; right: 30px;
            width: 350px; height: 550px; background: white; border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            z-index: 1000; flex-direction: column; overflow: hidden;
            border: 1px solid #dcdcdc; animation: slideUp 0.3s ease-out;
        }
        @keyframes slideUp{ from{opacity:0; transform: translateY(20px);} to{opacity:1; transform: translateY(0);} }
        .chat-header{
            background: #00796b; color: white; padding: 18px;
            display: flex; justify-content: space-between; align-items: center;
            font-weight: bold; font-size: 1.1em;
        }
        .close-btn{ background: none; border: none; color: white; font-size: 24px; cursor: pointer; }
        .chat-body{
            flex: 1; padding: 15px; overflow-y: auto; background: #fafafa;
            display: flex; flex-direction: column; gap: 12px;
        }
        .msg{
            padding: 12px 16px; border-radius: 18px; max-width: 80%;
            line-height: 1.4; word-wrap: break-word; box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .bot{ background: white; color: #333; align-self: flex-start; border: 1px solid #eee; border-bottom-left-radius: 2px; }
        .user{ background: #00796b; color: white; align-self: flex-end; border-bottom-right-radius: 2px; }
        .chat-footer{
            padding: 12px; border-top: 1px solid #eee; display: flex; gap: 8px;
            background: white; align-items: center;
        }
        .chat-footer input{
            flex: 1; padding: 12px; border: 1px solid #ccc; border-radius: 25px;
            outline: none; font-size: 14px;
        }
        .chat-footer input:focus{ border-color: #00796b; }
        .btn-icon{
            background: none; border: none; cursor: pointer; font-size: 22px;
            padding: 5px; color: #666; transition: 0.2s;
        }
        .btn-icon:hover{ color: #00796b; }
        .micro-active{ color: #d32f2f !important; animation: pulse 1.5s infinite; }
        @keyframes pulse{ 0%{transform:scale(1);} 50%{transform:scale(1.2);} 100%{transform:scale(1);} }
        .btn-hour{
            display:inline-block; margin: 4px 4px 0 0; padding: 6px 10px;
            background:#e7f6ee; border:1px solid #bfe6d0; border-radius: 8px;
            cursor:pointer; font-size: 13px;
        }
        .btn-hour:hover{ background: #d0f0dd; }
        .medico-card {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .medico-card:hover {
            border-color: #00796b;
            background: #f0f9f7;
        }
        .medico-card.selected {
            border-color: #00796b;
            background: #e0f2f1;
        }

        /* Estilos para el botón de urgencia */
        .urgencia-box {
            background: linear-gradient(135deg, #ff6b6b 0%, #ff5252 100%);
            border-radius: 12px;
            padding: 20px;
            margin: 24px 0;
            box-shadow: 0 4px 15px rgba(255, 82, 82, 0.3);
            animation: pulseUrgent 2s infinite;
        }

        @keyframes pulseUrgent {
            0%, 100% {
                box-shadow: 0 4px 15px rgba(255, 82, 82, 0.3);
            }
            50% {
                box-shadow: 0 4px 25px rgba(255, 82, 82, 0.6);
            }
        }

        .btn-urgente {
            background: white;
            color: #ff5252;
            font-weight: bold;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-block;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            transition: all 0.3s;
            border: none;
        }

        .btn-urgente:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.25);
            background: #fff;
        }
    </style>
</head>
<body>

<div style="padding: 16px; background: #f5f5f5;">
    <a href="<?= esc_url(get_stylesheet_directory_uri() . '/dashboard.php'); ?>"
       style="color: var(--primary); text-decoration: none; font-weight: 600;">
        ← Volver al dashboard
    </a>
</div>

<div class="container">
    <div class="left">
        <div class="logo"><span>🗓️ Nueva cita</span></div>
        <h1>Reserva tu cita</h1>
        <p class="tagline">Selecciona médico, fecha y hora. También puedes usar el chatbot (botón flotante).</p>

        <!-- BOTÓN DE CITA URGENTE -->
        <div class="urgencia-box">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
                <div style="flex: 1; min-width: 250px;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                        <span style="font-size: 28px;">🚨</span>
                        <strong style="color: white; font-size: 18px;">¿Necesitas atención urgente?</strong>
                    </div>
                    <p style="margin: 0; color: rgba(255,255,255,0.95); font-size: 14px; line-height: 1.5;">
                        Accede al sistema de triaje para evaluar tu situación y obtener orientación médica inmediata
                    </p>
                </div>
                <div>
                    <a href="<?= get_stylesheet_directory_uri(); ?>/final.html" class="btn-urgente">
                        ⚡ Ir a Triaje Urgente
                    </a>
                </div>
            </div>
        </div>

        <?php if (!empty($mensaje)): ?>
            <div class="alert alert-<?= $tipo_mensaje; ?>"><?= htmlspecialchars($mensaje); ?></div>
        <?php endif; ?>

        <!-- Selección de médico -->
        <div class="form-group">
            <label><strong>1. Selecciona el médico o especialidad</strong></label>
            <select id="selectMedico" onchange="cambiarMedico()" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;">
                <?php foreach ($medicos as $m): ?>
                    <option value="<?= $m->medico_id ?>" <?= $m->medico_id == $medico_sel ? 'selected' : '' ?>>
                        <?= htmlspecialchars($m->especialidad . ' - Dr/a. ' . $m->nombre . ' ' . $m->apellidos) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Selección de fecha -->
        <form method="get" class="form-fecha" id="formFecha">
            <input type="hidden" name="medico_id" id="medicoIdHidden" value="<?= $medico_sel ?>">
            <div class="form-group">
                <label><strong>2. Selecciona la fecha</strong></label>
                <input type="date" name="fecha" value="<?= htmlspecialchars($fecha_sel); ?>" min="<?= date('Y-m-d'); ?>"
                       onchange="this.form.submit()">
                <small class="small-muted">No se permiten fines de semana ni festivos.</small>
            </div>
        </form>

        <h2 style="margin-top:16px;"><strong>3. Elige una hora disponible</strong></h2>

        <?php if (!h2y_es_dia_valido($fecha_sel)): ?>
            <div class="alert alert-error">❌ Fecha no válida. Elige un día laborable.</div>
        <?php elseif (empty($franjas_disponibles)): ?>
            <div class="alert alert-error">❌ No hay citas disponibles para esta fecha. Selecciona otra.</div>
        <?php else: ?>
            <div class="alert" style="background:#fff; border:1px solid #eaeaea; padding: 16px;">
                <form method="post" id="formReserva">
                    <input type="hidden" name="fecha" value="<?= htmlspecialchars($fecha_sel); ?>">
                    <input type="hidden" name="hora" id="horaSeleccionada" value="">
                    <input type="hidden" name="medico_id" value="<?= $medico_sel ?>">

                    <p style="margin-bottom: 12px; font-weight: 600;">Horas disponibles:</p>
                    <div style="margin-bottom: 16px;">
                        <?php foreach ($franjas_disponibles as $hora): ?>
                            <button type="button" class="btn-hour" onclick="seleccionarHora('<?= htmlspecialchars($hora); ?>')">
                                <?= htmlspecialchars($hora); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-group" id="sintomasGroup" style="display:none;">
                        <label for="sintomas"><strong>4. Describe tus síntomas</strong> (opcional)</label>
                        <textarea name="sintomas" id="sintomas" rows="4"
                                  style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px;"
                                  placeholder="Describe brevemente tus síntomas o motivo de la consulta. Ejemplo: Dolor de cabeza persistente desde hace 3 días, fiebre..."></textarea>
                        <small class="small-muted">Esta información ayudará al médico a prepararse mejor para tu consulta.</small>
                        <div style="margin-top:16px; display:flex; gap:8px;">
                            <button type="submit" class="btn" style="flex:1;">✓ Confirmar cita</button>
                            <button type="button" class="btn btn-secondary" onclick="cancelarSeleccion()" style="flex:0;">Cancelar</button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="right">
        <h2>💬 Chatbot Inteligente</h2>
        <p class="small-muted">
            Prueba: <strong>"Quiero cita lo antes posible"</strong> o sigue el flujo conversacional.
        </p>
        <p class="small-muted" style="margin-top:12px;">
            El chatbot te guiará paso a paso: Mes → Día → Hora → Síntomas.
        </p>
        <div style="background:#e0f2f1; padding:12px; border-radius:8px; margin-top:16px;">
            <p style="margin:0; font-size:13px;">
                <strong>Comandos útiles:</strong><br>
                • "Urgente" - Cita más próxima<br>
                • "¿Qué días están libres?" - Ver disponibilidad<br>
                • "Ayuda" - Ver instrucciones<br>
                • "Cancelar" - Reiniciar
            </p>
        </div>
    </div>
</div>

<!-- UI CHAT -->
<div class="chat-btn-container">
    <div class="chat-tooltip" id="chatTooltip" onclick="toggleChat()">📝 Pide cita por voz o texto aquí</div>
    <button class="chat-btn" onclick="toggleChat()" title="Abrir Chat">🤖</button>
</div>

<div class="chat-popup" id="miChat">
    <div class="chat-header">
        <span>Asistente Virtual</span>
        <button class="close-btn" onclick="toggleChat()">×</button>
    </div>
    <div class="chat-body" id="chatBody"></div>
    <div class="chat-footer">
        <input type="text" id="chatInput" placeholder="Escribe aquí..." onkeypress="handleKeyPress(event)">
        <button class="btn-icon" id="btnMicro" onclick="activarVoz()" title="Dictar por voz">🎙️</button>
        <button class="btn-icon" onclick="sendMessage()" title="Enviar" style="color:#00796b;">➤</button>
    </div>
</div>

<script>
// Cambiar médico y recargar
function cambiarMedico() {
    const select = document.getElementById('selectMedico');
    const medicoId = select.value;
    document.getElementById('medicoIdHidden').value = medicoId;

    // Actualizar URL con nuevo médico
    const url = new URL(window.location.href);
    url.searchParams.set('medico_id', medicoId);
    window.location.href = url.toString();
}

// Reserva manual - selección de hora
function seleccionarHora(hora) {
    document.getElementById('horaSeleccionada').value = hora;
    document.getElementById('sintomasGroup').style.display = 'block';
    document.getElementById('sintomas').focus();

    // Resaltar botón seleccionado
    document.querySelectorAll('.btn-hour').forEach(btn => {
        btn.style.background = '#e7f6ee';
        btn.style.color = '#000';
        btn.style.fontWeight = 'normal';
    });
    event.target.style.background = '#4CAF50';
    event.target.style.color = 'white';
    event.target.style.fontWeight = 'bold';
}

function cancelarSeleccion() {
    document.getElementById('horaSeleccionada').value = '';
    document.getElementById('sintomasGroup').style.display = 'none';
    document.getElementById('sintomas').value = '';
    document.querySelectorAll('.btn-hour').forEach(btn => {
        btn.style.background = '#e7f6ee';
        btn.style.color = '#000';
        btn.style.fontWeight = 'normal';
    });
}

// ==========================================
// CHATBOT MEJORADO CON DEBUG
// ==========================================
const usuario = <?= json_encode($paciente_nombre); ?>;
const apiUrl = window.location.pathname + '?h2y_api=1';
const medicoIdActual = <?= $medico_sel ?>;

// VOZ
const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
let reconocimiento = null;

if (SpeechRecognition) {
    reconocimiento = new SpeechRecognition();
    reconocimiento.lang = 'es-ES';
    reconocimiento.interimResults = false;
    reconocimiento.onstart = () => {
        document.getElementById('btnMicro').classList.add('micro-active');
        document.getElementById('chatInput').placeholder = "Te escucho...";
    };
    reconocimiento.onend = () => {
        document.getElementById('btnMicro').classList.remove('micro-active');
        document.getElementById('chatInput').placeholder = "Escribe aquí...";
    };
    reconocimiento.onresult = (e) => {
        document.getElementById('chatInput').value = e.results[0][0].transcript;
        sendMessage();
    };
    reconocimiento.onerror = (e) => {
        console.error('Error reconocimiento:', e.error);
        botTalk('Error con el micrófono. Escribe tu mensaje.');
    };
} else {
    document.getElementById('btnMicro').style.display = 'none';
}

function activarVoz() {
    if (!reconocimiento) {
        botTalk('Tu navegador no soporta reconocimiento de voz.');
        return;
    }
    try {
        reconocimiento.start();
    } catch (e) {
        console.error('Error activar voz:', e);
    }
}

function botHabla(t) {
    if (!('speechSynthesis' in window)) return;
    window.speechSynthesis.cancel();
    const u = new SpeechSynthesisUtterance(t);
    u.lang = 'es-ES';
    window.speechSynthesis.speak(u);
}

// Estado del chat
let paso = 0; // 0=mes, 1=día, 2=hora, 3=síntomas, 4=confirmación
let cita = { mes: '', dia: null, hora: '', sintomas: '' };
let chatVisible = false;
let huecosDia = [];

function toggleChat() {
    const chat = document.getElementById('miChat');
    const tooltip = document.getElementById('chatTooltip');
    chatVisible = !chatVisible;
    chat.style.display = chatVisible ? 'flex' : 'none';
    if (chatVisible) {
        tooltip.style.display = 'none';
        if (!document.getElementById('chatBody').innerHTML.trim()) {
            setTimeout(() => botTalk(`Hola ${usuario}! ¿Para qué mes quieres pedir cita? O di "urgente" para la cita más cercana.`), 350);
        }
        document.getElementById('chatInput').focus();
    } else {
        tooltip.style.display = 'block';
    }
}

function handleKeyPress(e) {
    if (e.key === 'Enter') sendMessage();
}

function addMessage(texto, sender) {
    const body = document.getElementById('chatBody');
    const div = document.createElement('div');
    div.className = 'msg ' + (sender === 'bot' ? 'bot' : 'user');
    div.innerHTML = texto;
    body.appendChild(div);
    body.scrollTop = body.scrollHeight;
}

function botTalk(texto) {
    addMessage(texto, 'bot');
    const textoLimpio = texto.replace(/<[^>]*>/g, '').replace(/[✅❌⚠️📅📝🎉💬]/g, '');
    botHabla(textoLimpio);
}

async function api(datos) {
    try {
        // Siempre incluir el medico_id actual
        datos.medico_id = medicoIdActual;

        console.log('API REQUEST:', datos);
        const r = await fetch(apiUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(datos)
        });

        console.log('API RESPONSE STATUS:', r.status);

        if (!r.ok) {
            const errorText = await r.text();
            console.error('API ERROR TEXT:', errorText);
            throw new Error('Error HTTP: ' + r.status);
        }

        const result = await r.json();
        console.log('API RESPONSE JSON:', result);
        return result;
    } catch (e) {
        console.error('Error API:', e);
        return {status:'error', mensaje:'Error de conexión: ' + e.message};
    }
}

function pad2(n){ return String(n).padStart(2,'0'); }
function normalizarHora(h) {
    let x = String(h).trim().replace(':','');
    if (!/^\d{3,4}$/.test(x)) return null;
    if (x.length === 3) x = '0' + x;
    return x.slice(0,2) + ':' + x.slice(2,4);
}

function resetBot() {
    paso = 0;
    cita = { mes:'', dia:null, hora:'', sintomas:'' };
    huecosDia = [];
    botTalk(`Empecemos de nuevo. ¿Para qué mes quieres pedir cita?`);
}

function sendMessage() {
    const input = document.getElementById('chatInput');
    const texto = input.value.trim();
    if (!texto) return;
    addMessage(texto, 'user');
    input.value = '';
    setTimeout(() => cerebroBot(texto), 450);
}

// CEREBRO CON DEBUG MEJORADO
async function cerebroBot(texto) {
    console.log('PASO ACTUAL:', paso, 'TEXTO:', texto);

    // Sanitización básica
    const txt = texto.toLowerCase().replace(/<script.*?<\/script>/gi, '').substring(0, 500);

    // Comando cancelar
    if (txt.includes('cancelar') || txt.includes('reiniciar')) {
        resetBot();
        return;
    }

    // Comando ayuda
    if (txt.includes('ayuda') || txt === '?') {
        botTalk('Puedes decir: "Quiero cita lo antes posible" para urgencias, o seguir el flujo normal: Mes → Día → Hora → Síntomas. También "Cancelar" para reiniciar.');
        return;
    }

    // Interceptor urgencia
    if (txt.includes('antes posible') || txt.includes('pronto') || txt.includes('urgente') || txt.includes('cercano')) {
        botTalk('Buscando el primer hueco disponible...');
        const r = await api({accion:'buscar_urgente'});
        console.log('URGENTE RESPONSE:', r);

        if (r.status === 'ok' && r.slot) {
            const fecha = r.slot.fecha;
            const hora = r.slot.hora;
            const parts = fecha.split('-');
            const day = parseInt(parts[2],10);
            const month = parseInt(parts[1],10);
            const monthName = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'][month-1];
            cita.mes = monthName;
            cita.dia = day;
            cita.hora = hora;
            botTalk(`El primer hueco es el <strong>${day} de ${monthName}</strong> a las <strong>${hora}</strong>. Descríbeme tus síntomas o di "Sin síntomas".`);
            paso = 3;
        } else {
            botTalk('No hay huecos disponibles en los próximos 30 días.');
        }
        return;
    }

    // Interceptor disponibilidad
    if (txt.includes('libre') || txt.includes('disponible')) {
        const now = new Date();
        const year = now.getFullYear();
        const month = now.getMonth()+1;
        const r = await api({accion:'dias_llenos_mes', year, month});
        if (r.status === 'ok') {
            if (r.dias_llenos.length === 0) {
                botTalk('El mes actual está muy libre.');
            } else {
                botTalk(`Días completos: <strong>${r.dias_llenos.join(', ')}</strong>`);
            }
        } else {
            botTalk('No pude consultar disponibilidad.');
        }
        return;
    }

    // Flujo normal
    switch (paso) {
        case 0: { // MES
            const meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
            const elegido = meses.find(m => txt.includes(m));
            if (elegido) {
                cita.mes = elegido;
                botTalk(`${elegido.charAt(0).toUpperCase()+elegido.slice(1)}, entendido. ¿Qué día? (ej: 15)`);
                paso = 1;
            } else {
                botTalk('Dime un mes válido (ej: Febrero).');
            }
            break;
        }

        case 1: { // DÍA
            const m = txt.match(/\d{1,2}/);
            if (!m) { botTalk('Dame el número del día (ej: 10).'); return; }
            const dia = parseInt(m[0], 10);
            if (dia < 1 || dia > 31) { botTalk('Día inválido (1-31).'); return; }

            const meses = {enero:1,febrero:2,marzo:3,abril:4,mayo:5,junio:6,julio:7,agosto:8,septiembre:9,octubre:10,noviembre:11,diciembre:12};
            const monthNum = meses[cita.mes] || (new Date().getMonth()+1);
            const year = new Date().getFullYear();
            const fecha = `${year}-${pad2(monthNum)}-${pad2(dia)}`;

            console.log('CONSULTANDO HUECOS:', fecha);
            botTalk(`Consultando huecos para ${fecha}...`);

            const r = await api({accion:'buscar_huecos', fecha});
            console.log('HUECOS RESPONSE:', r);

            if (r.status === 'ok' && Array.isArray(r.huecos) && r.huecos.length > 0) {
                cita.dia = dia;
                huecosDia = r.huecos;
                botTalk(`El día <strong>${dia}</strong> tengo sitio. ¿A qué hora? (ej: 10:00, 1730)<br><br>` +
                        r.huecos.slice(0,10).map(h => `<button class="btn-hour" onclick="setHora('${h}')">${h}</button>`).join(''));
                paso = 2;
            } else {
                botTalk(`El día <strong>${dia}</strong> está completo o no es laborable. Elige otro.`);
            }
            break;
        }

        case 2: { // HORA
            let hora = null;
            const mm = txt.match(/\d{1,2}:?\d{2}/);
            if (mm) hora = normalizarHora(mm[0]);

            console.log('HORA INGRESADA:', txt, 'NORMALIZADA:', hora, 'HUECOS:', huecosDia);

            if (!hora) { botTalk('Hora inválida. Ej: 09:30 o 0930'); return; }
            if (!huecosDia.includes(hora)) {
                botTalk(`La hora <strong>${hora}</strong> ya está ocupada. Prueba otra.`);
                return;
            }
            cita.hora = hora;
            botTalk(`Anotado: <strong>${cita.dia} de ${cita.mes}</strong> a las <strong>${cita.hora}</strong>. Describe tus síntomas (o "Sin síntomas"):`);
            paso = 3;
            break;
        }

        case 3: { // SÍNTOMAS
            if (txt === 'sin sintomas' || txt === 'ninguno' || txt === 'no') {
                cita.sintomas = '';
            } else if (texto.length < 4) {
                botTalk('Por favor, especifica un poco más o di "Sin síntomas".');
                return;
            } else {
                cita.sintomas = texto.substring(0, 200);
            }
            const sintomasTexto = cita.sintomas ? cita.sintomas : 'Sin especificar';
            botTalk(`Resumen:<br>
                    Cita: <strong>${cita.dia} de ${cita.mes}</strong> a las <strong>${cita.hora}</strong><br>
                    Síntomas: <strong>${sintomasTexto}</strong><br><br>
                    ¿Confirmar? (Sí/No)`);
            paso = 4;
            break;
        }

        case 4: { // CONFIRMACIÓN
            if (txt.includes('sí') || txt === 'si' || txt.includes('ok') || txt.includes('vale') || txt.includes('confirmar')) {
                const meses = {enero:1,febrero:2,marzo:3,abril:4,mayo:5,junio:6,julio:7,agosto:8,septiembre:9,octubre:10,noviembre:11,diciembre:12};
                const monthNum = meses[cita.mes] || (new Date().getMonth()+1);
                const year = new Date().getFullYear();
                const fecha = `${year}-${pad2(monthNum)}-${pad2(cita.dia)}`;

                console.log('GUARDANDO CITA:', {fecha, hora: cita.hora, sintomas: cita.sintomas});
                botTalk('Confirmando tu cita...');

                const r = await api({accion:'guardar_cita', fecha, hora: cita.hora, sintomas: cita.sintomas});
                console.log('GUARDAR RESPONSE:', r);

                if (r.status === 'ok') {
                    botTalk('✅ Cita confirmada. Redirigiendo al dashboard...');
                    paso = 5;
                    setTimeout(() => {
                        window.location.href = <?= json_encode(get_stylesheet_directory_uri() . '/dashboard.php?success=cita_creada'); ?>;
                    }, 1500);
                } else {
                    botTalk('❌ Error: ' + (r.mensaje || 'desconocido') + '. Intenta de nuevo o usa el formulario manual.');
                    console.error('ERROR AL GUARDAR:', r);
                    resetBot();
                }
            } else {
                botTalk('Vale, empecemos otra vez. ¿Qué mes querías?');
                paso = 0;
                cita = { mes:'', dia:null, hora:'', sintomas:'' };
                huecosDia = [];
            }
            break;
        }

        default:
            botTalk('Ya tienes tu cita. Recarga la página para pedir otra.');
    }
}

function setHora(h){
    document.getElementById('chatInput').value = h;
    sendMessage();
}
</script>

<?php wp_footer(); ?>
</body>
</html>
