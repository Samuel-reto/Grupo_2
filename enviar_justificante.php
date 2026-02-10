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
// 1. Capturamos si el usuario marcó el checkbox
$actualizar_perfil = isset($input['actualizar_perfil']) && $input['actualizar_perfil'] === true;

error_log("✓ Cita ID: $cita_id");
error_log("✓ Email destino: $email_destino");
error_log("✓ Actualizar perfil: " . ($actualizar_perfil ? 'SI' : 'NO'));

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

// 2. Si el usuario aceptó, actualizamos su correo en la tabla h2y_paciente
if ($actualizar_perfil) {
    $paciente_id_session = $_SESSION['h2y_paciente_id'] ?? $_SESSION['h2y_pacienteid'] ?? 0;
    
    if ($paciente_id_session > 0) {
        $wpdb->update(
            $wpdb->prefix . 'h2y_paciente',
            ['email' => $email_destino], // Nuevo email
            ['paciente_id' => $paciente_id_session], // Donde el ID sea el del usuario actual
            ['%s'], 
            ['%d']
        );
        error_log("✅ Email actualizado en el perfil del paciente ID: $paciente_id_session");
    }
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

