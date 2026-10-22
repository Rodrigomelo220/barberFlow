<?php
// Roteador para `php -S` (equivale ao .htaccess do Apache).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = realpath(__DIR__ . '/..' . $path);
if ($path !== '/' && $file && is_file($file) && str_starts_with($file, realpath(__DIR__ . '/..')) && !preg_match('#/(app|sql|storage|views|tests)/#', $path)) {
    if (substr($file, -4) === '.php') { $_SERVER['SCRIPT_NAME'] = $path; return false; }
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/../index.php';
