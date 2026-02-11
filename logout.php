<?php
if (!defined('ABSPATH')) require_once('../../../wp-load.php');
session_start();
session_unset();
session_destroy();

$index_url = get_stylesheet_directory_uri() . '/index.php';
header("Location: $index_url");
exit;
?>

