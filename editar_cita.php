<?php
if (!defined('ABSPATH')) require_once('../../../wp-load.php');
if (!session_id()) session_start();

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

if (!isset($_SESSION['h2y_tipo'])) {
    $login_url = get_stylesheet_directory_uri() . '/index.php';
    header("Location: $login_url");
    exit;
}

$cita_id = intval($_GET['id'] ?? 0);
$cita = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . H2Y_CITA . " WHERE cita_id = %d", $cita_id));

if (!$cita) {
    die("Cita no encontrada");
}

$mensaje = "";
$tipo_mensaje = "error"; // 'error' o 'success'

// CANCELAR CITA
if (isset($_GET['accion']) && $_GET['accion'] === 'cancelar') {
    $wpdb->update(
        H2Y_CITA, 
        ['estado' => 'cancelada'], 
        ['cita_id' => $cita_id]
    );
    
    $dashboard = get_stylesheet_directory_uri() . '/dashboard_paciente.php?success=cancelada';
    header("Location: $dashboard");
    exit;
}

// MODIFICAR FECHA/HORA
if ($_POST && isset($_POST['fecha']) && isset($_POST['hora'])) {
    $fecha = sanitize_text_field($_POST['fecha']);
    $hora = sanitize_text_field($_POST['hora']);
    
    if (empty($fecha) || empty($hora)) {
        $mensaje = "❌ Fecha y hora son obligatorios.";
    } else {
        $inicio = "$fecha $hora";
        $fin = date('Y-m-d H:i:s', strtotime("$inicio +20 minutes"));
        
        // Verificar disponibilidad
        $conflicto = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM " . H2Y_CITA . "
            WHERE medico_id = %d 
              AND cita_id != %d
              AND estado <> 'cancelada'
              AND fecha_hora_inicio < %s 
              AND fecha_hora_fin > %s
        ", $cita->medico_id, $cita_id, $fin, $inicio));
        
        if ($conflicto > 0) {
            $mensaje = "❌ El horario seleccionado no está disponible.";
        } else {
            $resultado = $wpdb->update(H2Y_CITA, [
                'fecha_hora_inicio' => $inicio,
                'fecha_hora_fin' => $fin
            ], ['cita_id' => $cita_id]);
            
            if ($resultado !== false) {
                $dashboard = get_stylesheet_directory_uri() . '/dashboard_paciente.php?success=modificada';
                header("Location: $dashboard");
                exit;
            } else {
                $mensaje = "❌ Error al modificar la cita.";
            }
        }
    }
}

// Obtener datos del médico
$medico = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM " . H2Y_MEDICO . " WHERE medico_id = %d", $cita->medico_id
));
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Modificar cita</title>
    <link rel="stylesheet" href="<?= get_stylesheet_directory_uri(); ?>/style.css">
    <script>
        function confirmarCancelacion() {
            return confirm('⚠️ ¿Estás seguro de que deseas CANCELAR esta cita?\n\nEsta acción no se puede deshacer.');
        }
    </script>
</head>
<body style="background: linear-gradient(135deg, #e8f5e9, #c8e6c9);">
<div class="page">
    <div class="page-header">
        <div>
            <h2>✏️ Modificar cita médica</h2>
            <p class="small-muted">
                Médico: <strong><?= htmlspecialchars($medico->nombre . ' ' . $medico->apellidos) ?></strong><br>
                Especialidad: <strong><?= htmlspecialchars($medico->especialidad) ?></strong>
            </p>
        </div>
        <div>
            <a href="<?= get_stylesheet_directory_uri(); ?>/dashboard_paciente.php" class="btn btn-secondary">
                ← Volver
            </a>
        </div>
    </div>
    
    <?php if ($mensaje): ?>
        <div class="alert alert-<?= $tipo_mensaje ?>">
            <?= htmlspecialchars($mensaje) ?>
        </div>
    <?php endif; ?>
    
    <div style="background: #fff3cd; border-left: 4px solid #f9a825; padding: 12px; margin-bottom: 20px; border-radius: 4px;">
        <strong>ℹ️ Información:</strong> Solo puedes modificar citas en días laborables (L-V) dentro del horario oficial.
    </div>
    
    <form method="post" style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 16px;">
        <div class="filter-row">
            <div class="form-group" style="flex:2;">
                <label for="fecha">📅 Nueva fecha</label>
                <input type="date" name="fecha" id="fecha"
                       value="<?= substr($cita->fecha_hora_inicio, 0, 10) ?>" 
                       min="<?= date('Y-m-d') ?>" required>
            </div>
            
            <div class="form-group" style="flex:1;">
                <label for="hora">🕒 Nueva hora</label>
                <input type="time" name="hora" id="hora"
                       value="<?= substr($cita->fecha_hora_inicio, 11, 5) ?>" 
                       step="1200" required>
                <small class="small-muted">Franjas de 20 minutos</small>
            </div>
        </div>
        
        <button type="submit" class="btn" style="margin-right: 8px;">
            💾 Guardar cambios
        </button>
        
        <a href="<?= get_stylesheet_directory_uri(); ?>/dashboard_paciente.php" 
           class="btn btn-secondary">
            Cancelar
        </a>
    </form>
    
    <!-- SECCIÓN CANCELAR CITA -->
    <div style="background: #ffebee; border: 1px solid #ef5350; padding: 20px; border-radius: 8px;">
        <h3 style="margin-top: 0; color: #c62828;">🗑️ Zona peligrosa</h3>
        <p style="color: #666; font-size: 14px;">
            Si no puedes asistir a esta cita, puedes cancelarla. Podrás solicitar una nueva cita en otro momento.
        </p>
        
        <a href="?id=<?= $cita_id ?>&accion=cancelar" 
           onclick="return confirmarCancelacion();"
           class="btn" 
           style="background: linear-gradient(135deg, #c62828, #d32f2f); margin-top: 8px;">
            ❌ Cancelar esta cita
        </a>
    </div>
</div>
</body>
</html>
