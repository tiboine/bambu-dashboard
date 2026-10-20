<?php
/* Template Name: Bambu Fullskjerm */
$bambu_is_stats = ($p = get_post()) && has_shortcode($p->post_content, 'bambu_stats') && !has_shortcode($p->post_content, 'bambu_dashboard');
$bambu_tag      = $bambu_is_stats ? 'Statistikk' : '3D-print status';
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= esc_html($bambu_tag) ?> – Makerspace Ringebu</title>
  <script>
    // Sett tema før første maling, så det ikke blinker mørkt → lyst. Resten håndteres av bambu-dashboard.js.
    try { if (localStorage.getItem('bambu_theme') === 'light') document.documentElement.className += ' bambu-fs-light'; } catch (e) {}
  </script>
  <?php wp_head(); ?>
  <style>
    /* Kun side-nivå; alt annet ligger i assets/bambu-dashboard.css */
    html, body { margin: 0 !important; padding: 0 !important; height: 100%; overflow: hidden; background: #05070d; }
    html.bambu-fs-light, html.bambu-fs-light body { background: #e9eef6; }
    #wpadminbar { display: none !important; }
  </style>
</head>
<body>
<div class="bambu-fs-page" id="bambu-fs-page">

  <header class="bambu-fs-header">
    <div class="bambu-fs-brand">
      <img src="https://www.makerspaceringebu.no/wp-content/uploads/2021/05/MSR_navnetrekk-liggende-svart.png"
           alt="Makerspace Ringebu" />
      <span class="bambu-fs-tag"><?= esc_html($bambu_tag) ?></span>
    </div>

    <div class="bambu-fs-stats" id="bambu-fs-stats"></div>

    <div class="bambu-fs-right">
      <div class="bambu-fs-slot" id="bambu-fs-live"></div>
      <span class="bambu-fs-clock" id="bambu-fs-clock">--:--</span>
      <button class="bambu-hud-btn" id="bambu-theme-toggle" type="button" title="Bytt tema" aria-label="Bytt tema"></button>
    </div>
  </header>

  <main class="bambu-fs-main">
    <?php while (have_posts()) { the_post(); the_content(); } ?>
  </main>

</div>

<?php wp_footer(); ?>
</body>
</html>
