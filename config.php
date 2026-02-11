<?php
// config.php - Health2You MySQL
if (!defined('ABSPATH')) exit;

global $wpdb;

// Constantes tablas
define('H2Y_PACIENTE', 'paciente');
define('H2Y_MEDICO', 'medico');
define('H2Y_ADMINISTRATIVO', 'administrativo');
define('H2Y_CITA', 'cita');
define('H2Y_JUSTIFICANTE', 'justificante');

// Configuración de correo Gmail para 2FA
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'health2you.asir2@gmail.com');
define('SMTP_PASSWORD', 'pvwttrecwdgegzkr');
define('SMTP_FROM_EMAIL', 'health2you.asir2@gmail.com');
define('SMTP_FROM_NAME', 'Health2You');

// Configuración de Videollamadas
if (!defined('H2Y_VIDEOLLAMADA')) {
    define('H2Y_VIDEOLLAMADA', 'videollamada');
}

if (!defined('H2Y_VIDEOLLAMADA_LOG')) {
    define('H2Y_VIDEOLLAMADA_LOG', 'videollamada_log');
}

if (!defined('VIDEOLLAMADA_EXPIRACION_MINUTOS')) {
    define('VIDEOLLAMADA_EXPIRACION_MINUTOS', 30); // Token expira en 30 minutos
}

if (!defined('VIDEOLLAMADA_MAX_PARTICIPANTES')) {
    define('VIDEOLLAMADA_MAX_PARTICIPANTES', 2);
}

if (!defined('JITSI_DOMAIN')) {
    define('JITSI_DOMAIN', 'meet.jit.si');
}

?>
