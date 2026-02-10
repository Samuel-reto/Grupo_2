<?php
/**
 * Script de diagnóstico para verificar la ruta del logo
 * Guarda este archivo como: test_logo.php en el mismo directorio
 */

if (!defined('ABSPATH')) {
    require_once dirname(__FILE__) . '/../../../wp-load.php';
}

echo "<h2>Diagnóstico de Ruta del Logo</h2>";

// 1. Ruta del directorio del theme
$theme_dir = get_stylesheet_directory();
echo "<p><strong>Directorio del theme:</strong> $theme_dir</p>";

// 2. URI del directorio del theme
$theme_uri = get_stylesheet_directory_uri();
echo "<p><strong>URI del theme:</strong> $theme_uri</p>";

// 3. Ruta completa del archivo
$logo_path = $theme_dir . '/Logo_empresa_grupo_2.png';
echo "<p><strong>Ruta completa del logo:</strong> $logo_path</p>";

// 4. URL completa del logo
$logo_url = $theme_uri . '/Logo_empresa_grupo_2.png';
echo "<p><strong>URL del logo:</strong> $logo_url</p>";

// 5. Verificar si el archivo existe
if (file_exists($logo_path)) {
    echo "<p style='color: green;'>✅ El archivo EXISTE en el servidor</p>";
    
    // Verificar permisos
    $perms = substr(sprintf('%o', fileperms($logo_path)), -4);
    echo "<p><strong>Permisos:</strong> $perms</p>";
    
    // Verificar tamaño
    $size = filesize($logo_path);
    echo "<p><strong>Tamaño:</strong> " . round($size / 1024, 2) . " KB</p>";
    
    // Verificar si es legible
    if (is_readable($logo_path)) {
        echo "<p style='color: green;'>✅ El archivo es LEGIBLE</p>";
    } else {
        echo "<p style='color: red;'>❌ El archivo NO es legible (problema de permisos)</p>";
    }
} else {
    echo "<p style='color: red;'>❌ El archivo NO EXISTE en el servidor</p>";
    
    // Listar archivos en el directorio
    echo "<h3>Archivos en el directorio del theme:</h3>";
    $files = scandir($theme_dir);
    echo "<ul>";
    foreach ($files as $file) {
        if ($file != '.' && $file != '..') {
            echo "<li>$file</li>";
        }
    }
    echo "</ul>";
}

// 6. Probar la imagen
echo "<h3>Vista previa de la imagen:</h3>";
echo "<img src='$logo_url' alt='Logo' style='max-width: 200px; border: 2px solid #ccc; padding: 10px;' />";

// 7. Verificar con diferentes métodos
echo "<h3>Pruebas de diferentes rutas:</h3>";

// Método 1: Ruta relativa
$url1 = get_template_directory_uri() . '/Logo_empresa_grupo_2.png';
echo "<p><strong>Método 1 (template_directory_uri):</strong> $url1</p>";
echo "<img src='$url1' style='max-width: 100px;' /><br>";

// Método 2: Ruta absoluta desde WordPress
$url2 = home_url('/wp-content/themes/health2you/Logo_empresa_grupo_2.png');
echo "<p><strong>Método 2 (home_url):</strong> $url2</p>";
echo "<img src='$url2' style='max-width: 100px;' /><br>";

// Método 3: Ruta directa
$url3 = '/wp-content/themes/health2you/Logo_empresa_grupo_2.png';
echo "<p><strong>Método 3 (ruta directa):</strong> $url3</p>";
echo "<img src='$url3' style='max-width: 100px;' /><br>";

// 8. Verificar el .htaccess
echo "<h3>Verificación de configuración del servidor:</h3>";
$htaccess_path = ABSPATH . '.htaccess';
if (file_exists($htaccess_path)) {
    echo "<p style='color: green;'>✅ El archivo .htaccess existe</p>";
    if (is_readable($htaccess_path)) {
        echo "<p style='color: orange;'>⚠️ Revisa que no haya reglas bloqueando archivos PNG</p>";
    }
} else {
    echo "<p style='color: orange;'>⚠️ No se encontró archivo .htaccess</p>";
}

// 9. Información del servidor
echo "<h3>Información del servidor:</h3>";
echo "<p><strong>Servidor web:</strong> " . $_SERVER['SERVER_SOFTWARE'] . "</p>";
echo "<p><strong>PHP Version:</strong> " . phpversion() . "</p>";

?>
