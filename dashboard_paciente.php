<?php
if (!defined('ABSPATH')) {
    require_once('../../../wp-load.php');
}

if (!session_id()) {
    session_start();
}

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

// ============================================
// VERIFICACIÓN ESTRICTA 2FA
// ============================================

// 1. Si tiene sesión temporal = NO completó 2FA
if (isset($_SESSION['paciente_temp_id'])) {
    header("Location: " . get_stylesheet_directory_uri() . "/verificar_2fa.php");
    exit;
}

// 2. Si NO está logueado como paciente
if (!isset($_SESSION['h2y_tipo']) || $_SESSION['h2y_tipo'] !== 'paciente') {
    header("Location: " . get_stylesheet_directory_uri() . "/login.php");
    exit;
}

// 3. Si NO tiene paciente_id = sesión inválida
if (!isset($_SESSION['h2y_paciente_id'])) {
    session_destroy();
    header("Location: " . get_stylesheet_directory_uri() . "/login.php");
    exit;
}

// ✅ ACCESO VERIFICADO - Continuar con dashboard
$paciente_id = $_SESSION['h2y_paciente_id'];
$paciente_nombre = $_SESSION['h2y_paciente_nombre'];

// ✅ Si llegó aquí, pasó el 2FA correctamente
$paciente_id = $_SESSION['h2y_paciente_id'];
$paciente_nombre = $_SESSION['h2y_paciente_nombre'];

// ============================================
// LÓGICA DEL DASHBOARD
// ============================================

$success_msg = "";
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'nueva':
            $success_msg = "✅ Cita reservada correctamente.";
            break;
        case 'modificada':
            $success_msg = "✅ Cita modificada correctamente.";
            break;
        case 'cancelada':
            $success_msg = "✅ Cita cancelada correctamente.";
            break;
    }
}

// Citas pendientes
$citas_pendientes = $wpdb->get_results($wpdb->prepare("
    SELECT c.*, CONCAT(m.nombre, ' ', m.apellidos) AS medico_nombre, m.especialidad
    FROM " . H2Y_CITA . " c
    JOIN " . H2Y_MEDICO . " m ON c.medico_id = m.medico_id
    WHERE c.paciente_id = %d
      AND c.estado = 'pendiente'
      AND c.fecha_hora_inicio >= CURRENT_DATE
      AND DAYOFWEEK(c.fecha_hora_inicio) NOT IN (1,7)
    ORDER BY c.fecha_hora_inicio ASC
", $paciente_id));

// Citas de hoy
$hoy = date('Y-m-d');
$citas_hoy = $wpdb->get_results($wpdb->prepare("
    SELECT c.*, CONCAT(m.nombre, ' ', m.apellidos) AS medico_nombre
    FROM " . H2Y_CITA . " c
    JOIN " . H2Y_MEDICO . " m ON c.medico_id = m.medico_id
    WHERE c.paciente_id = %d AND DATE(c.fecha_hora_inicio) = %s
    ORDER BY c.fecha_hora_inicio ASC
", $paciente_id, $hoy));

// Historial
$citas_pasadas = $wpdb->get_results($wpdb->prepare("
    SELECT c.*, CONCAT(m.nombre, ' ', m.apellidos) AS medico_nombre
    FROM " . H2Y_CITA . " c
    JOIN " . H2Y_MEDICO . " m ON c.medico_id = m.medico_id
    WHERE c.paciente_id = %d AND c.fecha_hora_inicio < NOW()
    ORDER BY c.fecha_hora_inicio DESC LIMIT 10
", $paciente_id));
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis citas - Health2You</title>
    <link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/style.css">
    <?php wp_head(); ?>
</head>
<body style="background: linear-gradient(135deg, #e8f5e9, #c8e6c9); padding: 16px;">

<div class="page">
    <div class="page-header">
        <div>
            <h2>👩‍⚕️ Mis citas médicas</h2>
            <p class="small-muted">
                Bienvenida, <strong><?= htmlspecialchars($paciente_nombre) ?></strong>
                <span style="background: #4caf50; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; margin-left: 8px;">
                    🔒 Sesión verificada con 2FA
                </span>
            </p>
        </div>
        <div>
            <a href="<?= get_stylesheet_directory_uri(); ?>/logout.php" class="btn btn-secondary">
                🚪 Cerrar sesión
            </a>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert" style="background: #e8f5e9; color: #2e7d32; padding: 16px; border-radius: 8px; margin-bottom: 20px;">
            <?= $success_msg ?>
        </div>
    <?php endif; ?>

    <div class="filter-row" style="margin-bottom: 24px;">
        <a href="<?= get_stylesheet_directory_uri(); ?>/nueva_cita.php" class="btn">
            📅 Nueva cita
        </a>
    </div>

    <?php if (!empty($citas_hoy)): ?>
        <h3 style="margin-top: 30px;">📅 Citas de HOY (<?= date('d/m/Y') ?>)</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>Hora</th>
                    <th>Médico</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($citas_hoy as $c): ?>
                    <tr style="background: #fff9c4;">
                        <td><strong><?= substr($c->fecha_hora_inicio, 11, 5) ?></strong></td>
                        <td><?= htmlspecialchars($c->medico_nombre) ?></td>
                        <td><span class="badge badge-<?= $c->estado ?>"><?= $c->estado ?></span></td>
                        <td>
                            <?php if ($c->estado === 'pendiente'): ?>
                                <a href="<?= get_stylesheet_directory_uri(); ?>/editar_cita.php?id=<?= $c->cita_id ?>">
                                    ✏️ Modificar
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div style="background: #e3f2fd; padding: 16px; border-radius: 8px; margin: 20px 0; text-align: center;">
            ℹ️ No tienes citas programadas para hoy
        </div>
    <?php endif; ?>

    <?php if (!empty($citas_pendientes)): ?>
        <h3 style="margin-top: 30px;">⏳ Próximas citas</h3>
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
                        <td><?= date('d/m/Y H:i', strtotime($c->fecha_hora_inicio)) ?></td>
                        <td><?= htmlspecialchars($c->medico_nombre) ?></td>
                        <td><?= htmlspecialchars($c->especialidad) ?></td>
                        <td><span class="badge badge-<?= $c->estado ?>"><?= $c->estado ?></span></td>
                        <td>
                            <a href="<?= get_stylesheet_directory_uri(); ?>/editar_cita.php?id=<?= $c->cita_id ?>">
                                ✏️ Modificar
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div style="background: #f5f5f5; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center;">
            <p style="margin: 0;">📭 No tienes citas pendientes</p>
            <a href="<?= get_stylesheet_directory_uri(); ?>/nueva_cita.php" class="btn" style="margin-top: 12px;">
                Reservar ahora
            </a>
        </div>
    <?php endif; ?>

    <?php if (!empty($citas_pasadas)): ?>
        <h3 style="margin-top: 40px;">📜 Historial de citas</h3>
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
                    <tr style="opacity: 0.7;">
                        <td><?= date('d/m/Y H:i', strtotime($c->fecha_hora_inicio)) ?></td>
                        <td><?= htmlspecialchars($c->medico_nombre) ?></td>
                        <td><span class="badge badge-<?= $c->estado ?>"><?= $c->estado ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

</div>

<?php wp_footer(); ?>
</body>
</html>

