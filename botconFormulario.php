<?php
/*
Template Name: H2Y - Nueva Cita
*/

// 1. PROTECCIÓN DE SEGURIDAD
if (!isset($_SESSION['h2y_tipo']) || $_SESSION['h2y_tipo'] !== 'paciente') {
    wp_redirect(home_url('/login/'));
    exit;
}

get_header();
global $wpdb;

// 2. LÓGICA PHP PARA EL FORMULARIO MANUAL (Igual que antes)
$paciente_id = $_SESSION['h2y_paciente_id'];
$medico = $wpdb->get_row("SELECT * FROM " . H2Y_MEDICO . " ORDER BY medico_id LIMIT 1");
$mensaje = "";
$franjas_disponibles = [];

// Funciones PHP auxiliares
function es_dia_valido($fecha) {
    $dia = date('N', strtotime($fecha));
    if ($dia >= 6) return false;
    $festivos = ['2026-01-01','2026-01-06','2026-03-19','2026-04-17','2026-05-01', '2026-08-15','2026-10-12','2026-11-01','2026-12-08','2026-12-25'];
    return !in_array($fecha, $festivos);
}

function get_franjas($wpdb, $medico_id, $fecha) {
    $franjas = ['09:00','09:30','10:00','10:30','11:00','11:30','12:00','12:30', '16:00','16:30','17:00','17:30','18:00'];
    $disponibles = [];
    foreach ($franjas as $hora) {
        $inicio = "$fecha $hora";
        $fin = date('Y-m-d H:i:s', strtotime("$inicio +20 minutes"));
        $ocupada = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM " . H2Y_CITA . " 
            WHERE medico_id = %d AND estado <> 'cancelada' 
            AND fecha_hora_inicio < %s AND fecha_hora_fin > %s
        ", $medico_id, $fin, $inicio));
        if (!$ocupada) $disponibles[] = $hora;
    }
    return $disponibles;
}

// Procesar formulario MANUAL
if ($_POST && isset($_POST['fecha']) && isset($_POST['hora'])) {
    $fecha = sanitize_text_field($_POST['fecha']);
    $hora = sanitize_text_field($_POST['hora']);
    if (es_dia_valido($fecha)) {
        $inicio = "$fecha $hora";
        $fin = date('Y-m-d H:i:s', strtotime("$inicio +20 minutes"));
        $wpdb->insert(H2Y_CITA, [
            'paciente_id' => $paciente_id,
            'medico_id' => $medico->medico_id,
            'fecha_hora_inicio' => $inicio,
            'fecha_hora_fin' => $fin,
            'estado' => 'pendiente'
        ]);
        wp_redirect(home_url('/area-paciente/?success=nueva'));
        exit;
    } else {
        $mensaje = "Fecha no válida";
    }
}

// Cargar datos para la vista manual
$fecha_sel = $_GET['fecha'] ?? date('Y-m-d');
if (es_dia_valido($fecha_sel)) {
    $franjas_disponibles = get_franjas($wpdb, $medico->medico_id, $fecha_sel);
}
?>

<!-- === ESTRUCTURA HTML === -->

<div class="h2y-container">
    <div class="header-dashboard">
        <h2>Nueva Cita</h2>
        <a href="<?php echo home_url('/area-paciente/'); ?>" class="btn-volver">← Volver</a>
    </div>

    <!-- VISTA MANUAL (Siempre visible) -->
    <div class="card-form">
        <h3>📅 Selección Manual</h3>
        <?php if($mensaje) echo "<p class='error'>$mensaje</p>"; ?>
        
        <form method="GET" action="" class="form-fecha">
            <label>Fecha:</label>
            <input type="date" name="fecha" value="<?php echo $fecha_sel; ?>" min="<?php echo date('Y-m-d'); ?>" onchange="this.form.submit()">
        </form>

        <div class="horarios-grid">
            <?php if (!empty($franjas_disponibles)): ?>
                <?php foreach ($franjas_disponibles as $hora): ?>
                    <form method="POST" action="">
                        <input type="hidden" name="fecha" value="<?php echo $fecha_sel; ?>">
                        <input type="hidden" name="hora" value="<?php echo $hora; ?>">
                        <button type="submit" class="btn-hora"><?php echo $hora; ?></button>
                    </form>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No hay citas disponibles. Prueba otra fecha o usa el Chatbot.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- === CHATBOT INTEGRADO === -->

