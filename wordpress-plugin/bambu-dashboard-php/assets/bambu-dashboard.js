/**
 * Bambu Lab 3D Print Dashboard – WordPress PHP Plugin (HUD-design)
 *
 * Samme oppførsel som Raspberry Pi-dashboardet: kortene bygges én gang og
 * oppdateres på plass (ingen innerHTML-ombygging → ingen blinking), all
 * data fra API-et settes med textContent, og bilder byttes bare når selve
 * filen er en annen.
 */
(function () {
  'use strict';

  const cfg = (typeof window.bambuConfig === 'object' && window.bambuConfig) ? window.bambuConfig : {};
  // restBase mangler i eldre PHP-versjoner – bygg den fra restUrl.
  const REST_BASE = String(cfg.restBase || (String(cfg.restUrl || '/wp-json/').replace(/\/$/, '') + '/bambu/v1')).replace(/\/$/, '');

  const POLL_STATUS_MS = 30000;
  const POLL_TODAY_MS  = 30000;  // pågående prints endrer "I dag"-tallene fortløpende
  const THEME_KEY      = 'bambu_theme';
  const STATS_KEY      = 'bambu_stats_v1';

  // ── Små hjelpere ────────────────────────────────────────────────────────

  const $ = (sel, root) => (root || document).querySelector(sel);
  const collator = new Intl.Collator('no', { numeric: true, sensitivity: 'base' });

  function setText(el, v) { v = v == null ? '' : String(v); if (el.textContent !== v) el.textContent = v; }
  function setHidden(el, h) { h = !!h; if (el.hidden !== h) el.hidden = h; }
  function setTransform(el, v) { if (el.style.transform !== v) el.style.transform = v; }
  function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function num(v) { const n = Number(v); return isFinite(n) ? n : 0; }
  function pad2(n) { return String(n).padStart(2, '0'); }
  function hhmm(d) { return pad2(d.getHours()) + ':' + pad2(d.getMinutes()); }

  // Lager et element. Tekst settes alltid som textContent, aldri som HTML.
  function h(tag, cls, text) {
    const el = document.createElement(tag);
    if (cls) el.className = cls;
    if (text != null) el.textContent = String(text);
    return el;
  }

  // Kun for faste, interne SVG-/mal-strenger (aldri data fra API-et).
  function fromHTML(html) {
    const t = document.createElement('template');
    t.innerHTML = html.trim();
    return t.content.firstElementChild;
  }

  // Bygg REST-URL som virker både med pene permalenker (/wp-json/bambu/v1)
  // og enkle permalenker (?rest_route=/bambu/v1).
  function apiUrl(path, params) {
    const u = new URL(REST_BASE, location.href);
    const route = u.searchParams.get('rest_route');
    if (route != null) u.searchParams.set('rest_route', route.replace(/\/$/, '') + path);
    else u.pathname = u.pathname.replace(/\/$/, '') + path;
    if (params) Object.keys(params).forEach(k => u.searchParams.set(k, String(params[k])));
    return u.toString();
  }

  async function fetchJSON(path, params) {
    const res = await fetch(apiUrl(path, params), { cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'application/json' } });
    let data = null;
    try { data = await res.json(); } catch (e) { /* ikke JSON */ }
    if (!res.ok) {
      const err = new Error(data && typeof data.message === 'string' ? data.message : 'HTTP ' + res.status);
      err.status = res.status;
      throw err;
    }
    if (!data || typeof data !== 'object') throw new Error('Ugyldig svar fra serveren');
    return data;
  }

  // Bare http(s) (eller relative, dvs. samme opprinnelse) – aldri javascript:/data:
  function safeUrl(url) {
    if (!url || typeof url !== 'string') return '';
    try {
      const u = new URL(url, location.href);
      return (u.protocol === 'https:' || u.protocol === 'http:') ? u.href : '';
    } catch (e) { return ''; }
  }

  // Demomodus slås på med ?bambu_demo i URL-en eller [bambu_dashboard demo="true"]
  // Gjør det lett å se i konsollen hvilken versjon nettleseren faktisk har lastet
  console.info('[bambu] Bambu Dashboard v' + (cfg.version || '?'));

  const DEMO_URL = /[?&](bambu_)?demo(=|&|$)/.test(location.search);

  // ── Demomodus: oppdiktede printere som "printer" (?demo / ?bambu_demo) ──
  // Bruker ekte printernavn når de finnes, ellers en liten fiktiv flåte.
  const DEMO_JOBS = [
    ['Benchy.3mf', 900, '#ff8a3d'],
    ['Telefonholder_v2.3mf', 1500, '#4fc3f7'],
    ['Navneskilt_Ringebu.3mf', 1200, '#9ccc65'],
    ['Vase_spiral.3mf', 2100, '#f06292'],
    ['Kabelklips_x10.3mf', 600, '#ffd54f'],
  ];
  const DEMO_AMS = [
    [{ color: '#ff6a00', type: 'PLA' }, { color: '#1e88e5', type: 'PETG' }, { color: '#ffffff', type: 'PLA' }, { color: '#212121', type: 'PLA' }],
    [{ color: '#43a047', type: 'PLA' }],
    [{ color: '#e53935', type: 'PLA' }, { color: '#fdd835', type: 'PLA' }],
  ];
  function demoThumb(color) {
    const svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><g stroke="#1a1a1a" stroke-width="3" stroke-linejoin="round">'
      + '<path fill="' + color + '" d="M100 28 164 62v76l-64 34-64-34V62z"/>'
      + '<path fill="rgba(0,0,0,.18)" d="M100 96v76l64-34V62z"/><path fill="rgba(255,255,255,.25)" d="M36 62l64 34 64-34-64-34z"/></g></svg>';
    return 'data:image/svg+xml;base64,' + btoa(svg);
  }
  function demoPrinters(real) {
    const fleet = (real && real.length ? real : ['Mini 1', 'Mini 2', 'Mini 3', 'Mini 4', 'Mini 5', 'Mini 6', 'Mini 7', 'X1 Carbon']
      .map((n, i) => ({ id: 'demo' + i, name: n, model: n.startsWith('Mini') ? 'A1 mini' : 'X1 Carbon', online: true })));
    const now = Date.now() / 1000;
    return fleet.map((p, i) => {
      const [title, dur, color] = DEMO_JOBS[i % DEMO_JOBS.length];
      const role = i === fleet.length - 1 && fleet.length > 4 ? 'offline' : ['print', 'print', 'idle', 'print', 'paused', 'print'][i % 6];
      const base = { id: p.id, name: p.name, model: p.model, online: role !== 'offline', print_status: 'IDLE',
        task_name: null, progress: null, time_remaining: null, layer: null, total_layers: null,
        nozzle_temp: 25 + (i % 3), bed_temp: 23 + (i % 2), spd_lvl: 2, ams_colors: DEMO_AMS[i % DEMO_AMS.length], thumbnail: null };
      if (role === 'offline' || role === 'idle') return base;
      const layers = Math.round(dur / 6);
      if (role === 'paused') {
        return Object.assign(base, { _plan_g: Math.round(dur / 25), print_status: 'PAUSED', task_name: title, progress: 63, time_remaining: Math.round(dur * 0.37) * 6,
          layer: Math.round(layers * 0.63), total_layers: layers, nozzle_temp: 180, bed_temp: 60, thumbnail: demoThumb(color) });
      }
      // Syklus: printer i `dur` sekunder, er "ferdig" i 45 s, starter på nytt
      const t = (now + i * 211) % (dur + 45);
      if (t >= dur) return Object.assign(base, { print_status: 'SUCCESS', task_name: title, progress: 100, thumbnail: demoThumb(color) });
      const pct = Math.floor(t / dur * 100);
      return Object.assign(base, { _plan_g: Math.round(dur / 25), print_status: 'RUNNING', task_name: title, progress: pct,
        time_remaining: Math.round((dur - t) * 6), layer: Math.max(1, Math.round(layers * pct / 100)), total_layers: layers,
        nozzle_temp: 218 + (i % 5), bed_temp: 60 + (i % 3) * 5, spd_lvl: [2, 3, 2, 4][i % 4], thumbnail: demoThumb(color) });
    });
  }
  // "I dag" i demo: faste ferdige prints + pågående prints × fremdrift (tallene vokser)
  function demoToday(list) {
    const d = { prints: 9, weight_g: 381.6, time_s: 21600, length_cm: 12720 };
    (list || []).forEach(p => {
      if (!p._plan_g || (p.print_status !== 'RUNNING' && p.print_status !== 'PAUSED')) return;
      const pct = (p.progress || 0) / 100;
      d.prints += 1;
      d.weight_g += p._plan_g * pct;
      d.length_cm += p._plan_g * 33.3 * pct;
      d.time_s += Math.round(p._plan_g * 25 * pct);
    });
    return d;
  }

  // Bambu-cover-URL-er er signerte og får ny query-streng jevnlig. For bilder fra
  // andre domener sammenlignes derfor URL uten query; egne (proxy-)URL-er sammenlignes hele.
  function thumbKey(href) {
    try {
      const u = new URL(href);
      return u.origin === location.origin ? u.href : u.origin + u.pathname;
    } catch (e) { return href; }
  }

  function linkButton(url, label) {
    const href = safeUrl(url);
    if (!href || !label) return null;
    const a = h('a', 'bambu-link-btn', label);
    a.href = href;   // samme fane – nytt vindu "forsvinner" i fullskjerm/kiosk
    return a;
  }

  // ── Formatering ─────────────────────────────────────────────────────────

  function formatLeft(s) {
    s = num(s);
    if (s <= 0) return '';
    const hh = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
    if (hh > 0) return hh + 't ' + m + 'm igjen';
    if (m > 0) return m + ' min igjen';
    return 'under 1 min';
  }
  function finishClock(s) {
    s = num(s);
    if (s <= 0) return '';
    const d = new Date(Date.now() + s * 1000);
    const days = Math.round((new Date(d).setHours(0, 0, 0, 0) - new Date().setHours(0, 0, 0, 0)) / 864e5);
    return days >= 1 ? (days === 1 ? 'i morgen' : '+' + days + 'd') + ' ' + hhmm(d) : hhmm(d);
  }
  function formatWeight(g) { g = num(g); return g >= 1000 ? (g / 1000).toFixed(1) + ' kg' : Math.round(g) + ' g'; }
  function formatLength(cm) { const m = num(cm) / 100; return m >= 1000 ? (m / 1000).toFixed(1) + ' km' : Math.round(m) + ' m'; }
  function formatDuration(s) {
    s = num(s);
    const hh = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
    if (hh >= 24) return Math.floor(hh / 24) + 'd ' + (hh % 24) + 't';
    return hh > 0 ? hh + 't ' + m + 'm' : m + 'm';
  }
  function formatInt(n) { return Math.round(num(n)).toLocaleString('nb-NO'); }

  // ── Ikoner (faste strenger) ─────────────────────────────────────────────

  const ICON = {
    moon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.79A9 9 0 1111.21 3a7 7 0 009.79 9.79z"/></svg>',
    sun: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>',
    warn: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg>',
    download: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><path d="M7 10l5 5 5-5M12 15V3"/></svg>',
  };

  // ── Tema ────────────────────────────────────────────────────────────────
  // Én felles håndtering for både fullskjerm-siden og alle wraps.

  function wrapTheme(wrap) {
    const saved = lsGet(THEME_KEY);
    if (saved === 'dark' || saved === 'light') return saved;
    if (wrap && wrap.classList.contains('bambu-light')) return 'light';
    return 'dark';  // standard når ingenting er valgt
  }

  function setWrapTheme(wrap, theme) {
    wrap.classList.toggle('bambu-dark', theme === 'dark');
    wrap.classList.toggle('bambu-light', theme === 'light');
  }

  function applyPageTheme(theme, persist) {
    const dark = theme !== 'light';
    document.querySelectorAll('.bambu-wrap').forEach(w => setWrapTheme(w, dark ? 'dark' : 'light'));
    const page = $('.bambu-fs-page');
    if (page) {
      page.classList.toggle('fs-dark', dark);
      page.classList.toggle('fs-light', !dark);
      document.documentElement.classList.toggle('bambu-fs-light', !dark);
    }
    const btn = document.getElementById('bambu-theme-toggle');
    if (btn) {
      btn.innerHTML = dark ? ICON.sun : ICON.moon;  // fast ikon-streng
      btn.title = dark ? 'Bytt til lyst tema' : 'Bytt til mørkt tema';
      btn.setAttribute('aria-label', btn.title);
    }
    if (persist) lsSet(THEME_KEY, dark ? 'dark' : 'light');
  }

  function initTheme() {
    const wraps = document.querySelectorAll('.bambu-wrap');
    if ($('.bambu-fs-page')) {
      applyPageTheme(wrapTheme(wraps[0]), false);
    } else {
      wraps.forEach(w => setWrapTheme(w, wrapTheme(w)));
    }
    const btn = document.getElementById('bambu-theme-toggle');
    if (btn) {
      btn.addEventListener('click', () => {
        const page = $('.bambu-fs-page');
        const first = $('.bambu-wrap');
        const isLight = page ? page.classList.contains('fs-light') : !!(first && first.classList.contains('bambu-light'));
        applyPageTheme(isLight ? 'dark' : 'light', true);
      });
    }
  }

  // ── Polling-hjelper: én forespørsel om gangen, pause når fanen er skjult ──

  function poller(fn, ms) {
    let busy = false;
    const run = async () => {
      if (busy) return;
      busy = true;
      try { await fn(); } finally { busy = false; }
    };
    setInterval(() => { if (!document.hidden) run(); }, ms);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) run(); });
    run();
    return run;
  }

  // ════════════════════════════════════════════════════════════════════════
  //  Dashboard
  // ════════════════════════════════════════════════════════════════════════

  const CARD_HTML =
    '<article class="bambu-card bambu-panel bambu-corners">' +
      '<div class="bambu-card-fill"></div>' +
      '<div class="bambu-card-alert"></div>' +
      '<header class="bambu-card-head">' +
        '<div class="bambu-card-id"><span class="bambu-card-name"></span><span class="bambu-card-model"></span></div>' +
        '<span class="bambu-chip"><i></i><span class="bambu-chip-text"></span></span>' +
      '</header>' +
      '<div class="bambu-card-visual">' +
        '<img class="bambu-thumb" alt="" decoding="async" hidden />' +
        '<svg class="bambu-placeholder" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true">' +
          '<path d="M32 6 54 18v28L32 58 10 46V18z"/><path d="M10 18l22 12 22-12M32 30v28"/><path d="M21 12l22 12" opacity=".45"/>' +
        '</svg>' +
      '</div>' +
      '<div class="bambu-card-job" hidden></div>' +
      '<div class="bambu-card-main">' +
        '<div class="bambu-readout">' +
          '<span class="bambu-pct"><span class="bambu-pct-num">0</span><small>%</small></span>' +
          '<span class="bambu-state-msg"></span>' +
          '<span class="bambu-eta"><span class="bambu-eta-left"></span><span class="bambu-eta-finish"></span></span>' +
        '</div>' +
        '<div class="bambu-prog"><div class="bambu-prog-fill"></div><div class="bambu-prog-ticks"></div></div>' +
      '</div>' +
      '<footer class="bambu-card-meta">' +
        '<span class="bambu-meta bambu-m-nozzle" title="Dyse" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 14.76V3.5a2.5 2.5 0 00-5 0v11.26a4.5 4.5 0 105 0z"/></svg><b></b></span>' +
        '<span class="bambu-meta bambu-m-bed" title="Plate" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="9" width="20" height="6" rx="1.5"/></svg><b></b></span>' +
        '<span class="bambu-meta bambu-m-layer" title="Lag" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg><b></b></span>' +
        '<span class="bambu-meta bambu-m-speed" title="Hastighet" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg><b></b></span>' +
        '<span class="bambu-ams"></span>' +
      '</footer>' +
    '</article>';

  const LABEL   = { printing: 'Printer', paused: 'Pauset', failed: 'Feil', done: 'Ferdig', idle: 'Klar', offline: 'Offline' };
  const MESSAGE = { paused: 'Pauset', failed: 'Feilet', done: 'Fullført', idle: 'Klar', offline: 'Offline' };
  const SPEED   = { 1: 'Stille', 2: 'Normal', 3: 'Sport', 4: 'Ludicrous' };

  function initDashboard(wrap) {
    const fsPage = wrap.closest('.bambu-fs-page');
    const fit = !!fsPage && wrap.dataset.auto !== 'false';
    const demo = DEMO_URL || wrap.dataset.demo === 'true';
    let demoReal = null;  // ekte printerliste (navn) brukt i demo
    let lastList = [];    // siste printerliste (brukes av demo-"I dag")
    const cols = Math.max(1, Math.min(8, parseInt(wrap.dataset.cols, 10) || 5));
    wrap.style.setProperty('--bambu-cols', cols);

    const cards = new Map();       // printer-id → kort-element
    const finishedAt = {};         // printer-id → tidspunkt vi så at den ble ferdig
    const seen = new Set();        // printere vi har sett minst én gang i denne økten
    let hasData = false;

    // ── Skjelett (bygges én gang) ──
    const topbar = h('div', 'bambu-topbar');
    const chips = h('div', 'bambu-chips');
    const live = h('span', 'bambu-live');
    live.title = 'Tilkoblingsstatus';
    const liveText = h('span', 'bambu-live-text', 'Kobler til…');
    live.append(h('span', 'bambu-live-dot'), liveText);
    const right = h('div', 'bambu-topbar-right');
    right.append(live);
    const link = linkButton(cfg.dashLinkUrl, cfg.dashLinkLabel);
    if (link) right.append(link);
    topbar.append(chips, right);

    const banner = h('div', 'bambu-banner');
    banner.hidden = true;
    banner.setAttribute('role', 'status');
    const bannerText = h('span');
    banner.append(fromHTML(ICON.warn), bannerText);

    const grid = h('div', 'bambu-grid');
    const empty = h('div', 'bambu-empty', 'Laster printere…');

    const today = h('section', 'bambu-today');
    today.hidden = true;
    const todayVals = {};
    const todayLabel = h('div', 'bambu-today-item');
    todayLabel.append(h('span', 'bambu-eyebrow bambu-today-label', 'I dag'));
    today.append(todayLabel);
    [['prints', 'prints'], ['weight', 'filament'], ['length', 'lengde'], ['time', 'printtid']].forEach(([key, label]) => {
      const item = h('div', 'bambu-today-item');
      todayVals[key] = h('b', null, '–');
      item.append(todayVals[key], h('span', 'bambu-eyebrow', label));
      today.append(item);
    });

    wrap.replaceChildren(topbar, banner, grid, empty, today);
    wrap.classList.toggle('bambu-fit', fit);

    // I fullskjerm-malen flyttes chips, live-status og lenke opp i headeren.
    if (fsPage) {
      const statsSlot = $('#bambu-fs-stats', fsPage);
      const liveSlot = $('#bambu-fs-live', fsPage);
      if (statsSlot && liveSlot) {
        statsSlot.append(chips);
        liveSlot.append(live);
        if (link) liveSlot.append(link);
        wrap.classList.add('is-moved-topbar');
      }
    }

    function showBanner(kind, text) {
      banner.classList.toggle('is-warn', kind === 'warn');
      setText(bannerText, text || '');
      if (banner.hidden !== !kind) {
        setHidden(banner, !kind);
        layoutFit();  // banneret tar plass – regn ut kortstørrelsen på nytt
      }
    }

    function setLive(kind, text) {
      live.classList.toggle('is-live', kind === 'live');
      live.classList.toggle('is-error', kind === 'error');
      setText(liveText, text);
    }

    // ── Status ──
    function stateOf(p) {
      if (!p.online) return 'offline';
      const s = String(p.print_status || '').toUpperCase();
      if (s === 'SUCCESS' || s === 'FINISH') {
        if (!(p.id in finishedAt)) {
          // Var den allerede ferdig første gang vi så den, er den ikke "nettopp ferdig".
          finishedAt[p.id] = seen.has(p.id) ? Date.now() : 0;
        }
        return Date.now() - finishedAt[p.id] < 120000 ? 'done' : 'idle';
      }
      delete finishedAt[p.id];
      if (s === 'RUNNING' || s === 'PRINTING' || s === 'PREPARE') return 'printing';
      if (s === 'PAUSED' || s === 'PAUSE') return 'paused';
      if (s === 'FAIL' || s === 'FAILED') return 'failed';
      return 'idle';
    }

    // ── Kort ──
    function createCard(id) {
      const el = fromHTML(CARD_HTML);
      el.dataset.dev = id;
      el.style.setProperty('--holo-delay', -(Math.random() * 9).toFixed(2) + 's');  // hologram ikke i takt
      const q = s => el.querySelector(s);
      el._r = {
        fill: q('.bambu-card-fill'), name: q('.bambu-card-name'), model: q('.bambu-card-model'),
        chip: q('.bambu-chip-text'), thumb: q('.bambu-thumb'), job: q('.bambu-card-job'),
        pct: q('.bambu-pct'), pctNum: q('.bambu-pct-num'), msg: q('.bambu-state-msg'),
        eta: q('.bambu-eta'), etaLeft: q('.bambu-eta-left'), etaFinish: q('.bambu-eta-finish'),
        prog: q('.bambu-prog'), progFill: q('.bambu-prog-fill'),
        nozzle: q('.bambu-m-nozzle'), bed: q('.bambu-m-bed'), layer: q('.bambu-m-layer'), speed: q('.bambu-m-speed'),
        ams: q('.bambu-ams'), thumbKey: null, amsSig: null,
      };
      return el;
    }

    // Bytt bare bilde når selve filen er en annen, og forhåndslast før bytte
    // så det aldri vises en tom boks.
    function setThumb(card, rawUrl) {
      const r = card._r;
      // data:-bilder er trygge i <img> (brukes av demomodus)
      const href = /^data:image\/(svg\+xml|png|jpeg);base64,/.test(rawUrl || '') ? rawUrl : safeUrl(rawUrl);
      const key = href ? thumbKey(href) : '';
      if (key === r.thumbKey) return;
      r.thumbKey = key;
      if (!href) {
        r.thumb.hidden = true;
        card.classList.remove('has-thumb');
        r.thumb.removeAttribute('src');
        return;
      }
      const pre = new Image();
      pre.onload = () => {
        if (r.thumbKey !== key) return;
        r.thumb.src = href;
        r.thumb.hidden = false;
        card.classList.add('has-thumb');
      };
      pre.onerror = () => { if (r.thumbKey === key) r.thumbKey = null; };  // prøv igjen neste runde
      pre.src = href;
    }

    function setMeta(el, show, text) {
      setHidden(el, !show);
      if (show) setText(el.querySelector('b'), text);
    }

    function updateCard(card, p, state) {
      const r = card._r;
      const hasJob = state === 'printing' || state === 'paused' || state === 'failed';
      const pv = parseFloat(p.progress);
      const progress = p.progress != null && !isNaN(pv) ? Math.max(0, Math.min(100, pv)) : null;

      if (card.dataset.state !== state) card.dataset.state = state;
      card.classList.toggle('has-job', hasJob && progress != null);

      setText(r.name, p.name || p.id);
      setText(r.model, p.model && p.model !== 'Ukjent' ? p.model : '');
      setText(r.chip, LABEL[state]);

      setThumb(card, p.thumbnail || '');

      const job = hasJob && p.task_name ? String(p.task_name) : '';
      setText(r.job, job);
      setHidden(r.job, !job);
      if (job) r.job.title = job;

      // Fremdrift
      const showPct = hasJob && progress != null;
      setHidden(r.pct, !showPct);
      if (showPct) setText(r.pctNum, Math.round(progress));

      setHidden(r.msg, state === 'printing' && showPct);
      setText(r.msg, state === 'printing' ? 'Printer' : (MESSAGE[state] || ''));

      const left = state === 'printing' ? formatLeft(p.time_remaining) : '';
      const fin  = state === 'printing' ? finishClock(p.time_remaining) : '';
      setHidden(r.eta, !(left || fin));
      setText(r.etaLeft, left);
      setText(r.etaFinish, fin ? 'ferdig ' + fin : '');

      const barValue = state === 'done' ? 1 : (showPct ? progress / 100 : 0);
      setHidden(r.prog, !(showPct || state === 'done'));
      setTransform(r.progFill, 'scaleX(' + barValue + ')');
      setTransform(r.fill, 'scaleX(' + (showPct ? progress / 100 : 0) + ')');

      // Meta-linje (temperatur/lag/AMS er som regel null i PHP-versjonen → skjules)
      const online = !!p.online;
      setMeta(r.nozzle, online && p.nozzle_temp != null && isFinite(p.nozzle_temp), Math.round(num(p.nozzle_temp)) + '°');
      setMeta(r.bed, online && p.bed_temp != null && isFinite(p.bed_temp), Math.round(num(p.bed_temp)) + '°');
      setMeta(r.layer, hasJob && p.layer != null && num(p.total_layers) > 0, num(p.layer) + '/' + num(p.total_layers));
      setMeta(r.speed, state === 'printing' && !!SPEED[p.spd_lvl], SPEED[p.spd_lvl] || '');

      const colors = (online && Array.isArray(p.ams_colors))
        ? p.ams_colors.filter(c => c && /^#[0-9a-f]{6}$/i.test(c.color || ''))
        : [];
      const amsSig = colors.map(c => c.color + (c.type || '')).join('|');
      if (amsSig !== r.amsSig) {
        r.amsSig = amsSig;
        r.ams.replaceChildren(...colors.map(c => {
          const s = document.createElement('span');
          s.style.background = c.color;
          s.title = (String(c.type || '') + ' ' + c.color).trim();
          return s;
        }));
      }
    }

    function updateChips(states, printers) {
      const count = s => states.filter(x => x === s).length;
      const items = [
        ['Totalt', printers.length, ''],
        ['Online', printers.filter(p => p.online).length, ''],
        ['Printer', count('printing'), 'is-orange'],
      ];
      const failed = count('failed');
      if (failed) items.push(['Feil', failed, 'is-red']);
      const sig = JSON.stringify(items);
      if (chips.dataset.sig === sig) return;
      chips.dataset.sig = sig;
      chips.replaceChildren(...items.map(([label, val, cls]) => {
        const el = h('span', 'bambu-hud-stat ' + cls);
        el.append(h('b', null, val), ' ' + label);
        return el;
      }));
    }

    function render(printers) {
      const list = printers.filter(p => p && p.id != null).map(p => ({ p, id: String(p.id), state: stateOf(p) }));
      // Fast rekkefølge etter navn (Mini 2 før Mini 10), så kortene ikke hopper når status endres
      list.sort((a, b) => collator.compare(String(a.p.name || a.id), String(b.p.name || b.id)));

      const ids = new Set();
      list.forEach((e, i) => {
        ids.add(e.id);
        let card = cards.get(e.id);
        if (!card) { card = createCard(e.id); cards.set(e.id, card); }
        updateCard(card, e.p, e.state);
        if (grid.children[i] !== card) grid.insertBefore(card, grid.children[i] || null);
      });
      cards.forEach((card, id) => { if (!ids.has(id)) { card.remove(); cards.delete(id); } });
      list.forEach(e => seen.add(e.p.id));

      updateChips(list.map(e => e.state), list.map(e => e.p));
      setText(empty, list.length ? '' : 'Ingen printere funnet.');
      setHidden(empty, list.length > 0);
      layoutFit();
    }

    async function refresh() {
      try {
        let data;
        if (demo) {
          if (demoReal === null) {
            try { const r = await fetchJSON('/status'); demoReal = Array.isArray(r.printers) ? r.printers : []; }
            catch (e) { demoReal = []; }
          }
          data = { printers: demoPrinters(demoReal), error: null, needs_verify_code: false };
        } else {
          data = await fetchJSON('/status');
        }

        if (data.needs_verify_code) {
          showBanner('warn', 'Venter på verifiseringskode fra Bambu Lab. En administrator må skrive inn koden under Innstillinger → Bambu Dashboard i WordPress.');
          setLive('error', 'Venter på kode');
          if (!hasData) setText(empty, 'Venter på verifisering…');
          return;
        }

        const printers = Array.isArray(data.printers) ? data.printers : null;
        if (data.error && (!printers || !printers.length)) {
          // Behold siste gode data, vis bare feilen.
          showBanner('error', 'Feil fra Bambu Cloud: ' + String(data.error));
          setLive('error', 'Feil');
          if (!hasData) setText(empty, 'Ingen data ennå – prøver igjen…');
          return;
        }

        showBanner(data.error ? 'error' : null, data.error ? 'Feil fra Bambu Cloud: ' + String(data.error) : '');
        hasData = true;
        lastList = printers || [];
        render(lastList);
        if (demo) {
          showBanner('warn', 'Demomodus – printdataene er oppdiktet.');
          setLive('live', 'Demo · ' + hhmm(new Date()));
        } else {
          setLive('live', 'Oppdatert ' + hhmm(new Date()));
        }
      } catch (e) {
        console.warn('[bambu] Kunne ikke hente status:', e);
        showBanner('error', e && e.status
          ? 'Serverfeil (HTTP ' + e.status + '): ' + e.message + ' – prøver igjen om litt.'
          : 'Ingen kontakt med serveren – prøver igjen om litt.');
        setLive('error', 'Frakoblet');
        if (!hasData) setText(empty, 'Kunne ikke koble til – prøver igjen…');
      }
    }

    async function refreshToday() {
      try {
        const d = demo ? demoToday(lastList) : await fetchJSON('/today');
        if (d.error) return;  // behold gamle tall
        setText(todayVals.prints, formatInt(d.prints));
        setText(todayVals.weight, formatWeight(d.weight_g));
        setText(todayVals.length, formatLength(d.length_cm));
        setText(todayVals.time, formatDuration(d.time_s));
        if (today.hidden) { today.hidden = false; layoutFit(); }
      } catch (e) { /* behold gamle tall */ }
    }

    // ── Tilpass-til-skjerm: finn kolonner/rader som gir størst mulige kort ──
    function layoutFit() {
      if (!fit) return;
      const n = cards.size;
      if (!n) return;
      const gap = parseFloat(getComputedStyle(grid).columnGap) || 12;
      const W = grid.clientWidth, H = grid.clientHeight;
      const ratio = 0.82;  // ønsket bredde/høyde for et kort
      let best = null;
      for (let c = 1; c <= n; c++) {
        const rows = Math.ceil(n / c);
        const w = (W - gap * (c - 1)) / c;
        const hh = (H - gap * (rows - 1)) / rows;
        if (w <= 0 || hh <= 0) continue;
        const cw = Math.min(w, hh * ratio * 1.35);   // tillat litt bredere kort enn idealet
        const score = Math.min(cw, hh * ratio);
        if (!best || score > best.score) best = { score, w: cw, h: hh };
      }
      if (!best) return;
      grid.style.setProperty('--bambu-card-w', Math.floor(best.w) + 'px');
      grid.style.setProperty('--bambu-card-h', Math.floor(best.h) + 'px');
    }

    if (fit) {
      let resizeTimer;
      window.addEventListener('resize', () => { clearTimeout(resizeTimer); resizeTimer = setTimeout(layoutFit, 120); });
      document.addEventListener('fullscreenchange', () => requestAnimationFrame(layoutFit));
    }

    poller(refresh, demo ? 3000 : POLL_STATUS_MS);
    poller(refreshToday, demo ? 3000 : POLL_TODAY_MS);
  }

  // ════════════════════════════════════════════════════════════════════════
  //  Statistikk
  // ════════════════════════════════════════════════════════════════════════

  const SVG_NS = 'http://www.w3.org/2000/svg';
  const MONTHS = ['jan', 'feb', 'mar', 'apr', 'mai', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'des'];

  const rate = d => num(d.total_prints) > 0 ? Math.round(num(d.successful) / num(d.total_prints) * 100) : 0;

  const SORTS = [
    { key: 'total_prints',    label: 'Prints',   dir: -1, get: d => num(d.total_prints) },
    { key: 'rate',            label: 'Suksess',  dir: -1, get: rate },
    { key: 'total_time_s',    label: 'Tid',      dir: -1, get: d => num(d.total_time_s) },
    { key: 'total_weight_g',  label: 'Filament', dir: -1, get: d => num(d.total_weight_g) },
    { key: 'total_length_cm', label: 'Lengde',   dir: -1, get: d => num(d.total_length_cm) },
    { key: 'name',            label: 'Navn',     dir: 1,  get: d => String(d.name || '') },
  ];

  // Tidspunkt fra API-et er UTC. Bruk unix-sekunder når de finnes.
  function parseUtc(ts, str) {
    const n = Number(ts);
    if (ts != null && isFinite(n) && n > 0) return new Date(n * 1000);
    if (!str || typeof str !== 'string') return null;
    const p = str.split(/[- :T]/).map(Number);
    if (p.length < 3 || p.slice(0, 3).some(isNaN)) return null;
    return new Date(Date.UTC(p[0], p[1] - 1, p[2], p[3] || 0, p[4] || 0, p[5] || 0));
  }
  function formatHistDate(d) {
    if (!d) return '–';
    const year = d.getFullYear() !== new Date().getFullYear() ? ' ' + d.getFullYear() : '';
    return d.getDate() + '. ' + MONTHS[d.getMonth()] + year + ' ' + hhmm(d);
  }

  function ring(pct, hasPrints) {
    const r = 26, c = 2 * Math.PI * r;
    const box = h('div', 'bambu-ring');
    box.title = 'Suksessrate';
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', '0 0 64 64');
    const track = document.createElementNS(SVG_NS, 'circle');
    track.setAttribute('class', 'bambu-ring-track' + (hasPrints ? '' : ' is-empty'));
    const val = document.createElementNS(SVG_NS, 'circle');
    val.setAttribute('class', 'bambu-ring-val');
    [track, val].forEach(el => { el.setAttribute('cx', '32'); el.setAttribute('cy', '32'); el.setAttribute('r', String(r)); });
    val.setAttribute('stroke-dasharray', String(c));
    val.setAttribute('stroke-dashoffset', String(c * (1 - pct / 100)));
    if (!pct) val.setAttribute('hidden', '');
    svg.append(track, val);
    box.append(svg, h('span', null, hasPrints ? pct + '%' : '–'));
    return box;
  }

  function initStats(wrap) {
    let devices = [];
    let sortKey = 'name';
    let sortDir = 1;
    let histLoaded = false;
    let histToken = 0;
    let histPage = 1;

    // ── Skjelett ──
    const head = h('div', 'bambu-stats-head');
    const title = h('div', 'bambu-title');
    title.append('Print', h('span', null, '//'), 'logg');
    const headRight = h('div', 'bambu-head-right');
    const sync = h('span', 'bambu-sync');
    headRight.append(sync);
    const link = linkButton(cfg.statsLinkUrl, cfg.statsLinkLabel);
    // I fullskjerm-malen ligger lenken i headeren, samme sted som på dashboardet
    const fsLiveSlot = $('#bambu-fs-live', wrap.closest('.bambu-fs-page') || document.createElement('div'));
    if (link) (fsLiveSlot || headRight).append(link);
    head.append(title, headRight);

    const ind = h('div', 'bambu-refresh-ind');
    const errBanner = h('div', 'bambu-banner');
    const errText = h('span');
    errBanner.append(fromHTML(ICON.warn), errText);
    errBanner.hidden = true;
    const warnBanner = h('div', 'bambu-banner is-warn');
    const warnText = h('span');
    warnBanner.append(fromHTML(ICON.warn), warnText);
    warnBanner.hidden = true;

    const loading = h('div', 'bambu-loading', 'Henter statistikk…');
    const content = h('div', 'bambu-stats-content');
    content.hidden = true;

    const kpis = h('div', 'bambu-kpis');

    const tabs = h('div', 'bambu-tabs');
    const tabGroup = h('div', 'bambu-tab-group');
    tabGroup.setAttribute('role', 'tablist');
    const tabBtns = {};
    [['stats', 'Per printer'], ['history', 'Alle prints']].forEach(([key, label]) => {
      const b = h('button', 'bambu-tab-btn', label);
      b.type = 'button';
      b.setAttribute('role', 'tab');
      b.addEventListener('click', () => showTab(key));
      tabBtns[key] = b;
      tabGroup.append(b);
    });
    const exportBtn = h('a', 'bambu-sort-btn bambu-export-btn');
    exportBtn.href = apiUrl('/export');
    exportBtn.setAttribute('download', '');
    exportBtn.append(fromHTML(ICON.download), ' Last ned CSV');
    tabs.append(tabGroup, exportBtn);

    // Fane: per printer
    const statsTab = h('div', 'bambu-tab-stats');
    const toolbar = h('div', 'bambu-toolbar');
    toolbar.append(h('span', 'bambu-eyebrow', 'Sorter'));
    const sortBtns = SORTS.map(s => {
      const b = h('button', 'bambu-sort-btn');
      b.type = 'button';
      b.dataset.key = s.key;
      b.addEventListener('click', () => {
        if (sortKey === s.key) sortDir *= -1;
        else { sortKey = s.key; sortDir = s.dir; }
        renderGrid();
      });
      toolbar.append(b);
      return b;
    });
    const pgrid = h('div', 'bambu-pgrid');
    statsTab.append(toolbar, pgrid);

    // Fane: alle prints
    const histTab = h('div', 'bambu-hist');
    histTab.hidden = true;
    const histMsg = h('div', 'bambu-loading', 'Laster historikk…');
    const tableWrap = h('div', 'bambu-table-wrap bambu-panel');
    tableWrap.hidden = true;
    const table = h('table', 'bambu-hist-table');
    const thead = h('thead');
    const htr = h('tr');
    [['Dato', ''], ['Printer', ''], ['Tittel', ''], ['Status', ''], ['Tid', 'is-num'], ['Filament', 'is-num']].forEach(([t, c]) => htr.append(h('th', c, t)));
    thead.append(htr);
    const tbody = h('tbody');
    table.append(thead, tbody);
    tableWrap.append(table);
    const pager = h('div', 'bambu-pager');
    const prevBtn = h('button', 'bambu-sort-btn', '← Forrige');
    const nextBtn = h('button', 'bambu-sort-btn', 'Neste →');
    prevBtn.type = nextBtn.type = 'button';
    const pageNum = h('span', 'bambu-pagenum');
    const histTotal = h('span', 'bambu-hist-total');
    pager.append(prevBtn, pageNum, nextBtn, histTotal);
    pager.hidden = true;
    prevBtn.addEventListener('click', () => loadHistory(histPage - 1));
    nextBtn.addEventListener('click', () => loadHistory(histPage + 1));
    histTab.append(histMsg, tableWrap, pager);

    content.append(warnBanner, kpis, tabs, statsTab, histTab);
    wrap.replaceChildren(head, ind, errBanner, loading, content);

    function showError(text) {
      setText(errText, text || '');
      setHidden(errBanner, !text);
    }

    function showTab(key) {
      Object.keys(tabBtns).forEach(k => {
        tabBtns[k].classList.toggle('active', k === key);
        tabBtns[k].setAttribute('aria-selected', k === key ? 'true' : 'false');
      });
      setHidden(statsTab, key !== 'stats');
      setHidden(histTab, key !== 'history');
      if (key === 'history' && !histLoaded) loadHistory(1);
    }

    // ── KPI-er ──
    function kpi(label, value, color) {
      const el = h('div', 'bambu-kpi bambu-panel');
      if (color) el.dataset.k = color;
      el.append(h('span', 'bambu-eyebrow', label), h('b', null, value));
      return el;
    }

    function renderKpis() {
      const t = devices.reduce((a, d) => ({
        prints: a.prints + num(d.total_prints), ok: a.ok + num(d.successful), fail: a.fail + num(d.failed),
        time: a.time + num(d.total_time_s), weight: a.weight + num(d.total_weight_g), length: a.length + num(d.total_length_cm),
      }), { prints: 0, ok: 0, fail: 0, time: 0, weight: 0, length: 0 });
      kpis.replaceChildren(
        kpi('Totalt prints', formatInt(t.prints), 'orange'),
        kpi('Vellykket', formatInt(t.ok), 'green'),
        kpi('Feilet / avbrutt', formatInt(t.fail), 'red'),
        kpi('Suksessrate', (t.prints ? Math.round(t.ok / t.prints * 100) : 0) + '%', 'cyan'),
        kpi('Total printtid', formatDuration(t.time)),
        kpi('Filament', formatWeight(t.weight)),
        kpi('Lengde', formatLength(t.length))
      );
    }

    // ── Printerkort ──
    function pstat(label, value, hl) {
      const el = h('div', 'bambu-pstat' + (hl ? ' is-hl' : ''));
      el.append(h('b', null, value), h('span', 'bambu-eyebrow', label));
      return el;
    }

    function pcard(d) {
      const card = h('article', 'bambu-pcard bambu-panel bambu-corners');
      const head = h('div', 'bambu-pcard-head');
      const t = h('div', 'bambu-pcard-title');
      t.append(h('div', 'bambu-pcard-name', d.name || '–'), h('div', 'bambu-pcard-model', d.model || ''));
      head.append(t, ring(rate(d), num(d.total_prints) > 0));
      const stats = h('div', 'bambu-pstats');
      stats.append(
        pstat('Prints', formatInt(d.total_prints), true),
        pstat('Tid', formatDuration(d.total_time_s)),
        pstat('Filament', formatWeight(d.total_weight_g)),
        pstat('Lengde', formatLength(d.total_length_cm))
      );
      const okfail = h('div', 'bambu-okfail');
      okfail.append(
        h('span', 'is-ok', '▲ ' + formatInt(d.successful) + ' vellykket'),
        h('span', 'is-fail', formatInt(d.failed) + ' feilet ▼')
      );
      card.append(head, stats, okfail);
      return card;
    }

    function renderGrid() {
      const s = SORTS.find(x => x.key === sortKey) || SORTS[0];
      // Printere som er fjernet fra kontoen telles i totalene, men får ikke eget kort
      const sorted = devices.filter(d => !d.removed).sort((a, b) => {
        if (s.key === 'name') return sortDir * collator.compare(s.get(a), s.get(b));
        return sortDir * (s.get(a) - s.get(b)) || collator.compare(String(a.name || ''), String(b.name || ''));
      });
      pgrid.replaceChildren(...sorted.map(pcard));
      if (!sorted.length) pgrid.append(h('div', 'bambu-empty', 'Ingen printere funnet.'));
      sortBtns.forEach((b, i) => {
        const def = SORTS[i];
        const active = def.key === sortKey;
        b.classList.toggle('active', active);
        b.replaceChildren(def.label);
        if (active) b.append(' ', h('span', 'bambu-sort-arrow', sortDir === -1 ? '↓' : '↑'));
      });
    }

    function renderAll(data) {
      devices = Array.isArray(data.devices) ? data.devices.filter(d => d && typeof d === 'object') : [];
      renderKpis();
      renderGrid();
      if (data.data_complete === true) {
        warnBanner.hidden = true;
      } else {
        const apiTotal = num(data.api_total);
        setText(warnText, 'Historikken lastes fortsatt inn – tallene er ikke komplette ennå. Last inn siden på nytt om litt.' +
          (apiTotal > 0 ? ' (Bambu rapporterer ' + formatInt(apiTotal) + ' prints totalt.)' : ''));
        warnBanner.hidden = false;
      }
      const at = num(data._fetched_at);
      setText(sync, at ? 'Oppdatert ' + new Date(at).toLocaleString('nb-NO', { dateStyle: 'short', timeStyle: 'short' }) : '');
      loading.hidden = true;
      content.hidden = false;
    }

    // ── Historikk ──
    function histRow(p) {
      const ok = num(p.status) === 2;
      const start = parseUtc(p.start_ts, p.start_time);
      const end = parseUtc(p.end_ts, p.end_time);
      const dur = (start && end && end > start) ? Math.round((end - start) / 1000) : num(p.cost_time_s);
      const tr = h('tr', ok ? '' : 'is-fail');
      const status = h('span', 'bambu-status' + (ok ? '' : ' is-fail'));
      status.append(h('i'), ok ? 'OK' : 'Feilet');
      const stTd = h('td', 'bambu-hist-status');
      stTd.append(status);
      const titleTd = h('td', 'bambu-hist-title', p.title || '–');
      if (p.title) titleTd.title = String(p.title);
      tr.append(
        h('td', 'bambu-hist-date', formatHistDate(start)),
        h('td', 'bambu-hist-printer', p.device_name || '–'),
        titleTd,
        stTd,
        h('td', 'bambu-hist-meta', dur > 0 ? formatDuration(dur) : '–'),
        h('td', 'bambu-hist-meta', ok ? formatWeight(p.weight_g) : '–')
      );
      return tr;
    }

    async function loadHistory(page) {
      const token = ++histToken;
      histTab.classList.add('is-loading');
      prevBtn.disabled = nextBtn.disabled = true;
      try {
        const data = await fetchJSON('/prints', { page: Math.max(1, page), per_page: 50 });
        if (token !== histToken) return;
        if (data.error) throw new Error(String(data.error));
        const prints = Array.isArray(data.prints) ? data.prints : [];
        const pages = Math.max(1, num(data.pages) || 1);
        histPage = Math.min(Math.max(1, num(data.page) || page), pages);
        histLoaded = true;
        tbody.replaceChildren(...prints.map(histRow));
        setHidden(tableWrap, !prints.length);
        setText(histMsg, prints.length ? '' : 'Ingen prints funnet.');
        setHidden(histMsg, prints.length > 0);
        setText(pageNum, 'Side ' + histPage + ' av ' + pages);
        setText(histTotal, formatInt(data.total) + ' prints totalt');
        setHidden(prevBtn, pages <= 1);
        setHidden(nextBtn, pages <= 1);
        setHidden(pageNum, pages <= 1);
        prevBtn.disabled = histPage <= 1;
        nextBtn.disabled = histPage >= pages;
        setHidden(pager, false);
        if (page !== 1) histTab.scrollIntoView({ block: 'nearest' });
      } catch (e) {
        if (token !== histToken) return;
        console.warn('[bambu] Kunne ikke laste historikk:', e);
        if (!histLoaded) {
          setText(histMsg, 'Kunne ikke laste historikk.');
          setHidden(histMsg, false);
        } else {
          prevBtn.disabled = histPage <= 1;
          nextBtn.disabled = false;
        }
        showError('Kunne ikke laste historikk: ' + e.message);
      } finally {
        if (token === histToken) histTab.classList.remove('is-loading');
      }
    }

    // ── Lasting med lokal cache ──
    async function load() {
      let cached = null;
      try { cached = JSON.parse(lsGet(STATS_KEY) || 'null'); } catch (e) { cached = null; }
      if (!cached || typeof cached !== 'object' || !Array.isArray(cached.devices)) cached = null;

      if (cached) renderAll(cached);
      ind.classList.add('is-active');
      try {
        const data = await fetchJSON('/stats');
        if (data.error) throw new Error(String(data.error));
        data._fetched_at = Date.now();
        lsSet(STATS_KEY, JSON.stringify(data));
        showError('');
        renderAll(data);
      } catch (e) {
        console.warn('[bambu] Kunne ikke hente statistikk:', e);
        if (cached) showError('Kunne ikke oppdatere statistikken – viser lagrede tall. (' + e.message + ')');
        else { loading.hidden = true; showError('Kunne ikke laste statistikk: ' + e.message); }
      } finally {
        ind.classList.remove('is-active');
      }
    }

    showTab('stats');
    load();
  }

  // ════════════════════════════════════════════════════════════════════════
  //  Fullskjerm-header: klokke
  // ════════════════════════════════════════════════════════════════════════

  function initClock() {
    const clock = document.getElementById('bambu-fs-clock');
    if (!clock) return;
    const tick = () => setText(clock, hhmm(new Date()));
    tick();
    setInterval(tick, 1000);
  }

  // ── Init ────────────────────────────────────────────────────────────────

  function init() {
    initTheme();
    initClock();
    const dash = document.getElementById('bambu-dashboard');
    if (dash) initDashboard(dash);
    const stats = document.getElementById('bambu-stats');
    if (stats) initStats(stats);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

})();
