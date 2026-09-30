<?php

/*
 * index.php für den Server: Die öffentlichen Dateien liegen in /home/iascomm/public_html/em,
 * die Anwendung selbst in /home/iascomm/Energieableseportal (nicht aus dem Internet erreichbar).
 * Wird bei jedem Deploy von .cpanel.yml nach public_html/em kopiert.
 */

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$app_path = dirname(__DIR__, 2).'/Energieableseportal';

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $app_path.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require $app_path.'/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
$app = require_once $app_path.'/bootstrap/app.php';

// Öffentliches Verzeichnis ist dieser Ordner (für Vite-Manifest und public_path()).
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
