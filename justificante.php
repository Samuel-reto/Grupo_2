<?php
if (!defined('ABSPATH')) {
    require_once('../../../wp-load.php');
}
session_start();

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

// Seguridad
if (!isset($_SESSION['h2y_tipo']) || $_SESSION['h2y_tipo'] !== 'paciente') {
    wp_die('Acceso no autorizado');
}

$cita_id = (int)($_GET['cita_id'] ?? 0);
if ($cita_id <= 0) {
    wp_die('cita_id inválido');
}

// TCPDF - path correcto
require_once get_stylesheet_directory() . '/tcpdf/tcpdf.php';

// Datos cita
$cita = $wpdb->get_row($wpdb->prepare("
    SELECT c.*, m.nombre as medico_nombre, m.especialidad,
           CONCAT(p.nombre, ' ', p.apellidos) AS paciente_nombre, p.numero_tsi
    FROM " . H2Y_CITA . " c
    JOIN " . H2Y_MEDICO . " m ON c.medico_id = m.medico_id
    JOIN " . H2Y_PACIENTE . " p ON c.paciente_id = p.paciente_id
    WHERE c.cita_id = %d
", $cita_id));

if (!$cita) {
    wp_die('Cita no encontrada');
}

// PDF
$pdf = new TCPDF();
$pdf->SetCreator('Health2You');
$pdf->SetTitle('Justificante Cita ' . $cita_id);
$pdf->SetMargins(15, 15, 15);
$pdf->AddPage();

$fecha = date('d/m/Y H:i', strtotime($cita->fecha_hora_inicio));

// Título
$pdf->SetFont('helvetica', 'B', 20);
$pdf->Cell(0, 15, 'JUSTIFICANTE DE CITA', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(0, 8, 'Health2You', 0, 1, 'C');
$pdf->Ln(5);

// Datos en tabla
$datos = [
    ['Paciente:', $cita->paciente_nombre],
    ['TSI:', $cita->numero_tsi ?: 'N/A'],
    ['Médico:', $cita->medico_nombre],
    ['Especialidad:', $cita->especialidad],
    ['Fecha/Hora:', $fecha],
    ['Estado:', $cita->estado],
];

$pdf->SetFont('helvetica', 'B', 11);
foreach ($datos as $d) {
    $pdf->Cell(45, 8, $d[0], 1, 0, 'L');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(110, 8, $d[1], 1, 1, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
}

$pdf->Ln(10);
$pdf->SetFont('helvetica', 'I', 10);
$pdf->MultiCell(0, 6, 'Este documento certifica la asistencia a la cita médica.', 0, 'C');

$pdf->Ln(5);
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(0, 6, 'Generado: ' . date('d/m/Y H:i:s'), 0, 1, 'C');

$filename = 'justificante_cita_' . $cita_id . '.pdf';

// Headers descarga
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache');

// Limpiar buffers
while (ob_get_level()) ob_end_clean();

$pdf->Output($filename, 'D');
exit;
