<?php
/**
 * Health2You - Solicitud de Videollamada Urgente
 * Permite a los pacientes solicitar una videollamada con un médico de urgencias
 */

if (!defined('ABSPATH')) {
    require_once dirname(__FILE__) . '/../../../wp-load.php';
}

if (!session_id()) session_start();

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

// Seguridad: solo pacientes
if (!isset($_SESSION['h2y_tipo']) || $_SESSION['h2y_tipo'] !== 'paciente' || empty($_SESSION['h2y_user_id'])) {
    header('Location: ' . get_stylesheet_directory_uri() . '/login.php');
    exit;
}

$paciente_id = (int) $_SESSION['h2y_user_id'];
$paciente_nombre = $_SESSION['h2y_user_nombre'] ?? 'Paciente';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitar Videollamada Urgente - Health2You</title>
    <link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/styles.css">
    <?php wp_head(); ?>
    <style>
        .urgencia-container {
            max-width: 600px;
            margin: 40px auto;
            padding: 24px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        .urgencia-header {
            background: linear-gradient(135deg, #ff6b6b 0%, #ff5252 100%);
            color: white;
            padding: 24px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 24px;
        }
        .urgencia-icon {
            font-size: 48px;
            margin-bottom: 8px;
        }
        .estado-box {
            padding: 16px;
            border-radius: 8px;
            margin: 16px 0;
            text-align: center;
        }
        .estado-solicitada {
            background: #fff3cd;
            border: 2px solid #ffc107;
        }
        .estado-aceptada {
            background: #d4edda;
            border: 2px solid #28a745;
        }
        .estado-rechazada {
            background: #f8d7da;
            border: 2px solid #dc3545;
        }
        .btn-grande {
            padding: 16px 32px;
            font-size: 18px;
            font-weight: bold;
            border-radius: 8px;
            width: 100%;
            margin: 8px 0;
        }
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #ff5252;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 16px auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .countdown {
            font-size: 24px;
            font-weight: bold;
            color: #ff5252;
        }
    </style>
</head>
<body style="background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); min-height: 100vh;">

<div style="padding: 16px; background: rgba(255,255,255,0.9);">
    <a href="<?= esc_url(get_stylesheet_directory_uri() . '/nueva_cita.php'); ?>"
       style="color: var(--primary); text-decoration: none; font-weight: 600;">
        ← Volver
    </a>
</div>

<div class="urgencia-container">
    <div class="urgencia-header">
        <div class="urgencia-icon">🚨</div>
        <h1 style="margin: 0;">Videollamada Urgente</h1>
        <p style="margin: 8px 0 0 0; opacity: 0.9;">Atención médica inmediata por videoconferencia</p>
    </div>

    <!-- Formulario de solicitud -->
    <div id="formularioSolicitud">
        <div style="background: #e8f5e9; padding: 16px; border-radius: 8px; margin-bottom: 20px;">
            <h3 style="margin: 0 0 8px 0; color: #2e7d32;">ℹ️ ¿Cómo funciona?</h3>
            <ol style="margin: 0; padding-left: 20px;">
                <li>Describe brevemente tu urgencia</li>
                <li>Un médico de urgencias recibirá tu solicitud</li>
                <li>Espera a que el médico acepte (máx. 30 minutos)</li>
                <li>Accede a la videollamada automáticamente</li>
            </ol>
        </div>

        <div class="form-group">
            <label for="motivo"><strong>Describe tu urgencia</strong></label>
            <textarea id="motivo" rows="4"
                      style="width:100%; padding:12px; border:1px solid #ccc; border-radius:4px;"
                      placeholder="Ejemplo: Dolor intenso en el pecho desde hace 2 horas, fiebre alta de 39.5°C, dificultad para respirar..."></textarea>
            <small style="color: #666;">Sé lo más específico posible. Esta información ayudará al médico a prepararse.</small>
        </div>

        <button onclick="solicitarVideollamada()" class="btn btn-grande" style="background: #ff5252; color: white;">
            📞 Solicitar Videollamada Ahora
        </button>
    </div>

    <!-- Estado de la solicitud -->
    <div id="estadoSolicitud" style="display: none;">
        <div id="estadoContent"></div>
    </div>
</div>

<script>
const apiUrl = '<?= get_stylesheet_directory_uri(); ?>/videollamada_api.php';
let videollamadaId = null;
let checkInterval = null;
let countdownInterval = null;
let expiracionTimestamp = null;

async function solicitarVideollamada() {
    const motivo = document.getElementById('motivo').value.trim();

    if (!motivo || motivo.length < 10) {
        alert('Por favor, describe tu urgencia con más detalle (mínimo 10 caracteres)');
        return;
    }

    try {
        const resp = await fetch(apiUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                accion: 'solicitar_llamada',
                motivo: motivo
            })
        });

        const data = await resp.json();

        if (data.success) {
            videollamadaId = data.videollamada_id;
            
            // IMPORTANTE: Guardar el timestamp de expiración
            expiracionTimestamp = data.expira_timestamp || (Date.now() + (30 * 60 * 1000));
            
            document.getElementById('formularioSolicitud').style.display = 'none';
            document.getElementById('estadoSolicitud').style.display = 'block';
            mostrarEstadoSolicitada();
            iniciarVerificacion();
        } else {
            if (data.solicitud) {
                // Ya tiene una solicitud activa
                videollamadaId = data.solicitud.id;
                expiracionTimestamp = data.solicitud.expira_timestamp || (Date.now() + (30 * 60 * 1000));
                
                document.getElementById('formularioSolicitud').style.display = 'none';
                document.getElementById('estadoSolicitud').style.display = 'block';
                mostrarEstadoSolicitada();
                iniciarVerificacion();
            } else {
                alert('Error: ' + data.message);
            }
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error de conexión. Por favor, intenta de nuevo.');
    }
}

