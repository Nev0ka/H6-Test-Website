<?php

declare(strict_types=1);

namespace App;

/**
 * Builds the JSON-shaped view models consumed by both the server-rendered
 * dashboard shell (first paint) and the polling JSON APIs (public/api/*.php),
 * so the two never drift apart.
 */
final class Presenter
{
    public function __construct(private ServerRepository $repo)
    {
    }

    public function fleetView(?string $selectedHostname = null): array
    {
        $servers = $this->repo->allServersWithLatestMetric();
        $thresholdsCache = [];

        $counts = ['OK' => 0, 'Advarsel' => 0, 'KritiskOffline' => 0];
        $rows = [];

        foreach ($servers as $s) {
            $thresholds = $thresholdsCache[$s['id']] ??= $this->repo->getThresholds((int) $s['id']);
            $online = (bool) $s['online'];
            $status = $online && $s['recorded_at'] !== null
                ? StatusEngine::hostStatus($s, $thresholds, true)
                : StatusEngine::hostStatus($s, $thresholds, false);

            if ($status['label'] === 'OK') {
                $counts['OK']++;
            } elseif ($status['label'] === 'Advarsel') {
                $counts['Advarsel']++;
            } else {
                $counts['KritiskOffline']++;
            }

            $rows[] = [
                'hostname'  => $s['hostname'],
                'ip'        => $s['ip_address'],
                'selected'  => $s['hostname'] === $selectedHostname,
                'dot'       => $status['color'],
                'cpuLabel'  => $online ? Formatting::number((float) $s['cpu_pct'], 0) . ' %' : '—',
                'tempLabel' => $online ? Formatting::number((float) $s['cpu_temp_c'], 0) . ' °C' : 'ingen svar',
            ];
        }

        return [
            'servers'    => $rows,
            'fleetCount' => count($servers) . ' i alt',
            'tallies'    => [
                ['label' => 'Normal', 'n' => $counts['OK'], 'color' => StatusEngine::OK],
                ['label' => 'Advarsel', 'n' => $counts['Advarsel'], 'color' => StatusEngine::WARN],
                ['label' => 'Kritisk / offline', 'n' => $counts['KritiskOffline'], 'color' => StatusEngine::CRIT],
            ],
        ];
    }

