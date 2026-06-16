<?php
// BASE_URL falls back to a dynamic value derived from the current request
if (!defined('BASE_URL')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';
    define('BASE_URL', "$scheme://$host$basePath");
}
define('ITEMS_PER_PAGE', 10);
define('APP_VERSION', trim(file_get_contents(__DIR__ . '/../VERSION')));
