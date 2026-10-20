<?php
/**
 * Plugin Name: Bambu Lab 3D Print Dashboard (PHP)
 * Plugin URI: https://makerspaceringebu.no
 * Description: Sanntids 3D-print dashboard for Bambu Lab – ren PHP, ingen ekstern server nødvendig. Bruk [bambu_dashboard] og [bambu_stats].
 * Version: 1.5.3
 * Author: Makerspace Ringebu
 * License: GPL v2 or later
 * Text Domain: bambu-dashboard-php
 */

if (!defined('ABSPATH')) exit;

class BambuDashboardPHP {

    private static $instance = null;
    const API        = 'https://api.bambulab.com';
    const VERSION    = '1.5.3';
    const DB_VERSION = '1';
    const TEMPLATE   = 'bambu-dashboard-php/templates/fullscreen.php';
    const CRON_HOOK  = 'bambu_php_cron';
    // Bambu task-status: 1 = pågår, 2 = fullført, 3 = feilet/avbrutt
    const STATUS_RUNNING = 1;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    /** Aktivering: opprett tabell og planlegg cron */
    public static function activate() {
        $self = self::instance();
        $self->create_table();
        update_option('bambu_php_db_version', self::DB_VERSION);
        $self->schedule_cron();
    }

    /** Deaktivering: fjern planlagt cron */
    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    private function __construct() {
        add_action('plugins_loaded',     [$this, 'maybe_create_table']);
        add_action('init',               [$this, 'schedule_cron']);
        add_action(self::CRON_HOOK,      [$this, 'cron_sync']);
        add_action('admin_menu',         [$this, 'admin_menu']);
        add_action('rest_api_init',      [$this, 'register_rest_routes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_shortcode('bambu_dashboard', [$this, 'dashboard_shortcode']);
        add_shortcode('bambu_stats',     [$this, 'stats_shortcode']);
        add_filter('theme_page_templates', [$this, 'register_page_template']);
        add_filter('template_include',     [$this, 'load_page_template']);
    }

    public function register_page_template($templates) {
        $templates[self::TEMPLATE] = 'Bambu Fullskjerm';
        return $templates;
    }

    private function uses_fullscreen_template($post) {
        return $post && get_post_meta($post->ID, '_wp_page_template', true) === self::TEMPLATE;
    }

    public function load_page_template($template) {
        global $post;
        if (!$post) return $template;
        if ($this->uses_fullscreen_template($post)) {
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

        // Ikke auto-logg inn mens vi venter på verifiseringskode – hver innlogging
        // sender ny e-post og ugyldiggjør forrige kode.
        if (get_option('bambu_php_needs_verify')) return null;

        // Struping: maks ett automatisk innloggingsforsøk per 5 min
        if (get_transient('bambu_php_login_throttle')) return null;
        set_transient('bambu_php_login_throttle', 1, 5 * MINUTE_IN_SECONDS);

        $result = $this->do_login();
        if (is_wp_error($result)) return null;
        return get_option('bambu_php_token') ?: null;
    }

    /** Glem lagret token (f.eks. etter HTTP 401 fra Bambu) */
    private function forget_token() {
        delete_option('bambu_php_token');
        delete_option('bambu_php_token_expires');
    }

    private function store_token($data) {
        if (!is_array($data)) $data = [];
        $token = $data['accessToken'] ?? $data['access_token'] ?? null;
        if (!$token) return new WP_Error('no_token', 'Fikk ikke token fra Bambu API');

        // Bruk expiresIn (sekunder) fra API når den finnes, ellers 90 dager
        $ttl = (int)($data['expiresIn'] ?? $data['expires_in'] ?? 0);
        if ($ttl <= 0) $ttl = 86400 * 90;

        update_option('bambu_php_token',         $token);
        update_option('bambu_php_token_expires', time() + $ttl);
        update_option('bambu_php_needs_verify',  false);
        delete_transient('bambu_php_login_throttle');

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

    /**
     * GET mot Bambu API. Returnerer dekodet JSON-array eller WP_Error.
     * Ved HTTP 401 slettes token og vi prøver én ny innlogging (respekterer strupingen).
     */
    private function api_get($path, $timeout = 15, $retry = true) {
        if (!$this->get_token()) return new WP_Error('no_token', 'Ikke innlogget');

        $resp = wp_remote_get(self::API . $path, ['headers' => $this->api_headers(), 'timeout' => $timeout]);
        if (is_wp_error($resp)) return $resp;

        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code === 401) {
            $this->forget_token();
            if ($retry) return $this->api_get($path, $timeout, false);
            return new WP_Error('unauthorized', 'Bambu API avviste token (401)');
        }
        if ($code < 200 || $code >= 300) {
            return new WP_Error('http_' . $code, 'Bambu API svarte med HTTP ' . $code);
        }

        $body = json_decode(wp_remote_retrieve_body($resp), true);
        return is_array($body) ? $body : [];
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
            $sent = $this->send_verify_code($email);
            if (is_wp_error($sent)) return $sent;
            return new WP_Error('verify_required', 'Verifiseringskode sendt til ' . $email . ' – sjekk innboksen (og søppelpost)');
        }
        if (empty($body['accessToken']) && empty($body['access_token'])) {
            $code = (int) wp_remote_retrieve_response_code($resp);
            $msg  = $body['message'] ?? $body['error'] ?? '';
            return new WP_Error('login_failed', 'Innlogging feilet (HTTP ' . $code . ')' . ($msg ? ': ' . mb_substr((string) $msg, 0, 200) : ''));
        }

        return $this->store_token($body);
    }

    /** Be Bambu sende innloggingskode på e-post. Returnerer true eller WP_Error med Bambus svar. */
    public function send_verify_code($email = null) {
        if (!$email) $email = get_option('bambu_php_email');
        if (!$email) return new WP_Error('no_creds', 'E-post må settes i innstillingene');
        $resp = wp_remote_post(self::API . '/v1/user-service/user/sendemail/code', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['email' => $email, 'type' => 'codeLogin']),
            'timeout' => 15,
        ]);
        if (is_wp_error($resp)) {
            return new WP_Error('send_failed', 'Kunne ikke kontakte Bambu: ' . $resp->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) {
            $raw  = wp_remote_retrieve_body($resp);
            $body = json_decode($raw, true);
            $msg  = is_array($body) ? ($body['message'] ?? $body['error'] ?? '') : '';
            if (!$msg && stripos($raw, 'cloudflare') !== false) $msg = 'blokkert av Cloudflare (serverens IP er sannsynligvis sperret av Bambu)';
            return new WP_Error('send_failed', 'Bambu avviste utsending av kode (HTTP ' . $code . ')' . ($msg ? ': ' . mb_substr((string) $msg, 0, 200) : ''));
        }
        return true;
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

        // Enhetsliste – ved feil returneres WP_Error og ingenting caches
        $bind = $this->api_get('/v1/iot-service/api/user/bind');
        if (is_wp_error($bind)) return $bind;
        $devices = $bind['devices'] ?? [];
        $this->cache_device_info($devices);

        // Print-status
        $print_resp = $this->api_get('/v1/iot-service/api/user/print');
        $print_map = [];
        if (!is_wp_error($print_resp)) {
            foreach ($print_resp['devices'] ?? [] as $d) {
                if (!empty($d['dev_id'])) $print_map[$d['dev_id']] = $d;
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
            $dev_id = $dev['dev_id'] ?? null;
            if (!$dev_id) continue;
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
        $empty = ['prints' => 0, 'active' => 0, 'weight_g' => 0, 'time_s' => 0, 'length_cm' => 0];

        // Task-listen caches i 60 s; selve summen regnes ut på hvert kall så
        // pågående prints følger fremdriften (status caches selv i 30 s).
        $tasks = get_transient('bambu_php_today_tasks');
        if ($tasks === false) {
            $resp = $this->api_get('/v1/user-service/my/tasks?limit=100');
            if (is_wp_error($resp)) return $empty;
            $tasks = is_array($resp['hits'] ?? null) ? $resp['hits'] : [];
            set_transient('bambu_php_today_tasks', $tasks, 60);
        }

        // Fremdrift per printer som printer akkurat nå
        $live = [];
        $printers = $this->fetch_status();
        if (!is_wp_error($printers)) {
            foreach ((array) $printers as $p) {
                $st = strtoupper((string) ($p['print_status'] ?? ''));
                if (in_array($st, ['RUNNING', 'PRINTING', 'PREPARE', 'PAUSED', 'PAUSE'], true)) {
                    $live[(string) ($p['id'] ?? '')] = max(0, min(100, (float) ($p['progress'] ?? 0))) / 100;
                }
            }
        }

        // Dagsgrense i nettstedets tidssone (ikke UTC)
        $today     = wp_date('Y-m-d');
        $day_start = (new DateTimeImmutable('today', wp_timezone()))->getTimestamp();
        $now       = time();
        $r = $empty;
        $counted_live = [];
        foreach ($tasks as $t) {
            $ts      = $this->to_timestamp($t['startTime'] ?? null);
            $dev     = (string) ($t['deviceId'] ?? '');
            $running = in_array((int) ($t['status'] ?? -1), [0, self::STATUS_RUNNING], true);

            if ($running && isset($live[$dev]) && !isset($counted_live[$dev])) {
                // Pågående: planlagt forbruk × fremdrift, tid = medgått tid i dag
                $counted_live[$dev] = true;
                $pct = $live[$dev];
                $r['prints']++;
                $r['active']++;
                $r['weight_g']  += ($t['weight'] ?? 0) * $pct;
                $r['length_cm'] += ($t['length'] ?? 0) * $pct;
                if ($ts) $r['time_s'] += max(0, $now - max($ts, $day_start));
                continue;
            }
            if ($running) continue; // gammel/ukjent pågående task – ikke tell forbruk
            if ($ts && wp_date('Y-m-d', $ts) === $today) {
                $r['prints']++;
                $r['weight_g']  += $t['weight']   ?? 0;
                $r['time_s']    += $t['costTime'] ?? 0;
                $r['length_cm'] += $t['length']   ?? 0;
            }
        }
        // Printere som printer, men der tasken ikke er synlig ennå (nettopp startet)
        foreach ($live as $dev => $pct) {
            if (!isset($counted_live[$dev])) { $r['prints']++; $r['active']++; }
        }

        $r['weight_g']  = round($r['weight_g'], 1);
        $r['length_cm'] = round($r['length_cm'], 1);
        return $r;
    }

    private function fetch_stats() {
        $quick = get_transient('bambu_php_stats_v2');
        if ($quick !== false) return $quick;

        if (!$this->get_token()) return new WP_Error('no_token', 'Ikke innlogget');

        $dev_info = $this->get_device_info();

        $hist = wp_parse_args((array) get_option('bambu_php_hist_stats', []), [
            'boundary'  => null,
            'complete'  => false,
            'api_total' => 0,
        ]);

        // Fetch recent tasks (always, to catch new prints)
        $recent    = [];
        $api_total = 0;
        $after     = null;
        for ($i = 0; $i < 2; $i++) {
            $page = $this->fetch_task_page($after);
            if (empty($page['hits'])) break;
            if ($i === 0) $api_total = $page['total'];
            $recent = array_merge($recent, $page['hits']);
            if (count($page['hits']) < 100) break;
            $after = end($page['hits'])['id'] ?? null;
            if (!$after) break;
        }
        if ($api_total > 0) $hist['api_total'] = $api_total;

        foreach ($recent as $task) {
            $this->insert_print($task, $dev_info);
        }

        // Historikk-henting hvis ikke komplett (upsert håndterer duplikater).
        // Lås hindrer at samtidige /stats- eller /export-kall starter hver sin lange gjennomgang.
        $crawl_locked = (bool) get_transient('bambu_php_crawl_lock');
        if (!$hist['complete'] && !empty($recent) && !$crawl_locked) {
            set_transient('bambu_php_crawl_lock', 1, 60);
            $after      = $hist['boundary'] ?: (end($recent)['id'] ?? null);
            $deadline   = microtime(true) + 20;
            $seen_pages = [];
            while ($after && microtime(true) < $deadline) {
                $page     = $this->fetch_task_page($after);
                if (!empty($page['error'])) break; // nettverksfeil – ikke marker som komplett
                $first_id = $page['hits'][0]['id'] ?? null;
                if (empty($page['hits']) || $first_id === null) { $hist['complete'] = true; break; }
                if (isset($seen_pages[$first_id]))               { $hist['complete'] = true; break; }
                $seen_pages[$first_id] = true;
                foreach ($page['hits'] as $task) {
                    $this->insert_print($task, $dev_info);
                }
                $hist['boundary'] = end($page['hits'])['id'] ?? null;
                if (count($page['hits']) < 100) { $hist['complete'] = true; break; }
                $after = $hist['boundary'];
            }
            delete_transient('bambu_php_crawl_lock');
        }

        update_option('bambu_php_hist_stats', $hist);

        global $wpdb;
        $table    = $wpdb->prefix . 'bambu_prints';
        $db_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
        $db_stats = $this->get_stats_from_db($dev_info);
        $total    = array_sum(array_column($db_stats, 'total_prints'));

        $result = [
            'devices'       => array_values($db_stats),
            'total_tasks'   => $total,
            'api_total'     => $hist['api_total'],
            'data_complete' => $hist['complete'],
            'cache_info'    => [
                'history_complete' => $hist['complete'],
                'recent_fetched'   => count($recent),
                'db_prints_count'  => $db_count,
            ],
        ];

        $ttl = $hist['complete'] ? 300 : 30;
        set_transient('bambu_php_stats_v2', $result, $ttl);
        return $result;
    }

    /** Hent én side med tasks fra Bambu API – returnerer ['hits'=>[], 'total'=>N] */
    private function fetch_task_page($after = null) {
        $path = '/v1/user-service/my/tasks?limit=100' . ($after ? '&after=' . urlencode($after) : '');
        $body = $this->api_get($path, 20);
        if (is_wp_error($body)) return ['hits' => [], 'total' => 0, 'error' => true];
        return [
            'hits'  => is_array($body['hits'] ?? null) ? $body['hits'] : [],
            'total' => (int)($body['total'] ?? 0),
        ];
    }

    /** Konverter Bambu-tid (ISO-streng eller ms/s-tall) til unix-sekunder, eller null */
    private function to_timestamp($v) {
        if (!$v) return null;
        $ts = (is_numeric($v) && abs($v) > 1e10) ? intdiv((int)$v, 1000)
            : (is_numeric($v) ? (int)$v : strtotime((string)$v));
        return ($ts && $ts > 0) ? $ts : null;
    }

    /** Aggreger én task inn i stats-array */
    private function merge_task(array &$stats, array $task, array $dev_info) {
        $did = $task['deviceId'] ?? 'unknown';
        if (!isset($stats[$did])) {
            $stats[$did] = [
                'name'            => $dev_info[$did]['name']  ?? $did,
                'model'           => $dev_info[$did]['model'] ?? 'Ukjent',
                'total_prints'    => 0,
                'successful'      => 0,
                'failed'          => 0,
                'total_time_s'    => 0,
                'total_weight_g'  => 0,
                'total_length_cm' => 0,
            ];
        }
        $s = &$stats[$did];
        $s['total_prints']++;
        ($task['status'] ?? 0) === 2 ? $s['successful']++ : $s['failed']++;
        $s['total_time_s']    += $task['costTime'] ?? 0;
        $s['total_weight_g']  += $task['weight']   ?? 0;
        $s['total_length_cm'] += $task['length']   ?? 0;
    }

    /** Hent enhetsnavn og modell (cachet 1 time; tom liste caches ikke) */
    private function get_device_info() {
        $cached = get_transient('bambu_php_devinfo');
        if (is_array($cached) && $cached) return $cached;

        $bind = $this->api_get('/v1/iot-service/api/user/bind');
        if (is_wp_error($bind)) return [];
        return $this->cache_device_info($bind['devices'] ?? []);
    }

    /** Bygg dev_id → [name, model] fra bind-liste og cache den */
    private function cache_device_info(array $devices) {
        $info = [];
        foreach ($devices as $d) {
            if (empty($d['dev_id'])) continue;
            $info[$d['dev_id']] = [
                'name'  => $d['name']             ?? $d['dev_id'],
                'model' => $d['dev_product_name'] ?? 'Ukjent',
            ];
        }
        if ($info) set_transient('bambu_php_devinfo', $info, HOUR_IN_SECONDS);
        return $info;
    }

    // ── Cron + Pi-integrasjon ────────────────────────────────────────────────

    public function schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'hourly', self::CRON_HOOK);
        }
    }

