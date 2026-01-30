<?php
/**
 * Health2You - Nueva cita (manual + chatbot popup estilo botF.html)
 * Archivo: /wp-content/themes/health2you/nueva_cita.php
 */

if (!defined('ABSPATH')) {
    require_once dirname(__FILE__) . '/../../../wp-load.php';
}

if (!session_id()) session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

/* =========================
   Seguridad: solo paciente
========================= */
if (!isset($_SESSION['h2y_tipo']) || $_SESSION['h2y_tipo'] !== 'paciente' || empty($_SESSION['h2y_paciente_id'])) {
    wp_safe_redirect(get_stylesheet_directory_uri() . '/login.php');
    exit;
}

$paciente_id = (int) $_SESSION['h2y_paciente_id'];
$paciente_nombre = $_SESSION['h2y_paciente_nombre'] ?? 'Paciente';

/* =========================
   Médico (por defecto: el primero, como tus archivos)
========================= */
$medico = $wpdb->get_row("SELECT * FROM " . H2Y_MEDICO . " ORDER BY medico_id LIMIT 1");
if (!$medico) die("No hay médicos registrados.");

/* =========================
   Helpers
========================= */
function h2y_es_dia_valido($fecha) {
    $ts = strtotime($fecha);
    if ($ts === false) return false;

    $dia = (int) date('N', $ts); // 1..7
    if ($dia >= 6) return false; // finde

    $festivos = [
        '2026-01-01','2026-01-06','2026-03-19','2026-04-17','2026-05-01',
        '2026-08-15','2026-10-12','2026-11-01','2026-12-08','2026-12-25'
    ];
    return !in_array($fecha, $festivos, true);
}

function h2y_get_franjas($wpdb, $medico_id, $fecha) {
    // Franjas de tu nueva_cita.php original (20 min por cita)
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
             WHERE medico_id = %d
               AND estado <> 'cancelada'
               AND fecha_hora_inicio < %s
               AND fecha_hora_fin > %s",
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

/* =========================
   API del chatbot (MISMO archivo)
   POST JSON a: nueva_cita.php?h2y_api=1
========================= */
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

    if ($accion === 'buscar_urgente') {
        $slot = h2y_primer_hueco_urgente($wpdb, $medico->medico_id, 30);
        echo json_encode(['status' => 'ok', 'slot' => $slot]);
        exit;
    }

    if ($accion === 'buscar_huecos') {
        $fecha = sanitize_text_field($data['fecha'] ?? '');
        if (!$fecha || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $fecha)) {
            echo json_encode(['status' => 'error', 'mensaje' => 'Fecha inválida. Usa AAAA-MM-DD.']);
            exit;
        }
        if (!h2y_es_dia_valido($fecha)) {
            echo json_encode(['status' => 'ok', 'huecos' => []]);
            exit;
        }
        $huecos = h2y_get_franjas($wpdb, $medico->medico_id, $fecha);
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
        $dias = h2y_dias_llenos_mes($wpdb, $medico->medico_id, $year, $month);
        echo json_encode(['status' => 'ok', 'dias_llenos' => $dias]);
        exit;
    }

    if ($accion === 'guardar_cita') {
        $fecha = sanitize_text_field($data['fecha'] ?? '');
        $hora  = sanitize_text_field($data['hora'] ?? '');
        $motivo = sanitize_text_field($data['motivo'] ?? '');

        if (!$fecha || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $fecha)) {
            echo json_encode(['status' => 'error', 'mensaje' => 'Fecha inválida.']);
            exit;
        }
        if (!$hora || !preg_match('/^\\d{2}:\\d{2}$/', $hora)) {
            echo json_encode(['status' => 'error', 'mensaje' => 'Hora inválida (HH:MM).']);
            exit;
        }
        if (!h2y_es_dia_valido($fecha)) {
            echo json_encode(['status' => 'error', 'mensaje' => 'Día no válido (festivo o fin de semana).']);
            exit;
        }

        // Validar hueco
        $huecos = h2y_get_franjas($wpdb, $medico->medico_id, $fecha);
        if (!in_array($hora, $huecos, true)) {
            echo json_encode(['status' => 'error', 'mensaje' => "La hora $hora ya está ocupada."]);
            exit;
        }

        $inicio = "$fecha $hora:00";
        $fin = date('Y-m-d H:i:s', strtotime("$inicio +20 minutes"));

        $ok = $wpdb->insert(H2Y_CITA, [
            'paciente_id' => $paciente_id,
            'medico_id' => $medico->medico_id,
            'fecha_hora_inicio' => $inicio,
            'fecha_hora_fin' => $fin,
            'estado' => 'pendiente'
            // Nota: tu tabla cita no tiene "motivo". Si la añades, aquí se guarda.
        ]);

        echo json_encode($ok ? ['status' => 'ok'] : ['status' => 'error', 'mensaje' => 'No se pudo insertar la cita.']);
        exit;
    }

    echo json_encode(['status' => 'error', 'mensaje' => 'Acción no soportada']);
    exit;
}

