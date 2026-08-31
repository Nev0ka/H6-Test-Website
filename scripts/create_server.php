<?php

declare(strict_types=1);

// CLI only — registers a host and prints its agent API key ONCE (only the
// hash is stored). Run: php scripts/create_server.php <hostname> <ip> <os_family: linux|windows>

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;

if (php_sapi_name() !== 'cli') {
    exit('Kun til CLI-brug.' . PHP_EOL);
}

[$script, $hostname, $ip, $osFamily] = array_pad($argv, 4, null);

if ($hostname === null || $ip === null) {
    fwrite(STDERR, "Brug: php scripts/create_server.php <hostname> <ip> [linux|windows] [cpu_model] [cores] [os_name] [disk_gb] [ram_gb]\n");
    exit(1);
}
$osFamily = in_array($osFamily, ['linux', 'windows'], true) ? $osFamily : 'linux';
$cpuModel = $argv[4] ?? '';
$cores = (int) ($argv[5] ?? 0);
$osName = $argv[6] ?? ($osFamily === 'windows' ? 'Windows Server' : 'Linux');
$diskGb = (int) ($argv[7] ?? 0);
$ramGb = (int) ($argv[8] ?? 0);

$apiKey = bin2hex(random_bytes(32));
$hash = password_hash($apiKey, PASSWORD_ARGON2ID);

$pdo = Database::connection();
$stmt = $pdo->prepare(
    'INSERT INTO servers (hostname, ip_address, cpu_model, cpu_cores, os_name, os_family, total_disk_gb, total_ram_gb, api_key_hash)
     VALUES (:h, :ip, :cpu, :cores, :os, :fam, :disk, :ram, :hash)'
);
$stmt->execute([
    'h' => $hostname, 'ip' => $ip, 'cpu' => $cpuModel, 'cores' => $cores,
    'os' => $osName, 'fam' => $osFamily, 'disk' => $diskGb, 'ram' => $ramGb, 'hash' => $hash,
]);

echo "Server '{$hostname}' oprettet.\n";
echo "API-nøgle (gem den nu — den vises ikke igen):\n{$apiKey}\n";
echo "Agenten skal sende: Authorization: Bearer {$apiKey}\n";
