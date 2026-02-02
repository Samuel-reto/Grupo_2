<?php
if (!defined('ABSPATH')) require_once('../../../wp-load.php');
if (!session_id()) session_start();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health2You - Portal de Salud Online</title>
    <link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/styles.css">
    <?php wp_head(); ?>
</head>
<body class="portal-body">

<!-- Header principal -->
<header class="portal-header">
    <div class="header-container">
        <div class="logo-section">
            <h1>🏥 Health2You</h1>
            <p class="subtitle">Tu salud, siempre contigo</p>
        </div>
        <div class="header-actions">
            <?php if (isset($_SESSION['h2y_tipo'])): ?>
                <span class="user-info">
                    👤 <?= htmlspecialchars($_SESSION['h2y_paciente_nombre'] ?? $_SESSION['h2y_medico_nombre'] ?? 'Usuario') ?>
                </span>
                <a href="<?= site_url('/wp-content/themes/health2you/logout.php') ?>" class="btn-header btn-logout">
                    Cerrar sesión
                </a>
            <?php else: ?>
                <a href="<?= site_url('/wp-content/themes/health2you/login.php') ?>" class="btn-header btn-primary">
                    Acceder
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>

<!-- Tarjetas de servicios principales -->
<main class="portal-main">
    <div class="container">
        <h2 class="section-title">Servicios disponibles</h2>

        <div class="services-grid-compact">

            <!-- Cita Previa -->
            <div class="service-card-compact">
                <div class="card-icon-compact">📅</div>
                <h3>Cita Previa</h3>
                <p>Reserva tu cita médica</p>
                <?php if (isset($_SESSION['h2y_tipo']) && $_SESSION['h2y_tipo'] === 'paciente'): ?>
                    <a href="<?= site_url('/wp-content/themes/health2you/nueva_cita.php') ?>" class="btn-card-compact">
                        Nueva cita →
                    </a>
                <?php else: ?>
                    <a href="<?= site_url('/wp-content/themes/health2you/login.php') ?>" class="btn-card-compact">
                        Acceder →
                    </a>
                <?php endif; ?>
            </div>

            <!-- Área Paciente -->
            <div class="service-card-compact">
                <div class="card-icon-compact">👤</div>
                <h3>Área Paciente</h3>
                <p>Consulta tus citas</p>
                <?php if (isset($_SESSION['h2y_tipo']) && $_SESSION['h2y_tipo'] === 'paciente'): ?>
                    <a href="<?= site_url('/wp-content/themes/health2you/dashboard_paciente.php') ?>" class="btn-card-compact">
                        Mis citas →
                    </a>
                <?php else: ?>
                    <a href="<?= site_url('/wp-content/themes/health2you/login.php') ?>" class="btn-card-compact">
                        Acceder →
                    </a>
                <?php endif; ?>
            </div>

            <!-- Área Profesional -->
            <div class="service-card-compact card-professional">
                <div class="card-icon-compact">🩺</div>
                <h3>Área Profesional</h3>
                <p>Acceso médicos</p>
                <?php if (isset($_SESSION['h2y_tipo']) && $_SESSION['h2y_tipo'] === 'medico'): ?>
                    <a href="<?= site_url('/wp-content/themes/health2you/dashboard_medico.php') ?>" class="btn-card-compact">
                        Mi agenda →
                    </a>
                <?php else: ?>
                    <a href="<?= site_url('/wp-content/themes/health2you/login.php') ?>" class="btn-card-compact">
                        Acceder →
                    </a>
                <?php endif; ?>
            </div>

            <!-- Registro Paciente -->
            <div class="service-card-compact">
                <div class="card-icon-compact">📝</div>
                <h3>Nuevo Usuario</h3>
                <p>Regístrate aquí</p>
                <a href="<?= site_url('/wp-content/themes/health2you/registro.php') ?>" class="btn-card-compact">
                    Registrarse →
                </a>
            </div>

            <!-- Registro Médico -->
            <div class="service-card-compact card-professional">
                <div class="card-icon-compact">🩺</div>
                <h3>Registro Médico</h3>
                <p>Profesionales sanitarios</p>
                <a href="<?= site_url('/wp-content/themes/health2you/registro_medico.php') ?>" class="btn-card-compact">
                    Registrarse →
                </a>
            </div>

            <!-- Login directo -->
            <div class="service-card-compact card-info">
                <div class="card-icon-compact">🔐</div>
                <h3>Iniciar Sesión</h3>
                <p>Acceso al sistema</p>
                <a href="<?= site_url('/wp-content/themes/health2you/login.php') ?>" class="btn-card-compact">
                    Login →
                </a>
            </div>

        </div>
    </div>
</main>

<!-- Footer simple -->
<footer style="background: #2c3e50; color: white; padding: 20px 0; margin-top: auto; text-align: center;">
    <p style="margin: 0; font-size: 14px;">
        &copy; <?= date('Y') ?> Health2You - Sistema de Salud Online
    </p>
</footer>

<?php wp_footer(); ?>
</body>
</html>

