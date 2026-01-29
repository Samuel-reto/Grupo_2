<?php

ob_start();

if (!defined('ABSPATH')) {
    require_once('../../../wp-load.php');
}

if (!session_id()) {
    session_start();
}

// LIMPIAR sesiones viejas al iniciar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION = []; // Resetear todo
}

global $wpdb;
require_once get_stylesheet_directory() . '/config.php';

$error = "";

// ============================================
// FUNCIONES 2FA CON PHPMailer
// ============================================
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require get_stylesheet_directory() . '/PHPMailer/Exception.php';
require get_stylesheet_directory() . '/PHPMailer/PHPMailer.php';
require get_stylesheet_directory() . '/PHPMailer/SMTP.php';

function generar_codigo_2fa() {
    return str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
}

function enviar_codigo_email($email, $codigo, $nombre) {
    $mail = new PHPMailer(true);
    
    try {
        // Configuración servidor SMTP Gmail
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'health2you.asir2@gmail.com';
        $mail->Password   = 'pvwt trec wdge gzkr'; // ← CAMBIAR
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';
        
        // Configuración del email
        $mail->setFrom('health2you.asir2@gmail.com', 'Health2You');
        $mail->addAddress($email, $nombre);
        
        // Contenido HTML
        $mail->isHTML(true);
        $mail->Subject = '🔐 Health2You - Código de verificación';
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #f5f5f5; padding: 20px;'>
                <div style='background: white; padding: 40px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);'>
                    <div style='text-align: center; margin-bottom: 30px;'>
                        <h1 style='color: #0f9d58; margin: 0; font-size: 32px;'>🔐 Health2You</h1>
                        <p style='color: #666; margin: 10px 0 0 0;'>Sistema de Citas Médicas</p>
                    </div>
                    
                    <p style='font-size: 16px; color: #333; margin: 0 0 20px 0;'>
                        Hola <strong>$nombre</strong>,
                    </p>
                    
                    <p style='font-size: 16px; color: #333; margin: 0 0 30px 0;'>
                        Has solicitado acceder a tu cuenta. Para continuar, introduce el siguiente código de verificación:
                    </p>
                    
                    <div style='background: linear-gradient(135deg, #e8f5e9, #c8e6c9); padding: 30px; border-radius: 12px; text-align: center; margin: 0 0 30px 0; border: 2px solid #0f9d58;'>
                        <p style='margin: 0 0 10px 0; color: #666; font-size: 14px;'>TU CÓDIGO DE VERIFICACIÓN:</p>
                        <p style='font-size: 48px; font-weight: bold; color: #0f9d58; letter-spacing: 12px; font-family: monospace; margin: 0;'>
                            $codigo
                        </p>
                    </div>
                    
                    <div style='background: #fff3cd; border-left: 4px solid #ffc107; padding: 16px; border-radius: 4px; margin: 0 0 20px 0;'>
                        <p style='margin: 0; font-size: 14px; color: #856404;'>
                            ⏱️ <strong>Este código es válido por 5 minutos.</strong>
                        </p>
                    </div>
                    
                    <p style='font-size: 14px; color: #666; margin: 0 0 10px 0;'>
                        Si no solicitaste este código, ignora este mensaje.
                    </p>
                    
                    <p style='font-size: 14px; color: #666; margin: 0;'>
                        Nunca compartas este código con nadie, ni siquiera con personal de Health2You.
                    </p>
                    
                    <hr style='border: none; border-top: 1px solid #e0e0e0; margin: 30px 0;'>
                    
                    <p style='font-size: 12px; color: #999; text-align: center; margin: 0;'>
                        Health2You - Proyecto ASIR<br>
                        Sistema de Gestión de Citas Médicas con 2FA
                    </p>
                </div>
            </div>
        ";
        
        // Versión texto plano (para clientes sin HTML)
        $mail->AltBody = "Hola $nombre,\n\nTu código de verificación de Health2You es: $codigo\n\nEste código es válido por 5 minutos.\n\nSi no solicitaste este código, ignora este mensaje.\n\nHealth2You - Sistema de Citas Médicas";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        // Guardar error en log
        error_log("Error PHPMailer: {$mail->ErrorInfo}");
        return false;
    }
}

