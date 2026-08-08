<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/bootstrap/app.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$uri    = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$method = $_SERVER['REQUEST_METHOD'];
$routes = require __DIR__ . '/routes/api.php';

// Strip leading "sms/" from URI
$uri = preg_replace('#^sms/#', '', $uri);

if (isset($routes[$method][$uri])) {
    [$controllerClass, $action] = $routes[$method][$uri];
    $controller = new $controllerClass();
    $controller->$action();
} else {
    http_response_code(404);
    echo json_encode(["error" => "No route matched: {$method} /{$uri}"]);
}
