<?php

declare(strict_types=1);

// CLI only — deletes metric samples older than app_settings.metrics_retention_hours.
// Run from cron, e.g. hourly: php scripts/cleanup_metrics.php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\ServerRepository;

if (php_sapi_name() !== 'cli') {
    exit('Kun til CLI-brug.' . PHP_EOL);
}

$hours = (int) (new ServerRepository())->getSetting('metrics_retention_hours', '72');

$stmt = Database::connection()->prepare('DELETE FROM server_metrics WHERE recorded_at < (NOW() - INTERVAL :h HOUR)');
$stmt->bindValue('h', $hours, PDO::PARAM_INT);
$stmt->execute();

echo "Slettede målinger ældre end {$hours} timer (" . $stmt->rowCount() . " rækker).\n";
