<?php
/**
 * Theme Name: Health2You
 * Description: Sistema completo citas médicas con registro
 * Version: 2.0
 * Author: Raquel ASIR
 */

if (!defined('ABSPATH')) exit;

class Health2You {
    public function __construct() {
        add_action('init', [$this, 'init_sessions']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_shortcode('h2y_portal', [$this, 'portal_shortcode']);
        add_shortcode('h2y_login', [$this, 'login_shortcode']);
        add_shortcode('h2y_registro', [$this, 'registro_shortcode']);
        add_shortcode('h2y_registro_medico', [$this, 'registro_medico_shortcode']);
        add_shortcode('h2y_paciente', [$this, 'paciente_shortcode']);
        add_shortcode('h2y_medico', [$this, 'medico_shortcode']);
        add_action('admin_menu', [$this, 'admin_menu']);
    }
    
    public function init_sessions() {
        if (!session_id()) session_start();
    }
    
    public function enqueue_assets() {
        wp_enqueue_style('health2you-main', get_stylesheet_uri(), [], '2.0');
        wp_enqueue_style('health2you-styles', 
            get_stylesheet_directory_uri() . '/styles.css', [], '2.0');
    }
    
    public function portal_shortcode() {
        ob_start();
        include get_stylesheet_directory() . '/index.php';
        return ob_get_clean();
    }
    
    public function login_shortcode() {
        ob_start();
        include get_stylesheet_directory() . '/login.php';
        return ob_get_clean();
    }
    
    public function registro_shortcode() {
        ob_start();
        include get_stylesheet_directory() . '/registro.php';
        return ob_get_clean();
    }
    
    public function registro_medico_shortcode() {
        ob_start();
        include get_stylesheet_directory() . '/registro_medico.php';
        return ob_get_clean();
    }
    
    public function paciente_shortcode() {
        if (!isset($_SESSION['h2y_tipo']) || $_SESSION['h2y_tipo'] !== 'paciente') {
            return '<p>Acceso denegado. <a href="' . get_stylesheet_directory_uri() . '/login.php">Iniciar sesión</a></p>';
        }
        ob_start();
        include get_stylesheet_directory() . '/dashboard_paciente.php';
        return ob_get_clean();
    }
    
    public function medico_shortcode() {
        if (!isset($_SESSION['h2y_tipo']) || $_SESSION['h2y_tipo'] !== 'medico') {
            return '<p>Acceso denegado. <a href="' . get_stylesheet_directory_uri() . '/login.php">Iniciar sesión</a></p>';
        }
        ob_start();
        include get_stylesheet_directory() . '/dashboard_medico.php';
        return ob_get_clean();
    }
    
    public function admin_menu() {
        add_menu_page('Health2You', 'Health2You', 'manage_options', 
            'health2you', [$this, 'admin_page'], 'dashicons-heart', 25);
    }
    
    public function admin_page() {
        global $wpdb;
        $pacientes = $wpdb->get_var("SELECT COUNT(*) FROM paciente");
        $medicos = $wpdb->get_var("SELECT COUNT(*) FROM medico");
        $citas = $wpdb->get_var("SELECT COUNT(*) FROM cita");
        ?>
        <div class="wrap">
            <h1>Health2You - Portal de Salud</h1>
            <div style="background: white; padding: 20px; margin: 20px 0;">
                <h2>📊 Estadísticas</h2>
                <p>Pacientes registrados: <strong><?= $pacientes ?></strong></p>
                <p>Médicos activos: <strong><?= $medicos ?></strong></p>
                <p>Citas totales: <strong><?= $citas ?></strong></p>
            </div>
            <div style="background: #e8f5e9; padding: 15px; border-left: 4px solid #0f9d58;">
                <h3>🔧 Shortcodes disponibles:</h3>
                <p><code>[h2y_portal]</code> - Página principal (portal)</p>
                <p><code>[h2y_login]</code> - Página login</p>
                <p><code>[h2y_registro]</code> - Página registro pacientes</p>
                <p><code>[h2y_registro_medico]</code> - Página registro médicos</p>
                <p><code>[h2y_paciente]</code> - Dashboard paciente</p>
                <p><code>[h2y_medico]</code> - Dashboard médico</p>
            </div>
        </div>
        <?php
    }
}

new Health2You();
?>