// ============================================
// PROCESAR LOGIN - ÚNICO BLOQUE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $tipo = sanitize_text_field($_POST['tipo_usuario'] ?? 'paciente');
    $id   = sanitize_text_field(trim($_POST['identificador'] ?? ''));
    $pass = $_POST['password'] ?? '';

    if ($tipo === 'paciente') {
        
        $paciente = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . H2Y_PACIENTE . " WHERE numero_tsi = %s", 
            $id
        ));

        if (!$paciente) {
            $error = "❌ Paciente no encontrado.";
            
        } elseif (!password_verify($pass, $paciente->password_hash)) {
            $error = "❌ Contraseña incorrecta.";
            
        } elseif (empty($paciente->email)) {
            $error = "⚠️ Tu cuenta no tiene email. Contacta con soporte.";
            
        } else {
            // ✅ CREDENCIALES CORRECTAS - INICIAR 2FA
            
            $codigo = generar_codigo_2fa();
            
            // Guardar en sesión TEMPORAL (NO completa el login)
            $_SESSION['codigo_2fa'] = $codigo;
            $_SESSION['codigo_2fa_expira'] = time() + 300;
            $_SESSION['paciente_temp_id'] = $paciente->paciente_id;
            $_SESSION['paciente_temp_nombre'] = $paciente->nombre . ' ' . $paciente->apellidos;
            $_SESSION['paciente_temp_email'] = $paciente->email;
            $_SESSION['tipo_temp'] = 'paciente';
            
            // NO establecer h2y_tipo todavía (esto es clave)
            
            // Enviar email
            enviar_codigo_email($paciente->email, $codigo, $paciente->nombre);
            
            // Redirigir a 2FA
            wp_redirect(get_stylesheet_directory_uri() . '/verificar_2fa.php');
            exit;
        }
        
    } else {
        // MÉDICO - Sin 2FA
        $medico = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . H2Y_MEDICO . " WHERE colegiado = %s", 
            $id
        ));

        if ($medico && password_verify($pass, $medico->password_hash)) {
            $_SESSION['h2y_tipo'] = 'medico';
            $_SESSION['h2y_medico_id'] = $medico->medico_id;
            $_SESSION['h2y_medico_nombre'] = $medico->nombre . ' ' . $medico->apellidos;
            
            wp_redirect(get_stylesheet_directory_uri() . '/dashboard_medico.php');
            exit;
        } else {
            $error = "❌ Credenciales incorrectas.";
        }
    }
}

ob_end_flush();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health2You - Login 2FA</title>
    <link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/style.css">
    <?php wp_head(); ?>
</head>
<body>

<div style="padding: 16px; background: #f5f5f5;">
    <a href="<?= get_stylesheet_directory_uri(); ?>/index.php" 
       style="color: var(--primary); text-decoration: none; font-weight: 600;">
        ← Volver al inicio
    </a>
</div>

<div class="container">
    <div class="left">
        <div class="logo">
            <span>🔐 Health2You</span>
        </div>
        <h1>Acceso seguro</h1>
        <p class="tagline">Autenticación de dos factores (2FA)</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="form-group">
                <label for="tipo_usuario">¿Quién accede?</label>
                <select name="tipo_usuario" id="tipo_usuario">
                    <option value="paciente">👤 Paciente (con 2FA)</option>
                    <option value="medico">🩺 Profesional</option>
                </select>
            </div>

            <div class="form-group">
                <label for="identificador">Nº TSI / Colegiado</label>
                <input type="text" name="identificador" id="identificador" required>
            </div>

            <div class="form-group">
                <label for="password">Contraseña</label>
                <input type="password" name="password" id="password" required>
            </div>

            <button type="submit" class="btn">🔒 Iniciar sesión</button>
        </form>

        <div style="margin-top: 20px; text-align: center; padding-top: 16px; border-top: 1px solid #e0e0e0;">
            <p class="small-muted">¿No tienes cuenta?</p>
            <div style="display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; margin-top: 12px;">
                <a href="<?= get_stylesheet_directory_uri(); ?>/registro.php" class="btn btn-secondary">
                    Registro paciente
                </a>
                <a href="<?= get_stylesheet_directory_uri(); ?>/registro_medico.php" class="btn btn-secondary">
                    Registro médico
                </a>
            </div>
        </div>

        <div style="background: #e3f2fd; padding: 12px; border-radius: 8px; margin-top: 16px; font-size: 13px;">
            <strong>🧪 Test:</strong><br>
            TSI: <code>CANT390123456789</code><br>
            Password: <code>1234</code>
        </div>
    </div>

    <div class="right">
        <h2>🔐 ¿Qué es 2FA?</h2>
        <p class="small-muted">Doble capa de seguridad para tus datos sanitarios:</p>
        <ul class="helper-list">
            <li><strong>Paso 1:</strong> TSI + Contraseña</li>
            <li><strong>Paso 2:</strong> Código por email (6 dígitos)</li>
            <li>Válido 5 minutos</li>
            <li>Revisa spam si no llega</li>
        </ul>
    </div>
</div>

<?php wp_footer(); ?>
</body>
</html>

