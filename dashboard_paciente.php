<?php
if (!defined('ABSPATH')) require_once('../../../wp-load.php');
if (!session_id()) session_start();

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

// Verificar sesión
if (!isset($_SESSION['h2y_tipo']) || $_SESSION['h2y_tipo'] !== 'paciente') {
    $login_url = get_stylesheet_directory_uri() . '/index.php';
    header("Location: $login_url");
    exit;
}

$paciente_id = $_SESSION['h2y_paciente_id'];

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
                Bienvenida, <strong><?= htmlspecialchars($_SESSION['h2y_paciente_nombre']) ?></strong>
            </p>
        </div>
        <div>
            <a href="<?= get_stylesheet_directory_uri(); ?>/logout.php" class="btn btn-secondary">Cerrar sesión</a>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert" style="background: #e8f5e9; color: #2e7d32;"><?= $success_msg ?></div>
    <?php endif; ?>

    <div class="filter-row" style="margin-bottom: 24px;">
        <a href="<?= get_stylesheet_directory_uri(); ?>/nueva_cita.php" class="btn">📅 Nueva cita</a>
    </div>

    <?php if (!empty($citas_hoy)): ?>
        <h3>📅 Citas de HOY</h3>
        <table class="table">
            <thead>
                <tr><th>Hora</th><th>Médico</th><th>Estado</th><th>Acciones</th></tr>
            </thead>
            <tbody>
                <?php foreach ($citas_hoy as $c): ?>
                    <tr>
                        <td><?= substr($c->fecha_hora_inicio, 11, 5) ?></td>
                        <td><?= htmlspecialchars($c->medico_nombre) ?></td>
                        <td><span class="badge badge-<?= $c->estado ?>"><?= $c->estado ?></span></td>
                        <td>
                            <?php if ($c->estado === 'pendiente'): ?>
                                <a href="<?= get_stylesheet_directory_uri(); ?>/editar_cita.php?id=<?= $c->cita_id ?>">Modificar</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (!empty($citas_pendientes)): ?>
        <h3>⏳ Próximas citas</h3>
        <table class="table">
            <thead>
                <tr><th>Fecha/Hora</th><th>Médico</th><th>Especialidad</th><th>Estado</th><th>Acciones</th></tr>
            </thead>
            <tbody>
                <?php foreach ($citas_pendientes as $c): ?>
                    <tr>
                        <td><?= $c->fecha_hora_inicio ?></td>
                        <td><?= htmlspecialchars($c->medico_nombre) ?></td>
                        <td><?= htmlspecialchars($c->especialidad) ?></td>
                        <td><span class="badge badge-<?= $c->estado ?>"><?= $c->estado ?></span></td>
                        <td>
                            <a href="<?= get_stylesheet_directory_uri(); ?>/editar_cita.php?id=<?= $c->cita_id ?>">Modificar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