    public function cron_sync() {
        delete_transient('bambu_php_stats_v2');
        $this->fetch_stats();
    }

    private function get_pi_key() {
        $key = get_option('bambu_php_pi_key');
        if (!$key) {
            $key = bin2hex(random_bytes(20));
            update_option('bambu_php_pi_key', $key);
        }
        return $key;
    }

    public function check_pi_key(WP_REST_Request $req) {
        $key = $req->get_header('X-Bambu-Key');
        return $key && hash_equals($this->get_pi_key(), $key);
    }

    /** POST /bambu/v1/push-print – Pi sender ferdig print til WordPress */
    public function rest_push_print(WP_REST_Request $req) {
        $task = $req->get_json_params();
        if (!is_array($task) || empty($task['id'])) {
            return new WP_Error('missing_id', 'task id påkrevd', ['status' => 400]);
        }
        // Bruk cachet enhetsinfo så navn/modell blir riktig selv om Pi ikke sender dem
        $result = $this->insert_print($task, $this->get_device_info());
        if (in_array($result, ['inserted', 'updated'], true)) delete_transient('bambu_php_stats_v2');
        return rest_ensure_response([
            'inserted' => $result === 'inserted',
            'updated'  => $result === 'updated',
            'result'   => $result, // inserted | updated | unchanged | skipped_running | error
            'task_id'  => $task['id'],
        ]);
    }