    public function hostDetail(string $hostname, string $sortBy = 'cpu'): ?array
    {
        $server = $this->repo->findServerByHostname($hostname);
        if ($server === null) {
            return null;
        }

        $metric = $this->repo->latestMetric((int) $server['id']);
        $online = $this->repo->isOnline($metric['recorded_at'] ?? null);
        $thresholds = $this->repo->getThresholds((int) $server['id']);
        $status = StatusEngine::hostStatus($metric ?? [], $thresholds, $online);

        $cpu = $metric ? (float) $metric['cpu_pct'] : 0.0;
        $temp = $metric ? (float) $metric['cpu_temp_c'] : 0.0;
        $memPct = $metric ? (float) $metric['mem_pct'] : 0.0;
        $memUsedGb = $metric ? (float) $metric['mem_used_gb'] : 0.0;
        $diskUsedPct = $metric ? (float) $metric['disk_used_pct'] : 0.0;
        $tempWarn = (float) $thresholds['temp_warn_c'];
        $tempCrit = (float) $thresholds['temp_crit_c'];
        $diskWarn = (float) $thresholds['disk_warn_pct'];

        $gauges = [
            [
                'label' => 'CPU-belastning',
                'value' => Formatting::number($cpu, 0) . '%',
                'big'   => Formatting::number($cpu, 1) . ' %',
                'hint'  => $server['cpu_cores'] > 0 ? $server['cpu_cores'] . ' kerner' : '',
                'pct'   => $cpu,
                'ring'  => StatusEngine::ringColor($cpu, 60, 85, StatusEngine::BLUE),
            ],
            [
                'label' => 'CPU-temperatur',
                'value' => Formatting::number($temp, 0) . '°',
                'big'   => Formatting::number($temp, 1) . ' °C',
                'hint'  => 'Grænse ' . Formatting::number($tempWarn, 0) . ' °C',
                'pct'   => min(100.0, $temp),
                'ring'  => StatusEngine::ringColor($temp, $tempWarn, $tempCrit, StatusEngine::OK),
            ],
            [
                'label' => 'Hukommelse',
                'value' => Formatting::number($memPct, 0) . '%',
                'big'   => Formatting::number($memUsedGb, 1) . ' GB',
                'hint'  => 'af ' . $server['total_ram_gb'] . ' GB',
                'pct'   => $memPct,
                'ring'  => StatusEngine::ringColor($memPct, 75, 90, StatusEngine::BLUE),
            ],
            [
                'label' => 'Diskplads',
                'value' => Formatting::number($diskUsedPct, 0) . '%',
                'big'   => Formatting::number($server['total_disk_gb'] * (100 - $diskUsedPct) / 100, 0) . ' GB fri',
                'hint'  => 'af ' . Formatting::number((float) $server['total_disk_gb'], 0) . ' GB i alt',
                'pct'   => $diskUsedPct,
                'ring'  => StatusEngine::ringColor($diskUsedPct, $diskWarn, 95, StatusEngine::BLUE),
            ],
        ];

        $history = $this->repo->metricHistory((int) $server['id'], 60);
        $chart = ['labels' => [], 'cpu' => [], 'temp' => [], 'mem' => []];
        foreach ($history as $h) {
            $chart['labels'][] = Formatting::time(new \DateTimeImmutable($h['recorded_at']));
            $chart['cpu'][] = (float) $h['cpu_pct'];
            $chart['temp'][] = (float) $h['cpu_temp_c'];
            $chart['mem'][] = (float) $h['mem_pct'];
        }

        $volumes = [];
        $rawVolumes = $metric ? json_decode($metric['volumes_json'], true) : [];
        foreach ($rawVolumes as $v) {
            $usedPct = (float) $v['used_pct'];
            $sizeGb = (float) $v['size_gb'];
            $color = StatusEngine::ringColor($usedPct, $diskWarn, 95, StatusEngine::BLUE);
            $volumes[] = [
                'mount' => $v['mount'],
                'size'  => Formatting::number($sizeGb, 0) . ' GB',
                'free'  => Formatting::number($sizeGb * (100 - $usedPct) / 100, 0) . ' GB',
                'pct'   => Formatting::number($usedPct, 0) . '%',
                'color' => $color,
                'note'  => Formatting::number($usedPct, 0) . ' % brugt',
            ];
        }

        $processes = [];
        $rawProcesses = $metric ? json_decode($metric['processes_json'], true) : [];
        $sortKey = match ($sortBy) {
            'mem' => 'mem_gb',
            'disk' => 'disk_mbs',
            default => 'cpu_pct',
        };
        usort($rawProcesses, fn ($a, $b) => $b[$sortKey] <=> $a[$sortKey]);
        foreach (array_slice($rawProcesses, 0, 8) as $i => $p) {
            $state = $online ? $p['state'] : 'Ukendt';
            $pill = StatusEngine::processStatePill($state);
            $processes[] = [
                'name'     => $p['name'],
                'user'     => $p['user'],
                'pid'      => $p['pid'],
                'cpu'      => Formatting::number((float) $p['cpu_pct'], 1) . ' %',
                'cpuColor' => StatusEngine::processCpuColor((float) $p['cpu_pct']),
                'mem'      => Formatting::number((float) $p['mem_gb'], 1) . ' GB',
                'disk'     => Formatting::number((float) $p['disk_mbs'], 1) . ' MB/s',
                'state'    => $state,
                'stateBg'  => $pill['bg'],
                'stateFg'  => $pill['fg'],
                'rowEven'  => $i % 2 === 1,
            ];
        }

        $totalProcesses = $metric['total_processes'] ?? 0;
        $sortLabel = match ($sortBy) {
            'mem' => 'Hukommelse',
            'disk' => 'Disk',
            default => 'CPU',
        };

        $osFamily = $server['os_family'];
        $lastSeen = $metric ? Formatting::dateTime(new \DateTimeImmutable($metric['recorded_at'])) : null;

        return [
            'sel' => [
                'name'        => $server['hostname'],
                'ip'          => $server['ip_address'],
                'hw'          => $server['cpu_model'] . ' · ' . $server['cpu_cores'] . ' kerner',
                'os'          => $server['os_name'],
                'osFamily'    => $osFamily,
                'statusLabel' => $status['label'],
                'badgeBg'     => $status['bg'],
                'badgeFg'     => $status['fg'],
                'uptime'      => $metric ? Formatting::uptime((int) $metric['uptime_seconds']) : '—',
                'netIn'       => $metric ? Formatting::number((float) $metric['net_in_mbs'], 1) . ' MB/s' : '—',
                'netOut'      => $metric ? Formatting::number((float) $metric['net_out_mbs'], 1) . ' MB/s' : '—',
                'diskIo'      => $metric ? Formatting::number((float) $metric['disk_io_mbs'], 0) . ' MB/s' : '—',
                'fan'         => $metric ? Formatting::number((float) $metric['fan_rpm'], 0) . ' o/min' : '—',
            ],
            'online'      => $online,
            'hasAlert'    => $status['label'] !== 'OK',
            'alertTitle'  => !$online
                ? 'Ingen kontakt til agenten på ' . $server['hostname']
                : ($status['label'] === 'Kritisk'
                    ? 'Kritisk grænse overskredet på ' . $server['hostname']
                    : 'Værdi over advarselsgrænsen på ' . $server['hostname']),
            'alertBody'   => !$online
                ? 'Ingen måling modtaget' . ($lastSeen ? ' siden ' . $lastSeen : '') . '. Kontrollér netværk og agenttjeneste.'
                : ($diskUsedPct >= $diskWarn
                    ? 'Diskforbrug ' . Formatting::number($diskUsedPct, 0) . ' % mod grænsen ' . Formatting::number($diskWarn, 0) . ' % · CPU-temperatur ' . Formatting::number($temp, 1) . ' °C · CPU ' . Formatting::number($cpu, 0) . ' %.'
                    : ($cpu >= 85
                        ? 'CPU ' . Formatting::number($cpu, 0) . ' % vedvarende · CPU-temperatur ' . Formatting::number($temp, 1) . ' °C · diskforbrug ' . Formatting::number($diskUsedPct, 0) . ' %.'
                        : 'CPU-temperatur ' . Formatting::number($temp, 1) . ' °C mod grænsen ' . Formatting::number($tempWarn, 0) . ' °C · CPU ' . Formatting::number($cpu, 0) . ' % · diskforbrug ' . Formatting::number($diskUsedPct, 0) . ' %.'
                    )
                ),
            'gauges'      => $gauges,
            'chart'       => $chart,
            'volumes'     => $volumes,
            'processes'   => $processes,
            'sortBy'      => $sortBy,
            'procFooter'  => 'Viser ' . count($processes) . ' af ' . $totalProcesses . ' processer · sorteret efter ' . $sortLabel,
        ];
    }
}
