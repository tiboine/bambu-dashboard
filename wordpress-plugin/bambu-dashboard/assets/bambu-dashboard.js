/**
 * Bambu Lab 3D Print Dashboard - WordPress Plugin
 */
(function() {
  'use strict';

  const API = (typeof bambuConfig !== 'undefined' && bambuConfig.apiUrl) || 'http://localhost:5000';
  const finishedAt = {};
  const alertedPrinters = new Set();

  // ── Helpers ──
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
    return String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0');
  }

  function speedLabel(lvl) {
    return {1:'Stille',2:'Normal',3:'Sport',4:'Ludicrous'}[lvl] || null;
  }

  function formatWeight(g) {
    if (!g) return '0g';
    return g >= 1000 ? (g/1000).toFixed(1)+'kg' : Math.round(g)+'g';
  }

  function formatMeters(g) {
    const m = (g/1000)*330;
    return m >= 1000 ? (m/1000).toFixed(1)+'km' : Math.round(m)+'m';
  }

  function formatDuration(s) {
    if (!s) return '0t';
    const h = Math.floor(s/3600), m = Math.floor((s%3600)/60);
    if (h > 24) return Math.floor(h/24)+'d '+(h%24)+'t';
    if (h > 0) return h+'t '+m+'m';
    return m+'m';
  }

  function getEffectiveStatus(p) {
    const s = (p.print_status||'').toUpperCase();
    if (s === 'SUCCESS' || s === 'FINISH') {
      if (!finishedAt[p.id]) finishedAt[p.id] = Date.now();
      if (Date.now() - finishedAt[p.id] < 120000) return 'JUST_DONE';
      return 'IDLE';
    }
    if (finishedAt[p.id]) delete finishedAt[p.id];
    return s;
  }

  function statusBadge(p) {
    if (!p.online) return {t:'Offline',c:'bambu-badge-offline'};
    const s = getEffectiveStatus(p);
    if (s==='RUNNING'||s==='PRINTING') return {t:'Printer',c:'bambu-badge-printing'};
    if (s==='FAIL'||s==='FAILED') return {t:'Feil',c:'bambu-badge-offline'};
    if (s==='PAUSED'||s==='PAUSE') return {t:'Pauset',c:'bambu-badge-printing'};
    if (s==='JUST_DONE') return {t:'Ferdig',c:'bambu-badge-done'};
    return {t:'Ledig',c:'bambu-badge-idle'};
  }

  function cardCls(p) {
    if (!p.online) return 'offline';
    const s = getEffectiveStatus(p);
    if (s==='RUNNING'||s==='PRINTING') return 'printing';
    if (s==='FAIL'||s==='FAILED') return 'failed';
    if (s==='PAUSED'||s==='PAUSE') return 'paused';
    if (s==='JUST_DONE') return 'just-done';
    return 'online';
  }

  function sortPrinters(arr) {
    const o = {printing:0,failed:1,paused:2,'just-done':3,online:4,offline:5};
    return arr.sort((a,b) => (o[cardCls(a)]||4) - (o[cardCls(b)]||4));
  }

  function renderCard(p) {
    const badge = statusBadge(p);
    const cls = cardCls(p);
    const isPrinting = cls==='printing', isFailed = cls==='failed',
          isPaused = cls==='paused', isDone = cls==='just-done';
    const hasJob = isPrinting||isFailed||isPaused;
    const progress = p.progress!=null ? parseFloat(p.progress) : null;
    const zebraSpeeds = {1:'3s',2:'1.5s',3:'1.2s',4:'0.9s'};
    const zs = zebraSpeeds[p.spd_lvl]||'1.5s';

    let bg = '';
    if (hasJob && progress!=null) bg = '<div class="bambu-progress-bg" style="width:'+progress+'%;animation-duration:'+zs+'"></div>';
    else if (isDone) bg = '<div class="bambu-progress-bg"></div>';

    let thumb = p.thumbnail ? '<img class="bambu-thumb" src="'+p.thumbnail+'" />' : '';

    let body = '';
    if (hasJob && p.task_name) body += '<div class="bambu-job-name">'+p.task_name+'</div>';
    if (isFailed) body += '<div class="bambu-idle" style="color:var(--bambu-red);font-style:normal;font-weight:600">Printing feilet!</div>';
    if (isPaused) body += '<div class="bambu-idle" style="color:#f39c12;font-style:normal;font-weight:600">Printing pauset</div>';

    if ((isPrinting||isPaused) && progress!=null) {
      body += '<div class="bambu-pct">'+Math.round(progress)+'%</div>';
      const ts = formatTime(p.time_remaining), fs = estimatedFinish(p.time_remaining);
      if (ts||fs) {
        body += '<div class="bambu-time"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
        if (ts) body += ts + ' gjenstår';
        if (ts && fs) body += ' — ';
        if (fs) body += '<span class="bambu-time-finish">Ferdig '+fs+'</span>';
        body += '</div>';
      }
    } else if (isFailed && progress!=null) {
      body += '<div class="bambu-pct" style="color:var(--bambu-red)">Stoppet '+Math.round(progress)+'%</div>';
    } else if (isDone) {
      body += '<div class="bambu-idle" style="color:var(--bambu-green);font-style:normal;font-weight:700">Print fullfort!</div>';
    } else if (!p.online) {
      body += '<div class="bambu-idle">Printeren er ikke tilgjengelig.</div>';
    } else if (!hasJob) {
      body += '<div class="bambu-idle">Ingen aktiv jobb.</div>';
    }

    // Info row
    let info = [];
    if (p.nozzle_temp!=null) info.push(Math.round(p.nozzle_temp)+'°C');
    if (p.bed_temp!=null) info.push('Plate '+Math.round(p.bed_temp)+'°C');
    if (p.layer!=null&&p.total_layers>0) info.push('Lag '+p.layer+'/'+p.total_layers);
    const sl = speedLabel(p.spd_lvl);
    if (sl) info.push(sl);
    if (info.length>0 && (hasJob||p.online)) body += '<div class="bambu-info">'+info.join(' · ')+'</div>';

    return '<div class="bambu-card '+cls+'">'+bg+
      '<div class="bambu-card-header"><div><div class="bambu-card-name">'+p.name+'</div><div class="bambu-card-model">'+p.model+'</div></div>'+
      '<span class="bambu-badge '+badge.c+'">'+badge.t+'</span></div>'+
      '<div class="bambu-card-body">'+thumb+body+'</div></div>';
  }

  // ── Dashboard ──
  function initDashboard(el) {
    const cols = el.dataset.cols || '5';

    async function refresh() {
      try {
        const res = await fetch(API + '/api/status');
        const data = await res.json();
        if (data.needs_verify_code) {
          el.innerHTML = '<div class="bambu-loading">Venter pa verifisering i backend...</div>';
          return;
        }

        const sorted = sortPrinters(data.printers || []);
        const total = sorted.length, online = sorted.filter(p=>p.online).length;
        const printing = sorted.filter(p=>{const s=getEffectiveStatus(p);return s==='RUNNING'||s==='PRINTING';}).length;

        let html = '<div class="bambu-summary">';
        html += '<div class="bambu-stat"><span class="bambu-stat-label">Totalt</span><span class="bambu-stat-value">'+total+'</span></div>';
        html += '<div class="bambu-stat"><span class="bambu-stat-label">Online</span><span class="bambu-stat-value">'+online+'</span></div>';
        html += '<div class="bambu-stat"><span class="bambu-stat-label">Printer</span><span class="bambu-stat-value orange">'+printing+'</span></div>';
        html += '</div>';
        html += '<div class="bambu-grid cols-'+cols+'">'+sorted.map(renderCard).join('')+'</div>';
        html += '<div class="bambu-today" id="bambu-today"></div>';
        el.innerHTML = html;

        // Load today stats
        refreshToday();
      } catch (e) {
        el.innerHTML = '<div class="bambu-loading">Kunne ikke koble til backend ('+API+')</div>';
      }
    }

    async function refreshToday() {
      try {
        const res = await fetch(API + '/api/today');
        const d = await res.json();
        const bar = document.getElementById('bambu-today');
        if (!bar) return;
        const wkg = (d.weight_g/1000).toFixed(1);
        const len = formatMeters(d.weight_g);
        bar.innerHTML = '<span class="bambu-today-label">I dag</span>'+
          '<span><span class="bambu-today-value">'+d.prints+'</span> <span class="bambu-today-label">prints</span></span>'+
          '<span><span class="bambu-today-value">'+wkg+'kg</span> <span class="bambu-today-label">filament</span></span>'+
          '<span><span class="bambu-today-value">'+len+'</span> <span class="bambu-today-label">lengde</span></span>';
      } catch(e){}
    }

    refresh();
    setInterval(refresh, 5000);
    setInterval(refreshToday, 60000);
  }

  // ── Stats ──
  function initStats(el) {
    async function load() {
      try {
        const res = await fetch(API + '/api/stats');
        const data = await res.json();
        if (data.error) { el.innerHTML = '<div class="bambu-loading">Feil: '+data.error+'</div>'; return; }

        const devs = data.devices || [];
        let tp=0,ts=0,tf=0,tt=0,tw=0;
        devs.forEach(d=>{tp+=d.total_prints;ts+=d.successful;tf+=d.failed;tt+=d.total_time_s;tw+=d.total_weight_g;});
        const rate = tp>0?Math.round(ts/tp*100):0;

        let html = '<div class="bambu-stats-summary">';
        html += '<div class="bambu-stat"><span class="bambu-stat-label">Prints</span><span class="bambu-stat-value orange">'+tp+'</span></div>';
        html += '<div class="bambu-stat"><span class="bambu-stat-label">Vellykket</span><span class="bambu-stat-value" style="color:var(--bambu-green)">'+ts+'</span></div>';
        html += '<div class="bambu-stat"><span class="bambu-stat-label">Feilet</span><span class="bambu-stat-value" style="color:var(--bambu-red)">'+tf+'</span></div>';
        html += '<div class="bambu-stat"><span class="bambu-stat-label">Suksessrate</span><span class="bambu-stat-value" style="color:var(--bambu-green)">'+rate+'%</span></div>';
        html += '<div class="bambu-stat"><span class="bambu-stat-label">Printtid</span><span class="bambu-stat-value">'+formatDuration(tt)+'</span></div>';
        html += '<div class="bambu-stat"><span class="bambu-stat-label">Filament</span><span class="bambu-stat-value">'+formatWeight(tw)+'</span></div>';
        html += '</div>';

        devs.sort((a,b)=>b.total_prints-a.total_prints);
        html += '<div class="bambu-stats-grid">';
        devs.forEach(d => {
          const r = d.total_prints>0?Math.round(d.successful/d.total_prints*100):0;
          html += '<div class="bambu-stats-card"><div class="bambu-stats-card-header">';
          html += '<div class="bambu-stats-card-name">'+d.name+'</div><div class="bambu-stats-card-model">'+d.model+'</div></div>';
          html += '<div class="bambu-stats-card-body"><div class="bambu-stats-grid-inner">';
          html += '<div><div class="bambu-stats-item-label">Prints</div><div class="bambu-stats-item-value" style="color:var(--bambu-orange)">'+d.total_prints+'</div></div>';
          html += '<div><div class="bambu-stats-item-label">Printtid</div><div class="bambu-stats-item-value">'+formatDuration(d.total_time_s)+'</div></div>';
          html += '<div><div class="bambu-stats-item-label">Filament</div><div class="bambu-stats-item-value">'+formatWeight(d.total_weight_g)+'</div></div>';
          html += '<div><div class="bambu-stats-item-label">Lengde</div><div class="bambu-stats-item-value">'+formatMeters(d.total_weight_g)+'</div></div>';
          html += '</div>';
          html += '<div class="bambu-rate-bar"><div class="bambu-rate-fill" style="width:'+r+'%"></div></div>';
          html += '<div class="bambu-rate-nums"><span>'+d.successful+' vellykket</span><span>'+r+'%</span><span>'+d.failed+' feilet</span></div>';
          html += '</div></div>';
        });
        html += '</div>';
        el.innerHTML = html;
      } catch(e) {
        el.innerHTML = '<div class="bambu-loading">Kunne ikke koble til backend.</div>';
      }
    }
    load();
  }

  // ── Init ──
  document.addEventListener('DOMContentLoaded', function() {
    const dash = document.getElementById('bambu-dashboard');
    if (dash) initDashboard(dash);

    const stats = document.getElementById('bambu-stats');
    if (stats) initStats(stats);
  });
})();