    /** GET /bambu/v1/cron-sync?key=… – For ekte cron-jobb (cPanel/Linux) */
    public function rest_cron_sync(WP_REST_Request $req) {
        $key = sanitize_text_field($req->get_param('key') ?? '');
        if (!$key || !hash_equals($this->get_pi_key(), $key)) {
            return new WP_Error('unauthorized', 'Ugyldig nøkkel', ['status' => 401]);
        }
        delete_transient('bambu_php_stats_v2');
        $result = $this->fetch_stats();
        if (is_wp_error($result)) {
            return rest_ensure_response(['ok' => false, 'error' => $result->get_error_message()]);
        }
        return rest_ensure_response([
            'ok'        => true,
            'db_prints' => $result['cache_info']['db_prints_count'] ?? 0,
            'api_total' => $result['api_total'] ?? 0,
            'complete'  => $result['data_complete'] ?? false,
        ]);
    }

    // ── Database ─────────────────────────────────────────────────────────────

    /** Kjør dbDelta kun når lagret db-versjon avviker (ingen SHOW TABLES per request) */
    public function maybe_create_table() {
        if (get_option('bambu_php_db_version') !== self::DB_VERSION) {
            $this->create_table();
            update_option('bambu_php_db_version', self::DB_VERSION);
        }
    }

