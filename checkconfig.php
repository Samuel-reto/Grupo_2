<?php
/**
 * CHECK CONFIG - Verifica que config.php está cargado correctamente
 * Sube este archivo a: /wp-content/themes/health2you/check_config.php
 * Accede a: https://www.aglinformatica2.com.es/wp-content/themes/health2you/check_config.php
 */

if (!defined('ABSPATH')) require_once('../../../wp-load.php');

echo "<h1>🔍 Verificación de config.php</h1>";
echo "<style>
body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
h1 { color: #2c3e50; }
h2 { color: #27ae60; margin-top: 30px; }
.success { background: #e8f5e9; padding: 15px; border-radius: 5px; margin: 10px 0; color: #2e7d32; }
.error { background: #ffebee; padding: 15px; border-radius: 5px; margin: 10px 0; color: #c62828; }
.info { background: #e3f2fd; padding: 15px; border-radius: 5px; margin: 10px 0; color: #1565c0; }
table { width: 100%; border-collapse: collapse; margin: 20px 0; background: white; }
th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
th { background: #2c3e50; color: white; }
pre { background: #263238; color: #aed581; padding: 15px; border-radius: 5px; overflow-x: auto; }
</style>";

// 1. Verificar si el archivo config.php existe
echo "<h2>📁 Archivo config.php</h2>";

$config_path = get_stylesheet_directory() . '/config.php';
if (file_exists($config_path)) {
    echo "<div class='success'>✅ El archivo config.php EXISTE</div>";
    echo "<div class='info'>Ruta: <code>$config_path</code></div>";
    
    // Mostrar permisos
    $perms = substr(sprintf('%o', fileperms($config_path)), -4);
    echo "<div class='info'>Permisos: <code>$perms</code></div>";
    
    // Verificar si es legible
    if (is_readable($config_path)) {
        echo "<div class='success'>✅ El archivo es LEGIBLE</div>";
    } else {
        echo "<div class='error'>❌ El archivo NO ES LEGIBLE (problema de permisos)</div>";
    }
} else {
    echo "<div class='error'>❌ El archivo config.php NO EXISTE</div>";
    echo "<div class='error'>Debes subirlo a: <code>$config_path</code></div>";
}

// 2. Intentar cargar config.php
echo "<h2>📥 Carga de config.php</h2>";

if (file_exists($config_path)) {
    try {
        require_once $config_path;
        echo "<div class='success'>✅ config.php cargado correctamente</div>";
    } catch (Exception $e) {
        echo "<div class='error'>❌ Error al cargar config.php: " . $e->getMessage() . "</div>";
    }
} else {
    echo "<div class='error'>❌ No se puede cargar porque no existe</div>";
}

// 3. Verificar constantes
echo "<h2>🔧 Constantes Definidas</h2>";

$constantes = [
    'H2Y_PACIENTE',
    'H2Y_MEDICO',
    'H2Y_ADMINISTRATIVO',
    'H2Y_CITA',
    'H2Y_JUSTIFICANTE',
    'SMTP_HOST',
    'SMTP_PORT',
    'SMTP_USERNAME',
    'SMTP_FROM_EMAIL'
];

echo "<table>";
echo "<tr><th>Constante</th><th>Estado</th><th>Valor</th></tr>";

$todas_ok = true;
foreach ($constantes as $const) {
    $definida = defined($const);
    $estado = $definida ? '✅ Definida' : '❌ NO definida';
    $valor = $definida ? constant($const) : '-';
    
    // Ocultar contraseñas
    if ($const === 'SMTP_PASSWORD' && $definida) {
        $valor = str_repeat('*', strlen($valor));
    }
    
    $color = $definida ? '' : 'style="background: #ffebee;"';
    echo "<tr $color><td><code>$const</code></td><td>$estado</td><td>$valor</td></tr>";
    
    if (!$definida) $todas_ok = false;
}
echo "</table>";

if ($todas_ok) {
    echo "<div class='success'>✅ Todas las constantes necesarias están definidas</div>";
} else {
    echo "<div class='error'>❌ Faltan algunas constantes. Verifica el archivo config.php</div>";
}

// 4. Verificar conexión a base de datos
echo "<h2>🗄️ Conexión a Base de Datos</h2>";

global $wpdb;

if (defined('H2Y_PACIENTE')) {
    $test = $wpdb->get_var("SELECT COUNT(*) FROM " . H2Y_PACIENTE);
    if ($test !== null) {
        echo "<div class='success'>✅ Tabla <code>" . H2Y_PACIENTE . "</code> accesible ($test registros)</div>";
    } else {
        echo "<div class='error'>❌ No se puede acceder a la tabla <code>" . H2Y_PACIENTE . "</code></div>";
    }
}

if (defined('H2Y_MEDICO')) {
    $test = $wpdb->get_var("SELECT COUNT(*) FROM " . H2Y_MEDICO);
    if ($test !== null) {
        echo "<div class='success'>✅ Tabla <code>" . H2Y_MEDICO . "</code> accesible ($test registros)</div>";
    } else {
        echo "<div class='error'>❌ No se puede acceder a la tabla <code>" . H2Y_MEDICO . "</code></div>";
    }
}

if (defined('H2Y_ADMINISTRATIVO')) {
    $test = $wpdb->get_var("SELECT COUNT(*) FROM " . H2Y_ADMINISTRATIVO);
    if ($test !== null) {
        echo "<div class='success'>✅ Tabla <code>" . H2Y_ADMINISTRATIVO . "</code> accesible ($test registros)</div>";
    } else {
        echo "<div class='error'>❌ No se puede acceder a la tabla <code>" . H2Y_ADMINISTRATIVO . "</code></div>";
    }
}

// 5. Resumen
echo "<h2>📋 Resumen</h2>";

if (file_exists($config_path) && $todas_ok) {
    echo "<div class='success'>";
    echo "<h3 style='margin-top:0;'>✅ TODO CORRECTO</h3>";
    echo "<p>El archivo config.php está correctamente instalado y todas las constantes están definidas.</p>";
    echo "<p><strong>Puedes proceder a usar el sistema de registro.</strong></p>";
    echo "</div>";
} else {
    echo "<div class='error'>";
    echo "<h3 style='margin-top:0;'>❌ HAY PROBLEMAS</h3>";
    echo "<p><strong>Pasos para solucionar:</strong></p>";
    echo "<ol>";
    if (!file_exists($config_path)) {
        echo "<li>Sube el archivo <code>config.php</code> a: <code>$config_path</code></li>";
    }
    if (!$todas_ok) {
        echo "<li>Verifica que el archivo <code>config.php</code> contiene todas las constantes necesarias</li>";
    }
    echo "<li>Verifica los permisos del archivo: <code>chmod 644 config.php</code></li>";
    echo "<li>Recarga esta página para verificar de nuevo</li>";
    echo "</ol>";
    echo "</div>";
}

echo "<hr style='margin: 40px 0;'>";
echo "<p style='text-align:center;'>";
echo "<a href='?' style='display:inline-block; padding:10px 20px; background:#3498db; color:white; text-decoration:none; border-radius:5px;'>🔄 Recargar Verificación</a>";
echo "</p>";
echo "<p style='color: #7f8c8d; font-size: 12px; text-align:center;'>Verificación realizada el " . date('d/m/Y H:i:s') . "</p>";
?>
