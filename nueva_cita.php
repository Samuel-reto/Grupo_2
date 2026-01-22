<?php
if (!defined('ABSPATH')) require_once('../../../wp-load.php');
if (!session_id()) session_start();

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

if (!isset($_SESSION['h2y_tipo']) || $_SESSION['h2y_tipo'] !== 'paciente') {
    wp_redirect(home_url('/login-citas/'));
    exit;
}

$paciente_id = $_SESSION['h2y_paciente_id'];
$medico = $wpdb->get_row("SELECT * FROM " . H2Y_MEDICO . " ORDER BY medico_id LIMIT 1");
$mensaje = "";
$franjas_disponibles = [];

function es_dia_valido($fecha) {
    $dia = date('N', strtotime($fecha));
    if ($dia >= 6) return false;
    
    $festivos = ['2026-01-01','2026-01-06','2026-03-19','2026-04-17','2026-05-01',
                 '2026-08-15','2026-10-12','2026-11-01','2026-12-08','2026-12-25'];
    return !in_array($fecha, $festivos);
}

function get_franjas($wpdb, $medico_id, $fecha) {
    $franjas = ['08:30','08:50','09:10','09:30','09:50','10:10','10:30','10:50',
                '11:10','11:30','11:50','12:10','12:30','12:50','13:10',
                '16:00','16:20','16:40','17:00','17:20','17:40','18:00','18:20','18:40','19:00'];
    $disponibles = [];
    
    foreach ($franjas as $hora) {
        $inicio = "$fecha $hora";
        $fin = date('Y-m-d H:i:s', strtotime("$inicio +20 minutes"));
        
        $ocupada = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM " . H2Y_CITA . "
            WHERE medico_id = %d AND estado <> 'cancelada'
            AND fecha_hora_inicio < %s AND fecha_hora_fin > %s
        ", $medico_id, $fin, $inicio));
        
        if (!$ocupada) $disponibles[] = $hora;
    }
    return $disponibles;
}

if ($_POST && isset($_POST['fecha']) && isset($_POST['hora'])) {
    $fecha = sanitize_text_field($_POST['fecha']);
    $hora = sanitize_text_field($_POST['hora']);
    
    if (es_dia_valido($fecha)) {
        $inicio = "$fecha $hora";
        $fin = date('Y-m-d H:i:s', strtotime("$inicio +20 minutes"));
        
        $wpdb->insert(H2Y_CITA, [
            'paciente_id' => $paciente_id,
            'medico_id' => $medico->medico_id,
            'fecha_hora_inicio' => $inicio,
            'fecha_hora_fin' => $fin,
            'estado' => 'pendiente'
        ]);
        
       $dashboard = get_stylesheet_directory_uri() . '/dashboard_paciente.php?success=nueva';
header("Location: $dashboard");
        exit;
    } else {
        $mensaje = "Fecha no válida";
    }
}

$fecha_sel = $_GET['fecha'] ?? date('Y-m-d');
if (es_dia_valido($fecha_sel)) {
    $franjas_disponibles = get_franjas($wpdb, $medico->medico_id, $fecha_sel);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Nueva cita</title>
    <link rel="stylesheet" href="<?= get_stylesheet_directory_uri(); ?>/style.css">
</head>
<body style="background: linear-gradient(135deg, #e8f5e9, #c8e6c9);">
<div class="page">
    <h2>📅 Nueva cita</h2>
    
    <?php if ($mensaje): ?>
        <div class="alert alert-error"><?= $mensaje ?></div>
    <?php endif; ?>
    
    <form method="post">
        <div class="form-group">
            <label>Fecha</label>
            <input type="date" name="fecha" value="<?= $fecha_sel ?>" min="<?= date('Y-m-d') ?>" required>
        </div>
        
        <div class="form-group">
            <label>Hora</label>
            <select name="hora" required>
                <option value="">Seleccionar...</option>
                <?php foreach ($franjas_disponibles as $h): ?>
                    <option value="<?= $h ?>"><?= $h ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <button type="submit" class="btn">Reservar</button>
        <a href="<?= get_stylesheet_directory_uri(); ?>/dashboard_paciente.php" class="btn btn-secondary">Volver</a>
    </form>
</div>
</body>
</html>
