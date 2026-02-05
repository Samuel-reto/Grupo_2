<?php
/**
 * Health2You - API de Videollamadas
 * Versión modificada con cooldown e invalidación de tokens
 */

if (!defined('ABSPATH')) {
    require_once dirname(__FILE__) . '/../../../wp-load.php';
}

if (!session_id()) session_start();

header('Content-Type: application/json; charset=utf-8');

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

// Verificar autenticación
if (!isset($_SESSION['h2y_tipo']) || !isset($_SESSION['h2y_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autenticado']);
    exit;
}

$tipo_usuario = $_SESSION['h2y_tipo'];
$user_id = (int) $_SESSION['h2y_user_id'];

// Leer acción
$raw_input = file_get_contents('php://input');
$input = json_decode($raw_input, true);
$accion = $input['accion'] ?? '';

// Definir constantes si no existen
if (!defined('H2Y_VIDEOLLAMADA')) {
    define('H2Y_VIDEOLLAMADA', $wpdb->prefix . 'h2y_videollamada');
}
if (!defined('H2Y_VIDEOLLAMADA_LOG')) {
    define('H2Y_VIDEOLLAMADA_LOG', $wpdb->prefix . 'h2y_videollamada_log');
}
if (!defined('VIDEOLLAMADA_EXPIRACION_MINUTOS')) {
    define('VIDEOLLAMADA_EXPIRACION_MINUTOS', 30);
}
if (!defined('VIDEOLLAMADA_MAX_PARTICIPANTES')) {
    define('VIDEOLLAMADA_MAX_PARTICIPANTES', 2);
}
if (!defined('VIDEOLLAMADA_COOLDOWN_MINUTOS')) {
    define('VIDEOLLAMADA_COOLDOWN_MINUTOS', 10);
}

// ============================================================================
// FUNCIONES AUXILIARES
// ============================================================================

function generar_token_seguro() {
    return bin2hex(random_bytes(32));
}

