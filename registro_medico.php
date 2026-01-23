<?php
if (!defined('ABSPATH')) require_once('../../../wp-load.php');
if (!session_id()) session_start();

// Activar errores temporalmente
ini_set('display_errors', 1);
error_reporting(E_ALL);

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

// Verificar sesión médico
if (!isset($_SESSION['h2y_tipo']) || $_SESSION['h2y_tipo'] !== 'medico') {
    $login_url = get_stylesheet_directory_uri() . '/index.php';
    header("Location: $login_url");
    exit;
}

$medico_id = $_SESSION['h2y_medico_id'];
$medico_nombre = $_SESSION['h2y_medico_nombre'];

// Obtener datos del médico
$medico = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM " . H2Y_MEDICO . " WHERE medico_id = %d", $medico_id
));

// Citas de hoy
$hoy = date('Y-m-d');
$citas_hoy = $wpdb->get_results($wpdb->prepare("
    SELECT c.*, 
           CONCAT(p.nombre, ' ', p.apellidos) AS paciente_nombre,
           p.telefono,
           p.numero_tsi
    FROM " . H2Y_CITA . " c
    JOIN " . H2Y_PACIENTE . " p ON c.paciente_id = p.paciente_id
    WHERE c.medico_id = %d 
      AND DATE(c.fecha_hora_inicio) = %s
    ORDER BY c.fecha_hora_inicio ASC
", $medico_id, $hoy));

// Próximas citas (siguientes 7 días)
$proximas_citas = $wpdb->get_results($wpdb->prepare("
    SELECT c.*, 
           CONCAT(p.nombre, ' ', p.apellidos) AS paciente_nombre,
           p.telefono
    FROM " . H2Y_CITA . " c
    JOIN " . H2Y_PACIENTE . " p ON c.paciente_id = p.paciente_id
    WHERE c.medico_id = %d 
      AND c.estado = 'pendiente'
      AND DATE(c.fecha_hora_inicio) > CURDATE()
      AND DATE(c.fecha_hora_inicio) <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY c.fecha_hora_inicio ASC
", $medico_id));

// Estadísticas
$total_citas_hoy = count($citas_hoy);
$pendientes_hoy = count(array_filter($citas_hoy, function($c) { return $c->estado === 'pendiente'; }));
$total_pacientes = $wpdb->get_var($wpdb->prepare("
    SELECT COUNT(DISTINCT paciente_id) 
    FROM " . H2Y_CITA . " 
    WHERE medico_id = %d
", $medico_id));

// Procesar acciones (marcar como asistida)
if (isset($_GET['accion']) && $_GET['accion'] === 'asistida' && isset($_GET['cita_id'])) {
    $cita_id = intval($_GET['cita_id']);
    $wpdb->update(
        H2Y_CITA, 
        ['estado' => 'asistida'], 
        ['cita_id' => $cita_id, 'medico_id' => $medico_id]
    );
    
    $redirect = get_stylesheet_directory_uri() . '/dashboard_medico.php?success=asistida';
    header("Location: $redirect");
    exit;
}

$success_msg = "";
if (isset($_GET['success']) && $_GET['success'] === 'asistida') {
    $success_msg = "✅ Cita marcada como asistida correctamente.";
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agenda Médico - Health2You</title>
    <link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/style.css">
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
    </style>
</head>
<body style="background: linear-gradient(135deg, #e3f2fd, #bbdefb); padding: 16px;">
<div class="page">
    <div class="page-header">
        <div>
            <h2>🩺 Agenda Médica</h2>
            <p class="small-muted">
                Dr/a. <strong><?= htmlspecialchars($medico_nombre) ?></strong><br>
                Especialidad: <strong><?= htmlspecialchars($medico->especialidad) ?></strong>
            </p>
        </div>
        <div>
            <a href="<?= get_stylesheet_directory_uri(); ?>/logout.php" class="btn btn-secondary">Cerrar sesión</a>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert" style="background: #e8f5e9; color: #2e7d32;"><?= $success_msg ?></div>
    <?php endif; ?>

    <!-- Estadísticas -->
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

    <!-- Citas de HOY -->
    <?php if (!empty($citas_hoy)): ?>
        <h3>📅 Citas de HOY (<?= date('d/m/Y') ?>)</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>Hora</th>
                    <th>Paciente</th>
                    <th>TSI</th>
                    <th>Teléfono</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($citas_hoy as $c): ?>
                    <tr>
                        <td><strong><?= date('H:i', strtotime($c->fecha_hora_inicio)) ?></strong></td>
                        <td><?= htmlspecialchars($c->paciente_nombre) ?></td>
                        <td><small><?= htmlspecialchars($c->numero_tsi) ?></small></td>
                        <td><?= htmlspecialchars($c->telefono ?: '-') ?></td>
                        <td>
                            <span class="badge badge-<?= $c->estado ?>"><?= $c->estado ?></span>
                        </td>
                        <td>
                            <?php if ($c->estado === 'pendiente'): ?>
                                <a href="?accion=asistida&cita_id=<?= $c->cita_id ?>" 
                                   onclick="return confirm('¿Marcar como asistida?');"
                                   style="color: var(--primary);">
                                    ✓ Marcar asistida
                                </a>
                            <?php elseif ($c->estado === 'asistida'): ?>
                                <span style="color: #2e7d32;">✓ Completada</span>
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
            <p style="color: #999;">Disfruta de tu día libre 😊</p>
        </div>
    <?php endif; ?>

    <!-- Próximas citas (7 días) -->
    <?php if (!empty($proximas_citas)): ?>
        <h3>🗓️ Próximas citas (siguientes 7 días)</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Hora</th>
                    <th>Paciente</th>
                    <th>Teléfono</th>
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
                        <td>
                            <span class="badge badge-<?= $c->estado ?>"><?= $c->estado ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (empty($citas_hoy) && empty($proximas_citas)): ?>
        <div style="background: #f5f5f5; padding: 48px; text-align: center; border-radius: 8px;">
            <div style="font-size: 64px; margin-bottom: 16px;">🏖️</div>
            <h3>No tienes citas programadas</h3>
            <p style="color: #666;">Tu agenda está libre para los próximos días.</p>
        </div>
    <?php endif; ?>

    <div style="margin-top: 32px; padding-top: 16px; border-top: 1px solid #e0e0e0; text-align: center; color: #999; font-size: 13px;">
        Health2You MiSalud@SCS - Sistema de gestión de citas médicas
    </div>
</div>
<?php wp_footer(); ?>
</body>
</html>
