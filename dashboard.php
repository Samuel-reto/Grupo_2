<?php
/**
 * Health2You - Enviar Justificante por Email
 * Versión usando PHPMailer con SMTP (igual que el 2FA)
 */

if (!defined('ABSPATH')) {
    require_once('../../../wp-load.php');
}

if (!session_id()) session_start();

header('Content-Type: application/json; charset=utf-8');

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

// Cargar PHPMailer (igual que login.php)
$autoload_path = get_stylesheet_directory() . '/vendor/autoload.php';
if (file_exists($autoload_path)) {
    require_once $autoload_path;
} else {
    require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
    require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
    require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// DEBUG: Log de inicio
error_log("==========================================");
error_log("=== INICIO ENVIAR JUSTIFICANTE ===");
error_log("==========================================");

// Definir constantes si no existen
if (!defined('H2Y_CITA')) {
    define('H2Y_CITA', $wpdb->prefix . 'h2y_cita');
}
if (!defined('H2Y_MEDICO')) {
    define('H2Y_MEDICO', $wpdb->prefix . 'h2y_medico');
}
if (!defined('H2Y_PACIENTE')) {
    define('H2Y_PACIENTE', $wpdb->prefix . 'h2y_paciente');
}

// Seguridad - verificar tipo de usuario
if (!isset($_SESSION['h2y_tipo']) || $_SESSION['h2y_tipo'] !== 'paciente') {
    error_log("❌ Error: Tipo de usuario no válido");
    echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
    exit;
}

// Obtener ID del paciente de la sesión
$paciente_id_session = $_SESSION['h2y_user_id'] ?? 0;
error_log("✓ ID Paciente de sesión: " . $paciente_id_session);

if (!$paciente_id_session) {
    error_log("❌ Error: No hay ID de paciente en sesión");
    echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
    exit;
}

// Verificar que sea POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("❌ Error: Método no permitido");
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

// Leer datos JSON del request
$raw_input = file_get_contents('php://input');
$input = json_decode($raw_input, true);

$cita_id = (int)($input['cita_id'] ?? 0);
$email_destino = sanitize_email($input['email'] ?? '');

error_log("✓ Cita ID: $cita_id");
error_log("✓ Email destino: $email_destino");

if ($cita_id <= 0) {
    error_log("❌ Error: ID de cita inválido");
    echo json_encode(['success' => false, 'message' => 'ID de cita inválido']);
    exit;
}

if (!is_email($email_destino)) {
    error_log("❌ Error: Email inválido");
    echo json_encode(['success' => false, 'message' => 'Email inválido']);
    exit;
}

// Query sin numero_colegiado (campo que no existe)
$query = "
    SELECT
        c.cita_id,
        c.paciente_id,
        c.medico_id,
        c.fecha_hora_inicio,
        c.fecha_hora_fin,
        c.estado,
        c.sintomas,
        m.nombre as medico_nombre,
        m.apellidos as medico_apellidos,
        m.especialidad,
        p.nombre as paciente_nombre,
        p.apellidos as paciente_apellidos,
        p.numero_tsi,
        p.email as paciente_email
    FROM " . H2Y_CITA . " c
    LEFT JOIN " . H2Y_MEDICO . " m ON c.medico_id = m.medico_id
    LEFT JOIN " . H2Y_PACIENTE . " p ON c.paciente_id = p.paciente_id
    WHERE c.cita_id = %d
";

$cita = $wpdb->get_row($wpdb->prepare($query, $cita_id));

if ($wpdb->last_error) {
    error_log("❌ Error MySQL: " . $wpdb->last_error);
    echo json_encode(['success' => false, 'message' => 'Error de base de datos: ' . $wpdb->last_error]);
    exit;
}

if (!$cita) {
    error_log("❌ Error: Cita no encontrada");
    echo json_encode(['success' => false, 'message' => 'Cita no encontrada']);
    exit;
}

// Verificar pertenencia
if ((int)$cita->paciente_id !== (int)$paciente_id_session) {
    error_log("❌ Error de seguridad: La cita no pertenece al paciente actual");
    echo json_encode(['success' => false, 'message' => 'No tienes permiso para acceder a esta cita']);
    exit;
}

// Verificar estado
if ($cita->estado !== 'asistida') {
    error_log("⚠️ Advertencia: Cita no asistida - Estado: " . $cita->estado);
    echo json_encode([
        'success' => false,
        'message' => 'Solo se pueden enviar justificantes de citas asistidas. Estado actual: ' . $cita->estado
    ]);
    exit;
}

error_log("✓ Todas las verificaciones pasadas. Generando email...");

// Construir nombres completos
$medico_nombre_completo = trim(($cita->medico_nombre ?? '') . ' ' . ($cita->medico_apellidos ?? ''));
$paciente_nombre_completo = trim(($cita->paciente_nombre ?? '') . ' ' . ($cita->paciente_apellidos ?? ''));

$fecha_inicio = strtotime($cita->fecha_hora_inicio);
$fecha_fin = strtotime($cita->fecha_hora_fin);
$duracion = ($fecha_fin - $fecha_inicio) / 60;
$codigo_verificacion = strtoupper(substr(md5($cita_id . $paciente_id_session . time()), 0, 8));

// Preparar contenido del email HTML
$html_body = '
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Arial, sans-serif; background-color: #f5f7fa;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f5f7fa; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); overflow: hidden;">

                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #00796b 0%, #004d40 100%); padding: 40px 30px; text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 32px; font-weight: 600; letter-spacing: -0.5px;">
                                🏥 Health2You
                            </h1>
                            <p style="margin: 10px 0 0 0; color: rgba(255,255,255,0.9); font-size: 16px;">
                                Justificante de Asistencia Médica
                            </p>
                        </td>
                    </tr>

                    <!-- Contenido -->
                    <tr>
                        <td style="padding: 40px 30px;">
                            <p style="margin: 0 0 20px 0; font-size: 18px; color: #2c3e50; line-height: 1.6;">
                                Estimado/a <strong style="color: #00796b;">' . htmlspecialchars($paciente_nombre_completo) . '</strong>,
                            </p>

                            <p style="margin: 0 0 30px 0; font-size: 15px; color: #5a6c7d; line-height: 1.6;">
                                A continuación encontrará el justificante de su cita médica:
                            </p>

                            <!-- Detalles de la cita -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f8f9fa; border-left: 4px solid #00796b; border-radius: 8px; margin: 20px 0;">
                                <tr>
                                    <td style="padding: 20px;">
                                        <h3 style="margin: 0 0 15px 0; color: #00796b; font-size: 18px;">📋 Detalles de la Cita</h3>

                                        <table width="100%" cellpadding="0" cellspacing="0" style="font-size: 14px;">
                                            <tr>
                                                <td style="padding: 8px 0; color: #5a6c7d;"><strong style="color: #2c3e50;">Paciente:</strong></td>
                                                <td style="padding: 8px 0; color: #5a6c7d;">' . htmlspecialchars($paciente_nombre_completo) . '</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 8px 0; color: #5a6c7d;"><strong style="color: #2c3e50;">Nº TSI:</strong></td>
                                                <td style="padding: 8px 0; color: #5a6c7d;">' . htmlspecialchars($cita->numero_tsi ?: 'No registrado') . '</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 8px 0; color: #5a6c7d;"><strong style="color: #2c3e50;">Fecha:</strong></td>
                                                <td style="padding: 8px 0; color: #5a6c7d;">' . date('d/m/Y', $fecha_inicio) . '</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 8px 0; color: #5a6c7d;"><strong style="color: #2c3e50;">Hora:</strong></td>
                                                <td style="padding: 8px 0; color: #5a6c7d;">' . date('H:i', $fecha_inicio) . ' - ' . date('H:i', $fecha_fin) . '</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 8px 0; color: #5a6c7d;"><strong style="color: #2c3e50;">Médico:</strong></td>
                                                <td style="padding: 8px 0; color: #5a6c7d;">Dr./Dra. ' . htmlspecialchars($medico_nombre_completo) . '</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 8px 0; color: #5a6c7d;"><strong style="color: #2c3e50;">Especialidad:</strong></td>
                                                <td style="padding: 8px 0; color: #5a6c7d;">' . htmlspecialchars($cita->especialidad ?: 'Medicina General') . '</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 8px 0; color: #5a6c7d;"><strong style="color: #2c3e50;">Duración:</strong></td>
                                                <td style="padding: 8px 0; color: #5a6c7d;">' . $duracion . ' minutos</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- Certificación -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="background: linear-gradient(135deg, #e0f2f1 0%, #b2dfdb 100%); border: 2px solid #00796b; border-radius: 12px; margin: 30px 0;">
                                <tr>
                                    <td style="padding: 30px; text-align: center;">
                                        <h3 style="margin: 0 0 15px 0; color: #00796b; font-size: 20px;">✓ CERTIFICACIÓN</h3>
                                        <p style="margin: 0; font-size: 15px; color: #2c3e50; line-height: 1.8;">
                                            Se certifica que el/la paciente <strong>' . htmlspecialchars($paciente_nombre_completo) . '</strong>
                                            acudió a consulta médica el día <strong>' . date('d/m/Y', $fecha_inicio) . '</strong>
                                            a las <strong>' . date('H:i', $fecha_inicio) . '</strong> horas en las instalaciones de Health2You,
                                            siendo atendido/a por el Dr./Dra. <strong>' . htmlspecialchars($medico_nombre_completo) . '</strong>
                                            (' . htmlspecialchars($cita->especialidad ?: 'Medicina General') . ').
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <!-- Advertencia -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #fff9e6; border-left: 4px solid #ffc107; border-radius: 8px; margin-top: 30px;">
                                <tr>
                                    <td style="padding: 20px;">
                                        <p style="margin: 0; font-size: 14px; color: #856404; line-height: 1.6;">
                                            <strong>⚠️ Importante:</strong> Este documento es un justificante oficial generado por el sistema Health2You.
                                            Puede ser presentado en su lugar de trabajo o cualquier institución que lo requiera.
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <!-- Código de verificación -->
                            <p style="margin: 30px 0 0 0; font-size: 12px; color: #95a5a6; line-height: 1.8;">
                                <strong>Código de verificación:</strong> ' . $codigo_verificacion . '<br>
                                <strong>ID de cita:</strong> ' . str_pad($cita_id, 6, '0', STR_PAD_LEFT) . '<br>
                                <strong>Fecha de emisión:</strong> ' . date('d/m/Y H:i:s') . '
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8f9fa; padding: 30px; text-align: center; border-top: 1px solid #e9ecef;">
                            <p style="margin: 0 0 10px 0; font-size: 16px; color: #2c3e50; font-weight: 600;">
                                Health2You - Tu salud, nuestra prioridad
                            </p>
                            <p style="margin: 0; font-size: 13px; color: #95a5a6; line-height: 1.6;">
                                © ' . date('Y') . ' Health2You. Todos los derechos reservados.<br>
                                Este es un correo automático, por favor no responder.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>';

// Texto plano alternativo
$text_body = "
JUSTIFICANTE DE ASISTENCIA MÉDICA
Health2You

Estimado/a " . $paciente_nombre_completo . ",

Se certifica que acudió a consulta médica:

- Fecha: " . date('d/m/Y', $fecha_inicio) . "
- Hora: " . date('H:i', $fecha_inicio) . " - " . date('H:i', $fecha_fin) . "
- Médico: Dr./Dra. " . $medico_nombre_completo . "
- Especialidad: " . ($cita->especialidad ?: 'Medicina General') . "
- TSI: " . ($cita->numero_tsi ?: 'No registrado') . "

Código de verificación: " . $codigo_verificacion . "
ID de cita: " . str_pad($cita_id, 6, '0', STR_PAD_LEFT) . "

Health2You - Tu salud, nuestra prioridad
";

error_log("📧 Enviando email usando PHPMailer con SMTP...");
error_log("  - Para: " . $email_destino);
error_log("  - SMTP Host: " . SMTP_HOST);
error_log("  - SMTP Port: " . SMTP_PORT);

// Enviar email usando PHPMailer (IGUAL QUE EL 2FA)
try {
    $mail = new PHPMailer(true);

    // Configuración SMTP (igual que login.php)
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USERNAME;
    $mail->Password = SMTP_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = SMTP_PORT;
    $mail->CharSet = 'UTF-8';

    // Remitente y destinatario
    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress($email_destino, $paciente_nombre_completo);

    // Contenido
    $mail->isHTML(true);
    $mail->Subject = '📄 Justificante de Cita Médica - Health2You';
    $mail->Body = $html_body;
    $mail->AltBody = $text_body;

    // Enviar
    $mail->send();

    error_log("✅ ¡ÉXITO! Justificante enviado correctamente");
    error_log("  - Cita ID: $cita_id");
    error_log("  - Email: $email_destino");
    error_log("  - Paciente: $paciente_nombre_completo");
    error_log("==========================================");

    echo json_encode([
        'success' => true,
        'message' => 'Justificante enviado correctamente a ' . $email_destino
    ]);

} catch (Exception $e) {
    error_log("❌ ERROR al enviar el email con PHPMailer");
    error_log("  - Error: " . $mail->ErrorInfo);
    error_log("  - Exception: " . $e->getMessage());
    error_log("==========================================");

    echo json_encode([
        'success' => false,
        'message' => 'Error al enviar el email: ' . $mail->ErrorInfo
    ]);
}

exit;
root@ip-10-0-5-162:/var/www/html/wp-content/themes/health2you# cat dashboard.php
<?php
if (!defined('ABSPATH')) require_once('../../../wp-load.php');

// IMPORTANTE: Iniciar sesión ANTES de cualquier cosa
if (!session_id()) {
    session_start();
}

ini_set('display_errors', 1);
error_reporting(E_ALL);

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

// Verificar sesión activa
if (!isset($_SESSION['h2y_tipo']) || !isset($_SESSION['h2y_user_id'])) {
    session_destroy();
    session_start();
    ?>
    <script>
        alert('No hay sesión activa. Redirigiendo al login...');
        window.location.href = '<?= get_stylesheet_directory_uri(); ?>/login.php';
    </script>
    <?php
    exit;
}

$tipo_usuario = $_SESSION['h2y_tipo'];
$user_id = $_SESSION['h2y_user_id'];
$user_nombre = $_SESSION['h2y_user_nombre'] ?? 'Usuario';

// Variables específicas según tipo
$especialidad = $_SESSION['h2y_especialidad'] ?? '';
$success_msg = "";

if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'asistida':   $success_msg = '✅ Cita marcada como asistida correctamente.'; break;
        case 'nueva':      $success_msg = '✅ Cita reservada correctamente.'; break;
        case 'modificada': $success_msg = '✅ Cita modificada correctamente.'; break;
        case 'cancelada':  $success_msg = '✅ Cita cancelada correctamente.'; break;
        case 'cita_creada': $success_msg = '✅ Cita creada exitosamente desde el chatbot.'; break;
        case '1':          $success_msg = '✅ Cita marcada como asistida correctamente.'; break;
    }
}