function mostrarEstadoSolicitada() {
    document.getElementById('estadoContent').innerHTML = `
        <div class="estado-box estado-solicitada">
            <div class="spinner"></div>
            <h3>⏳ Esperando respuesta del médico...</h3>
            <p>Tu solicitud ha sido enviada a los médicos de urgencias disponibles.</p>
            <p>Tiempo restante: <span class="countdown" id="countdown">30:00</span></p>
        </div>
        <p style="text-align: center; color: #666; margin-top: 16px;">
            <small>No cierres esta página. Serás redirigido automáticamente cuando el médico acepte.</small>
        </p>
        <button onclick="cancelarSolicitud()" class="btn" style="background: #dc3545; color: white; margin-top: 16px;">
            ❌ Cancelar Solicitud
        </button>
    `;

    // Iniciar cuenta regresiva
    actualizarCountdown();
    countdownInterval = setInterval(actualizarCountdown, 1000);
}

function actualizarCountdown() {
    if (!expiracionTimestamp) {
        console.error('No hay timestamp de expiración');
        return;
    }

    const ahora = Date.now();
    const diff = expiracionTimestamp - ahora;

    if (diff <= 0) {
        clearInterval(countdownInterval);
        // No marcar como expirada automáticamente, esperar a que el servidor lo confirme
        const countdownEl = document.getElementById('countdown');
        if (countdownEl) {
            countdownEl.textContent = '0:00';
            countdownEl.style.color = '#dc3545';
        }
        return;
    }

    const minutos = Math.floor(diff / 60000);
    const segundos = Math.floor((diff % 60000) / 1000);

    const countdownEl = document.getElementById('countdown');
    if (countdownEl) {
        countdownEl.textContent = `${minutos}:${segundos.toString().padStart(2, '0')}`;
        
        // Cambiar color si queda menos de 5 minutos
        if (minutos < 5) {
            countdownEl.style.color = '#ff9800';
        }
        if (minutos < 2) {
            countdownEl.style.color = '#dc3545';
        }
    }
}

function mostrarEstadoAceptada(token, medicoNombre) {
    clearInterval(checkInterval);
    clearInterval(countdownInterval);

    document.getElementById('estadoContent').innerHTML = `
        <div class="estado-box estado-aceptada">
            <h3>✅ ¡Solicitud Aceptada!</h3>
            <p>El Dr./Dra. <strong>${medicoNombre}</strong> ha aceptado tu videollamada.</p>
            <p style="margin-top: 16px;">Redirigiendo a la sala de videollamada...</p>
            <div class="spinner"></div>
        </div>
    `;

    // Redirigir a la videollamada
    setTimeout(() => {
        window.location.href = '<?= get_stylesheet_directory_uri(); ?>/videollamada.php?token=' + token;
    }, 2000);
}

function mostrarEstadoRechazada() {
    clearInterval(checkInterval);
    clearInterval(countdownInterval);

    document.getElementById('estadoContent').innerHTML = `
        <div class="estado-box estado-rechazada">
            <h3>❌ Solicitud Rechazada</h3>
            <p>Lo sentimos, no hay médicos de urgencias disponibles en este momento.</p>
            <p>Por favor, intenta de nuevo más tarde o acude a urgencias físicas si es necesario.</p>
        </div>
        <button onclick="location.reload()" class="btn btn-grande" style="margin-top: 16px;">
            🔄 Intentar de Nuevo
        </button>
    `;
}

function mostrarEstadoExpirada() {
    clearInterval(checkInterval);
    clearInterval(countdownInterval);

    document.getElementById('estadoContent').innerHTML = `
        <div class="estado-box estado-rechazada">
            <h3>⏰ Tiempo Expirado</h3>
            <p>Tu solicitud ha expirado sin respuesta.</p>
            <p>Por favor, intenta de nuevo o acude a urgencias físicas si es necesario.</p>
        </div>
        <button onclick="location.reload()" class="btn btn-grande" style="margin-top: 16px;">
            🔄 Nueva Solicitud
        </button>
    `;
}

async function cancelarSolicitud() {
    if (!confirm('¿Estás seguro de que deseas cancelar la solicitud?')) {
        return;
    }

    clearInterval(checkInterval);
    clearInterval(countdownInterval);

    try {
        await fetch(apiUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                accion: 'rechazar_solicitud',
                videollamada_id: videollamadaId
            })
        });
    } catch (error) {
        console.error('Error cancelando:', error);
    }

    location.reload();
}

async function verificarEstado() {
    if (!videollamadaId) return;

    try {
        const resp = await fetch(apiUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                accion: 'verificar_estado',
                videollamada_id: videollamadaId
            })
        });

        const data = await resp.json();

        if (data.success) {
            if (data.estado === 'aceptada') {
                mostrarEstadoAceptada(data.token, data.medico_nombre);
            } else if (data.estado === 'rechazada') {
                mostrarEstadoRechazada();
            } else if (data.estado === 'expirada') {
                mostrarEstadoExpirada();
            }
            // Si está en 'solicitada', continuar esperando
        }
    } catch (error) {
        console.error('Error verificando estado:', error);
    }
}

function iniciarVerificacion() {
    verificarEstado();
    checkInterval = setInterval(verificarEstado, 3000); // Verificar cada 3 segundos
}

// Limpiar intervalos al cerrar la página
window.addEventListener('beforeunload', function() {
    if (checkInterval) clearInterval(checkInterval);
    if (countdownInterval) clearInterval(countdownInterval);
});
</script>

<?php wp_footer(); ?>
</body>
</html>
