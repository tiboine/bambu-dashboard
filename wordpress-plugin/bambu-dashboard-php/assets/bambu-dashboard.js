/**
 * Bambu Lab 3D Print Dashboard – WordPress PHP Plugin v1.0.8
 */
(function () {
  'use strict';

  const cfg  = (typeof bambuConfig !== 'undefined') ? bambuConfig : {};
  const REST = (cfg.restUrl || '/wp-json/').replace(/\/$/, '') + '/bambu/v1';
  const finishedAt = {};

  // ── Tema ────────────────────────────────────────────────────────────────

  function isDark(wrap) {
    return wrap.classList.contains('bambu-dark') ||
      (!wrap.classList.contains('bambu-light') && window.matchMedia('(prefers-color-scheme: dark)').matches);
  }

  function applyTheme(wrap, dark) {
    if (dark) {
      wrap.classList.add('bambu-dark');
      wrap.classList.remove('bambu-light');
    } else {
      wrap.classList.add('bambu-light');
      wrap.classList.remove('bambu-dark');
    }
    try { localStorage.setItem('bambu_theme', dark ? 'dark' : 'light'); } catch (e) {}
    // Oppdater ikon på toggle-knappen
    const btn = wrap.closest('.bambu-fs-page, body')
                    ?.querySelector?.('#bambu-theme-toggle');
    if (btn) btn.innerHTML = dark ? iconSun() : iconMoon();
  }

  function iconMoon() {
    return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1111.21 3a7 7 0 009.79 9.79z"/></svg>';
  }

  function iconSun() {
    return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>';
  }

  // Sett initialt tema fra localStorage
  function initTheme(wrap) {
    let saved;
    try { saved = localStorage.getItem('bambu_theme'); } catch (e) {}
    if (saved === 'dark') applyTheme(wrap, true);
    else if (saved === 'light') applyTheme(wrap, false);
    // Ellers: auto (CSS media query)
  }

  // ── Helpers ─────────────────────────────────────────────────────────────

  function formatTime(s) {
    if (!s || s <= 0) return null;
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
    if (h > 0) return h + 't ' + m + 'm';
    if (m > 0) return m + ' min';
    return 'Under 1 min';
  }

  function estimatedFinish(s) {
    if (!s || s <= 0) return null;
    const d = new Date(Date.now() + s * 1000);
    return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
  }

  function speedLabel(lvl) {
    return { 1: 'Stille', 2: 'Normal', 3: 'Sport', 4: 'Ludicrous' }[lvl] || null;
  }

  function formatWeight(g) {
    if (!g) return '0g';
    return g >= 1000 ? (g / 1000).toFixed(1) + 'kg' : Math.round(g) + 'g';
  }

  function formatMeters(cm) {
    const m = cm / 100;
    return m >= 1000 ? (m / 1000).toFixed(1) + 'km' : Math.round(m) + 'm';
  }

  function formatDuration(s) {
    if (!s) return '0t';
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
    if (h > 24) return Math.floor(h / 24) + 'd ' + (h % 24) + 't';
    if (h > 0) return h + 't ' + m + 'm';
    return m + 'm';
  }

  // ── Status-logikk ────────────────────────────────────────────────────────

  function getStatus(p) {
    const s = (p.print_status || '').toUpperCase();
    if (s === 'SUCCESS' || s === 'FINISH') {
      if (!finishedAt[p.id]) finishedAt[p.id] = Date.now();
      if (Date.now() - finishedAt[p.id] < 120000) return 'JUST_DONE';
      return 'IDLE';
    }
    if (finishedAt[p.id]) delete finishedAt[p.id];
    return s;
  }

  function statusBadge(p) {
    if (!p.online) return { t: 'Offline',  c: 'bambu-badge-offline' };
    const s = getStatus(p);
    if (s === 'RUNNING'  || s === 'PRINTING') return { t: 'Printer', c: 'bambu-badge-printing' };
    if (s === 'FAIL'     || s === 'FAILED')   return { t: 'Feil',    c: 'bambu-badge-offline' };
    if (s === 'PAUSED'   || s === 'PAUSE')    return { t: 'Pauset',  c: 'bambu-badge-printing' };
    if (s === 'JUST_DONE')                    return { t: 'Ferdig',  c: 'bambu-badge-done' };
    return { t: 'Ledig', c: 'bambu-badge-idle' };
  }

  function cardCls(p) {
    if (!p.online) return 'offline';
    const s = getStatus(p);
    if (s === 'RUNNING'  || s === 'PRINTING') return 'printing';
    if (s === 'FAIL'     || s === 'FAILED')   return 'failed';
    if (s === 'PAUSED'   || s === 'PAUSE')    return 'paused';
    if (s === 'JUST_DONE')                    return 'just-done';
    return 'online';
  }

  function sortPrinters(arr) {
    const o = { printing: 0, failed: 1, paused: 2, 'just-done': 3, online: 4, offline: 5 };
    return arr.sort((a, b) => (o[cardCls(a)] || 4) - (o[cardCls(b)] || 4));
  }

  // ── Kort-rendering ───────────────────────────────────────────────────────

  function renderCard(p) {
    const badge      = statusBadge(p);
    const cls        = cardCls(p);
    const isPrinting = cls === 'printing';
    const isFailed   = cls === 'failed';
    const isPaused   = cls === 'paused';
    const isDone     = cls === 'just-done';
    const hasJob     = isPrinting || isFailed || isPaused;
    const progress   = p.progress != null ? parseFloat(p.progress) : null;

    // Zebra-animasjonsrate basert på hastighet
    const speeds = { 1: '3s', 2: '1.5s', 3: '1.2s', 4: '0.9s' };
    const zs = speeds[p.spd_lvl] || '1.5s';

    let bg = '';
    if (hasJob && progress != null)
      bg = '<div class="bambu-progress-bg" style="width:' + progress + '%;animation-duration:' + zs + '"></div>';
    else if (isDone)
      bg = '<div class="bambu-progress-bg"></div>';

    const thumb = p.thumbnail
      ? '<img class="bambu-thumb" src="' + p.thumbnail + '" />'
      : '';

    let body = '';
    if (hasJob && p.task_name)
      body += '<div class="bambu-job-name"><span>Fil: </span>' + p.task_name + '</div>';

    if (isFailed)
      body += '<div class="bambu-idle" style="color:var(--bambu-red);font-style:normal;font-weight:600">Printing feilet!</div>';
    if (isPaused)
      body += '<div class="bambu-idle" style="color:#f39c12;font-style:normal;font-weight:600">Printing pauset</div>';

    if ((isPrinting || isPaused) && progress != null) {
      body += '<div class="bambu-pct">' + Math.round(progress) + '%</div>';
      const ts = formatTime(p.time_remaining);
      const fs = estimatedFinish(p.time_remaining);
      if (ts || fs) {
        body += '<div class="bambu-time">'
          + '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
        if (ts) body += ts + ' gjenstår';
        if (ts && fs) body += ' — ';
        if (fs) body += '<span class="bambu-time-finish">Ferdig ' + fs + '</span>';
        body += '</div>';
      }
    } else if (isFailed && progress != null) {
      body += '<div class="bambu-pct" style="color:var(--bambu-red)">Stoppet ' + Math.round(progress) + '%</div>';
    } else if (isDone) {
      body += '<div class="bambu-idle" style="color:var(--bambu-green);font-style:normal;font-weight:700">Print fullført!</div>';
    } else if (!p.online) {
      body += '<div class="bambu-idle">Printeren er ikke tilgjengelig.</div>';
    } else {
      body += '<div class="bambu-idle">Ingen aktiv jobb.</div>';
    }

    // ── Info-rad (temp, lag, hastighet, AMS) ──
    let info = [];

    if (p.nozzle_temp != null)
      info.push('<span class="bambu-info-item">'
        + '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 14.76V3.5a2.5 2.5 0 00-5 0v11.26a4.5 4.5 0 105 0z"/></svg>'
        + Math.round(p.nozzle_temp) + '°C</span>');

    if (p.bed_temp != null)
      info.push('<span class="bambu-info-item">'
        + '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/></svg>'
        + 'Plate ' + Math.round(p.bed_temp) + '°C</span>');

    if (p.layer != null && p.total_layers > 0)
      info.push('<span class="bambu-info-item">'
        + '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>'
        + 'Lag ' + p.layer + '/' + p.total_layers + '</span>');

    const sl = speedLabel(p.spd_lvl);
    if (sl)
      info.push('<span class="bambu-info-item">'
        + '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>'
        + sl + '</span>');

    if (p.ams_colors && p.ams_colors.length > 0) {
      const dots = p.ams_colors.map(c =>
        '<span class="bambu-ams-dot" style="background:' + c.color + '" title="' + c.type + ' ' + c.color + '"></span>'
      ).join(' ');
      info.push('<span class="bambu-info-item">' + dots + '</span>');
    }

    if (info.length > 0 && (hasJob || p.online))
      body += '<div class="bambu-info">' + info.join('') + '</div>';

    return '<div class="bambu-card ' + cls + '">' + bg
      + '<div class="bambu-card-header">'
      + '<div><div class="bambu-card-name">' + p.name + '</div>'
      + '<div class="bambu-card-model">' + p.model + '</div></div>'
      + '<span class="bambu-badge ' + badge.c + '">' + badge.t + '</span>'
      + '</div>'
      + '<div class="bambu-card-body">' + thumb + body + '</div>'
      + '</div>';
  }

  // ── Dashboard ────────────────────────────────────────────────────────────

  function initDashboard(el, linkUrl, linkLabel) {
    const cols = el.dataset.cols || '5';
    const relink = () => injectLinkButton(el, linkUrl, linkLabel);
    initTheme(el);

    async function refresh() {
      try {
        const res  = await fetch(REST + '/status');
        const data = await res.json();

        if (data.needs_verify_code) {
          el.innerHTML = '<div class="bambu-loading">Venter på verifisering – sjekk WordPress-innstillinger.</div>';
          relink(); return;
        }

        const sorted   = sortPrinters(data.printers || []);
        const total    = sorted.length;
        const online   = sorted.filter(p => p.online).length;
        const printing = sorted.filter(p => { const s = getStatus(p); return s === 'RUNNING' || s === 'PRINTING'; }).length;
        const failed   = sorted.filter(p => { const s = getStatus(p); return s === 'FAIL' || s === 'FAILED'; }).length;

        let html = '<div class="bambu-summary">'
          + '<div class="bambu-stat"><span class="bambu-stat-label">Totalt</span><span class="bambu-stat-value">' + total + '</span></div>'
          + '<div class="bambu-stat"><span class="bambu-stat-label">Online</span><span class="bambu-stat-value">' + online + '</span></div>'
          + '<div class="bambu-stat"><span class="bambu-stat-label">Printer</span><span class="bambu-stat-value orange">' + printing + '</span></div>';
        if (failed > 0)
          html += '<div class="bambu-stat"><span class="bambu-stat-label">Feil</span><span class="bambu-stat-value red">' + failed + '</span></div>';
        html += '</div>';

        html += '<div class="bambu-grid cols-' + cols + '">' + sorted.map(renderCard).join('') + '</div>';
        html += '<div class="bambu-today" id="bambu-today"></div>';
        el.innerHTML = html;
        relink();

        refreshToday();
      } catch (e) {
        el.innerHTML = '<div class="bambu-loading">Kunne ikke koble til – prøver igjen...</div>';
        relink();
      }
    }

    async function refreshToday() {
      try {
        const res = await fetch(REST + '/today');
        const d   = await res.json();
        const bar = document.getElementById('bambu-today');
        if (!bar) return;
        bar.innerHTML =
          '<div class="bambu-today-item"><span class="bambu-today-label">I dag</span></div>'
          + '<div class="bambu-today-item"><span class="bambu-today-value">' + d.prints + '</span><span class="bambu-today-label">prints</span></div>'
          + '<div class="bambu-today-item"><span class="bambu-today-value">' + Math.round(d.weight_g) + 'g</span><span class="bambu-today-label">filament</span></div>'
          + '<div class="bambu-today-item"><span class="bambu-today-value">' + formatMeters(d.length_cm ?? 0) + '</span><span class="bambu-today-label">lengde</span></div>';
      } catch (e) {}
    }

    refresh();
    setInterval(refresh, 30000);
    setInterval(refreshToday, 60000);
  }

  // ── Statistikk ───────────────────────────────────────────────────────────

  function initStats(el, linkUrl, linkLabel) {
    const relink = () => injectLinkButton(el, linkUrl, linkLabel);
    initTheme(el);

    let allDevs = [];
    let sortKey = 'total_prints';
    let sortDir = -1; // -1 = desc, 1 = asc

    const sortDefs = [
      { key: 'name',           label: 'Navn',    dir: 1  },
      { key: 'total_prints',   label: 'Prints',  dir: -1 },
      { key: 'total_time_s',   label: 'Tid',     dir: -1 },
      { key: 'total_weight_g', label: 'Filament',dir: -1 },
      { key: '_meters',        label: 'Lengde',  dir: -1 },
    ];

    function getSortValue(d, key) {
      if (key === '_meters') return d.total_length_cm ?? 0;
      return d[key] ?? 0;
    }

    function renderSortBar() {
      let html = '';
      sortDefs.forEach(s => {
        const active = sortKey === s.key;
        const arrow  = active ? (sortDir === -1 ? ' ↓' : ' ↑') : '';
        html += '<button class="bambu-sort-btn' + (active ? ' active' : '') + '" data-key="' + s.key + '">'
              + s.label + arrow + '</button>';
      });
      return html;
    }

    function renderGrid() {
      const sorted = [...allDevs].sort((a, b) => {
        // Navn: naturlig sortering (Mini 2 < Mini 10 < Mini 11)
        if (sortKey === 'name') {
          return sortDir * a.name.localeCompare(b.name, 'no', { numeric: true, sensitivity: 'base' });
        }
        const av = getSortValue(a, sortKey);
        const bv = getSortValue(b, sortKey);
        if (av < bv) return sortDir;
        if (av > bv) return -sortDir;
        return 0;
      });

      let html = '<div class="bambu-stats-grid">';
      sorted.forEach(d => {
        const r = d.total_prints > 0 ? Math.round(d.successful / d.total_prints * 100) : 0;
        html += '<div class="bambu-stats-card">'
          + '<div class="bambu-stats-card-header"><div class="bambu-stats-card-name">' + d.name + '</div>'
          + '<div class="bambu-stats-card-model">' + d.model + '</div></div>'
          + '<div class="bambu-stats-card-body"><div class="bambu-stats-grid-inner">'
          + statsItem('Prints',   String(d.total_prints), 'color:var(--bambu-orange)')
          + statsItem('Printtid', formatDuration(d.total_time_s))
          + statsItem('Filament', formatWeight(d.total_weight_g))
          + statsItem('Lengde',   formatMeters(d.total_length_cm ?? 0))
          + '</div>'
          + '<div class="bambu-rate-bar"><div class="bambu-rate-fill" style="width:' + r + '%"></div></div>'
          + '<div class="bambu-rate-nums"><span>' + d.successful + ' vellykket</span><span>' + r + '%</span><span>' + d.failed + ' feilet</span></div>'
          + '</div></div>';
      });
      html += '</div>';
      return html;
    }

    function render() {
      const grid = el.querySelector('.bambu-stats-grid-wrap');
      if (grid) grid.innerHTML = renderGrid();
      // Oppdater sort-knapper
      el.querySelectorAll('.bambu-sort-btn').forEach(btn => {
        const active = btn.dataset.key === sortKey;
        btn.classList.toggle('active', active);
        if (active) {
          btn.textContent = sortDefs.find(s => s.key === sortKey).label + (sortDir === -1 ? ' ↓' : ' ↑');
        } else {
          btn.textContent = sortDefs.find(s => s.key === btn.dataset.key).label;
        }
      });
    }

    async function load() {
      try {
        const res  = await fetch(REST + '/stats');
        const data = await res.json();
        if (data.error) { el.innerHTML = '<div class="bambu-loading">Feil: ' + data.error + '</div>'; relink(); return; }

        allDevs = data.devices || [];
        let tp = 0, ts = 0, tf = 0, tt = 0, tw = 0;
        allDevs.forEach(d => { tp += d.total_prints; ts += d.successful; tf += d.failed; tt += d.total_time_s; tw += d.total_weight_g; });
        const rate = tp > 0 ? Math.round(ts / tp * 100) : 0;

        // Vis advarsel hvis historikken ikke er komplett ennå
        const apiTotal = data.api_total || 0;
        const isComplete = data.data_complete === true;
        let warning = '';
        if (!isComplete) {
          warning = '<div class="bambu-stats-warning">'
            + '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
            + ' Historikken lastes inn – tallene er ikke komplette ennå. Last inn siden på nytt om litt.'
            + (apiTotal > 0 ? ' (Bambu rapporterer ' + apiTotal.toLocaleString('no') + ' tasks totalt)' : '')
            + '</div>';
        }

        let html = warning + '<div class="bambu-stats-summary">'
          + stat('Prints', tp, 'orange')
          + stat('Vellykket', ts, 'color:var(--bambu-green)')
          + stat('Feilet', tf, 'color:var(--bambu-red)')
          + stat('Suksessrate', rate + '%', 'color:var(--bambu-green)')
          + stat('Printtid', formatDuration(tt))
          + stat('Filament', formatWeight(tw))
          + '</div>';

        const exportUrl = REST + '/export';
        html += '<div class="bambu-sort-bar">' + renderSortBar(true) +
          '<a class="bambu-sort-btn bambu-export-btn" href="' + exportUrl + '" download>' +
          '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>' +
          ' Last ned CSV</a></div>';
        html += '<div class="bambu-stats-grid-wrap">' + renderGrid() + '</div>';
        el.innerHTML = html;
        relink();

        // Knytt klikk-hendelser til sort-knapper
        el.querySelectorAll('.bambu-sort-btn').forEach(btn => {
          btn.addEventListener('click', () => {
            const key = btn.dataset.key;
            if (sortKey === key) {
              sortDir *= -1;
            } else {
              sortKey = key;
              sortDir = sortDefs.find(s => s.key === key).dir;
            }
            render();
          });
        });

      } catch (e) {
        el.innerHTML = '<div class="bambu-loading">Kunne ikke laste statistikk.</div>';
        relink();
      }
    }
    load();
  }

  function stat(label, val, style) {
    const s = style ? (style === 'orange' ? ' orange' : '" style="' + style) : '';
    return '<div class="bambu-stat"><span class="bambu-stat-label">' + label + '</span>'
      + '<span class="bambu-stat-value' + (style === 'orange' ? ' orange' : '') + '"'
      + (style && style !== 'orange' ? ' style="' + style + '"' : '') + '>' + val + '</span></div>';
  }

  function statsItem(label, val, style) {
    return '<div><div class="bambu-stats-item-label">' + label + '</div>'
      + '<div class="bambu-stats-item-value"' + (style ? ' style="' + style + '"' : '') + '>' + val + '</div></div>';
  }

  function tp_str(n) { return String(n); }

  // ── Init ─────────────────────────────────────────────────────────────────

  function injectLinkButton(wrap, url, label) {
    if (!url || !label) return;
    // Fjern gammel knapp hvis den finnes (unngå duplikater etter innerHTML-oppdateringer)
    const old = wrap.querySelector('.bambu-link-btn');
    if (old) old.remove();
    const btn = document.createElement('a');
    btn.href        = url;
    btn.target      = '_blank';
    btn.rel         = 'noopener';
    btn.className   = 'bambu-link-btn';
    btn.textContent = label;
    wrap.appendChild(btn);
  }

  document.addEventListener('DOMContentLoaded', function () {
    // Tema-toggle (brukes i fullskjerm-malen)
    const toggleBtn = document.getElementById('bambu-theme-toggle');
    if (toggleBtn) {
      toggleBtn.addEventListener('click', function () {
        const wrap = document.getElementById('bambu-dashboard') || document.getElementById('bambu-stats');
        if (wrap) applyTheme(wrap, !isDark(wrap));
      });
    }

    const dash  = document.getElementById('bambu-dashboard');
    if (dash)  initDashboard(dash, cfg.dashLinkUrl, cfg.dashLinkLabel);

    const stats = document.getElementById('bambu-stats');
    if (stats) initStats(stats, cfg.statsLinkUrl, cfg.statsLinkLabel);
  });

})();
