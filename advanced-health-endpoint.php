<?php

/**
 * Plugin Name: Advanced Health Endpoint
 * Plugin URI: https://github.com/CHFR91/advanced-health-endpoint
 * Description: Endpoint REST /wp-json/ahe/v1/health avec widget dashboard, page admin, uptime history et monitoring complet. Compatible UptimeRobot.
 * Version: 2.2.1
 * Requires at least: 5.2
 * Requires PHP: 7.2
 * Author: Advanced Health Team
 * Author URI: https://github.com/CHFR91/advanced-health-endpoint
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: advanced-health-endpoint
 */

if (! defined('ABSPATH')) {
    exit;
}

final class Advanced_Health_Endpoint
{

    const VERSION         = '2.2.1';
    const TRANSIENT       = 'ahe_uptime';
    const TRANS_AUTO      = 'ahe_autoload_size';
    const OPTION_TOKEN    = 'ahe_secret_token';
    const MAX_CHECKS      = 7;
    const UPTIME_COOLDOWN = 300;
    const RATE_MAX        = 20;
    const RATE_WINDOW     = 60;
    const CRON_HOOK       = 'ahe_weekly_cleanup';

    /* ══════════════════════════════════════════════
       Initialisation
       ══════════════════════════════════════════════ */

    public static function init()
    {
        add_action('rest_api_init', array(__CLASS__, 'register_route'));
        add_action('wp_dashboard_setup', array(__CLASS__, 'dashboard_widget'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_init', array(__CLASS__, 'handle_admin_actions'));
        add_action('wp_ajax_ahe_test_endpoint', array(__CLASS__, 'ajax_test_endpoint'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'cleanup_rate_transients'));

        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'weekly', self::CRON_HOOK);
        }
    }

    public static function activate()
    {
        if (! get_option(self::OPTION_TOKEN)) {
            update_option(self::OPTION_TOKEN, wp_generate_password(40, false), true);
        }
    }

    public static function deactivate()
    {
        delete_transient(self::TRANSIENT);
        delete_transient(self::TRANS_AUTO);
        wp_clear_scheduled_hook(self::CRON_HOOK);
        self::cleanup_rate_transients();
    }