/* =========================
   Reserva manual (tu lógica original)
========================= */
$mensaje = "";
$franjas_disponibles = [];

if ($_POST && isset($_POST['fecha']) && isset($_POST['hora'])) {
    $fecha = sanitize_text_field($_POST['fecha']);
    $hora  = sanitize_text_field($_POST['hora']);

    if (h2y_es_dia_valido($fecha)) {
        $inicio = "$fecha $hora:00";
        $fin = date('Y-m-d H:i:s', strtotime("$inicio +20 minutes"));

        $wpdb->insert(H2Y_CITA, [
            'paciente_id' => $paciente_id,
            'medico_id' => $medico->medico_id,
            'fecha_hora_inicio' => $inicio,
            'fecha_hora_fin' => $fin,
            'estado' => 'pendiente'
        ]);

        wp_safe_redirect(get_stylesheet_directory_uri() . '/dashboard_paciente.php?success=nueva');
        exit;
    } else {
        $mensaje = "Fecha no válida";
    }
}

$fecha_sel = $_GET['fecha'] ?? date('Y-m-d');
if (h2y_es_dia_valido($fecha_sel)) {
    $franjas_disponibles = h2y_get_franjas($wpdb, $medico->medico_id, $fecha_sel);
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

    <!-- Chatbot: estilos basados en botF.html (tooltip + popup + voz) -->
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
    </style>
</head>

<body>

<div style="padding: 16px; background: #f5f5f5;">
    <a href="<?= esc_url(get_stylesheet_directory_uri() . '/dashboard_paciente.php'); ?>"
       style="color: var(--primary); text-decoration: none; font-weight: 600;">
        ← Volver al dashboard
    </a>
</div>

<div class="container">
    <div class="left">
        <div class="logo"><span>🗓️ Nueva cita</span></div>
        <h1>Selecciona fecha y hora</h1>
        <p class="tagline">Puedes reservar manualmente o usar el chatbot (botón flotante).</p>

        <?php if (!empty($mensaje)): ?>
            <div class="alert alert-error"><?= htmlspecialchars($mensaje); ?></div>
        <?php endif; ?>

        <form method="get" class="form-fecha">
            <div class="form-group">
                <label>Fecha *</label>
                <input type="date" name="fecha" value="<?= htmlspecialchars($fecha_sel); ?>" min="<?= date('Y-m-d'); ?>"
                       onchange="this.form.submit()">
                <small class="small-muted">No se permiten fines de semana ni festivos.</small>
            </div>
        </form>

        <h2 style="margin-top:16px;">Franjas disponibles</h2>

        <?php if (!h2y_es_dia_valido($fecha_sel)): ?>
            <div class="alert alert-error">Fecha no válida. Elige un día laborable.</div>
        <?php elseif (empty($franjas_disponibles)): ?>
            <div class="alert alert-error">No hay citas disponibles para esta fecha. Selecciona otra.</div>
        <?php else: ?>
            <div class="alert" style="background:#fff; border:1px solid #eaeaea;">
                <?php foreach ($franjas_disponibles as $hora): ?>
                    <form method="post" style="display:inline-block; margin:4px;">
                        <input type="hidden" name="fecha" value="<?= htmlspecialchars($fecha_sel); ?>">
                        <input type="hidden" name="hora" value="<?= htmlspecialchars($hora); ?>">
                        <button type="submit" class="btn-hour"><?= htmlspecialchars($hora); ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="right">
        <h2>Chatbot</h2>
        <p class="small-muted">
            Prueba: “Quiero cita lo antes posible” o “Quiero cita” y sigue Mes → Día → Hora → Motivo.
        </p>
        <p class="small-muted">
            Médico: <?= htmlspecialchars($medico->especialidad . ' - ' . $medico->nombre . ' ' . $medico->apellidos); ?>
        </p>
        <!-- BOTÓN CITA URGENTE AÑADIDO AQUÍ -->
        <a href="<?= esc_url(get_stylesheet_directory_uri() . '/final.html'); ?>" 
           class="btn-urgente" 
           style="display:inline-block; margin-top:20px; padding:12px 24px; background:#d32f2f; color:white; text-decoration:none; border-radius:8px; font-weight:600; text-align:center; box-shadow:0 2px 8px rgba(0,0,0,0.15);">
            🚨 CITA URGENTE
        </a>
    </div>
</div>

<!-- UI CHAT (popup + tooltip como botF.html) -->
<div class="chat-btn-container">
    <div class="chat-tooltip" id="chatTooltip" onclick="toggleChat()">Rellena tus datos por voz o texto aquí</div>
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
/**
 * Bot con características tipo botF.html: tooltip, popup, voz y TTS.
 * Diferencia: la disponibilidad y el guardado van contra tu BD real vía AJAX al mismo PHP.
 */
const usuario = <?= json_encode($paciente_nombre); ?>;
const apiUrl = window.location.pathname + '?h2y_api=1';

// VOZ (SpeechRecognition) + TTS (speechSynthesis) como botF.html
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
} else {
    document.getElementById('btnMicro').style.display = 'none';
}

