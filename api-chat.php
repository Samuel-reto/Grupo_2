<?php
/*
Template Name: H2Y - API Chat
*/

// Evitar que nadie acceda directamente desde el navegador
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Método no permitido');
}

// Cargar WordPress (al usar Template Name, WP ya está cargado, pero limpiamos salida)
// No necesitamos get_header() aquí porque devolvemos JSON (datos), no HTML.

global $wpdb;

// Leer los datos JSON que envía el bot
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['accion'])) {
    echo json_encode(['status' => 'error', 'mensaje' => 'No acción']);
    exit;
}

// RESPONDER AL BOT SEGÚN LA ACCIÓN
$respuesta = ['status' => 'error', 'mensaje' => 'Acción desconocida'];

// Acción 1: Buscar huecos libres
if ($input['accion'] === 'buscar_huecos') {
    $fecha = sanitize_text_field($input['fecha']); // Formato YYYY-MM-DD
    $medico_id = 1; // Por defecto asignamos al primer médico, o podrías pedir especialidad

    // Tu lógica de buscar huecos (copiada de nueva_cita.php)
    $franjas = ['09:00','09:30','10:00','10:30','11:00','11:30','12:00','12:30'];
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
    
    $respuesta = ['status' => 'ok', 'huecos' => $disponibles];
}

// Acción 2: Guardar la cita
if ($input['accion'] === 'guardar_cita') {
    if (!isset($_SESSION['h2y_paciente_id'])) {
        $respuesta = ['status' => 'error', 'mensaje' => 'No has iniciado sesión'];
    } else {
        $fecha = sanitize_text_field($input['fecha']);
        $hora = sanitize_text_field($input['hora']);
        $inicio = "$fecha $hora";
        $fin = date('Y-m-d H:i:s', strtotime("$inicio +20 minutes"));
        $medico_id = 1; 

        $insertado = $wpdb->insert(H2Y_CITA, [
            'paciente_id' => $_SESSION['h2y_paciente_id'],
            'medico_id' => $medico_id,
            'fecha_hora_inicio' => $inicio,
            'fecha_hora_fin' => $fin,
            'estado' => 'pendiente'
        ]);

        if ($insertado) {
            $respuesta = ['status' => 'ok', 'mensaje' => 'Cita guardada correctamente'];
        } else {
            $respuesta = ['status' => 'error', 'mensaje' => 'Error al guardar en BD'];
        }
    }
}

// Devolver respuesta JSON al JavaScript
header('Content-Type: application/json');
echo json_encode($respuesta);
exit;
