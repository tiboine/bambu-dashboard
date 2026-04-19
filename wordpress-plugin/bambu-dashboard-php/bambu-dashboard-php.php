<?php
/**
 * Plugin Name: Bambu Lab 3D Print Dashboard (PHP)
 * Plugin URI: https://makerspaceringebu.no
 * Description: Sanntids 3D-print dashboard for Bambu Lab – ren PHP, ingen ekstern server nødvendig. Bruk [bambu_dashboard] og [bambu_stats].
 * Version: 1.1.4
 * Author: Makerspace Ringebu
 * License: GPL v2 or later
 * Text Domain: bambu-dashboard-php
 */

if (!defined('ABSPATH')) exit;

class BambuDashboardPHP {

    private static $instance = null;
    const API = 'https://api.bambulab.com';

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu',         [$this, 'admin_menu']);
        add_action('rest_api_init',      [$this, 'register_rest_routes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_shortcode('bambu_dashboard', [$this, 'dashboard_shortcode']);
        add_shortcode('bambu_stats',     [$this, 'stats_shortcode']);
        add_filter('theme_page_templates', [$this, 'register_page_template']);
        add_filter('template_include',     [$this, 'load_page_template']);
    }

    public function register_page_template($templates) {
        $templates['bambu-dashboard-php/templates/fullscreen.php'] = 'Bambu Fullskjerm';
        return $templates;
    }

    public function load_page_template($template) {
        global $post;
        if (!$post) return $template;
        $selected = get_post_meta($post->ID, '_wp_page_template', true);
        if ($selected === 'bambu-dashboard-php/templates/fullscreen.php') {
            $path = plugin_dir_path(__FILE__) . 'templates/fullscreen.php';
            if (file_exists($path)) return $path;
        }
        return $template;
    }

    // ── Token-håndtering ──────────────────────────────────────────────────────

    private function get_token() {
        $token   = get_option('bambu_php_token');
        $expires = (float) get_option('bambu_php_token_expires', 0);
        if ($token && time() < $expires) return $token;

        $result = $this->do_login();
        if (is_wp_error($result)) return null;
        return get_option('bambu_php_token');
    }

    private function store_token(array $data) {
        $token = $data['accessToken'] ?? $data['access_token'] ?? null;
        if (!$token) return new WP_Error('no_token', 'Fikk ikke token fra Bambu API');

        update_option('bambu_php_token',         $token);
        update_option('bambu_php_token_expires', time() + 86400 * 90);
        update_option('bambu_php_needs_verify',  false);

        // Hent user_id fra JWT-payload
        $parts = explode('.', $token);
        if (count($parts) === 3) {
            $pad     = strlen($parts[1]) % 4;
            $payload = base64_decode(strtr($parts[1], '-_', '+/') . ($pad ? str_repeat('=', 4 - $pad) : ''));
            $decoded = json_decode($payload, true);
            $uid     = $decoded['sub'] ?? $decoded['uid'] ?? null;
            if ($uid) update_option('bambu_php_user_id', (string) $uid);
        }

        return true;
    }

    private function api_headers() {
        return [
            'Authorization' => 'Bearer ' . $this->get_token(),
            'Content-Type'  => 'application/json',
        ];
    }

    // ── Autentisering ─────────────────────────────────────────────────────────

    public function do_login($email = null, $password = null) {
        if (!$email)    $email    = get_option('bambu_php_email');
        if (!$password) $password = get_option('bambu_php_password');
        if (!$email || !$password)
            return new WP_Error('no_creds', 'E-post og passord må settes i innstillingene');

        $resp = wp_remote_post(self::API . '/v1/user-service/user/login', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['account' => $email, 'password' => $password]),
            'timeout' => 15,
        ]);
        if (is_wp_error($resp)) return $resp;

        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (($body['loginType'] ?? '') === 'verifyCode') {
            update_option('bambu_php_needs_verify', true);
            // Trigger sending av verifiserings-e-post
            wp_remote_post(self::API . '/v1/user-service/user/sendemail/code', [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => wp_json_encode(['email' => $email, 'type' => 'codeLogin']),
                'timeout' => 15,
            ]);
            return new WP_Error('verify_required', 'Verifiseringskode sendt til ' . $email . ' – sjekk innboksen din');
        }