// =============================================================================
// LÓGICA SEGÚN TIPO DE USUARIO
// =============================================================================

// Definición de fechas globales para uso en consultas
$hoy = date('Y-m-d');
$hoy_inicio = "$hoy 00:00:00";
$hoy_fin = "$hoy 23:59:59";

if ($tipo_usuario === 'paciente') {
    // =========== PACIENTE ===========
    $paciente_id = $user_id;

    // Citas pendientes (próximas)
    $citas_pendientes = $wpdb->get_results($wpdb->prepare("
        SELECT c.*, CONCAT(m.nombre, ' ', m.apellidos) AS medico_nombre, m.especialidad
        FROM " . H2Y_CITA . " c
        JOIN " . H2Y_MEDICO . " m ON c.medico_id = m.medico_id
        WHERE c.paciente_id = %d
          AND c.estado = 'pendiente'
          AND c.fecha_hora_inicio >= CURRENT_DATE
        ORDER BY c.fecha_hora_inicio ASC
    ", $paciente_id));

    // Citas de hoy
    $citas_hoy = $wpdb->get_results($wpdb->prepare("
    SELECT c.*, CONCAT(m.nombre, ' ', m.apellidos) AS medico_nombre, p.email
    FROM " . H2Y_CITA . " c
    JOIN " . H2Y_MEDICO . " m ON c.medico_id = m.medico_id
    JOIN " . H2Y_PACIENTE . " p ON c.paciente_id = p.paciente_id
    WHERE c.paciente_id = %d
      AND DATE(c.fecha_hora_inicio) = %s
    ORDER BY c.fecha_hora_inicio ASC
    ", $paciente_id, $hoy));

    // Historial (pasadas)
    $citas_pasadas = $wpdb->get_results($wpdb->prepare("
        SELECT c.*, CONCAT(m.nombre, ' ', m.apellidos) AS medico_nombre
        FROM " . H2Y_CITA . " c
        JOIN " . H2Y_MEDICO . " m ON c.medico_id = m.medico_id
        WHERE c.paciente_id = %d
          AND c.fecha_hora_inicio < NOW()
        ORDER BY c.fecha_hora_inicio DESC
        LIMIT 10
    ", $paciente_id));

} elseif ($tipo_usuario === 'medico') {
    // =========== MÉDICO (Lógica corregida del código 2) ===========
    $medico_id = $user_id;

    // 1. Citas de hoy (SIN JOIN primero para evitar pérdida de datos)
    $citas_hoy_raw = $wpdb->get_results($wpdb->prepare("
        SELECT * FROM " . H2Y_CITA . "
        WHERE medico_id = %d AND fecha_hora_inicio >= %s AND fecha_hora_inicio <= %s
        ORDER BY fecha_hora_inicio ASC
    ", $medico_id, $hoy_inicio, $hoy_fin));

    // 2. Enriquecer con datos de paciente manualmente
    $citas_hoy = [];
    foreach ($citas_hoy_raw as $cita) {
        $paciente = $wpdb->get_row($wpdb->prepare(
            "SELECT nombre, apellidos, telefono, numero_tsi FROM " . H2Y_PACIENTE . " WHERE paciente_id = %d",
            $cita->paciente_id
        ));

        // Asignamos las variables que espera el HTML del Código 1
        $cita->paciente_nombre = $paciente
            ? trim($paciente->nombre . ' ' . $paciente->apellidos)
            : "Paciente ID: {$cita->paciente_id} (No encontrado)";
        $cita->telefono = $paciente->telefono ?? 'No disponible';
        $cita->numero_tsi = $paciente->numero_tsi ?? 'N/A';

        $citas_hoy[] = $cita;
    }

    // 3. Próximas citas (siguientes 7 días) con la lógica robusta
    $manana_inicio = date('Y-m-d 00:00:00', strtotime('+1 day'));
    $fecha_limite = date('Y-m-d 23:59:59', strtotime('+7 days'));

    $proximas_raw = $wpdb->get_results($wpdb->prepare("
        SELECT * FROM " . H2Y_CITA . "
        WHERE medico_id = %d AND estado = 'pendiente'
        AND fecha_hora_inicio >= %s AND fecha_hora_inicio <= %s
        ORDER BY fecha_hora_inicio ASC
    ", $medico_id, $manana_inicio, $fecha_limite));

    $proximas_citas = [];
    foreach ($proximas_raw as $cita) {
        $paciente = $wpdb->get_row($wpdb->prepare(
            "SELECT nombre, apellidos, telefono FROM " . H2Y_PACIENTE . " WHERE paciente_id = %d",
            $cita->paciente_id
        ));

        $cita->paciente_nombre = $paciente
            ? trim($paciente->nombre . ' ' . $paciente->apellidos)
            : "Paciente ID: {$cita->paciente_id}";
        $cita->telefono = $paciente->telefono ?? 'No disponible';

        $proximas_citas[] = $cita;
    }

    // 4. Estadísticas
    $total_citas_hoy = count($citas_hoy);
    $pendientes_hoy = count(array_filter($citas_hoy, function($c) { return $c->estado === 'pendiente'; }));

    // Total citas generales (del código 1, útil para mensaje vacío)
    $total_citas_medico = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . H2Y_CITA . " WHERE medico_id = %d",
        $medico_id
    ));

    $total_pacientes = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(DISTINCT paciente_id)
        FROM " . H2Y_CITA . "
        WHERE medico_id = %d
    ", $medico_id));

    // 5. Procesar acciones (marcar como asistida)
    if (isset($_GET['accion']) && $_GET['accion'] === 'asistida' && isset($_GET['cita_id'])) {
        $cita_id = intval($_GET['cita_id']);

        $resultado = $wpdb->update(
            H2Y_CITA,
            ['estado' => 'asistida'],
            ['cita_id' => $cita_id],
            ['%s'],
            ['%d']
        );

        header("Location: " . get_stylesheet_directory_uri() . '/dashboard.php?success=asistida');
        exit;
    }

} elseif ($tipo_usuario === 'administrativo') {
    // =========== ADMINISTRATIVO ===========
    $admin_id = $user_id;

    // Citas de hoy (todas)
    $citas_hoy = $wpdb->get_results($wpdb->prepare("
        SELECT c.*,
               CONCAT(p.nombre, ' ', p.apellidos) AS paciente_nombre,
               p.numero_tsi,
               p.telefono,
               CONCAT(m.nombre, ' ', m.apellidos) AS medico_nombre,
               m.especialidad
        FROM " . H2Y_CITA . " c
        INNER JOIN " . H2Y_PACIENTE . " p ON c.paciente_id = p.paciente_id
        INNER JOIN " . H2Y_MEDICO . " m ON c.medico_id = m.medico_id
        WHERE DATE(c.fecha_hora_inicio) = %s
        ORDER BY c.fecha_hora_inicio ASC
    ", $hoy));

    // Próximas citas (siguientes 7 días)
    $proximas_citas = $wpdb->get_results($wpdb->prepare("
        SELECT c.*,
               CONCAT(p.nombre, ' ', p.apellidos) AS paciente_nombre,
               CONCAT(m.nombre, ' ', m.apellidos) AS medico_nombre,
               m.especialidad
        FROM " . H2Y_CITA . " c
        INNER JOIN " . H2Y_PACIENTE . " p ON c.paciente_id = p.paciente_id
        INNER JOIN " . H2Y_MEDICO . " m ON c.medico_id = m.medico_id
        WHERE c.estado = 'pendiente'
          AND DATE(c.fecha_hora_inicio) > CURDATE()
          AND DATE(c.fecha_hora_inicio) <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ORDER BY c.fecha_hora_inicio ASC
    "));

    // Estadísticas globales
    $total_citas_hoy = count($citas_hoy);
    $pendientes_hoy = count(array_filter($citas_hoy, function($c) { return $c->estado === 'pendiente'; }));
    $total_pacientes = $wpdb->get_var("SELECT COUNT(*) FROM " . H2Y_PACIENTE);
    $total_medicos = $wpdb->get_var("SELECT COUNT(*) FROM " . H2Y_MEDICO);

    // Médicos con más citas hoy
    $medicos_hoy = $wpdb->get_results($wpdb->prepare("
        SELECT m.medico_id,
               CONCAT(m.nombre, ' ', m.apellidos) AS medico_nombre,
               m.especialidad,
               COUNT(*) as num_citas
        FROM " . H2Y_CITA . " c
        INNER JOIN " . H2Y_MEDICO . " m ON c.medico_id = m.medico_id
        WHERE DATE(c.fecha_hora_inicio) = %s
        GROUP BY m.medico_id
        ORDER BY num_citas DESC
        LIMIT 5
    ", $hoy));
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Health2You</title>
    <link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/styles.css">
    <?php wp_head(); ?>
    <style>
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-card h3 {
            font-size: 32px;
            margin: 8px 0;
            color: var(--primary);
        }
        .stat-card p {
            color: #666;
            margin: 0;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .admin-badge {
            background: #ff9800;
            color: white;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        .medico-badge {
            background: #2196F3;
            color: white;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        .paciente-badge {
            background: #4CAF50;
            color: white;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        .sintomas-cell {
            max-width: 300px;
            font-size: 13px;
            line-height: 1.4;
        }
        .sintomas-expandible {
            cursor: pointer;
            color: #666;
        }
        .sintomas-full {
            display: none;
            background: #f5f5f5;
            padding: 8px;
            border-radius: 4px;
            margin-top: 4px;
            font-size: 12px;
            border-left: 3px solid #00796b;
        }
        .sintomas-expandible:hover {
            color: #000;
        }
        .btn-asistida {
            background: #4CAF50;
            color: white;
            padding: 6px 12px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 13px;
            display: inline-block;
            transition: all 0.2s;
        }
        .btn-asistida:hover {
            background: #45a049;
            transform: scale(1.05);
        }
        .cita-row-hoy {
            background: #fff9c4 !important;
        }
        .estado-completada {
            color: #2e7d32;
            font-weight: 600;
        }
        .debug-info {
            background: #f0f0f0;
            border: 1px solid #ccc;
            padding: 12px;
            margin: 16px 0;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
        }
    </style>
</head>

<?php
// Color de fondo según tipo
$bg_color = '#e3f2fd'; // Azul claro (default)
if ($tipo_usuario === 'medico') {
    $bg_color = '#e8f5e9'; // Verde claro
} elseif ($tipo_usuario === 'administrativo') {
    $bg_color = '#fff3e0'; // Naranja claro
}
?>

<body style="background: linear-gradient(135deg, <?= $bg_color ?>, #f5f5f5); padding: 16px;">
<div class="page">
    <div class="page-header">
        <div>
            <h2>
                <?php if ($tipo_usuario === 'paciente'): ?>
                    👤 Mi Panel de Citas
                <?php elseif ($tipo_usuario === 'medico'): ?>
                    🩺 Agenda Médica
                <?php else: ?>
                    💼 Panel Administrativo
                <?php endif; ?>
            </h2>
            <p class="small-muted">
                Bienvenido/a, <strong><?= htmlspecialchars($user_nombre) ?></strong>
                <?php if ($tipo_usuario === 'paciente'): ?>
                    <span class="paciente-badge">Paciente</span>
                <?php elseif ($tipo_usuario === 'medico'): ?>
                    <span class="medico-badge">Médico - <?= htmlspecialchars($especialidad) ?></span>
                <?php else: ?>
                    <span class="admin-badge">Administrativo</span>
                <?php endif; ?>
            </p>
        </div>
        <div style="display: flex; gap: 8px;">
            <a href="<?= get_stylesheet_directory_uri(); ?>/index.php" class="btn btn-secondary">← Inicio</a>
            <a href="<?= get_stylesheet_directory_uri(); ?>/logout.php" class="btn btn-secondary">Cerrar sesión</a>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert" style="background: #e8f5e9; color: #2e7d32;"><?= $success_msg ?></div>
    <?php endif; ?>

    <?php if ($tipo_usuario === 'paciente'): ?>
        <div class="filter-row" style="margin-bottom: 24px;">
            <a href="<?php echo get_stylesheet_directory_uri(); ?>/nueva_cita.php" class="btn">Nueva cita</a>
        </div>

        <?php if (!empty($citas_hoy)): ?>
            <h3 style="margin-top: 30px;">📅 Citas de HOY (<?php echo date('d/m/Y'); ?>)</h3>
            <table class="table">
                <thead>
                <tr>
                    <th>Hora</th>
                    <th>Médico</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                    <th>Justificante</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($citas_hoy as $c): ?>
                    <tr class="cita-row-hoy">
                        <td><strong><?php echo substr($c->fecha_hora_inicio, 11, 5); ?></strong></td>
                        <td><?php echo htmlspecialchars($c->medico_nombre); ?></td>
                        <td><span class="badge badge-<?php echo $c->estado; ?>"><?php echo $c->estado; ?></span></td>
                        <td>
                            <?php if ($c->estado === 'pendiente'): ?>
                                <a href="<?php echo get_stylesheet_directory_uri(); ?>/editar_cita.php?id=<?php echo (int)$c->cita_id; ?>">✏️ Modificar</a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($c->estado === 'asistida'): ?>
                                <a href="<?= get_stylesheet_directory_uri(); ?>/justificante.php?cita_id=<?= $c->cita_id ?>"
                                class="btn btn-success" style="padding:4px 8px;font-size:12px;" target="_blank">
                                    📄 Descargar
                                </a>
                                <button onclick="abrirModalEmail(<?= $c->cita_id ?>, '<?= $c->email ?>')"
                                        class="btn btn-secondary" style="padding:4px 8px;font-size:12px;">
                                    📧 Enviar
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if (!empty($citas_pendientes)): ?>
            <h3 style="margin-top: 30px;">🗓️ Próximas citas</h3>
            <table class="table">
                <thead>
                <tr>
                    <th>Fecha/Hora</th>
                    <th>Médico</th>
                    <th>Especialidad</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($citas_pendientes as $c): ?>
                    <tr>
                        <td><?php echo date('d/m/Y H:i', strtotime($c->fecha_hora_inicio)); ?></td>
                        <td><?php echo htmlspecialchars($c->medico_nombre); ?></td>
                        <td><?php echo htmlspecialchars($c->especialidad); ?></td>
                        <td><span class="badge badge-<?php echo $c->estado; ?>"><?php echo $c->estado; ?></span></td>
                        <td>
                            <?php if ($c->estado === 'pendiente'): ?>
                                <a href="<?php echo get_stylesheet_directory_uri(); ?>/editar_cita.php?id=<?php echo (int)$c->cita_id; ?>">✏️ Modificar</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div style="background:#f5f5f5;padding:20px;border-radius:8px;margin:20px 0;text-align:center;">
                <p style="margin:0;">No tienes citas pendientes</p>
                <a href="<?php echo get_stylesheet_directory_uri(); ?>/nueva_cita.php" class="btn" style="margin-top:12px;">Reservar ahora</a>
            </div>
        <?php endif; ?>

        <?php if (!empty($citas_pasadas)): ?>
            <h3 style="margin-top: 40px;">📋 Historial de citas</h3>
            <table class="table">
                <thead>
                <tr>
                    <th>Fecha/Hora</th>
                    <th>Médico</th>
                    <th>Estado</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($citas_pasadas as $c): ?>
                    <tr style="opacity:0.7;">
                        <td><?php echo date('d/m/Y H:i', strtotime($c->fecha_hora_inicio)); ?></td>
                        <td><?php echo htmlspecialchars($c->medico_nombre); ?></td>
                        <td><span class="badge badge-<?php echo $c->estado; ?>"><?php echo $c->estado; ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    <?php elseif ($tipo_usuario === 'medico'): ?>
        <div class="stats-grid">
            <div class="stat-card">
                <p>Citas hoy</p>
                <h3><?= $total_citas_hoy ?></h3>
            </div>
            <div class="stat-card">
                <p>Pendientes hoy</p>
                <h3><?= $pendientes_hoy ?></h3>
            </div>
            <div class="stat-card">
                <p>Total pacientes</p>
                <h3><?= $total_pacientes ?></h3>
            </div>
        </div>

        <?php if (!empty($citas_hoy)): ?>
            <h3>📅 Citas de HOY (<?= date('d/m/Y') ?>)</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 80px;">Hora</th>
                        <th>Paciente</th>
                        <th style="width: 120px;">TSI</th>
                        <th style="width: 120px;">Teléfono</th>
                        <th>Síntomas</th>
                        <th style="width: 100px;">Estado</th>
                        <th style="width: 150px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($citas_hoy as $c): ?>
                        <tr class="<?= $c->estado === 'asistida' ? '' : 'cita-row-hoy' ?>">
                            <td><strong><?= date('H:i', strtotime($c->fecha_hora_inicio)) ?></strong></td>
                            <td><?= htmlspecialchars($c->paciente_nombre) ?></td>
                            <td><small><?= htmlspecialchars($c->numero_tsi) ?></small></td>
                            <td><?= htmlspecialchars($c->telefono ?: '-') ?></td>
                            <td class="sintomas-cell">
                                <?php if (!empty($c->sintomas) && strlen($c->sintomas) > 2): ?>
                                    <?php
                                    $sintomas_corto = mb_strlen($c->sintomas) > 50
                                            ? mb_substr($c->sintomas, 0, 50) . '...'
                                            : $c->sintomas;
                                    ?>
                                    <div class="sintomas-expandible" onclick="toggleSintomas(this)">
                                        <span class="sintomas-preview">
                                            <?= htmlspecialchars($sintomas_corto) ?>
                                            <?php if (mb_strlen($c->sintomas) > 50): ?>
                                                <small style="color:#00796b;">[ver más]</small>
                                            <?php endif; ?>
                                        </span>
                                        <?php if (mb_strlen($c->sintomas) > 50): ?>
                                        <div class="sintomas-full">
                                            <strong>Síntomas completos:</strong><br>
                                            <?= nl2br(htmlspecialchars($c->sintomas)) ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #ccc; font-size: 13px; font-style: italic;">Sin especificar</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?= $c->estado ?>"><?= ucfirst($c->estado) ?></span>
                            </td>
                            <td>
                                <?php if ($c->estado === 'pendiente'): ?>
                                    <a href="?accion=asistida&cita_id=<?= $c->cita_id ?>"
                                       onclick="return confirm('¿Marcar como asistida?');"
                                       class="btn-asistida">
                                       ✓ Marcar asistida
                                    </a>
                                <?php elseif ($c->estado === 'asistida'): ?>
                                    <span class="estado-completada">✓ Completada</span>
                                <?php else: ?>
                                    <span style="color: #999;">Cancelada</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div style="background: #fff; padding: 32px; text-align: center; border-radius: 8px; margin-bottom: 24px;">
                <div style="font-size: 48px; margin-bottom: 16px;">📅</div>
                <h3 style="color: #666;">No tienes citas programadas para hoy</h3>
                <p style="color: #999;">
                    Disfruta de tu día libre 😊
                    <?php if (isset($total_citas_medico) && $total_citas_medico > 0): ?>
                        <br><small>(Tienes <?= $total_citas_medico ?> citas en total en el sistema)</small>
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if (!empty($proximas_citas)): ?>
            <h3 style="margin-top:40px;">🗓️ Próximas citas (siguientes 7 días)</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Hora</th>
                        <th>Paciente</th>
                        <th>Teléfono</th>
                        <th>Síntomas</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($proximas_citas as $c): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($c->fecha_hora_inicio)) ?></td>
                            <td><?= date('H:i', strtotime($c->fecha_hora_inicio)) ?></td>
                            <td><?= htmlspecialchars($c->paciente_nombre) ?></td>
                            <td><?= htmlspecialchars($c->telefono ?: '-') ?></td>
                            <td class="sintomas-cell">
                                <?php if (!empty($c->sintomas) && strlen($c->sintomas) > 2): ?>
                                    <span style="color: #666; font-size: 13px;" title="<?= htmlspecialchars($c->sintomas) ?>">
                                        <?= htmlspecialchars(mb_strlen($c->sintomas) > 40 ? mb_substr($c->sintomas, 0, 40) . '...' : $c->sintomas) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #ccc; font-size: 13px;">Sin especificar</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?= $c->estado ?>"><?= $c->estado ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    <?php else: ?>
        <div class="stats-grid">
            <div class="stat-card">
                <p>Citas hoy</p>
                <h3><?= $total_citas_hoy ?></h3>
            </div>
            <div class="stat-card">
                <p>Pendientes hoy</p>
                <h3><?= $pendientes_hoy ?></h3>
            </div>
            <div class="stat-card">
                <p>Total pacientes</p>
                <h3><?= $total_pacientes ?></h3>
            </div>
            <div class="stat-card">
                <p>Total médicos</p>
                <h3><?= $total_medicos ?></h3>
            </div>
        </div>

        <?php if (!empty($medicos_hoy)): ?>
            <h3>👨‍⚕️ Médicos con citas hoy</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Médico</th>
                        <th>Especialidad</th>
                        <th>Nº Citas hoy</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($medicos_hoy as $m): ?>
                        <tr>
                            <td><?= htmlspecialchars($m->medico_nombre) ?></td>
                            <td><?= htmlspecialchars($m->especialidad) ?></td>
                            <td><strong><?= $m->num_citas ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if (!empty($citas_hoy)): ?>
            <h3>📅 Todas las citas de HOY (<?= date('d/m/Y') ?>)</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Hora</th>
                        <th>Paciente</th>
                        <th>TSI</th>
                        <th>Médico</th>
                        <th>Especialidad</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($citas_hoy as $c): ?>
                        <tr>
                            <td><strong><?= date('H:i', strtotime($c->fecha_hora_inicio)) ?></strong></td>
                            <td><?= htmlspecialchars($c->paciente_nombre) ?></td>
                            <td><?= htmlspecialchars($c->numero_tsi) ?></td>
                            <td><?= htmlspecialchars($c->medico_nombre) ?></td>
                            <td><?= htmlspecialchars($c->especialidad) ?></td>
                            <td><span class="badge badge-<?= $c->estado ?>"><?= $c->estado ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
    function toggleSintomas(element) {
        var full = element.querySelector('.sintomas-full');
        var preview = element.querySelector('.sintomas-preview');

        if (full) {
            if (full.style.display === 'block') {
                full.style.display = 'none';
                preview.style.display = 'inline';
            } else {
                full.style.display = 'block';
                preview.style.display = 'none';
            }
        }
    }
    function abrirModalEmail(citaId, email) {
    document.getElementById('citaIdActual').value = citaId;
    document.getElementById('emailDestino').value = email;
    document.getElementById('modalAlert').innerHTML = '';
    document.getElementById('emailModal').style.display = 'block';
}

function cerrarModalEmail() {
    document.getElementById('emailModal').style.display = 'none';
}

async function enviarJustificante() {
    const citaId = document.getElementById('citaIdActual').value;
    const email = document.getElementById('emailDestino').value;
    const alertDiv = document.getElementById('modalAlert');
    alertDiv.innerHTML = 'Enviando...';

    try {
        const resp = await fetch('<?= get_stylesheet_directory_uri(); ?>/enviar_justificante.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({cita_id: citaId, email})
        });
        const data = await resp.json();
        if (data.success) {
            alertDiv.innerHTML = '<span style="color:green;">✅ Justificante enviado correctamente</span>';
        } else {
            alertDiv.innerHTML = '<span style="color:red;">❌ Error: '+data.message+'</span>';
        }
    } catch(e) {
        alertDiv.innerHTML = '<span style="color:red;">❌ Error de conexión</span>';
    }
}

</script>
<!-- Modal envío justificante -->
    <div id="emailModal" class="modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:999;">
        <div class="modal-content" style="background:#fff;margin:10% auto;padding:20px;border-radius:8px;max-width:400px;">
            <h4>Enviar justificante</h4>
            <p>Email de destino:</p>
            <input type="email" id="emailDestino" style="width:100%;padding:10px;margin-bottom:15px;border:1px solid #ccc;border-radius:5px;">
            <input type="hidden" id="citaIdActual">
            <div id="modalAlert"></div>
            <div style="text-align:right;">
                <button onclick="cerrarModalEmail()" class="btn btn-secondary" style="margin-right:8px;">Cancelar</button>
                <button onclick="enviarJustificante()" class="btn btn-success">Enviar</button>
            </div>
        </div>
    </div>

</body>
</html>
