<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\ServerRepository;

header('Content-Type: application/json; charset=utf-8');

/**
 * Agent ingestion endpoint. Auth is a per-host API key (NOT the user session
 * cookie — agents aren't browsers), sent as "Authorization: Bearer <key>".
 * Provision keys with scripts/create_server.php.
 */
function fail(int $code, string $message): never
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Kun POST er tilladt.');
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    fail(401, 'Mangler eller ugyldig Authorization-header.');
}
$apiKey = $m[1];

$rawBody = file_get_contents('php://input', length: 1_048_576); // 1 MB cap
if ($rawBody === false || $rawBody === '') {
    fail(400, 'Tom forespørgsel.');
}

try {
    $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
} catch (\JsonException) {
    fail(400, 'Ugyldig JSON.');
}

if (!is_array($payload) || !isset($payload['hostname']) || !is_string($payload['hostname'])) {
    fail(400, 'Mangler hostname.');
}

$repo = new ServerRepository();
$server = $repo->findServerByApiKey($payload['hostname'], $apiKey);
if ($server === null) {
    fail(401, 'Ugyldigt hostname eller API-nøgle.');
}

function num(mixed $v, float $default = 0.0): float
{
    return is_numeric($v) ? (float) $v : $default;
}

$volumes = [];
if (isset($payload['volumes']) && is_array($payload['volumes'])) {
    foreach (array_slice($payload['volumes'], 0, 32) as $v) {
        if (!is_array($v) || !isset($v['mount'])) {
            continue;
        }
        $volumes[] = [
            'mount'    => substr((string) $v['mount'], 0, 64),
            'size_gb'  => num($v['size_gb'] ?? 0),
            'used_pct' => max(0.0, min(100.0, num($v['used_pct'] ?? 0))),
        ];
    }
}

$processes = [];
if (isset($payload['processes']) && is_array($payload['processes'])) {
    foreach (array_slice($payload['processes'], 0, 500) as $p) {
        if (!is_array($p) || !isset($p['name'])) {
            continue;
        }
        $state = in_array($p['state'] ?? '', ['Kører', 'Venter'], true) ? $p['state'] : 'Ukendt';
        $processes[] = [
            'name'     => substr((string) $p['name'], 0, 128),
            'user'     => substr((string) ($p['user'] ?? ''), 0, 64),
            'pid'      => (int) ($p['pid'] ?? 0),
            'cpu_pct'  => max(0.0, num($p['cpu_pct'] ?? 0)),
            'mem_gb'   => max(0.0, num($p['mem_gb'] ?? 0)),
            'disk_mbs' => max(0.0, num($p['disk_mbs'] ?? 0)),
            'state'    => $state,
        ];
    }
}

$repo->insertMetric((int) $server['id'], [
    'cpu_pct'         => max(0.0, min(100.0, num($payload['cpu_pct'] ?? 0))),
    'cpu_temp_c'      => num($payload['cpu_temp_c'] ?? 0),
    'mem_pct'         => max(0.0, min(100.0, num($payload['mem_pct'] ?? 0))),
    'mem_used_gb'     => max(0.0, num($payload['mem_used_gb'] ?? 0)),
    'disk_used_pct'   => max(0.0, min(100.0, num($payload['disk_used_pct'] ?? 0))),
    'net_in_mbs'      => max(0.0, num($payload['net_in_mbs'] ?? 0)),
    'net_out_mbs'     => max(0.0, num($payload['net_out_mbs'] ?? 0)),
    'disk_io_mbs'     => max(0.0, num($payload['disk_io_mbs'] ?? 0)),
    'fan_rpm'         => max(0, (int) num($payload['fan_rpm'] ?? 0)),
    'uptime_seconds'  => max(0, (int) num($payload['uptime_seconds'] ?? 0)),
    'volumes'         => $volumes,
    'processes'       => $processes,
    'total_processes' => max(count($processes), (int) num($payload['total_processes'] ?? 0)),
]);

echo json_encode(['ok' => true]);
