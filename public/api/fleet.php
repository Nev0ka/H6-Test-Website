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

$selected = isset($_GET['selected']) ? (string) $_GET['selected'] : null;

$presenter = new Presenter(new ServerRepository());
echo json_encode([
    'data'  => $presenter->fleetView($selected),
    'clock' => date('H:i'),
], JSON_UNESCAPED_SLASHES);
