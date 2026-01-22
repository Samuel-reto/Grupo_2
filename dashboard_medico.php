<?php
if (!defined('ABSPATH')) exit;
if (!session_id()) session_start();

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

if (!isset($_SESSION['h2y_tipo']) || $_SESSION['h2y_tipo'] !== 'medico') {
    wp_redirect(home_url('/login-citas/'));
    exit;
}

$medico_id = $_SESSION['h2y_medico_id'];
$hoy = date('Y-m-d');

$citas_hoy = $wpdb->get_results($wpdb->prepare("
    SELECT c.*, CONCAT(p.nombre, ' ', p.apellidos) AS paciente_nombre, p.numero_tsi, p.telefono
    FROM " . H2Y_CITA . " c
    JOIN " . H2Y_PACIENTE . " p ON c.paciente_id = p.paciente_id
    WHERE c.medico_id = %d AND DATE(c.fecha_hora_inicio) = %s
    ORDER BY c.fecha_hora_inicio
", $medico_id, $hoy));

$citas_futuras = $wpdb->get_results($wpdb->prepare("
    SELECT c.*, CONCAT(p.nombre, ' ', p.apellidos) AS paciente_nombre, p.numero_tsi
    FROM " . H2Y_CITA . " c
    JOIN " . H2Y_PACIENTE . " p ON c.paciente_id = p.paciente_id
    WHERE c.medico_id = %d AND DATE(c.fecha_hora_inicio) > %s
    ORDER BY c.fecha_hora_inicio LIMIT 20
", $medico_id, $hoy));
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Agenda médico</title>
    <link rel="stylesheet" href="<?= get_stylesheet_directory_uri(); ?>/style.css">
    <?php wp_head(); ?>
</head>
<body style="background: linear-gradient(135deg, #e8f5e9, #c8e6c9); padding: 16px;">
<div class="page">
    <div class="page-header">
        <div>
            <h2>👨‍⚕️ Agenda clínica</h2>
            <p class="small-muted">Dr/a. <?= htmlspecialchars($_SESSION['h2y_medico_nombre']) ?></p>
        </div>
        <div>
            <a href="<?= get_stylesheet_directory_uri(); ?>/logout.php" class="btn btn-secondary">Cerrar sesión</a>
        </div>
    </div>

    <h3>Citas de hoy (<?= $hoy ?>)</h3>
    <table class="table">
        <thead>
            <tr><th>Hora</th><th>Paciente</th><th>TSI</th><th>Teléfono</th><th>Estado</th></tr>
        </thead>
        <tbody>
            <?php foreach ($citas_hoy as $c): ?>
                <tr>
                    <td><?= substr($c->fecha_hora_inicio, 11, 5) ?></td>
                    <td><?= htmlspecialchars($c->paciente_nombre) ?></td>
                    <td><?= htmlspecialchars($c->numero_tsi) ?></td>
                    <td><?= htmlspecialchars($c->telefono) ?></td>
                    <td><span class="badge badge-<?= $c->estado ?>"><?= $c->estado ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h3>Próximas citas</h3>
    <table class="table">
        <thead>
            <tr><th>Fecha</th><th>Paciente</th><th>TSI</th><th>Estado</th></tr>
        </thead>
        <tbody>
            <?php foreach ($citas_futuras as $c): ?>
                <tr>
                    <td><?= $c->fecha_hora_inicio ?></td>
                    <td><?= htmlspecialchars($c->paciente_nombre) ?></td>
                    <td><?= htmlspecialchars($c->numero_tsi) ?></td>
                    <td><span class="badge badge-<?= $c->estado ?>"><?= $c->estado ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php wp_footer(); ?>
</body>
</html>