function limpiar_expiradas($wpdb) {
    $wpdb->query("
        UPDATE " . H2Y_VIDEOLLAMADA . "
        SET estado = 'expirada'
        WHERE estado IN ('solicitada', 'aceptada')
        AND expira_en < NOW()
    ");
}

function registrar_log($wpdb, $videollamada_id, $usuario_id, $tipo_usuario, $accion) {
    $wpdb->insert(
        H2Y_VIDEOLLAMADA_LOG,
        [
            'videollamada_id' => $videollamada_id,
            'usuario_id' => $usuario_id,
            'tipo_usuario' => $tipo_usuario,
            'accion' => $accion,
            'timestamp' => current_time('mysql')
        ],
        ['%d', '%d', '%s', '%s', '%s']
    );
}

// Limpiar expiradas
limpiar_expiradas($wpdb);

switch ($accion) {

    // ============================================================
    // SOLICITAR LLAMADA (CON COOLDOWN)
    // ============================================================
    case 'solicitar_llamada':

        if ($tipo_usuario !== 'paciente') {
            echo json_encode(['success' => false, 'message' => 'Solo pacientes pueden solicitar llamadas']);
            exit;
        }

        // ============================================================
        // COOLDOWN: Prevenir solicitudes consecutivas
        // ============================================================
        $ultima_solicitud = $wpdb->get_row($wpdb->prepare("
            SELECT fecha_solicitud, estado, videollamada_id
            FROM " . H2Y_VIDEOLLAMADA . " 
            WHERE paciente_id = %d 
            ORDER BY fecha_solicitud DESC 
            LIMIT 1
        ", $user_id));

        if ($ultima_solicitud) {
            $tiempo_transcurrido = (time() - strtotime($ultima_solicitud->fecha_solicitud)) / 60; // en minutos
            $cooldown_minutos = VIDEOLLAMADA_COOLDOWN_MINUTOS;
            
            if ($tiempo_transcurrido < $cooldown_minutos) {
                $minutos_restantes = ceil($cooldown_minutos - $tiempo_transcurrido);
                echo json_encode([
                    'success' => false, 
                    'message' => "Por favor, espera $minutos_restantes minuto(s) antes de realizar otra solicitud de urgencia.",
                    'cooldown' => true,
                    'minutos_restantes' => $minutos_restantes
                ]);
                exit;
            }
        }
        // ============================================================

        $solicitud_activa = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM " . H2Y_VIDEOLLAMADA . "
            WHERE paciente_id = %d
            AND estado IN ('solicitada', 'aceptada', 'en_curso')
            LIMIT 1
        ", $user_id));

        if ($solicitud_activa) {
            echo json_encode([
                'success' => false,
                'message' => 'Ya tienes una solicitud activa',
                'solicitud' => [
                    'id' => $solicitud_activa->videollamada_id,
                    'estado' => $solicitud_activa->estado,
                    'token' => $solicitud_activa->token,
                    'expira_timestamp' => strtotime($solicitud_activa->expira_en) * 1000
                ]
            ]);
            exit;
        }

        $motivo = sanitize_textarea_field($input['motivo'] ?? '');
        $token = generar_token_seguro();

        $expira_en = date(
            'Y-m-d H:i:s',
            strtotime('+' . VIDEOLLAMADA_EXPIRACION_MINUTOS . ' minutes')
        );

        $resultado = $wpdb->insert(
            H2Y_VIDEOLLAMADA,
            [
                'paciente_id' => $user_id,
                'token' => $token,
                'estado' => 'solicitada',
                'motivo' => $motivo,
                'fecha_solicitud' => current_time('mysql'),
                'expira_en' => $expira_en
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s']
        );

        if ($resultado) {
            $videollamada_id = $wpdb->insert_id;
            registrar_log($wpdb, $videollamada_id, $user_id, $tipo_usuario, 'solicitar');
            
            echo json_encode([
                'success' => true,
                'message' => 'Solicitud enviada',
                'videollamada_id' => $videollamada_id,
                'token' => $token,
                'expira_en' => $expira_en,
                'expira_timestamp' => strtotime($expira_en) * 1000
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error al crear solicitud']);
        }

        break;

    // ============================================================
    // VERIFICAR ESTADO
    // ============================================================
    case 'verificar_estado':

        if ($tipo_usuario !== 'paciente') {
            echo json_encode(['success' => false, 'message' => 'Solo pacientes']);
            exit;
        }

        $videollamada_id = (int) ($input['videollamada_id'] ?? 0);

        $solicitud = $wpdb->get_row($wpdb->prepare("
            SELECT v.*, CONCAT(m.nombre, ' ', m.apellidos) as medico_nombre
            FROM " . H2Y_VIDEOLLAMADA . " v
            LEFT JOIN " . H2Y_MEDICO . " m ON v.medico_id = m.medico_id
            WHERE v.videollamada_id = %d AND v.paciente_id = %d
        ", $videollamada_id, $user_id));

        if (!$solicitud) {
            echo json_encode(['success' => false, 'message' => 'Solicitud no encontrada']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'estado' => $solicitud->estado,
            'token' => $solicitud->token,
            'medico_nombre' => $solicitud->medico_nombre,
            'expira_en' => $solicitud->expira_en,
            'expira_timestamp' => strtotime($solicitud->expira_en) * 1000
        ]);

        break;

    // ============================================================
    // VERIFICAR TOKEN
    // ============================================================
    case 'verificar_token':

        $token = sanitize_text_field($input['token'] ?? '');

        if (empty($token)) {
            echo json_encode(['success' => false, 'message' => 'Token vacío']);
            exit;
        }

        $videollamada = $wpdb->get_row($wpdb->prepare("
            SELECT
                v.*,
                CONCAT(p.nombre, ' ', p.apellidos) as paciente_nombre,
                CONCAT(m.nombre, ' ', m.apellidos) as medico_nombre
            FROM " . H2Y_VIDEOLLAMADA . " v
            INNER JOIN " . H2Y_PACIENTE . " p ON v.paciente_id = p.paciente_id
            LEFT JOIN " . H2Y_MEDICO . " m ON v.medico_id = m.medico_id
            WHERE v.token = %s
            AND v.estado IN ('aceptada', 'en_curso')
            AND v.expira_en > NOW()
        ", $token));

        if (!$videollamada) {
            echo json_encode(['success' => false, 'message' => 'Token inválido o expirado']);
            exit;
        }

        $tiene_permiso = false;

        if ($tipo_usuario === 'paciente' && $videollamada->paciente_id == $user_id) {
            $tiene_permiso = true;
        } elseif ($tipo_usuario === 'medico' && $videollamada->medico_id == $user_id) {
            $tiene_permiso = true;
        }

        if (!$tiene_permiso) {
            echo json_encode(['success' => false, 'message' => 'Sin permiso']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'videollamada' => [
                'id' => $videollamada->videollamada_id,
                'token' => $videollamada->token,
                'paciente_nombre' => $videollamada->paciente_nombre,
                'medico_nombre' => $videollamada->medico_nombre,
                'estado' => $videollamada->estado,
                'expira_timestamp' => strtotime($videollamada->expira_en) * 1000
            ]
        ]);

        break;

    // ============================================================
    // OBTENER SOLICITUDES (PARA MÉDICOS)
    // ============================================================
    case 'obtener_solicitudes':

        if ($tipo_usuario !== 'medico') {
            echo json_encode(['success' => false, 'message' => 'Solo médicos']);
            exit;
        }

        // Obtener solicitudes pendientes que no hayan expirado
        $solicitudes = $wpdb->get_results("
            SELECT
                v.videollamada_id,
                v.motivo,
                v.expira_en,
                CONCAT(p.nombre, ' ', p.apellidos) as paciente_nombre,
                p.numero_tsi,
                TIMESTAMPDIFF(MINUTE, NOW(), v.expira_en) as minutos_restantes
            FROM " . H2Y_VIDEOLLAMADA . " v
            INNER JOIN " . H2Y_PACIENTE . " p ON v.paciente_id = p.paciente_id
            WHERE v.estado = 'solicitada'
            AND v.expira_en > NOW()
            ORDER BY v.fecha_solicitud ASC
        ");

        echo json_encode([
            'success' => true,
            'solicitudes' => $solicitudes
        ]);

        break;

    // ============================================================
    // ACEPTAR SOLICITUD (MÉDICO)
    // ============================================================
    case 'aceptar_solicitud':

        if ($tipo_usuario !== 'medico') {
            echo json_encode(['success' => false, 'message' => 'Solo médicos']);
            exit;
        }

        $videollamada_id = (int) ($input['videollamada_id'] ?? 0);

        // Verificar que siga disponible
        $existe = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM " . H2Y_VIDEOLLAMADA . "
            WHERE videollamada_id = %d AND estado = 'solicitada' AND expira_en > NOW()
        ", $videollamada_id));

        if (!$existe) {
            echo json_encode(['success' => false, 'message' => 'La solicitud ya no está disponible o expiró']);
            exit;
        }

        // Actualizar estado y asignar médico
        $resultado = $wpdb->update(
            H2Y_VIDEOLLAMADA,
            [
                'estado' => 'aceptada',
                'medico_id' => $user_id,
                'fecha_inicio' => current_time('mysql')
            ],
            ['videollamada_id' => $videollamada_id],
            ['%s', '%d', '%s'],
            ['%d']
        );

        if ($resultado) {
            registrar_log($wpdb, $videollamada_id, $user_id, $tipo_usuario, 'aceptar');
            
            echo json_encode([
                'success' => true,
                'token' => $existe->token,
                'message' => 'Llamada aceptada'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error al aceptar la llamada']);
        }

        break;

    // ============================================================
    // INICIAR LLAMADA (Cambiar estado a 'en_curso')
    // ============================================================
    case 'iniciar_llamada':

        $token = sanitize_text_field($input['token'] ?? '');

        if (empty($token)) {
            echo json_encode(['success' => false, 'message' => 'Token no proporcionado']);
            exit;
        }

        // Verificar token válido
        $videollamada = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM " . H2Y_VIDEOLLAMADA . "
            WHERE token = %s
            AND estado IN ('aceptada', 'en_curso')
        ", $token));

        if (!$videollamada) {
            echo json_encode(['success' => false, 'message' => 'Token inválido']);
            exit;
        }

        // Verificar permisos
        $tiene_permiso = false;
        if ($tipo_usuario === 'paciente' && $videollamada->paciente_id == $user_id) {
            $tiene_permiso = true;
        } elseif ($tipo_usuario === 'medico' && $videollamada->medico_id == $user_id) {
            $tiene_permiso = true;
        }

        if (!$tiene_permiso) {
            echo json_encode(['success' => false, 'message' => 'Sin permiso']);
            exit;
        }

        // Actualizar a 'en_curso' si aún está en 'aceptada'
        if ($videollamada->estado === 'aceptada') {
            $wpdb->update(
                H2Y_VIDEOLLAMADA,
                ['estado' => 'en_curso'],
                ['token' => $token],
                ['%s'],
                ['%s']
            );

            registrar_log($wpdb, $videollamada->videollamada_id, $user_id, $tipo_usuario, 'iniciar');
        }

        echo json_encode(['success' => true]);

        break;

    // ============================================================
    // FINALIZAR LLAMADA (Invalidar Token)
    // ============================================================
    case 'finalizar_llamada':

        $token = sanitize_text_field($input['token'] ?? '');

        if (empty($token)) {
            echo json_encode(['success' => false, 'message' => 'Token no proporcionado']);
            exit;
        }

        // Verificar que el usuario tiene permiso para finalizar esta llamada
        $videollamada = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM " . H2Y_VIDEOLLAMADA . "
            WHERE token = %s
        ", $token));

        if (!$videollamada) {
            echo json_encode(['success' => false, 'message' => 'Videollamada no encontrada']);
            exit;
        }

        // Verificar permisos
        $tiene_permiso = false;
        if ($tipo_usuario === 'paciente' && $videollamada->paciente_id == $user_id) {
            $tiene_permiso = true;
        } elseif ($tipo_usuario === 'medico' && $videollamada->medico_id == $user_id) {
            $tiene_permiso = true;
        }

        if (!$tiene_permiso) {
            echo json_encode(['success' => false, 'message' => 'Sin permiso para finalizar esta llamada']);
            exit;
        }

        // Actualizar estado a 'finalizada' y registrar fecha de fin
        $resultado = $wpdb->update(
            H2Y_VIDEOLLAMADA,
            [
                'estado' => 'finalizada',
                'fecha_fin' => current_time('mysql')
            ],
            ['token' => $token],
            ['%s', '%s'],
            ['%s']
        );

        if ($resultado !== false) {
            // Registrar en log
            registrar_log($wpdb, $videollamada->videollamada_id, $user_id, $tipo_usuario, 'finalizar');
            
            echo json_encode([
                'success' => true, 
                'message' => 'Llamada finalizada correctamente'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error al finalizar la llamada']);
        }

        break;

    // ============================================================
    // RECHAZAR SOLICITUD
    // ============================================================
    case 'rechazar_solicitud':

        $videollamada_id = (int) ($input['videollamada_id'] ?? 0);

        // Verificar propiedad si es paciente, o permiso si es médico
        $where_extra = "";
        if ($tipo_usuario === 'paciente') {
            $where_extra = $wpdb->prepare(" AND paciente_id = %d", $user_id);
        }

        $existe = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM " . H2Y_VIDEOLLAMADA . "
            WHERE videollamada_id = %d $where_extra
        ", $videollamada_id));

        if (!$existe) {
            echo json_encode(['success' => false, 'message' => 'Solicitud no encontrada']);
            exit;
        }

        $wpdb->update(
            H2Y_VIDEOLLAMADA,
            ['estado' => 'rechazada'],
            ['videollamada_id' => $videollamada_id],
            ['%s'],
            ['%d']
        );

        registrar_log($wpdb, $videollamada_id, $user_id, $tipo_usuario, 'rechazar');

        echo json_encode(['success' => true, 'message' => 'Solicitud finalizada']);

        break;

    // ============================================================
    // EXPULSAR PARTICIPANTE (SOLO MÉDICOS)
    // ============================================================
    case 'expulsar_participante':

        if ($tipo_usuario !== 'medico') {
            echo json_encode(['success' => false, 'message' => 'Solo médicos pueden expulsar participantes']);
            exit;
        }

        $token = sanitize_text_field($input['token'] ?? '');

        if (empty($token)) {
            echo json_encode(['success' => false, 'message' => 'Token no proporcionado']);
            exit;
        }

        // Verificar que el médico es el asignado a esta llamada
        $videollamada = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM " . H2Y_VIDEOLLAMADA . "
            WHERE token = %s AND medico_id = %d
        ", $token, $user_id));

        if (!$videollamada) {
            echo json_encode(['success' => false, 'message' => 'No tienes permiso para expulsar de esta llamada']);
            exit;
        }

        // Finalizar la llamada
        $wpdb->update(
            H2Y_VIDEOLLAMADA,
            [
                'estado' => 'finalizada',
                'fecha_fin' => current_time('mysql')
            ],
            ['token' => $token],
            ['%s', '%s'],
            ['%s']
        );

        registrar_log($wpdb, $videollamada->videollamada_id, $user_id, $tipo_usuario, 'expulsar');

        echo json_encode(['success' => true, 'message' => 'Participante expulsado y llamada finalizada']);

        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Acción no soportada']);
}

exit;
