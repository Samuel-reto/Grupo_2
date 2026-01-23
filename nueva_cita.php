<?php
if (!defined('ABSPATH')) require_once('../../../wp-load.php');
if (!session_id()) session_start();

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

if (!isset($_SESSION['h2y_tipo']) || $_SESSION['h2y_tipo'] !== 'paciente') {
    $login_url = get_stylesheet_directory_uri() . '/login.php';
    header("Location: $login_url");
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
    <title>Nueva cita - Health2You</title>
    <link rel="stylesheet" href="<?= get_stylesheet_directory_uri(); ?>/styles.css">
</head>
<body style="background: linear-gradient(135deg, #e8f5e9, #c8e6c9);">
<div class="page">
    <div class="page-header">
        <div>
            <h2>📅 Nueva cita</h2>
            <p class="small-muted">
                Selecciona fecha y hora para tu consulta
            </p>
        </div>
        <div>
            <a href="<?= get_stylesheet_directory_uri(); ?>/dashboard_paciente.php" class="btn btn-secondary">
                ← Volver
            </a>
        </div>
    </div>
    
    <?php if ($mensaje): ?>
        <div class="alert alert-error"><?= $mensaje ?></div>
    <?php endif; ?>
    
    <div style="background: #fff8e1; border-left: 4px solid #f9a825; padding: 12px; margin-bottom: 20px; border-radius: 4px;">
        <strong>ℹ️ Información:</strong> Las citas están disponibles de lunes a viernes, en horario de mañana (8:30-13:30) y tarde (16:00-19:00).
    </div>
    
    <form method="get" style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 16px;">
        <div class="form-group">
            <label for="fecha">📅 Seleccionar fecha</label>
            <input type="date" name="fecha" id="fecha" value="<?= $fecha_sel ?>" min="<?= date('Y-m-d') ?>" required>
        </div>
        <button type="submit" class="btn btn-secondary">Ver disponibilidad</button>
    </form>
    
    <?php if (!empty($franjas_disponibles)): ?>
        <form method="post" style="background: white; padding: 20px; border-radius: 8px;">
            <input type="hidden" name="fecha" value="<?= $fecha_sel ?>">
            
            <h3>🕒 Horarios disponibles para <?= date('d/m/Y', strtotime($fecha_sel)) ?></h3>
            
            <div class="form-group">
                <label>Selecciona una hora:</label>
                <select name="hora" required style="width: 100%;">
                    <option value="">Seleccionar...</option>
                    <?php foreach ($franjas_disponibles as $h): ?>
                        <option value="<?= $h ?>"><?= $h ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <button type="submit" class="btn">✓ Confirmar cita</button>
            <a href="<?= get_stylesheet_directory_uri(); ?>/dashboard_paciente.php" class="btn btn-secondary" style="margin-left: 8px;">
                Cancelar
            </a>
        </form>
    <?php elseif ($_GET['fecha'] ?? false): ?>
        <div style="background: #ffebee; padding: 20px; border-radius: 8px; text-align: center;">
            <div style="font-size: 48px; margin-bottom: 16px;">❌</div>
            <h3>No hay horarios disponibles</h3>
            <p>No hay citas disponibles para esta fecha. Por favor, selecciona otra fecha.</p>
        </div>
    <?php else: ?>
        <div style="background: #e3f2fd; padding: 20px; border-radius: 8px; text-align: center;">
            <div style="font-size: 48px; margin-bottom: 16px;">📅</div>
            <h3>Selecciona una fecha</h3>
            <p>Usa el formulario superior para ver las horas disponibles.</p>
        </div>
    <?php endif; ?>
    
    <div style="margin-top: 24px; padding: 16px; background: #f5f5f5; border-radius: 8px;">
        <h4 style="margin-top: 0;">Información importante:</h4>
        <ul style="font-size: 14px; color: #666; line-height: 1.8;">
            <li>Las citas tienen una duración de 20 minutos</li>
            <li>Puedes modificar o cancelar tu cita desde "Mis citas"</li>
            <li>Te recomendamos llegar 5 minutos antes de tu cita</li>
            <li>No se realizan citas los fines de semana ni festivos</li>
        </ul>
    </div>
</div>
</body>
</html>

