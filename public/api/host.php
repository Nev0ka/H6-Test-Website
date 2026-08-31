<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Auth;
use App\Presenter;
use App\ServerRepository;

Auth::start();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Ikke logget ind.']);
    exit;
}

$hostname = isset($_GET['host']) ? (string) $_GET['host'] : '';
$requestedSort = (string) ($_GET['sort'] ?? 'cpu');
$sortBy = in_array($requestedSort, ['cpu', 'mem', 'disk'], true) ? $requestedSort : 'cpu';

if ($hostname === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Mangler parameteren host.']);
    exit;
}

$presenter = new Presenter(new ServerRepository());
$detail = $presenter->hostDetail($hostname, $sortBy);

if ($detail === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Ukendt server.']);
    exit;
}

echo json_encode(['data' => $detail, 'clock' => date('H:i')], JSON_UNESCAPED_SLASHES);
