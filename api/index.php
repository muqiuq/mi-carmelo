<?php
require_once 'config.php';

// Simple API Router
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$path = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '/';

// Temporary setup for the API layout
switch ($path) {
    case '/ping':
        echo json_encode(['status' => 'ok', 'message' => 'API is running']);
        break;
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Endpoint not found']);
        break;
}