    private function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . 'bambu_prints';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id      VARCHAR(64)     NOT NULL,
            device_id    VARCHAR(64)     NOT NULL DEFAULT '',
            device_name  VARCHAR(128)    NOT NULL DEFAULT '',
            device_model VARCHAR(128)    NOT NULL DEFAULT '',
            title        VARCHAR(255)    NOT NULL DEFAULT '',
            status       TINYINT         NOT NULL DEFAULT 0,
            start_time   DATETIME        NULL,
            end_time     DATETIME        NULL,
            weight_g     FLOAT           NOT NULL DEFAULT 0,
            length_cm    FLOAT           NOT NULL DEFAULT 0,
            cost_time_s  INT UNSIGNED    NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY   task_id (task_id),
            KEY          device_id (device_id),
            KEY          start_time (start_time)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /** Upsert av én task – returnerer 'inserted' | 'updated' | 'unchanged' | 'skipped_running' | 'error' */
    private function insert_print(array $task, array $dev_info) {
        global $wpdb;
        $table = $wpdb->prefix . 'bambu_prints';
        $tid   = $task['id'] ?? null;
        if (!$tid) return 'error';

        $did      = (string)($task['deviceId'] ?? 'unknown');
        $status   = (int)($task['status'] ?? 0);
        $start_ts = $this->to_timestamp($task['startTime'] ?? null);
        $end_ts   = $this->to_timestamp($task['endTime']   ?? null);

        // Ikke lagre prints som tydelig fortsatt pågår – de fanges opp ved neste sync
        if ($status === self::STATUS_RUNNING && (!$end_ts || $end_ts > time())) {
            return 'skipped_running';
        }

        $start_dt = $start_ts ? gmdate('Y-m-d H:i:s', $start_ts) : null; // lagres i UTC
        $end_dt   = $end_ts   ? gmdate('Y-m-d H:i:s', $end_ts)   : null;
        $s_ph     = $start_dt ? '%s' : 'NULL';
        $e_ph     = $end_dt   ? '%s' : 'NULL';

        $name  = ($task['deviceName']  ?? '') ?: ($dev_info[$did]['name']  ?? $did);
        $model = ($task['deviceModel'] ?? '') ?: ($dev_info[$did]['model'] ?? 'Ukjent');

        $args = [
            $tid,
            $did,
            mb_substr((string) $name, 0, 127),
            mb_substr((string) $model, 0, 127),
            mb_substr((string)($task['title'] ?? ''), 0, 254),
            $status,
        ];
        if ($start_dt) $args[] = $start_dt;
        if ($end_dt)   $args[] = $end_dt;
        array_push($args,
            (float)($task['weight']   ?? 0),
            (float)($task['length']   ?? 0),
            (int)($task['costTime']   ?? 0)
        );

        // Upsert: en print fanget midt i (eller med foreløpig status) oppdateres når den er ferdig.
        // Navn/modell/tittel overskrives bare hvis lagret verdi er tom/plassholder.
        $affected = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `$table`
                    (task_id, device_id, device_name, device_model, title, status,
                     start_time, end_time, weight_g, length_cm, cost_time_s)
                 VALUES (%s, %s, %s, %s, %s, %d, $s_ph, $e_ph, %f, %f, %d)
                 ON DUPLICATE KEY UPDATE
                    status       = VALUES(status),
                    end_time     = COALESCE(VALUES(end_time), end_time),
                    weight_g     = VALUES(weight_g),
                    length_cm    = VALUES(length_cm),
                    cost_time_s  = VALUES(cost_time_s),
                    title        = IF(title = '', VALUES(title), title),
                    device_name  = IF(device_name IN ('', device_id), VALUES(device_name), device_name),
                    device_model = IF(device_model IN ('', 'Ukjent'), VALUES(device_model), device_model)",
                ...$args
            )
        );
        if ($affected === false) return 'error';
        if ($affected === 1)     return 'inserted';
        if ($affected >= 2)      return 'updated';
        return 'unchanged';
    }

    /** Returns per-device aggregated stats from the DB table (ekte navn/modell foretrekkes over plassholdere) */
    private function get_stats_from_db(array $dev_info = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'bambu_prints';
        $rows  = $wpdb->get_results(
            "SELECT device_id,
                    MAX(CASE WHEN device_name  NOT IN ('', device_id) THEN device_name  END) AS name,
                    MAX(CASE WHEN device_model NOT IN ('', 'Ukjent')  THEN device_model END) AS model,
                    COUNT(*)          AS total_prints,
                    SUM(status = 2)   AS successful,
                    SUM(status != 2)  AS failed,
                    SUM(CASE WHEN start_time IS NOT NULL AND end_time IS NOT NULL
                             THEN TIMESTAMPDIFF(SECOND, start_time, end_time)
                             ELSE cost_time_s END)              AS total_time_s,
                    SUM(CASE WHEN status = 2 THEN weight_g  ELSE 0 END) AS total_weight_g,
                    SUM(CASE WHEN status = 2 THEN length_cm ELSE 0 END) AS total_length_cm
             FROM `$table`
             GROUP BY device_id",
            ARRAY_A
        );
        $stats = [];
        foreach ((array) $rows as $r) {
            $did = $r['device_id'];
            $stats[$did] = [
                // Ikke lenger koblet til kontoen. Kun når enhetslisten faktisk ble hentet,
                // ellers ville alle printere blitt skjult ved en API-feil.
                'removed'         => !empty($dev_info) && !isset($dev_info[$did]),
                'name'            => $dev_info[$did]['name']  ?? ($r['name']  ?: $did),
                'model'           => $r['model'] ?: ($dev_info[$did]['model'] ?? 'Ukjent'),
                'total_prints'    => (int)$r['total_prints'],
                'successful'      => (int)$r['successful'],
                'failed'          => (int)$r['failed'],
                'total_time_s'    => (float)$r['total_time_s'],
                'total_weight_g'  => (float)$r['total_weight_g'],
                'total_length_cm' => (float)$r['total_length_cm'],
            ];
        }
        return $stats;
    }

    // ── WP REST API ──────────────────────────────────────────────────────────

    public function register_rest_routes() {
        $public = ['permission_callback' => '__return_true'];
        $admin  = ['permission_callback' => function() { return current_user_can('manage_options'); }];

        register_rest_route('bambu/v1', '/status',     array_merge(['methods' => 'GET', 'callback' => [$this, 'rest_status']],    $public));
        register_rest_route('bambu/v1', '/today',      array_merge(['methods' => 'GET', 'callback' => [$this, 'rest_today']],     $public));
        register_rest_route('bambu/v1', '/stats',      array_merge(['methods' => 'GET', 'callback' => [$this, 'rest_stats']],     $public));
        register_rest_route('bambu/v1', '/prints',     array_merge(['methods' => 'GET', 'callback' => [$this, 'rest_prints']],    $public));
        register_rest_route('bambu/v1', '/export',     array_merge(['methods' => 'GET', 'callback' => [$this, 'rest_export']],    $public));
        // Feilsøkingsruter – kun for administratorer
        register_rest_route('bambu/v1', '/raw-sample', array_merge(['methods' => 'GET', 'callback' => [$this, 'rest_raw_sample']],$admin));
        register_rest_route('bambu/v1', '/recent',     array_merge(['methods' => 'GET', 'callback' => [$this, 'rest_recent']],    $admin));
        register_rest_route('bambu/v1', '/oldest',     array_merge(['methods' => 'GET', 'callback' => [$this, 'rest_oldest']],    $admin));
        register_rest_route('bambu/v1', '/push-print', ['methods' => 'POST','callback' => [$this, 'rest_push_print'], 'permission_callback' => [$this, 'check_pi_key']]);
        register_rest_route('bambu/v1', '/cron-sync', ['methods' => 'GET', 'callback' => [$this, 'rest_cron_sync'],  'permission_callback' => '__return_true']);
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

    /** Paginated list of individual prints from DB – ?page=1&per_page=20&device=<id> */
    public function rest_prints(WP_REST_Request $req) {
        global $wpdb;
        $table    = $wpdb->prefix . 'bambu_prints';
        $page     = max(1, (int)($req->get_param('page')     ?? 1));
        $per_page = min(100, max(1, (int)($req->get_param('per_page') ?? 20)));
        $device   = sanitize_text_field($req->get_param('device') ?? '');
        $offset   = ($page - 1) * $per_page;

        if ($device) {
            $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE device_id = %s", $device));
            $rows  = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT task_id, device_id, device_name, device_model, title, status,
                            start_time, end_time, weight_g, length_cm, cost_time_s
                     FROM `$table` WHERE device_id = %s ORDER BY start_time DESC LIMIT %d OFFSET %d",
                    $device, $per_page, $offset
                ),
                ARRAY_A
            );
        } else {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
            $rows  = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT task_id, device_id, device_name, device_model, title, status,
                            start_time, end_time, weight_g, length_cm, cost_time_s
                     FROM `$table` ORDER BY start_time DESC LIMIT %d OFFSET %d",
                    $per_page, $offset
                ),
                ARRAY_A
            );
        }

        // start_time/end_time er UTC ('Y-m-d H:i:s' uten tidssone).
        // start_ts/end_ts er unix-sekunder (int|null) – bruk disse for lokal visning i JS.
        $utc_ts = function($dt) {
            if (!$dt) return null;
            $ts = strtotime($dt . ' UTC');
            return $ts ? (int) $ts : null;
        };
        $prints = array_map(function($r) use ($utc_ts) {
            return [
                'task_id'      => $r['task_id'],
                'device_id'    => $r['device_id'],
                'device_name'  => $r['device_name'],
                'device_model' => $r['device_model'],
                'title'        => $r['title'],
                'status'       => (int)$r['status'],
                'start_time'   => $r['start_time'],
                'end_time'     => $r['end_time'],
                'start_ts'     => $utc_ts($r['start_time']),
                'end_ts'       => $utc_ts($r['end_time']),
                'weight_g'     => (float)$r['weight_g'],
                'length_cm'    => (float)$r['length_cm'],
                'cost_time_s'  => (int)$r['cost_time_s'],
            ];
        }, (array) $rows);

        return rest_ensure_response([
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => $total > 0 ? (int)ceil($total / $per_page) : 0,
            'prints'   => $prints,
        ]);
    }

    /** CSV-eksport av aggregert statistikk per printer */
    public function rest_export() {
        $stats = $this->fetch_stats();
        if (is_wp_error($stats)) {
            return new WP_Error('stats_error', $stats->get_error_message(), ['status' => 500]);
        }

        $rows   = [];
        $rows[] = ['Printer', 'Modell', 'Prints', 'Vellykket', 'Feilet', 'Suksessrate %', 'Printtid (timer)', 'Filament (g)', 'Filament (kg)', 'Lengde (m)', 'Lengde (km)'];
        foreach ($stats['devices'] ?? [] as $d) {
            $rate   = $d['total_prints'] > 0 ? round($d['successful'] / $d['total_prints'] * 100, 1) : 0;
            $hours  = round($d['total_time_s'] / 3600, 1);
            $len_m  = round(($d['total_length_cm'] ?? 0) / 100, 1);
            $len_km = round(($d['total_length_cm'] ?? 0) / 100000, 3);
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
                $len_m,
                $len_km,
            ];
        }

        $out = '';
        foreach ($rows as $row) {
            $out .= implode(';', array_map(function($v) {
                $v = (string) $v;
                // Beskytt mot formel-injeksjon i Excel/Sheets (tall som -1.5 er trygge)
                if ($v !== '' && !is_numeric($v) && strpbrk($v[0], "=+-@\t\r") !== false) {
                    $v = "'" . $v;
                }
                return '"' . str_replace('"', '""', $v) . '"';
            }, $row)) . "\r\n";
        }

        // Send CSV direkte – omgår WP REST JSON-wrapping
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="bambu-stats-' . wp_date('Y-m-d') . '.csv"');
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

        // Bla gjennom til siste side – med tak på tid og antall sider
        $last_hits     = $first['hits'];
        $pages_fetched = 1;
        $deadline      = microtime(true) + 25;
        while ($pages_fetched < 200 && microtime(true) < $deadline) {
            $next_after = end($last_hits)['id'] ?? null;
            if (!$next_after) break;
            $page = $this->fetch_task_page($next_after);
            if (empty($page['hits'])) break;
            // Loop-deteksjon
            if (($page['hits'][0]['id'] ?? null) === ($last_hits[0]['id'] ?? null)) break;
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
                'status'   => ($oldest['status'] ?? null) === 2 ? 'Fullført' : 'Feilet/Annet',
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
                'status'     => ($t['status'] ?? null) === 2 ? 'Fullført' : (($t['status'] ?? null) === 3 ? 'Feilet' : 'Ukjent (' . ($t['status'] ?? '?') . ')'),
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
                $in = wp_unslash($_POST);
                update_option('bambu_php_dash_link_url',    esc_url_raw($in['bambu_php_dash_link_url'] ?? ''));
                update_option('bambu_php_dash_link_label',  sanitize_text_field($in['bambu_php_dash_link_label'] ?? ''));
                update_option('bambu_php_stats_link_url',   esc_url_raw($in['bambu_php_stats_link_url'] ?? ''));
                update_option('bambu_php_stats_link_label', sanitize_text_field($in['bambu_php_stats_link_label'] ?? ''));
                echo '<div class="notice notice-success"><p>✅ Lenker lagret.</p></div>';
            }

            if (isset($_POST['bambu_php_email'])) {
                update_option('bambu_php_email', sanitize_email(wp_unslash($_POST['bambu_php_email'])));
                // Passord: kun wp_unslash – sanitize_text_field ville ødelagt passord med spesialtegn
                $password = wp_unslash($_POST['bambu_php_password'] ?? '');
                if ($password !== '') {
                    update_option('bambu_php_password', $password);
                }
                delete_transient('bambu_php_status');
                delete_transient('bambu_php_today_tasks');
                delete_transient('bambu_php_stats_v2');
                delete_transient('bambu_php_devinfo');
                delete_transient('bambu_php_login_throttle');
                // NB: hist_stats slettes IKKE – akkumulert historikk bevares ved ny innlogging

                $result = $this->do_login();
                if (is_wp_error($result)) {
                    $cls = $result->get_error_code() === 'verify_required' ? 'notice-warning' : 'notice-error';
                    echo '<div class="notice ' . $cls . '"><p>' . esc_html($result->get_error_message()) . '</p></div>';
                } else {
                    echo '<div class="notice notice-success"><p>✅ Innlogget og token lagret.</p></div>';
                }
            }

            if (isset($_POST['bambu_resend_code'])) {
                update_option('bambu_php_needs_verify', true);
                $result = $this->send_verify_code();
                if (is_wp_error($result)) {
                    echo '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
                } else {
                    echo '<div class="notice notice-success"><p>✅ Ny kode sendt til ' . esc_html(get_option('bambu_php_email')) . ' – sjekk innboksen og søppelpost.</p></div>';
                }
            }

            if (!empty($_POST['bambu_verify_code'])) {
                $code   = sanitize_text_field(wp_unslash($_POST['bambu_verify_code']));
                $result = $this->do_verify($code);
                if (is_wp_error($result)) {
                    echo '<div class="notice notice-error"><p>Feil: ' . esc_html($result->get_error_message()) . '</p></div>';
                } else {
                    echo '<div class="notice notice-success"><p>✅ Verifisert og innlogget.</p></div>';
                }
            }

            if (isset($_POST['bambu_reset_cache'])) {
                // Myk reset: fjern bare transient, behold akkumulert historikk
                delete_transient('bambu_php_stats_v2');
                delete_transient('bambu_php_today_tasks');
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

            if (isset($_POST['bambu_regen_key'])) {
                delete_option('bambu_php_pi_key');
                $this->get_pi_key(); // genererer ny
                echo '<div class="notice notice-success"><p>✅ Ny API-nøkkel generert. Oppdater .env på Pi-en.</p></div>';
            }

            if (isset($_POST['bambu_wipe_history'])) {
                // Hard reset: slett ALT inkl. akkumulert historikk og DB-tabellen
                delete_transient('bambu_php_stats_v2');
                delete_transient('bambu_php_today_tasks');
                delete_transient('bambu_php_status');
                delete_option('bambu_php_hist_stats');
                global $wpdb;
                $wpdb->query("TRUNCATE TABLE `{$wpdb->prefix}bambu_prints`");
                echo '<div class="notice notice-warning"><p>⚠️ All statistikk-historikk slettet (inkl. DB-tabellen). Data hentes på nytt fra Bambu (kun tasks Bambu har lagret er tilgjengelig).</p></div>';
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

            <form method="post" style="margin-top:.5rem">
                <?php wp_nonce_field('bambu_php_settings'); ?>
                <input type="hidden" name="bambu_resend_code" value="1" />
                <?php submit_button('Send ny verifiseringskode', 'secondary', 'submit', false); ?>
            </form>

            <hr>
            <h2>Statistikk-cache</h2>
            <?php
            global $wpdb;
            $cached_tasks = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}bambu_prints`");
            $hist = get_option('bambu_php_hist_stats', null);
            ?>
            <p>
                Lagrede historiske prints: <strong><?= number_format($cached_tasks) ?></strong>
                · Status: <strong><?= $hist ? (!empty($hist['complete']) ? '✅ Komplett' : '⏳ Laster...') : '–' ?></strong>
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
            <h2>Pi-integrasjon</h2>
            <?php $pi_key = $this->get_pi_key(); ?>
            <p>Raspberry Pi-appen bruker API-nøkkelen for å sende print-data direkte til WordPress. Cron-URL-en kan legges inn i cPanel for automatisk synkronisering selv uten sidebesøk.</p>
            <table class="form-table">
                <tr>
                    <th scope="row">API-nøkkel (Pi → WP)</th>
                    <td>
                        <code style="word-break:break-all"><?= esc_html($pi_key) ?></code>
                        <form method="post" style="display:inline;margin-left:1rem">
                            <?php wp_nonce_field('bambu_php_settings'); ?>
                            <input type="hidden" name="bambu_regen_key" value="1" />
                            <?php submit_button('Generer ny nøkkel', 'secondary small', 'submit', false,
                                ['onclick' => 'return confirm("Generer ny nøkkel? Pi-appen må oppdateres med den nye.")'
                            ]); ?>
                        </form>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Push-endepunkt</th>
                    <td><code><?= esc_html(rest_url('bambu/v1/push-print')) ?></code><br>
                    <span style="color:#666;font-size:0.9em">POST med header <code>X-Bambu-Key: &lt;nøkkel&gt;</code> og task-data som JSON</span></td>
                </tr>
                <tr>
                    <th scope="row">Cron-URL (automatisk sync)</th>
                    <td>
                        <code style="word-break:break-all"><?= esc_html(rest_url('bambu/v1/cron-sync') . '?key=' . $pi_key) ?></code>
                        <p class="description" style="margin-top:0.5rem">
                            Legg inn i cPanel → Cron Jobs (f.eks. hvert 30. minutt):<br>
                            <code>curl -s "<?= esc_html(rest_url('bambu/v1/cron-sync') . '?key=' . $pi_key) ?>" > /dev/null</code>
                        </p>
                    </td>
                </tr>
            </table>

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
            !has_shortcode($post->post_content, 'bambu_stats') &&
            !$this->uses_fullscreen_template($post)) return;

        wp_enqueue_style('bambu-php-css',
            plugin_dir_url(__FILE__) . 'assets/bambu-dashboard.css', [], $this->asset_version('assets/bambu-dashboard.css'));

        wp_enqueue_script('bambu-php-js',
            plugin_dir_url(__FILE__) . 'assets/bambu-dashboard.js', [], $this->asset_version('assets/bambu-dashboard.js'), true);

        // Råverdier: JS bruker textContent/href, så esc_html her ville gitt dobbel escaping.
        // restBase er full namespace-URL (f.eks. https://x/wp-json/bambu/v1 eller
        // https://x/?rest_route=/bambu/v1 ved enkle permalenker). JS bør bygge URL-er som
        // restBase + '/stats' og legge til query-parametre med URL/URLSearchParams
        // (bruk '&' hvis URL-en allerede har '?'), ikke ved å lime på '?…' direkte.
        wp_localize_script('bambu-php-js', 'bambuConfig', [
            'restUrl'   => esc_url_raw(rest_url('')),
            'restBase'  => esc_url_raw(rest_url('bambu/v1')),
            'theme'     => 'auto',
            'php_mode'  => true,
            'version'   => self::VERSION,
            'dashLinkUrl'   => esc_url_raw(get_option('bambu_php_dash_link_url', '')),
            'dashLinkLabel' => (string) get_option('bambu_php_dash_link_label', ''),
            'statsLinkUrl'  => esc_url_raw(get_option('bambu_php_stats_link_url', '')),
            'statsLinkLabel'=> (string) get_option('bambu_php_stats_link_label', ''),
        ]);
    }

    /** Asset-versjon: filmtime når tilgjengelig (cache-busting), ellers plugin-versjon */
    private function asset_version($rel) {
        $path = plugin_dir_path(__FILE__) . $rel;
        $mtime = file_exists($path) ? filemtime($path) : false;
        return $mtime ? (string) $mtime : self::VERSION;
    }

    public function dashboard_shortcode($atts) {
        $atts = shortcode_atts(['theme' => '', 'cols' => '5', 'auto' => 'true', 'demo' => 'false'], $atts);
        $cls  = $atts['theme'] === 'dark' ? 'bambu-dark' : ($atts['theme'] === 'light' ? 'bambu-light' : '');
        return sprintf(
            '<div id="bambu-dashboard" class="bambu-wrap %s" data-cols="%s" data-auto="%s" data-demo="%s"><div class="bambu-loading">Laster 3D-print dashboard...</div></div>',
            esc_attr($cls), esc_attr($atts['cols']), esc_attr($atts['auto']), $atts['demo'] === 'true' ? 'true' : 'false'
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

register_activation_hook(__FILE__,   ['BambuDashboardPHP', 'activate']);
register_deactivation_hook(__FILE__, ['BambuDashboardPHP', 'deactivate']);

BambuDashboardPHP::instance();
