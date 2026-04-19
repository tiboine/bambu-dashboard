<?php
/* Template Name: Bambu Fullskjerm */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>3D-print status – Makerspace Ringebu</title>
  <?php wp_head(); ?>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; overflow: hidden; }
    #wpadminbar { display: none !important; }
    html { margin-top: 0 !important; }

    .bambu-fs-page {
      display: flex;
      flex-direction: column;
      height: 100vh;
      width: 100vw;
      overflow: hidden;
      transition: background .3s;
    }

    /* ── Header ── */
    .bambu-fs-header {
      flex-shrink: 0;
      height: clamp(48px, 6.5vh, 80px);
      padding: 0 clamp(1rem, 2vw, 2.5rem);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      transition: background .3s, border-color .3s;
    }

    .bambu-fs-header img {
      height: clamp(22px, 3.2vh, 46px);
    }

    .bambu-fs-right {
      display: flex;
      align-items: center;
      gap: clamp(0.5rem, 1vw, 1.5rem);
    }

    .bambu-fs-title {
      font-family: 'Jura', sans-serif;
      font-size: clamp(0.65rem, 1vw, 1.2rem);
      letter-spacing: 0.06em;
      transition: color .3s;
    }

    /* Tema-toggle */
    #bambu-theme-toggle {
      border: none;
      width: clamp(28px, 3.5vw, 48px);
      height: clamp(28px, 3.5vw, 48px);
      border-radius: clamp(5px, 0.6vw, 9px);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: background .2s, color .2s;
      flex-shrink: 0;
    }
    #bambu-theme-toggle svg {
      width: clamp(14px, 1.8vw, 26px) !important;
      height: clamp(14px, 1.8vw, 26px) !important;
    }
    #bambu-theme-toggle:hover { opacity: .8; }

    /* ── Dashboard fyller resten ── */
    .bambu-fs-page .bambu-wrap {
      flex: 1 1 0;
      min-height: 0;
      overflow-y: auto;
      border-radius: 0 !important;
      padding: clamp(0.75rem, 1.2vw, 1.5rem) clamp(1rem, 1.8vw, 2.5rem) !important;
    }

    /* Scrollbar */
    .bambu-wrap::-webkit-scrollbar       { width: 5px; }
    .bambu-wrap::-webkit-scrollbar-track { background: transparent; }
    .bambu-wrap::-webkit-scrollbar-thumb { border-radius: 3px; }

    /* ── Lyst tema ── */
    .bambu-fs-page.fs-light { background: #f1f1f3; }
    .bambu-fs-page.fs-light .bambu-fs-header { background: #1a1b1f; border-bottom: 1px solid #111; }
    .bambu-fs-page.fs-light .bambu-fs-header img { filter: brightness(0) invert(1); }
    .bambu-fs-page.fs-light .bambu-fs-title { color: rgba(255,255,255,.4); }
    .bambu-fs-page.fs-light #bambu-theme-toggle { background: rgba(255,255,255,.12); color: rgba(255,255,255,.7); }
    .bambu-fs-page.fs-light .bambu-wrap::-webkit-scrollbar-thumb { background: #ccc; }

    /* ── Mørkt tema ── */
    .bambu-fs-page.fs-dark { background: #18191d; }
    .bambu-fs-page.fs-dark .bambu-fs-header { background: #111215; border-bottom: 1px solid #2a2b30; }
    .bambu-fs-page.fs-dark .bambu-fs-header img { filter: brightness(0) invert(1); }
    .bambu-fs-page.fs-dark .bambu-fs-title { color: rgba(255,255,255,.3); }
    .bambu-fs-page.fs-dark #bambu-theme-toggle { background: rgba(255,255,255,.08); color: rgba(255,255,255,.6); }
    .bambu-fs-page.fs-dark .bambu-wrap::-webkit-scrollbar-thumb { background: #3a3b40; }
  </style>
</head>
<body>
<div class="bambu-fs-page" id="bambu-fs-page">

  <header class="bambu-fs-header">
    <img src="https://www.makerspaceringebu.no/wp-content/uploads/2021/05/MSR_navnetrekk-liggende-svart.png"
         alt="Makerspace Ringebu" />
    <div class="bambu-fs-right">
      <span class="bambu-fs-title">3D-print status</span>
      <button id="bambu-theme-toggle" title="Bytt tema" aria-label="Bytt tema"></button>
    </div>
  </header>

  <?php while (have_posts()) { the_post(); the_content(); } ?>

</div>

<script>
(function () {
  const page = document.getElementById('bambu-fs-page');
  const btn  = document.getElementById('bambu-theme-toggle');
  const getWrap = () => page.querySelector('.bambu-wrap');

  const MOON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1111.21 3a7 7 0 009.79 9.79z"/></svg>';
  const SUN  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>';

  function setDark(dark) {
    const wrap = getWrap();
    if (dark) {
      page.classList.replace('fs-light', 'fs-dark') || page.classList.add('fs-dark');
      page.classList.remove('fs-light');
      if (wrap) { wrap.classList.add('bambu-dark'); wrap.classList.remove('bambu-light'); }
      btn.innerHTML = SUN;
    } else {
      page.classList.replace('fs-dark', 'fs-light') || page.classList.add('fs-light');
      page.classList.remove('fs-dark');
      if (wrap) { wrap.classList.add('bambu-light'); wrap.classList.remove('bambu-dark'); }
      btn.innerHTML = MOON;
    }
    try { localStorage.setItem('bambu_theme', dark ? 'dark' : 'light'); } catch (e) {}
  }

  let saved;
  try { saved = localStorage.getItem('bambu_theme'); } catch (e) {}
  const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  setDark(saved === 'dark' || (!saved && prefersDark));

  btn.addEventListener('click', () => setDark(page.classList.contains('fs-light')));
})();
</script>

<?php wp_footer(); ?>
</body>
</html>
