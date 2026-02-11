<?php
/**
 * Health2You - Gestión de Médicos de Urgencias
 * Panel administrativo para asignar médicos al servicio de urgencias
 */

if (!defined('ABSPATH')) {
    require_once dirname(__FILE__) . '/../../../wp-load.php';
}

if (!session_id()) session_start();

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

// Seguridad: solo administrativos
if (!isset($_SESSION['h2y_tipo']) || $_SESSION['h2y_tipo'] !== 'administrativo') {
    header('Location: ' . get_stylesheet_directory_uri() . '/login.php');
    exit;
}

$mensaje = '';
$tipo_mensaje = 'success';

// Procesar actualización
if ($_POST && isset($_POST['actualizar_urgencias'])) {
    $medicos_urgencias = $_POST['medicos_urgencias'] ?? [];
    
    // Primero, quitar urgencias a todos
    $wpdb->query("UPDATE " . H2Y_MEDICO . " SET atiende_urgencias = 0");
    
    // Luego, activar solo los seleccionados
    if (!empty($medicos_urgencias)) {
        $ids = array_map('intval', $medicos_urgencias);
        $ids_str = implode(',', $ids);
        $wpdb->query("UPDATE " . H2Y_MEDICO . " SET atiende_urgencias = 1 WHERE medico_id IN ($ids_str)");
    }
    
    $mensaje = '✅ Configuración de urgencias actualizada correctamente';
}

// Obtener todos los médicos
$medicos = $wpdb->get_results("
    SELECT 
        m.*,
        COUNT(DISTINCT c.paciente_id) as total_pacientes,
        COUNT(c.cita_id) as total_citas
    FROM " . H2Y_MEDICO . " m
    LEFT JOIN " . H2Y_CITA . " c ON m.medico_id = c.medico_id
    GROUP BY m.medico_id
    ORDER BY m.apellidos, m.nombre
");

// Estadísticas de videollamadas
$stats_videollamadas = $wpdb->get_results("
    SELECT 
        DATE(fecha_solicitud) as fecha,
        COUNT(*) as total,
        SUM(CASE WHEN estado = 'aceptada' OR estado = 'en_curso' OR estado = 'finalizada' THEN 1 ELSE 0 END) as atendidas,
        SUM(CASE WHEN estado = 'rechazada' THEN 1 ELSE 0 END) as rechazadas,
        SUM(CASE WHEN estado = 'expirada' THEN 1 ELSE 0 END) as expiradas
    FROM " . H2Y_VIDEOLLAMADA . "
    WHERE fecha_solicitud >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(fecha_solicitud)
    ORDER BY fecha DESC
    LIMIT 10
");
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Urgencias - Health2You</title>
    <link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/styles.css">
    <?php wp_head(); ?>
    <style>
        .medico-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s;
        }
        .medico-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }
        .medico-card.urgencias-activo {
            border-left: 5px solid #ff5252;
        }
        .medico-info {
            flex: 1;
        }
        .medico-nombre {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 4px;
        }
        .medico-especialidad {
            color: #666;
            font-size: 14px;
            margin-bottom: 8px;
        }
        .medico-stats {
            display: flex;
            gap: 16px;
            font-size: 13px;
            color: #999;
        }
        .toggle-urgencias {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }
        .toggle-urgencias input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: #ff5252;
        }
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        .stats-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 24px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }
        .stat-item {
            text-align: center;
            padding: 16px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #ff5252;
        }
        .stat-label {
            font-size: 14px;
            color: #666;
            margin-top: 4px;
        }
    </style>
</head>
<body style="background: linear-gradient(135deg, #fff3e0, #f5f5f5); padding: 16px;">

<div class="page">
    <div class="page-header">
        <div>
            <h2>🚨 Gestión de Médicos de Urgencias</h2>
            <p class="small-muted">Configura qué médicos pueden atender videollamadas urgentes</p>
        </div>
        <a href="<?= get_stylesheet_directory_uri(); ?>/dashboard.php" class="btn btn-secondary">← Volver</a>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert" style="background: <?= $tipo_mensaje === 'success' ? '#d4edda' : '#f8d7da' ?>; color: <?= $tipo_mensaje === 'success' ? '#155724' : '#721c24' ?>;">
            <?= $mensaje ?>
        </div>
    <?php endif; ?>

    <!-- Estadísticas -->
    <?php if (!empty($stats_videollamadas)): ?>
        <div class="stats-card">
            <h3 style="margin-top: 0;">📊 Estadísticas de Videollamadas (Últimos 30 días)</h3>
            
            <?php
            $total_solicitudes = array_sum(array_column($stats_videollamadas, 'total'));
            $total_atendidas = array_sum(array_column($stats_videollamadas, 'atendidas'));
            $total_rechazadas = array_sum(array_column($stats_videollamadas, 'rechazadas'));
            $total_expiradas = array_sum(array_column($stats_videollamadas, 'expiradas'));
            ?>
            
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-number"><?= $total_solicitudes ?></div>
                    <div class="stat-label">Total Solicitudes</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" style="color: #28a745;"><?= $total_atendidas ?></div>
                    <div class="stat-label">Atendidas</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" style="color: #ffc107;"><?= $total_rechazadas ?></div>
                    <div class="stat-label">Rechazadas</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" style="color: #dc3545;"><?= $total_expiradas ?></div>
                    <div class="stat-label">Expiradas</div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Formulario de médicos -->
    <form method="post">
        <h3>👨‍⚕️ Selecciona Médicos para Urgencias</h3>
        <p class="small-muted">Los médicos seleccionados recibirán notificaciones de videollamadas urgentes en su dashboard</p>

        <?php if (empty($medicos)): ?>
            <div class="alert" style="background: #f8d7da; color: #721c24;">
                No hay médicos registrados en el sistema
            </div>
        <?php else: ?>
            <?php foreach ($medicos as $medico): ?>
                <div class="medico-card <?= $medico->atiende_urgencias ? 'urgencias-activo' : '' ?>">
                    <div class="medico-info">
                        <div class="medico-nombre">
                            Dr./Dra. <?= htmlspecialchars($medico->nombre . ' ' . $medico->apellidos) ?>
                        </div>
                        <div class="medico-especialidad">
                            <?= htmlspecialchars($medico->especialidad) ?>
                        </div>
                        <div class="medico-stats">
                            <span>👥 <?= $medico->total_pacientes ?> pacientes</span>
                            <span>📅 <?= $medico->total_citas ?> citas</span>
                            <span>📧 <?= htmlspecialchars($medico->email) ?></span>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 16px;">
                        <div style="text-align: right; margin-right: 12px;">
                            <div style="font-size: 12px; color: #666; margin-bottom: 4px;">
                                <?= $medico->atiende_urgencias ? 'Activo en urgencias' : 'No atiende urgencias' ?>
                            </div>
                        </div>
                        <label class="toggle-urgencias">
                            <input type="checkbox" 
                                   name="medicos_urgencias[]" 
                                   value="<?= $medico->medico_id ?>"
                                   <?= $medico->atiende_urgencias ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
            <?php endforeach; ?>

            <div style="margin-top: 24px;">
                <button type="submit" name="actualizar_urgencias" class="btn" style="padding: 14px 32px; font-size: 16px;">
                    💾 Guardar Configuración
                </button>
            </div>
        <?php endif; ?>
    </form>
</div>

<?php wp_footer(); ?>
</body>
</html>