        return $this->store_token($body);
    }

    public function do_verify($code) {
        $email = get_option('bambu_php_email');
        $resp  = wp_remote_post(self::API . '/v1/user-service/user/login', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['account' => $email, 'code' => $code]),
            'timeout' => 15,
        ]);
        if (is_wp_error($resp)) return $resp;
        return $this->store_token(json_decode(wp_remote_retrieve_body($resp), true));
    }

    // ── Datahenting ──────────────────────────────────────────────────────────

    private function fetch_status() {
        $cached = get_transient('bambu_php_status');
        if ($cached !== false) return $cached;

        if (!$this->get_token()) return new WP_Error('no_token', 'Ikke innlogget');

        // Enhetsliste
        $bind = wp_remote_get(self::API . '/v1/iot-service/api/user/bind', [
            'headers' => $this->api_headers(), 'timeout' => 15,
        ]);
        if (is_wp_error($bind)) return $bind;
        $devices = json_decode(wp_remote_retrieve_body($bind), true)['devices'] ?? [];

        // Print-status
        $print_resp = wp_remote_get(self::API . '/v1/iot-service/api/user/print', [
            'headers' => $this->api_headers(), 'timeout' => 15,
        ]);
        $print_map = [];
        if (!is_wp_error($print_resp)) {
            foreach (json_decode(wp_remote_retrieve_body($print_resp), true)['devices'] ?? [] as $d) {
                $print_map[$d['dev_id']] = $d;
            }
        }

        // Nyeste cover-bilder
        $covers = [];
        foreach (($this->fetch_task_page())['hits'] as $t) {
            $did = $t['deviceId'] ?? null;
            if ($did && !isset($covers[$did]) && ($t['cover'] ?? null)) $covers[$did] = $t['cover'];
        }

        // Slå sammen
        $printers = [];
        foreach ($devices as $dev) {
            $dev_id = $dev['dev_id'];
            $pd     = $print_map[$dev_id] ?? [];

            $progress   = $pd['progress']   ?? null;
            $start_time = $pd['start_time'] ?? null;
            $prediction = $pd['prediction'] ?? null;

            $time_remaining = null;
            if ($start_time && $prediction && $progress && (float) $progress > 0) {
                $elapsed        = time() - ($start_time / 1000);
                $estimated      = $elapsed / ((float) $progress / 100);
                $time_remaining = max(0, (int)($estimated - $elapsed));
            }

            $printers[] = [
                'id'             => $dev_id,
                'name'           => $dev['name']             ?? $dev_id,
                'model'          => $dev['dev_product_name'] ?? 'Ukjent',
                'online'         => $dev['online']           ?? false,
                'print_status'   => $dev['print_status']     ?? '',
                'task_name'      => $pd['task_name']         ?? null,
                'progress'       => $progress,
                'time_remaining' => $time_remaining,
                'thumbnail'      => $covers[$dev_id] ?? ($pd['thumbnail'] ?? null),
                // MQTT-data ikke tilgjengelig i PHP-versjon
                'nozzle_temp'    => null,
                'bed_temp'       => null,
                'layer'          => null,
                'total_layers'   => null,
                'spd_lvl'        => null,
                'ams_colors'     => [],
            ];
        }

        set_transient('bambu_php_status', $printers, 30);
        return $printers;
    }

    private function fetch_today() {
        $cached = get_transient('bambu_php_today');
        if ($cached !== false) return $cached;

        if (!$this->get_token()) return ['prints' => 0, 'weight_g' => 0, 'time_s' => 0];

        $resp = wp_remote_get(self::API . '/v1/user-service/my/tasks?limit=100', [
            'headers' => $this->api_headers(), 'timeout' => 15,
        ]);
        if (is_wp_error($resp)) return ['prints' => 0, 'weight_g' => 0, 'time_s' => 0];

        $today = gmdate('Y-m-d');
        $prints = $weight = $time_s = 0;
        foreach (json_decode(wp_remote_retrieve_body($resp), true)['hits'] ?? [] as $t) {
            if (substr($t['startTime'] ?? '', 0, 10) === $today) {
                $prints++;
                $weight += $t['weight']   ?? 0;
                $time_s += $t['costTime'] ?? 0;
            }
        }

        $result = ['prints' => $prints, 'weight_g' => $weight, 'time_s' => $time_s];
        set_transient('bambu_php_today', $result, 60);
        return $result;
    }

    private function fetch_stats() {
        // Rask cache – unngår DB-kall mellom sideinnlastinger
        $quick = get_transient('bambu_php_stats');
        if ($quick !== false) return $quick;

        if (!$this->get_token()) return new WP_Error('no_token', 'Ikke innlogget');

        $dev_info = $this->get_device_info();

        // ── Ferske jobber (alltid hentet på nytt for å fange statusendringer) ──
        $recent          = [];
        $api_total       = 0;
        $after           = null;
        for ($i = 0; $i < 2; $i++) {   // 2 sider = 200 ferske jobber
            $page = $this->fetch_task_page($after);
            if (empty($page['hits'])) break;
            if ($i === 0) $api_total = $page['total'];   // faktisk total fra Bambu
            $recent = array_merge($recent, $page['hits']);
            if (count($page['hits']) < 100) break;
            $after = end($page['hits'])['id'];
        }

        $newest_recent_id = $recent[0]['id'] ?? null;
        $boundary_id      = !empty($recent) ? end($recent)['id'] : null;

        // ── Historisk cache (persistent, hentes kun én gang) ──
        $hist = get_option('bambu_php_hist_stats', [
            'stats'     => [],        // aggregerte tall per device
            'boundary'  => null,      // siste task-ID i historikk (eldste vi har sett)
            'complete'  => false,     // har vi hentet all historikk?
            'newest'    => null,      // nyeste task-ID vi aggregerte inn i historikk
            'api_total' => 0,         // faktisk total rapportert av Bambu API
        ]);

        // Oppdater api_total alltid hvis vi fikk et tall
        if ($api_total > 0) $hist['api_total'] = $api_total;

        if (!$hist['complete'] && $boundary_id) {
            // Hent historikk i biter – maks 20 sek per forespørsel for å unngå timeout.
            // Sjekker også at side-ID endrer seg (stopper ved paginerings-loop).
            $after    = $hist['boundary'] ?: $boundary_id;
            $deadline = microtime(true) + 20;
            $seen_ids = [];   // loop-deteksjon
            while (microtime(true) < $deadline) {
                $page      = $this->fetch_task_page($after);
                $first_id  = $page['hits'][0]['id'] ?? null;
                if (empty($page['hits']) || $first_id === null) {
                    $hist['complete'] = true;
                    break;
                }
                // Hvis vi ser samme første ID igjen betyr det at ?after= ikke fungerer
                if (isset($seen_ids[$first_id])) {
                    $hist['complete'] = true;   // ingen flere unike sider
                    break;
                }
                $seen_ids[$first_id] = true;
                foreach ($page['hits'] as $task) {
                    $this->merge_task($hist['stats'], $task, $dev_info);
                }
                $hist['boundary'] = end($page['hits'])['id'];
                if (count($page['hits']) < 100) { $hist['complete'] = true; break; }
                $after = $hist['boundary'];
            }
            if (!isset($hist['newest'])) $hist['newest'] = $boundary_id;
            update_option('bambu_php_hist_stats', $hist);
        } elseif ($hist['complete'] && $boundary_id && $hist['newest'] !== $boundary_id) {
            // Historikken er komplett men grensen har beveget seg (nye jobber siden sist)
            $after = $boundary_id;
            $stop  = $hist['newest'];
            $done  = false;
            for ($i = 0; $i < 5 && !$done; $i++) {
                $page = $this->fetch_task_page($after);
                if (empty($page['hits'])) break;
                foreach ($page['hits'] as $task) {
                    if ($task['id'] === $stop) { $done = true; break; }
                    $this->merge_task($hist['stats'], $task, $dev_info);
                }
                if (count($page['hits']) < 100) break;
                $after = end($page['hits'])['id'];
            }
            $hist['newest'] = $boundary_id;
            update_option('bambu_php_hist_stats', $hist);
        }

        // ── Kombiner historikk + ferske jobber ──
        $stats = $hist['stats'];
        foreach ($recent as $task) {
            $this->merge_task($stats, $task, $dev_info);
        }

        $total  = array_sum(array_column($stats, 'total_prints'));
        $result = [
            'devices'        => array_values($stats),
            'total_tasks'    => $total,
            'api_total'      => $hist['api_total'],   // faktisk total fra Bambu (for å oppdage avkorting)
            'data_complete'  => $hist['complete'],
            'cache_info'     => [
                'history_complete' => $hist['complete'],
                'recent_fetched'   => count($recent),
            ],
        ];

        // Hvis historikken ikke er ferdig lastet, la transient utløpe raskere så neste sideinnlasting fortsetter
        $ttl = $hist['complete'] ? 300 : 30;
        set_transient('bambu_php_stats', $result, $ttl);
        return $result;
    }

    /** Hent én side med tasks fra Bambu API – returnerer ['hits'=>[], 'total'=>N] */
    private function fetch_task_page($after = null) {
        $url  = self::API . '/v1/user-service/my/tasks?limit=100' . ($after ? '&after=' . urlencode($after) : '');
        $resp = wp_remote_get($url, ['headers' => $this->api_headers(), 'timeout' => 20]);
        if (is_wp_error($resp)) return ['hits' => [], 'total' => 0];
        $body = json_decode(wp_remote_retrieve_body($resp), true) ?? [];
        return [
            'hits'  => $body['hits']  ?? [],
            'total' => (int)($body['total'] ?? 0),
        ];
    }

    /** Aggreger én task inn i stats-array */
    private function merge_task(array &$stats, array $task, array $dev_info) {
        $did = $task['deviceId'] ?? 'unknown';
        if (!isset($stats[$did])) {
            $stats[$did] = [
                'name'           => $dev_info[$did]['name']  ?? $did,
                'model'          => $dev_info[$did]['model'] ?? 'Ukjent',
                'total_prints'   => 0,
                'successful'     => 0,
                'failed'         => 0,
                'total_time_s'   => 0,
                'total_weight_g' => 0,
            ];
        }
        $s = &$stats[$did];
        $s['total_prints']++;
        ($task['status'] ?? 0) === 2 ? $s['successful']++ : $s['failed']++;
        $s['total_time_s']   += $task['costTime'] ?? 0;
        $s['total_weight_g'] += $task['weight']   ?? 0;
    }

    /** Hent enhetsnavn og modell */
    private function get_device_info() {
        $bind = wp_remote_get(self::API . '/v1/iot-service/api/user/bind', [
            'headers' => $this->api_headers(), 'timeout' => 15,
        ]);
        $info = [];
        if (!is_wp_error($bind)) {
            foreach (json_decode(wp_remote_retrieve_body($bind), true)['devices'] ?? [] as $d) {
                $info[$d['dev_id']] = [
                    'name'  => $d['name']             ?? $d['dev_id'],
                    'model' => $d['dev_product_name'] ?? 'Ukjent',
                ];
            }
        }
        return $info;
    }

    // ── WP REST API ──────────────────────────────────────────────────────────

    public function register_rest_routes() {
        $public = ['permission_callback' => '__return_true'];
        $admin  = ['permission_callback' => function() { return current_user_can('manage_options'); }];

        register_rest_route('bambu/v1', '/status',     array_merge(['methods' => 'GET', 'callback' => [$this, 'rest_status']],    $public));
        register_rest_route('bambu/v1', '/today',      array_merge(['methods' => 'GET', 'callback' => [$this, 'rest_today']],     $public));
        register_rest_route('bambu/v1', '/stats',      array_merge(['methods' => 'GET', 'callback' => [$this, 'rest_stats']],     $public));
        register_rest_route('bambu/v1', '/export',     array_merge(['methods' => 'GET', 'callback' => [$this, 'rest_export']],    $public));
        register_rest_route('bambu/v1', '/raw-sample', array_merge(['methods' => 'GET', 'callback' => [$this, 'rest_raw_sample']],$public));
        register_rest_route('bambu/v1', '/recent',     array_merge(['methods' => 'GET', 'callback' => [$this, 'rest_recent']],    $public));
        register_rest_route('bambu/v1', '/oldest',     array_merge(['methods' => 'GET', 'callback' => [$this, 'rest_oldest']],    $public));
        register_rest_route('bambu/v1', '/verify',     array_merge(['methods' => 'POST','callback' => [$this, 'rest_verify']],    $admin));
    }

    public function rest_status() {
        $printers = $this->fetch_status();
        if (is_wp_error($printers)) {
            return rest_ensure_response([
                'printers'         => [],
                'error'            => $printers->get_error_message(),
                'needs_verify_code'=> (bool) get_option('bambu_php_needs_verify'),
                'mqtt_connected'   => false,
            ]);
        }
        return rest_ensure_response([
            'printers'         => $printers,
            'error'            => null,
            'needs_verify_code'=> false,
            'mqtt_connected'   => false,
        ]);
    }

    public function rest_today() {
        return rest_ensure_response($this->fetch_today());
    }

    public function rest_stats() {
        $stats = $this->fetch_stats();
        if (is_wp_error($stats)) return rest_ensure_response(['error' => $stats->get_error_message()]);
        return rest_ensure_response($stats);
    }

    /** CSV-eksport av aggregert statistikk per printer */
    public function rest_export() {
        $stats = $this->fetch_stats();
        if (is_wp_error($stats)) {
            return new WP_Error('stats_error', $stats->get_error_message(), ['status' => 500]);
        }

        $rows   = [];
        $rows[] = ['Printer', 'Modell', 'Prints', 'Vellykket', 'Feilet', 'Suksessrate %', 'Printtid (timer)', 'Filament (g)', 'Filament (kg)'];
        foreach ($stats['devices'] as $d) {
            $rate  = $d['total_prints'] > 0 ? round($d['successful'] / $d['total_prints'] * 100, 1) : 0;
            $hours = round($d['total_time_s'] / 3600, 1);
            $rows[] = [
                $d['name'],
                $d['model'],
                $d['total_prints'],
                $d['successful'],
                $d['failed'],
                $rate,
                $hours,
                round($d['total_weight_g'], 0),
                round($d['total_weight_g'] / 1000, 3),
            ];
        }

        $out = '';
        foreach ($rows as $row) {
            $out .= implode(';', array_map(function($v) {
                return '"' . str_replace('"', '""', $v) . '"';
            }, $row)) . "\r\n";
        }

        // Send CSV direkte – omgår WP REST JSON-wrapping
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="bambu-stats-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-cache');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
        echo $out;
        exit;
    }

    /** Returnerer 5 rå tasks slik Bambu API sender dem – for å sjekke feltenheter */
    public function rest_raw_sample() {
        if (!$this->get_token()) return new WP_Error('no_token', 'Ikke innlogget', ['status' => 401]);
        $page = $this->fetch_task_page();
        $sample = array_slice($page['hits'], 0, 5);
        return rest_ensure_response([
            'api_total'  => $page['total'],
            'sample'     => $sample,
            'note'       => 'costTime er i sekunder hvis verdi < 100000, millisekunder hvis > 1000000',
        ]);
    }

    /** Finner den eldste tilgjengelige printen i Bambu Cloud */
    public function rest_oldest() {
        if (!$this->get_token()) return new WP_Error('no_token', 'Ikke innlogget', ['status' => 401]);

        // Hent første side for å finne totalt antall og startpunkt for paginering
        $first = $this->fetch_task_page();
        $total = $first['total'];
        if (empty($first['hits'])) return rest_ensure_response(['error' => 'Ingen tasks funnet']);

        // Bla gjennom til siste side
        $after = null;
        $last_hits = $first['hits'];
        $pages_fetched = 1;
        while (true) {
            $next_after = end($last_hits)['id'] ?? null;
            if (!$next_after) break;
            $page = $this->fetch_task_page($next_after);
            if (empty($page['hits'])) break;
            // Loop-deteksjon
            if ($page['hits'][0]['id'] === $last_hits[0]['id']) break;
            $last_hits = $page['hits'];
            $pages_fetched++;
            if (count($page['hits']) < 100) break;
        }

        $oldest = end($last_hits);
        $cost_s = (int)($oldest['costTime'] ?? 0);
        return rest_ensure_response([
            'api_total'    => $total,
            'pages_fetched'=> $pages_fetched,
            'oldest_print' => [
                'printer'  => $oldest['deviceName']  ?? $oldest['deviceId'] ?? '?',
                'model'    => $oldest['deviceModel'] ?? '?',
                'title'    => $oldest['title']       ?? '',
                'status'   => $oldest['status'] === 2 ? 'Fullført' : 'Feilet/Annet',
                'start'    => $oldest['startTime']   ?? '',
                'end'      => $oldest['endTime']     ?? '',
                'duration' => intdiv($cost_s, 3600) . 't ' . intdiv($cost_s % 3600, 60) . 'm',
                'weight_g' => $oldest['weight']      ?? 0,
            ],
        ]);
    }

    /** Returnerer de N siste printene, rent og lesbart – for manuell verifisering */
    public function rest_recent(WP_REST_Request $req) {
        if (!$this->get_token()) return new WP_Error('no_token', 'Ikke innlogget', ['status' => 401]);
        $n    = min((int)($req->get_param('n') ?? 2), 50);
        $page = $this->fetch_task_page();

        $result = [];
        foreach (array_slice($page['hits'], 0, $n) as $t) {
            $start   = $t['startTime'] ?? '';
            $end     = $t['endTime']   ?? '';
            $cost_s  = (int)($t['costTime'] ?? 0);
            $h = intdiv($cost_s, 3600);
            $m = intdiv($cost_s % 3600, 60);
            $result[] = [
                'printer'    => $t['deviceName']  ?? $t['deviceId'] ?? '?',
                'model'      => $t['deviceModel'] ?? '?',
                'title'      => $t['title']       ?? '',
                'status'     => $t['status'] === 2 ? 'Fullført' : ($t['status'] === 3 ? 'Feilet' : 'Ukjent (' . $t['status'] . ')'),
                'start'      => $start,
                'end'        => $end,
                'duration'   => $h . 't ' . $m . 'm  (costTime=' . $cost_s . 's)',
                'weight_g'   => $t['weight']  ?? 0,
                'length_mm'  => $t['length']  ?? 0,
            ];
        }

        return rest_ensure_response([
            'api_total'       => $page['total'],
            'api_total_note'  => 'Dette er alt Bambu Cloud har lagret – eldre prints er ikke tilgjengelig via API',
            'showing'         => count($result),
            'prints'          => $result,
        ]);
    }

    public function rest_verify(WP_REST_Request $req) {
        $code = sanitize_text_field($req->get_param('code') ?? '');
        if (!$code) return new WP_Error('no_code', 'Ingen kode oppgitt', ['status' => 400]);
        $result = $this->do_verify($code);
        if (is_wp_error($result)) return new WP_Error('verify_failed', $result->get_error_message(), ['status' => 400]);
        delete_transient('bambu_php_status');
        return rest_ensure_response(['ok' => true]);
    }

    // ── Admin ────────────────────────────────────────────────────────────────

    public function admin_menu() {
        add_options_page(
            'Bambu Dashboard (PHP)', 'Bambu Dashboard (PHP)',
            'manage_options', 'bambu-dashboard-php', [$this, 'settings_page']
        );
    }

    public function settings_page() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('bambu_php_settings')) {

            if (isset($_POST['bambu_php_dash_link_url'])) {
                update_option('bambu_php_dash_link_url',    esc_url_raw($_POST['bambu_php_dash_link_url']));
                update_option('bambu_php_dash_link_label',  sanitize_text_field($_POST['bambu_php_dash_link_label']));
                update_option('bambu_php_stats_link_url',   esc_url_raw($_POST['bambu_php_stats_link_url']));
                update_option('bambu_php_stats_link_label', sanitize_text_field($_POST['bambu_php_stats_link_label']));
                echo '<div class="notice notice-success"><p>✅ Lenker lagret.</p></div>';
            }

            if (isset($_POST['bambu_php_email'])) {
                update_option('bambu_php_email', sanitize_email($_POST['bambu_php_email']));
                if (!empty($_POST['bambu_php_password'])) {
                    update_option('bambu_php_password', sanitize_text_field($_POST['bambu_php_password']));
                }
                delete_transient('bambu_php_status');
                delete_transient('bambu_php_today');
                delete_transient('bambu_php_stats');
                // NB: hist_stats slettes IKKE – akkumulert historikk bevares ved ny innlogging

                $result = $this->do_login();
                if (is_wp_error($result)) {
                    $cls = $result->get_error_code() === 'verify_required' ? 'notice-warning' : 'notice-error';
                    echo '<div class="notice ' . $cls . '"><p>' . esc_html($result->get_error_message()) . '</p></div>';
                } else {
                    echo '<div class="notice notice-success"><p>✅ Innlogget og token lagret.</p></div>';
                }
            }

            if (!empty($_POST['bambu_verify_code'])) {
                $code   = sanitize_text_field($_POST['bambu_verify_code']);
                $result = $this->do_verify($code);
                if (is_wp_error($result)) {
                    echo '<div class="notice notice-error"><p>Feil: ' . esc_html($result->get_error_message()) . '</p></div>';
                } else {
                    echo '<div class="notice notice-success"><p>✅ Verifisert og innlogget.</p></div>';
                }
            }

            if (isset($_POST['bambu_reset_cache'])) {
                // Myk reset: fjern bare transient, behold akkumulert historikk
                delete_transient('bambu_php_stats');
                delete_transient('bambu_php_today');
                delete_transient('bambu_php_status');
                // Marker historikken som ikke-komplett så nye tasks hentes på nytt,
                // men behold alle aggregerte tall vi allerede har
                $hist = get_option('bambu_php_hist_stats', []);
                if ($hist) {
                    $hist['complete'] = false;
                    $hist['newest']   = null;
                    $hist['boundary'] = null;
                    update_option('bambu_php_hist_stats', $hist);
                }
                echo '<div class="notice notice-success"><p>✅ Cache nullstilt. Akkumulert historikk er bevart – nye tasks hentes på nytt.</p></div>';
            }

            if (isset($_POST['bambu_wipe_history'])) {
                // Hard reset: slett ALT inkl. akkumulert historikk (kun ved korrupt data)
                delete_transient('bambu_php_stats');
                delete_transient('bambu_php_today');
                delete_transient('bambu_php_status');
                delete_option('bambu_php_hist_stats');
                echo '<div class="notice notice-warning"><p>⚠️ All statistikk-historikk slettet. Data hentes på nytt fra Bambu (kun tasks Bambu har lagret er tilgjengelig).</p></div>';
            }
        }

        $email        = get_option('bambu_php_email', '');
        $needs_verify = get_option('bambu_php_needs_verify', false);
        $has_token    = (bool) get_option('bambu_php_token');
        $expires      = (float) get_option('bambu_php_token_expires', 0);
        $rest_url     = rest_url('bambu/v1/');
        $dash_link_url    = get_option('bambu_php_dash_link_url', '');
        $dash_link_label  = get_option('bambu_php_dash_link_label', '');
        $stats_link_url   = get_option('bambu_php_stats_link_url', '');
        $stats_link_label = get_option('bambu_php_stats_link_label', '');
        ?>
        <div class="wrap">
            <h1>Bambu Lab Dashboard <span style="font-size:0.7em;opacity:.6">(PHP)</span></h1>

            <?php if ($has_token && $expires > time()): ?>
            <div class="notice notice-success inline" style="margin-bottom:1rem">
                <p>✅ Innlogget · Token utløper om <?= (int)(($expires - time()) / 86400) ?> dager</p>
            </div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('bambu_php_settings'); ?>
                <h2>Lenkeknapper</h2>
                <p style="color:#666">Vises øverst til høyre på hver side – bruk til å lenke mellom dashboard og statistikk.</p>
                <table class="form-table">
                    <tr><th colspan="2" style="padding-bottom:0"><strong>Dashboard-siden</strong></th></tr>
                    <tr>
                        <th scope="row">Knapptekst</th>
                        <td><input type="text" name="bambu_php_dash_link_label" value="<?= esc_attr($dash_link_label) ?>" class="regular-text" placeholder="f.eks. Statistikk" /></td>
                    </tr>
                    <tr>
                        <th scope="row">URL</th>
                        <td><input type="url" name="bambu_php_dash_link_url" value="<?= esc_attr($dash_link_url) ?>" class="regular-text" placeholder="https://..." /></td>
                    </tr>
                    <tr><th colspan="2" style="padding-bottom:0;padding-top:1.5em"><strong>Statistikk-siden</strong></th></tr>
                    <tr>
                        <th scope="row">Knapptekst</th>
                        <td><input type="text" name="bambu_php_stats_link_label" value="<?= esc_attr($stats_link_label) ?>" class="regular-text" placeholder="f.eks. Dashboard" /></td>
                    </tr>
                    <tr>
                        <th scope="row">URL</th>
                        <td><input type="url" name="bambu_php_stats_link_url" value="<?= esc_attr($stats_link_url) ?>" class="regular-text" placeholder="https://..." /></td>
                    </tr>
                </table>
                <?php submit_button('Lagre lenker', 'secondary'); ?>
            </form>

            <hr>
            <form method="post">
                <?php wp_nonce_field('bambu_php_settings'); ?>
                <h2>Bambu Lab konto</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">E-post</th>
                        <td><input type="email" name="bambu_php_email" value="<?= esc_attr($email) ?>" class="regular-text" required /></td>
                    </tr>
                    <tr>
                        <th scope="row">Passord</th>
                        <td>
                            <input type="password" name="bambu_php_password" class="regular-text"
                                placeholder="<?= $has_token ? '(lagret – la stå tom for å beholde)' : 'Skriv inn passord' ?>" />
                        </td>
                    </tr>
                </table>
                <?php submit_button('Lagre og logg inn'); ?>
            </form>

            <?php if ($needs_verify): ?>
            <hr>
            <h2>Verifiseringskode</h2>
            <p>Bambu Lab krever e-postverifisering. Sjekk innboksen din og skriv inn koden under.</p>
            <form method="post">
                <?php wp_nonce_field('bambu_php_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Kode</th>
                        <td><input type="text" name="bambu_verify_code" class="regular-text" placeholder="123456" /></td>
                    </tr>
                </table>
                <?php submit_button('Bekreft kode'); ?>
            </form>
            <?php endif; ?>

            <hr>
            <h2>Statistikk-cache</h2>
            <?php
            $hist = get_option('bambu_php_hist_stats', null);
            $cached_tasks = 0;
            if ($hist) {
                foreach ($hist['stats'] ?? [] as $d) $cached_tasks += $d['total_prints'] ?? 0;
            }
            ?>
            <p>
                Lagrede historiske prints: <strong><?= number_format($cached_tasks) ?></strong>
                · Status: <strong><?= $hist ? ($hist['complete'] ? '✅ Komplett' : '⏳ Laster...') : '–' ?></strong>
                <?php if ($hist && !empty($hist['api_total'])): ?>
                · Bambu rapporterer totalt: <strong><?= number_format($hist['api_total']) ?></strong>
                <?php endif; ?>
            </p>
            <p style="color:#666;font-size:0.9em">
                ℹ️ Akkumulerte stats lagres permanent i WordPress og overlever selv om Bambu sletter eldre historikk.<br>
                Bruk "Myk reset" for å hente inn nye tasks. "Hard reset" sletter alt – bruk kun hvis data er korrupt.
            </p>
            <form method="post" style="display:inline-block;margin-right:1rem">
                <?php wp_nonce_field('bambu_php_settings'); ?>
                <input type="hidden" name="bambu_reset_cache" value="1" />
                <?php submit_button('↺ Myk reset (bevar historikk)', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" style="display:inline-block" onsubmit="return confirm('Er du sikker? Dette sletter ALL akkumulert historikk permanent.')">
                <?php wp_nonce_field('bambu_php_settings'); ?>
                <input type="hidden" name="bambu_wipe_history" value="1" />
                <?php submit_button('⚠ Hard reset (slett alt)', 'delete', 'submit', false); ?>
            </form>

            <hr>
            <h2>Bruk</h2>
            <table class="widefat striped" style="max-width:600px">
                <tbody>
                    <tr><td><code>[bambu_dashboard]</code></td><td>Live printer-status</td></tr>
                    <tr><td><code>[bambu_stats]</code></td><td>Historisk statistikk</td></tr>
                    <tr><td><code>[bambu_dashboard cols="4" theme="dark"]</code></td><td>Valgfrie attributter</td></tr>
                </tbody>
            </table>
            <p style="margin-top:1rem"><strong>REST API URL:</strong> <code><?= esc_html($rest_url) ?></code></p>
            <p style="color:#888;font-size:0.85em">
                ℹ️ Denne versjonen bruker Bambu Cloud REST API (oppdateres hvert 30s).
                Live temperatur og lagnummer er kun tilgjengelig i Flask-versjonen (via MQTT).
            </p>
        </div>
        <?php
    }

    // ── Frontend ─────────────────────────────────────────────────────────────

    public function enqueue_assets() {
        global $post;
        if (!$post) return;
        if (!has_shortcode($post->post_content, 'bambu_dashboard') &&
            !has_shortcode($post->post_content, 'bambu_stats')) return;

        wp_enqueue_style('bambu-php-css',
            plugin_dir_url(__FILE__) . 'assets/bambu-dashboard.css', [], '1.0.0');

        wp_enqueue_script('bambu-php-js',
            plugin_dir_url(__FILE__) . 'assets/bambu-dashboard.js', [], '1.0.0', true);

        wp_localize_script('bambu-php-js', 'bambuConfig', [
            'restUrl'   => rest_url(''),
            'theme'     => 'auto',
            'php_mode'  => true,
            'dashLinkUrl'   => esc_url(get_option('bambu_php_dash_link_url', '')),
            'dashLinkLabel' => esc_html(get_option('bambu_php_dash_link_label', '')),
            'statsLinkUrl'  => esc_url(get_option('bambu_php_stats_link_url', '')),
            'statsLinkLabel'=> esc_html(get_option('bambu_php_stats_link_label', '')),
        ]);
    }

    public function dashboard_shortcode($atts) {
        $atts = shortcode_atts(['theme' => '', 'cols' => '5', 'auto' => 'true'], $atts);
        $cls  = $atts['theme'] === 'dark' ? 'bambu-dark' : ($atts['theme'] === 'light' ? 'bambu-light' : '');
        return sprintf(
            '<div id="bambu-dashboard" class="bambu-wrap %s" data-cols="%s" data-auto="%s"><div class="bambu-loading">Laster 3D-print dashboard...</div></div>',
            esc_attr($cls), esc_attr($atts['cols']), esc_attr($atts['auto'])
        );
    }

    public function stats_shortcode($atts) {
        $atts = shortcode_atts(['theme' => ''], $atts);
        $cls  = $atts['theme'] === 'dark' ? 'bambu-dark' : ($atts['theme'] === 'light' ? 'bambu-light' : '');
        return sprintf(
            '<div id="bambu-stats" class="bambu-wrap %s"><div class="bambu-loading">Laster statistikk...</div></div>',
            esc_attr($cls)
        );
    }
}

BambuDashboardPHP::instance();
