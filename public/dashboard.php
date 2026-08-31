<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Auth;
use App\Presenter;
use App\ServerRepository;

Auth::start();
Auth::requireLogin();

$user = Auth::user();
$repo = new ServerRepository();
$presenter = new Presenter($repo);

$allServers = $repo->allServersWithLatestMetric();
$requestedHost = isset($_GET['host']) ? (string) $_GET['host'] : null;
$selectedHostname = null;

if ($requestedHost !== null && array_any($allServers, fn ($s) => $s['hostname'] === $requestedHost)) {
    $selectedHostname = $requestedHost;
} elseif (!empty($allServers)) {
    $selectedHostname = $allServers[0]['hostname'];
}

$fleet = $presenter->fleetView($selectedHostname);
$detail = $selectedHostname !== null ? $presenter->hostDetail($selectedHostname) : null;

function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/** array_any polyfill — PHP 8.4 has it natively, this keeps older 8.2/8.3 working. */
if (!function_exists('array_any')) {
    function array_any(array $arr, callable $fn): bool
    {
        foreach ($arr as $k => $v) {
            if ($fn($v, $k)) {
                return true;
            }
        }
        return false;
    }
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=1440">
<title>Serverovervågning</title>
<link rel="stylesheet" href="/assets/css/tokens.css">
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="app-shell">

  <header class="header">
    <div class="header-brand">
      <div class="header-logo"><i class="fa-solid fa-server" aria-hidden="true"></i></div>
      <span class="header-title">Serverovervågning</span>
    </div>
    <div class="header-search">
      <i class="fa-solid fa-magnifying-glass" style="font-size:11px" aria-hidden="true"></i>
      <span>Søg efter server, IP eller tag</span>
    </div>
    <div class="header-spacer"></div>
    <div class="header-live">
      <span class="header-live-dot" id="live-dot"></span>
      <span id="live-label">Live · opdaterer hvert 2. sekund</span>
      <span class="header-dot-divider">·</span>
      <span>Sidst opdateret <span id="clock"><?= e($fleet['clock'] ?? date('H:i')) ?></span></span>
    </div>
    <button type="button" class="btn" id="live-toggle">
      <i class="fa-solid fa-rotate" aria-hidden="true"></i><span id="live-button-label">Pause</span>
    </button>
    <div class="header-avatar" title="<?= e($user['display_name']) ?>"><?= e($user['initials']) ?></div>
    <a href="/logout.php" class="btn" style="text-decoration:none">Log ud</a>
  </header>

  <div class="layout">
    <aside class="sidebar">
      <div class="sidebar-head">
        <span class="sidebar-head-label">Servere</span>
        <span class="sidebar-head-count" id="fleet-count"><?= e($fleet['fleetCount']) ?></span>
      </div>
      <div class="tally-grid" id="tally-grid">
        <?php foreach ($fleet['tallies'] as $t): ?>
          <div class="tally-cell">
            <div class="tally-count" style="color: <?= e($t['color']) ?>"><?= (int) $t['n'] ?></div>
            <div class="tally-label"><?= e($t['label']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="server-list" id="server-list">
        <?php if (empty($fleet['servers'])): ?>
          <div class="empty-state">Ingen servere er registreret endnu.</div>
        <?php endif; ?>
        <?php foreach ($fleet['servers'] as $s): ?>
          <button type="button" class="server-row<?= $s['selected'] ? ' selected' : '' ?>" data-host="<?= e($s['hostname']) ?>">
            <span class="server-dot" style="background: <?= e($s['dot']) ?>"></span>
            <span class="server-id">
              <span class="server-name"><?= e($s['hostname']) ?></span>
              <span class="server-sub"><?= e($s['ip']) ?></span>
            </span>
            <span class="server-right">
              <span class="server-cpu" style="color: <?= e($s['dot']) ?>"><?= e($s['cpuLabel']) ?></span>
              <span class="server-temp"><?= e($s['tempLabel']) ?></span>
            </span>
          </button>
        <?php endforeach; ?>
      </div>
    </aside>

    <main class="main" id="main-content">
      <?php if ($detail === null): ?>
        <div class="empty-state">Vælg en server i venstre side, eller vent på den første måling fra en agent.</div>
      <?php else: ?>
        <?= render_host_detail($detail) ?>
      <?php endif; ?>
    </main>
  </div>
</div>

<script>window.__CSRF__ = <?= json_encode(Auth::csrfToken()) ?>;</script>
<script src="/assets/vendor/chart.umd.min.js"></script>
<script src="/assets/js/dashboard.js"></script>
</body>
</html>
<?php

/** Renders the main-column markup for a host detail payload — shared shape with dashboard.js's client-side renderer. */
function render_host_detail(array $d): string
{
    ob_start();
    $sel = $d['sel'];
    ?>
    <div class="main-head">
      <div>
        <div class="host-title-row">
          <h1 class="host-title" id="host-name"><?= e($sel['name']) ?></h1>
          <span class="status-pill" id="host-status-pill" style="color: <?= e($sel['badgeFg']) ?>; background: <?= e($sel['badgeBg']) ?>"><?= e($sel['statusLabel']) ?></span>
        </div>
        <div class="meta-line" id="host-meta">
          <span><i class="fa-solid fa-network-wired" aria-hidden="true"></i><?= e($sel['ip']) ?></span>
          <span><i class="fa-solid fa-microchip" aria-hidden="true"></i><?= e($sel['hw']) ?></span>
          <span><i class="fa-<?= $sel['osFamily'] === 'windows' ? 'brands fa-windows' : 'brands fa-linux' ?>" aria-hidden="true"></i><?= e($sel['os']) ?></span>
          <span><i class="fa-solid fa-clock" aria-hidden="true"></i>Driftstid <?= e($sel['uptime']) ?></span>
        </div>
      </div>
      <div class="main-actions">
        <button type="button" class="btn" disabled title="Ikke tilgængelig endnu">Eksportér rapport</button>
        <button type="button" class="btn-primary-soft" disabled title="Ikke tilgængelig endnu">Konfigurér grænser</button>
      </div>
    </div>

    <div id="alert-slot"><?= render_alert($d) ?></div>

    <div class="gauges-grid" id="gauges-grid">
      <?php foreach ($d['gauges'] as $g): ?>
        <div class="gauge-card">
          <div class="gauge-ring" style="--ring-color: <?= e($g['ring']) ?>; --ring-pct: <?= (float) $g['pct'] ?>">
            <div class="gauge-ring-inner"><?= e($g['value']) ?></div>
          </div>
          <div class="gauge-text">
            <div class="gauge-label"><?= e($g['label']) ?></div>
            <div class="gauge-value"><?= e($g['big']) ?></div>
            <div class="gauge-hint"><?= e($g['hint']) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="panels-grid">
      <div class="card chart-card">
        <div class="chart-header">
          <div class="chart-title">Belastning og temperatur</div>
          <div class="chart-legend">
            <span><span class="legend-swatch" style="background: var(--nf-primary)"></span>CPU %</span>
            <span><span class="legend-swatch" style="background: var(--nf-warning)"></span>CPU-temp °C</span>
            <span><span class="legend-swatch" style="background: var(--nf-gray-400)"></span>Hukommelse %</span>
          </div>
        </div>
        <div class="chart-canvas-wrap" id="chart-wrap">
          <?php if (empty($d['chart']['labels'])): ?>
            <div class="chart-empty">Ingen målinger endnu for de seneste 60 minutter.</div>
          <?php else: ?>
            <canvas id="load-chart"></canvas>
          <?php endif; ?>
        </div>
      </div>

      <div class="card disk-card">
        <div class="disk-title">Diskenheder</div>
        <div class="volume-list" id="volume-list">
          <?php foreach ($d['volumes'] as $v): ?>
            <div>
              <div class="volume-head">
                <span class="volume-mount"><?= e($v['mount']) ?></span>
                <span class="volume-free"><?= e($v['free']) ?> fri af <?= e($v['size']) ?></span>
              </div>
              <div class="volume-track"><div class="volume-bar" style="--bar-pct: <?= e($v['pct']) ?>; --bar-color: <?= e($v['color']) ?>"></div></div>
              <div class="volume-note"><?= e($v['note']) ?></div>
            </div>
          <?php endforeach; ?>
          <?php if (empty($d['volumes'])): ?>
            <div class="volume-note">Ingen diskdata modtaget endnu.</div>
          <?php endif; ?>
        </div>
        <div class="disk-divider" id="disk-stats">
          <div><div class="stat-label">Netværk ind</div><div class="stat-value"><?= e($sel['netIn']) ?></div></div>
          <div><div class="stat-label">Netværk ud</div><div class="stat-value"><?= e($sel['netOut']) ?></div></div>
          <div><div class="stat-label">Disk I/O</div><div class="stat-value"><?= e($sel['diskIo']) ?></div></div>
          <div><div class="stat-label">Blæser</div><div class="stat-value"><?= e($sel['fan']) ?></div></div>
        </div>
      </div>
    </div>

    <div class="card process-card">
      <div class="process-header">
        <div class="process-title">Processer</div>
        <div class="sort-chips" id="sort-chips">
          <button type="button" class="sort-chip<?= $d['sortBy'] === 'cpu' ? ' active' : '' ?>" data-sort="cpu">CPU</button>
          <button type="button" class="sort-chip<?= $d['sortBy'] === 'mem' ? ' active' : '' ?>" data-sort="mem">Hukommelse</button>
          <button type="button" class="sort-chip<?= $d['sortBy'] === 'disk' ? ' active' : '' ?>" data-sort="disk">Disk</button>
        </div>
      </div>
      <table class="process-table">
        <thead>
          <tr>
            <th>Proces</th><th>Bruger</th><th>PID</th><th>CPU</th><th>Hukommelse</th><th>Disk</th><th>Status</th>
          </tr>
        </thead>
        <tbody id="process-rows">
          <?php foreach ($d['processes'] as $p): ?>
            <tr class="<?= $p['rowEven'] ? 'row-even' : '' ?>">
              <td class="proc-name"><?= e($p['name']) ?></td>
              <td class="proc-user"><?= e($p['user']) ?></td>
              <td class="proc-num"><?= (int) $p['pid'] ?></td>
              <td class="proc-num proc-cpu" style="color: <?= e($p['cpuColor']) ?>"><?= e($p['cpu']) ?></td>
              <td class="proc-num"><?= e($p['mem']) ?></td>
              <td class="proc-num"><?= e($p['disk']) ?></td>
              <td class="proc-status"><span class="state-pill" style="color: <?= e($p['stateFg']) ?>; background: <?= e($p['stateBg']) ?>"><?= e($p['state']) ?></span></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($d['processes'])): ?>
            <tr><td colspan="7" class="empty-state">Ingen procesdata modtaget endnu.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      <div class="process-footer" id="process-footer"><?= e($d['procFooter']) ?></div>
    </div>
    <?php
    return ob_get_clean();
}

function render_alert(array $d): string
{
    if (!$d['hasAlert']) {
        return '';
    }
    ob_start();
    $isCrit = $d['sel']['statusLabel'] === 'Kritisk' || !$d['online'];
    ?>
    <div class="alert-banner<?= $isCrit ? ' crit' : '' ?>">
      <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
      <div class="alert-body-wrap">
        <div class="alert-title"><?= e($d['alertTitle']) ?></div>
        <div class="alert-body"><?= e($d['alertBody']) ?></div>
      </div>
      <a href="#" class="alert-link">Se hændelseslog</a>
    </div>
    <?php
    return ob_get_clean();
}
