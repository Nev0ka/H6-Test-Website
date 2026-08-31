<?php

declare(strict_types=1);

namespace App;

use PDO;

final class ServerRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function getSetting(string $key, string $default): string
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :k');
        $stmt->execute(['k' => $key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? (string) $value : $default;
    }

    public function offlineAfterSeconds(): int
    {
        return (int) $this->getSetting('offline_after_seconds', '120');
    }

    /** Global defaults merged with a per-host override, if one exists. */
    public function getThresholds(?int $serverId): array
    {
        $stmt = $this->pdo->prepare('SELECT temp_warn_c, temp_crit_c, disk_warn_pct FROM thresholds WHERE server_id IS NULL LIMIT 1');
        $stmt->execute();
        $global = $stmt->fetch() ?: ['temp_warn_c' => 70, 'temp_crit_c' => 82, 'disk_warn_pct' => 85];

        if ($serverId === null) {
            return $global;
        }

        $stmt = $this->pdo->prepare('SELECT temp_warn_c, temp_crit_c, disk_warn_pct FROM thresholds WHERE server_id = :id LIMIT 1');
        $stmt->execute(['id' => $serverId]);
        $override = $stmt->fetch();

        return $override ?: $global;
    }

    /** All servers with their most recent metric sample (if any), for the fleet sidebar. */
    public function allServersWithLatestMetric(): array
    {
        $offlineAfter = $this->offlineAfterSeconds();

        $sql = "SELECT s.*, m.recorded_at, m.cpu_pct, m.cpu_temp_c, m.mem_pct, m.mem_used_gb,
                       m.disk_used_pct, m.net_in_mbs, m.net_out_mbs, m.disk_io_mbs, m.fan_rpm,
                       m.uptime_seconds, m.volumes_json, m.processes_json, m.total_processes
                FROM servers s
                LEFT JOIN server_metrics m ON m.id = (
                    SELECT id FROM server_metrics WHERE server_id = s.id ORDER BY recorded_at DESC LIMIT 1
                )
                ORDER BY s.hostname ASC";

        $rows = $this->pdo->query($sql)->fetchAll();

        foreach ($rows as &$row) {
            $row['online'] = $row['recorded_at'] !== null
                && (time() - strtotime($row['recorded_at'])) <= $offlineAfter;
        }

        return $rows;
    }

    public function findServerByHostname(string $hostname): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM servers WHERE hostname = :h LIMIT 1');
        $stmt->execute(['h' => $hostname]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findServerById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM servers WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function latestMetric(int $serverId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM server_metrics WHERE server_id = :id ORDER BY recorded_at DESC LIMIT 1');
        $stmt->execute(['id' => $serverId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Samples for the 60-minute chart, oldest first. */
    public function metricHistory(int $serverId, int $minutes = 60): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT recorded_at, cpu_pct, cpu_temp_c, mem_pct FROM server_metrics
             WHERE server_id = :id AND recorded_at >= (NOW() - INTERVAL :minutes MINUTE)
             ORDER BY recorded_at ASC'
        );
        $stmt->bindValue('id', $serverId, PDO::PARAM_INT);
        $stmt->bindValue('minutes', $minutes, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function isOnline(?string $recordedAt): bool
    {
        return $recordedAt !== null && (time() - strtotime($recordedAt)) <= $this->offlineAfterSeconds();
    }

    /** Verifies an agent's API key for a hostname; returns the server row on success. */
    public function findServerByApiKey(string $hostname, string $apiKey): ?array
    {
        $server = $this->findServerByHostname($hostname);
        if ($server === null || !password_verify($apiKey, $server['api_key_hash'])) {
            return null;
        }
        return $server;
    }

    public function insertMetric(int $serverId, array $m): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO server_metrics
                (server_id, recorded_at, cpu_pct, cpu_temp_c, mem_pct, mem_used_gb, disk_used_pct,
                 net_in_mbs, net_out_mbs, disk_io_mbs, fan_rpm, uptime_seconds,
                 volumes_json, processes_json, total_processes)
             VALUES
                (:server_id, NOW(), :cpu_pct, :cpu_temp_c, :mem_pct, :mem_used_gb, :disk_used_pct,
                 :net_in_mbs, :net_out_mbs, :disk_io_mbs, :fan_rpm, :uptime_seconds,
                 :volumes_json, :processes_json, :total_processes)'
        );

        $stmt->execute([
            'server_id'       => $serverId,
            'cpu_pct'         => $m['cpu_pct'],
            'cpu_temp_c'      => $m['cpu_temp_c'],
            'mem_pct'         => $m['mem_pct'],
            'mem_used_gb'     => $m['mem_used_gb'],
            'disk_used_pct'   => $m['disk_used_pct'],
            'net_in_mbs'      => $m['net_in_mbs'] ?? 0,
            'net_out_mbs'     => $m['net_out_mbs'] ?? 0,
            'disk_io_mbs'     => $m['disk_io_mbs'] ?? 0,
            'fan_rpm'         => $m['fan_rpm'] ?? 0,
            'uptime_seconds'  => $m['uptime_seconds'] ?? 0,
            'volumes_json'    => json_encode($m['volumes'] ?? [], JSON_THROW_ON_ERROR),
            'processes_json'  => json_encode($m['processes'] ?? [], JSON_THROW_ON_ERROR),
            'total_processes' => $m['total_processes'] ?? count($m['processes'] ?? []),
        ]);
    }
}
