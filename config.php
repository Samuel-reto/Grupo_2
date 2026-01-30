<?php
// config.php - Health2You MySQL
if (!defined('ABSPATH')) exit;

global $wpdb;

// Constantes tablas
define('H2Y_PACIENTE', 'paciente');
define('H2Y_MEDICO', 'medico');
define('H2Y_CITA', 'cita');
define('H2Y_JUSTIFICANTE', 'justificante');
define('H2Y_VISTA', 'vista_citas_completas');


// Configuración de correo Gmail para 2FA
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'health2you.asir2@gmail.com');
define('SMTP_PASSWORD', 'pvwttrecwdgegzkr');
define('SMTP_FROM_EMAIL', 'health2you.asir2@gmail.com');
define('SMTP_FROM_NAME', 'Health2You');
?>