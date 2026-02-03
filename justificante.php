<?php
if (!defined('ABSPATH')) {
    require_once('../../../wp-load.php');
}

if (!session_id()) session_start();

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

// Seguridad
if (!isset($_SESSION['h2y_tipo']) || $_SESSION['h2y_tipo'] !== 'paciente') {
    wp_die('Acceso no autorizado');
}

// Verificar que existe paciente_id en sesión (compatibilidad con nueva estructura)
$paciente_id_session = $_SESSION['h2y_paciente_id'] ?? $_SESSION['h2y_pacienteid'] ?? 0;
if (!$paciente_id_session) {
    wp_die('Sesión inválida. Por favor, inicia sesión nuevamente.');
}

$cita_id = (int)($_GET['cita_id'] ?? 0);
if ($cita_id <= 0) {
    wp_die('cita_id inválido');
}

// TCPDF - path correcto
require_once get_stylesheet_directory() . '/tcpdf/tcpdf.php';

// Datos cita - Consulta compatible con ambas estructuras
// Primero intentamos obtener con la estructura nueva (nombre y apellidos separados)
$cita = $wpdb->get_row($wpdb->prepare("
    SELECT 
        c.*,
        m.nombre as medico_nombre,
        m.apellidos as medico_apellidos,
        m.especialidad,
        p.nombre as paciente_nombre,
        p.apellidos as paciente_apellidos,
        p.numero_tsi
    FROM " . H2Y_CITA . " c
    JOIN " . H2Y_MEDICO . " m ON c.medico_id = m.medico_id
    JOIN " . H2Y_PACIENTE . " p ON c.paciente_id = p.paciente_id
    WHERE c.cita_id = %d
      AND c.paciente_id = %d
", $cita_id, $paciente_id_session));

if (!$cita) {
    wp_die('Cita no encontrada o no tienes permiso para acceder a ella');
}

// Construir nombre completo del médico
$medico_nombre_completo = trim($cita->medico_nombre);
if (!empty($cita->medico_apellidos)) {
    $medico_nombre_completo .= ' ' . $cita->medico_apellidos;
}

// Construir nombre completo del paciente
$paciente_nombre_completo = trim($cita->paciente_nombre);
if (!empty($cita->paciente_apellidos)) {
    $paciente_nombre_completo .= ' ' . $cita->paciente_apellidos;
}

// PDF
$pdf = new TCPDF();
$pdf->SetCreator('Health2You');
$pdf->SetTitle('Justificante Cita ' . $cita_id);
$pdf->SetMargins(15, 15, 15);
$pdf->AddPage();

$fecha = date('d/m/Y H:i', strtotime($cita->fecha_hora_inicio));

// Título con logo (opcional)
$pdf->SetFont('helvetica', 'B', 20);
$pdf->SetTextColor(76, 175, 80); // Verde Health2You
$pdf->Cell(0, 15, 'JUSTIFICANTE DE CITA MEDICA', 0, 1, 'C');

$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(0, 8, 'Health2You - Sistema de Gestion de Citas', 0, 1, 'C');
$pdf->Ln(3);

// Línea separadora
$pdf->SetLineWidth(0.5);
$pdf->SetDrawColor(76, 175, 80);
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
$pdf->Ln(8);

// Información del paciente
$pdf->SetFont('helvetica', 'B', 13);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(0, 8, 'DATOS DEL PACIENTE', 0, 1, 'L', true);
$pdf->Ln(2);

$datos_paciente = [
    ['Nombre completo:', $paciente_nombre_completo],
    ['Numero TSI:', $cita->numero_tsi ?: 'No registrado'],
];

$pdf->SetFont('helvetica', 'B', 11);
foreach ($datos_paciente as $d) {
    $pdf->Cell(50, 7, $d[0], 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 7, $d[1], 0, 1, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
}

$pdf->Ln(5);

// Información de la cita
$pdf->SetFont('helvetica', 'B', 13);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(0, 8, 'DATOS DE LA CITA', 0, 1, 'L', true);
$pdf->Ln(2);

// Calcular duración
$fecha_inicio = strtotime($cita->fecha_hora_inicio);
$fecha_fin = strtotime($cita->fecha_hora_fin);
$duracion = ($fecha_fin - $fecha_inicio) / 60; // en minutos

$datos_cita = [
    ['Medico:', $medico_nombre_completo],
    ['Especialidad:', $cita->especialidad ?: 'Medicina General'],
    ['Fecha:', date('d/m/Y', $fecha_inicio)],
    ['Hora inicio:', date('H:i', $fecha_inicio)],
    ['Hora fin:', date('H:i', $fecha_fin)],
    ['Duracion:', $duracion . ' minutos'],
    ['Estado:', strtoupper($cita->estado)],
    ['ID Cita:', str_pad($cita_id, 6, '0', STR_PAD_LEFT)],
];

$pdf->SetFont('helvetica', 'B', 11);
foreach ($datos_cita as $d) {
    $pdf->Cell(50, 7, $d[0], 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 7, $d[1], 0, 1, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
}

$pdf->Ln(10);

// Texto certificación
$pdf->SetFont('helvetica', '', 11);
$pdf->MultiCell(0, 6, 
    'Se certifica que el/la paciente arriba mencionado/a tiene programada una cita medica ' .
    'para el dia ' . date('d/m/Y', $fecha_inicio) . ' a las ' . date('H:i', $fecha_inicio) . ' horas ' .
    'en las instalaciones de Health2You con el Dr./Dra. ' . $medico_nombre_completo . '.',
    0, 'J');

$pdf->Ln(8);

// Nota importante
$pdf->SetFont('helvetica', 'I', 10);
$pdf->SetTextColor(100, 100, 100);
$pdf->MultiCell(0, 5, 
    'NOTA: Este documento es un justificante informativo generado por el sistema ' .
    'Health2You. Para validar su autenticidad, puede verificar el ID de cita en ' .
    'nuestro sistema o contactar con nuestras oficinas.',
    0, 'L');

// Línea separadora
$pdf->Ln(8);
$pdf->SetLineWidth(0.3);
$pdf->SetDrawColor(200, 200, 200);
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
$pdf->Ln(5);

// Pie de página con información
$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(0, 5, 'Health2You - Sistema de Gestion de Citas Medicas', 0, 1, 'C');
$pdf->SetFont('helvetica', 'I', 8);
$pdf->SetTextColor(100, 100, 100);
$pdf->Cell(0, 5, 'Documento generado el: ' . date('d/m/Y H:i:s'), 0, 1, 'C');

// Código de verificación (opcional)
$codigo_verificacion = strtoupper(substr(md5($cita_id . $paciente_id_session), 0, 8));
$pdf->Ln(3);
$pdf->SetFont('helvetica', '', 8);
$pdf->Cell(0, 5, 'Codigo de verificacion: ' . $codigo_verificacion, 0, 1, 'C');

$filename = 'justificante_cita_' . str_pad($cita_id, 6, '0', STR_PAD_LEFT) . '.pdf';

// Headers para forzar descarga
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Limpiar todos los buffers de salida
while (ob_get_level()) {
    ob_end_clean();
}

// Generar PDF
$pdf->Output($filename, 'D');
exit;