    public static function cleanup_rate_transients()
    {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_ahe_rate_%'
                OR option_name LIKE '_transient_timeout_ahe_rate_%'"
        );
    }

    /* ══════════════════════════════════════════════
       Sécurité : vérification du token
       ══════════════════════════════════════════════ */

    private static function verify_token($request)
    {
        $stored = get_option(self::OPTION_TOKEN, '');
        if (empty($stored)) {
            return false;
        }
        // Priorité au header (plus sécurisé, pas dans les access logs).
        $header = $request->get_header('X-Health-Token');
        if (! empty($header) && hash_equals($stored, $header)) {
            return true;
        }
        // Fallback query string (UptimeRobot gratuit).
        $param = $request->get_param('token');
        if (! empty($param) && hash_equals($stored, (string) $param)) {
            return true;
        }
        return false;
    }

    /* ══════════════════════════════════════════════
       Rate Limiting
       ══════════════════════════════════════════════ */

    private static function check_rate_limit()
    {
        $ip  = self::get_client_ip();
        $key = 'ahe_rate_' . md5($ip);
        $hit = (int) get_transient($key);

        if ($hit >= self::RATE_MAX) {
            return false;
        }

        set_transient($key, $hit + 1, self::RATE_WINDOW);
        return true;
    }

    private static function get_client_ip()
    {
        $headers = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        );
        foreach ($headers as $h) {
            if (! empty($_SERVER[$h])) {
                $ip = strtok($_SERVER[$h], ',');
                $ip = filter_var(trim($ip), FILTER_VALIDATE_IP);
                if ($ip) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    /* ══════════════════════════════════════════════
       Endpoint REST
       ══════════════════════════════════════════════ */

    public static function register_route()
    {
        register_rest_route(
            'ahe/v1',
            '/health',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array(__CLASS__, 'handle_request'),
                'permission_callback' => '__return_true',
            )
        );
    }

    public static function handle_request($request)
    {
        if (! self::check_rate_limit()) {
            return new WP_REST_Response(
                array(
                    'status'  => 'RATE_LIMITED',
                    'message' => 'Too many requests.',
                ),
                429
            );
        }

        $authenticated = self::verify_token($request);
        $queries_before = get_num_queries();
        $data = self::collect_health_data($authenticated);
        $data['db_queries_health'] = get_num_queries() - $queries_before;

        self::record_uptime($data['status']);

        $response = new WP_REST_Response($data, 200);
        $response->set_headers(
            array(
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'X-AHE-Version' => self::VERSION,
            )
        );
        return $response;
    }

    /* ══════════════════════════════════════════════
       Collecte des données
       ══════════════════════════════════════════════ */

    private static function collect_health_data($full = false)
    {
        $data = array(
            'status'    => 'OK',
            'timestamp' => gmdate('c'),
        );

        if (! $full) {
            return $data;
        }

        global $wpdb;

        // WordPress.
        $data['wordpress'] = array(
            'version'     => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'db_version'  => $wpdb->db_version(),
            'multisite'   => is_multisite(),
            'site_url'    => get_site_url(),
            'debug_mode'  => defined('WP_DEBUG') && WP_DEBUG,
        );

        // Mémoire PHP.
        $mem_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $mem_used  = memory_get_usage(false);
        $data['memory'] = array(
            'limit_mb'    => round($mem_limit / 1048576, 1),
            'used_mb'     => round($mem_used / 1048576, 1),
            'peak_mb'     => round(memory_get_peak_usage(false) / 1048576, 1),
            'usage_pct'   => $mem_limit > 0 ? round($mem_used / $mem_limit * 100, 1) : 0,
        );

        // Base de données.
        $db_ok = (bool) $wpdb->get_var('SELECT 1');
        $data['database'] = array(
            'connected'     => $db_ok,
            'prefix'        => $wpdb->prefix,
            'queries_total' => get_num_queries(),
        );

        // Autoload.
        $autoload = get_transient(self::TRANS_AUTO);
        if (false === $autoload) {
            $autoload = $wpdb->get_var(
                "SELECT ROUND( SUM( LENGTH(option_value) ) / 1048576, 2 )
                 FROM {$wpdb->options}
                 WHERE autoload = 'yes'"
            );
            set_transient(self::TRANS_AUTO, $autoload, 300);
        }
        $data['database']['autoload_mb'] = (float) $autoload;

        // Disk.
        $data['disk'] = self::get_disk_info();

        // Extensions.
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins    = get_plugins();
        $active_plugins = (array) get_option('active_plugins', array());

        $plugin_updates = 0;
        $update_trans   = get_site_transient('update_plugins');
        if (is_object($update_trans) && ! empty($update_trans->response)) {
            $plugin_updates = count($update_trans->response);
        }

        $theme_updates = 0;
        $theme_trans   = get_site_transient('update_themes');
        if (is_object($theme_trans) && ! empty($theme_trans->response)) {
            $theme_updates = count($theme_trans->response);
        }

        $data['extensions'] = array(
            'plugins_total'   => count($all_plugins),
            'plugins_active'  => count($active_plugins),
            'plugin_updates'  => $plugin_updates,
            'theme_updates'   => $theme_updates,
            'active_theme'    => get_template(),
        );

        // Cron.
        $data['cron'] = self::get_cron_info();

        // Performance.
        $data['performance'] = array(
            'time_elapsed_ms' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 1),
        );

        // Dégradation si problèmes.
        if (! $db_ok) {
            $data['status'] = 'CRITICAL';
        } elseif ($data['memory']['usage_pct'] > 90 || $plugin_updates > 5) {
            $data['status'] = 'WARNING';
        }

        return $data;
    }

    /* ══════════════════════════════════════════════
       Disk Info
       ══════════════════════════════════════════════ */

    private static function get_disk_info()
    {
        $info = array(
            'free_mb'    => null,
            'total_mb'   => null,
            'usage_pct'  => null,
        );

        if (function_exists('disk_free_space') && function_exists('disk_total_space')) {
            $free  = @disk_free_space(ABSPATH);
            $total = @disk_total_space(ABSPATH);
            if (false !== $free && false !== $total && $total > 0) {
                $info['free_mb']   = round($free / 1048576, 0);
                $info['total_mb']  = round($total / 1048576, 0);
                $info['usage_pct'] = round((1 - $free / $total) * 100, 1);
            }
        }

        return $info;
    }

    /* ══════════════════════════════════════════════
       Cron Info
       ══════════════════════════════════════════════ */

    private static function get_cron_info()
    {
        $info = array(
            'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'alternate_cron'   => defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON,
            'overdue_events'   => 0,
        );

        $crons = _get_cron_array();
        if (is_array($crons)) {
            $now = time();
            foreach ($crons as $timestamp => $hooks) {
                if ($timestamp < ($now - 600)) {
                    $info['overdue_events'] += count($hooks);
                }
            }
        }

        return $info;
    }

    /* ══════════════════════════════════════════════
       Uptime history
       ══════════════════════════════════════════════ */

    private static function record_uptime($status)
    {
        $history = get_transient(self::TRANSIENT);
        if (! is_array($history)) {
            $history = array();
        }

        // Cooldown : ne pas écrire plus d'une fois toutes les 5 min.
        if (! empty($history)) {
            $last = end($history);
            if (isset($last['time']) && (time() - strtotime($last['time'])) < self::UPTIME_COOLDOWN) {
                return;
            }
        }

        $history[] = array(
            'time'   => gmdate('c'),
            'status' => $status,
        );
        $history = array_slice($history, -self::MAX_CHECKS);
        set_transient(self::TRANSIENT, $history, DAY_IN_SECONDS);
    }

    /* ══════════════════════════════════════════════
       Actions admin (POST) — traitement séparé
       ══════════════════════════════════════════════ */

    public static function handle_admin_actions()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        // Clear uptime history.
        if (isset($_POST['ahe_clear_uptime'])) {
            check_admin_referer('ahe_clear_uptime_action');
            delete_transient(self::TRANSIENT);
            add_action('admin_notices', function () {
                echo '<div class="notice notice-success is-dismissible"><p>';
                esc_html_e('AHE : Historique uptime effacé.', 'advanced-health-endpoint');
                echo '</p></div>';
            });
        }

        // Regenerate token.
        if (isset($_POST['ahe_regenerate_token'])) {
            check_admin_referer('ahe_regenerate_token_action');
            update_option(self::OPTION_TOKEN, wp_generate_password(40, false), true);
            add_action('admin_notices', function () {
                echo '<div class="notice notice-success is-dismissible"><p>';
                esc_html_e('AHE : Token régénéré avec succès.', 'advanced-health-endpoint');
                echo '</p></div>';
            });
        }
    }

    /* ══════════════════════════════════════════════
       Dashboard Widget
       ══════════════════════════════════════════════ */

    public static function dashboard_widget()
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        wp_add_dashboard_widget(
            'ahe_dashboard',
            '🩺 Advanced Health Endpoint',
            array(__CLASS__, 'render_dashboard_widget')
        );
    }


    public static function render_dashboard_widget()
    {
        $data    = self::collect_health_data(true);
        $history = get_transient(self::TRANSIENT);
        if (! is_array($history)) {
            $history = array();
        }

        $wp  = isset($data['wordpress'])   ? $data['wordpress']   : array();
        $mem = isset($data['memory'])       ? $data['memory']      : array();
        $db  = isset($data['database'])     ? $data['database']    : array();
        $dsk = isset($data['disk'])         ? $data['disk']        : array();
        $ext = isset($data['extensions'])   ? $data['extensions']  : array();
        $crn = isset($data['cron'])         ? $data['cron']        : array();
        $prf = isset($data['performance'])  ? $data['performance'] : array();

        // Couleur du statut.
        $status       = isset($data['status']) ? $data['status'] : 'UNKNOWN';
        $status_color = 'OK' === $status ? '#2ecc71' : ('WARNING' === $status ? '#f39c12' : '#e74c3c');

        echo '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif">';

        // Statut global.
        printf(
            '<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
            <span style="display:inline-block;width:14px;height:14px;border-radius:50%%;background:%s;border:2px solid rgba(0,0,0,.15)"></span>
            <strong style="font-size:15px">Status : %s</strong>
            <span style="color:#999;font-size:12px;margin-left:auto">%s</span>
        </div>',
            esc_attr($status_color),
            esc_html($status),
            esc_html($data['timestamp'])
        );

        // Table des métriques.
        echo '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:14px">';

        $rows = array(
            array('WordPress',      isset($wp['version']) ? $wp['version'] . ' / PHP ' . $wp['php_version'] : '—'),
            array('DB Version',     isset($wp['db_version']) ? $wp['db_version'] : '—'),
            array('DB Connexion',   isset($db['connected']) ? ($db['connected'] ? '✅ OK' : '❌ Échec') : '—'),
            array('Autoload',       isset($db['autoload_mb']) ? $db['autoload_mb'] . ' MB' : '—'),
            array('Mémoire',        isset($mem['used_mb']) ? $mem['used_mb'] . ' / ' . $mem['limit_mb'] . ' MB (' . $mem['usage_pct'] . '%)' : '—'),
            array('Mémoire pic',    isset($mem['peak_mb']) ? $mem['peak_mb'] . ' MB' : '—'),
            array('Disque',         isset($dsk['usage_pct']) && null !== $dsk['usage_pct'] ? $dsk['usage_pct'] . '% utilisé (' . number_format($dsk['free_mb'] / 1024, 1) . ' GB libre)' : '—'),
            array('Plugins',        isset($ext['plugins_active']) ? $ext['plugins_active'] . ' actifs / ' . $ext['plugins_total'] . ' installés' : '—'),
            array('Mises à jour',   isset($ext['plugin_updates']) ? $ext['plugin_updates'] . ' plugins, ' . $ext['theme_updates'] . ' thèmes' : '—'),
            array('Thème actif',    isset($ext['active_theme']) ? $ext['active_theme'] : '—'),
            array('WP Debug',       isset($wp['debug_mode']) ? ($wp['debug_mode'] ? '⚠️ Activé' : '✅ Désactivé') : '—'),
            array('Cron',           isset($crn['wp_cron_disabled']) ? ($crn['wp_cron_disabled'] ? '⚠️ DISABLE_WP_CRON' : '✅ Actif') : '—'),
            array('Cron en retard', isset($crn['overdue_events']) ? $crn['overdue_events'] : '—'),
            array('Requêtes DB',    isset($db['queries_total']) ? $db['queries_total'] : '—'),
            array('TTFB',           isset($prf['time_elapsed_ms']) ? $prf['time_elapsed_ms'] . ' ms' : '—'),
        );

        $i = 0;
        foreach ($rows as $row) {
            $bg = $i % 2 === 0 ? '#f9f9f9' : '#fff';
            printf(
                '<tr style="background:%s"><td style="padding:6px 8px;font-weight:600;white-space:nowrap">%s</td><td style="padding:6px 8px">%s</td></tr>',
                $bg,
                esc_html($row[0]),
                $row[1] // Déjà échappé ou contient des entités sûres.
            );
            $i++;
        }

        echo '</table>';

        // Uptime dots.
        if (! empty($history)) {
            echo '<p style="margin-bottom:4px"><strong>Uptime récent :</strong></p>';
            echo '<div style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap">';
            foreach ($history as $entry) {
                $color = 'OK' === $entry['status'] ? '#2ecc71' : ('WARNING' === $entry['status'] ? '#f39c12' : '#e74c3c');
                $title = esc_attr($entry['time'] . ' — ' . $entry['status']);
                printf(
                    '<span title="%s" style="width:18px;height:18px;border-radius:50%%;background:%s;display:inline-block;border:2px solid rgba(0,0,0,.1)"></span>',
                    $title,
                    $color
                );
            }
            echo '</div>';
        } else {
            echo '<p style="color:#999">Aucun check enregistré.</p>';
        }

        // Boutons.
        echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">';

        echo '<form method="post" style="margin:0">';
        wp_nonce_field('ahe_clear_uptime_action');
        echo '<button type="submit" name="ahe_clear_uptime" value="1" class="button button-small">Effacer historique</button>';
        echo '</form>';

        echo '<button id="ahe-test-btn" class="button button-primary button-small">Tester endpoint</button>';
        echo '</div>';

        echo '<pre id="ahe-test-result" style="margin-top:10px;background:#f5f5f5;padding:10px;border-radius:4px;max-height:300px;overflow:auto;display:none;font-size:12px"></pre>';

        printf(
            '<script>
        document.getElementById("ahe-test-btn").addEventListener("click", function() {
            var btn = this;
            var pre = document.getElementById("ahe-test-result");
            btn.disabled = true;
            btn.textContent = "Chargement…";
            pre.style.display = "block";
            pre.textContent = "";
            fetch(ajaxurl + "?action=ahe_test_endpoint&_wpnonce=%s")
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (d.success) {
                        pre.textContent = JSON.stringify(d.data, null, 2);
                    } else {
                        pre.textContent = "Erreur : " + (d.data || "inconnue");
                    }
                })
                .catch(function(e){ pre.textContent = "Erreur réseau : " + e.message; })
                .finally(function(){ btn.disabled = false; btn.textContent = "Tester endpoint"; });
        });
        </script>',
            esc_js(wp_create_nonce('ahe_test_endpoint'))
        );

        echo '</div>';
    }


    /* ══════════════════════════════════════════════
       AJAX Test (pas de token dans le DOM)
       ══════════════════════════════════════════════ */

    public static function ajax_test_endpoint()
    {
        check_ajax_referer('ahe_test_endpoint');

        if (! current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $token = get_option(self::OPTION_TOKEN, '');
        $url   = get_rest_url(null, 'ahe/v1/health');
        $resp  = wp_remote_get(
            $url,
            array(
                'timeout'   => 10,
                'sslverify' => false,
                'headers'   => array(
                    'X-Health-Token' => $token,
                ),
            )
        );

        if (is_wp_error($resp)) {
            wp_send_json_error($resp->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (! $body) {
            wp_send_json_error('Invalid JSON response');
        }

        wp_send_json_success($body);
    }

    /* ══════════════════════════════════════════════
       Page Admin (Outils → Advanced Health)
       ══════════════════════════════════════════════ */

    public static function admin_menu()
    {
        add_management_page(
            'Advanced Health Endpoint',
            'Advanced Health',
            'manage_options',
            'advanced-health-endpoint',
            array(__CLASS__, 'render_admin_page')
        );
    }

    public static function render_admin_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $token = get_option(self::OPTION_TOKEN, '');
        $url   = get_rest_url(null, 'ahe/v1/health');

        $url_header = $url;
        $url_param  = add_query_arg('token', $token, $url);
        $url_public = $url;
?>
        <div class="wrap">
            <h1>🩺 Advanced Health Endpoint <small>v<?php echo esc_html(self::VERSION); ?></small></h1>

            <h2>🔑 Token d'accès</h2>
            <table class="form-table">
                <tr>
                    <th>Token actuel</th>
                    <td><code
                            style="font-size:14px;background:#f0f0f0;padding:6px 12px;border-radius:4px"><?php echo esc_html($token); ?></code>
                    </td>
                </tr>
            </table>

            <form method="post" style="margin-bottom:20px">
                <?php wp_nonce_field('ahe_regenerate_token_action'); ?>
                <button type="submit" name="ahe_regenerate_token" value="1" class="button"
                    onclick="return confirm('Régénérer le token ? Les services configurés (UptimeRobot…) devront être mis à jour.');">
                    🔄 Régénérer le token
                </button>
            </form>

            <hr>

            <h2>📡 Accès à l'endpoint</h2>

            <h3>Méthode 1 — Header <code>X-Health-Token</code> (recommandé)</h3>
            <pre style="background:#23282d;color:#fff;padding:15px;border-radius:4px;overflow-x:auto">curl -s -H "X-Health-Token: <?php echo esc_html($token); ?>" \
  <?php echo esc_html($url_header); ?> | jq .</pre>

            <h3>Méthode 2 — Query string (UptimeRobot gratuit)</h3>
            <pre
                style="background:#23282d;color:#fff;padding:15px;border-radius:4px;overflow-x:auto">curl -s "<?php echo esc_html($url_param); ?>" | jq .</pre>

            <h3>Méthode 3 — Sans token (réponse minimale)</h3>
            <pre
                style="background:#23282d;color:#fff;padding:15px;border-radius:4px;overflow-x:auto">curl -s <?php echo esc_html($url_public); ?> | jq .</pre>

            <hr>

            <h2>🤖 Configuration UptimeRobot</h2>
            <table class="widefat" style="max-width:600px">
                <tr>
                    <td><strong>Type</strong></td>
                    <td>HTTP(s) — Keyword</td>
                </tr>
                <tr>
                    <td><strong>URL</strong></td>
                    <td><code><?php echo esc_html($url_param); ?></code></td>
                </tr>
                <tr>
                    <td><strong>URL alternative (header)</strong></td>
                    <td><code><?php echo esc_html($url_header); ?></code><br><small>Avec header custom
                            <code>X-Health-Token: <?php echo esc_html($token); ?></code></small></td>
                </tr>
                <tr>
                    <td><strong>Keyword</strong></td>
                    <td><code>"status":"OK"</code></td>
                </tr>
                <tr>
                    <td><strong>Interval</strong></td>
                    <td>5 minutes</td>
                </tr>
            </table>

            <hr>

            <h2>ℹ️ Sécurité</h2>
            <ul style="max-width:800px;font-size:13px">
                <li>✅ L'endpoint public ne révèle <strong>aucune information</strong> sensible</li>
                <li>✅ Rate limiting : <?php echo esc_html(self::RATE_MAX); ?> requêtes /
                    <?php echo esc_html(self::RATE_WINDOW); ?>s par IP</li>
                <li>✅ Préférez le header <code>X-Health-Token</code> : le token n'apparaît pas dans les logs serveur</li>
                <li>✅ Le test AJAX ne transite <strong>jamais</strong> le token côté navigateur</li>
                <li>✅ Nettoyage automatique hebdomadaire des transients de rate limiting</li>
                <li>✅ Les transients et crons sont nettoyés à la désactivation du plugin</li>
            </ul>
        </div>
<?php
    }
}

/* ══════════════════════════════════════════════
   Hooks d'activation / désactivation
   ══════════════════════════════════════════════ */
register_activation_hook(__FILE__, array('Advanced_Health_Endpoint', 'activate'));
register_deactivation_hook(__FILE__, array('Advanced_Health_Endpoint', 'deactivate'));

/* ══════════════════════════════════════════════
   Boot
   ══════════════════════════════════════════════ */
add_action('plugins_loaded', array('Advanced_Health_Endpoint', 'init'));
