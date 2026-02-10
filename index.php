<?php
if (!defined('ABSPATH')) require_once('../../../wp-load.php');
if (!session_id()) session_start();

// Si ya hay sesión activa, redirigir al dashboard
if (isset($_SESSION['h2y_tipo'])) {
    wp_safe_redirect(get_stylesheet_directory_uri() . '/dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health2You - Portal de Salud Online</title>
    <link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/styles.css">
    <?php wp_head(); ?>

    <!-- PWA Health2You -->
<link rel="manifest" href="<?= get_stylesheet_directory_uri(); ?>/manifest.json">
<meta name="theme-color" content="#0f9d58">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Health2You">
<link rel="apple-touch-icon" href="<?= get_stylesheet_directory_uri(); ?>/icon-192.png">

<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    navigator.serviceWorker.register('<?= get_stylesheet_directory_uri(); ?>/sw.js')
    .then(function(registration) {
      console.log('PWA ServiceWorker registrado correctamente');
    }).catch(function(error) {
      console.log('Error al registrar PWA ServiceWorker:', error);
    });
  });
}
</script>
    
</head>
<body>

<div style="padding: 16px; background: #f5f5f5; text-align: center;">
    <?php if (isset($_SESSION['h2y_tipo'])): ?>
        <span style="color: #666;">
            Sesión activa: <?= htmlspecialchars($_SESSION['h2y_user_nombre'] ?? 'Usuario') ?>
        </span>
        <a href="<?= get_stylesheet_directory_uri(); ?>/logout.php" style="color: var(--primary); margin-left: 20px; text-decoration: none; font-weight: 600;">
            Cerrar sesión
        </a>
    <?php endif; ?>
</div>

<div class="container">
    <div class="left" style="max-width: 600px; margin: 0 auto; background: linear-gradient(135deg, #e8f5e9, #c8e6c9); padding: 40px; border-radius: 12px;">
        <div class="logo">
            <span>🏥 Health2You</span>
        </div>
        <h1>Portal de Salud Online</h1>
        <p class="tagline">
            Accede a tu cuenta para gestionar tus citas médicas de forma rápida y segura
        </p>

        <div style="display: flex; flex-direction: column; gap: 16px; margin-top: 32px;">
            <a href="<?= get_stylesheet_directory_uri(); ?>/login.php" class="btn" style="padding: 18px; font-size: 18px; text-align: center;">
                🔐 Iniciar Sesión
            </a>
            <a href="<?= get_stylesheet_directory_uri(); ?>/registro.php" class="btn btn-secondary" style="padding: 18px; font-size: 18px; text-align: center;">
                📝 Crear Cuenta Nueva
            </a>
        </div>

        <!-- Características -->
        <div style="margin-top: 48px; padding-top: 32px; border-top: 1px solid #e0e0e0;">
            <h3 style="text-align: center; color: var(--primary); margin-bottom: 24px;">
                ¿Qué puedes hacer?
            </h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                <div style="text-align: center; padding: 20px;">
                    <div style="font-size: 40px; margin-bottom: 12px;">📅</div>
                    <h4 style="margin: 0 0 8px 0; color: #2c3e50;">Cita Previa</h4>
                    <p style="margin: 0; color: #666; font-size: 14px;">Reserva online 24/7</p>
                </div>
                <div style="text-align: center; padding: 20px;">
                    <div style="font-size: 40px; margin-bottom: 12px;">👨‍⚕️</div>
                    <h4 style="margin: 0 0 8px 0; color: #2c3e50;">Profesionales</h4>
                    <p style="margin: 0; color: #666; font-size: 14px;">Equipo cualificado</p>
                </div>
                <div style="text-align: center; padding: 20px;">
                    <div style="font-size: 40px; margin-bottom: 12px;">🔒</div>
                    <h4 style="margin: 0 0 8px 0; color: #2c3e50;">Seguridad</h4>
                    <p style="margin: 0; color: #666; font-size: 14px;">Datos protegidos 2FA</p>
                </div>
            </div>
        </div>

        <!-- Información según tipo de usuario -->
        <div style="margin-top: 40px; background: #f8f9fa; padding: 24px; border-radius: 8px;">
            <h3 style="margin: 0 0 16px 0; color: #2c3e50;">Acceso para:</h3>
            <ul class="helper-list">
                <li><strong>👤 Pacientes:</strong> Gestiona tus citas médicas</li>
                <li><strong>🩺 Médicos:</strong> Consulta tu agenda y pacientes</li>
                <li><strong>💼 Administrativos:</strong> Panel de gestión global</li>
            </ul>
        </div>
    </div>
</div>

<footer style="background: #2c3e50; color: white; padding: 20px 0; margin-top: 60px; text-align: center;">
    <p style="margin: 0; font-size: 14px;">
        &copy; <?= date('Y') ?> Health2You - Sistema de Salud Online
    </p>
</footer>

<?php wp_footer(); ?>
</body>
</html>