function activarVoz() {
    if (reconocimiento) reconocimiento.start();
}

function botHabla(t) {
    if (!('speechSynthesis' in window)) return;
    window.speechSynthesis.cancel();
    const u = new SpeechSynthesisUtterance(t);
    u.lang = 'es-ES';
    window.speechSynthesis.speak(u);
}

// UI chat
let paso = 0; // 0 mes, 1 día, 2 hora, 3 motivo, 4 confirmación, 5 final
let cita = { mes: '', dia: null, hora: '', motivo: '' };
let chatVisible = false;

// Interno: cache de huecos del día seleccionado
let huecosDia = [];

function toggleChat() {
    const chat = document.getElementById('miChat');
    const tooltip = document.getElementById('chatTooltip');

    chatVisible = !chatVisible;
    chat.style.display = chatVisible ? 'flex' : 'none';

    if (chatVisible) {
        tooltip.style.display = 'none';
        if (!document.getElementById('chatBody').innerHTML.trim()) {
            setTimeout(() => botTalk(`Hola ${usuario}! ¿Para qué mes quieres pedir cita?`), 350);
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
    botHabla(texto.replace(/<[^>]*>/g, ''));
}

async function api(datos) {
    try {
        const r = await fetch(apiUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(datos)
        });
        return await r.json();
    } catch (e) {
        return {status:'error', mensaje:'Fallo de conexión con el servidor'};
    }
}

// Utilidades
function pad2(n){ return String(n).padStart(2,'0'); }
function normalizarHora(h) {
    let x = String(h).trim().replace(':','');
    if (!/^\\d{3,4}$/.test(x)) return null;
    if (x.length === 3) x = '0' + x;
    return x.slice(0,2) + ':' + x.slice(2,4);
}
function resetBot() {
    paso = 0;
    cita = { mes:'', dia:null, hora:'', motivo:'' };
    huecosDia = [];
    botTalk(`Empecemos de nuevo. ¿Para qué mes quieres pedir cita?`);
}

// SEND
function sendMessage() {
    const input = document.getElementById('chatInput');
    const texto = input.value.trim();
    if (!texto) return;

    addMessage(texto, 'user');
    input.value = '';
    setTimeout(() => cerebroBot(texto), 450);
}

// “CEREBRO” con comportamiento tipo botF.html (urgencia, disponibilidad, flujo normal)
async function cerebroBot(texto) {
    const txt = texto.toLowerCase();

    // Comando cancelar
    if (txt.includes('cancelar')) {
        resetBot();
        return;
    }

    // 1) Interceptor urgencia: "lo antes posible" (salta mes)
    if (txt.includes('antes posible') || txt.includes('pronto') || txt.includes('cercano') || txt.includes('urgente')) {
        botTalk('He entendido urgencia. Buscando el primer hueco disponible...');
        const r = await api({accion:'buscar_urgente'});
        if (r.status === 'ok' && r.slot) {
            // Convertimos fecha a "mes + día" como el bot original, pero guardamos real
            const fecha = r.slot.fecha; // YYYY-MM-DD
            const hora = r.slot.hora;   // HH:MM
            const parts = fecha.split('-');
            const day = parseInt(parts[2],10);
            const month = parseInt(parts[1],10);
            const monthName = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'][month-1];

            cita.mes = monthName;
            cita.dia = day;
            cita.hora = hora;

            botTalk(`He encontrado hueco y lo más pronto es el <strong>${day}</strong> de <strong>${monthName}</strong> a las <strong>${hora}</strong>.<br>Te lo reservo? Dime el motivo si te vale, o di No.`);
            paso = 3; // pedimos motivo directamente
        } else {
            botTalk('Lo siento, no encuentro huecos próximos.');
        }
        return;
    }

    // 2) Interceptor disponibilidad ("libre/disponible")
    if (txt.includes('libre') || txt.includes('disponible')) {
        const now = new Date();
        const year = now.getFullYear();
        const month = (cita.mes === 'febrero') ? 2 : (now.getMonth()+1);

        const r = await api({accion:'dias_llenos_mes', year, month});
        if (r.status === 'ok') {
            if (r.dias_llenos.length === 0) {
                botTalk('Este mes está bastante libre: no veo días completos.');
            } else {
                botTalk(`Este mes tengo todo libre excepto los días: <strong>${r.dias_llenos.join(', ')}</strong>.`);
            }
        } else {
            botTalk('No pude consultar disponibilidad ahora mismo.');
        }
        return;
    }

    // 3) Flujo normal tipo botF: Mes -> Día -> Hora -> Motivo -> Confirmación
    switch (paso) {
        case 0: { // MES
            if (txt.includes('febrero') || txt.includes('enero') || txt.includes('marzo') || txt.includes('abril') || txt.includes('mayo') || txt.includes('junio') || txt.includes('julio') || txt.includes('agosto') || txt.includes('septiembre') || txt.includes('octubre') || txt.includes('noviembre') || txt.includes('diciembre')) {
                const meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
                const elegido = meses.find(m => txt.includes(m));
                cita.mes = elegido;

                botTalk(`${elegido.charAt(0).toUpperCase()+elegido.slice(1)}, entendido. ¿Qué día quieres venir? Dime el número, ej 15.`);
                paso = 1;
            } else {
                botTalk('Por favor, dime un mes válido (ej: Febrero).');
            }
            break;
        }

        case 1: { // DÍA
            const m = txt.match(/\\d{1,2}/);
            if (!m) { botTalk('Necesito el número del día, ej 10.'); return; }

            const dia = parseInt(m[0], 10);
            if (dia < 1 || dia > 31) { botTalk('Ese día no existe. Dime uno del 1 al 31.'); return; }

            // Construir fecha real YYYY-MM-DD desde mes/día
            const meses = {enero:1,febrero:2,marzo:3,abril:4,mayo:5,junio:6,julio:7,agosto:8,septiembre:9,octubre:10,noviembre:11,diciembre:12};
            const monthNum = meses[cita.mes] || (new Date().getMonth()+1);
            const year = new Date().getFullYear();
            const fecha = `${year}-${pad2(monthNum)}-${pad2(dia)}`;

            botTalk(`Consultando huecos para <strong>${fecha}</strong>...`);
            const r = await api({accion:'buscar_huecos', fecha});
            if (r.status === 'ok' && Array.isArray(r.huecos) && r.huecos.length > 0) {
                cita.dia = dia;
                huecosDia = r.huecos;

                botTalk(`El día <strong>${dia}</strong> tengo sitio. ¿A qué hora? (ej 1000, 1730)<br><br>` +
                        r.huecos.map(h => `<button class="btn-hour" onclick="setHora('${h}')">${h}</button>`).join(''));
                paso = 2;
            } else {
                botTalk(`El día <strong>${dia}</strong> está completo o no es laborable. Por favor, elige otro.`);
            }
            break;
        }

        case 2: { // HORA
            let hora = null;
            const mm = txt.match(/\\d{1,2}:?\\d{2}/);
            if (mm) hora = normalizarHora(mm[0]);

            if (!hora) { botTalk('Dime una hora válida, ej 09:30 o 0930.'); return; }

            if (!huecosDia.includes(hora)) {
                botTalk(`La hora <strong>${hora}</strong> ya está cogida. Prueba otra.`);
                return;
            }

            cita.hora = hora;
            botTalk(`Anotado <strong>${cita.dia}</strong> de <strong>${cita.mes}</strong> a las <strong>${cita.hora}</strong>. ¿Cuál es el motivo?`);
            paso = 3;
            break;
        }

        case 3: { // MOTIVO
            if (texto.length < 4) { botTalk('Por favor, especifica un poco más el motivo.'); return; }

            cita.motivo = texto;

            botTalk(`Resumen:<br>
                    Cita el <strong>${cita.dia}</strong> de <strong>${cita.mes}</strong> a las <strong>${cita.hora}</strong><br>
                    Motivo: <strong>${cita.motivo}</strong><br><br>
                    ¿Es correcto? (Sí/No)`);
            paso = 4;
            break;
        }

        case 4: { // CONFIRMACIÓN
            if (txt.includes('sí') || txt === 'si' || txt.includes('ok') || txt.includes('claro') || txt.includes('vale')) {
                const meses = {enero:1,febrero:2,marzo:3,abril:4,mayo:5,junio:6,julio:7,agosto:8,septiembre:9,octubre:10,noviembre:11,diciembre:12};
                const monthNum = meses[cita.mes] || (new Date().getMonth()+1);
                const year = new Date().getFullYear();
                const fecha = `${year}-${pad2(monthNum)}-${pad2(cita.dia)}`;

                botTalk('Perfecto! Confirmando tu cita...');
                const r = await api({accion:'guardar_cita', fecha, hora: cita.hora, motivo: cita.motivo});

                if (r.status === 'ok') {
                    botTalk('✅ Tu cita ha sido confirmada. Te llevo al dashboard...');
                    paso = 5;
                    setTimeout(() => {
                        window.location.href = <?= json_encode(get_stylesheet_directory_uri() . '/dashboard_paciente.php?success=cita_creada'); ?>;
                    }, 1200);
                } else {
                    botTalk('❌ Error al guardar: ' + (r.mensaje || 'desconocido') + '. Empecemos de nuevo.');
                    resetBot();
                }
            } else {
                botTalk('Vaya, empecemos de nuevo. ¿Qué mes querías?');
                paso = 0;
                cita = { mes:'', dia:null, hora:'', motivo:'' };
                huecosDia = [];
            }
            break;
        }

        default:
            botTalk('Ya tienes tu cita cerrada. Recarga la página.');
    }
}

function setHora(h){
    document.getElementById('chatInput').value = h;
    sendMessage();
}

function activarVoz() {
    if (!reconocimiento) {
        botTalk('Tu navegador no soporta micrófono.');
        return;
    }

    if (!('webkitSpeechRecognition' in window)) {
        botTalk('Micro no soportado. Usa Chrome.');
        return;
    }

    reconocimiento = new (window.SpeechRecognition || window.webkitSpeechRecognition)();
    reconocimiento.lang = 'es-ES';
    reconocimiento.interimResults = false;
    reconocimiento.continuous = false;

    reconocimiento.onstart = () => {
        document.getElementById('btnMicro').classList.add('micro-active');
        document.getElementById('chatInput').placeholder = "Te escucho...";
    };

    reconocimiento.onend = () => {
        document.getElementById('btnMicro').classList.remove('micro-active');
        document.getElementById('chatInput').placeholder = "Escribe aquí...";
    };

    reconocimiento.onresult = (e) => {
        const texto = e.results[0][0].transcript;
        document.getElementById('chatInput').value = texto;
        sendMessage();
    };

    reconocimiento.onerror = (e) => {
        console.log('Error micro:', e.error);
        botTalk(`Error micro: ${e.error}`);
    };

    reconocimiento.start();
}
</script>

<?php wp_footer(); ?>
</body>
</html>
