<?php
/**
 * Health2You - Videollamada Segura
 * Sala de videollamada con acceso controlado por token
 */

if (!defined('ABSPATH')) {
    require_once dirname(__FILE__) . '/../../../wp-load.php';
}

if (!session_id()) session_start();

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

// Verificar autenticación
if (!isset($_SESSION['h2y_tipo']) || !isset($_SESSION['h2y_user_id'])) {
    header('Location: ' . get_stylesheet_directory_uri() . '/login.php');
    exit;
}

$tipo_usuario = $_SESSION['h2y_tipo'];
$user_id = $_SESSION['h2y_user_id'];
$user_nombre = $_SESSION['h2y_user_nombre'] ?? 'Usuario';

// Obtener token de la URL
$token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

if (empty($token)) {
    die('Token no proporcionado');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Videoconsulta Urgente - Health2You</title>
    
    <!-- Librería Jitsi -->
    <script src='https://meet.jit.si/external_api.js'></script>
    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@300;400;600;700&display=swap" rel="stylesheet">

    <style>
        body { margin: 0; font-family: 'Segoe UI', sans-serif; background-color: #f4f6f8; height: 100vh; display: flex; flex-direction: column; }

        header { background: white; height: 70px; display: flex; align-items: center; justify-content: space-between; padding: 0 30px; border-bottom: 2px solid #ddd; }
        .user-tag { background: #e8f5e9; color: #0f9d58; padding: 5px 15px; border-radius: 20px; font-weight: bold; }

        .main { display: flex; flex: 1; padding: 20px; gap: 20px; height: calc(100vh - 70px); box-sizing: border-box; }
        
        .video-container { flex: 3; background: black; border-radius: 15px; overflow: hidden; position: relative; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        #jitsi-meet { width: 100%; height: 100%; }

        .sidebar { flex: 1; min-width: 300px; background: white; border-radius: 15px; padding: 25px; display: flex; flex-direction: column; align-items: center; text-align: center; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        
        .logo { width: 100px; height: 100px; object-fit: contain; margin-bottom: 15px; }
        h2 { margin: 0 0 5px 0; color: #333; }
        p { margin: 0 0 20px 0; color: #777; font-size: 0.9rem; }

        .info-card { width: 100%; background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #0f9d58; text-align: left; margin-bottom: 15px; }
        .label { font-size: 0.7rem; color: #666; text-transform: uppercase; font-weight: bold; }
        .val { font-size: 1.1rem; font-weight: 600; color: #333; }

        .btn-end { margin-top: auto; width: 100%; padding: 15px; background: #ff4757; color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: bold; cursor: pointer; }
        .btn-end:hover { background: #ff6b81; }

        .btn-expulsar { width: 100%; padding: 12px; background: #ff9800; color: white; border: none; border-radius: 8px; font-size: 0.9rem; font-weight: bold; cursor: pointer; margin-top: 10px; }
        .btn-expulsar:hover { background: #f57c00; }

        .error-msg { display: none; position: absolute; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.9); color: white; flex-direction: column; justify-content: center; align-items: center; z-index: 99; }
        .error-msg h1 { margin: 0 0 16px 0; }

        .loading { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; text-align: center; }
        .spinner { border: 4px solid #f3f3f3; border-top: 4px solid #0f9d58; border-radius: 50%; width: 50px; height: 50px; animation: spin 1s linear infinite; margin: 0 auto 16px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>

    <header>
        <div class="user-tag">
            <?php if ($tipo_usuario === 'paciente'): ?>
                👤 Paciente: <?= htmlspecialchars($user_nombre) ?>
            <?php else: ?>
                👨‍⚕️ Médico: <?= htmlspecialchars($user_nombre) ?>
            <?php endif; ?>
        </div>
    </header>

    <div class="main">
        <div class="video-container">
            <div class="loading" id="loadingMsg">
                <div class="spinner"></div>
                <p>Verificando acceso y cargando videollamada...</p>
            </div>
            <div id="jitsi-meet"></div>
            <div id="errorMsg" class="error-msg">
                <h1 id="errorTitle">⛔ Error</h1>
                <p id="errorText"></p>
                <button onclick="window.location.href='<?= get_stylesheet_directory_uri(); ?>/dashboard.php'" 
                        style="margin-top: 20px; padding: 12px 24px; background: white; color: #333; border: none; border-radius: 8px; cursor: pointer; font-weight: bold;">
                    ← Volver al Dashboard
                </button>
            </div>
        </div>

        <div class="sidebar">
            <img src="<?= get_stylesheet_directory_uri(); ?>/Logo_empresa_grupo_2.png" alt="Logo" class="logo">
            <h2>HEALTH 2 YOU</h2>
            <p>Videoconsulta Urgente</p>

            <div class="info-card">
                <div class="label">SALA</div>
                <div class="val" id="sala-id">Cargando...</div>
            </div>

            <div class="info-card">
                <div class="label">ESTADO</div>
                <div class="val" id="sala-estado">Conectando...</div>
            </div>

            <div class="info-card" id="participantesCard" style="display: none;">
                <div class="label">PARTICIPANTES</div>
                <div class="val" id="num-participantes">0</div>
            </div>

            <?php if ($tipo_usuario === 'medico'): ?>
            <button onclick="expulsarPaciente()" class="btn-expulsar" id="btnExpulsar" style="display: none;">
                🚫 Expulsar Paciente
            </button>
            <?php endif; ?>

            <button onclick="finalizarLlamada()" class="btn-end">Finalizar Llamada</button>
        </div>
    </div>

    <script>
        const apiUrl = '<?= get_stylesheet_directory_uri(); ?>/videollamada_api.php';
        const token = '<?= $token ?>';
        const tipoUsuario = '<?= $tipo_usuario ?>';
        const userId = <?= $user_id ?>;
        const userNombre = '<?= htmlspecialchars($user_nombre, ENT_QUOTES) ?>';
        
        let jitsiApi = null;
        let videollamadaData = null;

        async function verificarYCargar() {
            try {
                // Verificar token
                const resp = await fetch(apiUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        accion: 'verificar_token',
                        token: token
                    })
                });
                
                const data = await resp.json();
                
                if (!data.success) {
                    mostrarError('Acceso Denegado', data.message || 'Token inválido o expirado');
                    return;
                }
                
                videollamadaData = data.videollamada;
                
                // Mostrar info
                document.getElementById('sala-id').textContent = '#' + videollamadaData.id;
                
                // Inicializar Jitsi
                await iniciarJitsi();
                
                // Registrar entrada
                await fetch(apiUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        accion: 'iniciar_llamada',
                        token: token
                    })
                });
                
                document.getElementById('loadingMsg').style.display = 'none';
                
            } catch (error) {
                console.error('Error:', error);
                mostrarError('Error de Conexión', 'No se pudo conectar con el servidor. Por favor, intenta de nuevo.');
            }
        }

        function iniciarJitsi() {
            return new Promise((resolve, reject) => {
                const domain = 'meet.jit.si';
                const options = {
                    roomName: 'Health2You_Urgente_' + videollamadaData.id + '_' + token.substring(0, 8),
                    width: '100%',
                    height: '100%',
                    parentNode: document.querySelector('#jitsi-meet'),
                    lang: 'es',
                    userInfo: {
                        displayName: userNombre + (tipoUsuario === 'medico' ? ' (Médico)' : ' (Paciente)')
                    },
                    configOverwrite: { 
                        startWithAudioMuted: false, 
                        startWithVideoMuted: false,
                        prejoinPageEnabled: false,
                        disableDeepLinking: true,
                        enableWelcomePage: false
                    },
                    interfaceConfigOverwrite: {
                        TOOLBAR_BUTTONS: ['microphone', 'camera', 'desktop', 'hangup', 'chat', 'tileview'],
                        SHOW_JITSI_WATERMARK: false,
                        SHOW_BRAND_WATERMARK: false
                    }
                };

                jitsiApi = new JitsiMeetExternalAPI(domain, options);

                jitsiApi.addEventListener('videoConferenceJoined', () => {
                    console.log('Usuario unido a la conferencia');
                    document.getElementById('sala-estado').textContent = 'Conectado';
                    document.getElementById('sala-estado').style.color = '#0f9d58';
                    document.getElementById('participantesCard').style.display = 'block';
                    actualizarParticipantes();
                    resolve();
                });

                jitsiApi.addEventListener('participantJoined', () => {
                    actualizarParticipantes();
                });

                jitsiApi.addEventListener('participantLeft', () => {
                    actualizarParticipantes();
                });

                jitsiApi.addEventListener('readyToClose', () => {
                    finalizarLlamada();
                });
            });
        }

        function actualizarParticipantes() {
            if (!jitsiApi) return;
            
            const num = jitsiApi.getNumberOfParticipants();
            document.getElementById('num-participantes').textContent = num;
            
            // Mostrar botón de expulsar si es médico y hay paciente
            if (tipoUsuario === 'medico' && num > 0) {
                document.getElementById('btnExpulsar').style.display = 'block';
            }
            
            // Control de máximo 2 personas
            if (num > 2) {
                mostrarError('Sala Llena', 'Solo se permiten 2 participantes en la videollamada');
                setTimeout(() => {
                    if (jitsiApi) jitsiApi.dispose();
                    finalizarLlamada();
                }, 3000);
            }
        }

        async function finalizarLlamada() {
            if (jitsiApi) {
                jitsiApi.dispose();
                jitsiApi = null;
            }
            
            try {
                await fetch(apiUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        accion: 'finalizar_llamada',
                        token: token
                    })
                });
            } catch (error) {
                console.error('Error finalizando:', error);
            }
            
            window.location.href = '<?= get_stylesheet_directory_uri(); ?>/dashboard.php';
        }

        async function expulsarPaciente() {
            if (!confirm('¿Estás seguro de que quieres expulsar al paciente de la videollamada?')) {
                return;
            }
            
            try {
                await fetch(apiUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        accion: 'expulsar_participante',
                        token: token,
                        usuario_id: videollamadaData.paciente_id
                    })
                });
                
                alert('Paciente expulsado. Finalizando llamada...');
                finalizarLlamada();
            } catch (error) {
                console.error('Error expulsando:', error);
            }
        }

        function mostrarError(titulo, mensaje) {
            document.getElementById('loadingMsg').style.display = 'none';
            document.getElementById('errorTitle').textContent = titulo;
            document.getElementById('errorText').textContent = mensaje;
            document.getElementById('errorMsg').style.display = 'flex';
        }

        // Iniciar al cargar la página
        window.addEventListener('load', verificarYCargar);

        // Limpiar al cerrar la página
        window.addEventListener('beforeunload', () => {
            if (jitsiApi) {
                finalizarLlamada();
            }
        });
    </script>

</body>
</html>
