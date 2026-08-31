(function () {
  'use strict';

  const POLL_MS = 2200;
  const state = {
    selectedHost: document.querySelector('.server-row.selected')?.dataset.host || null,
    sortBy: document.querySelector('.sort-chip.active')?.dataset.sort || 'cpu',
    live: true,
    timer: null,
    chart: null,
  };

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[c]);
  }

  async function getJSON(url) {
    const res = await fetch(url, { credentials: 'same-origin' });
    if (res.status === 401) {
      window.location.href = '/login.php';
      throw new Error('unauthenticated');
    }
    if (!res.ok) {
      throw new Error('request failed: ' + res.status);
    }
    return res.json();
  }

  // ── Sidebar ──────────────────────────────────────────────────────────────
  function renderSidebar(fleet) {
    $('#fleet-count').textContent = fleet.fleetCount;

    $('#tally-grid').innerHTML = fleet.tallies.map((t) => `
      <div class="tally-cell">
        <div class="tally-count" style="color: ${esc(t.color)}">${t.n}</div>
        <div class="tally-label">${esc(t.label)}</div>
      </div>`).join('');

    if (!fleet.servers.length) {
      $('#server-list').innerHTML = '<div class="empty-state">Ingen servere er registreret endnu.</div>';
      return;
    }

    $('#server-list').innerHTML = fleet.servers.map((s) => `
      <button type="button" class="server-row${s.selected ? ' selected' : ''}" data-host="${esc(s.hostname)}">
        <span class="server-dot" style="background: ${esc(s.dot)}"></span>
        <span class="server-id">
          <span class="server-name">${esc(s.hostname)}</span>
          <span class="server-sub">${esc(s.ip)}</span>
        </span>
        <span class="server-right">
          <span class="server-cpu" style="color: ${esc(s.dot)}">${esc(s.cpuLabel)}</span>
          <span class="server-temp">${esc(s.tempLabel)}</span>
        </span>
      </button>`).join('');
  }

  // ── Main column ──────────────────────────────────────────────────────────
  function renderAlert(d) {
    if (!d.hasAlert) return '';
    const isCrit = d.sel.statusLabel === 'Kritisk' || !d.online;
    return `
      <div class="alert-banner${isCrit ? ' crit' : ''}">
        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
        <div class="alert-body-wrap">
          <div class="alert-title">${esc(d.alertTitle)}</div>
          <div class="alert-body">${esc(d.alertBody)}</div>
        </div>
        <a href="#" class="alert-link">Se hændelseslog</a>
      </div>`;
  }

  function renderHostDetail(d) {
    const sel = d.sel;
    const osIcon = sel.osFamily === 'windows' ? 'fa-brands fa-windows' : 'fa-brands fa-linux';

    const gauges = d.gauges.map((g) => `
      <div class="gauge-card">
        <div class="gauge-ring" style="--ring-color: ${esc(g.ring)}; --ring-pct: ${g.pct}">
          <div class="gauge-ring-inner">${esc(g.value)}</div>
        </div>
        <div class="gauge-text">
          <div class="gauge-label">${esc(g.label)}</div>
          <div class="gauge-value">${esc(g.big)}</div>
          <div class="gauge-hint">${esc(g.hint)}</div>
        </div>
      </div>`).join('');

    const volumes = d.volumes.length ? d.volumes.map((v) => `
      <div>
        <div class="volume-head">
          <span class="volume-mount">${esc(v.mount)}</span>
          <span class="volume-free">${esc(v.free)} fri af ${esc(v.size)}</span>
        </div>
        <div class="volume-track"><div class="volume-bar" style="--bar-pct: ${esc(v.pct)}; --bar-color: ${esc(v.color)}"></div></div>
        <div class="volume-note">${esc(v.note)}</div>
      </div>`).join('') : '<div class="volume-note">Ingen diskdata modtaget endnu.</div>';

    const processRows = d.processes.length ? d.processes.map((p) => `
      <tr class="${p.rowEven ? 'row-even' : ''}">
        <td class="proc-name">${esc(p.name)}</td>
        <td class="proc-user">${esc(p.user)}</td>
        <td class="proc-num">${esc(p.pid)}</td>
        <td class="proc-num proc-cpu" style="color: ${esc(p.cpuColor)}">${esc(p.cpu)}</td>
        <td class="proc-num">${esc(p.mem)}</td>
        <td class="proc-num">${esc(p.disk)}</td>
        <td class="proc-status"><span class="state-pill" style="color: ${esc(p.stateFg)}; background: ${esc(p.stateBg)}">${esc(p.state)}</span></td>
      </tr>`).join('') : '<tr><td colspan="7" class="empty-state">Ingen procesdata modtaget endnu.</td></tr>';

    const chartWrap = d.chart.labels.length
      ? '<canvas id="load-chart"></canvas>'
      : '<div class="chart-empty">Ingen målinger endnu for de seneste 60 minutter.</div>';

    return `
      <div class="main-head">
        <div>
          <div class="host-title-row">
            <h1 class="host-title" id="host-name">${esc(sel.name)}</h1>
            <span class="status-pill" style="color: ${esc(sel.badgeFg)}; background: ${esc(sel.badgeBg)}">${esc(sel.statusLabel)}</span>
          </div>
          <div class="meta-line">
            <span><i class="fa-solid fa-network-wired" aria-hidden="true"></i>${esc(sel.ip)}</span>
            <span><i class="fa-solid fa-microchip" aria-hidden="true"></i>${esc(sel.hw)}</span>
            <span><i class="${osIcon}" aria-hidden="true"></i>${esc(sel.os)}</span>
            <span><i class="fa-solid fa-clock" aria-hidden="true"></i>Driftstid ${esc(sel.uptime)}</span>
          </div>
        </div>
        <div class="main-actions">
          <button type="button" class="btn" disabled title="Ikke tilgængelig endnu">Eksportér rapport</button>
          <button type="button" class="btn-primary-soft" disabled title="Ikke tilgængelig endnu">Konfigurér grænser</button>
        </div>
      </div>

      <div id="alert-slot">${renderAlert(d)}</div>

      <div class="gauges-grid">${gauges}</div>

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
          <div class="chart-canvas-wrap" id="chart-wrap">${chartWrap}</div>
        </div>

        <div class="card disk-card">
          <div class="disk-title">Diskenheder</div>
          <div class="volume-list">${volumes}</div>
          <div class="disk-divider">
            <div><div class="stat-label">Netværk ind</div><div class="stat-value">${esc(sel.netIn)}</div></div>
            <div><div class="stat-label">Netværk ud</div><div class="stat-value">${esc(sel.netOut)}</div></div>
            <div><div class="stat-label">Disk I/O</div><div class="stat-value">${esc(sel.diskIo)}</div></div>
            <div><div class="stat-label">Blæser</div><div class="stat-value">${esc(sel.fan)}</div></div>
          </div>
        </div>
      </div>

      <div class="card process-card">
        <div class="process-header">
          <div class="process-title">Processer</div>
          <div class="sort-chips" id="sort-chips">
            <button type="button" class="sort-chip${d.sortBy === 'cpu' ? ' active' : ''}" data-sort="cpu">CPU</button>
            <button type="button" class="sort-chip${d.sortBy === 'mem' ? ' active' : ''}" data-sort="mem">Hukommelse</button>
            <button type="button" class="sort-chip${d.sortBy === 'disk' ? ' active' : ''}" data-sort="disk">Disk</button>
          </div>
        </div>
        <table class="process-table">
          <thead><tr><th>Proces</th><th>Bruger</th><th>PID</th><th>CPU</th><th>Hukommelse</th><th>Disk</th><th>Status</th></tr></thead>
          <tbody id="process-rows">${processRows}</tbody>
        </table>
        <div class="process-footer">${esc(d.procFooter)}</div>
      </div>`;
  }

  function renderChart(chart) {
    const canvas = $('#load-chart');
    if (!canvas || typeof Chart === 'undefined') return;

    if (state.chart) {
      state.chart.destroy();
      state.chart = null;
    }

    state.chart = new Chart(canvas.getContext('2d'), {
      type: 'line',
      data: {
        labels: chart.labels,
        datasets: [
          {
            label: 'CPU %', data: chart.cpu, borderColor: 'oklch(0.557 0.224 264.94)',
            backgroundColor: 'oklch(0.557 0.224 264.94 / 9%)', fill: true,
            borderWidth: 2, pointRadius: 0, tension: 0.25,
          },
          {
            label: 'CPU-temp °C', data: chart.temp, borderColor: 'oklch(0.769 0.153 70.08)',
            borderWidth: 1.75, pointRadius: 0, tension: 0.25, fill: false,
          },
          {
            label: 'Hukommelse %', data: chart.mem, borderColor: '#9ca3af',
            borderWidth: 1.5, pointRadius: 0, tension: 0.25, fill: false, borderDash: [4, 3],
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        interaction: { intersect: false, mode: 'index' },
        scales: {
          y: { min: 0, max: 100, grid: { color: '#f1f2f4' }, ticks: { stepSize: 50, color: '#9ca3af', font: { size: 10 } } },
          x: { grid: { display: false }, ticks: { maxTicksLimit: 5, color: '#9ca3af', font: { size: 10 } } },
        },
        plugins: { legend: { display: false } },
      },
    });
  }

  // ── Data flow ────────────────────────────────────────────────────────────
  async function refreshFleet() {
    const { data, clock } = await getJSON('/api/fleet.php?selected=' + encodeURIComponent(state.selectedHost || ''));
    renderSidebar(data);
    $('#clock').textContent = clock;
  }

  async function refreshHost() {
    if (!state.selectedHost) return;
    const { data } = await getJSON(
      '/api/host.php?host=' + encodeURIComponent(state.selectedHost) + '&sort=' + encodeURIComponent(state.sortBy)
    );
    $('#main-content').innerHTML = renderHostDetail(data);
    renderChart(data.chart);
  }

  async function tick() {
    try {
      await Promise.all([refreshFleet(), refreshHost()]);
    } catch (e) {
      console.error('Opdatering fejlede', e);
    }
  }

  function schedule() {
    clearInterval(state.timer);
    if (state.live) {
      state.timer = setInterval(tick, POLL_MS);
    }
  }

  function selectHost(hostname) {
    if (hostname === state.selectedHost) return;
    state.selectedHost = hostname;
    const url = new URL(window.location.href);
    url.searchParams.set('host', hostname);
    window.history.pushState({}, '', url);
    tick();
  }

  // ── Event wiring (delegated — content is re-rendered on every poll) ──────
  document.addEventListener('click', (e) => {
    const row = e.target.closest('.server-row');
    if (row) {
      selectHost(row.dataset.host);
      return;
    }
    const chip = e.target.closest('.sort-chip');
    if (chip) {
      state.sortBy = chip.dataset.sort;
      refreshHost();
    }
  });

  $('#live-toggle').addEventListener('click', () => {
    state.live = !state.live;
    $('#live-dot').classList.toggle('paused', !state.live);
    $('#live-label').textContent = state.live ? 'Live · opdaterer hvert 2. sekund' : 'Opdatering sat på pause';
    $('#live-button-label').textContent = state.live ? 'Pause' : 'Genoptag';
    schedule();
  });

  // The server-rendered canvas has no Chart.js instance attached yet; the
  // first tick() below fetches fresh data and (re)renders the chart from it.
  tick();
  schedule();
})();
