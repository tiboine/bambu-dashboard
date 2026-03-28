<?php
/**
 * Plugin Name: Bambu Lab 3D Print Dashboard
 * Plugin URI: https://makerspaceringebu.no
 * Description: Sanntids 3D-print dashboard for Bambu Lab printere. Bruk shortcode [bambu_dashboard] for dashboard og [bambu_stats] for statistikk.
 * Version: 1.0.0
 * Author: Makerspace Ringebu
 * License: GPL v2 or later
 * Text Domain: bambu-dashboard
 */

if (!defined('ABSPATH')) exit;

class BambuDashboard {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_shortcode('bambu_dashboard', [$this, 'dashboard_shortcode']);
        add_shortcode('bambu_stats', [$this, 'stats_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function admin_menu() {
        add_options_page(
            'Bambu Dashboard',
            'Bambu Dashboard',
            'manage_options',
            'bambu-dashboard',
            [$this, 'settings_page']
        );
    }

    public function register_settings() {
        register_setting('bambu_dashboard', 'bambu_api_url');
        register_setting('bambu_dashboard', 'bambu_theme');
    }

    public function settings_page() {
        $api_url = get_option('bambu_api_url', 'http://localhost:5000');
        $theme = get_option('bambu_theme', 'auto');
        ?>
        <div class="wrap">
            <h1>Bambu Lab Dashboard Innstillinger</h1>
            <form method="post" action="options.php">
                <?php settings_fields('bambu_dashboard'); ?>
                <table class="form-table">
                    <tr>
                        <th>Backend API URL</th>
                        <td>
                            <input type="url" name="bambu_api_url" value="<?php echo esc_attr($api_url); ?>" class="regular-text" />
                            <p class="description">URL til Flask-backend (f.eks. http://din-server:5000)</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Standard tema</th>
                        <td>
                            <select name="bambu_theme">
                                <option value="auto" <?php selected($theme, 'auto'); ?>>Automatisk</option>
                                <option value="light" <?php selected($theme, 'light'); ?>>Lyst</option>
                                <option value="dark" <?php selected($theme, 'dark'); ?>>Morkt</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <h2>Bruk</h2>
            <p>Legg til <code>[bambu_dashboard]</code> pa en side for a vise dashboardet.</p>
            <p>Legg til <code>[bambu_stats]</code> pa en side for a vise statistikk.</p>
            <p>Valgfrie attributter: <code>[bambu_dashboard theme="dark" cols="5"]</code></p>
        </div>
        <?php
    }

    public function enqueue_assets() {
        global $post;
        if (!$post) return;

        if (has_shortcode($post->post_content, 'bambu_dashboard') ||
            has_shortcode($post->post_content, 'bambu_stats')) {
            wp_enqueue_style(
                'bambu-dashboard',
                plugin_dir_url(__FILE__) . 'assets/bambu-dashboard.css',
                [],
                '1.0.0'
            );
            wp_enqueue_script(
                'bambu-dashboard',
                plugin_dir_url(__FILE__) . 'assets/bambu-dashboard.js',
                [],
                '1.0.0',
                true
            );
            wp_localize_script('bambu-dashboard', 'bambuConfig', [
                'apiUrl' => get_option('bambu_api_url', 'http://localhost:5000'),
                'theme' => get_option('bambu_theme', 'auto'),
            ]);
        }
    }

    public function dashboard_shortcode($atts) {
        $atts = shortcode_atts([
            'theme' => '',
            'cols' => '5',
            'auto' => 'true',
        ], $atts);

        $theme_class = '';
        if ($atts['theme'] === 'dark') $theme_class = 'bambu-dark';
        elseif ($atts['theme'] === 'light') $theme_class = 'bambu-light';

        return '<div id="bambu-dashboard" class="bambu-wrap ' . esc_attr($theme_class) . '" data-cols="' . esc_attr($atts['cols']) . '" data-auto="' . esc_attr($atts['auto']) . '">
            <div class="bambu-loading">Laster 3D-print dashboard...</div>
        </div>';
    }

    public function stats_shortcode($atts) {
        $atts = shortcode_atts([
            'theme' => '',
        ], $atts);

        $theme_class = '';
        if ($atts['theme'] === 'dark') $theme_class = 'bambu-dark';
        elseif ($atts['theme'] === 'light') $theme_class = 'bambu-light';

        return '<div id="bambu-stats" class="bambu-wrap ' . esc_attr($theme_class) . '">
            <div class="bambu-loading">Laster statistikk...</div>
        </div>';
    }
}

BambuDashboard::instance();