<!-- Estilos CSS del Bot -->
<style>
    .chat-toggle-btn {
        position: fixed; bottom: 30px; right: 30px;
        width: 60px; height: 60px; border-radius: 50%;
        background: #0066cc; color: white; border: none;
        box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        cursor: pointer; z-index: 9999; font-size: 24px;
        transition: transform 0.3s;
    }
    .chat-toggle-btn:hover { transform: scale(1.1); }
    
    .chatbot-container {
        position: fixed; bottom: 100px; right: 30px;
        width: 350px; height: 500px; background: white;
        border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        display: none; flex-direction: column; z-index: 9999;
        border: 1px solid #ddd; overflow: hidden;
    }
    
    .chat-header { background: #0066cc; color: white; padding: 15px; display: flex; align-items: center; gap: 10px; }
    .bot-avatar { width: 35px; height: 35px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 20px; }
    .chat-body { flex: 1; padding: 15px; overflow-y: auto; background: #f5f7fb; display: flex; flex-direction: column; gap: 10px; }
    
    .message { max-width: 80%; padding: 10px 15px; border-radius: 15px; font-size: 14px; line-height: 1.4; animation: fadeIn 0.3s ease; }
    .bot-msg { background: white; color: #333; border-bottom-left-radius: 2px; border: 1px solid #e1e1e1; }
    .user-msg { background: #0066cc; color: white; align-self: flex-end; border-bottom-right-radius: 2px; }
    
    .chat-input-area { padding: 10px; background: white; border-top: 1px solid #eee; display: flex; gap: 10px; }
    .chat-input-area input { flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 20px; outline: none; }
    .chat-input-area button { background: #0066cc; color: white; border: none; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
</style>

<!-- HTML del Bot -->
<button class="chat-toggle-btn" onclick="toggleChat()">💬</button>

<div class="chatbot-container" id="chatbot">
    <div class="chat-header">
        <div class="bot-avatar">🤖</div>
        <div><strong>Asistente Health2You</strong><br><small>En línea</small></div>
        <button onclick="toggleChat()" style="margin-left:auto; background:none; border:none; color:white; cursor:pointer;">✕</button>
    </div>
    
    <div class="chat-body" id="chatBody">
        <div class="message bot-msg">
            ¡Hola! 👋 Soy tu asistente inteligente.<br>
            Puedo buscar huecos libres por ti.<br><br>
            Dime: <em>"Quiero cita"</em> para empezar.
        </div>
    </div>
    
    <div class="chat-input-area">
        <input type="text" id="userInput" placeholder="Escribe aquí..." onkeypress="handleKeyPress(event)">
        <button onclick="sendMessage()">➤</button>
        <button onclick="startVoice()" title="Hablar">🎙️</button>
    </div>
</div>

<!-- LÓGICA JAVASCRIPT CON AJAX -->
<script>
    // URL de la API que creaste (WordPress la genera dinámicamente)
    const apiUrl = "<?php echo home_url('/api-chat/'); ?>"; 

    // Elementos del DOM
    const chatBody = document.getElementById('chatBody');
    const userInput = document.getElementById('userInput');
    const chatbot = document.getElementById('chatbot');
    
    // Variables de Estado
    let step = 0; 
    let appointmentData = { fecha: '', hora: '' };

    // --- FUNCIONES BÁSICAS DE UI ---
    function toggleChat() {
        chatbot.style.display = chatbot.style.display === 'flex' ? 'none' : 'flex';
        if(chatbot.style.display === 'flex') userInput.focus();
    }
    function handleKeyPress(e) { if (e.key === 'Enter') sendMessage(); }
    
    function addMessage(text, sender) {
        const div = document.createElement('div');
        div.classList.add('message', sender === 'bot' ? 'bot-msg' : 'user-msg');
        div.innerHTML = text;
        chatBody.appendChild(div);
        chatBody.scrollTop = chatBody.scrollHeight;
    }

    // --- FUNCIÓN AJAX: ENVÍA DATOS AL SERVIDOR PHP ---
    async function enviarAlServidor(datos) {
        try {
            const respuesta = await fetch(apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(datos)
            });
            // Si la API devuelve un error HTML (ej: 404), lanzamos error
            if (!respuesta.ok) throw new Error("Error HTTP: " + respuesta.status);
            return await respuesta.json();
        } catch (error) {
            console.error("Error AJAX:", error);
            return { status: 'error', mensaje: 'Fallo de conexión con el servidor.' };
        }
    }

    // --- LÓGICA PRINCIPAL DEL BOT ---
    function sendMessage() {
        const text = userInput.value.trim();
        if (!text) return;
        addMessage(text, 'user');
        userInput.value = '';
        
        // Simular pensamiento
        setTimeout(() => processBotLogic(text), 500);
    }

    async function processBotLogic(text) {
        const lowerText = text.toLowerCase();

        // Comando Cancelar
        if (lowerText.includes('cancelar')) {
            step = 0; appointmentData = {};
            addMessage("Operación cancelada. ¿En qué más puedo ayudarte?", 'bot');
            return;
        }

        // PASO 0: INICIO
        if (step === 0) {
            if (lowerText.includes('cita') || lowerText.includes('doctor')) {
                step = 1;
                addMessage("Perfecto. Escribe la fecha que deseas en formato **AAAA-MM-DD** (Ej: 2026-02-15).", 'bot');
            } else {
                addMessage("No te he entendido. Escribe 'Quiero cita' para empezar.", 'bot');
            }
        }
        
        // PASO 1: RECIBIR FECHA Y CONSULTAR (AJAX)
        else if (step === 1) {
            // Validación simple de fecha
            if (!text.match(/^\d{4}-\d{2}-\d{2}$/)) {
                addMessage("⚠️ Formato incorrecto. Por favor usa AAAA-MM-DD (Ej: 2026-02-15).", 'bot');
                return;
            }

            appointmentData.fecha = text;
            addMessage("⏳ Consultando disponibilidad en tiempo real...", 'bot');

            // Llamada al servidor
            const respuesta = await enviarAlServidor({
                accion: 'buscar_huecos',
                fecha: appointmentData.fecha
            });

            if (respuesta.status === 'ok' && respuesta.huecos.length > 0) {
                step = 2;
                let msg = `📅 Para el **${appointmentData.fecha}** tengo estos huecos:<br><br>`;
                // Crear botones clicables para las horas
                respuesta.huecos.forEach(h => {
                    msg += `<button onclick="seleccionarHora('${h}')" style="margin:2px; padding:5px; cursor:pointer; background:#e1f0ff; border:none; border-radius:4px;">${h}</button> `;
                });
                msg += `<br><br>Haz clic en una hora o escríbela.`;
                addMessage(msg, 'bot');
            } else {
                addMessage("❌ Lo siento, no hay huecos libres ese día o es festivo. Prueba con otra fecha (AAAA-MM-DD).", 'bot');
                // Nos quedamos en step 1 para que ponga otra fecha
            }
        }

        // PASO 2: RECIBIR HORA (Puede venir por texto)
        else if (step === 2) {
            // Si el usuario escribe la hora manualmente
            appointmentData.hora = text;
            confirmarCita();
        }

        // PASO 3: CONFIRMACIÓN Y GUARDADO (AJAX)
        else if (step === 3) {
            if (lowerText.includes('si') || lowerText.includes('vale') || lowerText.includes('ok')) {
                addMessage("⏳ Registrando tu cita en la base de datos...", 'bot');

                const respuesta = await enviarAlServidor({
                    accion: 'guardar_cita',
                    fecha: appointmentData.fecha,
                    hora: appointmentData.hora
                });

                if (respuesta.status === 'ok') {
                    addMessage("✅ **¡Cita Confirmada!**", 'bot');
                    addMessage("Ya aparece en tu panel de paciente.", 'bot');
                    // Recargar la página a los 3 segundos para ver la cita
                    setTimeout(() => window.location.href = "<?php echo home_url('/area-paciente/'); ?>", 3000);
                    step = 0;
                } else {
                    addMessage("❌ Error al guardar: " + respuesta.mensaje, 'bot');
                    step = 0;
                }
            } else {
                addMessage("Cita cancelada. Empezamos de nuevo si quieres.", 'bot');
                step = 0;
            }
        }
    }

    // Helper para cuando hacen clic en el botón de hora
    function seleccionarHora(hora) {
        userInput.value = hora;
        sendMessage(); // Envía la hora como si el usuario la hubiera escrito
    }

    function confirmarCita() {
        step = 3;
        addMessage(`📝 Resumen: Cita el **${appointmentData.fecha}** a las **${appointmentData.hora}**. ¿Es correcto? (Sí/No)`, 'bot');
    }

    // Voz
    function startVoice() {
        if (!('webkitSpeechRecognition' in window)) { alert("Tu navegador no soporta voz."); return; }
        const recognition = new webkitSpeechRecognition();
        recognition.lang = 'es-ES';
        recognition.start();
        recognition.onresult = function(event) {
            userInput.value = event.results[0][0].transcript;
            sendMessage();
        };
    }
</script>

<?php get_footer(); ?>
