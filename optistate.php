<?php
/**
 * Plugin Name: Optimal State
 * Plugin URI: https://payhip.com/optistate
 * Description: Advanced WordPress optimization suite featuring integrated database cleanup and backup tools, page caching, and diagnostic tools.
 * Version: 1.2.0
 * Author: Luke Garrison
 * Author URI: https://payhip.com/optistate
 * Text Domain: optistate
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.5
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) {
    exit;
}
class OPTISTATE_Process_Store {
    private $table_name;
    private static $runtime_cache = [];
    private const CACHE_GROUP = 'optistate_processes';
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'optistate_processes';
    }
    public function get_table_name() {
        return $this->table_name;
    }
    public function set($key, $value, $expiration = 0) {
        global $wpdb;
        self::$runtime_cache[$key] = $value;
        wp_cache_set($key, $value, self::CACHE_GROUP, $expiration);
        $json_value = wp_json_encode($value);
        $expire_time = ($expiration > 0) ? time() + absint($expiration) : 0;
        $created_at = current_time('mysql');
        $sql = "INSERT INTO {$this->table_name} (process_key, process_value, expiration, created_at) 
                VALUES (%s, %s, %d, %s) 
                ON DUPLICATE KEY UPDATE 
                process_value = VALUES(process_value), 
                expiration = VALUES(expiration), 
                created_at = VALUES(created_at)";
        $suppress = $wpdb->suppress_errors(true);
        $result = $wpdb->query($wpdb->prepare($sql, $key, $json_value, $expire_time, $created_at));
        $wpdb->suppress_errors($suppress);
        return $result !== false;
    }
    public function get($key) {
        if (array_key_exists($key, self::$runtime_cache)) {
            return self::$runtime_cache[$key];
        }
        $cached_value = wp_cache_get($key, self::CACHE_GROUP);
        if ($cached_value !== false) {
            if ($cached_value === '___NOT_FOUND___') {
                self::$runtime_cache[$key] = false;
                return false;
            }
            self::$runtime_cache[$key] = $cached_value;
            return $cached_value;
        }
        global $wpdb;
        $suppress = $wpdb->suppress_errors(true);
            $row = $wpdb->get_row($wpdb->prepare(
            "SELECT process_value FROM {$this->table_name} 
            WHERE process_key = %s 
            AND (expiration = 0 OR expiration > %d)", 
            $key, 
            time()
        ));
        $wpdb->suppress_errors($suppress);
        if (!$row) {
            wp_cache_set($key, '___NOT_FOUND___', self::CACHE_GROUP, 60);
            return false;
        }
        $decoded = json_decode($row->process_value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }
        self::$runtime_cache[$key] = $decoded;
        wp_cache_set($key, $decoded, self::CACHE_GROUP, 300);
        return $decoded;
    }
    public function delete($key) {
        global $wpdb;
        unset(self::$runtime_cache[$key]);
        wp_cache_delete($key, self::CACHE_GROUP);
        return $wpdb->delete($this->table_name, ['process_key' => $key], ['%s']);
    }
    public function cleanup() {
        global $wpdb;
        self::$runtime_cache = [];
        $suppress = $wpdb->suppress_errors(true);
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->table_name} WHERE expiration > 0 AND expiration < %d", time()));
        $wpdb->suppress_errors($suppress);
    }
    public function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            process_key varchar(191) NOT NULL,
            process_value longtext NOT NULL,
            expiration bigint(20) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY process_key (process_key),
            KEY expiration (expiration)
        ) $charset_collate;";
        if (!function_exists('dbDelta')) {
            require_once (ABSPATH . 'wp-admin/includes/upgrade.php');
        }
        dbDelta($sql);
    }
    public function drop_table() {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$this->table_name}");
    }
}
class OPTISTATE {
    const PLUGIN_NAME = "Optimal State (Free)";
    const VERSION = "1.2.0";
    const OPTION_NAME = "optistate_settings";
    const NONCE_ACTION = "optistate_nonce";
    const STATS_TRANSIENT = 'optistate_db_metrics_cache_v2';
    const STATS_CACHE_DURATION = 12 * HOUR_IN_SECONDS;
    const SETTINGS_DIR_NAME = 'optistate-settings';
    const BACKUP_DIR_NAME = 'optistate/db-backups';
    const TEMP_DIR_NAME = 'optistate/db-restore-temp';
    const CACHE_DIR_NAME = 'optistate/page-cache';
    const SETTINGS_FILE_NAME = 'settings.json';
    const LOG_FILE_NAME = 'optimization-log.json';
    const GENERATED_SETTINGS_FILE_NAME = 'generated-settings.php';
    const REGEX_BOUNDARY_FMT = '/(?:^|[^\p{L}\p{N}_])%s(?:[^\p{L}\p{N}_]|$)/%s';
    const TRACKING_PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid', 'msclkid', 'mc_cid', 'mc_eid', '_ga', 'ref', 'source'];
    private $db_backup_manager;
    public $wp_filesystem;
    private $process_store;
    private $settings_file_path;
    private $log_file_path;
    private $backup_dir;
    private $temp_dir;
    private $cache_dir;
    private $server_caching_settings;
    private $performance_feature_definitions = [];
    private $is_revisions_defined;
    private $is_trash_days_defined;
    private $cache_path_cache = [];
    private $settings_cache = null;
    private $compiled_exclude_patterns = null;
    private $exclude_urls_raw_cache = null;
    private $performance_settings_cache = null;
    private $combined_consent_patterns = null;
    private $is_mobile_request = null;
    private $upload_dir_info;
    public function __construct() {
        $this->wp_filesystem = $this->init_wp_filesystem();
        $this->process_store = new OPTISTATE_Process_Store();
        $this->upload_dir_info = wp_upload_dir();
        if (!$this->wp_filesystem) {
            add_action('admin_notices', function () {
                if (current_user_can('manage_options')) {
                    echo '<div class="notice notice-error is-dismissible">';
                    echo '<h3>' . esc_html__('WP Optimal State - Critical Error', 'optistate') . '</h3>';
                    echo '<p>' . esc_html__('The plugin cannot access the file system. This is usually caused by:', 'optistate') . '</p>';
                    echo '<ul style="list-style-type: disc; margin-left: 20px;">';
                    echo '<li>' . esc_html__('Incorrect file permissions on wp-content/uploads/', 'optistate') . '</li>';
                    echo '<li>' . esc_html__('Server configuration preventing file operations', 'optistate') . '</li>';
                    echo '<li>' . esc_html__('FTP credentials not configured (if using FTP mode)', 'optistate') . '</li>';
                    echo '</ul>';
                    echo '<p><strong>' . esc_html__('Action required:', 'optistate') . '</strong> ';
                    echo esc_html__('Contact your hosting provider to resolve file system access issues.', 'optistate') . '</p>';
                    echo '</div>';
                }
            }, 5);
            return;
        }
        $this->early_cache_check();
        $base_dir = trailingslashit($this->upload_dir_info['basedir']);
        $plugin_data_dir = $base_dir . self::SETTINGS_DIR_NAME . '/';
        $this->settings_file_path = $plugin_data_dir . self::SETTINGS_FILE_NAME;
        $this->log_file_path = $plugin_data_dir . self::LOG_FILE_NAME;
        $this->backup_dir = $base_dir . self::BACKUP_DIR_NAME . '/';
        $this->temp_dir = $base_dir . self::TEMP_DIR_NAME . '/';
        $this->cache_dir = $base_dir . self::CACHE_DIR_NAME . '/';
        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX) || (defined('WP_CLI') && WP_CLI)) {
            $this->ensure_directories_exist();
            $config_constants = get_transient('optistate_config_constants');
            if ($config_constants === false) {
                $config_constants = ['WP_POST_REVISIONS' => $this->is_constant_in_wp_config('WP_POST_REVISIONS'), 'EMPTY_TRASH_DAYS' => $this->is_constant_in_wp_config('EMPTY_TRASH_DAYS') ];
                set_transient('optistate_config_constants', $config_constants, 12 * HOUR_IN_SECONDS);
            }
            $this->is_revisions_defined = $config_constants['WP_POST_REVISIONS'];
            $this->is_trash_days_defined = $config_constants['EMPTY_TRASH_DAYS'];
        } else {
            $this->is_revisions_defined = false;
        }
        $settings = $this->get_persistent_settings();
        $this->db_backup_manager = new OPTISTATE_Backup_Manager($this, $this->log_file_path, $settings["max_backups"], $this->process_store);
        $this->_performance_init_features();
        $this->apply_performance_optimizations();
        $this->register_wordpress_hooks();
        $this->register_ajax_handlers();
        add_action("optistate_run_pagespeed_worker", [$this, "run_pagespeed_worker"]);
        add_action("init", [$this, "protect_settings_file"]);
        add_action("init", [$this, "handle_settings_download"]);
        add_action("admin_notices", [$this, "display_permission_warnings"]);
        add_action('admin_notices', [$this, 'display_restore_completion_notice']);
        add_action('deleted_user', [$this, 'cleanup_deleted_user_from_access_list']);
    }
    public function init_wp_filesystem() {
        if ($this->wp_filesystem) {
            return $this->wp_filesystem;
        }
        global $wp_filesystem;
        if (!empty($wp_filesystem)) {
            $this->wp_filesystem = $wp_filesystem;
            return $wp_filesystem;
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        if (WP_Filesystem()) {
            $this->wp_filesystem = $wp_filesystem;
            return $wp_filesystem;
        }
        return null;
    }
    private function ensure_directories_exist() {
        if (!$this->wp_filesystem) return;
        $plugin_data_dir = dirname($this->settings_file_path);
        static $checked = false;
        if ($checked) return;
        if (!$this->wp_filesystem->is_dir($plugin_data_dir)) {
            wp_mkdir_p($plugin_data_dir);
        }
        foreach ([$this->cache_dir, $this->backup_dir] as $dir) {
            if (!$this->wp_filesystem->is_dir($dir)) {
                if (wp_mkdir_p($dir)) {
                    $this->wp_filesystem->chmod($dir, 0755);
                }
            }
        }
        $this->secure_cache_directory();
        $this->protect_settings_file();
        if (!$this->wp_filesystem->is_dir($this->temp_dir)) {
            if (wp_mkdir_p($this->temp_dir)) {
                $this->wp_filesystem->chmod($this->temp_dir, 0750);
            }
        }
        $checked = true;
    }
    public function check_rate_limit($action, $duration_in_seconds = 10) {
        $user_id = get_current_user_id();
        if ($user_id === 0) return false;
        $transient_key = 'optistate_rl_' . $user_id;
        $timestamps = get_transient($transient_key);
        if (!is_array($timestamps)) $timestamps = [];
        $current_time = time();
        if (isset($timestamps[$action])) {
            $last_called = (int)$timestamps[$action];
            if (($current_time - $last_called) < $duration_in_seconds) return false;
        }
        $timestamps[$action] = $current_time;
        foreach ($timestamps as $act => $time) {
            if (($current_time - (int)$time) > 600) unset($timestamps[$act]);
        }
        set_transient($transient_key, $timestamps, 10 * MINUTE_IN_SECONDS);
        return true;
    }
    private function detect_server_type() {
        static $server_type = null;
        if ($server_type !== null) {
            return $server_type;
        }
        if (isset($_SERVER['SERVER_SOFTWARE']) && stripos($_SERVER['SERVER_SOFTWARE'], 'nginx') !== false) {
            $server_type = 'nginx';
        } elseif (isset($_SERVER['SERVER_SOFTWARE']) && stripos($_SERVER['SERVER_SOFTWARE'], 'apache') !== false) {
            $server_type = 'apache';
        } elseif (function_exists('apache_get_modules')) {
            $server_type = 'apache';
        } else {
            $server_type = 'unknown';
        }
        return $server_type;
    }
    public function format_timestamp($timestamp, $is_utc = false) {
        $format = get_option('date_format') . ' ' . get_option('time_format');
        return wp_date($format, $timestamp);
    }
    public function get_total_database_size($force_refresh = false) {
        if (!$force_refresh) {
            $full_stats = get_transient(self::STATS_TRANSIENT);
            if (is_array($full_stats) && isset($full_stats['total_db_size_bytes'])) {
                return (float)$full_stats['total_db_size_bytes'];
            }
            $cached_size = get_transient('optistate_db_size_cache');
            if ($cached_size !== false) {
                return (float)$cached_size;
            }
        }
        global $wpdb;
        $query = $wpdb->prepare("
        SELECT SUM(data_length + index_length) 
        FROM information_schema.TABLES 
        WHERE table_schema = %s
    ", DB_NAME);
        $total_db_size = (float)$wpdb->get_var($query);
        if ($total_db_size === 0.0) {
            $tables = $wpdb->get_results("SHOW TABLE STATUS FROM `" . DB_NAME . "`");
            if ($tables) {
                foreach ($tables as $table) {
                    $total_db_size+= ((float)$table->Data_length + (float)$table->Index_length);
                }
            }
        }
        set_transient('optistate_db_size_cache', $total_db_size, HOUR_IN_SECONDS);
        return $total_db_size;
    }
    private function check_required_permissions() {
        $issues = [];
        if (!$this->wp_filesystem) {
            $issues[] = __("WP_Filesystem is not initialized. File operations cannot proceed.", "optistate");
            return $issues;
        }
        $upload_dir = wp_upload_dir();
        $plugin_data_dir = trailingslashit($upload_dir['basedir']) . self::SETTINGS_DIR_NAME . '/';
        if (!$this->wp_filesystem->is_writable($plugin_data_dir)) {
            $issues[] = __("Plugin data directory is not writable. Settings and logs cannot be saved.", "optistate");
        }
        if (!$this->wp_filesystem->is_dir($this->backup_dir)) {
            if (!wp_mkdir_p($this->backup_dir)) {
                $issues[] = __("Backup directory could not be created.", "optistate");
            }
        } elseif (!$this->wp_filesystem->is_writable($this->backup_dir)) {
            $issues[] = __("Backup directory is not writable. Database backups cannot be created.", "optistate");
        }
        return empty($issues) ? true : $issues;
    }
    public function display_permission_warnings() {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, "optistate") === false || !current_user_can("manage_options")) {
            return;
        }
        $permission_issues = $this->check_required_permissions();
        if ($permission_issues !== true) { ?>
        <div class="notice notice-error is-dismissible">
            <h3><?php echo esc_html__("WP Optimal State - Permission Issues", "optistate"); ?></h3>
            <p><?php echo esc_html__("The following issues prevent the plugin from functioning properly:", "optistate"); ?></p>
            <ul style="list-style-type: disc; margin-left: 20px;">
                <?php foreach ($permission_issues as $issue): ?>
                    <li><?php echo esc_html($issue); ?></li>
                <?php
            endforeach; ?>
            </ul>
            <p>
                <strong><?php echo esc_html__("How to fix:", "optistate"); ?></strong><br>
                <?php echo esc_html__("Please ensure the following directories have write permissions (typically 755 or higher):", "optistate"); ?>
            </p>
            <ul style="list-style-type: disc; margin-left: 20px;">
                <li><code><?php echo esc_html($this->backup_dir); ?></code></li>
                <li><code><?php echo esc_html($this->temp_dir); ?></code></li>
            </ul>
            <p>
                <?php echo esc_html__("You may need to contact your hosting provider to adjust these permissions.", "optistate"); ?>
            </p>
        </div>
        <?php
        }
    }
    public function secure_directory($dir_path, $htaccess_rules) {
        if (!$this->wp_filesystem || !$this->wp_filesystem->is_dir($dir_path)) {
            return false;
        }
        $htaccess_file = trailingslashit($dir_path) . ".htaccess";
        if (!$this->wp_filesystem->exists($htaccess_file)) {
            $htaccess_content = implode(PHP_EOL, $htaccess_rules);
            $this->wp_filesystem->put_contents($htaccess_file, $htaccess_content, FS_CHMOD_FILE);
        }
        $index_file = trailingslashit($dir_path) . "index.php";
        if (!$this->wp_filesystem->exists($index_file)) {
            $index_content = "<?php\n// Silence is golden\n// WP Optimal State Secure Directory\nhttp_response_code(403);\nexit;";
            $this->wp_filesystem->put_contents($index_file, $index_content, FS_CHMOD_FILE);
        }
        $index_html = trailingslashit($dir_path) . "index.html";
        if (!$this->wp_filesystem->exists($index_html)) {
            $html_content = "<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>Access Denied</h1></body></html>";
            $this->wp_filesystem->put_contents($index_html, $html_content, FS_CHMOD_FILE);
        }
        return true;
    }
    public function display_restore_completion_notice() {
        $restore_completed = get_option('optistate_restore_completed');
        if ($restore_completed && is_array($restore_completed)) {
            delete_option('optistate_restore_completed');
            $time_ago = human_time_diff($restore_completed['timestamp'], current_time('timestamp'));
?>
        <div class="notice notice-success is-dismissible" style="border-left: 4px solid #46b450; padding: 15px; margin: 20px 0;">
            <h2 style="margin-top: 0; color: #46b450;">
                <span class="dashicons dashicons-yes-alt" style="font-size: 28px; width: 28px; height: 28px; vertical-align: middle;"></span>
                <?php echo esc_html__('Database Restore Completed Successfully!', 'optistate'); ?>
            </h2>
            <p style="font-size: 15px; margin: 10px 0;">
                <strong><?php echo esc_html__('Your database has been fully restored.', 'optistate'); ?></strong>
            </p>
            <p style="margin: 5px 0;">
                <?php echo '📁 '; ?>
                <strong><?php echo esc_html__('Backup file:', 'optistate'); ?></strong> 
                <?php echo esc_html($restore_completed['filename']); ?>
            </p>
            <p style="margin: 5px 0;">
                <?php echo '⏰ '; ?>
                <strong><?php echo esc_html__('Completed:', 'optistate'); ?></strong> 
                <?php echo esc_html(sprintf(__('Less than %s ago', 'optistate'), $time_ago)); ?>
            </p>
            <p style="margin: 5px 0;">
                <?php echo '🔢 '; ?>
                <strong><?php echo esc_html__('Queries executed:', 'optistate'); ?></strong> 
                <?php echo esc_html(number_format_i18n($restore_completed['queries'])); ?>
            </p>
            <p style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; color: #666;">
                <?php echo 'ℹ️ '; ?>
                <?php echo esc_html__('You were logged out because the database was replaced, causing your login session to reset.', 'optistate'); ?>
            </p>
        </div>
        <?php
        }
    }
    private function register_wordpress_hooks() {
        add_action("admin_menu", [$this, "add_admin_menu"]);
        add_action("admin_enqueue_scripts", [$this, "enqueue_admin_assets"]);
        add_action('transition_post_status', [$this, 'on_post_status_transition'], 10, 3);
        add_action('post_updated', [$this, 'on_post_updated'], 10, 3);
        add_action('transition_comment_status', [$this, 'on_comment_status_transition'], 10, 3);
        add_action('edited_term', [$this, 'on_edited_term'], 10, 3);
        add_action('wp_update_nav_menu', [$this, 'purge_entire_cache']);
        add_action('widget_update_callback', [$this, 'purge_entire_cache']);
        add_action('customize_save_after', [$this, 'purge_entire_cache']);
        add_action("init", [$this, "validate_consent_for_session"], 20);
    }
    private function detect_mobile() {
        if ($this->is_mobile_request !== null) {
            return $this->is_mobile_request;
        }
        if (function_exists('wp_is_mobile')) {
            $this->is_mobile_request = wp_is_mobile();
            return $this->is_mobile_request;
        }
        $user_agent = $_SERVER['HTTP_USER_AGENT']??'';
        if (empty($user_agent)) {
            $this->is_mobile_request = false;
            return false;
        }
        static $mobile_pattern = null;
        if ($mobile_pattern === null) {
            $mobile_patterns = ['iPhone', 'iPod', 'Android.*Mobile', 'Windows Phone', 'IEMobile', 'BlackBerry', 'BB10', 'webOS', 'Palm', 'Symbian', 'iPad', 'Android(?!.*Mobile)', 'Kindle', 'Silk/', 'Tablet', 'Opera Mini', 'Opera Mobi', 'Mobile Safari'];
            $mobile_pattern = '/(' . implode('|', $mobile_patterns) . ')/i';
        }
        $this->is_mobile_request = (bool)preg_match($mobile_pattern, wp_unslash($user_agent));
        return $this->is_mobile_request;
    }
    private function get_consent_cookie_patterns() {
        return ['cookieyes-consent', 'cky-consent', 'complianz_consent_status', 'complianz_policy_id', 'cmplz_', 'cookie_notice_accepted', 'cookie-notice-accepted', 'cookielawinfo-checkbox-necessary', 'cookielawinfo-checkbox-analytics', 'cli_user_preference', 'viewed_cookie_policy', 'borlabs-cookie', 'BorlabsCookie', 'real-cookie-banner', 'rcb-consent', 'CookieConsent', 'CookieConsentBulkSetting', 'OptanonConsent', 'OptanonAlertBoxClosed', 'termly-consent', 'iubenda-cookie-consent', '_iub_cs-', 'cc_cookie', 'cookie_control', 'moove_gdpr_popup', 'wp_gdpr_cookie_consent', 'gdpr_cookie_consent', 'euconsent-v2', 'cookie_consent', 'cookie-consent'];
    }
    private function get_combined_consent_patterns() {
        if ($this->combined_consent_patterns !== null) {
            return $this->combined_consent_patterns;
        }
        $patterns = $this->get_consent_cookie_patterns();
        $custom_cookie_string = $this->server_caching_settings['custom_consent_cookie']??'';
        if ($custom_cookie_string === '' && empty($this->server_caching_settings)) {
            $perf_settings = $this->_performance_get_settings();
            $custom_cookie_string = $perf_settings['server_caching']['custom_consent_cookie']??'';
        }
        if (!empty($custom_cookie_string)) {
            $normalized_string = str_replace(["\n", "\r", " "], ',', $custom_cookie_string);
            $custom_patterns = array_filter(array_map('trim', explode(',', $normalized_string)));
            if (!empty($custom_patterns)) {
                $patterns = array_unique(array_merge($patterns, $custom_patterns));
            }
        }
        $this->combined_consent_patterns = $patterns;
        return $this->combined_consent_patterns;
    }
    public function validate_consent_for_session() {
        $perf_settings = $this->_performance_get_settings();
        $server_caching = $perf_settings['server_caching']??[];
        if (empty($server_caching['enabled']) || !empty($server_caching['disable_cookie_check'])) {
            return;
        }
        if (isset($_COOKIE['optistate_session_validated'])) {
            return;
        }
        $path = defined('COOKIEPATH') ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        $secure = is_ssl();
        if ($this->has_any_consent_cookie()) {
            if (!isset($_COOKIE['optistate_consent_flag'])) {
                setcookie('optistate_consent_flag', '1', time() + YEAR_IN_SECONDS, $path, $domain, $secure, true);
            }
            setcookie('optistate_session_validated', '1', 0, $path, $domain, $secure, true);
        } else {
            $expires = time() - YEAR_IN_SECONDS;
            if (isset($_COOKIE['optistate_consent_flag'])) {
                setcookie('optistate_consent_flag', '', $expires, $path, $domain, $secure, true);
            }
            if (isset($_COOKIE['optistate_session_validated'])) {
                setcookie('optistate_session_validated', '', $expires, $path, $domain, $secure, true);
            }
        }
    }
    public function force_plain_text_mail_type($content_type) {
        return 'text/plain';
    }
    public function strip_all_content_filters($message) {
        $message = wp_strip_all_tags($message);
        $message = html_entity_decode($message, ENT_QUOTES, 'UTF-8');
        return $message;
    }
    public function get_persistent_settings() {
        if ($this->settings_cache !== null) {
            return $this->settings_cache;
        }
        $cache_key = 'optistate_settings_blob';
        $cache_group = 'optistate';
        $cached_settings = wp_cache_get($cache_key, $cache_group);
        if ($cached_settings !== false && is_array($cached_settings)) {
            $this->settings_cache = $cached_settings;
            return $cached_settings;
        }
        $defaults = ["max_backups" => 1, "auto_optimize_days" => 0, "auto_optimize_time" => "02:00", "email_notifications" => false, "auto_backup_only" => false, "performance_features" => [], "disable_restore_security" => false, "allowed_users" => [], "pagespeed_api_key" => "AIzaSyDGo4ufkdrd2e8za0IRz_qnX-GWQdaBA2s"];
        $upload_dir = wp_upload_dir();
        $php_config_path = trailingslashit($upload_dir['basedir']) . self::SETTINGS_DIR_NAME . '/' . self::GENERATED_SETTINGS_FILE_NAME;
        $settings = null;
        if (file_exists($php_config_path)) {
            $settings = include $php_config_path;
            if (is_array($settings)) {
                if (!empty($settings['pagespeed_api_key'])) {
                    $settings['pagespeed_api_key'] = $this->decrypt_data($settings['pagespeed_api_key']);
                }
            }
        }
        if (!is_array($settings)) {
            $json_data = $this->secure_file_read($this->settings_file_path);
            if ($json_data !== false) {
                $settings = json_decode($json_data, true);
            }
        }
        if (!is_array($settings)) {
            $settings = $defaults;
        }
        $validated = [];
        $validated["max_backups"] = 1;
        $validated["auto_optimize_days"] = 0;
        $validated["auto_optimize_time"] = isset($settings["auto_optimize_time"]) && preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $settings["auto_optimize_time"]) ? $settings["auto_optimize_time"] : $defaults["auto_optimize_time"];
        $validated["email_notifications"] = isset($settings["email_notifications"]) ? (bool)$settings["email_notifications"] : $defaults["email_notifications"];
        $validated["auto_backup_only"] = isset($settings["auto_backup_only"]) ? (bool)$settings["auto_backup_only"] : $defaults["auto_backup_only"];
        $validated["performance_features"] = isset($settings["performance_features"]) && is_array($settings["performance_features"]) ? $settings["performance_features"] : $defaults["performance_features"];
        $validated["disable_restore_security"] = isset($settings["disable_restore_security"]) ? (bool)$settings["disable_restore_security"] : $defaults["disable_restore_security"];
        $validated["allowed_users"] = isset($settings["allowed_users"]) && is_array($settings["allowed_users"]) ? array_map('absint', $settings["allowed_users"]) : $defaults["allowed_users"];
        $raw_key = isset($settings["pagespeed_api_key"]) ? $settings["pagespeed_api_key"] : $defaults["pagespeed_api_key"];
        $validated["pagespeed_api_key"] = $this->decrypt_data($raw_key);
        wp_cache_set($cache_key, $validated, $cache_group, HOUR_IN_SECONDS);
        $this->settings_cache = $validated;
        return $validated;
    }
    public function check_user_access() {
        if (!current_user_can('manage_options')) {
            return false;
        }
        return true;
    }
    public function cleanup_deleted_user_from_access_list($user_id) {
        $settings = $this->get_persistent_settings();
        $allowed_users = $settings['allowed_users']??[];
        if (empty($allowed_users)) {
            return;
        }
        $key = array_search($user_id, $allowed_users, true);
        if ($key !== false) {
            unset($allowed_users[$key]);
            $allowed_users = array_values($allowed_users);
            $this->save_persistent_settings(['allowed_users' => $allowed_users]);
            $this->log_optimization('scheduled', sprintf('🗑️ Removed deleted user (ID: %s) from access list', number_format_i18n($user_id)), '');
        }
    }
    public function save_persistent_settings($new_settings) {
        $current_settings = $this->get_persistent_settings();
        $settings_to_save = array_merge($current_settings, $new_settings);
        $settings_to_save["max_backups"] = 1;
        $settings_to_save["auto_optimize_days"] = 0;
        if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $settings_to_save["auto_optimize_time"])) {
            $settings_to_save["auto_optimize_time"] = "02:00";
        }
        $settings_to_save["email_notifications"] = (bool)$settings_to_save["email_notifications"];
        $settings_to_save["auto_backup_only"] = (bool)($settings_to_save["auto_backup_only"]??false);
        if (!isset($settings_to_save["performance_features"]) || !is_array($settings_to_save["performance_features"])) {
            $settings_to_save["performance_features"] = [];
        }
        if (!isset($settings_to_save["allowed_users"]) || !is_array($settings_to_save["allowed_users"])) {
            $settings_to_save["allowed_users"] = [];
        } else {
            $settings_to_save["allowed_users"] = array_map('absint', array_unique($settings_to_save["allowed_users"]));
        }
        if (isset($settings_to_save["pagespeed_api_key"])) {
            if (strpos($settings_to_save["pagespeed_api_key"], 'enc:') !== 0) {
                $settings_to_save["pagespeed_api_key"] = $this->encrypt_data($settings_to_save["pagespeed_api_key"]);
            }
        }
        $json_data = json_encode($settings_to_save, JSON_PRETTY_PRINT);
        if ($json_data === false) {
            return false;
        }
        $success = $this->secure_file_write_atomic($this->settings_file_path, $json_data, true);
        if ($success) {
            $upload_dir = wp_upload_dir();
            $php_config_path = trailingslashit($upload_dir['basedir']) . self::SETTINGS_DIR_NAME . '/' . self::GENERATED_SETTINGS_FILE_NAME;
            $php_content = "<?php\ndefined('ABSPATH') || exit;\nreturn " . var_export($settings_to_save, true) . ";\n";
            $this->secure_file_write_atomic($php_config_path, $php_content, true);
            $this->settings_cache = null;
            wp_cache_delete('optistate_settings_blob', 'optistate');
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($php_config_path, true);
            }
            $this->settings_cache = null;
            if (function_exists('apcu_delete') && function_exists('apcu_enabled') && apcu_enabled()) {
                apcu_delete('optistate_early_settings_v1');
            }
        }
        return $success;
    }
    private function secure_cache_directory() {
        if (!$this->wp_filesystem || !$this->wp_filesystem->is_dir($this->cache_dir)) {
            return false;
        }
        $htaccess_file = $this->cache_dir . '.htaccess';
        if (!$this->wp_filesystem->exists($htaccess_file)) {
            $rules = ['# WP Optimal State - Secure Cache Directory', '# Prevent directory listing', 'Options -Indexes', '', '<IfModule mod_authz_core.c>', '    # Block PHP execution (defense-in-depth)', '    <FilesMatch "\.php$">', '        Require all denied', '    </FilesMatch>', '    # Only allow .html files to be served', '    <FilesMatch "^(?!.*\.html$)">', '        Require all denied', '    </FilesMatch>', '</IfModule>', '<IfModule !mod_authz_core.c>', '    # Block PHP execution (defense-in-depth)', '    <FilesMatch "\.php$">', '        Order deny,allow', '        Deny from all', '    </FilesMatch>', '    # Only allow .html files to be served', '    <FilesMatch "^(?!.*\.html$)">', '        Order deny,allow', '        Deny from all', '    </FilesMatch>', '</IfModule>', ];
            $htaccess_content = implode(PHP_EOL, $rules);
            $this->wp_filesystem->put_contents($htaccess_file, $htaccess_content, FS_CHMOD_FILE);
        }
        $index_file = $this->cache_dir . 'index.php';
        if (!$this->wp_filesystem->exists($index_file)) {
            $this->wp_filesystem->put_contents($index_file, "<?php\nhttp_response_code(403);\nexit;", FS_CHMOD_FILE);
        }
        return true;
    }
    private function get_trusted_host() {
        static $trusted_host = null;
        if ($trusted_host !== null) {
            return $trusted_host;
        }
        $wp_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $raw_host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $raw_host = explode(':', $raw_host) [0];
        if ($wp_host && strcasecmp($raw_host, $wp_host) === 0) {
            $trusted_host = $wp_host;
        } elseif (isset($_SERVER['SERVER_NAME'])) {
            $trusted_host = $_SERVER['SERVER_NAME'];
        } else {
            $trusted_host = preg_replace('/[^a-zA-Z0-9\-\.]/', '', $raw_host);
        }
        return $trusted_host;
    }
    private function early_cache_check() {
        if (defined('DOING_AJAX') || defined('DOING_CRON') || (function_exists('is_admin') && is_admin())) {
            return;
        }
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
            return;
        }
        $upload_dir = wp_upload_dir();
        $settings = null;
        $cache_key = 'optistate_early_settings_v1';
        if (function_exists('apcu_fetch') && function_exists('apcu_enabled') && apcu_enabled()) {
            $cached = apcu_fetch($cache_key, $success);
            if ($success && is_array($cached)) {
                $settings = $cached;
            }
        }
        if ($settings === null) {
            $php_config_path = trailingslashit($upload_dir['basedir']) . self::SETTINGS_DIR_NAME . '/' . self::GENERATED_SETTINGS_FILE_NAME;
            if (file_exists($php_config_path)) {
                $settings = include $php_config_path;
                if (is_array($settings) && function_exists('apcu_store') && function_exists('apcu_enabled') && apcu_enabled()) {
                    apcu_store($cache_key, $settings, 300);
                }
            }
        }
        if ($settings === null) {
            $db_settings = get_option(self::OPTION_NAME);
            if (is_array($db_settings)) {
                $settings = $db_settings;
            }
        }
        if (!is_array($settings) || empty($settings['performance_features']['server_caching']['enabled'])) {
            return;
        }
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            if (!WP_Filesystem()) {
                return;
            }
        }
        if (!$this->wp_filesystem) {
            return;
        }
        $this->cache_dir = trailingslashit(trailingslashit($upload_dir['basedir']) . self::CACHE_DIR_NAME . '/');
        $defaults = ['enabled' => false, 'lifetime' => 86400, 'query_string_mode' => 'include_safe', 'exclude_urls' => '', 'mobile_cache' => false, 'disable_cookie_check' => false];
        $this->server_caching_settings = array_merge($defaults, $settings['performance_features']['server_caching']);
        $is_mobile = false;
        if (!empty($this->server_caching_settings['mobile_cache'])) {
            $is_mobile = $this->detect_mobile();
        }
        $this->is_mobile_request = $is_mobile;
        static $shutdown_registered = false;
        if (!$shutdown_registered) {
            register_shutdown_function(function () {
                $error = error_get_last();
                if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                    $buffer_level = ob_get_level();
                    while ($buffer_level > 0) {
                        @ob_end_clean();
                        $buffer_level--;
                    }
                }
            });
            $shutdown_registered = true;
        }
        $this->maybe_serve_from_cache();
    }
    private function has_any_consent_cookie() {
        if (empty($_COOKIE) || !isset($_SERVER['HTTP_COOKIE'])) {
            return false;
        }
        static $pattern_regex = null;
        if ($pattern_regex === null) {
            $patterns = $this->get_combined_consent_patterns();
            if (empty($patterns)) {
                return false;
            }
            $escaped_patterns = array_map('preg_quote', $patterns);
            $pattern_regex = '/(?:^|;\s*|;)(' . implode('|', $escaped_patterns) . ')[^=]*=/i';
        }
        $cookie_header = sanitize_text_field(wp_unslash($_SERVER['HTTP_COOKIE']));
        return (bool)preg_match($pattern_regex, $cookie_header);
    }
    private function has_consent_cookie_early() {
        if (empty($_SERVER['HTTP_COOKIE'])) {
            return false;
        }
        $header = $_SERVER['HTTP_COOKIE'];
        $session_flag = 'optistate_session_validated=';
        $session_found = (strpos($header, $session_flag) === 0) || (strpos($header, '; ' . $session_flag) !== false) || (strpos($header, ';' . $session_flag) !== false);
        if (!$session_found) {
            return false;
        }
        $consent_flag = 'optistate_consent_flag=';
        $consent_found = (strpos($header, $consent_flag) === 0) || (strpos($header, '; ' . $consent_flag) !== false) || (strpos($header, ';' . $consent_flag) !== false);
        return $consent_found;
    }
    public function maybe_serve_from_cache() {
        if (isset($_SERVER['HTTP_COOKIE'])) {
            $cookie_header = sanitize_text_field(wp_unslash($_SERVER['HTTP_COOKIE']));
            if (preg_match('/(?:^|;\s*|;)wordpress_logged_in_[^=]*=/i', $cookie_header)) {
                return;
            }
        }
        if (isset($_GET['s'])) {
            return;
        }
        if (isset($_SERVER['HTTP_COOKIE'])) {
            $cookie_header = sanitize_text_field(wp_unslash($_SERVER['HTTP_COOKIE']));
            if (preg_match('/(?:^|;\s*|;)(woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session|edd_items_in_cart|wp_edd_session)[^=]*=/i', $cookie_header)) {
                return;
            }
        }
        if (!empty($_SERVER['QUERY_STRING'])) {
            foreach (self::TRACKING_PARAMS as $param) {
                if (isset($_GET[$param])) {
                    return;
                }
            }
        }
        $patterns = $this->get_compiled_exclude_patterns();
        if (!empty($patterns)) {
            $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw($_SERVER['REQUEST_URI']) : '';
            if ($request_uri !== '') {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $request_uri)) {
                        return;
                    }
                }
            }
        }
        $server_settings = is_array($this->server_caching_settings) ? $this->server_caching_settings : [];
        $cookie_check_disabled = !empty($server_settings['disable_cookie_check']);
        if (!$cookie_check_disabled && !$this->has_consent_cookie_early()) {
            return;
        }
        $http_host = $this->get_trusted_host();
        $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw($_SERVER['REQUEST_URI']) : '/';
        if (empty($http_host)) {
            return;
        }
        $is_mobile = $this->is_mobile_request;
        $cache_file = $this->get_cache_path($http_host, $request_uri, $is_mobile);
        if ($this->wp_filesystem->exists($cache_file)) {
            $lifetime = isset($server_settings['lifetime']) ? absint($server_settings['lifetime']) : 86400;
            $file_time = $this->wp_filesystem->mtime($cache_file);
            if ((time() - $file_time) < $lifetime) {
                $handle = @fopen($cache_file, 'rb');
                if ($handle) {
                    $header_buff = fread($handle, 4096);
                    if ($header_buff !== false && strlen($header_buff) >= 100 && strpos($header_buff, '<html') !== false) {
                        if (function_exists('apache_setenv')) {
                            @apache_setenv('PHP_CACHE_HEADERS', '1');
                        }
                        header('Cache-Control: public, max-age=' . $lifetime);
                        header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + $lifetime));
                        header('Last-Modified: ' . gmdate('D, d M Y H:i:s \G\M\T', $file_time));
                        header('Content-Type: text/html; charset=UTF-8');
                        $mobile_cache = !empty($server_settings['mobile_cache']);
                        header('Vary: ' . ($mobile_cache ? 'User-Agent, Accept-Encoding' : 'Accept-Encoding'));
                        echo $header_buff; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is pre-rendered HTML cache
                        fpassthru($handle);
                        $cache_type = $is_mobile ? 'Mobile' : 'Desktop';
                        echo "\n<!-- Cached by WP Optimal State ({$cache_type}) -->";
                        fclose($handle);
                        exit;
                    } else {
                        fclose($handle);
                        $this->wp_filesystem->delete($cache_file);
                    }
                } else {
                    $content = $this->wp_filesystem->get_contents($cache_file);
                    if ($content !== false && strlen($content) >= 100 && strpos($content, '<html') !== false) {
                        if (function_exists('apache_setenv')) {
                            @apache_setenv('PHP_CACHE_HEADERS', '1');
                        }
                        header('Cache-Control: public, max-age=' . $lifetime);
                        header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + $lifetime));
                        header('Last-Modified: ' . gmdate('D, d M Y H:i:s \G\M\T', $file_time));
                        header('Content-Type: text/html; charset=UTF-8');
                        $mobile_cache = !empty($server_settings['mobile_cache']);
                        header('Vary: ' . ($mobile_cache ? 'User-Agent, Accept-Encoding' : 'Accept-Encoding'));
                        $cache_type = $is_mobile ? 'Mobile' : 'Desktop';
                        echo $content . "\n<!-- Cached by WP Optimal State ({$cache_type}) -->"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        exit;
                    } else {
                        $this->wp_filesystem->delete($cache_file);
                    }
                }
            } else {
                $this->wp_filesystem->delete($cache_file);
            }
        }
        ob_start([$this, 'capture_and_cache_output']);
    }
    public function protect_settings_file() {
        $upload_dir = wp_upload_dir();
        $plugin_data_dir = trailingslashit($upload_dir['basedir']) . self::SETTINGS_DIR_NAME . '/';
        $rules = ['# WP Optimal State - Secure Settings Directory', '# Prevent directory listing', 'Options -Indexes', '', '# Block all direct web access', '<IfModule mod_authz_core.c>', '    Require all denied', '</IfModule>', '<IfModule !mod_authz_core.c>', '    Order deny,allow', '    Deny from all', '</IfModule>', ];
        $this->secure_directory($plugin_data_dir, $rules);
    }
    private function _performance_check_htaccess_writable() {
        if (!$this->wp_filesystem) {
            return ['writable' => false, 'exists' => false, 'path' => ABSPATH . '.htaccess', 'message' => __('WP_Filesystem is not initialized. File operations cannot proceed.', 'optistate') ];
        }
        $htaccess_path = get_home_path() . '.htaccess';
        $status = ['writable' => false, 'exists' => false, 'path' => $htaccess_path, 'message' => ''];
        if (!$this->wp_filesystem->exists($htaccess_path)) {
            $created = $this->wp_filesystem->put_contents($htaccess_path, '# WordPress htaccess' . PHP_EOL, FS_CHMOD_FILE);
            if ($created === false) {
                $status['message'] = __('.htaccess file does not exist and cannot be created. Check file permissions in your WordPress root directory.', 'optistate');
                return $status;
            }
            $status['exists'] = true;
        } else {
            $status['exists'] = true;
        }
        if (!$this->wp_filesystem->is_writable($htaccess_path)) {
            $status['message'] = __('.htaccess file exists but is not writable. Please set permissions to 644 or contact your hosting provider.', 'optistate');
            return $status;
        }
        $status['writable'] = true;
        $status['message'] = __('.htaccess file is writable and ready.', 'optistate');
        return $status;
    }
    private function _performance_apply_caching() {
        $server_type = $this->detect_server_type();
        if ($server_type !== 'apache') {
            return false;
        }
        $htaccess_check = $this->_performance_check_htaccess_writable();
        if (!$htaccess_check['writable']) {
            return false;
        }
        if (!$this->wp_filesystem) {
            return false;
        }
        $htaccess_path = $htaccess_check['path'];
        $current_content = $this->wp_filesystem->get_contents($htaccess_path);
        if ($current_content === false) {
            return false;
        }
        if (strpos($current_content, '# BEGIN WP Optimal State Caching') !== false) {
            return true;
        }
        $caching_rules = $this->_performance_get_caching_rules();
        $new_content = $caching_rules . PHP_EOL . $current_content;
        $bytes_written = $this->wp_filesystem->put_contents($htaccess_path, $new_content, FS_CHMOD_FILE);
        if ($bytes_written === false) {
            return false;
        }
        return true;
    }
    public function _performance_remove_caching() {
        $server_type = $this->detect_server_type();
        if ($server_type !== 'apache') {
            return true;
        }
        $htaccess_check = $this->_performance_check_htaccess_writable();
        if (!$htaccess_check['writable']) {
            return false;
        }
        if (!$this->wp_filesystem) {
            return false;
        }
        $htaccess_path = $htaccess_check['path'];
        $current_content = $this->wp_filesystem->get_contents($htaccess_path);
        if ($current_content === false) {
            return false;
        }
        $begin_comment = '# BEGIN WP Optimal State Caching';
        $end_comment = '# END WP Optimal State Caching';
        $separator = '# ============================================================';
        $pattern = '/\s*' . preg_quote($separator, '/') . '\s*\n' . '\s*' . preg_quote($begin_comment, '/') . '.*?' . preg_quote($end_comment, '/') . '\s*\n' . '\s*' . preg_quote($separator, '/') . '\s*\n?/s';
        $new_content = preg_replace($pattern, '', $current_content);
        $new_content = preg_replace("/\n{3,}/", "\n\n", trim($new_content));
        if (trim($new_content) === trim($current_content)) {
            return true;
        }
        $bytes_written = $this->wp_filesystem->put_contents($htaccess_path, $new_content, FS_CHMOD_FILE);
        if ($bytes_written === false) {
            return false;
        }
        return true;
    }
    private function _performance_get_caching_rules() {
        $rules = array('# ============================================================', '# BEGIN WP Optimal State Caching', '# ============================================================', '', '# ------------------------------', '# 1. EXPIRATION HEADERS (mod_expires)', '# ------------------------------', '<IfModule mod_expires.c>', '    ExpiresActive On', '', '    # Default: 30 days', '    ExpiresDefault "access plus 30 days"', '', '    # Static Assets: 1 year', '    ExpiresByType image/jpg "access plus 1 year"', '    ExpiresByType image/jpeg "access plus 1 year"', '    ExpiresByType image/png "access plus 1 year"', '    ExpiresByType image/gif "access plus 1 year"', '    ExpiresByType image/webp "access plus 1 year"', '    ExpiresByType image/svg+xml "access plus 1 year"', '    ExpiresByType image/x-icon "access plus 1 year"', '    ExpiresByType font/woff "access plus 1 year"', '    ExpiresByType font/woff2 "access plus 1 year"', '    ExpiresByType application/font-woff "access plus 1 year"', '', '    # CSS & JavaScript: 1 month', '    ExpiresByType text/css "access plus 1 month"', '    ExpiresByType application/javascript "access plus 1 month"', '    ExpiresByType application/x-javascript "access plus 1 month"', '', '    # HTML: Respect server-side caching headers (extended fallback)', '    ExpiresByType text/html "access plus 24 hours"', '</IfModule>', '', '# ------------------------------', '# 2. CACHE-CONTROL & SECURITY HEADERS (mod_headers)', '# ------------------------------', '<IfModule mod_headers.c>', '    # ---- Security ----', '    Header always set X-Content-Type-Options "nosniff"', '    Header always set X-Frame-Options "SAMEORIGIN"', '    Header always set Referrer-Policy "strict-origin-when-cross-origin"', '    Header always set X-XSS-Protection "1; mode=block"', '', '    # ---- Caching Rules ----', '    # Long cache for static assets', '    <FilesMatch "\.(css|js|ico|pdf|jpg|jpeg|png|gif|webp|svg|woff|woff2|eot|ttf|mp4|webm|mp3|ogg|wav|aac|m4a|flac)$">', '        Header set Cache-Control "max-age=31536000, public, immutable"', '    </FilesMatch>', '', '    # Dynamic content - extended browser caching (fallback when no PHP headers)', '    <FilesMatch "\.(php|html|htm)$">', '        Header set Cache-Control "public, max-age=86400" env=!PHP_CACHE_HEADERS', '    </FilesMatch>', '', '    # Protect sensitive WP files - always no cache', '    <FilesMatch "(wp-config\.php|readme\.html|license\.txt|wp-login\.php|wp-admin/|xmlrpc\.php)">', '        Header set Cache-Control "no-cache, no-store, must-revalidate"', '        Header set Pragma "no-cache"', '        Header set Expires "0"', '    </FilesMatch>', '', '    # Ensure proper encoding handling', '    <FilesMatch "\.(js|css|html|htm|xml|json)$">', '        Header append Vary Accept-Encoding', '    </FilesMatch>', '', '    # Remove ETag for consistency across CDNs', '    Header unset ETag', '    FileETag None', '</IfModule>', '', '# ------------------------------', '# 3. COMPRESSION (mod_deflate + mod_brotli)', '# ------------------------------', '', '# --- Brotli Compression (Modern Browsers) ---', '<IfModule mod_brotli.c>', '    AddOutputFilterByType BROTLI_COMPRESS text/html text/plain text/css application/javascript application/json image/svg+xml application/xml', '</IfModule>', '', '# --- GZIP Compression (Fallback) ---', '<IfModule mod_deflate.c>', '    # Compress text-based content', '    AddOutputFilterByType DEFLATE text/plain', '    AddOutputFilterByType DEFLATE text/html', '    AddOutputFilterByType DEFLATE text/css', '    AddOutputFilterByType DEFLATE text/javascript', '    AddOutputFilterByType DEFLATE application/javascript', '    AddOutputFilterByType DEFLATE application/x-javascript', '    AddOutputFilterByType DEFLATE application/xml', '    AddOutputFilterByType DEFLATE application/xhtml+xml', '    AddOutputFilterByType DEFLATE application/rss+xml', '    AddOutputFilterByType DEFLATE application/json', '    AddOutputFilterByType DEFLATE font/woff', '    AddOutputFilterByType DEFLATE font/woff2', '    AddOutputFilterByType DEFLATE image/svg+xml', '', '    # Skip already compressed files', '    SetEnvIfNoCase Request_URI \.(?:gz|zip|bz2|rar|7z|mp4|webm|avi)$ no-gzip dont-vary', '', '    # Browser workarounds', '    BrowserMatch ^Mozilla/4 gzip-only-text/html', '    BrowserMatch ^Mozilla/4\.0[678] no-gzip', '    BrowserMatch \bMSIE !no-gzip !gzip-only-text/html', '</IfModule>', '', '# ------------------------------', '# 4. OPTIONAL PERFORMANCE TUNING', '# ------------------------------', '', '# Disable directory listing', 'Options -Indexes', '', '# Leverage Keep-Alive connections', '<IfModule mod_headers.c>', '    Header set Connection keep-alive', '</IfModule>', '', '# ============================================================', '# END WP Optimal State Caching', '# ============================================================', '');
        return implode(PHP_EOL, $rules);
    }
    private function is_constant_in_wp_config($constant_name) {
        if (!defined($constant_name)) {
            return false;
        }
        if (!$this->wp_filesystem) {
            return false;
        }
        $config_file = ABSPATH . 'wp-config.php';
        if (!$this->wp_filesystem->exists($config_file)) {
            $config_file = dirname(ABSPATH) . '/wp-config.php';
            if (!$this->wp_filesystem->exists($config_file)) {
                return false;
            }
        }
        $config_content = $this->wp_filesystem->get_contents($config_file);
        if ($config_content === false || empty($config_content)) {
            return false;
        }
        $pattern = '/define\s*\(\s*[\'"]' . preg_quote($constant_name, '/') . '[\'"]\s*,/';
        return preg_match($pattern, $config_content) === 1;
    }
    private function update_cron_schedule($days, $time = "02:00") {
        wp_clear_scheduled_hook("optistate_scheduled_cleanup");
        $days = intval($days);
        if ($days <= 0) return;
        $timezone_string = get_option('timezone_string');
        if (empty($timezone_string)) {
            $offset = get_option('gmt_offset');
            $timezone_string = timezone_name_from_abbr('', $offset * 3600, 0);
            if ($timezone_string === false) {
                $timezone_string = 'UTC';
            }
        }
        try {
            $timezone = new DateTimeZone($timezone_string);
            $now = new DateTime('now', $timezone);
            $target = new DateTime('now', $timezone);
            list($hour, $minute) = explode(':', $time);
            $target->setTime((int)$hour, (int)$minute, 0);
            if ($target <= $now) {
                $target->modify('+1 day');
            }
            if ($days > 1) {
                $target->modify('+' . ($days - 1) . ' days');
            }
            $target->setTimezone(new DateTimeZone('UTC'));
            $utc_timestamp = $target->getTimestamp();
            wp_schedule_single_event($utc_timestamp, "optistate_scheduled_cleanup");
        }
        catch(Exception $e) {
        }
    }
    public function reschedule_cron_from_settings() {
        $settings = $this->get_persistent_settings();
        $this->update_cron_schedule($settings["auto_optimize_days"], $settings["auto_optimize_time"]);
    }
    private function register_ajax_handlers() {
        $handlers = ["get_stats", "clean_item", "optimize_tables", "one_click_optimize", "optimize_autoload", "get_optimization_log", "save_max_backups", "save_auto_settings", "get_health_score", "get_performance_features", "save_performance_features", "check_htaccess_status", "purge_page_cache", "get_cache_stats", "export_settings", "import_settings", "search_replace_dry_run", "run_pagespeed_audit", "save_pagespeed_settings", "check_pagespeed_status", "analyze_indexes", "check_index_status", "scan_integrity", "fix_integrity"];
        foreach ($handlers as $handler) {
            add_action("wp_ajax_optistate_" . $handler, [$this, "ajax_" . $handler]);
        }
        add_action("wp_ajax_optistate_get_table_analysis", [$this, "ajax_get_table_analysis"]);
        add_action("wp_ajax_optistate_check_restore_status", [$this->db_backup_manager, "ajax_check_restore_status"]);
    }
    public function add_admin_menu() {
        add_menu_page(__('Optimal State', 'optistate'), __('Optimal State', 'optistate'), "manage_options", "optistate", [$this, "display_admin_page"], "dashicons-performance", 80);
    }
    private function secure_file_write_atomic($filepath, $content, $require_csrf = true) {
        $is_system_context = wp_doing_cron() || (defined('WP_CLI') && WP_CLI);
        if (!$is_system_context) {
            if (!current_user_can("manage_options")) {
                return false;
            }
            if ($require_csrf && defined('DOING_AJAX') && DOING_AJAX) {
                $nonce = $_REQUEST["nonce"]??'';
                if (!wp_verify_nonce($nonce, OPTISTATE::NONCE_ACTION)) {
                    return false;
                }
            }
        }
        if (!$this->wp_filesystem) {
            return false;
        }
        $upload_dir = wp_upload_dir();
        $plugin_data_dir = trailingslashit($upload_dir['basedir']) . self::SETTINGS_DIR_NAME . '/';
        if (!$this->validate_file_path($filepath, $plugin_data_dir)) {
            return false;
        }
        $random_suffix = bin2hex(random_bytes(8));
        $temp_file = $filepath . ".tmp." . $random_suffix;
        $temp_handle = @fopen($temp_file, 'x');
        if ($temp_handle === false) {
            return false;
        }
        if (!flock($temp_handle, LOCK_EX)) {
            fclose($temp_handle);
            @unlink($temp_file);
            return false;
        }
        $bytes_written = fwrite($temp_handle, $content);
        $fsync_success = fsync($temp_handle);
        $write_success = ($bytes_written === strlen($content)) && $fsync_success;
        if ($write_success) {
            $temp_stat = fstat($temp_handle);
            flock($temp_handle, LOCK_UN);
            fclose($temp_handle);
            if ($temp_stat !== false) {
                clearstatcache(true, $temp_file);
                $current_stat = @stat($temp_file);
                if ($current_stat === false || $current_stat['ino'] !== $temp_stat['ino'] || $current_stat['size'] !== $temp_stat['size']) {
                    @unlink($temp_file);
                    return false;
                }
            }
            if (!$this->wp_filesystem->move($temp_file, $filepath, true)) {
                @unlink($temp_file);
                return false;
            }
            clearstatcache(true, $filepath);
            return true;
        } else {
            flock($temp_handle, LOCK_UN);
            fclose($temp_handle);
            @unlink($temp_file);
            return false;
        }
    }
    private function secure_file_read($filepath) {
        $upload_dir = wp_upload_dir();
        $plugin_data_dir = trailingslashit($upload_dir['basedir']) . self::SETTINGS_DIR_NAME . '/';
        if (!$this->validate_file_path($filepath, $plugin_data_dir)) {
            return false;
        }
        if (!$this->wp_filesystem) {
            return false;
        }
        if (!$this->wp_filesystem->exists($filepath)) {
            return false;
        }
        $contents = $this->wp_filesystem->get_contents($filepath);
        if ($contents === false) {
            return false;
        }
        return $contents;
    }
    private function validate_table_name($table_name) {
        global $wpdb;
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
            return false;
        }
        static $valid_tables = null;
        static $cache_time = null;
        if ($valid_tables === null || (time() - $cache_time) > 300) {
            $valid_tables = $wpdb->get_col("SHOW TABLES");
            $cache_time = time();
        }
        if (!in_array($table_name, $valid_tables, true)) {
            return false;
        }
        global $wp_version;
        if (version_compare($wp_version, '6.2', '>=')) {
            return $wpdb->prepare('%i', $table_name);
        } else {
            $escaped_table = str_replace('`', '``', $table_name);
            return '`' . $escaped_table . '`';
        }
    }
    private function validate_file_path($filepath, $allowed_dir) {
        if (!is_string($filepath) || !is_string($allowed_dir) || $filepath === '' || $allowed_dir === '') {
            return false;
        }
        $filepath = wp_normalize_path($filepath);
        $allowed_dir = wp_normalize_path($allowed_dir);
        if (strpos($filepath, $allowed_dir) !== 0) {
            return false;
        }
        if (strpos($filepath, '../') !== false || strpos($filepath, '..\\') !== false || strpos($filepath, "\0") !== false) {
            return false;
        }
        if (defined('FS_METHOD') && FS_METHOD === 'direct' && file_exists($allowed_dir)) {
            $canonical_allowed_dir = realpath($allowed_dir);
            if ($canonical_allowed_dir === false) {
                return false;
            }
            if (file_exists($filepath)) {
                $canonical_filepath = realpath($filepath);
                if ($canonical_filepath === false || strpos($canonical_filepath, $canonical_allowed_dir) !== 0) {
                    return false;
                }
            }
        }
        return true;
    }
    public function ajax_save_auto_settings() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        if (!$this->check_rate_limit("save_auto_settings", 3)) {
            wp_send_json_error(['message' => __('🕔 Please wait a few seconds before saving again.', 'optistate') ], 429);
            return;
        }
        $current_settings = $this->get_persistent_settings();
        $old_days = (int)$current_settings['auto_optimize_days'];
        $old_time = $current_settings['auto_optimize_time'];
        $old_email = (bool)$current_settings['email_notifications'];
        $old_max_backups = (int)$current_settings['max_backups'];
        $old_auto_backup_only = (bool)$current_settings['auto_backup_only'];
        $days_input = isset($_POST["auto_optimize_days"]) ? sanitize_text_field(wp_unslash($_POST["auto_optimize_days"])) : '0';
        if (!is_string($days_input) || !ctype_digit($days_input)) {
            wp_send_json_error(['message' => __('Invalid data format for days.', 'optistate') ]);
            return;
        }
        $days = (int)$days_input;
        $time_input = isset($_POST["auto_optimize_time"]) ? sanitize_text_field(wp_unslash($_POST["auto_optimize_time"])) : "02:00";
        $allowed_times = [];
        for ($hour = 0;$hour < 24;$hour++) {
            $allowed_times[] = sprintf("%02d:00", $hour);
        }
        if (!in_array($time_input, $allowed_times, true)) {
            wp_send_json_error(['message' => __('Invalid time value selected.', 'optistate') ]);
            return;
        }
        $time = $time_input;
        $email_notifications = isset($_POST["email_notifications"]) && $_POST["email_notifications"] === '1';
        $auto_backup_only = isset($_POST["auto_backup_only"]) && $_POST["auto_backup_only"] === '1';
        $max_backups_input = isset($_POST['max_backups']) ? sanitize_text_field(wp_unslash($_POST['max_backups'])) : '1';
        if (!is_string($max_backups_input) || !ctype_digit($max_backups_input)) {
            wp_send_json_error(['message' => __('Invalid data format for max backups.', 'optistate') ]);
            return;
        }
        $max_backups = (int)$max_backups_input;
        $disable_restore_security = isset($_POST["disable_restore_security"]) && $_POST["disable_restore_security"] === '1';
        $settings_to_save = ["auto_optimize_days" => $days, "auto_optimize_time" => $time, "email_notifications" => $email_notifications, "auto_backup_only" => $auto_backup_only, "max_backups" => $max_backups, "disable_restore_security" => $disable_restore_security];
        $this->save_persistent_settings($settings_to_save);
        $this->update_cron_schedule($days, $time);
        $settings_changed = ($old_days !== $days || $old_time !== $time || $old_email !== $email_notifications || $old_auto_backup_only !== $auto_backup_only || $old_max_backups !== $max_backups);
        if ($settings_changed) {
            $operation = '⚙️ ' . __('Automatic Backup & Cleanup Settings Updated', 'optistate');
            $this->log_optimization("manual", $operation, "");
        }
        $final_settings = $this->get_persistent_settings();
        wp_send_json_success(['message' => __('Settings for scheduled tasks saved successfully!', 'optistate'), 'days' => $final_settings['auto_optimize_days'], 'time' => $final_settings['auto_optimize_time'], 'email_notifications' => $final_settings['email_notifications'], 'auto_backup_only' => $final_settings['auto_backup_only'], 'max_backups' => $final_settings['max_backups'], 'disable_restore_security' => $final_settings['disable_restore_security']]);
    }
    public function enqueue_admin_assets($hook) {
        if ($hook !== "toplevel_page_optistate") {
            return;
        }
        wp_enqueue_style("optistate-admin-styles", plugin_dir_url(__FILE__) . "css/admin.css", [], OPTISTATE::VERSION);
        wp_enqueue_script("optistate-admin-script", plugin_dir_url(__FILE__) . "js/admin.js", ["jquery"], OPTISTATE::VERSION, true);
        $optimizer_data = ["ajaxurl" => admin_url("admin-ajax.php"), "nonce" => wp_create_nonce(OPTISTATE::NONCE_ACTION), "settings_updated" => isset($_GET["settings-updated"]) && $_GET["settings-updated"] === "true", ];
        wp_localize_script("optistate-admin-script", "optistate_Ajax", $optimizer_data);
        wp_localize_script("optistate-admin-script", "optistate_BackupMgr", ["ajax_url" => admin_url("admin-ajax.php"), "nonce" => wp_create_nonce("optistate_backup_nonce"), ]);
        wp_enqueue_script("gtranslate-widget", "https://cdn.gtranslate.net/widgets/latest/popup.js", [], null, true);
        wp_script_add_data("gtranslate-widget", "defer", true);
        $gtranslate_settings = 'window.gtranslateSettings = {"default_language": "en", "native_language_names": true, "languages": ["en","fr","es","de","ja","pt","it","zh-CN","ru","ko","tr","id","hi","ar"], "wrapper_selector": ".gtranslate_wrapper", "horizontal_position": "right", "vertical_position": "bottom", "flag_size": "24"};';
        wp_add_inline_script('gtranslate-widget', $gtranslate_settings, 'before');
    }
    private function get_combined_database_statistics($force_refresh = false) {
        if (!$force_refresh) {
            $cached_stats = get_transient(self::STATS_TRANSIENT);
            if ($cached_stats !== false && is_array($cached_stats)) {
                return $cached_stats;
            }
        }
        global $wpdb;
        $stats = ['post_revisions' => 0, 'auto_drafts' => 0, 'trashed_posts' => 0, 'spam_comments' => 0, 'trashed_comments' => 0, 'unapproved_comments' => 0, 'pingbacks' => 0, 'trackbacks' => 0, 'orphaned_postmeta' => 0, 'orphaned_commentmeta' => 0, 'orphaned_relationships' => 0, 'orphaned_usermeta' => 0, 'expired_transients' => 0, 'all_transients' => 0, 'duplicate_postmeta' => 0, 'duplicate_commentmeta' => 0, 'action_scheduler' => 0, 'oembed_cache' => 0, 'woo_bloat' => 0, 'empty_taxonomies' => 0];
        $wpdb->suppress_errors();
        $posts_aggregates = $wpdb->get_results("
        SELECT post_type, post_status, COUNT(*) as count 
        FROM {$wpdb->posts} 
        WHERE post_type = 'revision' OR post_status IN ('auto-draft', 'trash')
        GROUP BY post_type, post_status
    ", ARRAY_A);
        if (is_array($posts_aggregates)) {
            foreach ($posts_aggregates as $row) {
                $count = isset($row['count']) ? absint($row['count']) : 0;
                if (isset($row['post_type']) && $row['post_type'] === 'revision') {
                    $stats['post_revisions']+= $count;
                }
                if (isset($row['post_status'])) {
                    if ($row['post_status'] === 'auto-draft') {
                        $stats['auto_drafts']+= $count;
                    }
                    if ($row['post_status'] === 'trash') {
                        $stats['trashed_posts']+= $count;
                    }
                }
            }
        }
        $comments_aggregates = $wpdb->get_results("
        SELECT comment_approved, comment_type, COUNT(*) as count 
        FROM {$wpdb->comments} 
        WHERE comment_approved IN ('spam', 'trash', '0') OR comment_type IN ('pingback', 'trackback')
        GROUP BY comment_approved, comment_type
    ", ARRAY_A);
        if (is_array($comments_aggregates)) {
            foreach ($comments_aggregates as $row) {
                $count = isset($row['count']) ? absint($row['count']) : 0;
                if (isset($row['comment_approved'])) {
                    if ($row['comment_approved'] === 'spam') {
                        $stats['spam_comments']+= $count;
                    }
                    if ($row['comment_approved'] === 'trash') {
                        $stats['trashed_comments']+= $count;
                    }
                    if ($row['comment_approved'] === '0') {
                        $stats['unapproved_comments']+= $count;
                    }
                }
                if (isset($row['comment_type'])) {
                    if ($row['comment_type'] === 'pingback') {
                        $stats['pingbacks']+= $count;
                    }
                    if ($row['comment_type'] === 'trackback') {
                        $stats['trackbacks']+= $count;
                    }
                }
            }
        }
        $queries = ['orphaned_postmeta' => "SELECT COUNT(*) FROM {$wpdb->postmeta} pm WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->posts} p WHERE p.ID = pm.post_id)", 'orphaned_commentmeta' => "SELECT COUNT(*) FROM {$wpdb->commentmeta} cm WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->comments} c WHERE c.comment_ID = cm.comment_id)", 'orphaned_relationships' => "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->posts} p WHERE p.ID = tr.object_id)", 'orphaned_usermeta' => "SELECT COUNT(*) FROM {$wpdb->usermeta} um WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->users} u WHERE u.ID = um.user_id)", 'expired_transients' => "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()", 'all_transients' => "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'", 'duplicate_postmeta' => "SELECT COUNT(*) FROM (SELECT 1 FROM {$wpdb->postmeta} GROUP BY post_id, meta_key, meta_value HAVING COUNT(*) > 1) as temp", 'duplicate_commentmeta' => "SELECT COUNT(*) FROM (SELECT 1 FROM {$wpdb->commentmeta} GROUP BY comment_id, meta_key, meta_value HAVING COUNT(*) > 1) as temp"];
        $selects = [];
        foreach ($queries as $key => $sql) {
            $selects[] = "($sql) as $key";
        }
        $combined_sql = "SELECT " . implode(', ', $selects);
        $results = $wpdb->get_row($combined_sql, ARRAY_A);
        if (is_array($results)) {
            foreach ($results as $key => $val) {
                $stats[$key] = absint($val);
            }
        }
        $as_actions_table = $wpdb->prefix . 'actionscheduler_actions';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $as_actions_table)) === $as_actions_table) {
            $as_count = $wpdb->get_var("SELECT COUNT(*) FROM $as_actions_table WHERE status IN ('complete', 'failed', 'canceled')");
            $stats['action_scheduler'] = absint($as_count);
        }
        $oembed_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE '_oembed_%'");
        $stats['oembed_cache'] = absint($oembed_count);
        $current_time = time();
        $week_ago = $current_time - 604800;
        $woo_bloat = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} 
         WHERE (option_name LIKE '_wc_session_expires_%%' AND option_value < %d)
         OR (option_name LIKE '_transient_timeout_wc_%%' AND option_value < %d)
         OR (option_name LIKE '_transient_wc_var_%%')
         OR (option_name LIKE '_transient_timeout_wc_report_%%' AND option_value < %d)", $current_time, $current_time, $week_ago));
        $woo_bloat = absint($woo_bloat);
        static $wc_table_exists = null;
        $wc_session_table = $wpdb->prefix . 'woocommerce_sessions';
        if ($wc_table_exists === null) {
            $wc_table_exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wc_session_table)) === $wc_session_table);
        }
        if ($wc_table_exists) {
            $wc_sessions = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wc_session_table WHERE session_expiry < %d", $current_time));
            $woo_bloat+= absint($wc_sessions);
        }
        $stats['woo_bloat'] = $woo_bloat;
        $empty_tax_count = $wpdb->get_var("
        SELECT COUNT(t.term_id) 
        FROM {$wpdb->term_taxonomy} tt
        INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
        WHERE tt.count = 0 
        AND t.slug != 'uncategorized' 
        AND tt.taxonomy NOT IN ('nav_menu', 'link_category', 'post_format')
    ");
        $stats['empty_taxonomies'] = absint($empty_tax_count);
        $totals = $wpdb->get_row($wpdb->prepare("
        SELECT 
            COUNT(*) as total_tables,
            COALESCE(SUM(data_free), 0) as total_overhead,
            COALESCE(SUM(index_length), 0) as total_indexes,
            COALESCE(SUM(data_length + index_length), 0) as total_size
        FROM information_schema.TABLES 
        WHERE table_schema = %s
    ", DB_NAME));
        $total_overhead = 0;
        $total_indexes_size = 0;
        $total_tables_count = 0;
        $total_db_size = 0;
        if ($totals) {
            $total_overhead = isset($totals->total_overhead) ? (float)$totals->total_overhead : 0;
            $total_indexes_size = isset($totals->total_indexes) ? (float)$totals->total_indexes : 0;
            $total_tables_count = isset($totals->total_tables) ? absint($totals->total_tables) : 0;
            $total_db_size = isset($totals->total_size) ? (float)$totals->total_size : 0;
        }
        set_transient('optistate_db_size_cache', $total_db_size, HOUR_IN_SECONDS);
        $stats['raw_table_overhead_bytes'] = $total_overhead;
        $stats['table_overhead_bytes'] = $total_overhead * 0.11;
        $stats['total_db_size_bytes'] = $total_db_size;
        $stats['overhead_percentage'] = $stats['total_db_size_bytes'] > 0 ? ($stats['table_overhead_bytes'] / $stats['total_db_size_bytes']) * 100 : 0;
        $stats['table_overhead'] = size_format($stats['table_overhead_bytes'], 2);
        $stats['total_indexes_size_bytes'] = $total_indexes_size;
        $stats['total_indexes_size'] = size_format($total_indexes_size, 2);
        $stats['total_tables_count'] = $total_tables_count;
        $autoload_data = $wpdb->get_row("
        SELECT
            COUNT(*) as autoload_count,
            COALESCE(SUM(LENGTH(option_value)), 0) as autoload_size
        FROM {$wpdb->options}
        WHERE autoload = 'yes'
    ");
        $stats['autoload_size_bytes'] = 0;
        $stats['autoload_options'] = 0;
        if ($autoload_data) {
            $stats['autoload_size_bytes'] = isset($autoload_data->autoload_size) && is_numeric($autoload_data->autoload_size) ? absint($autoload_data->autoload_size) : 0;
            $stats['autoload_options'] = isset($autoload_data->autoload_count) ? absint($autoload_data->autoload_count) : 0;
        }
        $stats['autoload_size'] = size_format($stats['autoload_size_bytes'], 2);
        $posts_table = $wpdb->prefix . 'posts';
        $db_creation_date = $wpdb->get_var("SELECT post_date FROM {$posts_table} ORDER BY ID ASC LIMIT 1");
        $stats['db_creation_date'] = $db_creation_date ? date_i18n(get_option('date_format'), strtotime($db_creation_date)) : 'Unknown';
        $stats['formatted_total_size'] = size_format($stats['total_db_size_bytes'], 2);
        $wpdb->show_errors();
        set_transient(self::STATS_TRANSIENT, $stats, self::STATS_CACHE_DURATION);
        return $stats;
    }
    public function display_admin_page() {
        if (!current_user_can("manage_options")) {
            wp_die(esc_html__("You do not have sufficient permissions to access this page.", "optistate"));
        }
        if (!$this->check_user_access()) {
            echo '<div class="wrap optistate-wrap">';
            echo '<h1 class="optistate-title"><span class="dashicons dashicons-performance"></span> Optimal State (Free)</h1>';
            echo '<div class="notice notice-error optistate-access-notice">';
            echo '<h2>' . esc_html__('Access Restricted', 'optistate') . '</h2>';
            echo '<p>' . esc_html__('Your administrator account does not have permission to use this plugin. Please contact the site owner to request access.', 'optistate') . '</p>';
            echo '</div>';
            echo '</div>';
            return;
        }
        $settings = $this->get_persistent_settings();
        $auto_optimize_days = $settings["auto_optimize_days"];
        $auto_optimize_time = $settings["auto_optimize_time"];
        $email_notifications = $settings["email_notifications"];
        $auto_backup_only = $settings["auto_backup_only"];
        $backups = $this->db_backup_manager->get_backups();
?>
    <div class="wrap optistate-wrap">
    <h1 class="optistate-title"><span class="dashicons dashicons-performance"></span> Optimal State (Free)</h1>
    <div class="gtranslate_wrapper"></div>
    <img src="<?php echo esc_url(plugin_dir_url(__FILE__) . 'images/optistate-logo-small.webp'); ?>" alt="<?php echo esc_attr(OPTISTATE::PLUGIN_NAME); ?> Logo" class="optistate-logo">
    <div class="db-backup-wrap os-mt-neg-12">
        <div class="optistate-container">
            <?php $server_type = $this->detect_server_type(); ?>
            <div class="optistate-notice">
                <strong><?php echo 'ℹ️ ';
        echo esc_html__("Need Help?", "optistate"); ?></strong>
                <?php echo esc_html__("Check out the full plugin manual for detailed instructions and best practices.", "optistate"); ?>
                <a href="<?php echo esc_url(plugin_dir_url(__FILE__) . 'manual/v1-2-0.html'); ?>" target="_blank" rel="noopener noreferrer">
                    <?php echo esc_html__("READ THE MANUAL", "optistate");
        echo ' 📕'; ?>
                </a>
            </div>
            <?php if ($server_type === 'nginx'): ?>
            <div class="notice nginx-notice">
                <h3 class="os-mt-0">
                    <?php echo esc_html__('🖳 Nginx Server Detected', 'optistate'); ?>
                </h3>
                <p class="os-mb-5">
                   <?php echo esc_html__('⚠️ Your server is running Nginx. Security rules must be configured manually (Important). Browser Caching also requires manual activation (Optional).', 'optistate'); ?>
                </p>
                <p class="os-mb-0">
                    <?php echo esc_html__('Please follow our', 'optistate'); ?>
                    <a href="<?php echo esc_url(plugin_dir_url(__FILE__) . 'manual/v1-2-0.html#ch-7-3-1'); ?>" target="_blank" rel="noopener noreferrer">
                        <strong><?php echo esc_html__('Nginx Configuration Guide ⚙️', 'optistate'); ?></strong>
                    </a>
                </p>
            </div>
            <?php
        endif; ?>
            <h2 class="nav-tab-wrapper optistate-nav-tabs">
                <a href="#tab-backups" class="nav-tab nav-tab-active"><span class="dashicons dashicons-database-export"></span> <?php echo esc_html__('Backups', 'optistate'); ?></a>
                <a href="#tab-dashboard" class="nav-tab"><span class="dashicons dashicons-yes-alt"></span> <?php echo esc_html__('Optimize', 'optistate'); ?></a>
                <a href="#tab-stats" class="nav-tab"><span class="dashicons dashicons-chart-bar"></span> <?php echo esc_html__('Statistics', 'optistate'); ?></a>
                <a href="#tab-cleanup" class="nav-tab"><span class="dashicons dashicons-trash"></span> <?php echo esc_html__('Cleanup', 'optistate'); ?></a>
                <a href="#tab-advanced" class="nav-tab"><span class="dashicons dashicons-admin-tools"></span> <?php echo esc_html__('Advanced', 'optistate'); ?></a>
                <a href="#tab-automation" class="nav-tab"><span class="dashicons dashicons-clock"></span> <?php echo esc_html__('Automation', 'optistate'); ?></a>
                <a href="#tab-performance" class="nav-tab"><span class="dashicons dashicons-performance"></span> <?php echo esc_html__('Performance', 'optistate'); ?></a>
                <a href="#tab-settings" class="nav-tab"><span class="dashicons dashicons-admin-settings"></span> <?php echo esc_html__('Settings', 'optistate'); ?></a>
            </h2>
    <div id="tab-backups" class="optistate-tab-content active">
    <div class="optistate-card">
<h2>
    <span>
        <span class="dashicons dashicons-database-export"></span> 
        <?php echo esc_html__("1. Create a Database Backup", "optistate"); ?>
    </span>
    <a href="<?php echo esc_url(plugin_dir_url(__FILE__) . 'manual/v1-2-0.html#ch-4-1'); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr__('Read the Manual', 'optistate'); ?>">
        <span class="dashicons dashicons-info"></span>
    </a>
</h2>
        <p class="os-line-height-relaxed">
                        <?php echo '✔ ';
        echo esc_html__('Always backup your database before performing cleanup operations.', 'optistate'); ?><br>
                        <?php echo ' 🔄 ';
        echo esc_html__('You will be able to restore it if something goes wrong during cleanup.', 'optistate'); ?><br>
                        <?php echo ' 💾 ';
        printf(esc_html__('Backups are securely stored in your %s folder.', 'optistate'), '<span class="os-code-highlight"><code>' . esc_html('/wp-content/uploads/' . self::BACKUP_DIR_NAME) . '</code></span>'); ?>
                    </p>
                    <div class="os-mb-15">
                        <label for="max_backups_setting" class="os-label-block-bold-mb5">
                            <?php echo esc_html__("Maximum Backups to Keep:", "optistate"); ?>
                        </label>
                        <select class="os-select-bold-w100" name="max_backups_setting" id="max_backups_setting">
                            <?php
        $current_max_backups = $settings["max_backups"];
        for ($i = 1;$i <= 10;$i++) {
            $selected = $i === $current_max_backups ? "selected" : "";
            echo '<option value="' . esc_attr($i) . '" ' . esc_attr($selected) . ">" . esc_html($i) . "</option>";
        }
?>
                        </select>
                        <button type="button" class="button os-ml-10" id="save-max-backups-btn" disabled>
                            <?php echo '✓ ';
        echo esc_html__("Save", "optistate"); ?>
                        </button><span style="margin-left: 10px; font-size: 12px; font-weight: bold;"><a style="color: #007F6C;" href="https://payhip.com/b/AS3Pt" target="_blank">➝ PRO VERSION ONLY</a></span>
                        <p class="os-mt-5-lh-relaxed">
                            <?php echo '⚠️ ';
        echo esc_html__("Older backups will be automatically deleted when this limit is reached.", "optistate"); ?>
                            <br>
                            <?php echo 'ℹ️ ';
        echo esc_html__("Backups consume space: If you have limited storage capacity, keep only one or two backups.", "optistate"); ?>
                        </p>
                    </div>
                    <button type="button" class="button button-primary button-large os-font-weight-500" id="create-backup-btn">
                       <span class="dashicons dashicons-plus-alt"></span><?php echo esc_html__("Create Backup Now", "optistate"); ?>
                    </button>
                    <hr class="os-hr-separator">
<h2>
    <span>
        <span class="dashicons dashicons-database-view"></span> 
        <?php echo esc_html__("1.1 Manage Existing Backups", "optistate"); ?>
    </span>
    <a href="<?php echo esc_url(plugin_dir_url(__FILE__) . 'manual/v1-2-0.html#ch-4-3'); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr__('Read the Manual', 'optistate'); ?>">
        <span class="dashicons dashicons-info"></span>
    </a>
</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__("Backup Name", "optistate"); ?></th>
                                <th><?php echo esc_html__("Date Created", "optistate"); ?></th>
                                <th><?php echo esc_html__("Size", "optistate"); ?></th>
                                <th><?php echo esc_html__("Actions", "optistate"); ?></th>
                            </tr>
                        </thead>
                        <tbody id="backups-list">
                            <?php if (empty($backups)): ?>
                                <tr>
                                    <td colspan="4" class="db-backup-empty"><?php echo esc_html__("No backups found. Create your first backup!", "optistate"); ?></td>
                                </tr>
                            <?php
        else: ?>
                               <?php foreach ($backups as $backup): ?>
                                    <tr data-file="<?php echo esc_attr($backup["filename"]); ?>" data-bytes="<?php echo esc_attr($backup["size_bytes"]); ?>">
                                        <td>
                                    <strong><?php echo esc_html($backup["filename"]); ?></strong>
                                    <div class="os-backup-meta-row">
                                    <?php if ($backup["verified"]) {
                    echo '<span class="db-backup-verified optistate-integrity-info os-cursor-pointer" data-status="verified">✓ ' . esc_html__("Integrity", "optistate") . "</span>";
                } else {
                    echo '<span class="db-backup-unverified optistate-integrity-info os-cursor-pointer" data-status="unverified">⚠ ' . esc_html__("Integrity", "optistate") . "</span>";
                }
                $b_type = isset($backup['type']) ? $backup['type'] : 'MANUAL';
                $b_class = ($b_type === 'SCHEDULED') ? 'optistate-type-scheduled' : 'optistate-type-manual';
                $b_icon = ($b_type === 'SCHEDULED') ? '⏰' : '👤'; ?>
                                <span class="optistate-backup-type" title="<?php echo esc_attr($b_type === 'MANUAL' ? 'Created manually by user' : 'Created automatically by the system'); ?>">
                                <?php echo $b_icon . ' ' . esc_html($b_type); ?></span>
                                </div>
                                        </td>
                                        <td><?php echo esc_html($backup["date"]); ?></td>
                                        <td><?php echo esc_html($backup["size"]); ?></td>
                                        <td>
                                            <button class="button download-backup" data-file="<?php echo esc_attr($backup["filename"]); ?>">
                                                <span class="dashicons dashicons-download"></span> <?php echo esc_html__("Download", "optistate"); ?>
                                            </button>
                                            <button class="button restore-backup" data-file="<?php echo esc_attr($backup["filename"]); ?>">
                                                <span class="dashicons dashicons-backup"></span> <?php echo esc_html__("Restore", "optistate"); ?>
                                            </button>
                                            <button class="button delete-backup" data-file="<?php echo esc_attr($backup["filename"]); ?>">
                                                <span class="dashicons dashicons-trash"></span> <?php echo esc_html__("Delete", "optistate"); ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php
            endforeach; ?>
                            <?php
        endif; ?>
                        </tbody>
                    </table>
                    <div class="optistate-restore-file-section">
                        <h3><span class="dashicons dashicons-upload os-font-20"></span> <?php echo esc_html__("Restore Database from File", "optistate"); ?></h3>
                        <p class="os-line-height-relaxed"><?php echo '📤 ';
        echo esc_html__("Upload a database backup generated by WP Optimal State to restore your database (max. 3GB, .sql, .sql.gz).", "optistate"); ?><br>
                       <strong> <?php echo 'ℹ️ ';
        echo esc_html__("Please Note: ", "optistate"); ?></strong><?php echo esc_html__("This is not a website migration tool. It cannot replace your files, plugins, etc.", "optistate"); ?><br>
                       <strong><?php echo '⚠️ ';
        echo esc_html__("Extreme Caution: ", "optistate"); ?></strong><?php echo esc_html__("Restoring an incorrect or damaged database could ruin your website.", "optistate"); ?></p>
                            <div class="optistate-file-upload-area">
                            <input type="file" id="optistate-file-input" class="optistate-file-input" accept=".sql,.sql.gz" disabled>
                            <label for="optistate-file-input" class="optistate-file-label">
                                <span class="dashicons dashicons-upload"></span> <?php echo esc_html__("Choose Backup File", "optistate"); ?>
                            </label><br>
                            <div style="margin-top: 6px;"></div><span style="font-size: 12px; font-weight: bold;"><a style="color: #007F6C;" href="https://payhip.com/b/AS3Pt" target="_blank">↑ PRO VERSION ONLY ↑</a>︎<span></div>
                        </div>
                        <div id="optistate-file-info" class="optistate-file-info os-display-none">
                            <strong><?php echo esc_html__("Selected:", "optistate"); ?></strong> <span id="optistate-file-name"></span> 
                            (<span id="optistate-file-size"></span>)
                        </div>
                        <div id="optistate-upload-progress" class="optistate-upload-progress">
                            <div class="optistate-progress-bar">
                                <div class="optistate-progress-fill">0%</div>
                            </div>
                        </div>
                        <div id="restore-button-wrapper">
                            <button type="button" class="button button-primary button-large optistate-restore-file-btn" id="optistate-restore-file-btn">
                                <span class="dashicons dashicons-upload"></span> <?php echo esc_html__("Restore from File", "optistate"); ?>
                            </button>
                        </div>

                    <div class="os-mt-20">
                        <?php $disable_security = isset($settings['disable_restore_security']) ? (bool)$settings['disable_restore_security'] : false; ?>
                        <label class="optistate-danger-zone">
                            <input type="checkbox" id="disable_restore_security" name="disable_restore_security" value="1" <?php checked($disable_security, true); ?> class="os-mt-2" disabled>
                            <div>
                                <strong class="os-danger-text-lg"><?php echo '🔓 ' . esc_html__("Disable Restore Security Checks", "optistate"); ?></strong>
                                <p class="description os-m-5-0-0-0">
                                    <?php echo esc_html__("Check this if your restore fails due to false positives (e.g., suspicious code or disallowed SQL queries).", "optistate"); ?>
                                    <br>
                                    <div class="os-warning-text-danger"><?php echo '⚠️ ' . esc_html__("Warning: This disables the SQL Firewall and Malicious Code Scanner. Only use with trusted backup files.", "optistate"); ?></div>
                                    <div style="margin-top: 3px;"><span style="font-size: 12px; font-weight: bold;"><a style="color: #007F6C;" href="https://payhip.com/b/AS3Pt" target="_blank">↑ PRO VERSION ONLY ↑</a>︎<span></div>
                                </p>
                            </div>
                        </label>
                    </div>
                </div>
            </div>
            <div id="tab-dashboard" class="optistate-tab-content">
                <div class="optistate-grid-2">
                    <div class="optistate-card optistate-card-highlight">
<h2>
    <span>
        <?php echo '💥 ';
        echo esc_html__('2. One-Click Optimization', 'optistate'); ?>
    </span>
    <a href="<?php echo esc_url(plugin_dir_url(__FILE__) . 'manual/v1-2-0.html#ch-5-3'); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr__('Read the Manual', 'optistate'); ?>">
        <span class="dashicons dashicons-info"></span>
    </a>
</h2>
                        <p><?php echo esc_html__('Perform all safe optimizations with one click (db cleanup + table optimization)', 'optistate'); ?><br>
                        <?php echo esc_html__('Click "Optimize Now" to see what items will be deleted', 'optistate'); ?></p>
                        <button class="button button-primary button-hero optistate-one-click" id="optistate-one-click">
                            <?php echo esc_html__('🚀 Optimize Now', 'optistate'); ?>
                        </button>
                        <div id="optistate-one-click-results" class="optistate-results"></div>
                    </div>
                    <div class="optistate-card optistate-health-dashboard">
<h2>
    <span>
        <?php echo '📊 ';
        echo esc_html__('3. Database Health Score', 'optistate'); ?>
    </span>
    <a href="<?php echo esc_url(plugin_dir_url(__FILE__) . 'manual/v1-2-0.html#ch-5-1'); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr__('Read the Manual', 'optistate'); ?>">
        <span class="dashicons dashicons-info"></span>
    </a>
</h2>
                        <div id="optistate-health-score-loading" class="optistate-loading">
                            <?php echo esc_html__('🔎 Analyzing database health...', 'optistate'); ?>
                        </div>
                        <div id="optistate-health-score-wrapper" class="os-display-none">
                            <div class="health-score-main">
                                <div class="health-score-circle">
                                    <div class="health-score-value" id="health-score-value">0</div>
                                    <div class="health-score-label"><?php echo esc_html__('Overall Score', 'optistate'); ?></div>
                                </div>
                                <div class="health-score-details">
                                    <div class="health-score-category">
                                        <span class="category-label"><?php echo esc_html__('Performance', 'optistate'); ?></span>
                                        <span class="category-score" id="health-score-performance">0</span>
                                    </div>
                                    <div class="health-score-category">
                                        <span class="category-label"><?php echo esc_html__('Cleanliness', 'optistate'); ?></span>
                                        <span class="category-score" id="health-score-cleanliness">0</span>
                                    </div>
                                    <div class="health-score-category">
                                        <span class="category-label"><?php echo esc_html__('Efficiency', 'optistate'); ?></span>
                                        <span class="category-score" id="health-score-efficiency">0</span>
                                    </div>
                                </div>
                            </div>
                            <div class="health-score-recommendations">
                                <h4><?php echo esc_html__('⭐ Details & Recommendations', 'optistate'); ?></h4>
                                <div id="health-score-recommendations-list"></div>
                            </div>
                            <button class="button optistate-refresh-health-score" id="optistate-refresh-health-score">
                                <?php echo esc_html__('↻ Refresh Analysis', 'optistate'); ?>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="optistate-card os-mt-20">
                <h2 class="optistate-targeted-header-wrapper">
                    <span><?php echo '🎯 ' . esc_html__('Targeted Optimizations', 'optistate'); ?>
    <a href="<?php echo esc_url(plugin_dir_url(__FILE__) . 'manual/v1-2-0.html#ch-5-4'); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr__('Read the Manual', 'optistate'); ?>">
        <span class="dashicons dashicons-info os-ml-5"></span>
    </a></span>
                    <button type="button" class="button button-secondary os-font-normal" id="optistate-refresh-targeted-btn" title="<?php echo esc_attr__('Refresh Statistics', 'optistate'); ?>">
                        <?php echo esc_html__('⟲ Refresh', 'optistate'); ?>
                    </button>
                </h2>
                <div class="optistate-grid-targeted" id="optistate-targeted-ops">
                    <div class="optistate-card optistate-loading-placeholder">
                        <?php echo esc_html__('⟳ Loading advanced metrics...', 'optistate'); ?>
                    </div>
                </div>
                <div align="center" style="margin-top: 8px;"><span style="font-size: 12px; font-weight: bold;"><a style="color: #007F6C;" href="https://payhip.com/b/AS3Pt" target="_blank">↑ PRO VERSION ONLY ↑</a><span></div>
              </div>
            </div>
            <div id="tab-stats" class="optistate-tab-content">
                <div class="optistate-card">
<h2>
    <span>
        <?php echo '📈 ' . esc_html__('4. Database Statistics', 'optistate'); ?>
    </span>
    <a href="<?php echo esc_url(plugin_dir_url(__FILE__) . 'manual/v1-2-0.html#ch-5-2'); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr__('Read the Manual', 'optistate'); ?>">
        <span class="dashicons dashicons-info"></span>
    </a>
</h2>
                    <div id="optistate-stats-loading" class="optistate-loading"><?php echo esc_html__('Loading statistics...', 'optistate'); ?></div>
                    <p class="os-line-height-relaxed">
                        <?php echo '💡 ';
        echo esc_html__('Review your database statistics before and after cleanup and optimization operations.', 'optistate'); ?><br>
                    </p>
                    <div id="optistate-stats-container" class="optistate-stats-full">
                        <div id="optistate-stats" class="optistate-stats"></div>
                    </div>
                    <div id="optistate-db-size" class="optistate-db-size os-mt-20">
                        <strong><?php echo esc_html__('Total Database Size:', 'optistate'); ?></strong> <span id="optistate-db-size-value"><?php echo esc_html__('Calculating...', 'optistate'); ?></span>
                    </div>
                    <button class="button optistate-refresh-health-score" id="optistate-refresh-stats"><?php echo esc_html__('⟲ Refresh Stats', 'optistate'); ?></button>
                </div>
<div class="optistate-card os-mt-20">
<h2>
    <span>
        <span class="dashicons dashicons-dashboard"></span> 
<?php echo esc_html__("4.1 Performance Metrics (PageSpeed)", "optistate"); ?>
    </span>
    <a href="<?php echo esc_url(plugin_dir_url(__FILE__) . 'manual/v1-2-0.html#ch-7-5'); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr__('Read the Manual', 'optistate'); ?>">
        <span class="dashicons dashicons-info"></span>
    </a>
</h2>
                <p class="os-line-height-relaxed">
                <?php echo '🎚️ ';
        echo esc_html__('Analyze your site performance using Google PageSpeed Insights. Test different pages to identify bottlenecks.', 'optistate'); ?><br>
                <?php echo '🔑 ';
        echo esc_html__('If you need an API key, you can get it here:', 'optistate'); ?> 
                <a href="https://developers.google.com/speed/docs/insights/v5/get-started" target="_blank" rel="noopener noreferrer">PageSpeed Insights API</a>.
                </p>
    <div class="optistate-grid-2 os-psi-grid-layout">
        <div class="optistate-psi-controls">
            <div>
            <label for="optistate_pagespeed_key" class="os-label-block-bold-mb5">
                    <?php echo esc_html__('Google API Key (Optional but Recommended)', 'optistate'); ?>
                </label>
                <div class="os-flex-gap-10">
                    <div class="os-flex-1-relative">
                        <input type="password" id="optistate_pagespeed_key"
                               value="<?php echo esc_attr($settings['pagespeed_api_key']??''); ?>" 
                               class="os-input-password-padded"
                               placeholder="<?php echo esc_attr__('Enter API Key', 'optistate'); ?>">
                        <span id="toggle-api-key-visibility" class="dashicons dashicons-visibility os-toggle-password-icon" 
                              title="<?php echo esc_attr__('Show/Hide API Key', 'optistate'); ?>">
                        </span>
                    </div>
                    <button type="button" class="button" id="save-pagespeed-key-btn">
                        <?php echo esc_html__('Save Key', 'optistate'); ?>
                    </button>
                </div>
                <p class="description os-mt-5">
                    <?php echo esc_html__('Without a key, tests may fail due to public rate limits.', 'optistate'); ?>
                </p>
            </div>
            <div class="os-mt-20">
                <label for="optistate-test-url" class="os-label-block-bold-mb5">
                    <?php echo esc_html__('Page to Test', 'optistate'); ?>
                </label>
                <select id="optistate-test-url" class="os-w100-mb10">
                    <option value=""><?php echo esc_html__('🏠 Homepage (Default)', 'optistate'); ?></option>
                    <optgroup label="<?php echo esc_attr__('Recent Posts', 'optistate'); ?>">
                        <?php
        $recent_posts = get_posts(['numberposts' => 10, 'post_type' => 'post', 'post_status' => 'publish']);
        foreach ($recent_posts as $post) {
            echo '<option value="' . esc_attr(get_permalink($post->ID)) . '">' . esc_html($post->post_title) . '</option>';
        }
?>
                    </optgroup>
                    <optgroup label="<?php echo esc_attr__('Pages', 'optistate'); ?>">
                        <?php
        $pages = get_pages(['number' => 20, 'sort_column' => 'post_title']);
        foreach ($pages as $page) {
            echo '<option value="' . esc_attr(get_permalink($page->ID)) . '">' . esc_html($page->post_title) . '</option>';
        }
?>
                    </optgroup>
                </select>
                <input type="text" id="optistate-custom-url" 
                       placeholder="<?php echo esc_attr__('Or enter custom URL...', 'optistate'); ?>" 
                       class="os-w100-mb10">
            </div>
            <div class="os-flex-center-gap-10">
                <select id="optistate-strategy" class="os-h-36">
                    <option value="mobile"><?php echo esc_html__('📱 Mobile Device', 'optistate'); ?></option>
                    <option value="desktop"><?php echo esc_html__('💻 Desktop', 'optistate'); ?></option>
                </select>
                <button type="button" class="button button-primary button-large" id="run-pagespeed-btn">
                    <span class="dashicons dashicons-performance os-mt-3"></span>
                    <?php echo esc_html__('Run Audit', 'optistate'); ?>
                </button>
            </div>
            <p class="os-last-checked">
                <?php echo esc_html__('Last checked:', 'optistate'); ?> <span id="psi-timestamp"><?php echo esc_html__('Never', 'optistate'); ?></span><br>
                <span id="psi-tested-url" class="os-color-link-blue"></span>
            </p>
        </div>
        <div class="optistate-psi-score-wrapper">
            <div class="optistate-score-circle" id="psi-score-circle">
                <span id="psi-score">--</span>
            </div>
            <span class="optistate-psi-text"><?php echo '🚦' . esc_html__('Performance Score', 'optistate'); ?></span>
        </div>
    </div>
    <div id="optistate-psi-metrics" class="optistate-grid-targeted os-psi-metrics-disabled">
        <div class="optistate-card optistate-targeted-card os-card-min-auto-p12">
            <div class="targeted-header"><h4>FCP (First Contentful Paint)</h4></div>
            <div class="targeted-stat os-font-11em-bold" id="psi-fcp">--</div>
        </div>
        <div class="optistate-card optistate-targeted-card os-card-min-auto-p12">
            <div class="targeted-header"><h4>LCP (Largest Contentful Paint)</h4></div>
            <div class="targeted-stat os-font-11em-bold" id="psi-lcp">--</div>
        </div>
        <div class="optistate-card optistate-targeted-card os-card-min-auto-p12">
            <div class="targeted-header"><h4>CLS (Cumulative Layout Shift)</h4></div>
            <div class="targeted-stat os-font-11em-bold" id="psi-cls">--</div>
        </div>
        <div class="optistate-card optistate-targeted-card os-card-min-auto-p12">
            <div class="targeted-header"><h4>TTFB (Time to First Byte)</h4></div>
            <div class="targeted-stat os-font-11em-bold" id="psi-ttfb">--</div>
        </div>
       <div class="optistate-card optistate-targeted-card os-card-min-auto-p12">
            <div class="targeted-header"><h4>TBT (Total Blocking Time)</h4></div>
            <div class="targeted-stat os-font-11em-bold" id="psi-tbt">--</div>
        </div>
        <div class="optistate-card optistate-targeted-card os-card-min-auto-p12">
            <div class="targeted-header"><h4>SI (Speed Index)</h4></div>
            <div class="targeted-stat os-font-11em-bold" id="psi-si">--</div>
        </div>
        <div class="optistate-card optistate-targeted-card os-card-min-auto-p12">
            <div class="targeted-header"><h4>TTI (Time to Interactive)</h4></div>
            <div class="targeted-stat os-font-11em-bold" id="psi-tti">--</div>
        </div>
        <div class="os-psi-legend"><span class="os-color-muted">🚦 COLOR KEY</span><br><span class="os-color-success">🟢 GOOD (90-100)</span><br><span class="os-color-average">🟠 AVERAGE (60-89)</span><br><span class="os-color-poor">🔴 POOR (0-59)</span></div>
    </div>
    <div id="optistate-psi-recommendations" class="os-mt-32 os-display-none">
        <h3 class="os-psi-rec-header">
            <span class="dashicons dashicons-lightbulb os-color-link-blue"></span>
            <?php echo esc_html__('Recommended Actions', 'optistate'); ?>
        </h3>
        <div id="optistate-psi-recommendations-list"></div>
    </div>
    </div>
  </div>
<div id="tab-cleanup" class="optistate-tab-content">
    <div class="optistate-card">
<h2>
    <span>
        <?php echo '🧹 ';
        echo esc_html__("5. Detailed Database Cleanup", "optistate"); ?>
    </span>
    <a href="<?php echo esc_url(plugin_dir_url(__FILE__) . 'manual/v1-2-0.html#ch-5-5'); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr__('Read the Manual', 'optistate'); ?>">
        <span class="dashicons dashicons-info"></span>
    </a>
</h2>
        <p class="os-line-height-relaxed">
            <?php echo '🔹 ';
        echo esc_html__('Items marked with this symbol ⚠️ are not included in the one-click optimization and should be reviewed carefully before deletion.', 'optistate'); ?><br>
        </p>
        <div class="optistate-cleanup-grid" id="optistate-cleanup-items"></div>
        <div class="optistate-cleanup-actions">
            <button type="button" class="button optistate-refresh-cleanup-btn"><span class="dashicons dashicons-update os-icon-middle-adj"></span><?php echo esc_html__('Refresh Cleanup Data', 'optistate'); ?></button>
        </div>
    </div>
</div>
            <div id="tab-advanced" class="optistate-tab-content">
                <div class="optistate-card">
<h2>
    <span>
        <?php echo '🗄️ ';
        echo esc_html__("6. Advanced Database Optimization", "optistate"); ?>
    </span>
    <a href="<?php echo esc_url(plugin_dir_url(__FILE__) . 'manual/v1-2-0.html#ch-5-6'); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr__('Read the Manual', 'optistate'); ?>">
        <span class="dashicons dashicons-info"></span>
    </a>
</h2>
                    <p class="os-line-height-relaxed">
                        <?php echo '🔹 ';
        echo esc_html__('Optimize and repair database tables to improve performance.', 'optistate'); ?><br>
                        <strong><?php echo '‼️ ';
        echo esc_html__('Caution', 'optistate'); ?>:</strong> 
                        <?php echo esc_html__('These operations may make your website unresponsive for a few minutes, especially if your database is large and has never been optimized!', 'optistate'); ?>
                    </p>
                    <div class="opstistate-adv-optimize">
                        <button class="button optistate-refresh-stats" id="optistate-optimize-tables"><?php echo '⚡ ';
        echo esc_html__("Optimize All Tables", "optistate"); ?></button>
                        <button class="button optistate-refresh-stats" id="optistate-analyze-repair-tables" disabled><?php echo '🛠️ ';
        echo esc_html__("Analyze & Repair Tables", "optistate"); ?><div style="font-size: 12px; font-weight: bold; margin-top: 0;"><a style="color: #007F6C;" href="https://payhip.com/b/AS3Pt" target="_blank">↑ PRO VERSION ONLY ↑</a></div></button>
                        <button class="button optistate-refresh-stats" id="optistate-optimize-autoload" disabled><?php echo '⚙️ ';
        echo esc_html__("Optimize Autoloaded Options", "optistate"); ?><div style="font-size: 12px; font-weight: bold; margin-top: 0;"><a style="color: #007F6C;" href="https://payhip.com/b/AS3Pt" target="_blank">↑ PRO VERSION ONLY ↑</a></div></button>
                    </div>
                    <div id="optistate-table-results" class="optistate-results"></div>
                    <div class="os-mt-15">
                        <p class="os-line-height-relaxed">
                            <strong><?php echo '⚡ ';
        echo esc_html__('Optimize Tables', 'optistate'); ?>:</strong> 
                            <?php echo esc_html__('Runs OPTIMIZE TABLE on all database tables to reclaim space and improve query speed.', 'optistate'); ?>
                            <br>
                            <strong><?php echo '🛠️ ';
        echo esc_html__('Analyze & Repair', 'optistate'); ?>:</strong> 
                            <?php echo esc_html__('Checks tables for errors/corruption (CHECK TABLE), then runs REPAIR TABLE to fix issues.', 'optistate'); ?>
                            <br>
                            <strong><?php echo '⚙️ ';
        echo esc_html__('Autoloaded Options', 'optistate'); ?>:</strong> 
                            <?php echo esc_html__('Identifies large autoloaded options and sets them to non-autoload to boost site speed.', 'optistate'); ?>
                        </p>
                    </div>
                    <div class="optistate-analyzer-section os-mt-30">
                        <h3 class="os-flex-gap-8-mt0">
                            <?php echo '🧩 '; echo esc_html__('Database Structure Analysis', 'optistate'); ?>
            <a href="<?php echo esc_url(plugin_dir_url(__FILE__) . 'manual/v1-2-0.html#ch-5-7'); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr__('Read the Manual', 'optistate'); ?>">
        <span class="dashicons dashicons-info"></span>
    </a>
                        </h3>
                        <p class="os-mb-15-lh-relaxed">
                            <?php echo '💡 ';
        echo esc_html__('Understand the architecture of your WordPress database better.', 'optistate'); ?><br>
                            <?php echo '✓ ';
        echo esc_html__('Core WordPress tables are explained in detail.', 'optistate'); ?><br>
                            <?php echo '⚠️ ';
        echo esc_html__('Third-party tables (from plugins/themes) are highlighted.', 'optistate'); ?>
                        </p>
                        <button type="button" class="button button-secondary os-mb-15" id="optistate-analyze-tables-btn">
                             <?php echo '🔎 ';
        echo esc_html__("Analyze Database Structure", "optistate"); ?>
                        </button>
                        <div id="optistate-table-analysis-loading" class="os-loading-block-centered">
                            <span class="spinner is-active"></span>
                            <span><?php echo esc_html__("⏳ Analyzing database structure...", "optistate"); ?>
                        </div>
                        <div id="optistate-table-analysis-results" class="os-display-none"></div>
                    </div>
<div class="optistate-analyzer-section os-mt-30">
                        <h3 class="os-flex-gap-8-mt0">
                       <?php echo '🔢 '; echo esc_html__('MySQL Index Manager', 'optistate'); ?>
            <a href="<?php echo esc_url(plugin_dir_url(__FILE__) . 'manual/v1-2-0.html#ch-5-8'); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr__('Read the Manual', 'optistate'); ?>">
        <span class="dashicons dashicons-info"></span>
    </a>
    </h3>
    <p class="os-mb-15-lh-relaxed">
        <?php echo '⊹ ';
        echo esc_html__('Scans your database for missing high-impact indexes that can drastically improve query performance.', 'optistate'); ?><br>
        <?php echo '⏲ ';
        echo esc_html__('Adding missing indexes to columns like `autoload` can reduce query time by up to 90%.', 'optistate'); ?>
    </p>
    <button type="button" class="button button-secondary os-mb-15" id="optistate-analyze-indexes-btn">
            <?php echo '🔎 '; echo esc_html__("Scan for Missing Indexes", "optistate"); ?>
    </button>
    <div id="optistate-index-analysis-loading" class="os-loading-padded">
        <span class="spinner is-active os-spinner-reset"></span> 
        <?php echo esc_html__('Analyzing database schema...', 'optistate'); ?>
    </div>
    <div id="optistate-index-results" class="os-display-none"></div>
</div>
<div class="optistate-analyzer-section os-mt-30">
    <h3 class="os-flex-gap-8-mt0">
        <?php echo '🛡️ ' . esc_html__('Referential Integrity Scanner', 'optistate'); ?>
         <a href="<?php echo esc_url(plugin_dir_url(__FILE__) . 'manual/v1-2-0.html#ch-5-9'); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr__('Read the Manual', 'optistate'); ?>">
        <span class="dashicons dashicons-info"></span>
    </a>
    </h3>
    <p class="os-mb-15-lh-relaxed">
        <?php echo '👻 ' . esc_html__('Finds "Zombie Data": Rows in your database that point to content that no longer exists.', 'optistate'); ?><br>
        <?php echo '🔗 ' . esc_html__('Example: Post Meta pointing to a Post ID that was deleted years ago. Standard cleanup often misses these.', 'optistate'); ?>
    </p>
    <div class="os-flex-gap-10-mb15">
        <button type="button" class="button button-secondary" id="optistate-run-integrity-scan">
            <?php echo '🔎 ' . esc_html__("Scan Database Integrity", "optistate"); ?>
        </button>
        <span id="optistate-integrity-loading" class="os-display-none">
            <span class="spinner is-active os-spinner-reset"></span> 
            <?php echo esc_html__('Scanning relationships...', 'optistate'); ?>
        </span>
    </div>
    <div id="optistate-integrity-results" class="os-display-none">
        </div>
</div>
                <div class="optistate-sr-form os-mt-30">
    <h3 class="optistate-sr-header">
            <?php echo '↳↰ '; echo esc_html__('Database Search & Replace', 'optistate'); ?>
    <a href="<?php echo esc_url(plugin_dir_url(__FILE__) . 'manual/v1-2-0.html#ch-5-10'); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr__('Read the Manual', 'optistate'); ?>">
        <span class="dashicons dashicons-info"></span>
    </a>
    </h3>
    <div class="notice notice-warning inline os-warning-inline">
        <p><strong><?php echo '⚠️ ' . esc_html__('Advanced Feature - Use with Caution', 'optistate'); ?></strong></p>
        <p>
            <?php echo esc_html__('This tool searches your database for specific text and replaces it. It handles WordPress serialized data correctly.', 'optistate'); ?><br>
            <?php echo esc_html__('1. Always perform a Dry Run first to see what will be changed.', 'optistate'); ?><br>
            <?php echo esc_html__('2. Create a fresh database backup before replacing.', 'optistate'); ?>
        </p>
    </div>
    <div class="optistate-sr-inputs-wrapper">
        <div class="optistate-sr-input-group">
            <label for="optistate-sr-search" class="sr-search"><?php esc_html_e('Search For:', 'optistate'); ?></label>
            <input type="text" id="optistate-sr-search" class="sr-search-2" placeholder="e.g. http://old-domain.com" maxlength="600">
        </div>
        <div class="optistate-sr-input-group">
            <label for="optistate-sr-replace" class="sr-search"><?php esc_html_e('Replace With:', 'optistate'); ?></label>
            <input type="text" id="optistate-sr-replace" class="sr-search-2" placeholder="e.g. https://new-domain.com" maxlength="600">
        </div>
    </div>
    <div class="os-mt-15">
        <label class="os-cursor-pointer">
            <input type="checkbox" id="optistate-sr-case-sensitive" class="os-mr-4">
            <span class="os-font-weight-600"><?php esc_html_e('🔎 Case Sensitive', 'optistate'); ?></span>
        </label>
        <p class="description os-sr-desc-indent">
            <?php esc_html_e('If checked, "Apple" will not match "apple". Recommended for specific code replacements.', 'optistate'); ?>
        </p>
    </div>
    <div class="os-mt-10">
        <label class="os-cursor-pointer">
            <input type="checkbox" id="optistate-sr-partial-match" class="os-mr-4">
            <span class="os-font-weight-600"><?php esc_html_e('🧩 Partial Match', 'optistate'); ?></span>
        </label>
        <p class="description os-sr-desc-indent">
            <?php esc_html_e('If checked, searches for partial text anywhere in strings (e.g., "http://" in URLs). If unchecked, only matches complete words with boundaries.', 'optistate'); ?><br>
            <?php esc_html_e('ⓘ Usage example: "http://" ⮕ "https://"', 'optistate'); ?>
        </p>
    </div>
    <div class="os-mt-15">
        <label for="optistate-sr-tables" class="sr-tables"><?php esc_html_e('Select Tables (Optional):', 'optistate'); ?></label>
        <select id="optistate-sr-tables" multiple class="sr-tables-list">
            <option value="all" selected><?php esc_html_e('-- All Tables --', 'optistate'); ?></option>
            <?php
        global $wpdb;
        $tables = $wpdb->get_col("SHOW TABLES");
        foreach ($tables as $table) {
            echo '<option value="' . esc_attr($table) . '">' . esc_html($table) . '</option>';
        }
?>
        </select>
        <p class="description"><?php esc_html_e('Hold Ctrl/Cmd to select multiple specific tables. Leave as "All Tables" for a full site update.', 'optistate'); ?></p>
    </div>
    <div class="optistate-sr-actions">
        <button type="button" class="button button-secondary button-large" id="optistate-sr-dry-run">
            🔎️️ <?php esc_html_e('Perform Dry Run (Preview)', 'optistate'); ?>
        </button>
        <button type="button" class="button button-primary button-large" id="optistate-sr-execute" disabled>
            ↳↰ <?php esc_html_e('Execute Replacement', 'optistate'); ?>
        </button><span style="margin-left: 4px; font-size: 12px; font-weight: bold;"><a style="color: #007F6C;" href="https://payhip.com/b/AS3Pt" target="_blank">➝ PRO VERSION ONLY</a></span>
        <span id="optistate-sr-loading" class="os-sr-loading-span">
            <span class="spinner is-active os-spinner-reset"></span> <span class="sr-status-text"><?php esc_html_e('Processing...', 'optistate'); ?></span>
        </span>
    </div>
    <div id="optistate-sr-results" class="os-mt-20-hidden">
          </div>
       </div>
      </div>
    </div>
   
            <div id="tab-automation" class="optistate-tab-content">
                <div class="optistate-card">
<h2>
    <span>
        <span class="dashicons dashicons-clock"></span> 
        <?php echo esc_html__("7. Automatic Backup and Cleanup", "optistate"); ?>
    </span>
    <a href="<?php echo esc_url(plugin_dir_url(__FILE__) . 'manual/v1-2-0.html#ch-6'); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr__('Read the Manual', 'optistate'); ?>">
        <span class="dashicons dashicons-info"></span>
    </a>
</h2>
                    <div id="optistate-auto-settings-form">
                        <table class="form-table">
                            <tr>
                                <th><?php echo esc_html__("Run Tasks Automatically Every", "optistate"); ?></th>
                                <td>
                                    <input type="number" class="os-input-days" id="auto_optimize_days" name="<?php echo esc_attr(OPTISTATE::OPTION_NAME); ?>[auto_optimize_days]" value="<?php echo esc_attr($auto_optimize_days); ?>" min="0" max="0" disabled> 
                                    <strong><?php echo esc_html__('DAYS', 'optistate'); ?></strong> 
                                    <?php echo esc_html__('(0 to disable)', 'optistate'); ?>
                                    <span class="os-ml-15">
                                        <?php echo esc_html__("at", "optistate"); ?>
                                        <select class="os-select-time" id="auto_optimize_time" name="<?php echo esc_attr(OPTISTATE::OPTION_NAME); ?>[auto_optimize_time]" disabled>
                                            <?php
        for ($hour = 0;$hour < 24;$hour++) {
            $time_value = sprintf("%02d:00", $hour);
            $time_display = date_i18n("g:i A", strtotime($time_value));
            $selected = $time_value === $auto_optimize_time ? 'selected="selected"' : '';
            echo '<option value="' . esc_attr($time_value) . '" ' . esc_attr($selected) . '>' . esc_html($time_display) . '</option>';
        }
?>
                                        </select>
                                    </span>
                                    <p class="os-line-height-relaxed">
                                        <span id="auto-status-enabled" style="<?php echo $auto_optimize_days > 0 ? '' : 'display:none;'; ?>">
                                            <?php
        $task_description = $auto_backup_only ? esc_html__('Automated *backup only*', 'optistate') : esc_html__('Automated *backup & cleanup*', 'optistate');
        echo '✅ ';
        printf(esc_html__('%1$s is enabled and will run every %2$d days at %3$s.', 'optistate'), esc_html($task_description), absint($auto_optimize_days), esc_html(date_i18n('g:i A', strtotime($auto_optimize_time))));
?>
                                        </span>
                                        <span id="auto-status-disabled" style="<?php echo $auto_optimize_days > 0 ? 'display:none;' : ''; ?>">
                                            🔴 <?php echo esc_html__("Automated optimization is currently disabled.", "optistate"); ?>
                                        </span>
                                        <br>
                                        <span id="auto-task-desc-full" style="<?php echo $auto_backup_only ? 'display:none;' : ''; ?>">
                                            <?php echo 'ℹ️ ';
        echo esc_html__('When enabled, the following tasks will be performed regularly:', 'optistate'); ?> 
                                            <?php echo '➜ ';
        echo esc_html__('Database Backup', 'optistate'); ?> 
                                            <?php echo '➜ ';
        echo esc_html__('One-Click Optimization.', 'optistate'); ?><br>
                                            <?php echo '💡';
        echo esc_html__('Tip: Choose a time when website traffic is usually lower.', 'optistate'); ?> 
                                        </span>
                                        <span id="auto-task-desc-backup-only" style="<?php echo $auto_backup_only ? '' : 'display:none;'; ?>">
                                            <?php echo 'ℹ️ ';
        echo esc_html__('When enabled, the following tasks will be performed regularly:', 'optistate'); ?> 
                                            <?php echo '➜ ';
        echo esc_html__('Database Backup.', 'optistate'); ?><br>
                                            <?php echo '💡';
        echo esc_html__('Tip: Choose a time when website traffic is usually lower.', 'optistate'); ?> 
                                        </span>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html__("Backup Only", "optistate"); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="auto_backup_only" name="<?php echo esc_attr(OPTISTATE::OPTION_NAME); ?>[auto_backup_only]" value="1" <?php checked($auto_backup_only, true); ?> disabled>
                                        <strong><?php echo esc_html__("Perform database backup ONLY (skip cleanup)", "optistate"); ?></strong>
                                    </label>
                                    <p class="os-line-height-relaxed">
                                        <span id="auto-backup-only-status">
                                            <?php if ($auto_backup_only): ?>
                                                ✅ <?php echo esc_html__("Backup Only mode is enabled.", "optistate"); ?>
                                            <?php
        else: ?>
                                                ℹ️ <?php echo esc_html__("Backup & Cleanup mode is enabled.", "optistate"); ?>
                                            <?php
        endif; ?>
                                        </span>
                                        <br>
                                        <?php echo esc_html__("If checked, the scheduled task will only create a database backup. The automatic cleanup will be skipped.", "optistate"); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html__("Email Notifications", "optistate"); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="email_notifications" name="<?php echo esc_attr(OPTISTATE::OPTION_NAME); ?>[email_notifications]" value="1" <?php checked($email_notifications, true); ?> disabled>
                                        <?php echo esc_html__("Send completion email with backup and cleanup details", "optistate"); ?>
                                    </label>
                                    <p class="os-line-height-relaxed">
                                        <?php if ($email_notifications): ?>
                                            <span id="email-status-enabled"><?php echo '✅ ';
            echo esc_html__("Email notifications are enabled.", "optistate"); ?></span>
                                            <span id="email-status-disabled" class="os-display-none"><?php echo '🔴 ';
            echo esc_html__("Email notifications are disabled.", "optistate"); ?></span>
                                        <?php
        else: ?>
                                            <span id="email-status-enabled" class="os-display-none"><?php echo '✅ ';
            echo esc_html__("Email notifications are enabled.", "optistate"); ?></span>
                                            <span id="email-status-disabled"><?php echo '🔴 ';
            echo esc_html__("Email notifications are disabled.", "optistate"); ?></span>
                                        <?php
        endif; ?>
                                        <br>
                                        <?php echo '📧 ';
        printf(esc_html__('Notifications will be sent to: %s', 'optistate'), '<strong>' . esc_html(get_option('admin_email')) . '</strong>'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                        <button type="submit" class="button button-primary os-save-settings-btn" id="save-auto-optimize-btn" disabled>
                            <?php echo '✓ ';
        echo esc_html__("Save Settings", "optistate"); ?>
                        </button><div style="font-size: 12px; font-weight: bold; margin-top: -10px;"><a style="color: #007F6C;" href="https://payhip.com/b/AS3Pt" target="_blank">↑ PRO VERSION ONLY ↑</a></div>
                    </div>
                    <div id="optistate-settings-log"></div>
                </div>
            </div>
            <div id="tab-performance" class="optistate-tab-content">
                <div class="optistate-card">
<h2>
    <span>
        <span class="dashicons dashicons-admin-settings"></span> 
        <?php echo esc_html__("8. Performance Features Manager", "optistate"); ?>
    </span>
    <a href="<?php echo esc_url(plugin_dir_url(__FILE__) . 'manual/v1-2-0.html#ch-7'); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr__('Read the Manual', 'optistate'); ?>">
        <span class="dashicons dashicons-info"></span>
    </a>
</h2>
                    <div id="optistate-performance-content-wrapper">
                        <p class="os-line-height-relaxed">
                            <?php echo '🎯 ';
        echo esc_html__('Activate or deactivate WordPress features according to your needs for improved performance and lower server load.', 'optistate'); ?><br>
                            <strong><?php echo '⚠️ ';
        echo esc_html__('Important:', 'optistate'); ?></strong> 
                            <?php echo esc_html__('Some features may affect functionality. Features marked with ⚠️ should be tested carefully.', 'optistate'); ?><br>
                            <?php echo '✔ ';
        echo esc_html__('Click the Save button at the bottom of this list to confirm features activation/update.', 'optistate'); ?>
                        </p>
                        <div id="optistate-performance-features-loading" class="os-loading-padded-center">
                            <span class="spinner is-active"></span>
                            <span><?php echo esc_html__('Loading performance features...', 'optistate'); ?></span>
                        </div>
                        <div id="optistate-performance-features-container" class="os-display-none"></div>
                        <div id="optistate-performance-features-actions" class="optistate-features-actions">
                            <button type="button" class="button button-primary button-large os-save-perf-btn" id="save-performance-features-btn">
                                <?php echo '✓ ';
        echo esc_html__('Save Performance Settings', 'optistate'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div id="tab-settings" class="optistate-tab-content">
                <div class="optistate-card">
<h2>
    <span>
        <span class="dashicons dashicons dashicons-admin-generic"></span> 
        <?php echo esc_html__("9. Settings Export/Import & User Access", "optistate"); ?>
    </span>
    <a href="<?php echo esc_url(plugin_dir_url(__FILE__) . 'manual/v1-2-0.html#ch-9'); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr__('Read the Manual', 'optistate'); ?>">
        <span class="dashicons dashicons-info"></span>
    </a>
</h2>
                    <div id="optistate-export-content-wrapper">
                        <p class="os-line-height-relaxed">
                            <?php echo '⛯ ';
        echo esc_html__('Backup your plugin settings in order to restore them later, or export them to another WordPress site.', 'optistate'); ?><br>
                            <strong><?php echo '⚠️ ';
        echo esc_html__('Important:', 'optistate'); ?></strong> 
                            <?php echo esc_html__('This only exports plugin settings (backup limits, automation schedule, performance features, user access restrictions). It does NOT export your database backups or cached pages.', 'optistate'); ?>
                        </p>
                        <div class="optistate-grid-2">
                            <div class="optistate-file-export">
                                <h3 class="os-mt-0">
                                    <?php echo '📤 ';
        echo esc_html__('Export Settings', 'optistate'); ?>
                                </h3>
                                <p class="os-line-height-relaxed">
                                    <?php echo esc_html__('Download a JSON file containing all your plugin settings.', 'optistate'); ?><br>
                                    <?php echo '✓ ';
        echo esc_html__('Includes: Backup limits, automation schedule, performance features, user access restrictions.', 'optistate'); ?>
                                </p>
                                <button type="button" class="button-2" id="optistate-export-settings-btn">
                                    <span class="dashicons dashicons-download"></span> 
                                    <?php echo esc_html__('Export Settings', 'optistate'); ?>
                                </button>
                                <div id="optistate-export-status" class="os-mt-10"></div>
                            </div>
                            <div class="optistate-file-import">
                                <h3 class="os-mt-0">
                                    <?php echo '📥 ';
        echo esc_html__('Import Settings', 'optistate'); ?>
                                </h3>
                                <p class="os-line-height-relaxed">
                                    <?php echo esc_html__('Upload a settings file to replace current configuration.', 'optistate'); ?><br>
                                    <strong><?php echo '⚠️ ';
        echo esc_html__('Warning:', 'optistate'); ?></strong> 
                                    <?php echo esc_html__('This will overwrite your current settings!', 'optistate'); ?>
                                </p>
                                <div class="optistate-file-upload-area os-mb-10">
                                    <input type="file" id="optistate-settings-file-input" class="optistate-file-input" accept=".json">
                                    <label for="optistate-settings-file-input" class="optistate-file-label">
                                        <span class="dashicons dashicons-upload"></span> 
                                        <?php echo esc_html__('Choose JSON File', 'optistate'); ?>
                                    </label>
                                </div>
                                <div id="optistate-settings-file-info" class="optistate-file-info">
                                    <strong><?php echo esc_html__('Selected:', 'optistate'); ?></strong> 
                                    <span id="optistate-settings-file-name"></span>
                                </div>
                                <button type="button" class="button-2" id="optistate-import-settings-btn" disabled>
                                    <span class="dashicons dashicons-upload"></span> 
                                    <?php echo esc_html__('Import Settings', 'optistate'); ?>
                                </button>
                                <div id="optistate-import-status" class="os-mt-10"></div>
                            </div>
                        </div>
                        <div class="optistate-user-access">
                            <h3 class="os-mt-5">
                                <?php echo '🔐 ' . esc_html__('User Access Control', 'optistate'); ?>
                            </h3>
                            <p class="os-line-height-relaxed">
                                <?php echo esc_html__('Enable only selected administrators to use the plugin. If no users are selected, all admins can use the plugin.', 'optistate'); ?><br>
                                <strong><?php echo '⚠️ ' . esc_html__('Warning:', 'optistate'); ?></strong> 
                                <?php echo esc_html__('Do not lock yourself out! Always include your own account in the list.', 'optistate'); ?>
                            </p>
                            <?php
        $current_settings = $this->get_persistent_settings();
        $allowed_users = $current_settings['allowed_users']??[];
        $current_user_id = get_current_user_id();
        $admin_users = get_users(['role' => 'administrator', 'orderby' => 'display_name', 'order' => 'ASC']);
?>
                            <div class="optistate-admin-access">
                                <?php if (empty($admin_users)): ?>
                                    <p><?php echo esc_html__('No administrator accounts found.', 'optistate'); ?></p>
                                <?php
        else: ?>
                                    <?php foreach ($admin_users as $user): ?>
                                        <label class="optistate-admin-list <?php echo ($user->ID === $current_user_id) ? 'os-current-user-border' : ''; ?>">
                                            <input 
                                                type="checkbox" 
                                                class="optistate-allowed-user os-mr-8" 
                                                value="<?php echo esc_attr($user->ID); ?>"
                                                <?php checked(in_array($user->ID, $allowed_users)); ?>
                                            >
                                            <strong><?php echo esc_html($user->display_name); ?></strong>
                                            <span class="os-color-muted">
                                                (<?php echo esc_html($user->user_login); ?>)
                                                <?php if ($user->ID === $current_user_id): ?>
                                                    <span class="os-current-user-tag">← <?php echo esc_html__('You', 'optistate'); ?></span>
                                                <?php
                endif; ?>
                                            </span>
                                        </label>
                                    <?php
            endforeach; ?>
                                <?php
        endif; ?>
                            </div>
                            <p class="os-tip-text">
                                <?php echo '💡 ' . esc_html__('Tip: Leave all checkboxes unchecked to allow all administrators.', 'optistate'); ?>
                            </p>
                            <button type="button" class="button button-primary os-save-user-access-btn" id="optistate-save-user-access-btn" disabled>
                                <?php echo '✓ ' . esc_html__('Save User Access Settings', 'optistate'); ?>
                            </button><div style="margin-top: 10px; font-size: 12px; font-weight: bold;"><a style="color: #007F6C;" href="https://payhip.com/b/AS3Pt" target="_blank">↑ PRO VERSION ONLY ↑</a></div>
                            <div id="optistate-user-access-status" class="os-mt-10-mb-neg-10"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
    }
    public function log_optimization($type = "manual", $operation = null, $backup_filename = "") {
        if (null === $operation) {
            $operation = '🚀 ' . __("One-Click Optimization", "optistate") . ' + 💾 ' . __("Backup Created", "optistate");
        }
        $log_entries = $this->get_optimization_log();
        $log_entry = ["timestamp" => time(), "type" => $type, "date" => wp_date(get_option('date_format') . ' ' . get_option('time_format'), time()), "operation" => $operation, "backup_filename" => $backup_filename, "timezone" => get_option("timezone_string"), "gmt_offset" => get_option("gmt_offset"), ];
        array_unshift($log_entries, $log_entry);
        $log_entries = array_slice($log_entries, 0, 200);
        $this->save_log_to_file($log_entries);
    }
    private function save_log_to_file($log_entries) {
        $json_data = json_encode($log_entries, JSON_PRETTY_PRINT);
        if ($json_data === false) {
            return false;
        }
        $require_csrf = defined("DOING_AJAX") && DOING_AJAX;
        return $this->secure_file_write_atomic($this->log_file_path, $json_data, $require_csrf);
    }
    private function get_optimization_log() {
        $json_data = $this->secure_file_read($this->log_file_path);
        if ($json_data === false) {
            return [];
        }
        $log_entries = json_decode($json_data, true);
        if (is_array($log_entries)) {
            foreach ($log_entries as & $entry) {
                if (!isset($entry["timestamp"]) && isset($entry["date"])) {
                    $entry["timestamp"] = strtotime($entry["date"] . " GMT") + (float)get_option("gmt_offset") * HOUR_IN_SECONDS;
                }
            }
        }
        return is_array($log_entries) ? $log_entries : [];
    }
    public function ajax_get_table_analysis() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->check_user_access();
        $cache_key = 'optistate_table_analysis_cache';
        $cached_analysis = get_transient($cache_key);
        if ($cached_analysis !== false && is_array($cached_analysis)) {
            wp_send_json_success($cached_analysis);
            return;
        }
        global $wpdb;
        $core_table_definitions = ['commentmeta' => __('Comment Meta: Stores custom fields and extra data for comments.', 'optistate'), 'comments' => __('Comments: Contains all comments on posts, pages, and attachments.', 'optistate'), 'links' => __('Links: Stores blogroll links. Deprecated and rarely used in modern sites.', 'optistate'), 'options' => __('Options: Stores sitewide settings, plugin/theme configurations, and cached data.', 'optistate'), 'postmeta' => __('Post Meta: Contains custom fields and extra data for posts, pages, and CPTs.', 'optistate'), 'posts' => __('Posts: Stores all content, including posts, pages, attachments, and revisions.', 'optistate'), 'termmeta' => __('Term Meta: Stores custom fields and extra data for taxonomy terms (categories, tags).', 'optistate'), 'terms' => __('Terms: Stores the names and slugs for all categories, tags, and custom taxonomy terms.', 'optistate'), 'term_relationships' => __('Term Relationships: Links posts (from wp_posts) to their terms (from wp_terms).', 'optistate'), 'term_taxonomy' => __('Term Taxonomy: Defines the taxonomy (e.g., category, tag) for each term in wp_terms.', 'optistate'), 'usermeta' => __('User Meta: Stores extra user data, like first/last name, and user preferences.', 'optistate'), 'users' => __('Users: Stores all user accounts, including login names, hashed passwords, and emails.', 'optistate') ];
        if (is_multisite()) {
            $core_table_definitions['blogmeta'] = __('Blog Meta: Stores extra data for sites in the network.', 'optistate');
            $core_table_definitions['blogs'] = __('Blogs: Stores information about each site in the network.', 'optistate');
            $core_table_definitions['registration_log'] = __('Registration Log: Stores log of new user registrations.', 'optistate');
            $core_table_definitions['signups'] = __('Signups: Stores user signups, used when new blog/user registration is enabled.', 'optistate');
            $core_table_definitions['site'] = __('Site: Stores network-wide site data.', 'optistate');
            $core_table_definitions['sitemeta'] = __('Site Meta: Stores extra network-wide site meta data.', 'optistate');
        }
        $prefix_pattern = '/^' . preg_quote($wpdb->base_prefix, '/') . '(\d+_)?/';
        $tables_query = $wpdb->prepare("
        SELECT 
          TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, DATA_FREE, ENGINE, TABLE_COLLATION, UPDATE_TIME, CREATE_TIME
          FROM information_schema.TABLES 
          WHERE table_schema = %s
          ORDER BY TABLE_NAME ASC
        ", DB_NAME);
        $tables = $wpdb->get_results($tables_query, OBJECT);
        if (!$tables) {
            $tables = $wpdb->get_results("SHOW TABLE STATUS", OBJECT);
        }
        if (!$tables) {
            wp_send_json_error(esc_html__("Failed to retrieve table information", "optistate"));
            return;
        }
        $analysis = ['core_tables' => [], 'plugin_tables' => [], 'totals' => ['total_tables' => 0, 'core_count' => 0, 'plugin_count' => 0, 'total_size' => 0, 'core_size' => 0, 'plugin_size' => 0, 'total_rows' => 0], 'db_name' => DB_NAME];
        foreach ($tables as $table) {
            $prop_name = isset($table->TABLE_NAME) ? $table->TABLE_NAME : $table->Name;
            $prop_rows = isset($table->TABLE_ROWS) ? $table->TABLE_ROWS : $table->Rows;
            $prop_data = isset($table->DATA_LENGTH) ? $table->DATA_LENGTH : $table->Data_length;
            $prop_index = isset($table->INDEX_LENGTH) ? $table->INDEX_LENGTH : $table->Index_length;
            $prop_free = isset($table->DATA_FREE) ? $table->DATA_FREE : $table->Data_free;
            $prop_engine = isset($table->ENGINE) ? $table->ENGINE : $table->Engine;
            $prop_collation = isset($table->TABLE_COLLATION) ? $table->TABLE_COLLATION : $table->Collation;
            $prop_updated = isset($table->UPDATE_TIME) ? $table->UPDATE_TIME : (isset($table->Update_time) ? $table->Update_time : null);
            $prop_created = isset($table->CREATE_TIME) ? $table->CREATE_TIME : (isset($table->Create_time) ? $table->Create_time : null);
            $table_name = $prop_name;
            $base_name = preg_replace($prefix_pattern, '', $table_name);
            $is_core = isset($core_table_definitions[$base_name]);
            $is_optistate_processes = ($table_name === $wpdb->prefix . 'optistate_processes');
            $is_optistate_metadata = ($table_name === $wpdb->prefix . 'optistate_backup_metadata');
            $is_optistate = ($is_optistate_processes || $is_optistate_metadata);
            $description = $is_core ? $core_table_definitions[$base_name] : __('Third-party plugin/theme table', 'optistate');
            if ($is_optistate_processes) {
                $description = __('WP Optimal State Plugin: Ensures reliability in sensitive database operations by persisting backup/restore states to prevent timeouts. This table will be deleted upon plugin deactivation.', 'optistate');
            } elseif ($is_optistate_metadata) {
                $description = __('WP Optimal State Plugin: Stores metadata for generated database backups to verify file integrity and enforce retention limits. This table will be deleted if the plugin is uninstalled.', 'optistate');
            }
            $overhead_bytes = (int)$prop_free * 0.11;
            $date_format = get_option('date_format') . ' ' . get_option('time_format');
            $updated_local_formatted = $prop_updated ? mysql2date($date_format, $prop_updated, true) : __('Unknown', 'optistate');
            $created_local_formatted = $prop_created ? mysql2date($date_format, $prop_created, true) : __('Unknown', 'optistate');
            $is_abandoned = false;
            $abandoned_text = '';
            $abandoned_threshold = 2592000;
            if (!($is_core || $is_optistate) && $prop_updated) {
                $update_ts = strtotime($prop_updated);
                if ($update_ts && (time() - $update_ts > $abandoned_threshold)) {
                    $is_abandoned = true;
                    $abandoned_text = __('This table has not been accessed in over 30 days. It may belong to a deactivated or uninstalled plugin or theme.', 'optistate');
                }
            }
            $table_info = ['name' => $table_name, 'rows' => (int)$prop_rows, 'data_size' => (int)$prop_data, 'index_size' => (int)$prop_index, 'total_size' => (int)$prop_data + (int)$prop_index, 'overhead' => $overhead_bytes, 'engine' => $prop_engine, 'collation' => $prop_collation, 'updated' => $updated_local_formatted, 'created' => $created_local_formatted, 'description' => $description, 'is_core' => $is_core || $is_optistate, 'is_abandoned' => $is_abandoned, 'abandoned_text' => $abandoned_text];
            if ($is_core || $is_optistate) {
                $analysis['core_tables'][] = $table_info;
                $analysis['totals']['core_count']++;
                $analysis['totals']['core_size']+= $table_info['total_size'];
            } else {
                $analysis['plugin_tables'][] = $table_info;
                $analysis['totals']['plugin_count']++;
                $analysis['totals']['plugin_size']+= $table_info['total_size'];
            }
            $analysis['totals']['total_size']+= $table_info['total_size'];
            $analysis['totals']['total_rows']+= $table_info['rows'];
            $analysis['totals']['total_tables']++;
        }
        set_transient($cache_key, $analysis, 2 * MINUTE_IN_SECONDS);
        wp_send_json_success($analysis);
    }
    public function ajax_get_stats() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $force_refresh = isset($_POST["force_refresh"]) && sanitize_text_field(wp_unslash($_POST["force_refresh"])) == "true";
        if ($force_refresh) {
            if (!$this->check_rate_limit("refresh_stats", 6)) {
                wp_send_json_error(['message' => __('Please wait a few seconds before refreshing stats again.', 'optistate') ], 429);
                return;
            }
            delete_transient('optistate_db_size_cache');
        }
        try {
            $stats = $this->get_combined_database_statistics($force_refresh);
            wp_send_json_success($stats);
        }
        catch(Exception $e) {
            wp_send_json_error(['message' => $e->getMessage() ]);
        }
    }
    private function should_not_serve_cache() {
        if (isset($_GET['s'])) {
            return true;
        }
        if (is_user_logged_in()) {
            return true;
        }
        if (is_customize_preview()) {
            return true;
        }
        if (is_404() || post_password_required()) {
            return true;
        }
        $performance_settings = $this->_performance_get_settings();
        $cookie_check_disabled = isset($performance_settings['server_caching']['disable_cookie_check']) && $performance_settings['server_caching']['disable_cookie_check'];
        if ($cookie_check_disabled) {
            $patterns = $this->get_compiled_exclude_patterns();
            if (!empty($patterns) && is_array($patterns)) {
                $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
                if ($request_uri !== '') {
                    foreach ($patterns as $pattern) {
                        if (@preg_match($pattern, $request_uri)) {
                            return true;
                        }
                    }
                }
            }
            return false;
        }
        if (!$this->has_consent_cookie_early()) {
            return true;
        }
        $patterns = $this->get_compiled_exclude_patterns();
        if (!empty($patterns) && is_array($patterns)) {
            $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            if ($request_uri !== '') {
                foreach ($patterns as $pattern) {
                    if (@preg_match($pattern, $request_uri)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
    private function get_compiled_exclude_patterns() {
        if (!is_array($this->server_caching_settings)) {
            $settings = $this->_performance_get_settings();
            $this->server_caching_settings = isset($settings['server_caching']) && is_array($settings['server_caching']) ? $settings['server_caching'] : ['exclude_urls' => ''];
        }
        $current_exclude_urls = isset($this->server_caching_settings['exclude_urls']) ? (string)$this->server_caching_settings['exclude_urls'] : '';
        if ($this->compiled_exclude_patterns !== null && $this->exclude_urls_raw_cache === $current_exclude_urls) {
            return $this->compiled_exclude_patterns;
        }
        $this->exclude_urls_raw_cache = $current_exclude_urls;
        if (empty($current_exclude_urls) || trim($current_exclude_urls) === '') {
            $this->compiled_exclude_patterns = [];
            return [];
        }
        $excluded_paths = array_filter(array_map('trim', explode("\n", $current_exclude_urls)), function ($path) {
            return !empty($path) && $path !== '';
        });
        $patterns = [];
        foreach ($excluded_paths as $path) {
            $safe_pattern = str_replace('\*', '.*', preg_quote($path, '#'));
            $patterns[] = '#' . $safe_pattern . '#i';
        }
        $this->compiled_exclude_patterns = $patterns;
        return $patterns;
    }
    public function capture_and_cache_output($buffer) {
        if (!is_string($buffer)) {
            return '';
        }
        if (strlen($buffer) < 256 || http_response_code() !== 200) {
            return $buffer;
        }
        if ($this->should_not_serve_cache()) {
            return $buffer;
        }
        if (headers_sent() || http_response_code() !== 200) {
            return $buffer;
        }
        if (preg_match('/<title>.*?(error|fatal|warning|parse error).*?<\/title>/i', $buffer)) {
            return $buffer;
        }
        try {
            $host = $this->get_trusted_host();
            $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw($_SERVER['REQUEST_URI']) : '/';
            $is_mobile = $this->is_mobile_request;
            $cache_file = $this->get_cache_path($host, $uri, $is_mobile);
            if (empty($cache_file) || strpos($cache_file, $this->cache_dir) !== 0) {
                return $buffer;
            }
            $temp_cache_file = $cache_file . '.tmp';
            if ($this->wp_filesystem->put_contents($temp_cache_file, $buffer, FS_CHMOD_FILE)) {
                $this->wp_filesystem->move($temp_cache_file, $cache_file, true);
            }
        }
        catch(Exception $e) {
        }
        return $buffer;
    }
    private function get_safe_query_string($query_string) {
        if (empty($query_string)) {
            return '';
        }
        static $safe_params_list = null;
        if ($safe_params_list === null) {
            $default_safe_params = ['page', 'paged', 'lang', 'replytocom', 's', 'sort', 'orderby', 'view', 'amp', 'product_cat', 'product_tag', 'product-page', 'brand', 'eventDisplay', 'eventDate', 'forum-page'];
            $user_safe_params = apply_filters('optistate_safe_query_params', []);
            if (!is_array($user_safe_params)) {
                $user_safe_params = [];
            }
            $validated_user_params = array_filter($user_safe_params, function ($param) {
                if (!is_string($param)) {
                    return false;
                }
                return preg_match('/^[a-zA-Z0-9_-]{1,32}$/D', $param);
            });
            $safe_params_list = array_merge($default_safe_params, array_values($validated_user_params));
            $safe_params_list = array_unique($safe_params_list);
            if (count($safe_params_list) > 50) {
                $safe_params_list = array_slice($safe_params_list, 0, 50);
            }
        }
        $params = [];
        parse_str($query_string, $params);
        if (empty($params)) {
            return '';
        }
        $safe_params = [];
        foreach ($params as $key => $value) {
            if (in_array($key, $safe_params_list, true)) {
                $s_key = sanitize_text_field($key);
                $s_value = is_array($value) ? array_map('sanitize_text_field', $value) : sanitize_text_field($value);
                $safe_params[$s_key] = $s_value;
            }
        }
        if (empty($safe_params)) {
            return '';
        }
        ksort($safe_params);
        return http_build_query($safe_params);
    }
    private function get_cache_path($host, $uri, $is_mobile = null) {
        $settings = $this->server_caching_settings??[];
        if ($is_mobile === null) {
            $is_mobile = $this->is_mobile_request??false;
        }
        $lookup_key = $host . '|' . $uri . '|' . ($is_mobile ? '1' : '0');
        if (isset($this->cache_path_cache[$lookup_key])) {
            return $this->cache_path_cache[$lookup_key];
        }
        $path = wp_parse_url($uri, PHP_URL_PATH) ? : '/';
        $query_string_mode = $settings['query_string_mode']??'include_safe';
        $cache_key_uri = $path;
        if ($query_string_mode === 'unique_cache') {
            $cache_key_uri = $uri;
        } elseif ($query_string_mode === 'include_safe') {
            $query_string = wp_parse_url($uri, PHP_URL_QUERY);
            if (!empty($query_string)) {
                $safe_query = $this->get_safe_query_string($query_string);
                if (!empty($safe_query)) {
                    $cache_key_uri = rtrim($path, '/') . '?' . $safe_query;
                }
            }
        }
        $cache_key = wp_hash($host . $cache_key_uri);
        if ($is_mobile) {
            $cache_key.= '-mobile';
        }
        $result = $this->cache_dir . $cache_key . '.html';
        $this->cache_path_cache[$lookup_key] = $result;
        return $result;
    }
    public function ajax_purge_page_cache() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, 'nonce');
        if (!$this->check_rate_limit("purge_cache", 30)) {
            wp_send_json_error(['message' => esc_html__('🕔 Please wait 30 seconds before purging the cache again.', 'optistate') ], 429);
            return;
        }
        if (!$this->wp_filesystem) {
            $log_message = '❌ ' . esc_html__("Cache Purge Failed: WP_Filesystem missing", "optistate");
            $this->log_optimization("manual", $log_message, "");
            wp_send_json_error(['message' => esc_html__('WP_Filesystem not initialized.', 'optistate') ]);
            return;
        }
        $cache_dir = $this->cache_dir;
        if ($this->wp_filesystem->is_dir($cache_dir)) {
            if (!$this->wp_filesystem->delete($cache_dir, true)) {
                $log_message = '❌ ' . esc_html__("Cache Purge Failed: Directory deletion error", "optistate");
                $this->log_optimization("manual", $log_message, "");
                wp_send_json_error(['message' => esc_html__('Failed to delete cache directory. Check permissions.', 'optistate') ]);
                return;
            }
        }
        if (!wp_mkdir_p($cache_dir)) {
            $log_message = '❌ ' . esc_html__("Cache Purge Failed: Directory recreation error", "optistate");
            $this->log_optimization("manual", $log_message, "");
            wp_send_json_error(['message' => esc_html__('Failed to re-create cache directory.', 'optistate') ]);
            return;
        }
        $this->wp_filesystem->chmod($cache_dir, 0755);
        $this->secure_cache_directory();
        $log_message = '🗑️ ' . esc_html__("Page Cache Purged", "optistate");
        $this->log_optimization("manual", $log_message, "");
        $settings = $this->_performance_get_settings();
        wp_send_json_success(['message' => esc_html__('Successfully purged the entire page cache.', 'optistate') ]);
    }
    public function ajax_get_cache_stats() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, 'nonce');
        if (!$this->wp_filesystem) {
            wp_send_json_error(['message' => esc_html__('WP_Filesystem not initialized.', 'optistate') ]);
        }
        $file_count = 0;
        $total_size = 0;
        $mobile_file_count = 0;
        $newest_file_time = null;
        $oldest_file_time = null;
        $files = $this->wp_filesystem->dirlist($this->cache_dir);
        $MIN_VALID_TIMESTAMP = 978307200;
        if (!empty($files)) {
            foreach ($files as $file) {
                if (substr($file['name'], -5) === '.html') {
                    $file_count++;
                    $total_size+= (int)$file['size'];
                    if (!isset($file['lastmodunix'])) {
                        continue;
                    }
                    $file_time = (int)$file['lastmodunix'];
                    if (strpos($file['name'], '-mobile.html') !== false) {
                        $mobile_file_count++;
                    }
                    if ($file_time >= $MIN_VALID_TIMESTAMP) {
                        if ($newest_file_time === null || $file_time > $newest_file_time) {
                            $newest_file_time = $file_time;
                        }
                        if ($oldest_file_time === null || $file_time < $oldest_file_time) {
                            $oldest_file_time = $file_time;
                        }
                    }
                }
            }
        }
        $average_size = ($file_count > 0) ? ($total_size / $file_count) : 0;
        $current_time = time();
        $last_write_string = ($newest_file_time !== null) ? sprintf(__('%s ago', 'optistate'), human_time_diff($newest_file_time, $current_time)) : __('N/A', 'optistate');
        $oldest_page_string = ($oldest_file_time !== null) ? sprintf(__('%s ago', 'optistate'), human_time_diff($oldest_file_time, $current_time)) : __('N/A', 'optistate');
        wp_send_json_success(['file_count' => $file_count, 'total_size' => size_format($total_size, 2), 'mobile_file_count' => $mobile_file_count, 'average_size' => size_format($average_size, 2), 'last_write' => $last_write_string, 'oldest_page' => $oldest_page_string, ]);
    }
    private function encrypt_data($data) {
        if (empty($data)) return '';
        if (!function_exists('openssl_encrypt')) return $data;
        $method = 'AES-256-CBC';
        $secret = defined('AUTH_KEY') ? AUTH_KEY : wp_salt();
        $key = hash('sha256', $secret);
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));
        $encrypted = openssl_encrypt($data, $method, $key, 0, $iv);
        return 'enc:' . base64_encode($iv . $encrypted);
    }
    private function decrypt_data($data) {
        if (empty($data)) return '';
        if (strpos($data, 'enc:') !== 0) return $data;
        if (!function_exists('openssl_decrypt')) return $data;
        $method = 'AES-256-CBC';
        $secret = defined('AUTH_KEY') ? AUTH_KEY : wp_salt();
        $key = hash('sha256', $secret);
        $payload = base64_decode(substr($data, 4));
        $iv_length = openssl_cipher_iv_length($method);
        if (strlen($payload) < $iv_length) return '';
        $iv = substr($payload, 0, $iv_length);
        $ciphertext = substr($payload, $iv_length);
        return openssl_decrypt($ciphertext, $method, $key, 0, $iv);
    }
    public function run_pagespeed_worker($task_id) {
        $task = $this->process_store->get($task_id);
        if (!$task || $task['status'] !== 'pending') {
            return;
        }
        @set_time_limit(120);
        $test_url = $task['url'];
        $strategy = $task['strategy'];
        $settings = $this->get_persistent_settings();
        $api_key = isset($settings['pagespeed_api_key']) ? trim($settings['pagespeed_api_key']) : '';
        $endpoint = "https://www.googleapis.com/pagespeedonline/v5/runPagespeed";
        $args = ['url' => $test_url, 'strategy' => $strategy, 'category' => ['performance']];
        if (!empty($api_key)) {
            $args['key'] = $api_key;
        }
        $endpoint = add_query_arg($args, $endpoint);
        $response = wp_remote_get($endpoint, ['timeout' => 60, 'headers' => ['Accept' => 'application/json']]);
        if (is_wp_error($response)) {
            $task['status'] = 'error';
            $task['message'] = __('API Connection Failed: ', 'optistate') . $response->get_error_message();
            $this->process_store->set($task_id, $task, 600);
            return;
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if ($code !== 200) {
            $error_msg = isset($data['error']['message']) ? $data['error']['message'] : __('Unknown API Error', 'optistate');
            $task['status'] = 'error';
            $task['message'] = __('PageSpeed Error: ', 'optistate') . $error_msg;
            $this->process_store->set($task_id, $task, 600);
            return;
        }
        $lighthouse = $data['lighthouseResult']??[];
        $audits = $lighthouse['audits']??[];
        $perf_settings = $this->_performance_get_settings();
        $features_status = ['server_caching' => !empty($perf_settings['server_caching']['enabled']), 'browser_caching' => !empty($perf_settings['browser_caching']), 'lazy_load' => !empty($perf_settings['lazy_load']), 'db_query_caching' => !empty($perf_settings['db_query_caching']['enabled']) ];
        $recommendations = [];
        if (isset($audits['server-response-time'])) {
            $srt_audit = $audits['server-response-time'];
            if (isset($srt_audit['numericValue']) && $srt_audit['numericValue'] > 600) {
                if (!$features_status['server_caching']) {
                    $recommendations[] = ['priority' => 'high', 'icon' => 'dashicons-superhero', 'title' => __('Enable Server-Side Page Caching', 'optistate'), 'description' => sprintf(__('Server response time (TTFB) is %s. Enable Server-Side Page Caching to serve pre-rendered HTML instantly, eliminating PHP processing and database queries on each request.', 'optistate'), isset($srt_audit['displayValue']) ? str_replace('Root document took ', '', $srt_audit['displayValue']) : 'slow'), 'tab' => '#tab-performance', 'feature' => 'server_caching'];
                } else if (!$features_status['db_query_caching']) {
                    $recommendations[] = ['priority' => 'medium', 'icon' => 'dashicons-database', 'title' => __('Enable Database Query Caching', 'optistate'), 'description' => sprintf(__('TTFB is %s. You have page caching enabled, but database queries may still be slowing down cache generation. Enable DB Query Caching to reduce MySQL overhead.', 'optistate'), isset($srt_audit['displayValue']) ? str_replace('Root document took ', '', $srt_audit['displayValue']) : 'slow'), 'tab' => '#tab-performance', 'feature' => 'db_query_caching'];
                }
            }
        }
        if (isset($audits['render-blocking-resources'])) {
            $rbl_audit = $audits['render-blocking-resources'];
            if (isset($rbl_audit['score']) && $rbl_audit['score'] < 0.9) {
                $blocking_count = isset($rbl_audit['details']['items']) ? count($rbl_audit['details']['items']) : 0;
                if (!$features_status['browser_caching']) {
                    $recommendations[] = ['priority' => 'high', 'icon' => 'dashicons-performance', 'title' => __('Enable Browser Caching', 'optistate'), 'description' => sprintf(__('Your site has %s render-blocking resources. Enable Browser Caching to leverage browser cache for CSS, JavaScript, and static assets, reducing repeat load times.', 'optistate'), $blocking_count > 0 ? number_format_i18n($blocking_count) : 'multiple'), 'tab' => '#tab-performance', 'feature' => 'browser_caching'];
                }
            }
        }
        if (isset($audits['offscreen-images']) || isset($audits['modern-image-formats'])) {
            $offscreen = $audits['offscreen-images']??[];
            $modern_formats = $audits['modern-image-formats']??[];
            if (isset($offscreen['score']) && $offscreen['score'] < 0.9) {
                if (!$features_status['lazy_load']) {
                    $offscreen_count = isset($offscreen['details']['items']) ? count($offscreen['details']['items']) : 0;
                    $recommendations[] = ['priority' => 'high', 'icon' => 'dashicons-images-alt2', 'title' => __('Enable Lazy Loading for Images', 'optistate'), 'description' => sprintf(__('Detected %s off-screen images loading immediately. Enable Lazy Loading to defer images below the fold, reducing initial page weight and improving FCP/LCP.', 'optistate'), $offscreen_count > 0 ? number_format_i18n($offscreen_count) : 'multiple'), 'tab' => '#tab-performance', 'feature' => 'lazy_load'];
                }
            }
            if (isset($modern_formats['score']) && $modern_formats['score'] < 0.9) {
                $potential_savings = isset($modern_formats['details']['overallSavingsBytes']) ? round($modern_formats['details']['overallSavingsBytes'] / 1024) : 0;
                if ($potential_savings > 50) {
                    $recommendations[] = ['priority' => 'medium', 'icon' => 'dashicons-format-image', 'title' => __('Use Modern Image Formats', 'optistate'), 'description' => sprintf(__('Serving images in WebP or AVIF format could save ~%s KB. Consider using an image optimization plugin or CDN that automatically converts images to modern formats.', 'optistate'), number_format_i18n($potential_savings)), 'tab' => null, 'feature' => null];
                }
            }
        }
        if (isset($audits['unused-javascript'])) {
            $uj_audit = $audits['unused-javascript'];
            if (isset($uj_audit['score']) && $uj_audit['score'] < 0.9) {
                $wasted_bytes = isset($uj_audit['details']['overallSavingsBytes']) ? round($uj_audit['details']['overallSavingsBytes'] / 1024) : 0;
                $recommendations[] = ['priority' => 'medium', 'icon' => 'dashicons-editor-code', 'title' => __('Reduce Unused JavaScript', 'optistate'), 'description' => sprintf(__('~%s KB of unused JavaScript detected. Remove emoji scripts and unnecessary scripts from the Performance tab. Consider code splitting or deferring non-critical scripts.', 'optistate'), $wasted_bytes > 0 ? number_format_i18n($wasted_bytes) : 'significant amount'), 'tab' => '#tab-performance', 'feature' => 'emoji_script'];
            }
        }
        $tbt_value = $audits['total-blocking-time']['numericValue']??0;
        $tti_value = $audits['interactive']['numericValue']??0;
        if ($tbt_value > 600 || $tti_value > 7300) {
            $recommendations[] = ['priority' => 'high', 'icon' => 'dashicons-database', 'title' => __('Reduce JavaScript Execution Time', 'optistate'), 'description' => sprintf(__('Total Blocking Time is %s ms (target: <200ms). This makes your site feel unresponsive. Optimize database queries via "Optimize All Tables" and "Optimize Autoloaded Options" in Advanced tab. Consider disabling heavy plugins during page load.', 'optistate'), number_format_i18n(round($tbt_value))), 'tab' => '#tab-advanced', 'feature' => 'database'];
        }
        if (isset($audits['server-response-time'])) {
            $ttfb_audit = $audits['server-response-time'];
            $ttfb_value = $ttfb_audit['numericValue']??0;
            if ($ttfb_value > 600 && $features_status['server_caching']) {
                $recommendations[] = ['priority' => 'medium', 'icon' => 'dashicons-list-view', 'title' => __('Optimize Database Indexes', 'optistate'), 'description' => sprintf(__('Server response time is %s despite caching being enabled. Missing database indexes on frequently-queried columns can slow down page generation. Run "MySQL Index Manager" in the Advanced tab to analyze and add recommended indexes.', 'optistate'), isset($ttfb_audit['displayValue']) ? str_replace('Root document took ', '', $ttfb_audit['displayValue']) : 'slow'), 'tab' => '#tab-advanced', 'feature' => 'indexes'];
            }
        }
        if (isset($audits['unused-css-rules'])) {
            $uc_audit = $audits['unused-css-rules'];
            if (isset($uc_audit['score']) && $uc_audit['score'] < 0.9) {
                $wasted_css = isset($uc_audit['details']['overallSavingsBytes']) ? round($uc_audit['details']['overallSavingsBytes'] / 1024) : 0;
                $recommendations[] = ['priority' => 'low', 'icon' => 'dashicons-admin-appearance', 'title' => __('Reduce Unused CSS', 'optistate'), 'description' => sprintf(__('~%s KB of unused CSS detected. Consider generating Critical CSS or using a plugin to inline above-the-fold styles and defer the rest.', 'optistate'), $wasted_css > 0 ? number_format_i18n($wasted_css) : 'significant amount'), 'tab' => null, 'feature' => null];
            }
        }
        if (isset($audits['uses-rel-preload'])) {
            $preload_audit = $audits['uses-rel-preload'];
            if (isset($preload_audit['score']) && $preload_audit['score'] < 0.9) {
                $potential_savings = isset($preload_audit['details']['overallSavingsMs']) ? round($preload_audit['details']['overallSavingsMs']) : 0;
                $recommendations[] = ['priority' => 'medium', 'icon' => 'dashicons-external', 'title' => __('Preload Critical Assets', 'optistate'), 'description' => sprintf(__('Key resources (fonts, hero images) are discovered late, delaying render by ~%s ms. Use <link rel="preload"> to fetch critical assets immediately, improving LCP and preventing layout shifts.', 'optistate'), $potential_savings > 0 ? number_format_i18n($potential_savings) : 'several hundred'), 'tab' => null, 'feature' => null];
            }
        }
        if (isset($audits['unminified-javascript'])) {
            $minify_audit = $audits['unminified-javascript'];
            if (isset($minify_audit['score']) && $minify_audit['score'] < 0.9) {
                $savings = isset($minify_audit['details']['overallSavingsBytes']) ? round($minify_audit['details']['overallSavingsBytes'] / 1024) : 0;
                $recommendations[] = ['priority' => 'medium', 'icon' => 'dashicons-media-code', 'title' => __('Minify JavaScript', 'optistate'), 'description' => sprintf(__('Unminified JavaScript could be reduced by ~%s KB. Minification removes whitespace and comments, reducing file size and parse time. Use a minification plugin or CDN.', 'optistate'), $savings > 0 ? number_format_i18n($savings) : 'significant amount'), 'tab' => null, 'feature' => null];
            }
        }
        if (isset($audits['largest-contentful-paint-element'])) {
            $lcp_audit = $audits['largest-contentful-paint-element'];
            $lcp_value = $audits['largest-contentful-paint']['numericValue']??0;
            if ($lcp_value > 2500) {
                $lcp_element = isset($lcp_audit['details']['items'][0]['node']['nodeLabel']) ? $lcp_audit['details']['items'][0]['node']['nodeLabel'] : 'main content element';
                $recommendations[] = ['priority' => 'high', 'icon' => 'dashicons-images-alt', 'title' => __('Optimize Largest Contentful Paint', 'optistate'), 'description' => sprintf(__('LCP is %.1f s (target: <2.5s). The slowest element is: "%s". Optimize this element by using WebP format, adding proper sizing, preloading if it\'s an image, or enabling server-side caching.', 'optistate'), $lcp_value / 1000, substr($lcp_element, 0, 50)), 'tab' => '#tab-performance', 'feature' => 'server_caching'];
            }
        }
        if (isset($audits['third-party-summary'])) {
            $tp_audit = $audits['third-party-summary'];
            if (isset($tp_audit['score']) && $tp_audit['score'] < 0.9) {
                $blocking_time = isset($tp_audit['details']['summary']['blockingTime']) ? round($tp_audit['details']['summary']['blockingTime']) : 0;
                $recommendations[] = ['priority' => 'low', 'icon' => 'dashicons-cloud', 'title' => __('Reduce Third-Party Impact', 'optistate'), 'description' => sprintf(__('Third-party scripts blocked the main thread for %s ms. Audit analytics, ads, and social widgets. Consider self-hosting critical scripts or using facades (click-to-load) for non-essential embeds.', 'optistate'), $blocking_time > 0 ? number_format_i18n($blocking_time) : 'significant time'), 'tab' => null, 'feature' => null];
            }
        }
        if (isset($audits['font-display'])) {
            $font_audit = $audits['font-display'];
            if (isset($font_audit['score']) && $font_audit['score'] < 1) {
                $recommendations[] = ['priority' => 'medium', 'icon' => 'dashicons-editor-textcolor', 'title' => __('Optimize Web Font Loading', 'optistate'), 'description' => __('Web fonts are blocking text rendering. Add font-display: swap to your @font-face rules to show text immediately with system fonts, then swap to custom fonts when loaded. This prevents invisible text (FOIT).', 'optistate'), 'tab' => null, 'feature' => null];
            }
        }
        if (isset($audits['dom-size'])) {
            $dom_audit = $audits['dom-size'];
            if (isset($dom_audit['score']) && $dom_audit['score'] < 0.9) {
                $dom_elements = isset($dom_audit['numericValue']) ? round($dom_audit['numericValue']) : 0;
                if ($dom_elements > 1500) {
                    $recommendations[] = ['priority' => 'low', 'icon' => 'dashicons-networking', 'title' => __('Reduce DOM Size', 'optistate'), 'description' => sprintf(__('Page contains %s DOM elements (recommended: <1,500). Large DOMs increase memory usage, slow down style calculations, and hurt layout performance. Simplify page structure, remove unused elements, or implement pagination.', 'optistate'), number_format_i18n($dom_elements)), 'tab' => null, 'feature' => null];
                }
            }
        }
        usort($recommendations, function ($a, $b) {
            $priority_order = ['high' => 0, 'medium' => 1, 'low' => 2];
            return $priority_order[$a['priority']]<=>$priority_order[$b['priority']];
        });
        $ttfb_display = $audits['server-response-time']['displayValue']??'N/A';
        if ($ttfb_display !== 'N/A') {
            $ttfb_display = str_replace('Root document took ', '', $ttfb_display);
        }
        $results = ['score' => isset($lighthouse['categories']['performance']['score']) ? round($lighthouse['categories']['performance']['score'] * 100) : 0, 'fcp' => ['display' => $audits['first-contentful-paint']['displayValue']??'N/A', 'value' => $audits['first-contentful-paint']['numericValue']??0], 'lcp' => ['display' => $audits['largest-contentful-paint']['displayValue']??'N/A', 'value' => $audits['largest-contentful-paint']['numericValue']??0], 'cls' => ['display' => $audits['cumulative-layout-shift']['displayValue']??'N/A', 'value' => $audits['cumulative-layout-shift']['numericValue']??0], 'tbt' => ['display' => $audits['total-blocking-time']['displayValue']??'N/A', 'value' => $audits['total-blocking-time']['numericValue']??0], 'si' => ['display' => $audits['speed-index']['displayValue']??'N/A', 'value' => $audits['speed-index']['numericValue']??0], 'tti' => ['display' => $audits['interactive']['displayValue']??'N/A', 'value' => $audits['interactive']['numericValue']??0], 'ttfb' => ['display' => $ttfb_display, 'value' => $audits['server-response-time']['numericValue']??0], 'timestamp' => current_time(get_option('date_format') . ' ' . get_option('time_format')), 'strategy' => ucfirst($strategy), 'tested_url' => $test_url, 'recommendations' => array_slice($recommendations, 0, 5) ];
        update_option('optistate_pagespeed_last_state', ['url' => $test_url, 'strategy' => $strategy, 'timestamp' => time() ], false);
        $cache_key = 'optistate_pagespeed_' . md5($test_url . $strategy);
        set_transient($cache_key, $results, 30 * DAY_IN_SECONDS);
        $task['status'] = 'done';
        $task['results'] = $results;
        $this->process_store->set($task_id, $task, 600);
        $log_message = sprintf('🚦 ' . __('Performance Audit: %d%% (%s) - %s', 'optistate'), $results['score'], $results['strategy'], parse_url($test_url, PHP_URL_PATH) ? : '/');
        $this->log_optimization("manual", $log_message, "");
    }
    public function ajax_run_pagespeed_audit() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->check_user_access();
        $force_refresh = isset($_POST['force_refresh']) && $_POST['force_refresh'] === 'true';
        $strategy = isset($_POST['strategy']) && $_POST['strategy'] === 'desktop' ? 'desktop' : 'mobile';
        $test_url = isset($_POST['test_url']) ? trim(wp_unslash($_POST['test_url'])) : '';
        if (empty($test_url) && !$force_refresh) {
            $last_state = get_option('optistate_pagespeed_last_state');
            if (is_array($last_state) && !empty($last_state['url'])) {
                $test_url = $last_state['url'];
                $strategy = isset($last_state['strategy']) ? $last_state['strategy'] : $strategy;
            }
        }
        if (empty($test_url)) {
            $test_url = home_url();
        }
        $cache_key_static = 'optistate_pagespeed_' . md5($test_url . $strategy);
        $cached_data = get_transient($cache_key_static);
        if ($cached_data !== false && !$force_refresh) {
            wp_send_json_success($cached_data);
            return;
        }
        if (!$this->check_rate_limit("run_pagespeed", 10)) {
            wp_send_json_error(['message' => __('Please wait 10 seconds before running another audit.', 'optistate') ], 429);
            return;
        }
        $test_url_parsed = parse_url($test_url);
        $home_url_parsed = parse_url(home_url());
        if (!isset($test_url_parsed['host']) || !isset($home_url_parsed['host'])) {
            wp_send_json_error(['message' => __('Invalid URL format.', 'optistate') ]);
            return;
        }
        $test_host = $test_url_parsed['host'];
        $home_host = $home_url_parsed['host'];
        $is_valid_domain = ($test_host === $home_host) || (substr($test_host, -strlen('.' . $home_host)) === '.' . $home_host);
        if (!$is_valid_domain) {
            wp_send_json_error(['message' => __('Security Restriction: You can only test URLs belonging to this domain or its subdomains.', 'optistate') ]);
            return;
        }
        try {
            $task_id = 'psi_' . bin2hex(random_bytes(8));
            $task_data = ['status' => 'pending', 'url' => $test_url, 'strategy' => $strategy, 'started' => time(), 'user_id' => get_current_user_id() ];
            $this->process_store->set($task_id, $task_data, 600);
            wp_schedule_single_event(time(), 'optistate_run_pagespeed_worker', [$task_id]);
            wp_send_json_success(['status' => 'processing', 'task_id' => $task_id, 'message' => __('Audit started in background...', 'optistate') ]);
        }
        catch(Exception $e) {
            wp_send_json_error(['message' => __('Failed to start background task.', 'optistate') ]);
        }
    }
    public function ajax_save_pagespeed_settings() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->check_user_access();
        if (!$this->check_rate_limit("save_pagespeed", 3)) {
            wp_send_json_error(['message' => __('Please wait before saving again.', 'optistate') ], 429);
        }
        $api_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
        $api_key = trim($api_key);
        $length = strlen($api_key);
        if (!empty($api_key) && ($length < 30 || $length > 80)) {
            wp_send_json_error(['message' => __('API Key must be between 30 and 80 characters.', 'optistate') ]);
        }
        $this->save_persistent_settings(['pagespeed_api_key' => $api_key]);
        wp_send_json_success(['message' => __('API Key saved successfully.', 'optistate') ]);
    }
    public function ajax_check_pagespeed_status() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->check_user_access();
        $task_id = isset($_POST['task_id']) ? sanitize_text_field(wp_unslash($_POST['task_id'])) : '';
        if (empty($task_id)) {
            wp_send_json_error(['message' => __('Invalid Task ID.', 'optistate') ]);
            return;
        }
        $task = $this->process_store->get($task_id);
        if (!$task) {
            wp_send_json_error(['message' => __('Audit session expired. Please try again.', 'optistate') ]);
            return;
        }
        if ($task['status'] === 'done') {
            wp_send_json_success(['status' => 'done', 'data' => $task['results']]);
        } elseif ($task['status'] === 'error') {
            wp_send_json_error(['message' => $task['message']]);
        } else {
            wp_send_json_success(['status' => 'processing']);
        }
    }
    public function ajax_clean_item() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->check_user_access();
        $item_type = isset($_POST["item_type"]) ? sanitize_key(wp_unslash($_POST["item_type"])) : '';
        $cleaned = 0;
        global $wpdb;
        switch ($item_type) {
            case 'post_revisions':
                $cleaned = $this->clean_post_revisions();
            break;
            case 'auto_drafts':
                $cleaned = $this->clean_auto_drafts();
            break;
            case 'trashed_posts':
                $cleaned = $this->clean_trashed_posts();
            break;
            case 'spam_comments':
                $cleaned = $this->clean_spam_comments();
            break;
            case 'trashed_comments':
                $cleaned = $this->clean_trashed_comments();
            break;
            case 'unapproved_comments':
                $cleaned = $this->clean_unapproved_comments();
            break;
            case 'pingbacks':
                $cleaned = $this->clean_pingbacks();
            break;
            case 'trackbacks':
                $cleaned = $this->clean_trackbacks();
            break;
            case 'expired_transients':
                $cleaned = $this->clean_expired_transients();
            break;
            case 'all_transients':
                $cleaned = $this->clean_all_transients();
            break;
            case 'orphaned_postmeta':
                $cleaned = $this->clean_orphaned_postmeta();
            break;
            case 'orphaned_commentmeta':
                $cleaned = $this->clean_orphaned_commentmeta();
            break;
            case 'orphaned_relationships':
                $cleaned = $this->clean_orphaned_relationships();
            break;
            case 'orphaned_usermeta':
                $cleaned = $this->clean_orphaned_usermeta();
            break;
            case 'duplicate_postmeta':
                $cleaned = $this->clean_duplicate_postmeta();
            break;
            case 'duplicate_commentmeta':
                $cleaned = $this->clean_duplicate_commentmeta();
            break;
            default:
                wp_send_json_error(__("Invalid cleanup type (or PRO VERSION ONLY)", "optistate"));
                return;
        }
        if ($cleaned > 0) {
            $this->log_optimization("manual", "🧹 " . sprintf(__("Cleaned %s (%s)", "optistate"), str_replace('_', ' ', $item_type), number_format_i18n($cleaned)), "");
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
        }
        delete_transient("optistate_stats_cache");
        delete_transient(self::STATS_TRANSIENT);
        delete_transient('optistate_db_size_cache');
        wp_send_json_success($cleaned);
    }
    private function calculate_health_score($stats) {
        $score_data = ['overall_score' => 100, 'category_scores' => [], 'issues' => [], 'recommendations' => [], 'last_calculated' => current_time('Y-m-d H:i:s') ];
        $performance_score = $this->calculate_performance_score($stats);
        $cleanliness_score = $this->calculate_cleanliness_score($stats);
        $efficiency_score = $this->calculate_efficiency_score($stats);
        $raw_overall = ($performance_score * 0.40) + ($cleanliness_score * 0.35) + ($efficiency_score * 0.25);
        $sensitivity_factor = 1.3;
        $penalty = 100 - $raw_overall;
        $adjusted_score = 100 - ($penalty * $sensitivity_factor);
        $score_data['overall_score'] = (int)max(0, min(100, $adjusted_score));
        $score_data['category_scores']['performance'] = (int)max(0, min(100, 100 - ((100 - $performance_score) * 1.3)));
        $score_data['category_scores']['cleanliness'] = (int)max(0, min(100, 100 - ((100 - $cleanliness_score) * 1.3)));
        $score_data['category_scores']['efficiency'] = (int)max(0, min(100, 100 - ((100 - $efficiency_score) * 1.3)));
        $score_data['issues'] = $this->identify_issues($stats, $score_data);
        $detailed_recommendations = [];
        if (empty($score_data['issues'])) {
            $detailed_recommendations[] = ['priority' => 'success', 'message' => __('Excellent! Your database is fully optimized and structurally healthy.', 'optistate') ];
        } else {
            usort($score_data['issues'], function ($a, $b) {
                $priorities = ['high' => 3, 'medium' => 2, 'low' => 1];
                return $priorities[$b['severity']]<=>$priorities[$a['severity']];
            });
            foreach ($score_data['issues'] as $issue) {
                $priority = $issue['severity'];
                if ($priority === 'low') {
                    $priority = 'info';
                }
                $detailed_recommendations[] = ['priority' => $priority, 'message' => $issue['message']];
            }
        }
        $score_data['recommendations'] = $detailed_recommendations;
        return $score_data;
    }
    private function calculate_performance_score($stats) {
        $score = 100;
        if (!is_array($stats) || empty($stats)) {
            return 70;
        }
        $autoload_bytes = isset($stats['autoload_size_bytes']) ? (int)$stats['autoload_size_bytes'] : 0;
        if ($autoload_bytes > 2.5 * 1024 * 1024) {
            $score-= 35;
        } elseif ($autoload_bytes > 1.5 * 1024 * 1024) {
            $score-= 25;
        } elseif ($autoload_bytes > 800 * 1024) {
            $score-= 12;
        } elseif ($autoload_bytes > 500 * 1024) {
            $score-= 5;
        }
        $table_overhead = isset($stats['table_overhead_bytes']) ? (float)$stats['table_overhead_bytes'] : 0;
        $total_size = isset($stats['total_db_size_bytes']) ? (float)$stats['total_db_size_bytes'] : 1;
        $overhead_ratio = ($total_size > 0) ? ($table_overhead / $total_size) * 100 : 0;
        if ($overhead_ratio > 12) {
            $score-= 18;
        } elseif ($overhead_ratio > 8) {
            $score-= 12;
        } elseif ($overhead_ratio > 4) {
            $score-= 5;
        }
        $expired_transients = isset($stats['expired_transients']) ? (int)$stats['expired_transients'] : 0;
        if ($expired_transients > 1000) {
            $score-= 14;
        } elseif ($expired_transients > 500) {
            $score-= 8;
        } elseif ($expired_transients > 100) {
            $score-= 5;
        }
        return max(0, min(100, $score));
    }
    private function calculate_cleanliness_score($stats) {
        $score = 100;
        if (!is_array($stats)) {
            return 80;
        }
        $junk_count = 0;
        $junk_keys = ['post_revisions', 'auto_drafts', 'trashed_posts', 'spam_comments', 'trashed_comments', 'pingbacks', 'trackbacks'];
        foreach ($junk_keys as $key) {
            $junk_count+= isset($stats[$key]) ? (int)$stats[$key] : 0;
        }
        if ($junk_count > 5000) {
            $score-= 30;
        } elseif ($junk_count > 1000) {
            $score-= 20;
        } elseif ($junk_count > 500) {
            $score-= 12;
        } elseif ($junk_count > 100) {
            $score-= 5;
        }
        $orphan_keys = ['orphaned_postmeta', 'orphaned_commentmeta', 'orphaned_relationships', 'orphaned_usermeta', 'duplicate_postmeta', 'duplicate_commentmeta'];
        $orphan_count = 0;
        foreach ($orphan_keys as $key) {
            $orphan_count+= isset($stats[$key]) ? (int)$stats[$key] : 0;
        }
        if ($orphan_count > 1000) {
            $score-= 25;
        } elseif ($orphan_count > 100) {
            $score-= 12;
        } elseif ($orphan_count > 0) {
            $score-= 5;
        }
        $woo_bloat = isset($stats['woo_bloat']) ? (int)$stats['woo_bloat'] : 0;
        $as_bloat = isset($stats['action_scheduler']) ? (int)$stats['action_scheduler'] : 0;
        $app_bloat = $woo_bloat + $as_bloat;
        if ($app_bloat > 10000) {
            $score-= 18;
        } elseif ($app_bloat > 2000) {
            $score-= 10;
        } elseif ($app_bloat > 500) {
            $score-= 3;
        }
        return max(0, min(100, $score));
    }
    private function calculate_efficiency_score($stats) {
        $score = 100;
        if (!is_array($stats)) {
            return 85;
        }
        $total_size = isset($stats['total_db_size_bytes']) ? (float)$stats['total_db_size_bytes'] : 0;
        $index_size = isset($stats['total_indexes_size_bytes']) ? (float)$stats['total_indexes_size_bytes'] : 0;
        $overhead = isset($stats['raw_table_overhead_bytes']) ? (float)$stats['raw_table_overhead_bytes'] : 0;
        $data_size = max(1, $total_size - $index_size - $overhead);
        $index_ratio = ($index_size / $data_size) * 100;
        if ($index_ratio < 1) {
            $score-= 20;
        } elseif ($index_ratio < 2) {
            $score-= 12;
        }
        if ($index_ratio > 300) {
            $score-= 20;
        } elseif ($index_ratio > 200) {
            $score-= 15;
        } elseif ($index_ratio > 150) {
            $score-= 10;
        } elseif ($index_ratio > 120) {
            $score-= 5;
        }
        $table_count = isset($stats['total_tables_count']) ? (int)$stats['total_tables_count'] : 0;
        if ($table_count > 250) {
            $score-= 16;
        } elseif ($table_count > 150) {
            $score-= 10;
        } elseif ($table_count > 90) {
            $score-= 5;
        }
        $empty_terms = isset($stats['empty_taxonomies']) ? (int)$stats['empty_taxonomies'] : 0;
        if ($empty_terms > 250) {
            $score-= 10;
        } elseif ($empty_terms > 100) {
            $score-= 5;
        }
        return max(0, min(100, $score));
    }
    private function identify_issues($stats, $score_data) {
        $issues = [];
        $autoload_bytes = isset($stats['autoload_size_bytes']) ? (int)$stats['autoload_size_bytes'] : 0;
        $autoload_mb = round($autoload_bytes / 1024 / 1024, 2);
        if ($autoload_bytes > 1.5 * 1024 * 1024) {
            $issues[] = ['type' => 'performance', 'severity' => 'high', 'message' => sprintf(__('Critical: Autoloaded options size is %s MB. This data loads on every page request, significantly slowing down TTFB. Run "Advanced ⮕ Optimize Autoloaded Options".', 'optistate'), $autoload_mb), 'action' => 'optimize_autoload'];
        } elseif ($autoload_bytes > 800 * 1024) {
            $issues[] = ['type' => 'performance', 'severity' => 'medium', 'message' => sprintf(__('Warning: Autoloaded options size is high (%s MB). Reduce this to under 800KB for optimal performance. Run "Advanced ⮕ Optimize Autoloaded Options".', 'optistate'), $autoload_mb), 'action' => 'optimize_autoload'];
        }
        $eff_overhead = isset($stats['table_overhead_bytes']) ? (float)$stats['table_overhead_bytes'] : 0;
        if ($eff_overhead > 1.7 * 1024 * 1024) {
            $issues[] = ['type' => 'performance', 'severity' => 'medium', 'message' => sprintf(__('Database tables are fragmented (approx. %s recoverable space). Run "Advanced ⮕ Optimize All Tables" to defragment.', 'optistate'), size_format($eff_overhead)), 'action' => 'optimize_tables'];
        }
        $revisions = isset($stats['post_revisions']) ? (int)$stats['post_revisions'] : 0;
        if ($revisions > 500) {
            $issues[] = ['type' => 'cleanliness', 'severity' => 'medium', 'message' => sprintf(__('Found %s post revisions. Limiting revisions in "Performance Features" is recommended to prevent future bloat.', 'optistate'), number_format_i18n($revisions)), 'action' => 'clean_post_revisions'];
        }
        $orphans = (int)($stats['orphaned_postmeta']??0) + (int)($stats['orphaned_commentmeta']??0) + (int)($stats['orphaned_relationships']??0);
        if ($orphans > 0) {
            $issues[] = ['type' => 'cleanliness', 'severity' => ($orphans > 1000 ? 'high' : 'medium'), 'message' => sprintf(__('%s items of orphaned metadata detected. These are leftovers from deleted content and should be removed.', 'optistate'), number_format_i18n($orphans)), 'action' => 'one_click_optimize'];
        }
        $garbage = (int)($stats['spam_comments']??0) + (int)($stats['trashed_comments']??0) + (int)($stats['trashed_posts']??0);
        if ($garbage > 500) {
            $issues[] = ['type' => 'cleanliness', 'severity' => 'low', 'message' => sprintf(__('Trash and Spam folders contain %s items. Emptying them will free up space.', 'optistate'), number_format_i18n($garbage)), 'action' => 'one_click_optimize'];
        }
        $tables = isset($stats['total_tables_count']) ? (int)$stats['total_tables_count'] : 0;
        if ($tables > 200) {
            $issues[] = ['type' => 'efficiency', 'severity' => 'medium', 'message' => sprintf(__('High table count detected (%s tables). Check for tables left behind by uninstalled plugins using the "Advanced ⮕ Database Structure Analysis" tool.', 'optistate'), number_format_i18n($tables)), 'action' => 'tab_advanced'];
        } elseif ($tables > 90) {
            $issues[] = ['type' => 'efficiency', 'severity' => 'low', 'message' => sprintf(__('Above average table count detected (%s tables). Check for tables left behind by uninstalled plugins using the "Advanced ⮕ Database Structure Analysis" tool.', 'optistate'), number_format_i18n($tables)), 'action' => 'tab_advanced'];
        }
        $as_completed = isset($stats['action_scheduler']) ? (int)$stats['action_scheduler'] : 0;
        if ($as_completed > 5000) {
            $issues[] = ['type' => 'efficiency', 'severity' => 'low', 'message' => sprintf(__('Action Scheduler logs are accumulating (%s items). These can be safely purged to reduce table size.', 'optistate'), number_format_i18n($as_completed)), 'action' => 'clean_action_scheduler'];
        }
        return $issues;
    }
    public function ajax_get_health_score() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, 'nonce');
        $this->check_user_access();
        $force_refresh = isset($_POST["force_refresh"]) && sanitize_text_field(wp_unslash($_POST["force_refresh"])) === "true";
        $cache_key = 'optistate_health_score';
        if (!$force_refresh) {
            $cached_score = get_transient($cache_key);
            if ($cached_score !== false && is_array($cached_score)) {
                wp_send_json_success($cached_score);
                return;
            }
        } else {
            if (!$this->check_rate_limit("refresh_stats", 6)) {
                wp_send_json_error(['message' => __('🕔 Please wait a few seconds...', 'optistate') ], 429);
                return;
            }
            delete_transient($cache_key);
        }
        try {
            $stats = $this->get_combined_database_statistics($force_refresh);
            $health_score = $this->calculate_health_score($stats);
            set_transient($cache_key, $health_score, self::STATS_CACHE_DURATION);
            wp_send_json_success($health_score);
        }
        catch(Exception $e) {
            wp_send_json_error(['message' => esc_html__('Failed to calculate health score', 'optistate'), 'error' => esc_html($e->getMessage()) ]);
        }
    }
    public function ajax_one_click_optimize() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->check_user_access();
        if (!$this->check_rate_limit("one_click", 30)) {
            wp_send_json_error(['message' => __('🕔 Please wait 30 seconds before running a full optimization again.', 'optistate') ], 429);
            return;
        }
        $cleaned = $this->perform_optimizations(true);
        $total_cleaned = 0;
        if (is_array($cleaned)) {
            $total_cleaned = array_sum($cleaned);
        }
        $this->log_optimization("manual", "🧹 " . sprintf(__("One-Click Optimization Completed (%s items cleaned)", "optistate"), number_format_i18n($total_cleaned)));
        delete_transient(self::STATS_TRANSIENT);
        delete_transient('optistate_health_score');
        delete_transient('optistate_db_size_cache');
        $new_stats = $this->get_combined_database_statistics(true);
        $health_score = $this->calculate_health_score($new_stats);
        $cleaned['health_score'] = $health_score;
        wp_send_json_success($cleaned);
    }
    public function ajax_get_optimization_log() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $log = $this->get_optimization_log();
        wp_send_json_success($log);
    }
    public function ajax_save_max_backups() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        if (!$this->check_rate_limit("save_max_backups", 3)) {
            wp_send_json_error(['message' => __('🕔 Please wait a few seconds before saving again.', 'optistate') ], 429);
            return;
        }
        $current_settings = $this->get_persistent_settings();
        $old_max_backups = (int)$current_settings['max_backups'];
        $max_backups = isset($_POST["max_backups"]) ? absint($_POST["max_backups"]) : 1;
        $max_backups = max(1, min(1, $max_backups));
        $this->save_persistent_settings(["max_backups" => $max_backups]);
        if ($old_max_backups !== $max_backups) {
            $this->log_optimization("manual", '⚙️ ' . __("Maximum Backups to Keep Updated", "optistate"), "");
        }
        wp_send_json_success(["message" => __("Maximum backups setting saved successfully!", "optistate"), ]);
    }
    private function perform_optimizations($return_data = false) {
        global $wpdb;
        $is_automated = (wp_doing_cron() && (doing_action('optistate_scheduled_cleanup') || doing_action('optistate_async_backup_complete')));
        $is_cli = (defined('WP_CLI') && WP_CLI);
        $is_admin_request = current_user_can('manage_options');
        if (!$is_automated && !$is_cli && !$is_admin_request) {
            return $return_data ? [] : null;
        }
        $cleaned = [];
        $use_cli = (defined('WP_CLI') && WP_CLI) && php_sapi_name() === 'cli';
        if ($use_cli) {
            try {
                $wpdb->query("SET SESSION sql_mode = ''");
                $wpdb->query("SET SESSION foreign_key_checks = 0");
                $wpdb->query("SET SESSION unique_checks = 0");
                $wpdb->query("SET SESSION autocommit = 0");
                $wpdb->query("START TRANSACTION");
                $cleaned["post_revisions"] = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'");
                $cleaned["auto_drafts"] = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft'");
                $cleaned["trashed_comments"] = $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash'");
                $cleaned["expired_transients"] = $wpdb->query("DELETE FROM {$wpdb->options} 
                 WHERE option_name LIKE '_transient_timeout_%' 
                 AND option_value < UNIX_TIMESTAMP()");
                $cleaned["pingbacks"] = $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_type = 'pingback'");
                $cleaned["trackbacks"] = $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_type = 'trackback'");
                $cleaned["orphaned_postmeta"] = $wpdb->query("DELETE pm FROM {$wpdb->postmeta} pm
                 LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 WHERE p.ID IS NULL");
                $cleaned["orphaned_commentmeta"] = $wpdb->query("DELETE cm FROM {$wpdb->commentmeta} cm
                 LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID
                 WHERE c.comment_ID IS NULL");
                $cleaned["orphaned_relationships"] = $wpdb->query("DELETE tr FROM {$wpdb->term_relationships} tr
                 LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID
                 WHERE p.ID IS NULL");
                $cleaned["orphaned_usermeta"] = $wpdb->query("DELETE um FROM {$wpdb->usermeta} um
                 LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID
                 WHERE u.ID IS NULL");
                $cleaned["duplicate_postmeta"] = $wpdb->query("DELETE a FROM {$wpdb->postmeta} a
                 INNER JOIN (
                     SELECT post_id, meta_key, meta_value, MIN(meta_id) as min_id
                     FROM {$wpdb->postmeta}
                     GROUP BY post_id, meta_key, meta_value
                     HAVING COUNT(*) > 1
                 ) b ON a.post_id = b.post_id 
                     AND a.meta_key = b.meta_key 
                     AND a.meta_value = b.meta_value 
                     AND a.meta_id > b.min_id");
                $cleaned["duplicate_commentmeta"] = $wpdb->query("DELETE a FROM {$wpdb->commentmeta} a
                 INNER JOIN (
                     SELECT comment_id, meta_key, meta_value, MIN(meta_id) as min_id
                     FROM {$wpdb->commentmeta}
                     GROUP BY comment_id, meta_key, meta_value
                     HAVING COUNT(*) > 1
                 ) b ON a.comment_id = b.comment_id 
                     AND a.meta_key = b.meta_key 
                     AND a.meta_value = b.meta_value 
                     AND a.meta_id > b.min_id");
                $wpdb->query("COMMIT");
                $wpdb->query("SET SESSION foreign_key_checks = 1");
                $wpdb->query("SET SESSION unique_checks = 1");
                $wpdb->query("SET SESSION autocommit = 1");
                $wpdb->query("SET SESSION sql_mode = @@global.sql_mode");
                foreach ($cleaned as $key => $value) {
                    if ($value === false) {
                        $cleaned[$key] = 0;
                    }
                }
                if ($return_data) return $cleaned;
                return;
            }
            catch(Exception $e) {
                $wpdb->query("ROLLBACK");
                $wpdb->query("SET SESSION foreign_key_checks = 1");
                $wpdb->query("SET SESSION unique_checks = 1");
                $wpdb->query("SET SESSION autocommit = 1");
                if (method_exists($this, 'log_optimization')) {
                    $this->log_optimization("error", "❌ " . __("WP-CLI Optimization Failed", "optistate"), $e->getMessage());
                }
            }
        }
        $cleaned["post_revisions"] = $this->clean_post_revisions();
        $cleaned["auto_drafts"] = $this->clean_auto_drafts();
        $cleaned["trashed_comments"] = $this->clean_trashed_comments();
        $cleaned["orphaned_postmeta"] = $this->clean_orphaned_postmeta();
        $cleaned["orphaned_commentmeta"] = $this->clean_orphaned_commentmeta();
        $cleaned["orphaned_relationships"] = $this->clean_orphaned_relationships();
        $cleaned["expired_transients"] = $this->clean_expired_transients();
        $cleaned["duplicate_postmeta"] = $this->clean_duplicate_postmeta();
        $cleaned["duplicate_commentmeta"] = $this->clean_duplicate_commentmeta();
        $cleaned["orphaned_usermeta"] = $this->clean_orphaned_usermeta();
        $cleaned["pingbacks"] = $this->clean_pingbacks();
        $cleaned["trackbacks"] = $this->clean_trackbacks();
        if ($return_data) return $cleaned;
    }
    public function ajax_optimize_tables() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->check_user_access();
        if (!$this->check_rate_limit("heavy_op", 3)) {
            wp_send_json_error(['message' => __('🕔 Please wait a few seconds before running this operation again.', 'optistate') ], 429);
            return;
        }
        $result = $this->perform_optimize_tables(true);
        delete_transient("optistate_stats_cache");
        delete_transient('optistate_health_score');
        delete_transient('optistate_db_size_cache');
        $count = isset($result['optimized']) ? (int)$result['optimized'] : 0;
        $this->log_optimization("manual", sprintf('⚡ ' . __("Optimized All Tables (%s)", "optistate"), number_format_i18n($count)), "");
        delete_transient(self::STATS_TRANSIENT);
        wp_send_json_success($result);
    }
    private function perform_optimize_tables($return_data = false) {
        global $wpdb;
        $user_id = get_current_user_id();
        $transient_key = 'optistate_optimize_tables_state_' . $user_id;
        $is_ajax = (defined('DOING_AJAX') && DOING_AJAX);
        $max_execution_time = $is_ajax ? 4 : 600;
        $start_time = microtime(true);
        $original_time_limit = ini_get('max_execution_time');
        @set_time_limit(600);
        try {
            $use_cli = (defined('WP_CLI') && WP_CLI) && php_sapi_name() === 'cli';
            if ($use_cli) {
                try {
                    $tables_result = $wpdb->get_results("SHOW FULL TABLES WHERE Table_Type = 'BASE TABLE'", ARRAY_N);
                    $table_names = [];
                    if (!empty($tables_result)) {
                        foreach ($tables_result as $table_row) {
                            $table_names[] = '`' . esc_sql($table_row[0]) . '`';
                        }
                    }
                    if (!empty($table_names)) {
                        $table_list = implode(', ', $table_names);
                        $optimize_query = "OPTIMIZE TABLE $table_list";
                        $optimize_results = $wpdb->get_results($optimize_query, ARRAY_A);
                        $cli_results = ["optimized" => 0, "skipped" => 0, "failed" => 0, "reclaimed" => 0, "details" => []];
                        if (!empty($optimize_results)) {
                            foreach ($optimize_results as $row) {
                                $table = $row['Table']??'Unknown';
                                $msg_text = strtolower(trim($row['Msg_text']??''));
                                if ($msg_text === 'ok' || strpos($msg_text, 'up to date') !== false) {
                                    $cli_results["optimized"]++;
                                    $cli_results["details"][] = ["table" => $table, "status" => "optimized", "note" => "Optimized (WP-CLI)"];
                                } else {
                                    $cli_results["failed"]++;
                                    $cli_results["details"][] = ["table" => $table, "status" => "failed", "error" => $row['Msg_text']];
                                }
                            }
                            if ($return_data) return $cli_results;
                            return;
                        }
                    }
                }
                catch(Exception $e) {
                }
            }
            $state = get_transient($transient_key);
            if (!$state || !is_array($state)) {
                $tables = $wpdb->get_results("
                    SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, DATA_FREE
                    FROM information_schema.TABLES 
                    WHERE table_schema = DATABASE()
                    AND TABLE_TYPE = 'BASE TABLE'
                ", ARRAY_A);
                if (empty($tables)) {
                    $empty_results = ["optimized" => 0, "skipped" => 0, "failed" => 0, "reclaimed" => 0, "details" => []];
                    if ($return_data) return $empty_results;
                    return;
                }
                $state = ['tables' => $tables, 'total_count' => count($tables), 'current_index' => 0, 'results' => ["optimized" => 0, "skipped" => 0, "failed" => 0, "reclaimed" => 0, "details" => []]];
            }
            $tables = $state['tables'];
            $total_count = $state['total_count'];
            $current_index = $state['current_index'];
            $results = $state['results'];
            while ($current_index < $total_count) {
                if ($is_ajax && (microtime(true) - $start_time) > $max_execution_time) {
                    $state['current_index'] = $current_index;
                    $state['results'] = $results;
                    set_transient($transient_key, $state, 300);
                    $percentage = round(($current_index / $total_count) * 100);
                    $current_table_name = $tables[$current_index]['TABLE_NAME'];
                    return ['status' => 'running', 'percentage' => $percentage, 'message' => sprintf(__('Optimizing %s... (%d%%)', 'optistate'), $current_table_name, $percentage) ];
                }
                $table = $tables[$current_index];
                $table_name = $table["TABLE_NAME"];
                $initial_overhead = isset($table["DATA_FREE"]) ? intval($table["DATA_FREE"]) : 0;
                if ($this->should_skip_table_optimization($table)) {
                    $results["skipped"]++;
                    $results["details"][] = ["table" => $table_name, "status" => "skipped", "reason" => __("No overhead or not supported", "optistate") ];
                } else {
                    try {
                        $escaped_table_name = $this->validate_table_name($table_name);
                        if ($escaped_table_name === false) {
                            $results["failed"]++;
                            $results["details"][] = ["table" => $table_name, "status" => "failed", "error" => __("Invalid table name", "optistate") ];
                        } else {
                            $optimize_query = "OPTIMIZE TABLE $escaped_table_name";
                            $optimize_results = $wpdb->get_results($optimize_query, ARRAY_A);
                            $optimize_successful = false;
                            $optimize_error = null;
                            $is_already_optimized = false;
                            if (!empty($optimize_results)) {
                                foreach ($optimize_results as $result_row) {
                                    if (!isset($result_row['Msg_type']) || !isset($result_row['Msg_text'])) continue;
                                    $msg_type = strtolower(trim($result_row['Msg_type']));
                                    $msg_text = strtolower(trim($result_row['Msg_text']));
                                    if ($msg_type === 'status' && ($msg_text === 'ok' || strpos($msg_text, 'up to date') !== false)) {
                                        $optimize_successful = true;
                                        if (strpos($msg_text, 'up to date') !== false) $is_already_optimized = true;
                                    }
                                    if (($msg_type === 'error') || ($msg_type === 'note' && strpos($msg_text, 'not supported') !== false) || ($msg_type === 'warning')) {
                                        if (!$optimize_successful) $optimize_error = $result_row['Msg_text'];
                                    }
                                }
                            }
                            if ($optimize_successful && !$optimize_error) {
                                $reclaimed = 0;
                                if ($initial_overhead > 1024 * 1024 && !$is_already_optimized) {
                                    $optimized_stats = $wpdb->get_row($wpdb->prepare("SELECT DATA_FREE FROM information_schema.TABLES WHERE table_schema = DATABASE() AND TABLE_NAME = %s", $table_name), ARRAY_A);
                                    if ($optimized_stats && isset($optimized_stats["DATA_FREE"])) {
                                        $final_overhead = intval($optimized_stats["DATA_FREE"]);
                                        $reclaimed = max(0, $initial_overhead - $final_overhead);
                                    } else {
                                        $reclaimed = $initial_overhead;
                                    }
                                } else {
                                    $reclaimed = $is_already_optimized ? 0 : $initial_overhead;
                                }
                                $results["optimized"]++;
                                $results["reclaimed"]+= $reclaimed;
                                $detail = ["table" => $table_name, "status" => "optimized"];
                                if ($reclaimed > 0) $detail["reclaimed"] = size_format($reclaimed, 2);
                                if ($is_already_optimized) $detail["note"] = __("Already optimized", "optistate");
                                $results["details"][] = $detail;
                            } else {
                                $results["failed"]++;
                                $results["details"][] = ["table" => $table_name, "status" => "failed", "error" => $optimize_error ? : ($wpdb->last_error ? : __("Optimization failed", "optistate")) ];
                            }
                        }
                    }
                    catch(Exception $e) {
                        $results["failed"]++;
                        $results["details"][] = ["table" => $table_name, "status" => "error", "error" => $e->getMessage() ];
                    }
                }
                $current_index++;
            }
            delete_transient($transient_key);
            $results['status'] = 'done';
            if ($return_data) return $results;
        }
        finally {
            @set_time_limit($original_time_limit);
        }
    }
    private function should_skip_table_optimization($table) {
        if (!isset($table["DATA_FREE"]) || empty($table["DATA_FREE"]) || intval($table["DATA_FREE"]) < 1024) {
            return true;
        }
        if (!isset($table["TABLE_ROWS"]) || intval($table["TABLE_ROWS"]) == 0) {
            return true;
        }
        if (isset($table["ENGINE"]) && strtoupper($table["ENGINE"]) === "MEMORY") {
            return true;
        }
        if (isset($table["TABLE_TYPE"]) && $table["TABLE_TYPE"] !== "BASE TABLE") {
            return true;
        }
        return false;
    }
    private function is_active_transient_or_session($option_name) {
        if (strpos($option_name, '_transient_timeout_') === 0) {
            return true;
        }
        if (strpos($option_name, '_site_transient_timeout_') === 0) {
            return true;
        }
        if (strpos($option_name, '_transient_') === 0) {
            $transient_name = substr($option_name, strlen('_transient_'));
            return get_transient($transient_name) !== false;
        }
        if (strpos($option_name, '_site_transient_') === 0) {
            $transient_name = substr($option_name, strlen('_site_transient_'));
            return get_site_transient($transient_name) !== false;
        }
        if (strpos($option_name, '_wc_session_') === 0 || strpos($option_name, '_wp_session_') === 0) {
            return true;
        }
        return false;
    }
    private function run_batch_delete($query_template, $batch_size = 2000, $parameters = []) {
        global $wpdb;
        $use_cli = ((defined('WP_CLI') && WP_CLI) && php_sapi_name() === 'cli') || (defined('DOING_AJAX') && DOING_AJAX);
        $total_cleaned = 0;
        $has_limit = (strpos($query_template, 'LIMIT %d') !== false);
        $base_query = $query_template;
        if (!empty($parameters)) {
            $prepared_params = $has_limit ? array_merge($parameters, [$batch_size]) : $parameters;
            if (!$has_limit) {
                $base_query = $wpdb->prepare($query_template, ...$prepared_params);
            }
        }
        $start_time = time();
        $max_execution_time = ini_get('max_execution_time') ? (int)ini_get('max_execution_time') : 30;
        $time_limit = ($max_execution_time > 0) ? ($max_execution_time - 5) : 20;
        try {
            $wpdb->query("START TRANSACTION");
            while (true) {
                if (!$use_cli && (time() - $start_time) >= $time_limit) {
                    break;
                }
                if ($has_limit) {
                    $current_params = array_merge($parameters, [$batch_size]);
                    $query = $wpdb->prepare($query_template, ...$current_params);
                } else {
                    $query = $base_query;
                }
                $cleaned = $wpdb->query($query);
                if ($cleaned === false) {
                    throw new Exception($wpdb->last_error);
                }
                $total_cleaned+= $cleaned;
                $wpdb->query("COMMIT");
                $wpdb->query("START TRANSACTION");
                if ($cleaned < $batch_size || !$has_limit) {
                    break;
                }
                if (!$use_cli) {
                    usleep(20000);
                }
            }
            $wpdb->query("COMMIT");
        }
        catch(Exception $e) {
            $wpdb->query("ROLLBACK");
            return 0;
        }
        return $total_cleaned;
    }
    private function clean_post_revisions() {
        global $wpdb;
        return $this->run_batch_delete("DELETE FROM {$wpdb->posts} WHERE post_type = 'revision' LIMIT %d");
    }
    private function clean_auto_drafts() {
        global $wpdb;
        return $this->run_batch_delete("DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft' LIMIT %d");
    }
    private function clean_trashed_posts() {
        global $wpdb;
        return $this->run_batch_delete("DELETE FROM {$wpdb->posts} WHERE post_status = 'trash' LIMIT %d");
    }
    private function clean_spam_comments() {
        global $wpdb;
        return $this->run_batch_delete("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam' LIMIT %d");
    }
    private function clean_trashed_comments() {
        global $wpdb;
        return $this->run_batch_delete("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash' LIMIT %d");
    }
    private function clean_unapproved_comments() {
        global $wpdb;
        return $this->run_batch_delete("DELETE FROM {$wpdb->comments} WHERE comment_approved = '0' LIMIT %d");
    }
    private function clean_orphaned_postmeta() {
        global $wpdb;
        return $this->run_batch_delete("DELETE pm FROM {$wpdb->postmeta} pm 
         LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
         WHERE p.ID IS NULL 
         ORDER BY pm.meta_id 
         LIMIT %d");
    }
    private function clean_orphaned_commentmeta() {
        global $wpdb;
        return $this->run_batch_delete("DELETE cm FROM {$wpdb->commentmeta} cm 
         LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID 
         WHERE c.comment_ID IS NULL 
         ORDER BY cm.meta_id 
         LIMIT %d");
    }
    private function clean_orphaned_relationships() {
        global $wpdb;
        return $this->run_batch_delete("DELETE tr FROM {$wpdb->term_relationships} tr 
         LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID 
         WHERE p.ID IS NULL 
         LIMIT %d");
    }
    private function clean_expired_transients() {
        global $wpdb;
        $current_time = time();
        $query_template = "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT %d";
        $transient_pattern = $wpdb->esc_like('_transient_timeout_') . '%';
        return $this->run_batch_delete($query_template, 2000, [$transient_pattern, $current_time]);
    }
    private function clean_all_transients() {
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            return wp_cache_flush() ? 1 : 0;
        }
        global $wpdb;
        $use_cli = (defined('WP_CLI') && WP_CLI) && php_sapi_name() === 'cli';
        if ($use_cli) {
            try {
                $wpdb->query("START TRANSACTION");
                $cleaned = $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%%' OR option_name LIKE '_site_transient_%%'");
                $wpdb->query("COMMIT");
                return ($cleaned !== false) ? $cleaned : 0;
            }
            catch(Exception $e) {
                $wpdb->query("ROLLBACK");
                return 0;
            }
        }
        $total_cleaned = 0;
        $batch_size = 2000;
        while (true) {
            $ids = $wpdb->get_col($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE '_transient_%%' OR option_name LIKE '_site_transient_%%' LIMIT %d", $batch_size));
            if (empty($ids)) {
                break;
            }
            $sanitized_ids = array_map('absint', $ids);
            $placeholders = implode(',', array_fill(0, count($sanitized_ids), '%d'));
            $cleaned_in_batch = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_id IN ($placeholders)", $sanitized_ids));
            if ($cleaned_in_batch === false) {
                break;
            }
            $total_cleaned+= $cleaned_in_batch;
            if ($cleaned_in_batch < count($ids)) {
                break;
            }
            usleep(50000);
        }
        return $total_cleaned;
    }
    private function clean_duplicate_postmeta() {
        global $wpdb;
        return $this->run_batch_delete("DELETE a FROM {$wpdb->postmeta} a 
         INNER JOIN (
             SELECT post_id, meta_key, meta_value, MIN(meta_id) as min_id 
             FROM {$wpdb->postmeta} 
             GROUP BY post_id, meta_key, meta_value 
             HAVING COUNT(*) > 1
         ) b ON a.post_id = b.post_id 
            AND a.meta_key = b.meta_key 
            AND a.meta_value = b.meta_value 
            AND a.meta_id > b.min_id 
         ORDER BY a.meta_id 
         LIMIT %d");
    }
    private function clean_duplicate_commentmeta() {
        global $wpdb;
        return $this->run_batch_delete("DELETE a FROM {$wpdb->commentmeta} a 
         INNER JOIN (
             SELECT comment_id, meta_key, meta_value, MIN(meta_id) as min_id 
             FROM {$wpdb->commentmeta} 
             GROUP BY comment_id, meta_key, meta_value 
             HAVING COUNT(*) > 1
         ) b ON a.comment_id = b.comment_id 
            AND a.meta_key = b.meta_key 
            AND a.meta_value = b.meta_value 
            AND a.meta_id > b.min_id 
         ORDER BY a.meta_id 
         LIMIT %d");
    }
    private function clean_orphaned_usermeta() {
        global $wpdb;
        return $this->run_batch_delete("DELETE um FROM {$wpdb->usermeta} um 
         LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID 
         WHERE u.ID IS NULL 
         ORDER BY um.umeta_id 
         LIMIT %d");
    }
    private function clean_pingbacks() {
        global $wpdb;
        return $this->run_batch_delete("DELETE FROM {$wpdb->comments} WHERE comment_type = 'pingback' LIMIT %d");
    }
    private function clean_trackbacks() {
        global $wpdb;
        return $this->run_batch_delete("DELETE FROM {$wpdb->comments} WHERE comment_type = 'trackback' LIMIT %d");
    }
    private function _performance_init_features() {
        $has_persistent_cache = wp_using_ext_object_cache();
        $default_bots = "MJ12bot\nAhrefsBot\nSemrushBot\nDotBot\nPetalBot\nBytespider\nMauibot\nMegaIndex\nSerpstatBot\nBLEXBot\nDataForSeoBot\nAspiegelBot";
        $this->performance_feature_definitions = ['server_caching' => ['title' => __('🌐 Server-Side Page Caching', 'optistate'), 'description' => __('Drastically improves site speed by storing fully rendered pages as static HTML files. When a visitor requests a page, the lightweight cached file is served directly, bypassing slow PHP and database queries. Combine this with browser caching for ultimate performance. DO NOT ACTIVATE if you already use a caching plugin such as WP Rocket, LiteSpeed, WP Super Cache, etc.', 'optistate'), 'impact' => 'high', 'type' => 'custom_caching', 'default' => ['enabled' => false, 'lifetime' => 86400, 'query_string_mode' => 'include_safe', 'exclude_urls' => "/cart*\n/my-account*\n/checkout*\n/wp-login.php*\n/wp-admin*", 'mobile_cache' => false, 'disable_cookie_check' => false, 'custom_consent_cookie' => '', 'auto_preload' => false], 'safe' => true], 'browser_caching' => ['title' => __('💻 Browser Caching (.htaccess)', 'optistate'), 'description' => __('Enables browser caching by adding optimized caching and security rules to your .htaccess file. This improves page load times for returning visitors by storing static assets in their browser.<br>Requires Apache server with writable .htaccess file. For Nginx servers, manual configuration is required - see user manual (section 7.3.1) for details. Combine this with server-side caching for ultimate performance. DO NOT ACTIVATE if you already use a caching plugin such as WP Rocket, LiteSpeed, WP Super Cache, etc.', 'optistate'), 'impact' => 'medium', 'type' => 'toggle', 'default' => false, 'safe' => true], 'db_query_caching' => ['title' => __('🗄️ Database Query Caching', 'optistate'), 'description' => $has_persistent_cache ? __('Advanced object caching for database queries. Reduces database load by caching complex query results in Redis/Memcached. Do not activate if you use another plugin for this purpose.', 'optistate') : __('Advanced object caching for database queries. Reduces database load by caching complex query results in Redis/Memcached.<br>⚠️ Requirement Missing: A persistent object cache (Redis or Memcached) is not detected. This feature cannot be activated.', 'optistate'), 'impact' => 'high', 'type' => 'custom_db_caching', 'default' => ['enabled' => false, 'ttl_main' => 43200, 'ttl_secondary' => 86400, 'exclude_post_types' => 'shop_order,ticket,product', 'exclude_ids' => '', 'flush_on_comments' => true, 'flush_on_save' => true, ], 'safe' => true, 'disabled' => !$has_persistent_cache], 'lazy_load' => ['title' => __('⏲ Lazy Load Images & Iframes', 'optistate'), 'description' => __('Enforces native browser lazy loading by injecting loading="lazy" and decoding="async" attributes into images and iframes.<br>This improves Core Web Vitals by deferring off-screen media until needed.', 'optistate'), 'impact' => 'medium', 'type' => 'toggle', 'default' => false, 'safe' => true], 'bad_bot_blocker' => ['title' => __('🤖 Bad Bot Blocker', 'optistate'), 'description' => __('Blocks resource-intensive SEO crawlers that provide competitive intelligence to other businesses. Does NOT block legitimate search engines like Google, Bing, or regional search engines. You can customize the list to match your needs.', 'optistate'), 'impact' => 'high', 'type' => 'custom_bot_blocker', 'default' => ['enabled' => false, 'user_agents' => $default_bots], 'safe' => true], 'post_revisions' => ['title' => __('📝 Post Revisions Limit', 'optistate'), 'description' => __('WordPress saves a new copy every time you click "Save Draft". Limiting revisions prevents database bloat while keeping recent versions for safety.', 'optistate'), 'impact' => 'medium', 'options' => ['default' => __('WordPress Default (Unlimited)', 'optistate'), 'limit_3' => __('Limit to 3 Revisions', 'optistate'), 'limit_5' => __('Limit to 5 Revisions', 'optistate'), 'limit_10' => __('Limit to 10 Revisions', 'optistate'), 'disable' => __('Disable Revisions (not recommended)', 'optistate') ], 'default' => 'default', 'safe' => false], 'trash_auto_empty' => ['title' => __('🗑️ Automatic Trash Emptying', 'optistate'), 'description' => __('By default, WordPress automatically purges trashed posts and pages older than 30 days. However, you can customize this period or completely disable automatic emptying.<br>⚠ Warning: Once emptied, deleted content cannot be recovered.', 'optistate'), 'impact' => 'medium', 'options' => ['default' => __('WordPress Default (30 days)', 'optistate'), 'disable' => __('Disable Auto-Empty (Keep Forever)', 'optistate'), 'days_7' => __('7 Days', 'optistate'), 'days_14' => __('14 Days', 'optistate'), 'days_30' => __('30 Days', 'optistate'), 'days_60' => __('60 Days', 'optistate'), 'days_90' => __('90 Days', 'optistate') ], 'default' => 'default', 'safe' => false], 'xmlrpc' => ['title' => __('ᯤ XML-RPC Interface', 'optistate'), 'description' => __('Disables the XML-RPC API used by legacy mobile apps. It has been replaced by the REST API and is a frequent target for brute-force attacks.', 'optistate'), 'impact' => 'medium', 'type' => 'toggle', 'default' => false, 'safe' => false], 'heartbeat_api' => ['title' => __('၊၊||၊ Heartbeat API Control', 'optistate'), 'description' => __('Reduces or disables the WordPress Heartbeat API that creates frequent AJAX calls (every 15-60 seconds). This saves server resources but may disable real-time features like post editing locks.', 'optistate'), 'impact' => 'high', 'options' => ['default' => __('WordPress Default (Every 15-60 seconds)', 'optistate'), 'slow' => __('Slow Down (Every 2 minutes)', 'optistate'), 'disable_admin' => __('Disable in Admin Area', 'optistate'), 'disable_frontend' => __('Disable on Frontend Only', 'optistate'), 'disable_all' => __('Disable Everywhere', 'optistate') ], 'default' => 'default', 'safe' => true], 'emoji_script' => ['title' => __('😊 Emoji Scripts', 'optistate'), 'description' => __('Removes the emoji detection JavaScript (wp-emoji-release.min.js) loaded on every page. Modern browsers display emojis natively, making this script redundant.', 'optistate'), 'impact' => 'low', 'type' => 'toggle', 'default' => false, 'safe' => true], 'self_pingbacks' => ['title' => __('↩️ Self Pingbacks', 'optistate'), 'description' => __('Prevents WordPress from creating pingback notifications when you link to your own posts, reducing unnecessary database operations.', 'optistate'), 'impact' => 'low', 'type' => 'toggle', 'default' => false, 'safe' => true], 'rest_api_link' => ['title' => __('🔗 REST API Link Tag', 'optistate'), 'description' => __('Removes the REST API discovery link from page headers. The REST API will still work, but external discovery is disabled.', 'optistate'), 'impact' => 'low', 'type' => 'toggle', 'default' => false, 'safe' => true], 'shortlink' => ['title' => __('🔗 Shortlink Tag', 'optistate'), 'description' => __('Removes the shortlink meta tag from page headers. Shortlinks are rarely used and removing them saves minimal bandwidth.', 'optistate'), 'impact' => 'low', 'type' => 'toggle', 'default' => false, 'safe' => true], 'rsd_link' => ['title' => __('🔗 RSD (Really Simple Discovery) Link', 'optistate'), 'description' => __('Removes the RSD link used by external blog clients. Unless you use desktop blogging software, this can be safely removed.', 'optistate'), 'impact' => 'low', 'type' => 'toggle', 'default' => false, 'safe' => true], 'wlwmanifest' => ['title' => __('🪟 Windows Live Writer Manifest', 'optistate'), 'description' => __('Removes the Windows Live Writer manifest link. This software is discontinued and the link is no longer needed.', 'optistate'), 'impact' => 'low', 'type' => 'toggle', 'default' => false, 'safe' => true], 'wp_generator' => ['title' => __('♯ WordPress Version Meta Tag', 'optistate'), 'description' => __('Removes the WordPress version number from page headers. This improves security by hiding your WordPress version from potential attackers.', 'optistate'), 'impact' => 'low', 'type' => 'toggle', 'default' => false, 'safe' => true]];
    }
    private function _performance_get_settings() {
        if ($this->performance_settings_cache !== null) {
            return $this->performance_settings_cache;
        }
        $settings = $this->get_persistent_settings();
        if (!isset($settings['performance_features'])) {
            $settings['performance_features'] = [];
        }
        if (!isset($settings['performance_features']['server_caching']) || !is_array($settings['performance_features']['server_caching'])) {
            $settings['performance_features']['server_caching'] = ['enabled' => false, 'lifetime' => 86400, 'query_string_mode' => 'include_safe', 'exclude_urls' => '', 'mobile_cache' => false, 'disable_cookie_check' => false];
        }
        $this->performance_settings_cache = $settings['performance_features'];
        return $this->performance_settings_cache;
    }
    private function _performance_save_settings($features) {
        $validated_features = [];
        foreach ($features as $key => $value) {
            if (!isset($this->performance_feature_definitions[$key])) {
                continue;
            }
            $feature_def = $this->performance_feature_definitions[$key];
            if (isset($feature_def['type']) && $feature_def['type'] === 'custom_caching' && $key === 'server_caching') {
                if (is_array($value)) {
                    $allowed_query_modes = ['ignore_all', 'include_safe', 'unique_cache'];
                    $query_mode = $value['query_string_mode']??'include_safe';
                    if (!in_array($query_mode, $allowed_query_modes, true)) {
                        $query_mode = 'include_safe';
                    }
                    $validated_features[$key] = ['enabled' => filter_var($value['enabled']??false, FILTER_VALIDATE_BOOLEAN), 'lifetime' => min(max(absint($value['lifetime']??86400), HOUR_IN_SECONDS), 6 * MONTH_IN_SECONDS), 'query_string_mode' => $query_mode, 'exclude_urls' => sanitize_textarea_field($value['exclude_urls']??''), ];
                }
            } elseif (isset($feature_def['type']) && $feature_def['type'] === 'custom_bot_blocker') {
                if (is_array($value)) {
                    $raw_bots = isset($value['user_agents']) ? (string)$value['user_agents'] : '';
                    $bots_array = array_filter(array_map(function ($bot) {
                        return substr(trim($bot), 0, 100);
                    }, explode("\n", $raw_bots)));
                    $clean_bots_string = implode("\n", $bots_array);
                    $validated_features[$key] = ['enabled' => filter_var($value['enabled']??false, FILTER_VALIDATE_BOOLEAN), 'user_agents' => $clean_bots_string];
                } else {
                    $validated_features[$key] = $feature_def['default'];
                }
            } elseif (isset($feature_def['type']) && $feature_def['type'] === 'toggle') {
                $validated_features[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif (isset($feature_def['options']) && is_array($feature_def['options'])) {
                if (array_key_exists($value, $feature_def['options'])) {
                    $validated_features[$key] = sanitize_key($value);
                } else {
                    $validated_features[$key] = $feature_def['default'];
                }
            }
        }
        $this->performance_settings_cache = null;
        return $this->save_persistent_settings(['performance_features' => $validated_features]);
    }
    public function ajax_check_htaccess_status() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, 'nonce');
        $status = $this->_performance_check_htaccess_writable();
        if ($status['writable']) {
            wp_send_json_success(['writable' => true, 'message' => $status['message']]);
        } else {
            wp_send_json_error(['writable' => false, 'message' => $status['message'], 'exists' => $status['exists']]);
        }
    }
    private function apply_performance_optimizations() {
        $settings = $this->_performance_get_settings();
        if (isset($settings['server_caching']['enabled']) && $settings['server_caching']['enabled']) {
            add_action('muplugins_loaded', function () {
                if (!is_admin() && !wp_doing_ajax() && !wp_doing_cron() && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
                    $this->maybe_serve_from_cache();
                }
            }, 1);
        }
        if (isset($settings['browser_caching'])) {
            if ($settings['browser_caching']) {
                $this->_performance_apply_caching();
            } else {
                $this->_performance_remove_caching();
            }
        }
        if (isset($settings['heartbeat_api']) && $settings['heartbeat_api'] !== 'default') {
            $this->_performance_optimize_heartbeat($settings['heartbeat_api']);
        }
        if (isset($settings['post_revisions']) && $settings['post_revisions'] !== 'default') {
            $this->_performance_optimize_revisions($settings['post_revisions']);
        }
        if (isset($settings['trash_auto_empty']) && $settings['trash_auto_empty'] !== 'default') {
            $this->_performance_optimize_trash_days($settings['trash_auto_empty']);
        }
        if (!empty($settings['emoji_script'])) {
            $this->_performance_disable_emoji_scripts();
        }
        if (!empty($settings['xmlrpc'])) {
            $this->_performance_disable_xmlrpc();
        }
        if (!empty($settings['self_pingbacks'])) {
            $this->_performance_disable_self_pingbacks();
        }
        if (!empty($settings['rest_api_link'])) {
            remove_action('wp_head', 'rest_output_link_wp_head', 10);
            remove_action('template_redirect', 'rest_output_link_header', 11);
        }
        if (!empty($settings['shortlink'])) {
            remove_action('wp_head', 'wp_shortlink_wp_head', 10);
            remove_action('template_redirect', 'wp_shortlink_header', 11);
        }
        if (!empty($settings['rsd_link'])) {
            remove_action('wp_head', 'rsd_link');
        }
        if (!empty($settings['wlwmanifest'])) {
            remove_action('wp_head', 'wlwmanifest_link');
        }
        if (!empty($settings['wp_generator'])) {
            remove_action('wp_head', 'wp_generator');
            add_filter('the_generator', '__return_empty_string');
        }
        if (!empty($settings['lazy_load'])) {
            $this->_performance_enable_lazy_load();
        }
        if (!empty($settings['bad_bot_blocker'])) {
            $this->_performance_block_bad_bots();
            if (!empty($settings['bad_bot_blocker']['enabled']) && !empty($settings['bad_bot_blocker']['user_agents'])) {
                if ($this->detect_server_type() === 'apache' && $this->wp_filesystem) {
                    $ht_check = $this->_performance_check_htaccess_writable();
                    if ($ht_check['writable']) {
                        $current_content = $this->wp_filesystem->get_contents($ht_check['path']);
                        if ($current_content !== false && strpos($current_content, '# BEGIN WP Optimal State Bot Blocking') === false) {
                            $this->_performance_apply_bot_blocking($settings['bad_bot_blocker']['user_agents']);
                        }
                    }
                }
            } else {
                $this->_performance_remove_bot_blocking();
            }
        }
    }
    private function _performance_optimize_heartbeat($mode) {
        switch ($mode) {
            case 'slow':
                add_filter('heartbeat_settings', function ($settings) {
                    $settings['interval'] = 120;
                    return $settings;
                });
            break;
            case 'disable_admin':
                add_action('wp_enqueue_scripts', function () {
                    if (is_admin()) {
                        wp_deregister_script('heartbeat');
                    }
                }, 100);
            break;
            case 'disable_frontend':
                add_action('wp_enqueue_scripts', function () {
                    if (!is_admin()) {
                        wp_deregister_script('heartbeat');
                    }
                }, 100);
            break;
            case 'disable_all':
                add_action('wp_enqueue_scripts', function () {
                    wp_deregister_script('heartbeat');
                }, 100);
                add_action('admin_enqueue_scripts', function () {
                    wp_deregister_script('heartbeat');
                }, 100);
            break;
        }
    }
    private function _performance_optimize_revisions($mode) {
        if (defined('WP_POST_REVISIONS')) {
            return;
        }
        $values = ['limit_3' => 3, 'limit_5' => 5, 'limit_10' => 10, 'disable' => false];
        if (isset($values[$mode])) {
            define('WP_POST_REVISIONS', $values[$mode]);
        }
    }
    private function _performance_optimize_trash_days($mode) {
        if (defined('EMPTY_TRASH_DAYS')) {
            return;
        }
        $values = ['disable' => 0, 'days_7' => 7, 'days_14' => 14, 'days_30' => 30, 'days_60' => 60, 'days_90' => 90];
        if (isset($values[$mode])) {
            $days = absint($values[$mode]);
            if ($days >= 0 && $days <= 365) {
                define('EMPTY_TRASH_DAYS', $days);
            }
        }
    }
    private function _performance_disable_emoji_scripts() {
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
        add_filter('emoji_svg_url', '__return_false');
        if (!has_filter('tiny_mce_plugins', [$this, '_performance_remove_tinymce_emoji'])) {
            add_filter('tiny_mce_plugins', [$this, '_performance_remove_tinymce_emoji']);
        }
    }
    public function _performance_remove_tinymce_emoji($plugins) {
        if (is_array($plugins)) {
            return array_diff($plugins, ['wpemoji']);
        }
        return [];
    }
    private function _performance_disable_xmlrpc() {
        add_filter('xmlrpc_enabled', '__return_false');
        remove_action('wp_head', 'rsd_link');
        add_filter('xmlrpc_methods', function ($methods) {
            return [];
        });
    }
    private function _performance_disable_self_pingbacks() {
        remove_action('pre_ping', [$this, '_performance_filter_self_pingbacks']);
        add_action('pre_ping', [$this, '_performance_filter_self_pingbacks']);
    }
    public function _performance_filter_self_pingbacks(&$links) {
        $home = get_option('home');
        foreach ($links as $key => $link) {
            if (strpos($link, $home) === 0) {
                unset($links[$key]);
            }
        }
    }
    private function _performance_enable_lazy_load() {
        add_filter('wp_lazy_loading_enabled', '__return_true');
        add_filter('wp_content_img_tag', [$this, '_performance_add_async_decoding'], 10, 3);
    }
    public function _performance_add_async_decoding($filtered_image, $context, $attachment_id) {
        if (strpos($filtered_image, 'decoding=') === false) {
            $filtered_image = str_replace('<img ', '<img decoding="async" ', $filtered_image);
        }
        if (strpos($filtered_image, 'loading=') === false) {
            $filtered_image = str_replace('<img ', '<img loading="lazy" ', $filtered_image);
        }
        return $filtered_image;
    }
    public function ajax_analyze_indexes() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->check_user_access();
        if (!$this->check_rate_limit("analyze_indexes", 10)) {
            wp_send_json_error(['message' => __('🕔 Rate limit: Please wait 10 seconds.', 'optistate') ], 429);
            return;
        }
        $this->process_store->create_table();
        global $wpdb;
        $recommendations = [];
        $redundant_indexes = [];
        $wc_lookup_table = $wpdb->prefix . 'wc_order_product_lookup';
        $targets = [$wpdb->options => [[['autoload'], 'autoload', __('Speeds up your site\'s initial load time by organizing auto-loaded settings.', 'optistate') ], [['autoload', 'option_name'], 'idx_autoload_option', __('Allows for much faster retrieval of specific settings without searching the entire table.', 'optistate') ]], $wpdb->postmeta => [[['post_id', 'meta_key'], 'idx_post_meta_composite', __('Crucial for speeding up how custom fields are loaded for your posts and pages.', 'optistate') ], [['meta_key', 'meta_value(191)'], 'idx_meta_key_val', __('Optimizes searches that filter content by custom fields. (Uses a safe prefix length).', 'optistate') ]], $wpdb->posts => [[['post_type', 'post_status', 'post_date', 'ID'], 'idx_wp_query_optimization', __('A powerful index that speeds up the most common ways content is sorted and filtered on your site', 'optistate') ], [['post_author'], 'idx_author', __('Improves performance when viewing author archives or filtering posts in the admin area.', 'optistate') ]], $wpdb->usermeta => [[['user_id', 'meta_key'], 'idx_user_meta_composite', __('Speeds up checks for user permissions and loading user profile information.', 'optistate') ]], $wpdb->commentmeta => [[['comment_id', 'meta_key'], 'idx_comment_meta_composite', __('Essential for e-commerce sites using reviews/ratings. Speeds up loading review metadata significantly.', 'optistate') ]], $wpdb->termmeta => [[['term_id', 'meta_key'], 'idx_term_meta_composite', __('Speeds up menus and category archives that use custom images, colors, or layout settings.', 'optistate') ]], $wpdb->comments => [[['comment_post_ID', 'comment_approved', 'comment_date_gmt'], 'idx_comment_feed_loading', __('Drastically improves load times for posts with many comments by optimizing the standard WordPress comment fetch query.', 'optistate') ]], $wc_lookup_table => [[['product_id', 'date_created'], 'idx_wc_prod_lookup', __('Speeds up sales reporting and product purchase history lookups.', 'optistate') ]]];
        foreach ($targets as $table => $indexes) {
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
            if ($table_exists !== $table) {
                continue;
            }
            $raw_indexes = $wpdb->get_results("SHOW INDEX FROM `$table`", ARRAY_A);
            $existing_indexes = [];
            foreach ($raw_indexes as $idx) {
                $key_name = $idx['Key_name'];
                $seq = $idx['Seq_in_index'];
                $col = $idx['Column_name'];
                $existing_indexes[$key_name][$seq] = $col;
            }
            foreach ($indexes as $target) {
                list($columns, $suggested_name, $reason) = $target;
                $all_cols_exist = true;
                $clean_target_cols = [];
                foreach ($columns as $raw_col) {
                    $col_name = preg_replace('/\(\d+\)$/', '', $raw_col);
                    if (!preg_match('/^[a-zA-Z0-9_]+$/', $col_name)) {
                        $all_cols_exist = false;
                        break;
                    }
                    $clean_target_cols[] = $col_name;
                    $col_check = $wpdb->get_results($wpdb->prepare("SELECT COLUMN_NAME 
         FROM information_schema.COLUMNS 
         WHERE table_schema = %s 
         AND TABLE_NAME = %s 
         AND COLUMN_NAME = %s", DB_NAME, $table, $col_name));
                    if (empty($col_check)) {
                        $all_cols_exist = false;
                        break;
                    }
                }
                if (!$all_cols_exist) continue;
                $is_covered = false;
                foreach ($existing_indexes as $key_name => $idx_cols) {
                    ksort($idx_cols);
                    $idx_cols_values = array_values($idx_cols);
                    if (count($idx_cols_values) >= count($clean_target_cols)) {
                        $slice = array_slice($idx_cols_values, 0, count($clean_target_cols));
                        if ($slice === $clean_target_cols) {
                            $is_covered = true;
                            break;
                        }
                    }
                }
                if (!$is_covered) {
                    $full_reason = '<strong>' . __('Missing:', 'optistate') . '</strong> ' . $reason;
                    $recommendations[] = ['type' => 'missing', 'table' => $table, 'column' => implode(', ', $columns), 'raw_columns' => implode(',', $columns), 'index_name' => $suggested_name, 'reason' => $full_reason, 'status' => 'missing'];
                }
            }
            foreach ($existing_indexes as $key_a => $cols_a) {
                if ($key_a === 'PRIMARY') continue;
                ksort($cols_a);
                $vals_a = array_values($cols_a);
                foreach ($existing_indexes as $key_b => $cols_b) {
                    if ($key_a === $key_b) continue;
                    ksort($cols_b);
                    $vals_b = array_values($cols_b);
                    if (count($vals_b) >= count($vals_a)) {
                        $slice_b = array_slice($vals_b, 0, count($vals_a));
                        if ($vals_a === $slice_b) {
                            $is_a_unique = (bool)$wpdb->get_var($wpdb->prepare("SHOW INDEX FROM `$table` WHERE Key_name = %s AND Non_unique = 0", $key_a));
                            $is_b_unique = (bool)$wpdb->get_var($wpdb->prepare("SHOW INDEX FROM `$table` WHERE Key_name = %s AND Non_unique = 0", $key_b));
                            if ($is_a_unique && !$is_b_unique) {
                                continue;
                            }
                            $redundant_indexes[] = ['type' => 'redundant', 'table' => $table, 'column' => implode(', ', $vals_a), 'index_name' => $key_a, 'reason' => sprintf(__('Redundant: Covered by index "%s" (%s).<br>Removing this frees disk space and speeds up writes.', 'optistate'), $key_b, implode(', ', $vals_b)), 'status' => 'redundant', 'action_type' => 'drop'];
                            break;
                        }
                    }
                }
            }
        }
        $final_report = array_merge($recommendations, $redundant_indexes);
        wp_send_json_success(['recommendations' => $final_report]);
    }
    private function _mark_index_task_error($task_id, $task, $message) {
        $task['status'] = 'error';
        $task['message'] = $message;
        $this->process_store->set($task_id, $task, 30 * MINUTE_IN_SECONDS);
    }
    public function ajax_check_index_status() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->check_user_access();
        $task_id = isset($_POST['task_id']) ? sanitize_text_field(wp_unslash($_POST['task_id'])) : '';
        if (empty($task_id)) {
            wp_send_json_error(['message' => __('Invalid Task ID.', 'optistate') ]);
            return;
        }
        $task = $this->process_store->get($task_id);
        if (!$task) {
            wp_send_json_error(['message' => __('Task expired or not found.', 'optistate') ]);
            return;
        }
        if ($task['status'] === 'done') {
            wp_send_json_success(['status' => 'done']);
        } elseif ($task['status'] === 'error') {
            wp_send_json_error(['message' => __('Error: ', 'optistate') . $task['message']]);
        } else {
            wp_send_json_success(['status' => 'processing']);
        }
    }
    private function get_integrity_rules() {
        return apply_filters('optistate_integrity_rules', ['postmeta' => ['label' => __('Post Meta (Orphaned)', 'optistate'), 'child_table' => 'postmeta', 'child_key' => 'post_id', 'parent_table' => 'posts', 'parent_key' => 'ID', 'context_col' => 'meta_key', 'pk' => 'meta_id'], 'commentmeta' => ['label' => __('Comment Meta (Orphaned)', 'optistate'), 'child_table' => 'commentmeta', 'child_key' => 'comment_id', 'parent_table' => 'comments', 'parent_key' => 'comment_ID', 'context_col' => 'meta_key', 'pk' => 'meta_id'], 'usermeta' => ['label' => __('User Meta (Orphaned)', 'optistate'), 'child_table' => 'usermeta', 'child_key' => 'user_id', 'parent_table' => 'users', 'parent_key' => 'ID', 'context_col' => 'meta_key', 'pk' => 'umeta_id'], 'termmeta' => ['label' => __('Term Meta (Orphaned)', 'optistate'), 'child_table' => 'termmeta', 'child_key' => 'term_id', 'parent_table' => 'terms', 'parent_key' => 'term_id', 'context_col' => 'meta_key', 'pk' => 'meta_id'], 'term_taxonomy' => ['label' => __('Zombie Taxonomies (No Term Def)', 'optistate'), 'child_table' => 'term_taxonomy', 'child_key' => 'term_id', 'parent_table' => 'terms', 'parent_key' => 'term_id', 'context_col' => 'taxonomy', 'pk' => 'term_taxonomy_id'], 'term_relationships' => ['label' => __('Broken Relationships (No Taxonomy)', 'optistate'), 'child_table' => 'term_relationships', 'child_key' => 'term_taxonomy_id', 'parent_table' => 'term_taxonomy', 'parent_key' => 'term_taxonomy_id', 'context_col' => 'object_id', 'pk' => false], 'child_posts' => ['label' => __('Orphaned Revisions (No Parent)', 'optistate'), 'child_table' => 'posts', 'child_key' => 'post_parent', 'parent_table' => 'posts', 'parent_key' => 'ID', 'context_col' => 'post_title', 'pk' => 'ID', 'extra_where' => "AND c.post_parent > 0 AND c.post_type != 'attachment'"], 'comments_on_deleted' => ['label' => __('Comments on Deleted Posts', 'optistate'), 'child_table' => 'comments', 'child_key' => 'comment_post_ID', 'parent_table' => 'posts', 'parent_key' => 'ID', 'context_col' => 'comment_content', 'pk' => 'comment_ID', 'extra_where' => "AND c.comment_post_ID > 0"]]);
    }
    public function ajax_scan_integrity() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->check_user_access();
        if (!$this->check_rate_limit("scan_integrity", 10)) {
            wp_send_json_error(['message' => __('Please wait 10 seconds before scanning again.', 'optistate') ], 429);
            return;
        }
        global $wpdb;
        $results = [];
        $total_issues = 0;
        $rules = $this->get_integrity_rules();
        $start_time = microtime(true);
        $max_exec = 20;
        foreach ($rules as $type => $rule) {
            if ((microtime(true) - $start_time) > $max_exec) {
                $results[] = ['type' => 'timeout', 'label' => __('Scan paused (Time Limit)', 'optistate'), 'count' => 0, 'child_table' => '...', 'parent_table' => '...', 'samples' => []];
                break;
            }
            $child_table = $wpdb->prefix . $rule['child_table'];
            $parent_table = $wpdb->prefix . $rule['parent_table'];
            if ($wpdb->get_var("SHOW TABLES LIKE '$child_table'") != $child_table) continue;
            $extra_where = isset($rule['extra_where']) ? $rule['extra_where'] : '';
            $sql = "SELECT COUNT(*) 
        FROM $child_table c 
        LEFT JOIN $parent_table p ON c.{$rule['child_key']} = p.{$rule['parent_key']} 
        WHERE p.{$rule['parent_key']} IS NULL $extra_where";
            $count = (int)$wpdb->get_var($sql);
            if ($count > 0) {
                $total_issues+= $count;
                $context_col = $rule['context_col'];
                $sample_sql = "SELECT c.{$rule['child_key']} as fk_id, SUBSTRING(c.$context_col, 1, 50) as context 
                           FROM $child_table c 
                           LEFT JOIN $parent_table p ON c.{$rule['child_key']} = p.{$rule['parent_key']} 
                           WHERE p.{$rule['parent_key']} IS NULL $extra_where
                           LIMIT 3";
                $samples = $wpdb->get_results($sample_sql);
                $results[] = ['type' => $type, 'label' => $rule['label'], 'count' => $count, 'child_table' => $rule['child_table'], 'parent_table' => $rule['parent_table'], 'samples' => $samples];
            }
        }
        wp_send_json_success(['issues' => $results, 'total' => $total_issues]);
    }
    public function ajax_fix_integrity() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->check_user_access();
        $type = isset($_POST['type']) ? sanitize_key($_POST['type']) : '';
        $rules = $this->get_integrity_rules();
        if (!isset($rules[$type])) {
            wp_send_json_error(['message' => __('Invalid rule type.', 'optistate') ]);
            return;
        }
        global $wpdb;
        $rule = $rules[$type];
        $child_table = $wpdb->prefix . $rule['child_table'];
        $parent_table = $wpdb->prefix . $rule['parent_table'];
        $extra_where = isset($rule['extra_where']) ? $rule['extra_where'] : '';
        $limit = 2000;
        $deleted_count = 0;
        $wpdb->query("START TRANSACTION");
        try {
            if ($type === 'term_relationships') {
                $sql_fetch = "SELECT tr.object_id, tr.term_taxonomy_id 
                              FROM $child_table tr 
                              LEFT JOIN $parent_table tt ON tr.{$rule['child_key']} = tt.{$rule['parent_key']} 
                              WHERE tt.{$rule['parent_key']} IS NULL 
                              LIMIT $limit";
                $rows = $wpdb->get_results($sql_fetch);
                if ($rows) {
                    foreach ($rows as $row) {
                        $wpdb->delete($child_table, ['object_id' => $row->object_id, 'term_taxonomy_id' => $row->term_taxonomy_id], ['%d', '%d']);
                        $deleted_count++;
                    }
                }
            } else {
                $pk = $rule['pk'];
                $ids_sql = "SELECT c.$pk 
                            FROM $child_table c 
                            LEFT JOIN $parent_table p ON c.{$rule['child_key']} = p.{$rule['parent_key']} 
                            WHERE p.{$rule['parent_key']} IS NULL $extra_where
                            LIMIT $limit";
                $ids = $wpdb->get_col($ids_sql);
                if (!empty($ids)) {
                    $ids_string = implode(',', array_map('absint', $ids));
                    $wpdb->query("DELETE FROM $child_table WHERE $pk IN ($ids_string)");
                    $deleted_count = count($ids);
                }
            }
            $wpdb->query("COMMIT");
        }
        catch(Exception $e) {
            $wpdb->query("ROLLBACK");
            wp_send_json_error(['message' => __('Database error during fix.', 'optistate') ]);
            return;
        }
        $remaining_sql = "SELECT COUNT(c.{$rule['child_key']}) 
                          FROM $child_table c 
                          LEFT JOIN $parent_table p ON c.{$rule['child_key']} = p.{$rule['parent_key']} 
                          WHERE p.{$rule['parent_key']} IS NULL $extra_where";
        $remaining = (int)$wpdb->get_var($remaining_sql);
        if ($deleted_count > 0) {
            $this->log_optimization('manual', sprintf(__('🛡️ Integrity Fix: Removed %s orphaned rows from %s', 'optistate'), number_format_i18n($deleted_count), $rule['label']), '');
            if ($remaining === 0 || $deleted_count > 100) {
                wp_cache_flush();
            }
        }
        wp_send_json_success(['count' => $deleted_count, 'remaining' => $remaining, 'message' => sprintf(__('Cleaned %s rows.', 'optistate'), number_format_i18n($deleted_count)) ]);
    }
    private function _performance_get_bot_rules($user_agents_string) {
        if (empty($user_agents_string)) {
            return '';
        }
        $bots = array_filter(array_map('trim', explode("\n", $user_agents_string)));
        if (empty($bots)) {
            return '';
        }
        $rules = [];
        $rules[] = '# ============================================================';
        $rules[] = '# BEGIN WP Optimal State Bot Blocking';
        $rules[] = '# ============================================================';
        $rules[] = '<IfModule mod_setenvif.c>';
        foreach ($bots as $bot) {
            $safe_bot = preg_quote($bot, '/');
            $safe_bot = str_replace(' ', '\s', $safe_bot);
            $rules[] = sprintf('    SetEnvIfNoCase User-Agent "%s" bad_bot', $safe_bot);
        }
        $rules[] = '';
        $rules[] = '    <IfModule mod_authz_core.c>';
        $rules[] = '        <RequireAll>';
        $rules[] = '            Require all granted';
        $rules[] = '            Require not env bad_bot';
        $rules[] = '        </RequireAll>';
        $rules[] = '    </IfModule>';
        $rules[] = '    <IfModule !mod_authz_core.c>';
        $rules[] = '        Order Allow,Deny';
        $rules[] = '        Allow from all';
        $rules[] = '        Deny from env=bad_bot';
        $rules[] = '    </IfModule>';
        $rules[] = '</IfModule>';
        $rules[] = '# ============================================================';
        $rules[] = '# END WP Optimal State Bot Blocking';
        $rules[] = '# ============================================================';
        return implode(PHP_EOL, $rules);
    }
    public function _performance_apply_bot_blocking($user_agents) {
        $server_type = $this->detect_server_type();
        if ($server_type !== 'apache') {
            return false;
        }
        $htaccess_check = $this->_performance_check_htaccess_writable();
        if (!$htaccess_check['writable']) {
            return false;
        }
        $htaccess_path = $htaccess_check['path'];
        $current_content = $this->wp_filesystem->get_contents($htaccess_path);
        if ($current_content === false) {
            return false;
        }
        $new_rules = $this->_performance_get_bot_rules($user_agents);
        $clean_content = $this->_remove_rules_from_content($current_content, '# BEGIN WP Optimal State Bot Blocking', '# END WP Optimal State Bot Blocking');
        if (!empty($new_rules)) {
            $final_content = $new_rules . PHP_EOL . $clean_content;
        } else {
            $final_content = $clean_content;
        }
        return $this->wp_filesystem->put_contents($htaccess_path, $final_content, FS_CHMOD_FILE);
    }
    public function _performance_remove_bot_blocking() {
        $server_type = $this->detect_server_type();
        if ($server_type !== 'apache') {
            return true;
        }
        $htaccess_check = $this->_performance_check_htaccess_writable();
        if (!$htaccess_check['writable'] || !$htaccess_check['exists']) {
            return false;
        }
        $htaccess_path = $htaccess_check['path'];
        $current_content = $this->wp_filesystem->get_contents($htaccess_path);
        if ($current_content === false) {
            return false;
        }
        if (strpos($current_content, '# BEGIN WP Optimal State Bot Blocking') === false) {
            return true;
        }
        $new_content = $this->_remove_rules_from_content($current_content, '# BEGIN WP Optimal State Bot Blocking', '# END WP Optimal State Bot Blocking');
        return $this->wp_filesystem->put_contents($htaccess_path, $new_content, FS_CHMOD_FILE);
    }
    private function _remove_rules_from_content($content, $begin_marker, $end_marker) {
        $separator = '# ============================================================';
        $pattern = '/\s*' . preg_quote($separator, '/') . '\s*\n' . '\s*' . preg_quote($begin_marker, '/') . '.*?' . preg_quote($end_marker, '/') . '\s*\n' . '\s*' . preg_quote($separator, '/') . '\s*\n?/s';
        $new_content = preg_replace($pattern, '', $content);
        return preg_replace("/\n{3,}/", "\n\n", trim($new_content));
    }
    private function _performance_block_bad_bots() {
        if (is_user_logged_in() || is_admin()) {
            return;
        }
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        if (empty($ua)) {
            return;
        }
        $settings = $this->_performance_get_settings();
        if (empty($settings['bad_bot_blocker']['enabled'])) {
            return;
        }
        $raw_bots = isset($settings['bad_bot_blocker']['user_agents']) ? $settings['bad_bot_blocker']['user_agents'] : '';
        if (empty($raw_bots)) {
            return;
        }
        static $bots_list = null;
        if ($bots_list === null) {
            $bots_list = array_filter(array_map('trim', explode("\n", $raw_bots)));
        }
        foreach ($bots_list as $bot) {
            if (empty($bot)) continue;
            if (stripos($ua, $bot) !== false) {
                header('HTTP/1.1 403 Forbidden');
                die('Access Denied');
            }
        }
    }
    public function ajax_get_performance_features() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, 'nonce');
        $current_settings = $this->_performance_get_settings();
        $response = [];
        foreach ($this->performance_feature_definitions as $key => $feature) {
            $saved_value = $current_settings[$key]??[];
            $default_value = $feature['default']??null;
            if (is_array($default_value)) {
                $response[$key] = wp_parse_args($saved_value, $default_value);
            } else {
                $response[$key] = isset($current_settings[$key]) ? $current_settings[$key] : $default_value;
            }
        }
        $revisions_defined = $this->is_revisions_defined;
        $trash_days_defined = $this->is_trash_days_defined;
        wp_send_json_success(['features' => $response, 'definitions' => $this->performance_feature_definitions, 'revisions_defined' => $revisions_defined, 'trash_days_defined' => $trash_days_defined]);
    }
    public function ajax_save_performance_features() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, 'nonce');
        $this->check_user_access();
        if (!$this->check_rate_limit("save_performance", 3)) {
            wp_send_json_error(['message' => __('🕔 Please wait a few seconds before saving again.', 'optistate') ], 429);
            return;
        }
        $features = isset($_POST['features']) ? wp_unslash($_POST['features']) : [];
        if (!is_array($features)) {
            wp_send_json_error(['message' => __('Invalid data format', 'optistate') ]);
            return;
        }
        $current_settings = $this->_performance_get_settings();
        $feature_definitions = $this->performance_feature_definitions;
        $changes_to_log = [];
        $current_user = wp_get_current_user();
        $username = ($current_user && $current_user->exists()) ? $current_user->user_login : 'Unknown';
        foreach ($feature_definitions as $key => $def) {
            if (!isset($features[$key])) {
                continue;
            }
            $old_value_raw = $current_settings[$key]??$def['default'];
            $new_value_raw = $features[$key];
            $old_value_normalized = $old_value_raw;
            $new_value_normalized = null;
            if (isset($def['type']) && $def['type'] === 'custom_caching' && $key === 'server_caching') {
                $new_value_normalized = filter_var($new_value_raw['enabled']??false, FILTER_VALIDATE_BOOLEAN);
                $old_value_normalized = (bool)($old_value_raw['enabled']??false);
            } elseif (isset($def['type']) && $def['type'] === 'custom_bot_blocker') {
                $new_value_normalized = filter_var($new_value_raw['enabled']??false, FILTER_VALIDATE_BOOLEAN);
                $old_value_normalized = (bool)($old_value_raw['enabled']??false);
                $old_bots = isset($old_value_raw['user_agents']) ? trim($old_value_raw['user_agents']) : '';
                $new_bots = isset($new_value_raw['user_agents']) ? trim($new_value_raw['user_agents']) : '';
                if ($old_bots !== $new_bots) {
                    $changes_to_log[$key . '_list'] = ['title' => $def['title'], 'old' => 'list_updated', 'new' => 'list_updated', 'type' => 'list_update'];
                }
            } elseif (isset($def['type']) && $def['type'] === 'toggle') {
                $new_value_normalized = ($new_value_raw === 'true' || $new_value_raw === true || $new_value_raw === 1 || $new_value_raw === '1');
                $old_value_normalized = (bool)$old_value_raw;
            } elseif (isset($def['options'])) {
                $new_value_normalized = (string)$new_value_raw;
                $old_value_normalized = (string)$old_value_raw;
            }
            if ($new_value_normalized === null) {
                continue;
            }
            if ($old_value_normalized != $new_value_normalized) {
                $changes_to_log[$key] = ['title' => $def['title'], 'old' => $old_value_normalized, 'new' => $new_value_normalized, 'type' => $def['type']??(isset($def['options']) ? 'options' : 'unknown'), 'options' => $def['options']??null];
            }
        }
        $old_query_mode = $current_settings['server_caching']['query_string_mode']??'include_safe';
        $new_query_mode = $features['server_caching']['query_string_mode']??'include_safe';
        $query_mode_changed = ($old_query_mode !== $new_query_mode);
        $success = $this->_performance_save_settings($features);
        if ($success) {
            $this->apply_performance_optimizations();
            $fresh_settings = $this->_performance_get_settings();
            if (isset($fresh_settings['bad_bot_blocker'])) {
                $bot_settings = $fresh_settings['bad_bot_blocker'];
                if (!empty($bot_settings['enabled']) && !empty($bot_settings['user_agents'])) {
                    $write_result = $this->_performance_apply_bot_blocking($bot_settings['user_agents']);
                    if ($write_result === false && $this->detect_server_type() === 'apache') {
                        $this->log_optimization("error", "❌ " . __("Failed to write Bad Bot rules to .htaccess", "optistate"), "");
                    }
                } else {
                    $this->_performance_remove_bot_blocking();
                }
            }
            foreach ($changes_to_log as $key => $change) {
                $operation = '';
                $title = wp_strip_all_tags($change['title']);
                if ($change['type'] === 'list_update') {
                    $operation = sprintf(__('%s Updated by %s', 'optistate'), $title, $username);
                } elseif (strpos($key, 'post_revisions') !== false || strpos($key, 'trash_auto_empty') !== false || strpos($key, 'heartbeat_api') !== false) {
                    $operation = sprintf(__('%s Updated', 'optistate'), $title);
                } elseif ($change['type'] === 'custom_caching' || $change['type'] === 'custom_bot_blocker' || $change['type'] === 'toggle') {
                    if ($change['new']) {
                        $operation = sprintf(__('%s Activated by %s', 'optistate'), $title, $username);
                    } else {
                        $operation = sprintf(__('%s Deactivated by %s', 'optistate'), $title, $username);
                    }
                } elseif ($change['type'] === 'options' && isset($change['options'])) {
                    $old_label = $change['options'][$change['old']]??$change['old'];
                    $new_label = $change['options'][$change['new']]??$change['new'];
                    $operation = sprintf(__('%1$s changed from "%2$s" to "%3$s"', 'optistate'), $title, wp_strip_all_tags($old_label), wp_strip_all_tags($new_label));
                }
                if (!empty($operation)) {
                    $this->log_optimization("manual", $operation, "");
                }
            }
            $new_settings = $this->_performance_get_settings();
            if (!empty($new_settings['server_caching']['enabled'])) {
                $this->secure_cache_directory();
            }
            $message = __('Performance settings saved successfully!', 'optistate');
            if ($query_mode_changed) {
                $this->purge_entire_cache();
                $this->log_optimization("manual", '🗑️ ' . __("Page Cache Purged (Query Mode Changed)", "optistate"), "");
                $message.= ' ' . __('The page cache was automatically purged to apply the new query string settings.', 'optistate');
            }
            wp_send_json_success(['message' => $message]);
        } else {
            wp_send_json_error(['message' => __('Failed to save settings. Please try again.', 'optistate') ]);
        }
    }
    private function is_caching_enabled() {
        if (!isset($this->server_caching_settings)) {
            $settings = $this->_performance_get_settings();
            $this->server_caching_settings = $settings['server_caching']??['enabled' => false];
        }
        return !empty($this->server_caching_settings['enabled']);
    }
    public function purge_cache_for_url($url) {
        if (!$this->is_caching_enabled() || !$this->wp_filesystem) {
            return;
        }
        $parsed_url = wp_parse_url($url);
        if (!$parsed_url || empty($parsed_url['host']) || empty($parsed_url['path'])) {
            return;
        }
        $host = $parsed_url['host'];
        $uri = $parsed_url['path'];
        if (substr($uri, -1) !== '/') {
            $uri.= '/';
        }
        $cache_path_desktop = $this->get_cache_path($host, $uri, false);
        $settings = $this->_performance_get_settings() ['server_caching'];
        $cache_path_mobile = null;
        if ($settings['mobile_cache']??false) {
            $cache_path_mobile = $this->get_cache_path($host, $uri, true);
        }
        if ($this->wp_filesystem->exists($cache_path_desktop)) {
            $this->wp_filesystem->delete($cache_path_desktop);
        }
        if ($cache_path_mobile && $this->wp_filesystem->exists($cache_path_mobile)) {
            $this->wp_filesystem->delete($cache_path_mobile);
        }
    }
    public function purge_post_and_related_urls($post_id) {
        if (!$this->is_caching_enabled()) {
            return;
        }
        $post = get_post($post_id);
        if (!$post) {
            return;
        }
        $lock_file = $this->cache_dir . '.purge.lock';
        if (!$this->wp_filesystem->is_dir($this->cache_dir)) {
            return;
        }
        $lock_handle = @fopen($lock_file, 'c');
        if ($lock_handle === false) {
            $this->purge_without_lock($post);
            return;
        }
        $lock_acquired = false;
        $lock_attempts = 0;
        $max_attempts = 10;
        while (!$lock_acquired && $lock_attempts < $max_attempts) {
            $lock_acquired = flock($lock_handle, LOCK_EX | LOCK_NB);
            if (!$lock_acquired) {
                usleep(100000);
                $lock_attempts++;
            }
        }
        if (!$lock_acquired) {
            fclose($lock_handle);
            return;
        }
        try {
            $this->purge_cache_for_url(get_permalink($post));
            $this->purge_cache_for_url(home_url('/'));
            if (get_option('show_on_front') === 'page') {
                $posts_page_id = get_option('page_for_posts');
                if ($posts_page_id) {
                    $this->purge_cache_for_url(get_permalink($posts_page_id));
                }
            }
            $this->purge_cache_for_url(get_bloginfo('rss2_url'));
            $this->purge_cache_for_url(get_bloginfo('atom_url'));
            $this->purge_cache_for_url(get_bloginfo('comments_rss2_url'));
            $post_type_archive_link = get_post_type_archive_link(get_post_type($post));
            if ($post_type_archive_link) {
                $this->purge_cache_for_url($post_type_archive_link);
            }
            $this->purge_cache_for_url(get_author_posts_url($post->post_author));
            $taxonomies = get_object_taxonomies($post, 'public');
            $all_terms = [];
            if (!empty($taxonomies)) {
                foreach ($taxonomies as $taxonomy) {
                    $terms = wp_get_post_terms($post->ID, $taxonomy);
                    if (!is_wp_error($terms) && !empty($terms)) {
                        $all_terms = array_merge($all_terms, $terms);
                        foreach ($terms as $term) {
                            $this->purge_cache_for_url(get_term_link($term, $taxonomy));
                            $this->purge_paginated_term_archive($term, $taxonomy);
                        }
                    }
                }
            }
        }
        finally {
            flock($lock_handle, LOCK_UN);
            fclose($lock_handle);
            if ($this->wp_filesystem->exists($lock_file)) {
                $this->wp_filesystem->delete($lock_file);
            }
        }
    }
    private function purge_without_lock($post) {
        $this->purge_cache_for_url(get_permalink($post));
        $this->purge_cache_for_url(home_url('/'));
        if (get_option('show_on_front') === 'page') {
            $posts_page_id = get_option('page_for_posts');
            if ($posts_page_id) {
                $this->purge_cache_for_url(get_permalink($posts_page_id));
            }
        }
        $this->purge_cache_for_url(get_bloginfo('rss2_url'));
        $this->purge_cache_for_url(get_author_posts_url($post->post_author));
    }
    public function on_post_status_transition($new_status, $old_status, $post) {
        if (!$this->is_caching_enabled()) {
            return;
        }
        if (!get_post_type_object($post->post_type)->public) {
            return;
        }
        if ($new_status === 'publish' || $old_status === 'publish') {
            $this->purge_post_and_related_urls($post->ID);
        }
    }
    private function purge_paginated_term_archive($term, $taxonomy) {
        if (!$this->is_caching_enabled() || !$this->wp_filesystem) {
            return;
        }
        $query_args = ['post_type' => 'any', 'tax_query' => [['taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => $term->term_id, ], ], 'posts_per_page' => get_option('posts_per_page'), 'fields' => 'ids', ];
        $query = new WP_Query($query_args);
        $total_pages = $query->max_num_pages;
        if ($total_pages > 1) {
            $term_link = get_term_link($term, $taxonomy);
            if (!is_wp_error($term_link)) {
                for ($i = 2;$i <= $total_pages;$i++) {
                    $paginated_url = trailingslashit($term_link) . 'page/' . $i . '/';
                    $this->purge_cache_for_url($paginated_url);
                }
            }
        }
    }
    public function purge_entire_cache() {
        if (!$this->is_caching_enabled() || !$this->wp_filesystem) {
            return;
        }
        $files = $this->wp_filesystem->dirlist($this->cache_dir);
        if (!empty($files)) {
            foreach ($files as $file) {
                if (substr($file['name'], -5) === '.html') {
                    $this->wp_filesystem->delete($this->cache_dir . $file['name']);
                }
            }
        }
    }
    public function on_post_updated($post_id, $post_after, $post_before) {
        if (!$this->is_caching_enabled()) {
            return;
        }
        if ($post_before->post_status !== 'publish' || $post_after->post_status !== 'publish') {
            return;
        }
        if ($post_before->post_name !== $post_after->post_name) {
            $this->purge_cache_for_url(get_permalink($post_before));
        }
    }
    public function on_comment_status_transition($new_status, $old_status, $comment) {
        if (!$this->is_caching_enabled()) {
            return;
        }
        if ($new_status === 'approved' || $old_status === 'approved') {
            $this->purge_post_and_related_urls($comment->comment_post_ID);
        }
    }
    public function on_edited_term($term_id, $tt_id, $taxonomy) {
        if (!$this->is_caching_enabled()) {
            return;
        }
        $tax_obj = get_taxonomy($taxonomy);
        if (!$tax_obj || !$tax_obj->public) {
            return;
        }
        $term_link = get_term_link($term_id, $taxonomy);
        if (!is_wp_error($term_link)) {
            $this->purge_cache_for_url($term_link);
        }
    }
    public function handle_settings_download() {
        if (!isset($_GET['action']) || $_GET['action'] !== 'optistate_download_settings') {
            return;
        }
        if (!is_user_logged_in()) {
            wp_die(esc_html__('Authentication required.', 'optistate'), 403);
        }
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'optistate_download_settings')) {
            wp_die(esc_html__('Security check failed.', 'optistate'), esc_html__('Security Error', 'optistate'), ['response' => 403]);
        }
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'optistate'), 403);
        }
        $this->check_user_access();
        $settings = $this->get_persistent_settings();
        if (empty($settings)) {
            wp_die(esc_html__('No settings found to export.', 'optistate'), esc_html__('Export Error', 'optistate'), ['response' => 500]);
        }
        $export_data = ['plugin' => 'WP Optimal State', 'version' => OPTISTATE::VERSION, 'exported_at' => current_time('Y-m-d H:i:s'), 'exported_timestamp' => time(), 'site_url' => get_site_url(), 'wp_version' => get_bloginfo('version'), 'settings' => $settings];
        $json_content = json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json_content === false) {
            wp_die(esc_html__('Failed to generate settings file.', 'optistate'), esc_html__('Export Error', 'optistate'), ['response' => 500]);
        }
        $current_user = wp_get_current_user();
        $username = ($current_user && $current_user->exists()) ? $current_user->user_login : 'Unknown';
        $log_message = '📥 ' . sprintf(__("Settings Exported by %s", "optistate"), $username);
        $this->log_optimization("manual", $log_message, "");
        $filename = self::SETTINGS_DIR_NAME . '-' . gmdate('Y-m-d-His') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json_content));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        if (ob_get_level()) {
            ob_end_clean();
        }
        echo $json_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON content
        exit;
    }
    public function ajax_export_settings() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, 'nonce');
        if (!$this->check_rate_limit("export_settings", 5)) {
            wp_send_json_error(['message' => __('🕔 Please wait a few seconds before exporting again.', 'optistate') ], 429);
            return;
        }
        $download_url = add_query_arg(['action' => 'optistate_download_settings', '_wpnonce' => wp_create_nonce('optistate_download_settings') ], admin_url());
        wp_send_json_success(['download_url' => esc_url_raw($download_url), 'message' => __('Settings export ready.', 'optistate') ]);
    }
    public function ajax_import_settings() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, 'nonce');
        if (!$this->check_rate_limit("import_settings", 10)) {
            wp_send_json_error(['message' => __('🕔 Please wait at least 10 seconds before importing again.', 'optistate') ], 429);
            return;
        }
        if (!isset($_FILES['settings_file']) || !is_uploaded_file($_FILES['settings_file']['tmp_name'])) {
            wp_send_json_error(['message' => __('No file uploaded.', 'optistate') ]);
            return;
        }
        $file = $_FILES['settings_file'];
        if ($file['size'] > 1048576) {
            wp_send_json_error(['message' => __('File is too large. Maximum size is 1MB.', 'optistate') ]);
            return;
        }
        $filename = sanitize_file_name($file['name']);
        $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($file_ext !== 'json') {
            wp_send_json_error(['message' => __('Invalid file type. Only JSON files are allowed.', 'optistate') ]);
            return;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $allowed_mimes = ['application/json', 'text/plain', 'application/octet-stream'];
        if (!in_array($mime_type, $allowed_mimes, true)) {
            wp_send_json_error(['message' => __('Invalid file format detected.', 'optistate') ]);
            return;
        }
        $json_content = file_get_contents($file['tmp_name']);
        if ($json_content === false) {
            wp_send_json_error(['message' => __('Failed to read uploaded file.', 'optistate') ]);
            return;
        }
        if (preg_match('/<\?php|<script|eval\s*\(|exec\s*\(/i', $json_content)) {
            wp_send_json_error(['message' => __('Security risk detected. File contains suspicious content.', 'optistate') ]);
            return;
        }
        $import_data = json_decode($json_content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => __('Invalid JSON file. Error: ', 'optistate') . json_last_error_msg() ]);
            return;
        }
        if (!is_array($import_data) || !isset($import_data['plugin']) || !isset($import_data['settings'])) {
            wp_send_json_error(['message' => __('Invalid settings file structure.', 'optistate') ]);
            return;
        }
        if ($import_data['plugin'] !== 'WP Optimal State') {
            wp_send_json_error(['message' => __('This file is not a valid WP Optimal State settings export.', 'optistate') ]);
            return;
        }
        $new_settings = $import_data['settings'];
        if (!is_array($new_settings)) {
            wp_send_json_error(['message' => __('Settings data is corrupted.', 'optistate') ]);
            return;
        }
        $validated_settings = $this->validate_imported_settings($new_settings);
        if ($validated_settings === false) {
            wp_send_json_error(['message' => __('Settings validation failed. Import aborted.', 'optistate') ]);
            return;
        }
        $success = $this->save_persistent_settings($validated_settings);
        if (!$success) {
            wp_send_json_error(['message' => __('Failed to save imported settings.', 'optistate') ]);
            return;
        }
        $this->apply_performance_optimizations();
        $this->reschedule_cron_from_settings();
        delete_transient('optistate_stats_cache');
        delete_transient('optistate_health_score');
        $current_user = wp_get_current_user();
        $username = ($current_user && $current_user->exists()) ? $current_user->user_login : 'Unknown';
        $log_message = '📤 ' . sprintf(__("Settings Imported by %s", "optistate"), $username);
        $this->log_optimization("manual", $log_message, "");
        $summary = ['max_backups' => $validated_settings['max_backups'], 'auto_optimize_days' => $validated_settings['auto_optimize_days'], 'email_notifications' => $validated_settings['email_notifications'], 'performance_features_count' => count($validated_settings['performance_features']), 'imported_from_site' => isset($import_data['site_url']) ? esc_url($import_data['site_url']) : __('Unknown', 'optistate'), 'exported_at' => isset($import_data['exported_at']) ? sanitize_text_field($import_data['exported_at']) : __('Unknown', 'optistate') ];
        wp_send_json_success(['message' => __('Settings imported successfully!', 'optistate'), 'summary' => $summary]);
    }
    private function validate_imported_settings($settings) {
        if (!is_array($settings)) {
            return false;
        }
        $validated = [];
        if (isset($settings['max_backups'])) {
            $max_backups = absint($settings['max_backups']);
            $validated['max_backups'] = ($max_backups >= 1 && $max_backups <= 1) ? $max_backups : 1;
        } else {
            $validated['max_backups'] = 1;
        }
        if (isset($settings['auto_optimize_days'])) {
            $auto_days = absint($settings['auto_optimize_days']);
            $validated['auto_optimize_days'] = ($auto_days >= 0 && $auto_days <= 0) ? $auto_days : 0;
        } else {
            $validated['auto_optimize_days'] = 0;
        }
        if (isset($settings['auto_optimize_time'])) {
            $time = sanitize_text_field($settings['auto_optimize_time']);
            if (preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
                $validated['auto_optimize_time'] = $time;
            } else {
                $validated['auto_optimize_time'] = '02:00';
            }
        } else {
            $validated['auto_optimize_time'] = '02:00';
        }
        $validated['email_notifications'] = isset($settings['email_notifications']) ? (bool)$settings['email_notifications'] : false;
        $validated['auto_backup_only'] = isset($settings['auto_backup_only']) ? (bool)$settings['auto_backup_only'] : false;
        if (isset($settings['performance_features']) && is_array($settings['performance_features'])) {
            $validated['performance_features'] = $this->validate_performance_features($settings['performance_features']);
        } else {
            $validated['performance_features'] = [];
        }
        if (isset($settings['allowed_users']) && is_array($settings['allowed_users'])) {
            $validated['allowed_users'] = array_filter(array_map('absint', $settings['allowed_users']), function ($id) {
                return $id > 0;
            });
            $validated['allowed_users'] = array_values(array_unique($validated['allowed_users']));
        } else {
            $validated['allowed_users'] = [];
        }
        $validated['disable_restore_security'] = isset($settings['disable_restore_security']) ? (bool)$settings['disable_restore_security'] : false;
        if (isset($settings['pagespeed_api_key'])) {
            $api_key = sanitize_text_field($settings['pagespeed_api_key']);
            $api_key = trim($api_key);
            $length = strlen($api_key);
            if ($length >= 30 && $length <= 80) {
                $validated['pagespeed_api_key'] = $api_key;
            } else {
                $validated['pagespeed_api_key'] = '';
            }
        } else {
            $validated['pagespeed_api_key'] = '';
        }
        return $validated;
    }
    private function validate_performance_features($features) {
        if (!is_array($features)) {
            return [];
        }
        $validated = [];
        foreach ($features as $key => $value) {
            if (!isset($this->performance_feature_definitions[$key])) {
                continue;
            }
            $feature_def = $this->performance_feature_definitions[$key];
            if (isset($feature_def['type']) && $feature_def['type'] === 'custom_caching' && $key === 'server_caching') {
                if (is_array($value)) {
                    $validated[$key] = ['enabled' => filter_var($value['enabled']??false, FILTER_VALIDATE_BOOLEAN), 'lifetime' => min(max(absint($value['lifetime']??86400), HOUR_IN_SECONDS), 6 * MONTH_IN_SECONDS), 'query_string_mode' => in_array($value['query_string_mode']??'include_safe', ['ignore_all', 'include_safe', 'unique_cache'], true) ? $value['query_string_mode'] : 'include_safe', 'exclude_urls' => sanitize_textarea_field($value['exclude_urls']??''), 'disable_cookie_check' => filter_var($value['disable_cookie_check']??false, FILTER_VALIDATE_BOOLEAN), ];
                }
            } elseif (isset($feature_def['type']) && $feature_def['type'] === 'custom_bot_blocker') {
                if (is_array($value)) {
                    $raw_bots = isset($value['user_agents']) ? (string)$value['user_agents'] : '';
                    $bots_array = array_filter(array_map(function ($bot) {
                        return substr(trim($bot), 0, 100);
                    }, explode("\n", $raw_bots)));
                    $clean_bots_string = implode("\n", $bots_array);
                    $validated[$key] = ['enabled' => filter_var($value['enabled']??false, FILTER_VALIDATE_BOOLEAN), 'user_agents' => $clean_bots_string];
                } else {
                    $validated[$key] = $feature_def['default'];
                }
            } elseif (isset($feature_def['type']) && $feature_def['type'] === 'toggle') {
                $validated[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif (isset($feature_def['options']) && is_array($feature_def['options'])) {
                if (array_key_exists($value, $feature_def['options'])) {
                    $validated[$key] = sanitize_key($value);
                } else {
                    $validated[$key] = $feature_def['default'];
                }
            }
        }
        return $validated;
    }
    private function matches_with_word_boundary($text, $search, $case_sensitive = false, $partial_match = false) {
        if ($partial_match) {
            if ($case_sensitive) {
                return mb_strpos($text, $search) !== false;
            } else {
                return mb_stripos($text, $search) !== false;
            }
        } else {
            $escaped_search = preg_quote($search, '/');
            $flags = $case_sensitive ? 'u' : 'iu';
            $pattern = sprintf(self::REGEX_BOUNDARY_FMT, $escaped_search, $flags);
            return preg_match($pattern, $text) === 1;
        }
    }
    private function _get_sr_snippet($text, $search, $length = 100) {
        $pos = mb_stripos($text, $search);
        if ($pos === false) {
            return mb_substr($text, 0, $length) . (mb_strlen($text) > $length ? '...' : '');
        }
        $half_length = floor($length / 2);
        $start = max(0, $pos - $half_length);
        $snippet = mb_substr($text, $start, $length);
        if ($start > 0) {
            $snippet = '...' . $snippet;
        }
        if (($start + $length) < mb_strlen($text)) {
            $snippet.= '...';
        }
        return $snippet;
    }
    private function find_serialized_match_value($data, $search, $case_sensitive, $partial_match) {
        if (is_string($data)) {
            if ($this->matches_with_word_boundary($data, $search, $case_sensitive, $partial_match)) {
                return $data;
            }
        } elseif (is_array($data)) {
            foreach ($data as $value) {
                $found = $this->find_serialized_match_value($value, $search, $case_sensitive, $partial_match);
                if ($found !== false) return $found;
            }
        } elseif (is_object($data)) {
            $props = get_object_vars($data);
            foreach ($props as $value) {
                $found = $this->find_serialized_match_value($value, $search, $case_sensitive, $partial_match);
                if ($found !== false) return $found;
            }
        }
        return false;
    }
    public function ajax_search_replace_dry_run() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->check_user_access();
        $reset = isset($_POST['reset']) && $_POST['reset'] === 'true';
        if ($reset && !$this->check_rate_limit("search_replace_dry_run", 5)) {
            wp_send_json_error(['message' => __('🕐 Please wait a few seconds before searching again.', 'optistate') ], 429);
            return;
        }
        $search = isset($_POST['search']) ? stripslashes($_POST['search']) : '';
        $tables_input = isset($_POST['tables']) ? (array)$_POST['tables'] : ['all'];
        $case_sensitive = isset($_POST['case_sensitive']) && $_POST['case_sensitive'] == '1';
        $partial_match = isset($_POST['partial_match']) && $_POST['partial_match'] == '1';
        if (strlen($search) > 600) {
            wp_send_json_error(['message' => __('Search term is too long. Maximum length is 600 characters.', 'optistate') ]);
            return;
        }
        if (empty($search)) {
            wp_send_json_error(['message' => __('Please enter a search term.', 'optistate') ]);
        }
        $user_id = get_current_user_id();
        $transient_key = 'optistate_sr_dry_' . $user_id;
        $state = get_transient($transient_key);
        if ($reset || !$state) {
            global $wpdb;
            $valid_db_tables = $wpdb->get_col("SHOW TABLES");
            $tables = [];
            if (!empty($tables_input) && $tables_input[0] === 'all') {
                $tables = $valid_db_tables;
            } else {
                $tables = array_intersect(array_map('sanitize_text_field', $tables_input), $valid_db_tables);
            }
            $protected = [$this->process_store->get_table_name(), $wpdb->prefix . 'optistate_backup_metadata'];
            $tables = array_diff($tables, $protected);
            $state = ['tables' => array_values($tables), 'current_idx' => 0, 'total_matches' => 0, 'tables_affected' => 0, 'preview' => [], 'status' => 'running'];
        }
        $start_time = time();
        $max_exec_time = 20;
        global $wpdb;
        while ($state['current_idx'] < count($state['tables'])) {
            if ((time() - $start_time) > $max_exec_time) {
                set_transient($transient_key, $state, 10 * MINUTE_IN_SECONDS);
                $percent = round(($state['current_idx'] / count($state['tables'])) * 100);
                wp_send_json_success(['status' => 'running', 'percent' => $percent, 'message' => sprintf(__('Scanning table %s of %s...', 'optistate'), number_format_i18n($state['current_idx'] + 1), number_format_i18n(count($state['tables']))) ]);
                return;
            }
            $table = $state['tables'][$state['current_idx']];
            if (!$this->validate_table_name($table)) {
                $state['current_idx']++;
                continue;
            }
            $found_in_table = 0;
            $columns = $wpdb->get_results("SHOW COLUMNS FROM `$table`", ARRAY_A);
            $text_columns = [];
            $primary_key = '';
            foreach ($columns as $col) {
                if ($col['Key'] === 'PRI') {
                    $primary_key = $col['Field'];
                }
                if (preg_match('/char|text/i', $col['Type'])) {
                    $text_columns[] = $col['Field'];
                }
            }
            if (!empty($text_columns)) {
                foreach ($text_columns as $col) {
                    if ($case_sensitive) {
                        $sql = $wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE BINARY `$col` LIKE BINARY %s", '%' . $wpdb->esc_like($search) . '%');
                    } else {
                        $sql = $wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE `$col` LIKE %s", '%' . $wpdb->esc_like($search) . '%');
                    }
                    $count = (int)$wpdb->get_var($sql);
                    if ($count > 0) {
                        if ($case_sensitive) {
                            $preview_sql = $wpdb->prepare("SELECT `$primary_key`, `$col` FROM `$table` WHERE BINARY `$col` LIKE BINARY %s LIMIT 200", '%' . $wpdb->esc_like($search) . '%');
                        } else {
                            $preview_sql = $wpdb->prepare("SELECT `$primary_key`, `$col` FROM `$table` WHERE `$col` LIKE %s LIMIT 200", '%' . $wpdb->esc_like($search) . '%');
                        }
                        $rows = $wpdb->get_results($preview_sql, ARRAY_A);
                        $actual_matches = 0;
                        foreach ($rows as $row) {
                            $original = $row[$col];
                            $match_found = false;
                            $content_to_preview = $original;
                            if ($this->matches_with_word_boundary($original, $search, $case_sensitive, $partial_match)) {
                                $match_found = true;
                            } elseif (is_serialized($original)) {
                                $unserialized = @unserialize($original, ['allowed_classes' => false]);
                                if ($unserialized !== false) {
                                    $inner_match = $this->find_serialized_match_value($unserialized, $search, $case_sensitive, $partial_match);
                                    if ($inner_match !== false) {
                                        $match_found = true;
                                        $content_to_preview = $inner_match;
                                    }
                                }
                            }
                            if (!$match_found) {
                                continue;
                            }
                            $actual_matches++;
                            if (count($state['preview']) < 500) {
                                $snippet = $this->_get_sr_snippet($content_to_preview, $search, 140);
                                $preview_text = esc_html($snippet);
                                $modifier = $case_sensitive ? '' : 'i';
                                $escaped_search = preg_quote(esc_html($search), '/');
                                if ($partial_match) {
                                    $highlight_pattern = '/' . $escaped_search . '/' . $modifier . 'u';
                                } else {
                                    $highlight_pattern = sprintf(self::REGEX_BOUNDARY_FMT, $escaped_search, $modifier . 'u');
                                }
                                $preview_text = preg_replace($highlight_pattern, '<strong style="background:#ffeb3b;">$0</strong>', $preview_text);
                                if (is_serialized($original)) {
                                    $preview_text = '<span style="color:#2271b1; font-size:0.9em;">[' . __('Serialized Match', 'optistate') . ']</span> ' . $preview_text;
                                }
                                $state['preview'][] = ['table' => $table, 'column' => $col, 'id' => $row[$primary_key]??'N/A', 'content' => $preview_text];
                            }
                        }
                        if ($actual_matches > 0) {
                            $found_in_table+= $actual_matches;
                            $state['total_matches']+= $actual_matches;
                        }
                    }
                }
            }
            if ($found_in_table > 0) {
                $state['tables_affected']++;
            }
            $state['current_idx']++;
        }
        delete_transient($transient_key);
        wp_send_json_success(['status' => 'done', 'data' => ['total_matches' => $state['total_matches'], 'tables_affected' => $state['tables_affected'], 'preview' => $state['preview']]]);
    }
}
class OPTISTATE_DB_Wrapper {
    private static $instance = null;
    private $connection = null;
    private $max_retries = 3;
    private $db_config_cache = null;
    private function __construct() {
    }
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    public function get_connection() {
        if ($this->connection !== null) {
            if (@$this->connection->query("SELECT 1") !== false) {
                return $this->connection;
            }
            @$this->connection->close();
            $this->connection = null;
        }
        if ($this->db_config_cache === null) {
            $db_host = DB_HOST;
            $port = null;
            $socket = null;
            if (strpos($db_host, '[') !== false) {
                preg_match('/\[([^\]]+)\](?::(\d+))?/', $db_host, $matches);
                $db_host = isset($matches[1]) ? $matches[1] : $db_host;
                if (isset($matches[2]) && is_numeric($matches[2])) {
                    $port = (int)$matches[2];
                }
            } elseif (strpos($db_host, ':') !== false) {
                list($host, $port_or_socket) = explode(':', $db_host, 2);
                if (is_numeric($port_or_socket)) {
                    $port = (int)$port_or_socket;
                    $db_host = $host;
                } else {
                    $socket = $port_or_socket;
                    $db_host = $host;
                }
            }
            $this->db_config_cache = ['host' => $db_host, 'port' => $port, 'socket' => $socket];
        }
        $db_host = $this->db_config_cache['host'];
        $port = $this->db_config_cache['port'];
        $socket = $this->db_config_cache['socket'];
        $client_flags = defined('MYSQL_CLIENT_FLAGS') ? MYSQL_CLIENT_FLAGS : 0;
        global $wpdb;
        $charset = 'utf8mb4';
        if (isset($wpdb->charset) && !empty($wpdb->charset)) {
            $charset = $wpdb->charset;
        } elseif (defined('DB_CHARSET') && DB_CHARSET) {
            $charset = DB_CHARSET;
        }
        $attempts = 0;
        $last_error = '';
        while ($attempts < $this->max_retries) {
            try {
                $mysqli = mysqli_init();
                if (!$mysqli) {
                    throw new Exception("mysqli_init failed");
                }
                $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 20);
                if (defined('MYSQLI_OPT_NET_CMD_BUFFER_SIZE')) {
                    @$mysqli->options(MYSQLI_OPT_NET_CMD_BUFFER_SIZE, 16384);
                }
                if (defined('MYSQL_SSL_CA') && MYSQL_SSL_CA) {
                    $mysqli->ssl_set(defined('MYSQL_SSL_KEY') ? MYSQL_SSL_KEY : null, defined('MYSQL_SSL_CERT') ? MYSQL_SSL_CERT : null, MYSQL_SSL_CA, defined('MYSQL_SSL_CAPATH') ? MYSQL_SSL_CAPATH : null, defined('MYSQL_SSL_CIPHER') ? MYSQL_SSL_CIPHER : null);
                }
                $connected = @$mysqli->real_connect($db_host, DB_USER, DB_PASSWORD, DB_NAME, $port, $socket, $client_flags);
                if ($connected) {
                    if (!$mysqli->set_charset($charset)) {
                        $mysqli->set_charset('utf8');
                    }
                    $mysqli->query("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'");
                    $mysqli->query("SET SESSION wait_timeout=300");
                    
                    $this->connection = $mysqli;
                    return $this->connection;
                } else {
                    $error_msg = mysqli_connect_error() . " (" . mysqli_connect_errno() . ")";
                    @$mysqli->close();
                    throw new Exception($error_msg);
                }
            }
            catch(Exception $e) {
                $last_error = $e->getMessage();
                $attempts++;
                if ($attempts < $this->max_retries) {
                    sleep(pow(2, $attempts - 1));
                }
            }
        }
        throw new Exception(sprintf(__('Failed to connect to database after %s attempts. Error: %s', 'optistate'), number_format_i18n($this->max_retries), $last_error));
    }
    public function query($query) {
        $connection = $this->get_connection();
        $result = $connection->query($query);
        if ($result === false) {
            $errno = $connection->errno;
            if ($errno === 2006 || $errno === 2013) {
                @$connection->close();
                $this->connection = null;
                $connection = $this->get_connection();
                $result = $connection->query($query);
            }
        }
        return $result;
    }
    public function get_error() {
        return $this->connection ? $this->connection->error : __('No active database connection.', 'optistate');
    }
    public function close() {
        if ($this->connection !== null) {
            @$this->connection->close();
            $this->connection = null;
        }
    }
    public static function reset() {
        if (self::$instance !== null) {
            self::$instance->close();
            self::$instance = null;
        }
    }
}
class OPTISTATE_Backup_Manager {
    private $main_plugin;
    private $backup_dir;
    private $max_backups;
    private $log_file_path;
    private $wp_filesystem;
    private $restore_db = null;
    private static $gzip_path = null;
    private $process_store;
    const SQL_ESCAPE_MAP = ["\\" => "\\\\", "\0" => "\\0", "\n" => "\\n", "\r" => "\\r", "'" => "\\'", '"' => '\\"', "\x1a" => "\\Z"];
    public function __construct(OPTISTATE $main_plugin, $log_file_path = "", $max_backups_setting = 1, $process_store = null) {
        $this->main_plugin = $main_plugin;
        $this->wp_filesystem = $this->main_plugin->init_wp_filesystem();
        $this->process_store = ($process_store instanceof OPTISTATE_Process_Store) ? $process_store : new OPTISTATE_Process_Store();
        $upload_dir = wp_upload_dir();
        $this->backup_dir = trailingslashit($upload_dir['basedir']) . OPTISTATE::BACKUP_DIR_NAME . '/';
        $this->log_file_path = $log_file_path ? : trailingslashit($upload_dir['basedir']) . OPTISTATE::SETTINGS_DIR_NAME . '/' . OPTISTATE::LOG_FILE_NAME;
        $this->create_backup_metadata_table();
        if (!$this->ensure_secure_backup_dir()) {
            add_action("admin_notices", [$this, "display_backup_permission_warning"]);
        }
        $this->max_backups = max(1, min(10, intval($max_backups_setting)));
        add_action("wp_ajax_optistate_create_backup", [$this, "ajax_create_backup"]);
        add_action("wp_ajax_optistate_check_backup_status", [$this, "ajax_check_backup_status"]);
        add_action("optistate_run_manual_backup_chunk", [$this, "run_manual_backup_chunk_worker"], 10, 1);
        add_action("wp_ajax_optistate_delete_backup", [$this, "ajax_delete_backup"]);
        add_action("wp_ajax_optistate_restore_backup", [$this, "ajax_restore_backup"]);
        add_action("wp_ajax_optistate_check_decompression_status", [$this, "ajax_check_decompression_status"]);
        add_action("optistate_run_decompression_chunk", [$this, "run_decompression_chunk_worker"], 10, 1);
        add_action('optistate_daily_cleanup', [$this, 'cleanup_old_temp_files_daily']);
        add_action('init', [$this, 'schedule_daily_cleanup']);
        add_action("init", [$this, "handle_download_backup"]);
        add_action("init", [$this, "protect_backup_directory"]);
        add_action("optistate_run_rollback_cron", [$this, "run_rollback_cron_job"], 10, 1);
        add_action("wp_ajax_optistate_get_restore_status", [$this, "ajax_get_restore_status"]);
        add_action("optistate_run_safety_backup_chunk", [$this, "run_safety_backup_chunk_worker"], 10, 1);
        add_action("optistate_run_restore_init", [$this, "run_restore_init_worker"], 10, 1);
        add_action("optistate_run_restore_chunk", [$this, "run_restore_chunk_worker"], 10, 1);
        add_action("wp_ajax_optistate_check_manual_backup_on_load", [$this, "ajax_check_manual_backup_on_load"]);
        add_action("admin_notices", [$this, "display_rollback_status_notice"]);
        add_action("optistate_run_silent_backup_chunk", [$this, "run_silent_backup_chunk_worker"], 10, 1);
        if (get_option('optistate_maintenance_mode_active')) {
            add_action('template_redirect', [$this, 'show_maintenance_page_for_visitors'], 1);
        }
    }
    private function set_process_state($key, $value, $expiration = 0) {
        return $this->process_store->set($key, $value, $expiration);
    }
    private function get_process_state($key) {
        return $this->process_store->get($key);
    }
    private function delete_process_state($key) {
        return $this->process_store->delete($key);
    }
    private function _get_gzip_path() {
        static $gzip_path = 'uninitialized';
        if ($gzip_path !== 'uninitialized') {
            return $gzip_path;
        }
        $cached_path = get_transient('optistate_gzip_binary_path');
        if ($cached_path !== false) {
            $gzip_path = $cached_path;
            return $gzip_path;
        }
        if (!function_exists('exec') || @ini_get('safe_mode') || in_array('exec', array_map('trim', explode(',', @ini_get('disable_functions'))))) {
            return $gzip_path = false;
        }
        @exec('which gzip 2>/dev/null', $output, $return_var);
        if ($return_var === 0 && isset($output[0]) && @is_executable(trim($output[0]))) {
            $gzip_path = trim($output[0]);
            set_transient('optistate_gzip_binary_path', $gzip_path, MONTH_IN_SECONDS);
            return $gzip_path;
        }
        foreach (['/bin/gzip', '/usr/bin/gzip', '/usr/local/bin/gzip'] as $path) {
            if (@is_executable($path)) {
                $gzip_path = $path;
                set_transient('optistate_gzip_binary_path', $gzip_path, MONTH_IN_SECONDS);
                return $gzip_path;
            }
        }
        return $gzip_path = false;
    }
    private function cleanup_all_temp_sql_files($specific_file = null) {
        if (!$this->wp_filesystem) {
            return false;
        }
        $upload_dir = wp_upload_dir();
        $temp_dir = trailingslashit($upload_dir['basedir']) . OPTISTATE::TEMP_DIR_NAME . '/';
        if (!$this->wp_filesystem->is_dir($temp_dir)) {
            return true;
        }
        if ($specific_file !== null) {
            $filepath = $temp_dir . basename($specific_file);
            if ($this->wp_filesystem->exists($filepath)) {
                return $this->wp_filesystem->delete($filepath);
            }
            return true;
        }
        $files = $this->wp_filesystem->dirlist($temp_dir);
        if (empty($files)) {
            return true;
        }
        $deleted_count = 0;
        foreach ($files as $filename => $fileinfo) {
            if ($fileinfo['type'] === 'f' && preg_match('/\.sql$/i', $filename)) {
                $filepath = $temp_dir . $filename;
                if ($this->wp_filesystem->delete($filepath)) {
                    $deleted_count++;
                    if (preg_match('/(restore-temp-[a-f0-9]{32}|decompressed-[a-f0-9]{32})\.sql/', $filename, $matches)) {
                        $this->delete_process_state('optistate_temp_restore_' . $filename);
                    }
                }
            }
        }
        return true;
    }
    private function get_restore_db() {
        $this->restore_db = OPTISTATE_DB_Wrapper::get_instance()->get_connection();
        return $this->restore_db;
    }
    private function close_restore_db() {
        OPTISTATE_DB_Wrapper::get_instance()->close();
        $this->restore_db = null;
    }
    private function db_query($query) {
        $db = $this->get_restore_db();
        return $db->query($query);
    }
    private function db_get_last_error() {
        return OPTISTATE_DB_Wrapper::get_instance()->get_error();
    }
    private function db_get_var($query) {
        $db = $this->get_restore_db();
        $result = $db->query($query);
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_row();
            $result->free();
            return $row[0];
        }
        return null;
    }
    public function schedule_daily_cleanup() {
        if (!wp_next_scheduled('optistate_daily_cleanup')) {
            wp_schedule_event(time(), 'hourly', 'optistate_daily_cleanup');
        }
    }
    public function trigger_async_rollback($master_restore_key = null) {
        wp_schedule_single_event(time(), 'optistate_run_rollback_cron', [$master_restore_key]);
    }
    private function get_adaptive_worker_config() {
        static $config_cache = null;
        if ($config_cache !== null) {
            return $config_cache;
        }
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $max_execution_time = (int)ini_get('max_execution_time');
        if ($max_execution_time <= 0) {
            $max_execution_time = 60;
        }
        $cpu_cores = 1;
        if ($this->is_shell_exec_available()) {
            if (PHP_OS_FAMILY === 'Windows') {
                $cpu_info = @shell_exec('wmic cpu get NumberOfLogicalProcessors 2>NUL');
                if ($cpu_info) {
                    preg_match_all('/\d+/', $cpu_info, $matches);
                    if (!empty($matches[0])) {
                        $cpu_cores = max(1, (int)$matches[0][0]);
                    }
                }
            } else {
                $cpu_info = @shell_exec('nproc 2>/dev/null');
                if ($cpu_info && is_numeric(trim($cpu_info))) {
                    $cpu_cores = max(1, (int)trim($cpu_info));
                } else {
                    $cpu_info = @shell_exec('grep -c ^processor /proc/cpuinfo 2>/dev/null');
                    if ($cpu_info && is_numeric(trim($cpu_info))) {
                        $cpu_cores = max(1, (int)trim($cpu_info));
                    }
                }
            }
        }
        $memory_score = min(100, ($memory_limit / (512 * 1024 * 1024)) * 50);
        $time_score = min(100, ($max_execution_time / 60) * 30);
        $cpu_score = min(100, ($cpu_cores / 4) * 20);
        $performance_score = $memory_score + $time_score + $cpu_score;
        if ($performance_score >= 70) {
            $config = ['chunks_per_run' => 5, 'reschedule_delay' => 2, 'max_worker_time' => min(40, (int)($max_execution_time * 0.85)), 'tier' => 'high'];
        } elseif ($performance_score >= 40) {
            $config = ['chunks_per_run' => 3, 'reschedule_delay' => 3, 'max_worker_time' => min(25, (int)($max_execution_time * 0.80)), 'tier' => 'medium'];
        } else {
            $config = ['chunks_per_run' => 2, 'reschedule_delay' => 4, 'max_worker_time' => min(15, (int)($max_execution_time * 0.75)), 'tier' => 'low'];
        }
        $config['chunks_per_run'] = max(1, $config['chunks_per_run']);
        $config['reschedule_delay'] = max(1, $config['reschedule_delay']);
        $config['max_worker_time'] = max(10, $config['max_worker_time']);
        $config_cache = $config;
        return $config;
    }
    private function is_shell_exec_available() {
        if (!function_exists('shell_exec')) {
            return false;
        }
        if (@ini_get('safe_mode')) {
            return false;
        }
        $disabled_functions = @ini_get('disable_functions');
        if (!empty($disabled_functions)) {
            $disabled_array = array_map('trim', explode(',', $disabled_functions));
            if (in_array('shell_exec', $disabled_array, true)) {
                return false;
            }
        }
        if (extension_loaded('suhosin')) {
            $suhosin_disabled = @ini_get('suhosin.executor.func.blacklist');
            if (!empty($suhosin_disabled)) {
                $suhosin_array = array_map('trim', explode(',', $suhosin_disabled));
                if (in_array('shell_exec', $suhosin_array, true)) {
                    return false;
                }
            }
        }
        return true;
    }
    private function get_chunks_per_run() {
        $config = $this->get_adaptive_worker_config();
        return $config['chunks_per_run'];
    }
    private function get_reschedule_delay() {
        $config = $this->get_adaptive_worker_config();
        return $config['reschedule_delay'];
    }
    private function get_max_worker_time() {
        $config = $this->get_adaptive_worker_config();
        return $config['max_worker_time'];
    }
    public function run_rollback_cron_job($master_restore_key = null) {
        $class_instance = $this;
        register_shutdown_function(function () use ($class_instance, $master_restore_key) {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                if (method_exists($class_instance, 'log_optimization')) {
                    $class_instance->main_plugin->log_optimization("manual", "❌ " . __("CRITICAL: Fatal error during rollback. Please check your site to ensure it is functioning correctly.", "optistate") . " Error: " . $error['message'], "");
                }
                $class_instance->set_process_state('optistate_rollback_status', 'failed', HOUR_IN_SECONDS);
                $class_instance->deactivate_maintenance_mode();
                $class_instance->delete_process_state('optistate_restore_in_progress');
                $class_instance->cleanup_old_tables_after_restore();
                if ($master_restore_key) {
                    $master_state = $class_instance->get_process_state($master_restore_key);
                    if ($master_state) {
                        $master_state['status'] = 'error';
                        $master_state['message'] = esc_html__('Error: Database restore failed! A safety rollback was triggered and completed successfully. Your site is safe.', 'optistate');
                        $class_instance->set_process_state($master_restore_key, $master_state, 10 * MINUTE_IN_SECONDS);
                    }
                }
            }
        });
        $master_state = $master_restore_key ? $this->get_process_state($master_restore_key) : false;
        $instant_rollback_tables = $this->get_process_state('optistate_instant_rollback_tables');
        if ($instant_rollback_tables === false || !is_array($instant_rollback_tables)) {
            $log_filename = $this->get_process_state('optistate_last_restore_filename') ? : 'unknown_file';
            if ($instant_rollback_tables === false) {
                $this->deactivate_maintenance_mode();
                $this->cleanup_old_tables_after_restore();
                $this->delete_process_state('optistate_restore_in_progress');
                $this->delete_process_state('optistate_rollback_status');
                if ($master_state && $master_state['status'] === 'rollback_starting') {
                    $master_state['status'] = 'error';
                    $master_state['message'] = esc_html__('Restore failed before main data swap. No rollback needed.', 'optistate');
                    $this->set_process_state($master_restore_key, $master_state, 10 * MINUTE_IN_SECONDS);
                }
            }
            return;
        }
        $log_filename = $this->get_process_state('optistate_last_restore_filename') ? : 'unknown_file';
        if (method_exists($this->main_plugin, 'log_optimization')) {
            $this->main_plugin->log_optimization("manual", "⏪ " . __("Attempting INSTANT rollback via cron...", "optistate"), "");
        }
        $db = null;
        try {
            $db = OPTISTATE_DB_Wrapper::get_instance()->get_connection();
            $db->query("SET FOREIGN_KEY_CHECKS = 0");
            $db->query("SET AUTOCOMMIT = 0");
            $db->query("START TRANSACTION");
            foreach ($instant_rollback_tables as $original_table => $old_table) {
                $db->query("DROP TABLE IF EXISTS `" . esc_sql($original_table) . "`");
                $db->query("RENAME TABLE `" . esc_sql($old_table) . "` TO `" . esc_sql($original_table) . "`");
            }
            $db->query("COMMIT");
            $db->query("SET FOREIGN_KEY_CHECKS = 1");
            $db->query("SET AUTOCOMMIT = 1");
            OPTISTATE_DB_Wrapper::get_instance()->close();
            $db = null;
            wp_cache_flush();
            $this->delete_process_state('optistate_instant_rollback_tables');
            $this->set_process_state('optistate_rollback_status', 'success', HOUR_IN_SECONDS);
            $this->deactivate_maintenance_mode();
            $this->cleanup_old_tables_after_restore();
            $this->delete_process_state('optistate_restore_in_progress');
            $this->cleanup_all_temp_sql_files();
            if (method_exists($this->main_plugin, 'log_optimization')) {
                $this->main_plugin->log_optimization("manual", "✅ " . __("INSTANT Rollback Succeeded.", "optistate"), "");
            }
            if ($master_state) {
                $master_state['status'] = 'rollback_done';
                $master_state['message'] = esc_html__('Restore failed, but safety rollback succeeded! Your site is safe.', 'optistate');
                $this->set_process_state($master_restore_key, $master_state, 10 * MINUTE_IN_SECONDS);
            }
            return;
        }
        catch(Exception $e) {
            if ($db) {
                $db->query("ROLLBACK");
                $db->query("SET FOREIGN_KEY_CHECKS = 1");
                $db->query("SET AUTOCOMMIT = 1");
                OPTISTATE_DB_Wrapper::get_instance()->close();
                $db = null;
            }
            if (method_exists($this->main_plugin, 'log_optimization')) {
                $this->main_plugin->log_optimization("manual", "❌ " . __("CRITICAL: Instant Rollback FAILED.", "optistate") . " Error: " . $e->getMessage(), "");
            }
            $this->set_process_state('optistate_rollback_status', 'failed', HOUR_IN_SECONDS);
            $this->deactivate_maintenance_mode();
            $this->cleanup_old_tables_after_restore();
            $this->delete_process_state('optistate_restore_in_progress');
            if ($master_state) {
                $master_state['status'] = 'error';
                $master_state['message'] = esc_html__('CRITICAL: Restore FAILED and Rollback FAILED. Site may be broken.', 'optistate');
                $this->set_process_state($master_restore_key, $master_state, 10 * MINUTE_IN_SECONDS);
            }
        }
    }
    private function check_sufficient_disk_space($backup_filepath) {
        if (!$this->wp_filesystem) {
            return ['success' => false, 'message' => esc_html__('Filesystem not initialized for space check.', 'optistate')];
        }
        $free_space = @disk_free_space(WP_CONTENT_DIR);
        if ($free_space === false) {
            $this->log_failed_restore_operation('SpaceCheck', 'disk_free_space() is disabled. Skipping check.');
            return ['success' => true];
        }
        if (!$this->wp_filesystem->exists($backup_filepath)) {
            return ['success' => false, 'message' => esc_html__('Backup file not found for space check.', 'optistate')];
        }
        $backup_file_size = $this->wp_filesystem->size($backup_filepath);
        $is_compressed = preg_match('/\.gz$/i', $backup_filepath);
        if ($is_compressed) {
            $estimated_decompressed_size = $backup_file_size * 5;
            if ($estimated_decompressed_size > ($free_space * 0.9)) {
                $message = sprintf(__('Insufficient Disk Space: Restore Aborted!<br>Estimated Decompressed Size: %s<br>Available Space: %s', 'optistate'),
                    size_format($estimated_decompressed_size, 2),
                    size_format($free_space, 2)
                );
                return ['success' => false, 'message' => $message];
            }
        }
        $current_db_size = 0;
        $stats = get_transient(OPTISTATE::STATS_TRANSIENT);
        if ($stats && isset($stats['total_db_size_bytes'])) {
            $current_db_size = (float)$stats['total_db_size_bytes'];
        }
        if ($current_db_size <= 0) {
            $current_db_size = $this->main_plugin->get_total_database_size(false);
        }
        $estimated_backup_size = $is_compressed ? ($backup_file_size * 5) : ($backup_file_size * 1.1);
        $base_size = max($current_db_size, $estimated_backup_size);
        $required_space = $base_size * 2.5;
        $safety_buffer = 100 * 1024 * 1024;
        if ($free_space < ($required_space + $safety_buffer)) {
            $message = sprintf(esc_html__('Insufficient Disk Space: Restore Aborted!', 'optistate') . '<br>' . esc_html__('Available: %s', 'optistate') . '<br>' . esc_html__('Required (Est): %s', 'optistate'), size_format($free_space, 2), size_format($required_space + $safety_buffer, 2));
            return ['success' => false, 'message' => $message];
        }
        return ['success' => true];
    }
    private function is_safe_restore_query($query) {
        static $security_disabled = null;
        if ($security_disabled === null) {
            $settings = $this->main_plugin->get_persistent_settings();
            $security_disabled = !empty($settings['disable_restore_security']) && $settings['disable_restore_security'] === true;
        }
        if ($security_disabled) {
            return !empty(trim($query));
        }
        $trim_query = trim($query);
        if ($trim_query === '') return true;
        if (stripos($trim_query, 'INSERT') === 0) {
            return true;
        }
        $start_marker = substr($trim_query, 0, 150);
        if (!preg_match('/^\s*(DROP\s+TABLE|CREATE\s+TABLE|ALTER\s+TABLE|LOCK\s+TABLES|UNLOCK\s+TABLES|START\s+TRANSACTION|COMMIT|SET\s+(FOREIGN_KEY_CHECKS|UNIQUE_CHECKS|AUTOCOMMIT|SQL_MODE|TIME_ZONE|CHARACTER_SET|NAMES|SESSION)|(\/\*!))/i', $start_marker)) {
            return false;
        }
        if (preg_match('/(CREATE\s+(FUNCTION|TRIGGER|PROCEDURE|EVENT|USER)|GRANT\s+|REVOKE\s+|INTO\s+(OUTFILE|DUMPFILE)|LOAD_FILE|SET\s+GLOBAL|UNION\s+SELECT)/i', $trim_query)) {
            return false;
        }
        return true;
    }
    private function get_adaptive_batch_limit($table_name, $is_offset_method = false) {
        global $wpdb;
        static $row_length_cache = [];
        $target_size = 2 * 1024 * 1024;
        if (isset($row_length_cache[$table_name])) {
            $row_length = $row_length_cache[$table_name];
        } else {
            $row_length = $wpdb->get_var($wpdb->prepare("SELECT AVG_ROW_LENGTH FROM information_schema.tables WHERE table_schema = %s AND table_name = %s", DB_NAME, $table_name));
            $row_length_cache[$table_name] = $row_length;
        }
        if (!$row_length || $row_length < 1) {
            $row_length = 1024;
        }
        $limit = floor($target_size / $row_length);
        $limit = (int)max(10, min(2000, $limit));
        if ($is_offset_method) {
            $limit = (int)ceil($limit / 2);
            $limit = min($limit, 500);
        }
        return $limit;
    }
    public function handle_download_backup() {
        if (!isset($_GET["action"]) || $_GET["action"] !== "optistate_backup_download") {
            return;
        }
        if (!isset($_GET["file"]) || !isset($_GET["_wpnonce"])) {
            wp_die(esc_html__("Invalid download request.", "optistate"));
        }
        if (!wp_verify_nonce($_GET["_wpnonce"], "optistate_backup_nonce")) {
            wp_die(esc_html__("Security verification failed.", "optistate"));
        }
        $this->main_plugin->check_user_access();
        if (!$this->main_plugin->check_rate_limit("download_backup", 5)) {
            wp_die(esc_html__("🕔 Please wait a few seconds before downloading again.", "optistate"));
        }
        $filename = isset($_GET["file"]) ? basename(wp_unslash($_GET["file"])) : '';
        if (!preg_match('/\.sql(\.gz)?$/i', $filename)) {
            wp_die(esc_html__("Security violation: Invalid file type.", "optistate"));
        }
        if (empty($filename)) {
            wp_die(esc_html__("Invalid filename.", "optistate"));
        }
        $filepath = $this->backup_dir . $filename;
        $normalized_path = wp_normalize_path($filepath);
        $normalized_dir = wp_normalize_path($this->backup_dir);
        if (strpos($normalized_path, $normalized_dir) !== 0) {
            wp_die(esc_html__("Invalid file path.", "optistate"));
        }
        if (!$this->wp_filesystem->exists($filepath)) {
            wp_die(esc_html__("File not found.", "optistate"));
        }
        $file_size = $this->wp_filesystem->size($filepath);
        @set_time_limit(0);
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', 1);
        }
        @ini_set('zlib.output_compression', 'Off');
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        $content_type = preg_match('/\.gz$/i', $filename) ? 'application/gzip' : 'application/sql';
        header("Content-Type: " . $content_type);
        header("Content-Description: File Transfer");
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Pragma: public");
        header("Content-Length: " . $file_size);
        header("Accept-Ranges: bytes");
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: DENY");
        $offset = 0;
        if (isset($_SERVER['HTTP_RANGE'])) {
            if (preg_match('/bytes=(\d+)-(\d+)?/', $_SERVER['HTTP_RANGE'], $matches)) {
                $offset = intval($matches[1]);
                $end = isset($matches[2]) ? intval($matches[2]) : $file_size - 1;
                header('HTTP/1.1 206 Partial Content');
                header("Content-Range: bytes $offset-$end/$file_size");
                header("Content-Length: " . ($end - $offset + 1));
            }
        }
        $handle = @fopen($filepath, 'rb');
        if ($handle === false) {
            wp_die(esc_html__("Cannot open file.", "optistate"));
        }
        if ($offset > 0) {
            fseek($handle, $offset);
        }
        $chunk_size = 8 * 1024 * 1024;
        $max_send_time = 300;
        $start_time = time();
        while (!feof($handle) && !connection_aborted()) {
            if ((time() - $start_time) > $max_send_time) {
                @set_time_limit(300);
                $start_time = time();
            }
            $data = fread($handle, $chunk_size);
            if ($data === false) {
                break;
            }
            echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary/SQL file content
            if (ob_get_length() > 0) {
                ob_flush();
            }
            flush();
        }
        fclose($handle);
        exit();
    }
    private function _initiate_chunked_backup($filename, $extra_data = []) {
        global $wpdb;
        if (!preg_match('/\.sql\.gz$/i', $filename)) {
            $filename = preg_replace('/\.sql$/i', '', $filename) . '.sql.gz';
        }
        $filename = preg_replace('/(\.sql)?(\.gz)?$/i', '', $filename) . '.sql.gz';
        $filepath = $this->backup_dir . $filename;
        $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
        if (empty($tables)) {
            throw new Exception(esc_html__("No database tables found.", "optistate"));
        }
        $all_tables = wp_list_pluck($tables, 0);
        $exclude_table = $this->process_store->get_table_name();
        $metadata_table = $wpdb->prefix . 'optistate_backup_metadata';
        $all_tables = array_filter($all_tables, function ($table) use ($exclude_table, $metadata_table) {
            if ($table === $exclude_table) {
                return false;
            }
            if ($table === $metadata_table) {
                return false;
            }
            if (strpos($table, 'optistate_old_') === 0) {
                return false;
            }
            return true;
        });
        $all_tables = array_values($all_tables);
        $transient_key = 'optistate_backup_' . bin2hex(random_bytes(14));
        $state = ['filepath' => $filepath, 'filename' => $filename, 'all_tables' => $all_tables, 'total_tables' => count($all_tables), 'current_table_index' => 0, 'current_table_data_offset' => 0, 'primary_key' => null, 'status' => 'init', 'start_time' => time(), 'checksum' => '', 'user_id' => get_current_user_id() ];
        if (!empty($extra_data) && is_array($extra_data)) {
            $state = array_merge($state, $extra_data);
        }
        $this->set_process_state($transient_key, $state, DAY_IN_SECONDS);
        return $transient_key;
    }
    private function _perform_backup_chunk($state) {
        global $wpdb;
        $chunk_start_time = time();
        $max_chunk_time = $this->get_max_worker_time();
        $original_time_limit = ini_get('max_execution_time');
        $needed_time = $max_chunk_time + 60;
        @set_time_limit($needed_time);
        try {
            $filepath = $state['filepath'];
            $gz_mode = ($state['status'] === 'init') ? 'wb6f' : 'ab6f';
            $handle = @gzopen($filepath, $gz_mode);
            if (!$handle) {
                throw new Exception(esc_html__("Failed to open backup file for writing.", "optistate"));
            }
            $use_cli = (defined('WP_CLI') && WP_CLI) && php_sapi_name() === 'cli';
            if ($use_cli && $state['status'] === 'init') {
                try {
                    $current_local_time = current_time("Y-m-d H:i:s", false);
                    $header = "-- WordPress Database Backup\n";
                    $header.= "-- Created: " . $current_local_time . "\n";
                    $header.= "-- Database: " . DB_NAME . "\n";
                    $header.= "-- PHP Version: " . PHP_VERSION . "\n";
                    $header.= "-- WordPress Version: " . get_bloginfo("version") . "\n";
                    $header.= "-- ------------------------------------------------------\n\n";
                    $header.= "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n";
                    $header.= "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n";
                    $header.= "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n";
                    $header.= "/*!40101 SET NAMES utf8mb4 */;\n";
                    $header.= "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;\n";
                    $header.= "/*!40103 SET TIME_ZONE='+00:00' */;\n";
                    $header.= "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;\n";
                    $header.= "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n";
                    $header.= "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n";
                    $header.= "/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;\n\n";
                    $header.= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
                    @gzwrite($handle, $header);
                    $exclude_table = $this->process_store->get_table_name();
                    $metadata_table = $wpdb->prefix . 'optistate_backup_metadata';
                    foreach ($state['all_tables'] as $table_name) {
                        if ($table_name === $exclude_table || $table_name === $metadata_table || strpos($table_name, 'optistate_old_') === 0) {
                            continue;
                        }
                        $escaped_table = esc_sql($table_name);
                        $table_structure = "-- ------------------------------------------------------\n";
                        $table_structure.= "-- Table structure for `{$escaped_table}`\n";
                        $table_structure.= "-- ------------------------------------------------------\n\n";
                        $table_structure.= "DROP TABLE IF EXISTS `{$escaped_table}`;\n";
                        $create_table = $wpdb->get_row("SHOW CREATE TABLE `{$escaped_table}`", ARRAY_N);
                        if ($create_table && isset($create_table[1])) {
                            $table_structure.= $create_table[1] . ";\n\n";
                        }
                        @gzwrite($handle, $table_structure);
                        $columns = $wpdb->get_results("SHOW COLUMNS FROM `{$escaped_table}`", ARRAY_A);
                        $primary_key = null;
                        foreach ($columns as $column) {
                            if ($column['Key'] === 'PRI' || $column['Extra'] === 'auto_increment') {
                                if (preg_match('/int|decimal|float|double|real|numeric/i', $column['Type'])) {
                                    $primary_key = $column['Field'];
                                    break;
                                }
                            }
                        }
                        $batch_size = 1000;
                        $offset = 0;
                        while (true) {
                            if ($primary_key) {
                                $safe_primary_key = "`" . str_replace("`", "``", $primary_key) . "`";
                                $query = $wpdb->prepare("SELECT * FROM `{$escaped_table}` WHERE {$safe_primary_key} > %s ORDER BY {$safe_primary_key} ASC LIMIT %d", $offset, $batch_size);
                                $rows = $wpdb->get_results($query, ARRAY_A);
                            } else {
                                $query = $wpdb->prepare("SELECT * FROM `{$escaped_table}` LIMIT %d OFFSET %d", $batch_size, $offset);
                                $rows = $wpdb->get_results($query, ARRAY_A);
                            }
                            if (empty($rows)) {
                                break;
                            }
                            $insert_header = "INSERT INTO `{$escaped_table}` VALUES ";
                            $values_buffer = [];
                            foreach ($rows as $row) {
                                $values_buffer[] = $this->_format_row_for_sql($row);
                            }
                            if (!empty($values_buffer)) {
                                @gzwrite($handle, $insert_header . implode(",", $values_buffer) . ";\n");
                                $values_buffer = [];
                                if (function_exists('gc_collect_cycles')) {
                                    gc_collect_cycles();
                                }
                            }
                            if ($primary_key) {
                                $last_row = end($rows);
                                $offset = $last_row[$primary_key];
                            } else {
                                $offset+= count($rows);
                            }
                            if (count($rows) < $batch_size) {
                                break;
                            }
                        }
                        @gzwrite($handle, "\n-- ------------------------------------------------------\n\n");
                    }
                    $footer = "SET FOREIGN_KEY_CHECKS = 1;\n\n";
                    $footer.= "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;\n";
                    $footer.= "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n";
                    $footer.= "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n";
                    $footer.= "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;\n";
                    $footer.= "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n";
                    $footer.= "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n";
                    $footer.= "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n";
                    $footer.= "/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;\n";
                    @gzwrite($handle, $footer);
                    @gzclose($handle);
                    $state['status'] = 'done';
                    return ['status' => 'done', 'state' => $state];
                }
                catch(Exception $e) {
                    $use_cli = false;
                    @gzclose($handle);
                    $handle = @fopen($filepath, 'a');
                    if (!$handle) {
                        throw new Exception(esc_html__("Failed to reopen backup file after CLI failure.", "optistate"));
                    }
                }
            }
            while ((time() - $chunk_start_time) < $max_chunk_time) {
                switch ($state['status']) {
                    case 'init':
                        if ($this->wp_filesystem->exists($filepath)) {
                            $this->wp_filesystem->chmod($filepath, 0600);
                        }
                        $current_local_time = current_time("Y-m-d H:i:s", false);
                        $header = "-- WordPress Database Backup\n";
                        $header.= "-- Created: " . $current_local_time . "\n";
                        $header.= "-- Database: " . DB_NAME . "\n";
                        $header.= "-- PHP Version: " . PHP_VERSION . "\n";
                        $header.= "-- WordPress Version: " . get_bloginfo("version") . "\n";
                        $header.= "-- ------------------------------------------------------\n\n";
                        $header.= "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n";
                        $header.= "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n";
                        $header.= "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n";
                        $header.= "/*!40101 SET NAMES utf8mb4 */;\n";
                        $header.= "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;\n";
                        $header.= "/*!40103 SET TIME_ZONE='+00:00' */;\n";
                        $header.= "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;\n";
                        $header.= "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n";
                        $header.= "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n";
                        $header.= "/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;\n\n";
                        $header.= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
                        @gzwrite($handle, $header);
                        $state['status'] = 'tables';
                    break;
                    case 'tables':
                        if ($state['current_table_index'] >= $state['total_tables']) {
                            $state['status'] = 'footer';
                            break;
                        }
                        $table_name = $state['all_tables'][$state['current_table_index']];
                        $escaped_table = esc_sql($table_name);
                        $table_structure = "-- ------------------------------------------------------\n";
                        $table_structure.= "-- Table structure for `{$escaped_table}`\n";
                        $table_structure.= "-- ------------------------------------------------------\n\n";
                        $table_structure.= "DROP TABLE IF EXISTS `{$escaped_table}`;\n";
                        $create_table = $wpdb->get_row("SHOW CREATE TABLE `{$escaped_table}`", ARRAY_N);
                        if ($create_table && isset($create_table[1])) {
                            $table_structure.= $create_table[1] . ";\n\n";
                        } else {
                            throw new Exception(sprintf(__('Failed to get structure for table: %s', 'optistate'), $table_name));
                        }
                        @gzwrite($handle, $table_structure);
                        $columns = $wpdb->get_results("SHOW COLUMNS FROM `{$escaped_table}`", ARRAY_A);
                        $state['primary_key'] = null;
                        foreach ($columns as $column) {
                            if ($column['Key'] === 'PRI' || $column['Extra'] === 'auto_increment') {
                                if (preg_match('/int|decimal|float|double|real|numeric/i', $column['Type'])) {
                                    $state['primary_key'] = $column['Field'];
                                    break;
                                }
                            }
                        }
                        if (!$state['primary_key']) {
                            $indexes = $wpdb->get_results("SHOW INDEX FROM `{$escaped_table}` WHERE Non_unique = 0", ARRAY_A);
                            if (!empty($indexes)) {
                                foreach ($indexes as $index) {
                                    foreach ($columns as $col) {
                                        if ($col['Field'] === $index['Column_name']) {
                                            if (preg_match('/int|decimal|float|double|real|numeric/i', $col['Type'])) {
                                                $state['primary_key'] = $index['Column_name'];
                                                break 2;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        $state['status'] = 'data';
                        $state['current_table_data_offset'] = 0;
                    break;
                    case 'data':
                        $table_name = $state['all_tables'][$state['current_table_index']];
                        $result = $this->_backup_table_data_chunked($table_name, $state['primary_key'], $state['current_table_data_offset'], $handle, $chunk_start_time, $max_chunk_time);
                        if ($result['status'] === 'done') {
                            @gzwrite($handle, "\n-- ------------------------------------------------------\n\n");
                            $state['current_table_index']++;
                            $state['status'] = 'tables';
                        } else {
                            $state['current_table_data_offset'] = $result['offset'];
                        }
                    break;
                    case 'footer':
                        $footer = "SET FOREIGN_KEY_CHECKS = 1;\n\n";
                        $footer.= "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;\n";
                        $footer.= "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n";
                        $footer.= "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n";
                        $footer.= "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;\n";
                        $footer.= "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n";
                        $footer.= "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n";
                        $footer.= "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n";
                        $footer.= "/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;\n";
                        @gzwrite($handle, $footer);
                        $state['status'] = 'done';
                    break 2;
                }
            }
            if (is_resource($handle)) {
                @gzclose($handle);
            }
            $status = ($state['status'] === 'done') ? 'done' : 'running';
            return ['status' => $status, 'state' => $state];
        }
        catch(Exception $e) {
            if (isset($handle) && is_resource($handle)) {
                @gzclose($handle);
            }
            throw $e;
        }
        finally {
            if (is_resource($handle)) {
                @gzclose($handle);
            }
            @set_time_limit($original_time_limit);
        }
    }
    private function _backup_table_data_chunked($table_name, $primary_key, $offset, $file_handle, $start_time = null, $max_duration = 20) {
        global $wpdb;
        if ($start_time === null) {
            $start_time = time();
        }
        $memory_limit_str = ini_get('memory_limit');
        $memory_limit_bytes = wp_convert_hr_to_bytes($memory_limit_str);
        $unsafe_memory_threshold = $memory_limit_bytes > 0 ? ($memory_limit_bytes * 0.85) : (256 * 1024 * 1024);
        $is_offset_method = empty($primary_key);
        $batch_size = $this->get_adaptive_batch_limit($table_name, $is_offset_method);
        $safe_table = "`" . str_replace("`", "``", $table_name) . "`";
        $offset_batch_size = min($batch_size * 3, 1500);
        if ($primary_key) {
            $safe_primary_key = "`" . str_replace("`", "``", $primary_key) . "`";
            $query = $wpdb->prepare("SELECT * FROM {$safe_table} WHERE {$safe_primary_key} > %s ORDER BY {$safe_primary_key} ASC LIMIT %d", $offset, $batch_size);
        } else {
            $query = $wpdb->prepare("SELECT * FROM {$safe_table} LIMIT %d OFFSET %d", $offset_batch_size, $offset);
        }
        $use_mysqli_unbuffered = false;
        $result = null;
        $rows = [];
        $dbh = (isset($wpdb->dbh) && $wpdb->dbh instanceof mysqli) ? $wpdb->dbh : null;
        if ($dbh && $wpdb->use_mysqli) {
            $use_mysqli_unbuffered = true;
            $result = mysqli_query($dbh, $query, MYSQLI_USE_RESULT);
        } else {
            $rows = $wpdb->get_results($query, ARRAY_A);
        }
        if (($use_mysqli_unbuffered && !$result) || (!$use_mysqli_unbuffered && empty($rows))) {
            return ['status' => 'done', 'offset' => 0];
        }
        $row_count = 0;
        $insert_header = "INSERT INTO {$safe_table} VALUES ";
        $current_buffer = [];
        $current_buffer_len = 0;
        $max_buffer_len = 2 * 1024 * 1024;
        $last_pk_value = $offset;
        $process_row = function ($row) use (&$current_buffer, &$current_buffer_len, &$row_count, $primary_key, $insert_header, $file_handle, $max_buffer_len, &$last_pk_value, $offset, $unsafe_memory_threshold, $dbh) {
            $row_count++;
            if ($primary_key && isset($row[$primary_key])) {
                $last_pk_value = $row[$primary_key];
            } else {
                $last_pk_value = $offset + $row_count;
            }
            $row_string = $this->_format_row_for_sql($row, $dbh);
            $row_len = strlen($row_string);
            if (($current_buffer_len + $row_len) > $max_buffer_len) {
                if (!empty($current_buffer)) {
                    $write_res = @gzwrite($file_handle, $insert_header . implode(",", $current_buffer) . ";\n");
                    if ($write_res === false) throw new Exception('Failed to write backup data');
                    $current_buffer = [];
                    $current_buffer_len = 0;
                }
            }
            $current_buffer[] = $row_string;
            $current_buffer_len+= $row_len;
            $memory_pressure = false;
            if ($row_count % 1000 === 0) {
                $memory_pressure = (memory_get_usage(true) > $unsafe_memory_threshold);
            }
            if ($current_buffer_len > $max_buffer_len || count($current_buffer) > 500 || $memory_pressure) {
                $write_res = @gzwrite($file_handle, $insert_header . implode(",", $current_buffer) . ";\n");
                if ($write_res === false) throw new Exception('Failed to write backup data');
                $current_buffer = [];
                $current_buffer_len = 0;
                if ($memory_pressure && function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        };
        if ($use_mysqli_unbuffered) {
            while ($row = mysqli_fetch_assoc($result)) {
                $process_row($row);
                if ($row_count % 50 === 0 && (time() - $start_time) >= $max_duration) {
                    if (!empty($current_buffer)) {
                        @gzwrite($file_handle, $insert_header . implode(",", $current_buffer) . ";\n");
                    }
                    mysqli_free_result($result);
                    return ['status' => 'running', 'offset' => $last_pk_value];
                }
            }
            mysqli_free_result($result);
        } else {
            foreach ($rows as $row) {
                $process_row($row);
                if ($row_count % 50 === 0 && (time() - $start_time) >= $max_duration) {
                    if (!empty($current_buffer)) {
                        @gzwrite($file_handle, $insert_header . implode(",", $current_buffer) . ";\n");
                    }
                    return ['status' => 'running', 'offset' => $last_pk_value];
                }
            }
        }
        if (!empty($current_buffer)) {
            $res = @gzwrite($file_handle, $insert_header . implode(",", $current_buffer) . ";\n");
            if ($res === false) throw new Exception('Failed to write backup data');
        }
        $has_more = ($row_count >= ($is_offset_method ? ($offset_batch_size - 1) : $batch_size));
        return ['status' => $has_more ? 'running' : 'done', 'offset' => $last_pk_value];
    }
    public function display_rollback_status_notice() {
        $status = $this->get_process_state('optistate_rollback_status');
        if (!$status) {
            return;
        }
        $this->delete_process_state('optistate_rollback_status');
        if ($status === 'success') {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<h3>' . esc_html__("WP Optimal State: Rollback Succeeded", "optistate") . '</h3>';
            echo '<p>' . esc_html__("A recent database restore failed, but the automatic rollback to your safety backup was successful. Your site is now back to its previous state.", "optistate") . '</p>';
            echo '</div>';
        } elseif ($status === 'failed') {
            echo '<div class="notice notice-error">';
            echo '<h3>' . esc_html__("WP Optimal State: POTENTIAL ROLLBACK FAILURE", "optistate") . '</h3>';
            echo '<p>' . esc_html__("A database restore failed, and the automatic rollback may have failed as well. Check your site to ensure that it is working properly.", "optistate") . '</p>';
            $allowed_html = ['code' => [], ];
            echo '<p><strong>' . esc_html__("What to do:", "optistate") . '</strong> ' . wp_kses(sprintf(__("A safety backup file may still exist. If your site is broken, please check the <code>%s</code> folder for a file named 'SAFETY-RESTORE-...' and restore it manually or contact support.", "optistate"), esc_html(wp_normalize_path($this->backup_dir))), $allowed_html) . '</p>';
            echo '</div>';
        } elseif ($status === 'failed_no_backup') {
            echo '<div class="notice notice-error">';
            echo '<h3>' . esc_html__("WP Optimal State: Rollback Failed", "optistate") . '</h3>';
            echo '<p>' . esc_html__("A database restore failed, but the rollback could not run because the safety backup information was missing or expired. Please check your site's integrity.", "optistate") . '</p>';
            echo '</div>';
        }
    }
    private function _initiate_chunked_restore($filepath, $log_filename, $uploaded_file_info = []) {
        if (!$this->wp_filesystem) {
            throw new Exception(esc_html__("Filesystem not initialized.", "optistate"));
        }
        $total_size = $this->wp_filesystem->size($filepath);
        if ($total_size === false || $total_size < 100) {
            throw new Exception(esc_html__("Invalid or empty backup file.", "optistate"));
        }
        $transient_key = 'optistate_restore_' . bin2hex(random_bytes(14));
        $state = ['filepath' => $filepath, 'log_filename' => $log_filename, 'file_pointer' => 0, 'total_size' => $total_size, 'temp_tables_created' => [], 'executed_queries' => 0, 'start_time' => time(), 'status' => 'init', 'query_buffer' => '', 'line_buffer' => '', 'in_multi_line_comment' => false, 'batch_counter' => 0, 'database_name_validated' => false, 'uploaded_file_info' => $uploaded_file_info, 'deferred_indexes' => [], 'resume_attempts' => 0, 'last_error' => ''];
        $this->set_process_state($transient_key, $state, DAY_IN_SECONDS);
        return $transient_key;
    }
    private function _perform_restore_core($state) {
        global $wpdb;
        $db = null;
        $handle = null;
        $chunk_start_time = time();
        $max_chunk_time = 20;
        $original_time_limit = ini_get('max_execution_time');
        $needed_time = $max_chunk_time + 90;
        @set_time_limit($needed_time);
        try {
            $filepath = $state['filepath'];
            if (!$this->wp_filesystem->exists($filepath) || !$this->wp_filesystem->is_readable($filepath)) {
                throw new Exception(esc_html__('Backup file not found or not readable.', 'optistate'));
            }
            $handle = @fopen($filepath, 'r');
            if (!$handle) {
                throw new Exception(esc_html__('Failed to open backup file for reading.', 'optistate'));
            }
            @fseek($handle, $state['file_pointer']);
            $temp_tables_created = $state['temp_tables_created'];
            $state['deferred_indexes'] = $state['deferred_indexes']??[];
            try {
                $db = $this->get_restore_db();
                $executed_queries = $state['executed_queries'];
                $batch_counter = $state['batch_counter'];
                $in_multi_line_comment = $state['in_multi_line_comment'];
                $database_name_validated = $state['database_name_validated'];
                $query_buffer = $state['query_buffer']??'';
                $current_query_type = $state['current_query_type']??null;
                $transaction_max_size = 5 * 1024 * 1024;
                $transaction_max_time = 10;
                $current_transaction_size = 0;
                $last_commit_time = time();
                $exclude_table = $this->process_store->get_table_name();
                $metadata_table = $wpdb->prefix . 'optistate_backup_metadata';
                $exclude_patterns = ['main' => '/(CREATE\s+TABLE|DROP\s+TABLE|ALTER\s+TABLE|INSERT\s+INTO|LOCK\s+TABLES|RENAME\s+TABLE)\s+(?:IF\s+NOT\s+EXISTS\s+)?[`\'"]?' . preg_quote($exclude_table) . '[`\'"]?/i', 'metadata' => '/(CREATE\s+TABLE|DROP\s+TABLE|ALTER\s+TABLE|INSERT\s+INTO|LOCK\s+TABLES|RENAME\s+TABLE)\s+(?:IF\s+NOT\s+EXISTS\s+)?[`\'"]?' . preg_quote($metadata_table) . '[`\'"]?/i', 'old' => '/(CREATE\s+TABLE|DROP\s+TABLE|ALTER\s+TABLE|INSERT\s+INTO|LOCK\s+TABLES|RENAME\s+TABLE)\s+(?:IF\s+NOT\s+EXISTS\s+)?[`\'"]?optistate_old_[a-zA-Z0-9_]+[`\'"]?/i'];
                if ($state['status'] === 'init') {
                    $this->db_query("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
                    $this->db_query("SET FOREIGN_KEY_CHECKS = 0");
                    $this->db_query("SET AUTOCOMMIT = 0");
                    $this->db_query("SET sql_mode = ''");
                    $state['status'] = 'running';
                }
                while (($line = fgets($handle)) !== false) {
                    if ((time() - $chunk_start_time) > $max_chunk_time) {
                        $state['file_pointer'] = @ftell($handle);
                        $state['query_buffer'] = $query_buffer;
                        $state['temp_tables_created'] = $temp_tables_created;
                        $state['executed_queries'] = $executed_queries;
                        $state['batch_counter'] = $batch_counter;
                        $state['in_multi_line_comment'] = $in_multi_line_comment;
                        $state['database_name_validated'] = $database_name_validated;
                        $state['deferred_indexes'] = $state['deferred_indexes'];
                        $state['current_query_type'] = $current_query_type;
                        @fclose($handle);
                        return ['status' => 'running', 'state' => $state, 'message' => esc_html__('RESTORING DATABASE ....', 'optistate') ];
                    }
                    $trim_line = trim($line);
                    if ($trim_line === '') {
                        continue;
                    }
                    if ($in_multi_line_comment) {
                        if (strpos($trim_line, '*/') !== false) {
                            $in_multi_line_comment = false;
                            $parts = explode('*/', $trim_line, 2);
                            $trim_line = trim($parts[1]);
                            if ($trim_line === '') {
                                continue;
                            }
                        } else {
                            continue;
                        }
                    }
                    if ($query_buffer === '') {
                        if (strpos($trim_line, "/*") === 0 && strpos($trim_line, "*/") === false) {
                            $in_multi_line_comment = true;
                            continue;
                        }
                        if ((strpos($trim_line, "--") === 0 || strpos($trim_line, "#") === 0) || (strpos($trim_line, "/*") === 0 && strpos($trim_line, "*/") !== false)) {
                            if (strpos($trim_line, "/*!") !== 0) {
                                continue;
                            }
                        }
                        if (!$database_name_validated && stripos($trim_line, 'USE') === 0) {
                            if (preg_match('/^USE\s+`?([^`;\s]+)`?/i', $trim_line, $matches)) {
                                $db_name_in_file = trim($matches[1], "`");
                                if ($db_name_in_file !== DB_NAME) {
                                    throw new Exception(__('Database name mismatch detected.', 'optistate') . ' ' . sprintf(__('Expected: %1$s, Found: %2$s', 'optistate'), DB_NAME, $db_name_in_file));
                                }
                                $database_name_validated = true;
                                continue;
                            }
                        }
                    }
                    $query_buffer.= $line;
                    if ($current_query_type === null && $query_buffer !== '') {
                        $upper_start = strtoupper(substr(ltrim($query_buffer), 0, 12));
                        if (strpos($upper_start, 'INSERT INTO') === 0 || strpos($upper_start, 'INSERT') === 0) {
                            $current_query_type = 'INSERT';
                        } elseif (strpos($upper_start, 'CREATE') === 0) {
                            $current_query_type = 'CREATE';
                        } elseif (strpos($upper_start, 'DROP') === 0) {
                            $current_query_type = 'DROP';
                        } elseif (strpos($upper_start, 'ALTER') === 0) {
                            $current_query_type = 'ALTER';
                        } elseif (strpos($upper_start, 'LOCK') === 0) {
                            $current_query_type = 'LOCK';
                        } elseif (strpos($upper_start, 'SET ') === 0 || strpos($upper_start, 'COMMIT') === 0 || strpos($upper_start, 'START TRANSA') === 0 || strpos($upper_start, 'UNLOCK TABLE') === 0 || strpos($upper_start, '/*') === 0) {
                            $current_query_type = 'CONTROL';
                        } else {
                            $current_query_type = 'OTHER';
                        }
                    }
                    if (substr($trim_line, -1) !== ";") {
                        continue;
                    }
                    if ($current_query_type !== 'INSERT' && $current_query_type !== 'CONTROL') {
                        $should_skip = false;
                        if (strpos($query_buffer, $exclude_table) !== false) {
                            if (preg_match($exclude_patterns['main'], $query_buffer)) {
                                $should_skip = true;
                            }
                        }
                        if (!$should_skip && strpos($query_buffer, $metadata_table) !== false) {
                            if (preg_match($exclude_patterns['metadata'], $query_buffer)) {
                                $should_skip = true;
                            }
                        }
                        if (!$should_skip && strpos($query_buffer, 'optistate_old_') !== false) {
                            if (preg_match($exclude_patterns['old'], $query_buffer)) {
                                $should_skip = true;
                            }
                        }
                        if ($should_skip) {
                            $query_buffer = "";
                            $current_query_type = null;
                            continue;
                        }
                    }
                    $query_to_run = null;
                    $is_large_insert = false;
                    switch ($current_query_type) {
                        case 'INSERT':
                            if (preg_match('/INSERT\s+INTO\s+[`\'"]?([a-zA-Z0-9_]+)[`\'"]?/i', substr($query_buffer, 0, 200), $matches)) {
                                $original_table_name = $matches[1];
                                if (isset($temp_tables_created[$original_table_name])) {
                                    $temp_table_name = $temp_tables_created[$original_table_name];
                                    $query_to_run = preg_replace('/INSERT\s+INTO\s+[`\'"]?' . preg_quote($original_table_name) . '[`\'"]?/i', 'INSERT INTO `' . esc_sql($temp_table_name) . '`', $query_buffer, 1);
                                    $is_large_insert = (strlen($query_to_run) > 256 * 1024);
                                }
                            }
                        break;
                        case 'CREATE':
                            if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`\'"]?([a-zA-Z0-9_]+)[`\'"]?/i', $query_buffer, $matches)) {
                                $original_table_name = $matches[1];
                                if (!empty($original_table_name)) {
                                    $temp_table_name = $this->generate_temp_table_name($original_table_name, 'optistate_temp_', 64);
                                    $temp_tables_created[$original_table_name] = $temp_table_name;
                                    $parsing_result = $this->_parse_create_table_for_indexes($query_buffer, $temp_table_name);
                                    $query_to_run = $parsing_result['create_table_query'];
                                    if (!empty($parsing_result['alter_queries'])) {
                                        $state['deferred_indexes'][$temp_table_name] = array_merge($state['deferred_indexes'][$temp_table_name]??[], $parsing_result['alter_queries']);
                                    }
                                }
                            } else {
                                $query_to_run = $this->tokenize_and_rewrite_table($query_buffer, 'optistate_temp_', $temp_tables_created, 'CREATE');
                            }
                        break;
                        case 'DROP':
                        case 'ALTER':
                        case 'LOCK':
                            $query_to_run = $this->tokenize_and_rewrite_table($query_buffer, 'optistate_temp_', $temp_tables_created, $current_query_type);
                        break;
                        case 'CONTROL':
                            $query_to_run = $query_buffer;
                        break;
                        default:
                            $query_to_run = $query_buffer;
                    }
                    if ($query_to_run === null) {
                        $query_to_run = $query_buffer;
                    }
                    if ($current_query_type !== 'INSERT' && !$this->is_safe_restore_query($query_to_run)) {
                        $db->query("ROLLBACK");
                        throw new Exception(esc_html__('Security risk detected. Disallowed SQL query blocked.', 'optistate'));
                    }
                    if ($batch_counter === 0) {
                        $db->query("START TRANSACTION");
                    }
                    if ($is_large_insert) {
                        $batch_result = $this->_process_batched_insert($db, $query_to_run, $max_chunk_time, $chunk_start_time);
                        if ($batch_result['status'] === 'error') {
                            $this->db_query("ROLLBACK");
                            throw new Exception($batch_result['message']);
                        }
                        $executed_queries+= $batch_result['queries_executed'];
                        $batch_counter+= $batch_result['queries_executed'];
                        $current_transaction_size+= strlen($query_to_run);
                    } else {
                        $result = $db->query($query_to_run);
                        if ($result === false) {
                            $error_msg = $db->error;
                            $is_ignorable = (($current_query_type === 'DROP' && strpos($error_msg, "doesn't exist") !== false) || ($current_query_type === 'CREATE' && strpos($error_msg, "already exists") !== false));
                            if (!$is_ignorable) {
                                $db->query("ROLLBACK");
                                throw new Exception(__('SQL Error: ', 'optistate') . $error_msg . ' ' . sprintf(__('near query: %s', 'optistate'), substr($query_to_run, 0, 100) . '...'));
                            }
                        } else {
                            $executed_queries++;
                            $batch_counter++;
                            $current_transaction_size+= strlen($query_to_run);
                        }
                    }
                    if ($current_transaction_size > $transaction_max_size || (time() - $last_commit_time) > $transaction_max_time) {
                        $db->query("COMMIT");
                        $batch_counter = 0;
                        $current_transaction_size = 0;
                        $last_commit_time = time();
                    }
                    $query_buffer = "";
                    $current_query_type = null;
                }
                if ($batch_counter > 0) {
                    if ($this->db_query("COMMIT") === false) {
                        $this->db_query("ROLLBACK");
                        throw new Exception(esc_html__('Restore failed: Could not commit final transaction.', 'optistate'));
                    }
                }
                if (!empty($state['deferred_indexes'])) {
                    $settings = $this->main_plugin->get_persistent_settings();
                    $security_disabled = !empty($settings['disable_restore_security']) && $settings['disable_restore_security'] === true;
                    if (!$security_disabled) {
                        foreach ($state['deferred_indexes'] as $temp_table_name => $alter_queries) {
                            foreach ($alter_queries as $alter_query) {
                                if (!$this->is_safe_restore_query($alter_query)) {
                                    throw new Exception(esc_html__('Security risk detected in deferred index creation. ', 'optistate') . sprintf(__('Table: %s', 'optistate'), $temp_table_name));
                                }
                            }
                        }
                    }
                    $this->db_query("START TRANSACTION");
                    try {
                        foreach ($state['deferred_indexes'] as $temp_table_name => $alter_queries) {
                            foreach ($alter_queries as $alter_query) {
                                $result = $this->db_query($alter_query);
                                if ($result === false) {
                                    $error_msg = $this->db_get_last_error();
                                    if (stripos($error_msg, 'Duplicate') === false && stripos($error_msg, 'already exists') === false) {
                                        throw new Exception(sprintf(__('Failed to apply indexes to %s: ', 'optistate'), $temp_table_name) . $error_msg);
                                    }
                                }
                            }
                        }
                        $this->db_query("COMMIT");
                    }
                    catch(Exception $e) {
                        $this->db_query("ROLLBACK");
                        throw $e;
                    }
                }
                $this->db_query("SET AUTOCOMMIT = 1");
                $this->db_query("SET FOREIGN_KEY_CHECKS = 1");
                $this->db_query("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
                $this->db_query("SET sql_mode = @@global.sql_mode");
                if ($executed_queries < 5) {
                    throw new Exception(esc_html__('Insufficient queries executed. File may be corrupt.', 'optistate'));
                }
                if (!empty($temp_tables_created)) {
                    $verification_result = $this->verify_temp_tables($db, $temp_tables_created, 'optistate_temp_');
                    if (!$verification_result['valid']) {
                        $this->cleanup_temp_tables($db, $temp_tables_created);
                        throw new Exception(esc_html__('Temp table verification failed: ', 'optistate') . $verification_result['message']);
                    }
                    $swap_result = $this->swap_temp_tables_to_live($db, $temp_tables_created, 'optistate_temp_');
                    if (!$swap_result['success']) {
                        $this->cleanup_temp_tables($db, $temp_tables_created);
                        throw new Exception(sprintf(__('Table swap failed: %s', 'optistate'), $swap_result['message']));
                    }
                }
                $this->delete_process_state('optistate_restore_in_progress');
                $this->log_restore_operation($state['log_filename'], $executed_queries);
                $this->main_plugin->reschedule_cron_from_settings();
                $this->delete_process_state("optistate_stats_cache");
                $this->delete_process_state("optistate_health_score");
                $this->deactivate_maintenance_mode();
                if (!empty($state['uploaded_file_info']['temp_filepath_to_delete'])) {
                    $temp_file = basename($state['uploaded_file_info']['temp_filepath_to_delete']);
                    $this->cleanup_all_temp_sql_files($temp_file);
                }
                if (!empty($state['uploaded_file_info']['temp_transient_to_delete'])) {
                    $this->delete_process_state($state['uploaded_file_info']['temp_transient_to_delete']);
                }
                $this->cleanup_all_temp_sql_files();
                $this->cleanup_old_tables_after_restore();
                $total_time = time() - $state['start_time'];
                $message = sprintf(__('Database restored successfully!<br>Completion time: %1$d seconds.<br>Restored tables: %2$d.<br>', 'optistate'), $total_time, count($temp_tables_created));
                update_option('optistate_restore_completed', ['timestamp' => time(), 'filename' => $state['log_filename'], 'queries' => $executed_queries, 'tables' => count($temp_tables_created), 'duration' => $total_time], false);
                $this->close_restore_db();
                @fclose($handle);
                $this->delete_process_state('optistate_current_restore_key');
                return ['status' => 'done', 'state' => $state, 'message' => $message];
            }
            catch(Exception $e) {
                if ($this->restore_db !== null) {
                    $this->db_query("ROLLBACK");
                    $this->db_query("SET AUTOCOMMIT = 1");
                    $this->db_query("SET FOREIGN_KEY_CHECKS = 1");
                }
                if (isset($handle) && is_resource($handle)) {
                    @fclose($handle);
                }
                $state['temp_tables_created'] = $temp_tables_created;
                $this->close_restore_db();
                throw $e;
            }
        }
        finally {
            if (isset($handle) && is_resource($handle)) {
                @fclose($handle);
            }
            @set_time_limit($original_time_limit);
        }
    }
    private function tokenize_and_rewrite_table($sql, $temp_prefix, &$temp_tables_created, $query_type = null) {
        $length = strlen($sql);
        $new_sql = '';
        $STATE_DEFAULT = 0;
        $STATE_IN_BACKTICK = 1;
        $STATE_IN_SINGLE_QUOTE = 2;
        $STATE_IN_DOUBLE_QUOTE = 3;
        $STATE_IN_COMMENT_DASH = 4;
        $STATE_IN_COMMENT_HASH = 5;
        $STATE_IN_COMMENT_MULTI = 6;
        $state = $STATE_DEFAULT;
        $buffer = '';
        $i = 0;
        while ($i < $length) {
            $char = $sql[$i];
            $next_char = ($i + 1 < $length) ? $sql[$i + 1] : '';
            switch ($state) {
                case $STATE_DEFAULT:
                    if ($char === '`') {
                        $state = $STATE_IN_BACKTICK;
                        $new_sql.= $buffer;
                        $buffer = '';
                    } elseif ($char === "'") {
                        $state = $STATE_IN_SINGLE_QUOTE;
                        $new_sql.= $buffer . "'";
                        $buffer = '';
                    } elseif ($char === '"') {
                        $state = $STATE_IN_DOUBLE_QUOTE;
                        $new_sql.= $buffer . '"';
                        $buffer = '';
                    } elseif ($char === '-' && $next_char === '-') {
                        $state = $STATE_IN_COMMENT_DASH;
                        $new_sql.= $buffer . '--';
                        $buffer = '';
                        $i++;
                    } elseif ($char === '#') {
                        $state = $STATE_IN_COMMENT_HASH;
                        $new_sql.= $buffer . '#';
                        $buffer = '';
                    } elseif ($char === '/' && $next_char === '*') {
                        $state = $STATE_IN_COMMENT_MULTI;
                        $new_sql.= $buffer . '/*';
                        $buffer = '';
                        $i++;
                    } else {
                        $buffer.= $char;
                    }
                break;
                case $STATE_IN_BACKTICK:
                    if ($char === '`') {
                        if ($next_char === '`') {
                            $buffer.= '``';
                            $i++;
                        } else {
                            $original_name = $buffer;
                            $replacement = $this->get_temp_name_for_rewrite($original_name, $temp_prefix, $temp_tables_created, $query_type);
                            $new_sql.= '`' . $replacement . '`';
                            $buffer = '';
                            $state = $STATE_DEFAULT;
                        }
                    } else {
                        $buffer.= $char;
                    }
                break;
                case $STATE_IN_SINGLE_QUOTE:
                    $new_sql.= $char;
                    if ($char === '\\') {
                        $new_sql.= $next_char;
                        $i++;
                    } elseif ($char === "'" && $next_char === "'") {
                        $new_sql.= "'";
                        $i++;
                    } elseif ($char === "'") {
                        $state = $STATE_DEFAULT;
                    }
                break;
                case $STATE_IN_DOUBLE_QUOTE:
                    $new_sql.= $char;
                    if ($char === '\\') {
                        $new_sql.= $next_char;
                        $i++;
                    } elseif ($char === '"' && $next_char === '"') {
                        $new_sql.= '"';
                        $i++;
                    } elseif ($char === '"') {
                        $state = $STATE_DEFAULT;
                    }
                break;
                case $STATE_IN_COMMENT_DASH:
                case $STATE_IN_COMMENT_HASH:
                    $new_sql.= $char;
                    if ($char === "\n") {
                        $state = $STATE_DEFAULT;
                    }
                break;
                case $STATE_IN_COMMENT_MULTI:
                    $new_sql.= $char;
                    if ($char === '*' && $next_char === '/') {
                        $new_sql.= '/';
                        $i++;
                        $state = $STATE_DEFAULT;
                    }
                break;
            }
            $i++;
        }
        if (!empty($buffer)) {
            $new_sql.= $buffer;
        }
        return $new_sql;
    }
    private function get_temp_name_for_rewrite($original_name, $temp_prefix, &$temp_tables_created, $query_type) {
        if (isset($temp_tables_created[$original_name])) {
            return $temp_tables_created[$original_name];
        } elseif ($query_type !== null) {
            $temp_table = $this->generate_temp_table_name($original_name, $temp_prefix, 64);
            $temp_tables_created[$original_name] = $temp_table;
            return $temp_table;
        }
        return $original_name;
    }
    private function verify_temp_tables($db, $temp_tables_created, $temp_prefix) {
        if (empty($temp_tables_created)) {
            return ['valid' => false, 'message' => esc_html__('No temporary tables were created during restore.', 'optistate') ];
        }
        $found_options_table_key = null;
        $found_posts_table_key = null;
        $found_users_table_key = null;
        foreach ($temp_tables_created as $original_table => $temp_table) {
            if ($found_options_table_key === null && substr($original_table, -8) === '_options') $found_options_table_key = $original_table;
            if ($found_posts_table_key === null && substr($original_table, -6) === '_posts') $found_posts_table_key = $original_table;
            if ($found_users_table_key === null && substr($original_table, -6) === '_users') $found_users_table_key = $original_table;
        }
        if (!$found_options_table_key || !$found_posts_table_key || !$found_users_table_key) {
            $missing = [];
            if (!$found_options_table_key) $missing[] = '..._options';
            if (!$found_posts_table_key) $missing[] = '..._posts';
            if (!$found_users_table_key) $missing[] = '..._users';
            return ['valid' => false, 'message' => sprintf(__('Required WordPress core tables (%s) were not found in the backup.', 'optistate'), implode(', ', $missing)) ];
        }
        $core_table_keys_to_check = [$found_options_table_key, $found_posts_table_key, $found_users_table_key];
        foreach ($core_table_keys_to_check as $core_table_key) {
            $temp_table_name = $temp_tables_created[$core_table_key];
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $temp_table_name)) {
                return ['valid' => false, 'message' => 'Security Warning: Invalid table name format detected.'];
            }
            $table_exists = $this->db_get_var("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . esc_sql(DB_NAME) . "' AND TABLE_NAME = '" . esc_sql($temp_table_name) . "'");
            if (!$table_exists) {
                return ['valid' => false, 'message' => sprintf(__('Temporary table %1$s (for %2$s) was not created successfully.', 'optistate'), $temp_table_name, $core_table_key) ];
            }
            $row_count = $this->db_get_var("SELECT COUNT(*) FROM `" . esc_sql($temp_table_name) . "`");
            if ($row_count === null || $row_count == 0) {
                if ($core_table_key === $found_options_table_key) {
                    return ['valid' => false, 'message' => sprintf(__('Temporary table %s is empty. Restore may be corrupted.', 'optistate'), $temp_table_name) ];
                }
            }
        }
        $temp_options_table = $temp_tables_created[$found_options_table_key];
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $temp_options_table)) {
            return ['valid' => false, 'message' => 'Invalid options table name.'];
        }
        $siteurl_exists = $this->db_get_var("SELECT COUNT(*) FROM `" . esc_sql($temp_options_table) . "` WHERE option_name = 'siteurl'");
        if (!$siteurl_exists) {
            return ['valid' => false, 'message' => esc_html__("Critical WordPress option 'siteurl' not found in restored data.", 'optistate') ];
        }
        return ['valid' => true, 'message' => sprintf(__('All %s temporary tables verified successfully.', 'optistate'), number_format_i18n(count($temp_tables_created))) ];
    }
    private function generate_temp_table_name($original_table, $temp_prefix, $max_length = 64) {
        $full_temp_name = $temp_prefix . $original_table;
        if (strlen($full_temp_name) <= $max_length) {
            return $full_temp_name;
        }
        $hash = substr(md5($original_table), 0, 8);
        $compact_prefix = 'optistate_tmp_' . $hash . '_';
        $available_space = $max_length - strlen($compact_prefix);
        $truncated_table = substr($original_table, 0, $available_space);
        return $compact_prefix . $truncated_table;
    }
    private function swap_temp_tables_to_live($db, $temp_tables_created, $temp_prefix) {
        if (empty($temp_tables_created)) {
            return ['success' => false, 'message' => esc_html__('No tables to swap.', 'optistate') ];
        }
        $old_prefix = 'optistate_old_';
        $all_rename_pairs = [];
        $tables_to_cleanup = [];
        $rollback_plan = [];
        foreach ($temp_tables_created as $original_table => $temp_table_name) {
            $live_table = $original_table;
            $old_table = $this->generate_old_table_name($original_table, $old_prefix);
            $table_exists = $this->db_get_var("SELECT COUNT(*) FROM information_schema.TABLES " . "WHERE TABLE_SCHEMA = '" . esc_sql(DB_NAME) . "' AND TABLE_NAME = '" . esc_sql($live_table) . "'");
            if ($table_exists) {
                $all_rename_pairs[] = "`" . esc_sql($live_table) . "` TO `" . esc_sql($old_table) . "`";
                $tables_to_cleanup[] = $old_table;
                $rollback_plan[$live_table] = $old_table;
            }
            $all_rename_pairs[] = "`" . esc_sql($temp_table_name) . "` TO `" . esc_sql($live_table) . "`";
        }
        $transaction_active = true;
        $cleanup_registered = false;
        register_shutdown_function(function () use (&$transaction_active, &$cleanup_registered) {
            if ($transaction_active && $cleanup_registered) {
                try {
                    OPTISTATE_DB_Wrapper::reset();
                    $db_shutdown = OPTISTATE_DB_Wrapper::get_instance()->get_connection();
                    $db_shutdown->query("SET FOREIGN_KEY_CHECKS = 1");
                }
                catch(Exception $e) {
                }
                if (get_option('optistate_maintenance_mode_active')) {
                    delete_option('optistate_maintenance_mode_active');
                }
                if ($this->get_process_state('optistate_restore_in_progress')) {
                    $this->delete_process_state('optistate_restore_in_progress');
                }
                $transaction_active = false;
            }
        });
        $db->query("SET SESSION innodb_lock_wait_timeout = 50");
        $db->query("SET SESSION lock_wait_timeout = 50");
        $db->query("SET FOREIGN_KEY_CHECKS = 0");
        $this->set_process_state('optistate_instant_rollback_tables', $rollback_plan, HOUR_IN_SECONDS);
        try {
            $cleanup_registered = true;
            if (!empty($all_rename_pairs)) {
                $atomic_rename_query = "RENAME TABLE " . implode(', ', $all_rename_pairs);
                if ($db->query($atomic_rename_query) === false) {
                    throw new Exception(sprintf(__('Failed to atomically swap tables. Error: ', 'optistate'), $db->error));
                }
            }
            $this->delete_process_state('optistate_instant_rollback_tables');
            $transaction_active = false;
            $db->query("SET FOREIGN_KEY_CHECKS = 1");
            wp_cache_flush();
            return ['success' => true, 'message' => sprintf(__('All %s tables swapped successfully.', 'optistate'), number_format_i18n(count($temp_tables_created))), 'swapped_tables' => $tables_to_cleanup];
        }
        catch(Exception $e) {
            $transaction_active = false;
            $db->query("SET FOREIGN_KEY_CHECKS = 1");
            return ['success' => false, 'message' => esc_html__('Restore failed: ', 'optistate') . $e->getMessage() . esc_html__(' Your database was not modified.', 'optistate') ];
        }
    }
    private function generate_old_table_name($original_table, $old_prefix = 'optistate_old_', $max_length = 64) {
        $full_old_name = $old_prefix . $original_table;
        if (strlen($full_old_name) <= $max_length) {
            return $full_old_name;
        }
        $hash = substr(md5($original_table), 0, 8);
        $compact_prefix = 'optistate_old_' . $hash . '_';
        $available_space = $max_length - strlen($compact_prefix);
        $truncated_table = substr($original_table, 0, $available_space);
        return $compact_prefix . $truncated_table;
    }
    private function cleanup_temp_tables($db, $temp_tables_created) {
        if (empty($temp_tables_created)) {
            return;
        }
        $db->query("SET FOREIGN_KEY_CHECKS = 0");
        foreach ($temp_tables_created as $original_table => $temp_table) {
            $db->query("DROP TABLE IF EXISTS `" . esc_sql($temp_table) . "`");
            $old_table = $this->generate_old_table_name($original_table, 'optistate_old_');
            $db->query("DROP TABLE IF EXISTS `" . esc_sql($old_table) . "`");
        }
        $db->query("SET FOREIGN_KEY_CHECKS = 1");
    }
    public function cleanup_old_temp_files_daily() {
        if ($this->process_store) {
            $this->process_store->cleanup();
        }
        if ($this->get_process_state('optistate_restore_in_progress')) {
            return;
        }
        $upload_dir = wp_upload_dir();
        $temp_max_age = 2 * HOUR_IN_SECONDS;
        $temp_dir = trailingslashit($upload_dir['basedir']) . OPTISTATE::TEMP_DIR_NAME . '/';
        $this->delete_old_files($temp_dir, $temp_max_age);
        try {
            $db = OPTISTATE_DB_Wrapper::get_instance()->get_connection();
            $query = "SELECT TABLE_NAME FROM information_schema.TABLES 
              WHERE TABLE_SCHEMA = '" . esc_sql(DB_NAME) . "' 
              AND (TABLE_NAME LIKE 'optistate_old_%%' OR TABLE_NAME LIKE 'optistate_temp_%%')";
            $old_tables_result = $db->query($query);
            if ($old_tables_result && $old_tables_result->num_rows > 0) {
                $tables_to_drop = [];
                while ($row = $old_tables_result->fetch_row()) {
                    $tables_to_drop[] = "`" . esc_sql($row[0]) . "`";
                }
                $old_tables_result->free();
                if (!empty($tables_to_drop)) {
                    $db->query("SET FOREIGN_KEY_CHECKS = 0");
                    $db->query("DROP TABLE IF EXISTS " . implode(', ', $tables_to_drop));
                    $db->query("SET FOREIGN_KEY_CHECKS = 1");
                }
            }
            OPTISTATE_DB_Wrapper::get_instance()->close();
        }
        catch(Exception $e) {
            if (isset($db) && $db) {
                $db->query("SET FOREIGN_KEY_CHECKS = 1");
                OPTISTATE_DB_Wrapper::get_instance()->close();
            }
        }
        if (get_option('optistate_maintenance_mode_active')) {
            delete_option('optistate_maintenance_mode_active');
        }
    }
    private function delete_old_files($directory, $max_age) {
        if (!$this->wp_filesystem) {
            return;
        }
        if (!$this->wp_filesystem->is_dir($directory)) {
            return;
        }
        $files = $this->wp_filesystem->dirlist($directory);
        if (empty($files)) {
            return;
        }
        $current_time = time();
        foreach ($files as $filename => $fileinfo) {
            if ($fileinfo['type'] === 'f') {
                if (substr($filename, -4) !== '.sql' && substr($filename, -7) !== '.sql.gz') {
                    continue;
                }
                $file_mtime = 0;
                if (isset($fileinfo['lastmodunix']) && $fileinfo['lastmodunix']) {
                    $file_mtime = (int)$fileinfo['lastmodunix'];
                } elseif (isset($fileinfo['time']) && $fileinfo['time']) {
                    $file_mtime = (int)$fileinfo['time'];
                } else {
                    $file_path = $directory . $filename;
                    if ($this->wp_filesystem->exists($file_path)) {
                        $file_mtime = $this->wp_filesystem->mtime($file_path);
                    }
                }
                if ($file_mtime <= 0) {
                    continue;
                }
                $file_age = $current_time - $file_mtime;
                if ($file_age > $max_age) {
                    $file_path = $directory . $filename;
                    $this->wp_filesystem->delete($file_path);
                    if (preg_match('/([a-f0-9]{32})\.(sql|sql\.gz)$/', $filename, $matches)) {
                        $identifier = $matches[1];
                        $this->delete_process_state('optistate_temp_restore_' . $identifier);
                    }
                }
            }
        }
    }
    public function display_backup_permission_warning() {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, "optistate") === false || !current_user_can("manage_options")) {
            return;
        }
?>
    <div class="notice notice-warning is-dismissible">
        <p>
            <strong><?php esc_html_e("WP Optimal State - Backup Warning", "optistate"); ?></strong><br>
            <?php esc_html_e("The backup directory is not writable. Database backup functionality will not work until this is resolved.", "optistate"); ?>
        </p>
        <p>
            <code><?php echo esc_html($this->backup_dir); ?></code>
        </p>
        <p>
            <?php esc_html_e("Please ensure this directory has write permissions (typically 700 or higher).", "optistate"); ?>
        </p>
    </div>
    <?php
    }
    private function ensure_secure_backup_dir() {
        if (!$this->wp_filesystem) {
            return false;
        }
        if (!$this->wp_filesystem->is_dir($this->backup_dir)) {
            if (!$this->wp_filesystem->mkdir($this->backup_dir, FS_CHMOD_DIR, true)) {
                return false;
            }
        }
        $this->wp_filesystem->chmod($this->backup_dir, 0755);
        if (!$this->wp_filesystem->is_writable($this->backup_dir)) {
            return false;
        }
        return true;
    }
    public function protect_backup_directory() {
        if (!$this->wp_filesystem || !$this->wp_filesystem->is_dir($this->backup_dir)) {
            return;
        }
        $rules = ['# WP Optimal State - Secure Backup Directory', '# Prevent directory listing', 'Options -Indexes', '', '# Block all direct web access. Downloads are handled by PHP.', '<IfModule mod_authz_core.c>', '    Require all denied', '</IfModule>', '<IfModule !mod_authz_core.c>', '    Order deny,allow', '    Deny from all', '</IfModule>', ];
        $this->main_plugin->secure_directory($this->backup_dir, $rules);
    }
    private function ensure_required_tables_exist() {
        global $wpdb;
        $meta_table = $wpdb->prefix . 'optistate_backup_metadata';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $meta_table)) !== $meta_table) {
            $this->create_backup_metadata_table();
        }
        $process_table = $this->process_store->get_table_name();
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $process_table)) !== $process_table) {
            $this->process_store->create_table();
        }
    }
    private function log_manual_backup_operation($backup_filename, $start_timestamp_utc = null, $type = "manual") {
        if (empty($this->log_file_path)) {
            $upload_dir = wp_upload_dir();
            $this->log_file_path = trailingslashit($upload_dir['basedir']) . OPTISTATE::SETTINGS_DIR_NAME . '/' . OPTISTATE::LOG_FILE_NAME;
        }
        if (!$this->wp_filesystem) {
            return;
        }
        $log_entries = [];
        if ($this->wp_filesystem->exists($this->log_file_path)) {
            $json_data = $this->wp_filesystem->get_contents($this->log_file_path);
            if ($json_data !== false) {
                $log_entries = json_decode($json_data, true);
            }
        }
        if (!is_array($log_entries)) {
            $log_entries = [];
        }
        $backup_filepath = $this->backup_dir . $backup_filename;
        $log_timestamp_utc = $start_timestamp_utc ? $start_timestamp_utc : time();
        $local_timestamp_val = $log_timestamp_utc + ((float)get_option('gmt_offset') * HOUR_IN_SECONDS);
        $formatted_date_string = $this->main_plugin->format_timestamp($log_timestamp_utc, true);
        if (strpos($backup_filename, 'SAFETY-RESTORE-') === 0) {
            $type = "scheduled";
        }
        $operation_text = ($type === 'scheduled') ? __('Scheduled Backup Created (%s)', 'optistate') : __('Backup Created (%s)', 'optistate');
        $log_entry = ["timestamp" => $local_timestamp_val, "type" => $type, "date" => $formatted_date_string, "operation" => '💾 ' . esc_html(sprintf($operation_text, $backup_filename)), "backup_filename" => $backup_filename, "file_size" => $this->wp_filesystem->exists($backup_filepath) ? $this->wp_filesystem->size($backup_filepath) : 0, ];
        array_unshift($log_entries, $log_entry);
        $log_entries = array_slice($log_entries, 0, 200);
        $json_data = json_encode($log_entries, JSON_PRETTY_PRINT);
        if ($json_data !== false) {
            $this->wp_filesystem->put_contents($this->log_file_path, $json_data, FS_CHMOD_FILE);
        }
    }
    private function log_restore_start_operation($backup_filename, $type = "manual") {
        if (empty($this->log_file_path)) {
            $upload_dir = wp_upload_dir();
            $this->log_file_path = trailingslashit($upload_dir['basedir']) . 'optistate/' . OPTISTATE::LOG_FILE_NAME;
        }
        if (!$this->wp_filesystem) {
            return;
        }
        $log_entries = [];
        if ($this->wp_filesystem->exists($this->log_file_path)) {
            $json_data = $this->wp_filesystem->get_contents($this->log_file_path);
            if ($json_data !== false) {
                $log_entries = json_decode($json_data, true);
            }
        }
        if (!is_array($log_entries)) {
            $log_entries = [];
        }
        $log_timestamp_utc = time();
        $local_timestamp_val = $log_timestamp_utc + ((float)get_option('gmt_offset') * HOUR_IN_SECONDS);
        $formatted_date_string = $this->main_plugin->format_timestamp($log_timestamp_utc, true);
        $operation_text = '▶ ' . esc_html(sprintf(__('Database Restore Started (%s)', 'optistate'), $backup_filename));
        $log_entry = ["timestamp" => $local_timestamp_val, "type" => $type, "date" => $formatted_date_string, "operation" => $operation_text, "backup_filename" => $backup_filename, ];
        array_unshift($log_entries, $log_entry);
        $log_entries = array_slice($log_entries, 0, 200);
        $json_data = json_encode($log_entries, JSON_PRETTY_PRINT);
        if ($json_data !== false) {
            $this->wp_filesystem->put_contents($this->log_file_path, $json_data, FS_CHMOD_FILE);
        }
    }
    private function create_backup_metadata_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'optistate_backup_metadata';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        filename varchar(255) NOT NULL,
        database_name varchar(64) NOT NULL,
        file_size bigint(20) NOT NULL,
        created_timestamp bigint(20) NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY filename (filename),
        KEY created_timestamp (created_timestamp)
    ) $charset_collate;";
        require_once (ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    private function save_backup_metadata($filepath, $filename, $start_time) {
        global $wpdb;
        if (!$this->wp_filesystem || !$this->wp_filesystem->exists($filepath)) {
            return false;
        }
        try {
            $file_size = $this->wp_filesystem->size($filepath);
            $file_mtime = $this->wp_filesystem->mtime($filepath);
            $table_name = $wpdb->prefix . 'optistate_backup_metadata';
            $data = ['filename' => $filename, 'database_name' => DB_NAME, 'file_size' => $file_size, 'created_timestamp' => $file_mtime, 'created_at' => current_time('mysql') ];
            $wpdb->replace($table_name, $data);
            return true;
        }
        catch(Exception $e) {
            return false;
        }
    }
    private function verify_backup_file($filepath, $skip_integrity_check = false) {
        global $wpdb;
        if (!$this->wp_filesystem) {
            return ["valid" => false, "message" => esc_html__("Filesystem error.", "optistate") ];
        }
        if (!$this->wp_filesystem->exists($filepath) || $this->wp_filesystem->size($filepath) < 100) {
            return ["valid" => false, "message" => esc_html__("Backup file missing or too small.", "optistate") ];
        }
        $filename = basename($filepath);
        if ($skip_integrity_check || strpos($filename, 'restore-temp-') === 0 || strpos($filename, 'decompressed-') === 0) {
            return ["valid" => true, "message" => esc_html__("File accessible.", "optistate") ];
        }
        $table_name = $wpdb->prefix . 'optistate_backup_metadata';
        $metadata = $wpdb->get_row($wpdb->prepare("SELECT file_size, created_timestamp FROM {$table_name} WHERE filename = %s AND database_name = %s", $filename, DB_NAME), ARRAY_A);
        if (!$metadata) {
            return ["valid" => false, "message" => esc_html__("Backup metadata not found or database mismatch.", "optistate") ];
        }
        $actual_size = $this->wp_filesystem->size($filepath);
        $expected_size = (int)$metadata['file_size'];
        if ($actual_size !== $expected_size) {
            return ["valid" => false, "message" => sprintf(__('File size mismatch!<br>Expected: %1$s, Found: %2$s (Possible corruption).', 'optistate'), size_format($expected_size, 2), size_format($actual_size, 2)) ];
        }
        $actual_mtime = $this->wp_filesystem->mtime($filepath);
        $expected_timestamp = (int)$metadata['created_timestamp'];
        if ($actual_mtime !== $expected_timestamp) {
            return ["valid" => false, "message" => sprintf(__('File timestamp mismatch (File may have been tampered with).<br>Expected: %1$s, Found: %2$s.', 'optistate'), date('Y-m-d H:i:s', $expected_timestamp), date('Y-m-d H:i:s', $actual_mtime)) ];
        }
        if (preg_match('/\.gz$/i', $filepath)) {
            $gz_handle = @gzopen($filepath, 'rb');
            if (!$gz_handle) {
                return ["valid" => false, "message" => esc_html__("Cannot open gzip file.", "optistate") ];
            }
            $test_read = @gzread($gz_handle, 4096);
            @gzclose($gz_handle);
            if ($test_read === false) {
                return ["valid" => false, "message" => esc_html__("Gzip file corrupted.", "optistate") ];
            }
        }
        return ["valid" => true, "message" => esc_html__("Backup verified successfully.", "optistate"), "metadata" => $metadata];
    }
    private function quick_verify_backup_status($filepath) {
        $filename = basename($filepath);
        $cache_key = 'optistate_backup_integrity_' . md5($filename);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        $result = $this->verify_backup_file($filepath, false);
        if ($result['valid']) {
            set_transient($cache_key, $result, 24 * HOUR_IN_SECONDS);
        }
        return $result;
    }
    private function clear_all_integrity_caches() {
        if (!$this->wp_filesystem) {
            return;
        }
        $files = $this->wp_filesystem->dirlist($this->backup_dir);
        if (!empty($files)) {
            foreach ($files as $filename => $fileinfo) {
                delete_transient('optistate_backup_integrity_' . md5($filename));
            }
        }
    }
    public function get_backups() {
        if (!$this->wp_filesystem) {
            return [];
        }
        $dirlist = $this->wp_filesystem->dirlist($this->backup_dir);
        if (empty($dirlist)) {
            return [];
        }
        global $wpdb;
        $table_name = $wpdb->prefix . 'optistate_backup_metadata';
        $all_metadata = $wpdb->get_results($wpdb->prepare("SELECT filename, created_timestamp FROM {$table_name} WHERE database_name = %s", DB_NAME), OBJECT_K);
        $backup_types = [];
        if ($this->wp_filesystem->exists($this->log_file_path)) {
            $log_content = $this->wp_filesystem->get_contents($this->log_file_path);
            if ($log_content !== false) {
                $log_entries = json_decode($log_content, true);
                if (is_array($log_entries)) {
                    foreach ($log_entries as $entry) {
                        if (!empty($entry['backup_filename']) && !empty($entry['type'])) {
                            $backup_types[$entry['backup_filename']] = strtoupper($entry['type']);
                        }
                    }
                }
            }
        }
        $backup_list = [];
        $download_nonce = wp_create_nonce("optistate_backup_nonce");
        foreach ($dirlist as $filename => $fileinfo) {
            if ($fileinfo['type'] !== 'f' || !preg_match('/\.sql(\.gz)?$/i', $filename)) {
                continue;
            }
            $file = trailingslashit($this->backup_dir) . $filename;
            $file_timestamp = $all_metadata[$filename]->created_timestamp??$fileinfo['lastmodunix']??time();
            $formatted_date = $this->main_plugin->format_timestamp($file_timestamp, true);
            $download_url = add_query_arg(["action" => "optistate_backup_download", "file" => rawurlencode($filename), "_wpnonce" => $download_nonce, ], admin_url());
            $file_size = $fileinfo['size']??0;
            $verification = $this->quick_verify_backup_status($file);
            $type = isset($backup_types[$filename]) ? $backup_types[$filename] : 'MANUAL';
            if (strpos($filename, 'SAFETY-RESTORE-') === 0) {
                $type = 'SCHEDULED';
            }
            $backup_list[] = ["filename" => $filename, "date" => $formatted_date, "size" => size_format($file_size, 2), "size_bytes" => $file_size, "timestamp" => $file_timestamp, "filepath" => $file, "download_url" => $download_url, "verified" => $verification["valid"], "verification_message" => $verification["message"], "type" => $type];
        }
        usort($backup_list, function ($a, $b) {
            return $b["timestamp"] - $a["timestamp"];
        });
        return $backup_list;
    }
    public function ajax_create_backup() {
        check_ajax_referer("optistate_backup_nonce", "nonce");
        $this->ensure_required_tables_exist();
        $this->clear_all_integrity_caches();
        if (!$this->ensure_secure_backup_dir()) {
            wp_send_json_error(["message" => esc_html__("Backup directory is not writable. Please check file permissions.", "optistate") ]);
        }
        if (!$this->main_plugin->check_rate_limit("create_backup", 60)) {
            wp_send_json_error(["message" => esc_html__("🕔 Please wait 60 seconds before performing a new backup.", "optistate") ]);
            return;
        }
        try {
            $random_string = wp_generate_password(14, false);
            $date_part = current_time("Y-m-d");
            $filename = "BACKUP-" . $date_part . "_" . $random_string . ".sql.gz";
            $extra_data = ['is_manual' => true, 'user_id' => get_current_user_id() ];
            $transient_key = $this->_initiate_chunked_backup($filename, $extra_data);
            $this->process_store->set('optistate_manual_backup_user_' . get_current_user_id(), $transient_key, DAY_IN_SECONDS);
            wp_schedule_single_event(time(), "optistate_run_manual_backup_chunk", [$transient_key]);
            wp_send_json_success(['message' => esc_html__('Backup initiated... Processing in background.', 'optistate'), 'status' => 'starting', 'transient_key' => $transient_key]);
        }
        catch(Exception $e) {
            wp_send_json_error(["message" => esc_html__("Backup failed to start: ", "optistate") . $e->getMessage() ]);
        }
    }
    public function ajax_check_backup_status() {
        check_ajax_referer("optistate_backup_nonce", "nonce");
        $transient_key = isset($_POST['transient_key']) ? sanitize_text_field($_POST['transient_key']) : '';
        if (empty($transient_key) || strpos($transient_key, 'optistate_backup_') !== 0) {
            wp_send_json_error(['status' => 'error', 'message' => esc_html__("Invalid backup session.", "optistate") ]);
        }
        $completion_data = $this->get_process_state($transient_key . '_complete');
        if ($completion_data !== false) {
            $this->delete_process_state($transient_key . '_complete');
            if ($completion_data['status'] === 'done') {
                $backups = $this->get_backups();
                wp_send_json_success(['status' => 'done', 'message' => $completion_data['message'], 'backups' => $backups]);
            } else {
                wp_send_json_error(['status' => 'error', 'message' => $completion_data['message']]);
            }
            return;
        }
        $state = $this->get_process_state($transient_key);
        if ($state === false) {
            wp_send_json_error(['status' => 'error', 'message' => sprintf(__("Backup session expired or completed.<br>If this issue persists, try deactivating and reactivating the plugin.", "optistate")) ]);
            return;
        }
        $status = (isset($state['status']) && $state['status'] === 'compressing') ? 'compressing' : 'running';
        $message = ($status === 'compressing') ? sprintf(__('COMPRESSING ....', 'optistate')) : sprintf(__('BACKING UP ....', 'optistate'));
        wp_send_json_success(['status' => $status, 'message' => $message]);
    }
    public function ajax_check_restore_status() {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $master_restore_key = $this->process_store->get('optistate_restore_in_progress');
        if ($master_restore_key === false || strpos($master_restore_key, 'optistate_master_restore_') !== 0) {
            if (get_option('optistate_maintenance_mode_active')) {
                $this->deactivate_maintenance_mode();
                wp_send_json_success(['status' => 'stalled', 'message' => __('Found stuck maintenance mode. Cleared.', 'optistate') ]);
            }
            wp_send_json_success(['status' => 'none', 'message' => 'No restore in progress.']);
            return;
        }
        $master_state = $this->process_store->get($master_restore_key);
        if ($master_state === false) {
            $this->deactivate_maintenance_mode();
            $this->process_store->delete('optistate_restore_in_progress');
            wp_send_json_success(['status' => 'stalled', 'message' => __('Restore process expired. Aborting.', 'optistate') ]);
            return;
        }
        wp_send_json_success(['status' => 'running', 'master_restore_key' => $master_restore_key, 'button_selector' => $master_state['button_selector']??'', 'message' => 'Restore in progress. Resuming monitoring...']);
    }
    public function create_backup_silent($is_scheduled = false) {
        if (!wp_doing_cron() && !defined('WP_CLI') && !(defined('DOING_AJAX') && DOING_AJAX)) {
            if (!current_user_can('manage_options')) {
                return false;
            }
        }
        $this->ensure_required_tables_exist();
        if (!$this->wp_filesystem) {
            return false;
        }
        if (!$this->wp_filesystem->is_dir($this->backup_dir)) {
            if (!wp_mkdir_p($this->backup_dir)) {
                return false;
            }
        }
        $transient_key = null;
        try {
            $random_string = wp_generate_password(14, false);
            $date_part = current_time("Y-m-d");
            $filename = "BACKUP-" . $date_part . "_" . $random_string . ".sql.gz";
            $transient_key = $this->_initiate_chunked_backup($filename);
            if ($is_scheduled) {
                $state = $this->get_process_state($transient_key);
                if ($state) {
                    $state['is_scheduled'] = true;
                    $this->set_process_state($transient_key, $state, DAY_IN_SECONDS);
                }
            }
            wp_schedule_single_event(time(), "optistate_run_silent_backup_chunk", [$transient_key]);
            return true;
        }
        catch(Exception $e) {
            $filename_to_log = isset($filename) ? $filename : 'unknown_backup.sql';
            $this->log_failed_backup_operation($filename_to_log, $e->getMessage(), 'scheduled');
            if ($transient_key) {
                $this->delete_process_state($transient_key);
            }
            return false;
        }
    }
    private function execute_backup_worker($transient_key, $is_silent_worker = false) {
        $original_time_limit = ini_get('max_execution_time');
        @set_time_limit(180);
        try {
            if (empty($transient_key) || strpos($transient_key, 'optistate_backup_') !== 0) return;
            $state = $this->get_process_state($transient_key);
            if ($state === false) return;
            if (!$is_silent_worker && empty($state['is_manual'])) return;
            $start_time = time();
            $max_exec_time = (int)@ini_get('max_execution_time');
            if ($max_exec_time <= 0) $max_exec_time = 30;
            $time_limit = max(20, $max_exec_time * 0.8);
            $chunks_processed = 0;
            $chunks_per_run = $this->get_chunks_per_run();
            try {
                $result = ['status' => 'running', 'state' => $state];
                while ((time() - $start_time < $time_limit) && ($chunks_processed < $chunks_per_run)) {
                    $result = $this->_perform_backup_chunk($state);
                    $state = $result['state'];
                    $chunks_processed++;
                    if ($result['status'] === 'done') break;
                }
                if ($result['status'] === 'done') {
                    $state['status'] = 'compressing';
                    $this->set_process_state($transient_key, $state, DAY_IN_SECONDS);
                    $this->enforce_backup_limit();
                    $this->save_backup_metadata($state['filepath'], $state['filename'], $state['start_time']);
                    $is_scheduled = !empty($state['is_scheduled']);
                    $log_type = $is_scheduled ? 'scheduled' : 'manual';
                    $this->log_manual_backup_operation($state['filename'], $state['start_time'], $log_type);
                    $this->delete_process_state($transient_key);
                    if (!empty($state['user_id'])) {
                        $this->process_store->delete('optistate_manual_backup_user_' . $state['user_id']);
                    }
                    if ($is_scheduled) {
                        do_action('optistate_async_backup_complete', $state['filename']);
                    } else {
                        $this->set_process_state($transient_key . '_complete', ['status' => 'done', 'filename' => $state['filename'], 'message' => esc_html__('Backup created successfully!', 'optistate') ], 300);
                    }
                } elseif ($result['status'] === 'running') {
                    $this->set_process_state($transient_key, $state, DAY_IN_SECONDS);
                    $hook = $is_silent_worker ? "optistate_run_silent_backup_chunk" : "optistate_run_manual_backup_chunk";
                    wp_schedule_single_event(time() + $this->get_reschedule_delay(), $hook, [$transient_key]);
                }
            }
            catch(Exception $e) {
                if (!empty($state['user_id'])) {
                    $this->process_store->delete('optistate_manual_backup_user_' . $state['user_id']);
                }
                $filename_to_log = $state['filename']??'unknown_backup.sql';
                $log_type = (!empty($state['is_scheduled']) || strpos($filename_to_log, 'SAFETY-RESTORE-') === 0) ? 'scheduled' : 'manual';
                $this->log_failed_backup_operation($filename_to_log, $e->getMessage(), $log_type);
                $this->delete_process_state($transient_key);
                if (isset($state['filepath']) && $this->wp_filesystem->exists($state['filepath'])) {
                    $this->wp_filesystem->delete($state['filepath']);
                }
                if (!$is_silent_worker) {
                    $this->set_process_state($transient_key . '_complete', ['status' => 'error', 'message' => $e->getMessage() ], 300);
                }
            }
        }
        finally {
            @set_time_limit($original_time_limit);
        }
    }
    public function run_manual_backup_chunk_worker($transient_key) {
        $this->execute_backup_worker($transient_key, false);
    }
    public function run_silent_backup_chunk_worker($transient_key) {
        $this->execute_backup_worker($transient_key, true);
    }
    private function enforce_backup_limit() {
        $max_backups = (int)$this->max_backups;
        if ($max_backups < 1 || !$this->wp_filesystem || !$this->wp_filesystem->is_dir($this->backup_dir)) {
            return;
        }
        $backup_dir = rtrim($this->backup_dir, '/') . '/';
        $backup_files = [];
        $list = $this->wp_filesystem->dirlist($backup_dir, true, false);
        if (!is_array($list) || empty($list)) {
            return;
        }
        foreach ($list as $filename => $file_details) {
            if (!isset($file_details['name']) || isset($file_details['type']) && $file_details['type'] === 'd') {
                continue;
            }
            $filename = $file_details['name'];
            if (!preg_match('/\.sql(\.gz)?$/i', $filename)) {
                continue;
            }
            if (strpos($filename, 'SAFETY-RESTORE-') === 0) {
                continue;
            }
            $timestamp = isset($file_details['lastmodunix']) ? (int)$file_details['lastmodunix'] : 0;
            $backup_files[] = ['filename' => $filename, 'created_timestamp' => $timestamp, 'fullpath' => $backup_dir . $filename, ];
        }
        $current_count = count($backup_files);
        if ($current_count <= $max_backups) {
            return;
        }
        usort($backup_files, function ($a, $b) {
            return $a['created_timestamp']<=>$b['created_timestamp'];
        });
        $to_delete = array_slice($backup_files, 0, $current_count - $max_backups);
        if (empty($to_delete)) {
            return;
        }
        global $wpdb;
        $meta_table = $wpdb->prefix . 'optistate_backup_metadata';
        foreach ($to_delete as $file) {
            $filepath = $file['fullpath'];
            $filename = $file['filename'];
            if ($this->wp_filesystem->exists($filepath)) {
                $this->wp_filesystem->delete($filepath);
            }
            if ($wpdb->delete($meta_table, ['filename' => $filename], ['%s']) === false) {
            }
            delete_transient('optistate_backup_integrity_' . md5($filename));
        }
    }
    public function ajax_delete_backup() {
        check_ajax_referer("optistate_backup_nonce", "nonce");
        $this->main_plugin->check_user_access();
        if (!$this->main_plugin->check_rate_limit("delete_backup", 2)) {
            wp_send_json_error(['message' => esc_html__('🕐 Please wait a few seconds before deleting again.', 'optistate') ], 429);
            return;
        }
        if (!$this->wp_filesystem) {
            wp_send_json_error(["message" => esc_html__("Failed to initialize filesystem.", "optistate") ]);
        }
        $filename = isset($_POST["filename"]) ? basename(wp_unslash($_POST["filename"])) : '';
        if (!preg_match('/\.sql(\.gz)?$/i', $filename)) {
            wp_send_json_error(["message" => esc_html__("Security violation: Invalid file type.", "optistate") ]);
            return;
        }
        if (strpos($filename, "..") !== false || strpos($filename, "/") !== false || strpos($filename, "\\") !== false) {
            wp_send_json_error(["message" => esc_html__("Invalid filename.", "optistate") ]);
        }
        $filepath = $this->backup_dir . $filename;
        $normalized_path = wp_normalize_path($filepath);
        $normalized_dir = wp_normalize_path($this->backup_dir);
        if (strpos($normalized_path, $normalized_dir) !== 0) {
            wp_send_json_error(["message" => esc_html__("Invalid file path.", "optistate") ]);
        }
        if (!$this->wp_filesystem->exists($filepath)) {
            wp_send_json_error(["message" => esc_html__("Backup file not found.", "optistate"), ]);
        }
        global $wpdb;
        $table_name = $wpdb->prefix . 'optistate_backup_metadata';
        $wpdb->delete($table_name, ['filename' => $filename], ['%s']);
        delete_transient('optistate_backup_integrity_' . md5($filename));
        $files_to_delete = [$filepath];
        $success = true;
        $errors = [];
        foreach ($files_to_delete as $file_to_delete) {
            if ($this->wp_filesystem->exists($file_to_delete) && !$this->wp_filesystem->delete($file_to_delete)) {
                $success = false;
                $errors[] = basename($file_to_delete);
            }
        }
        if ($success) {
            if ($this->wp_filesystem && $this->log_file_path) {
                $log_entries = [];
                if ($this->wp_filesystem->exists($this->log_file_path)) {
                    $content = $this->wp_filesystem->get_contents($this->log_file_path);
                    if ($content) {
                        $log_entries = json_decode($content, true);
                        if (!is_array($log_entries)) {
                            $log_entries = [];
                        }
                    }
                }
                $log_entry = ["timestamp" => time(), "type" => "manual", "date" => wp_date(get_option('date_format') . ' ' . get_option('time_format'), time()), "operation" => "🗑️ " . sprintf(__("Backup Deleted (%s)", "optistate"), $filename), "backup_filename" => "", "timezone" => get_option("timezone_string"), "gmt_offset" => get_option("gmt_offset") ];
                array_unshift($log_entries, $log_entry);
                $log_entries = array_slice($log_entries, 0, 200);
                $json_data = json_encode($log_entries, JSON_PRETTY_PRINT);
                if ($json_data) {
                    $this->wp_filesystem->put_contents($this->log_file_path, $json_data, FS_CHMOD_FILE);
                }
            }
            wp_send_json_success(["message" => esc_html__("Backup and all associated data deleted successfully!", "optistate"), ]);
        } else {
            wp_send_json_error(["message" => esc_html__("Failed to delete some files.", "optistate") ]);
        }
    }
    public function ajax_restore_backup() {
        check_ajax_referer("optistate_backup_nonce", "nonce");
        $this->main_plugin->check_user_access();
        $this->ensure_required_tables_exist();
        if (is_multisite()) {
            wp_send_json_error(["message" => esc_html__("🛑 Safety Stop! Database restore is not supported on Multisite installations to prevent network-wide data loss.", "optistate") ]);
            return;
        }
        $this->clear_all_integrity_caches();
        if (!$this->wp_filesystem) {
            wp_send_json_error(["message" => esc_html__("Failed to initialize filesystem.", "optistate") ]);
            return;
        }
        $step = isset($_POST['step']) ? sanitize_key($_POST['step']) : 'init';
        if ($step !== 'init') {
            wp_send_json_error(["message" => esc_html__("Invalid request step.", "optistate") ]);
            return;
        }
        $temp_decompressed_path = null;
        $class_instance = $this;
        register_shutdown_function(function () use ($class_instance) {
            if ($class_instance->get_process_state('optistate_restore_in_progress')) {
                $error = error_get_last();
                if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                    $log_filename = $class_instance->get_process_state('optistate_last_restore_filename') ? : 'unknown_file';
                    $error_message = 'Fatal Error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line'];
                    $class_instance->set_process_state('optistate_last_restore_error', $error_message, HOUR_IN_SECONDS);
                    $class_instance->set_process_state('optistate_last_restore_filename', $log_filename, HOUR_IN_SECONDS);
                    if ($class_instance->get_process_state("optistate_safety_backup")) {
                        $class_instance->trigger_async_rollback();
                    } else {
                        $class_instance->deactivate_maintenance_mode();
                        $class_instance->delete_process_state('optistate_restore_in_progress');
                    }
                }
            }
        });
        if ($this->get_process_state('optistate_restore_in_progress')) {
            wp_send_json_error(["message" => esc_html__("A restore process is already in progress. Please wait for it to complete.", "optistate") ]);
            return;
        }
        $filename = isset($_POST["filename"]) ? basename(sanitize_text_field(wp_unslash($_POST["filename"]))) : '';
        $filepath = trailingslashit($this->backup_dir) . $filename;
        if (!$this->wp_filesystem->exists($filepath)) {
            wp_send_json_error(["message" => esc_html__("Backup file not found: ", "optistate") . esc_html($filename) ]);
            return;
        }
        $file_size = $this->wp_filesystem->size($filepath);
        if ($file_size < 100) {
            wp_send_json_error(["message" => esc_html__("Backup file is too small or empty.", "optistate") ]);
            return;
        }
        $verification = $this->verify_backup_file($filepath, false);
        if ($verification['valid'] === false) {
            $error_message = sprintf(esc_html__('Restore Aborted: %s', 'optistate'), $verification['message']);
            $this->log_failed_restore_operation($filename, "Restore blocked. " . $verification['message']);
            wp_send_json_error(["message" => $error_message]);
            return;
        }
        if (!$this->main_plugin->check_rate_limit("restore_backup", 60)) {
            wp_send_json_error(["message" => esc_html__("🕔 Please wait a few seconds before restoring again.", "optistate") ]);
            return;
        }
        $normalized_path = wp_normalize_path($filepath);
        $normalized_dir = wp_normalize_path($this->backup_dir);
        if (strpos($normalized_path, $normalized_dir) !== 0) {
            wp_send_json_error(["message" => esc_html__("Invalid file path.", "optistate") ]);
            return;
        }
        $button_selector = '.restore-backup[data-file="' . esc_attr($filename) . '"]';
        try {
            if (preg_match('/\.sql\.gz$/i', $filepath)) {
                $upload_dir = wp_upload_dir();
                $temp_dir = trailingslashit($upload_dir['basedir']) . OPTISTATE::TEMP_DIR_NAME . '/';
                if (!$this->wp_filesystem->is_dir($temp_dir)) {
                    if (!$this->wp_filesystem->mkdir($temp_dir, FS_CHMOD_DIR, true)) {
                        wp_send_json_error(["message" => esc_html__("Failed to create temp directory for decompression.", "optistate") ]);
                        return;
                    }
                }
                $temp_decompressed_path = $temp_dir . 'decompressed-' . bin2hex(random_bytes(14)) . '.sql';
                try {
                    $decompression_key = 'optistate_decompress_task_' . bin2hex(random_bytes(14));
                    $task_data = ['status' => 'pending', 'source_path' => $filepath, 'dest_path' => $temp_decompressed_path, 'log_filename' => $filename, 'button_selector' => $button_selector, 'source_size' => $this->wp_filesystem->size($filepath), 'master_restore_key' => null, 'uploaded_file_info' => ['temp_filepath_to_delete' => $temp_decompressed_path], 'is_upload' => false];
                    $this->set_process_state($decompression_key, $task_data, 2 * HOUR_IN_SECONDS);
                    wp_schedule_single_event(time(), "optistate_run_decompression_chunk", [$decompression_key]);
                    wp_send_json_success(['status' => 'decompressing', 'decompression_key' => $decompression_key, 'message' => esc_html__('Decompression started...', 'optistate') ]);
                }
                catch(Exception $e) {
                    if ($temp_decompressed_path && $this->wp_filesystem->exists($temp_decompressed_path)) {
                        $this->wp_filesystem->delete($temp_decompressed_path);
                    }
                    throw $e;
                }
                return;
            }
            $final_sql_path = $filepath;
            $settings = $this->main_plugin->get_persistent_settings();
            $security_active = empty($settings['disable_restore_security']);
            if ($security_active) {
                $handle = @fopen($final_sql_path, 'r');
                if (!$handle) {
                    wp_send_json_error(["message" => esc_html__("Failed to open file for security scan.", "optistate") ]);
                    return;
                }
                $sample = fread($handle, 32768);
                fclose($handle);
                if ($sample === false) {
                    wp_send_json_error(["message" => esc_html__("Failed to read file for security scan.", "optistate") ]);
                    return;
                }
                if (preg_match('/<\?php|<\?=|<\s*\?|script\s*language\s*=\s*["\']?php["\']?|eval\s*\(|exec\s*\(|system\s*\(|passthru\s*\(|shell_exec\s*\(|base64_decode/i', $sample)) {
                    wp_send_json_error(["message" => esc_html__("Security risk detected. The backup file contains suspicious code.", "optistate") ]);
                    return;
                }
            }
            $response = $this->_initiate_master_restore_process($final_sql_path, $filename, $button_selector, []);
            wp_send_json_success($response);
        }
        catch(Exception $e) {
            if ($temp_decompressed_path && $this->wp_filesystem->exists($temp_decompressed_path)) {
                $this->wp_filesystem->delete($temp_decompressed_path);
            }
            $this->deactivate_maintenance_mode();
            $this->delete_process_state('optistate_restore_in_progress');
            $this->delete_process_state('optistate_last_restore_filename');
            wp_send_json_error(["message" => esc_html__("Failed to initiate restore: ", "optistate") . esc_html($e->getMessage()) ]);
        }
    }
    private function log_restore_operation($backup_filename, $queries_executed) {
        if (empty($this->log_file_path)) {
            return;
        }
        if (!$this->wp_filesystem || !$this->wp_filesystem->exists($this->log_file_path)) {
            return;
        }
        $log_entries = [];
        $json_data = $this->wp_filesystem->get_contents($this->log_file_path);
        if ($json_data !== false) {
            $log_entries = json_decode($json_data, true);
            if (!is_array($log_entries)) {
                $log_entries = [];
            }
        }
        $log_entry = ["timestamp" => time(), "type" => "scheduled", "date" => $this->main_plugin->format_timestamp(time()), "operation" => '🏁 ' . esc_html(sprintf(__('Database Restore Completed (%s)', 'optistate'), $backup_filename)), "backup_filename" => $backup_filename, "queries_executed" => $queries_executed, ];
        array_unshift($log_entries, $log_entry);
        $log_entries = array_slice($log_entries, 0, 200);
        $json_data = json_encode($log_entries, JSON_PRETTY_PRINT);
        if ($json_data !== false) {
            if ($this->wp_filesystem) {
                $this->wp_filesystem->put_contents($this->log_file_path, $json_data, FS_CHMOD_FILE);
            }
        }
        delete_transient('optistate_health_score');
        delete_transient(OPTISTATE::STATS_TRANSIENT);
        delete_transient('optistate_db_size_cache');
    }
    private function _decompress_file($source_gz_path, $dest_sql_path) {
        $original_time_limit = ini_get('max_execution_time');
        @set_time_limit(0);
        try {
            if (!$this->wp_filesystem) {
                throw new Exception(esc_html__("Filesystem not initialized.", "optistate"));
            }
            $use_cli = (defined('WP_CLI') && WP_CLI) && php_sapi_name() === 'cli';
            if ($use_cli || function_exists('exec')) {
                $source_path_shell = escapeshellarg($source_gz_path);
                $dest_path_shell = escapeshellarg($dest_sql_path);
                $pigz_path = @exec("which pigz 2>/dev/null", $pigz_output, $pigz_return);
                if ($pigz_return === 0 && !empty($pigz_path)) {
                    $command = $pigz_path . " -d -c " . $source_path_shell . " > " . $dest_path_shell . " 2>&1";
                    @exec($command, $output, $return_var);
                    if ($return_var === 0 && $this->wp_filesystem->exists($dest_sql_path) && $this->wp_filesystem->size($dest_sql_path) > 0) {
                        $this->wp_filesystem->chmod($dest_sql_path, 0600);
                        return true;
                    }
                }
                $gzip_path = $this->_get_gzip_path();
                if ($gzip_path !== false) {
                    $command = $gzip_path . " -d -c " . $source_path_shell . " > " . $dest_path_shell . " 2>&1";
                    @exec($command, $output, $return_var);
                    if ($return_var === 0 && $this->wp_filesystem->exists($dest_sql_path) && $this->wp_filesystem->size($dest_sql_path) > 0) {
                        $this->wp_filesystem->chmod($dest_sql_path, 0600);
                        return true;
                    }
                }
            }
            if (!function_exists('gzopen')) {
                throw new Exception(esc_html__("GZIP functions not available.", "optistate"));
            }
            $progress_key = 'optistate_decompress_' . md5($source_gz_path);
            $progress = $this->get_process_state($progress_key);
            $source_size = filesize($source_gz_path);
            if ($progress === false) {
                $progress = ['source_bytes_read' => 0, 'dest_bytes_written' => 0, 'started' => time(), 'source_size' => $source_size];
            }
            if ((time() - $progress['started']) > 7200) {
                if ($this->wp_filesystem->exists($dest_sql_path)) {
                    $this->wp_filesystem->delete($dest_sql_path);
                }
                $this->delete_process_state($progress_key);
                $progress = ['source_bytes_read' => 0, 'dest_bytes_written' => 0, 'started' => time(), 'source_size' => $source_size];
            }
            $resuming = ($progress['dest_bytes_written'] > 0);
            if ($resuming) {
                if (!$this->wp_filesystem->exists($dest_sql_path)) {
                    $resuming = false;
                    $progress['dest_bytes_written'] = 0;
                } else {
                    $actual_size = $this->wp_filesystem->size($dest_sql_path);
                    if ($actual_size !== $progress['dest_bytes_written']) {
                        $this->wp_filesystem->delete($dest_sql_path);
                        $resuming = false;
                        $progress['dest_bytes_written'] = 0;
                    }
                }
            }
            $gz_handle = @gzopen($source_gz_path, 'rb');
            if (!$gz_handle) {
                throw new Exception(esc_html__("Cannot open GZIP file.", "optistate"));
            }
            $write_mode = $resuming ? 'ab' : 'wb';
            $sql_handle = @fopen($dest_sql_path, $write_mode);
            if (!$sql_handle) {
                @gzclose($gz_handle);
                throw new Exception(esc_html__("Cannot open destination file.", "optistate"));
            }
            if ($resuming) {
                $seek_position = $progress['dest_bytes_written'];
                if (gzseek($gz_handle, $seek_position) === - 1) {
                    @gzclose($gz_handle);
                    @fclose($sql_handle);
                    $this->delete_process_state($progress_key);
                    $this->wp_filesystem->delete($dest_sql_path);
                    throw new Exception("GZIP stream seek failed. Restarting process.");
                }
                fseek($sql_handle, 0, SEEK_END);
            }
            $chunk_size = 512 * 1024;
            $start_time = time();
            $max_chunk_time = 20;
            $bytes_written_this_session = 0;
            while (!gzeof($gz_handle)) {
                if ($bytes_written_this_session > 0 && ($bytes_written_this_session % (10 * $chunk_size)) === 0) {
                    if ((time() - $start_time) >= $max_chunk_time) {
                        $progress['dest_bytes_written']+= $bytes_written_this_session;
                        $progress['source_bytes_read'] = gztell($gz_handle);
                        $this->set_process_state($progress_key, $progress, 2 * HOUR_IN_SECONDS);
                        @gzclose($gz_handle);
                        @fclose($sql_handle);
                        return 'INCOMPLETE';
                    }
                }
                $chunk = @gzread($gz_handle, $chunk_size);
                if ($chunk === false) {
                    @gzclose($gz_handle);
                    @fclose($sql_handle);
                    throw new Exception(esc_html__("GZIP read error.", "optistate"));
                }
                $chunk_len = strlen($chunk);
                if ($chunk_len === 0) {
                    break;
                }
                $written = @fwrite($sql_handle, $chunk);
                if ($written === false || $written !== $chunk_len) {
                    @gzclose($gz_handle);
                    @fclose($sql_handle);
                    throw new Exception(esc_html__("Disk write error. Check quotas.", "optistate"));
                }
                $bytes_written_this_session+= $written;
                unset($chunk);
            }
            @gzclose($gz_handle);
            @fclose($sql_handle);
            if (!$this->wp_filesystem->exists($dest_sql_path) || $this->wp_filesystem->size($dest_sql_path) < 100) {
                $this->wp_filesystem->delete($dest_sql_path);
                $this->delete_process_state($progress_key);
                throw new Exception(esc_html__("Invalid decompression output.", "optistate"));
            }
            $this->wp_filesystem->chmod($dest_sql_path, 0600);
            $this->delete_process_state($progress_key);
            return true;
        }
        catch(Exception $e) {
            if (isset($gz_handle) && is_resource($gz_handle)) @gzclose($gz_handle);
            if (isset($sql_handle) && is_resource($sql_handle)) @fclose($sql_handle);
            throw $e;
        }
        finally {
            @set_time_limit($original_time_limit);
        }
    }
    private function protect_temp_directory($temp_dir) {
        if (!$this->wp_filesystem || !$this->wp_filesystem->is_dir($temp_dir)) {
            return;
        }
        $rules = ['# WP Optimal State - Secure Temp Restore Directory', '# Prevent directory listing', 'Options -Indexes', '', '# Block all direct web access.', '<IfModule mod_authz_core.c>', '    Require all denied', '</IfModule>', '<IfModule !mod_authz_core.c>', '    Order deny,allow', '    Deny from all', '</IfModule>', ];
        $this->main_plugin->secure_directory($temp_dir, $rules);
    }
    private function get_client_ip() {
        $ip = '';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        $ip = filter_var($ip, FILTER_VALIDATE_IP);
        return $ip ? $ip : 'unknown';
    }
    public function show_maintenance_page_for_visitors() {
        if (!get_option('optistate_maintenance_mode_active')) {
            return;
        }
        if (current_user_can('manage_options')) {
            return;
        }
        if (function_exists('is_login') && is_login()) {
            return;
        }
        $title = esc_html__('Briefly unavailable for scheduled maintenance', "optistate");
        $message = '<h1>' . esc_html__('Briefly unavailable for scheduled maintenance.', "optistate") . '</h1><p>' . esc_html__('We are currently performing critical database maintenance.', "optistate") . '</p><p>' . esc_html__('Please check back in a minute.', "optistate") . '</p>';
        wp_die(wp_kses_post($message), esc_html($title), ['response' => 503, 'back_link' => false]);
    }
    private function activate_maintenance_mode() {
        update_option('optistate_maintenance_mode_active', true, false);
    }
    public function deactivate_maintenance_mode() {
        delete_option('optistate_maintenance_mode_active');
    }
    private function validate_sql_file_simple($filepath) {
        if (!$this->wp_filesystem) {
            return ['valid' => false, 'message' => esc_html__('Filesystem error.', "optistate") ];
        }
        $is_gzipped = preg_match('/\.gz$/i', $filepath);
        $handle = $is_gzipped ? @gzopen($filepath, 'r') : @fopen($filepath, 'r');
        if (!$handle) {
            return ['valid' => false, 'message' => esc_html__('Cannot read file.', "optistate") ];
        }
        global $wpdb;
        $current_db = DB_NAME;
        $core_table_suffixes = ['_options', '_posts', '_users'];
        $found_tables = [];
        $found_db_name = false;
        $has_valid_sql = false;
        $bytes_read = 0;
        $max_bytes = 500 * 1024;
        $lines_read = 0;
        $max_lines = 5000;
        while (($line = ($is_gzipped ? gzgets($handle) : fgets($handle))) !== false) {
            $bytes_read+= strlen($line);
            $lines_read++;
            if ($bytes_read > $max_bytes || $lines_read > $max_lines) {
                if ($found_db_name && $has_valid_sql && count($found_tables) >= 3) {
                    break;
                }
            }
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            if (!$found_db_name && strpos($line, '--') === 0) {
                if (preg_match('/--\s*Database:\s*(.+)$/i', $line, $matches)) {
                    $file_db_name = trim(trim($matches[1]), '`');
                    $found_db_name = true;
                    if ($file_db_name !== $current_db) {
                        $is_gzipped ? gzclose($handle) : fclose($handle);
                        return ['valid' => false, 'message' => sprintf(__('Database mismatch!<br>File: "%1$s"<br>Current: "%2$s"', 'optistate'), $file_db_name, $current_db) ];
                    }
                }
            }
            if (!$has_valid_sql && preg_match('/^\s*(CREATE|INSERT|DROP|ALTER|SET)/i', $line)) {
                $has_valid_sql = true;
            }
            if (count($found_tables) < 3) {
                if (preg_match('/(?:CREATE\s+TABLE|INSERT\s+INTO)\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $line, $matches)) {
                    $table_name = $matches[1];
                    foreach ($core_table_suffixes as $suffix) {
                        if (!in_array($suffix, $found_tables)) {
                            if (substr($table_name, -strlen($suffix)) === $suffix) {
                                $found_tables[] = $suffix;
                            }
                        }
                    }
                }
            }
            if ($found_db_name && $has_valid_sql && count($found_tables) >= 3) {
                break;
            }
        }
        $is_gzipped ? gzclose($handle) : fclose($handle);
        if (!$has_valid_sql) {
            return ['valid' => false, 'message' => esc_html__('Not a valid SQL file.', "optistate") ];
        }
        if (!$found_db_name) {
            return ['valid' => false, 'message' => esc_html__('Cannot verify database name.', "optistate") ];
        }
        if (count($found_tables) < 3) {
            $missing = array_diff($core_table_suffixes, $found_tables);
            return ['valid' => false, 'message' => sprintf(__('Missing core tables: %s', 'optistate'), implode(', ', $missing)) ];
        }
        return ['valid' => true, 'message' => esc_html__('Validation passed.', "optistate") ];
    }
    private function log_failed_restore_operation($backup_filename, $reason) {
        if (empty($this->log_file_path)) {
            return;
        }
        if (!$this->wp_filesystem) {
            return;
        }
        $log_entries = [];
        if ($this->wp_filesystem->exists($this->log_file_path)) {
            $json_data = $this->wp_filesystem->get_contents($this->log_file_path);
            if ($json_data !== false) {
                $log_entries = json_decode($json_data, true);
                if (!is_array($log_entries)) {
                    $log_entries = [];
                }
            }
        }
        $log_entry = ["timestamp" => time(), "type" => "scheduled", "date" => $this->main_plugin->format_timestamp(time()), "operation" => '🛑 ' . esc_html__('Database Restore Failed', 'optistate') . ' + ⏪ ' . esc_html__('Rollback Succeeded', 'optistate'), "backup_filename" => $backup_filename, "details" => $reason, ];
        array_unshift($log_entries, $log_entry);
        $log_entries = array_slice($log_entries, 0, 200);
        $json_data = json_encode($log_entries, JSON_PRETTY_PRINT);
        if ($json_data !== false) {
            $this->wp_filesystem->put_contents($this->log_file_path, $json_data, FS_CHMOD_FILE);
        }
    }
    private function log_failed_backup_operation($backup_filename, $error_message, $type = 'manual') {
        if (empty($this->log_file_path) || !$this->wp_filesystem) {
            return;
        }
        $log_entries = [];
        if ($this->wp_filesystem->exists($this->log_file_path)) {
            $json_data = $this->wp_filesystem->get_contents($this->log_file_path);
            if ($json_data !== false) {
                $log_entries = json_decode($json_data, true);
            }
        }
        if (!is_array($log_entries)) {
            $log_entries = [];
        }
        $log_timestamp_utc = time();
        $local_timestamp_val = $log_timestamp_utc + ((float)get_option('gmt_offset') * HOUR_IN_SECONDS);
        $formatted_date_string = $this->main_plugin->format_timestamp($log_timestamp_utc, true);
        if (strpos($backup_filename, 'SAFETY-RESTORE-') === 0) {
            $type = 'scheduled';
        }
        $operation_text = '❌ ' . esc_html(sprintf(__('Backup Failed (%s)', 'optistate'), $error_message));
        $log_entry = ["timestamp" => $local_timestamp_val, "type" => $type, "date" => $formatted_date_string, "operation" => $operation_text, "backup_filename" => $backup_filename, "file_size" => 0, ];
        array_unshift($log_entries, $log_entry);
        $log_entries = array_slice($log_entries, 0, 200);
        $json_data = json_encode($log_entries, JSON_PRETTY_PRINT);
        if ($json_data !== false) {
            $this->wp_filesystem->put_contents($this->log_file_path, $json_data, FS_CHMOD_FILE);
        }
    }
    private function _format_row_for_sql($row, $dbh = null) {
        global $wpdb;
        if (!$dbh && isset($wpdb->dbh)) {
            $dbh = $wpdb->dbh;
        }
        $row_values = [];
        $use_mysqli = ($dbh instanceof mysqli);
        foreach ($row as $value) {
            if ($value === null) {
                $row_values[] = "NULL";
            } elseif (is_int($value) || is_float($value)) {
                $row_values[] = $value;
            } else {
                $string_val = (string)$value;
                if ($use_mysqli) {
                    $row_values[] = "'" . mysqli_real_escape_string($dbh, $string_val) . "'";
                } else {
                    $row_values[] = "'" . esc_sql($string_val) . "'";
                }
            }
        }
        return "(" . implode(",", $row_values) . ")";
    }
    private function _initiate_master_restore_process($sql_filepath, $log_filename, $button_selector, $uploaded_file_info = []) {
        if (!$this->wp_filesystem->exists($sql_filepath)) {
            throw new Exception(esc_html__("SQL file not found: ", "optistate") . $sql_filepath);
        }
        $db_name_validation = $this->validate_sql_file_simple($sql_filepath);
        if ($db_name_validation['valid'] === false) {
            $this->log_failed_restore_operation($log_filename, "Restore blocked. " . $db_name_validation['message']);
            throw new Exception($db_name_validation['message']);
        }
        $space_check = $this->check_sufficient_disk_space($sql_filepath);
        if (!$space_check['success']) {
            throw new Exception($space_check['message']);
        }
        $this->activate_maintenance_mode();
        $this->log_restore_start_operation($log_filename, "manual");
        $safety_filename = "SAFETY-RESTORE-" . current_time("Y-m-d_His") . ".sql.gz";
        $safety_filepath = trailingslashit($this->backup_dir) . $safety_filename;
        $safety_transient_key = $this->_initiate_chunked_backup($safety_filename, ['is_safety_backup' => true, 'user_id' => get_current_user_id() ]);
        $this->set_process_state("optistate_safety_backup", $safety_filepath, 2 * HOUR_IN_SECONDS);
        $master_restore_key = 'optistate_master_restore_' . bin2hex(random_bytes(14));
        $temp_filename = basename($sql_filepath);
        $master_state = ['status' => 'safety_backup_starting', 'message' => __('CREATING SAFETY BACKUP ....', 'optistate'), 'start_time' => time(), 'restore_target' => ['type' => 'temp_path', 'value' => $temp_filename], 'safety_backup_key' => $safety_transient_key, 'restore_key' => null, 'button_selector' => $button_selector];
        $this->set_process_state($master_restore_key, $master_state, 2 * HOUR_IN_SECONDS);
        $this->set_process_state('optistate_restore_in_progress', $master_restore_key, 2 * HOUR_IN_SECONDS);
        $this->set_process_state('optistate_last_restore_filename', $log_filename, 2 * HOUR_IN_SECONDS);
        $temp_transient_key = 'optistate_temp_restore_' . $temp_filename;
        $transient_data = ['path' => $sql_filepath, 'original_name' => $log_filename, 'size' => $this->wp_filesystem->size($sql_filepath), 'uploaded' => time(), 'user_id' => get_current_user_id(), 'ip_address' => $this->get_client_ip(), 'is_decompressed_backup' => !empty($uploaded_file_info) ? false : true];
        if (!empty($uploaded_file_info)) {
            $transient_data = array_merge($transient_data, $uploaded_file_info);
        }
        $this->set_process_state($temp_transient_key, $transient_data, 2 * HOUR_IN_SECONDS);
        wp_schedule_single_event(time(), "optistate_run_safety_backup_chunk", [$master_restore_key]);
        return ['status' => 'starting', 'master_restore_key' => $master_restore_key, 'message' => __('Safety backup initiated... Restore will proceed in background.', 'optistate') ];
    }
    private function _process_batched_insert($db, $query, $insert_batch_size, $queries_executed) {
        if (stripos($query, 'INSERT INTO') !== 0) {
            return ['status' => 'success', 'queries_executed' => $queries_executed];
        }
        $values_pos = stripos($query, ' VALUES ');
        if ($values_pos === false) {
            return ['status' => 'success', 'queries_executed' => $queries_executed];
        }
        $header = substr($query, 0, $values_pos) . ' VALUES ';
        $start_of_data_index = $values_pos + 7;
        $len = strlen($query);
        $end_of_data_index = $len;
        if ($end_of_data_index > $start_of_data_index && $query[$len - 1] === ';') {
            $end_of_data_index--;
        }
        $batch_rows = [];
        $start = $start_of_data_index;
        $depth = 0;
        $in_quotes = false;
        for ($c = $start_of_data_index;$c < $end_of_data_index;$c++) {
            $char = $query[$c];
            if ($in_quotes) {
                if ($char === '\\') {
                    $c++;
                } elseif ($char === "'") {
                    $in_quotes = false;
                }
            } elseif ($char === "'") {
                $in_quotes = true;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($depth === 0 && $char === ',') {
                if ($c > $start) {
                    $row = substr($query, $start, $c - $start);
                    $batch_rows[] = $row;
                    $start = $c + 1;
                    if (count($batch_rows) >= $insert_batch_size) {
                        $batch_query = $header . implode(', ', $batch_rows) . ';';
                        $result = $db->query($batch_query);
                        if ($result === false) {
                            return ['status' => 'error', 'queries_executed' => $queries_executed, 'message' => $db->error . ' (near: ' . substr($batch_query, 0, 150) . '...)'];
                        }
                        $queries_executed++;
                        $batch_rows = [];
                    }
                } else {
                    $start = $c + 1;
                }
            }
        }
        if ($depth === 0 && $end_of_data_index > $start) {
            $row = substr($query, $start, $end_of_data_index - $start);
            $batch_rows[] = $row;
        }
        if (!empty($batch_rows)) {
            $batch_query = $header . implode(', ', $batch_rows) . ';';
            $result = $db->query($batch_query);
            if ($result === false) {
                return ['status' => 'error', 'queries_executed' => $queries_executed, 'message' => $db->error . ' (near: ' . substr($batch_query, 0, 150) . '...)'];
            }
            $queries_executed++;
        }
        return ['status' => 'success', 'queries_executed' => $queries_executed];
    }
    private function _parse_create_table_for_indexes($create_query, $temp_table_name) {
        $alter_queries = [];
        if (!preg_match('/CREATE\s+TABLE/i', $create_query)) {
            return ['create_table_query' => $create_query, 'alter_queries' => []];
        }
        $first_paren = strpos($create_query, '(');
        $last_paren = strrpos($create_query, ')');
        if ($first_paren === false || $last_paren === false || $last_paren <= $first_paren) {
            $modified_query = preg_replace('/CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?[`\'"]?([a-zA-Z0-9_]+)[`\'"]?/i', 'CREATE TABLE $1`' . esc_sql($temp_table_name) . '`', $create_query, 1);
            return ['create_table_query' => $modified_query, 'alter_queries' => []];
        }
        $create_header = substr($create_query, 0, $first_paren + 1);
        $definitions_str = substr($create_query, $first_paren + 1, $last_paren - $first_paren - 1);
        $create_footer = substr($create_query, $last_paren);
        $create_header = preg_replace('/CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?[`\'"]?([a-zA-Z0-9_]+)[`\'"]?/i', 'CREATE TABLE $1`' . esc_sql($temp_table_name) . '`', $create_header, 1);
        $definitions = [];
        $len = strlen($definitions_str);
        $paren_level = 0;
        $in_quote = false;
        $quote_char = '';
        $current_def = '';
        for ($i = 0;$i < $len;$i++) {
            $char = $definitions_str[$i];
            if ($in_quote) {
                if ($char === $quote_char && ($i === 0 || $definitions_str[$i - 1] !== '\\')) {
                    $in_quote = false;
                }
            } elseif ($char === "'" || $char === '"' || $char === '`') {
                $in_quote = true;
                $quote_char = $char;
            } elseif ($char === '(') {
                $paren_level++;
            } elseif ($char === ')') {
                $paren_level--;
            } elseif ($char === ',' && $paren_level === 0) {
                $definitions[] = $current_def;
                $current_def = '';
                continue;
            }
            $current_def.= $char;
        }
        if (!empty(trim($current_def))) {
            $definitions[] = $current_def;
        }
        $essential_definitions = [];
        $deferrable_indexes = [];
        foreach ($definitions as $def) {
            $trimmed_def = trim($def);
            if (empty($trimmed_def)) {
                continue;
            }
            if (preg_match('/^\s*PRIMARY\s+KEY\s*\(/i', $trimmed_def)) {
                $essential_definitions[] = $trimmed_def;
            } elseif (preg_match('/^\s*UNIQUE\s+(KEY|INDEX)\s+/i', $trimmed_def)) {
                $essential_definitions[] = $trimmed_def;
            } elseif (preg_match('/^\s*CONSTRAINT\s+/i', $trimmed_def)) {
                $essential_definitions[] = $trimmed_def;
            } elseif (preg_match('/^\s*(KEY|INDEX|FULLTEXT|SPATIAL)\s+/i', $trimmed_def)) {
                $deferrable_indexes[] = $trimmed_def;
            } else {
                $essential_definitions[] = $trimmed_def;
            }
        }
        $new_create_query = $create_header . "\n" . implode(",\n", $essential_definitions) . "\n" . $create_footer;
        if (!empty($deferrable_indexes)) {
            $alter_prefix = "ALTER TABLE `" . esc_sql($temp_table_name) . "` ";
            $alter_parts = [];
            foreach ($deferrable_indexes as $index_line) {
                $alter_parts[] = "ADD " . $index_line;
            }
            $alter_queries[] = $alter_prefix . implode(', ', $alter_parts) . ';';
        }
        return ['create_table_query' => $new_create_query, 'alter_queries' => $alter_queries];
    }
    public function ajax_check_manual_backup_on_load() {
        check_ajax_referer("optistate_backup_nonce", "nonce");
        $user_id = get_current_user_id();
        if ($user_id === 0) {
            wp_send_json_success(['status' => 'none']);
            return;
        }
        $transient_key = $this->process_store->get('optistate_manual_backup_user_' . $user_id);
        if (empty($transient_key) || strpos($transient_key, 'optistate_backup_') !== 0) {
            wp_send_json_success(['status' => 'none']);
            return;
        }
        $state = $this->get_process_state($transient_key);
        if ($state === false) {
            $this->process_store->delete('optistate_manual_backup_user_' . $user_id);
            wp_send_json_success(['status' => 'stalled']);
            return;
        }
        wp_send_json_success(['status' => 'running', 'transient_key' => $transient_key]);
    }
    public function run_safety_backup_chunk_worker($master_restore_key) {
        $original_time_limit = ini_get('max_execution_time');
        @set_time_limit(240);
        try {
            $class_instance = $this;
            register_shutdown_function(function () use ($class_instance, $master_restore_key) {
                $error = error_get_last();
                if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                    $master_state = $class_instance->get_process_state($master_restore_key);
                    if ($master_state) {
                        $master_state['status'] = 'error';
                        $master_state['message'] = esc_html__('Fatal error during safety backup: ', 'optistate') . esc_html($error['message']);
                        $class_instance->set_process_state($master_restore_key, $master_state, 10 * MINUTE_IN_SECONDS);
                    }
                    $class_instance->deactivate_maintenance_mode();
                    $class_instance->delete_process_state('optistate_restore_in_progress');
                    $class_instance->delete_process_state('optistate_last_restore_filename');
                    $class_instance->delete_process_state('optistate_safety_backup');
                }
            });
            if (empty($master_restore_key) || strpos($master_restore_key, 'optistate_master_restore_') !== 0) {
                return;
            }
            $master_state = $this->get_process_state($master_restore_key);
            if ($master_state === false || !isset($master_state['safety_backup_key'])) {
                $this->deactivate_maintenance_mode();
                $this->delete_process_state('optistate_restore_in_progress');
                return;
            }
            $safety_backup_key = $master_state['safety_backup_key'];
            $safety_state = $this->get_process_state($safety_backup_key);
            if ($safety_state === false) {
                $master_state['status'] = 'error';
                $master_state['message'] = esc_html__('Safety backup session expired.', 'optistate');
                $this->set_process_state($master_restore_key, $master_state, 10 * MINUTE_IN_SECONDS);
                $this->deactivate_maintenance_mode();
                $this->delete_process_state('optistate_restore_in_progress');
                return;
            }
            try {
                $start_time = time();
                $max_exec_time = (int)@ini_get('max_execution_time');
                if ($max_exec_time <= 0) {
                    $max_exec_time = 30;
                }
                $time_limit = max(20, $max_exec_time * 0.8);
                $result = ['status' => 'running', 'state' => $safety_state];
                $chunks_processed = 0;
                while (time() - $start_time < $time_limit && $chunks_processed < $this->get_chunks_per_run()) {
                    $result = $this->_perform_backup_chunk($safety_state);
                    $safety_state = $result['state'];
                    $chunks_processed++;
                    if ($result['status'] === 'done') {
                        break;
                    }
                }
                $state = $result['state'];
                if ($result['status'] === 'running') {
                    $this->set_process_state($safety_backup_key, $state, DAY_IN_SECONDS);
                    $master_state['status'] = 'safety_backup_running';
                    $master_state['message'] = sprintf(__('CREATING SAFETY BACKUP ....', 'optistate'));
                    $this->set_process_state($master_restore_key, $master_state, 2 * HOUR_IN_SECONDS);
                    wp_schedule_single_event(time() + $this->get_reschedule_delay(), "optistate_run_safety_backup_chunk", [$master_restore_key]);
                } elseif ($result['status'] === 'done') {
                    $this->delete_process_state($safety_backup_key);
                    $this->enforce_backup_limit();
                    $this->save_backup_metadata($state['filepath'], $state['filename'], $state['start_time']);
                    $this->log_manual_backup_operation($state['filename'], $state['start_time'], 'scheduled');
                    if (!empty($state['user_id'])) {
                        $this->process_store->delete('optistate_manual_backup_user_' . $state['user_id']);
                    }
                    $master_state['status'] = 'restore_starting';
                    $master_state['message'] = esc_html__('SAFETY BACKUP COMPLETE ....', 'optistate');
                    $this->set_process_state($master_restore_key, $master_state, 2 * HOUR_IN_SECONDS);
                    wp_schedule_single_event(time(), "optistate_run_restore_init", [$master_restore_key]);
                }
            }
            catch(Exception $e) {
                $filename_to_log = isset($safety_state['filename']) ? $safety_state['filename'] : 'unknown_safety_backup.sql';
                $this->log_failed_backup_operation($filename_to_log, $e->getMessage(), 'scheduled');
                $this->delete_process_state($safety_backup_key);
                if (!empty($safety_state['user_id'])) {
                    $this->process_store->delete('optistate_manual_backup_user_' . $safety_state['user_id']);
                }
                if ($safety_state && isset($safety_state['filepath']) && $this->wp_filesystem->exists($safety_state['filepath'])) {
                    $this->wp_filesystem->delete($safety_state['filepath']);
                }
                $master_state['status'] = 'error';
                $master_state['message'] = esc_html__('Safety backup failed: ', 'optistate') . esc_html($e->getMessage());
                $this->set_process_state($master_restore_key, $master_state, 10 * MINUTE_IN_SECONDS);
                $this->deactivate_maintenance_mode();
                $this->delete_process_state('optistate_restore_in_progress');
                $this->delete_process_state('optistate_last_restore_filename');
                $this->delete_process_state('optistate_safety_backup');
            }
        }
        finally {
            @set_time_limit($original_time_limit);
        }
    }
    public function run_restore_init_worker($master_restore_key) {
        $original_time_limit = ini_get('max_execution_time');
        @set_time_limit(300);
        try {
            $class_instance = $this;
            register_shutdown_function(function () use ($class_instance, $master_restore_key) {
                $error = error_get_last();
                if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                    $master_state = $class_instance->get_process_state($master_restore_key);
                    if ($master_state) {
                        $master_state['status'] = 'error';
                        $master_state['message'] = esc_html__('Fatal error during restore init: ', 'optistate') . esc_html($error['message']);
                        $class_instance->set_process_state($master_restore_key, $master_state, 10 * MINUTE_IN_SECONDS);
                    }
                    if ($class_instance->get_process_state("optistate_safety_backup")) {
                        $class_instance->set_process_state('optistate_last_restore_error', $error['message'], HOUR_IN_SECONDS);
                        $class_instance->trigger_async_rollback($master_restore_key);
                    } else {
                        $class_instance->deactivate_maintenance_mode();
                        $class_instance->delete_process_state('optistate_restore_in_progress');
                        if (method_exists($class_instance, 'cleanup_old_tables_after_restore')) {
                            $class_instance->cleanup_old_tables_after_restore();
                        }
                    }
                }
            });
            if (empty($master_restore_key) || strpos($master_restore_key, 'optistate_master_restore_') !== 0) {
                return;
            }
            $master_state = $this->get_process_state($master_restore_key);
            if ($master_state === false || !isset($master_state['restore_target'])) {
                $this->deactivate_maintenance_mode();
                $this->delete_process_state('optistate_restore_in_progress');
                return;
            }
            $target_data = $master_state['restore_target'];
            $log_filename = 'unknown_file';
            try {
                if ($target_data['type'] !== 'temp_path') {
                    throw new Exception(esc_html__("Invalid restore target type. Expected temp_path.", "optistate"));
                }
                $temp_filename = $target_data['value'];
                $temp_transient_key = 'optistate_temp_restore_' . $temp_filename;
                $file_info = $this->get_process_state($temp_transient_key);
                if (!$file_info || !isset($file_info['path'])) {
                    throw new Exception(esc_html__("Temp file session expired or invalid.", "optistate"));
                }
                $filepath = $file_info['path'];
                $log_filename = $file_info['original_name']??$temp_filename;
                if (!$this->wp_filesystem->exists($filepath)) {
                    throw new Exception(esc_html__("SQL file not found: ", "optistate") . basename($filepath));
                }
                $file_size = $this->wp_filesystem->size($filepath);
                if ($file_size < 100) {
                    throw new Exception(esc_html__("File is too small or corrupt.", "optistate"));
                }
                $uploaded_file_info = ['temp_transient_to_delete' => $temp_transient_key];
                if (!empty($file_info['is_decompressed_backup'])) {
                    $uploaded_file_info['temp_filepath_to_delete'] = $filepath;
                }
                $restore_key = $this->_initiate_chunked_restore($filepath, $log_filename, $uploaded_file_info);
                $master_state['status'] = 'restore_running';
                $master_state['message'] = esc_html__('RESTORING DATABASE ....', 'optistate');
                $master_state['restore_key'] = $restore_key;
                $this->set_process_state($master_restore_key, $master_state, 2 * HOUR_IN_SECONDS);
                $this->set_process_state('optistate_current_restore_key', $restore_key, 90 * MINUTE_IN_SECONDS);
                wp_schedule_single_event(time(), "optistate_run_restore_chunk", [$master_restore_key]);
            }
            catch(Exception $e) {
                $master_state['status'] = 'error';
                $master_state['message'] = esc_html__('Restore failed to start: ', 'optistate') . esc_html($e->getMessage());
                $this->set_process_state($master_restore_key, $master_state, 10 * MINUTE_IN_SECONDS);
                $this->deactivate_maintenance_mode();
                $this->delete_process_state('optistate_restore_in_progress');
                $this->log_failed_restore_operation($log_filename, "Restore failed on initiation: " . $e->getMessage());
                if ($this->get_process_state("optistate_safety_backup")) {
                    $this->set_process_state('optistate_last_restore_error', $e->getMessage(), HOUR_IN_SECONDS);
                    $this->trigger_async_rollback($master_restore_key);
                }
            }
        }
        finally {
            @set_time_limit($original_time_limit);
        }
    }
    public function run_decompression_chunk_worker($decompression_key) {
        $original_time_limit = ini_get('max_execution_time');
        @set_time_limit(180);
        try {
            if (empty($decompression_key) || strpos($decompression_key, 'optistate_decompress_task_') !== 0) {
                return;
            }
            $task_data = $this->get_process_state($decompression_key);
            if ($task_data === false) {
                return;
            }
            try {
                if (empty($task_data['space_check_passed'])) {
                    $space_check = $this->check_sufficient_disk_space($task_data['source_path']);
                    
                    if (!$space_check['success']) {
                        throw new Exception($space_check['message']);
                    }
                    $task_data['space_check_passed'] = true;
                    $this->set_process_state($decompression_key, $task_data, 2 * HOUR_IN_SECONDS);
                }
                $result = $this->_decompress_file($task_data['source_path'], $task_data['dest_path']);
                if ($result === 'INCOMPLETE') {
                    $progress_key = 'optistate_decompress_' . md5($task_data['source_path']);
                    $decompress_progress = $this->get_process_state($progress_key);
                    if ($decompress_progress && isset($decompress_progress['dest_bytes_written'])) {
                        $task_data['status'] = 'decompressing';
                        $task_data['bytes_written'] = $decompress_progress['dest_bytes_written'];
                    } else {
                        $task_data['status'] = 'decompressing';
                        $task_data['bytes_written'] = 0;
                    }
                    $this->set_process_state($decompression_key, $task_data, 2 * HOUR_IN_SECONDS);
                    wp_schedule_single_event(time() + 3, "optistate_run_decompression_chunk", [$decompression_key]);
                } elseif ($result === true) {
                   $final_sql_path = $task_data['dest_path'];
                   $log_filename = $task_data['log_filename'];
                   $button_selector = $task_data['button_selector'];
                   $uploaded_file_info = $task_data['uploaded_file_info'];
                   $settings = $this->main_plugin->get_persistent_settings();
                   $security_active = empty($settings['disable_restore_security']);
                   if ($security_active) {
                        $handle = @fopen($final_sql_path, 'r');
                        if (!$handle) {
                            throw new Exception(esc_html__("Failed to open decompressed file for security scan.", "optistate"));
                        }
                        $sample = fread($handle, 32768);
                        fclose($handle);
                        if ($sample === false) {
                            throw new Exception(esc_html__("Failed to read decompressed file for security scan.", "optistate"));
                        }
                        if (preg_match('/<\?php|<\?=|<\s*\?|script\s*language\s*=\s*["\']?php["\']?|eval\s*\(|exec\s*\(|system\s*\(|passthru\s*\(|shell_exec\s*\(|base64_decode/i', $sample)) {
                            throw new Exception(esc_html__("Security risk detected. The decompressed file contains suspicious code.", "optistate"));
                        }
                    }
                    $response = $this->_initiate_master_restore_process($final_sql_path, $log_filename, $button_selector, $uploaded_file_info);
                        if (!empty($task_data['is_upload']) && !empty($task_data['uploaded_gz_path'])) {
                            if ($this->wp_filesystem->exists($task_data['uploaded_gz_path'])) {
                                $this->wp_filesystem->delete($task_data['uploaded_gz_path']);
                            }
                        }
                        $task_data['status'] = 'restore_starting';
                        $task_data['master_restore_key'] = $response['master_restore_key'];
                        $this->set_process_state($decompression_key, $task_data, 10 * MINUTE_IN_SECONDS);
                    } else {
                    throw new Exception(esc_html__("Unknown decompression error.", "optistate"));
                }
            }
            catch(Exception $e) {
                $task_data['status'] = 'error';
                $task_data['message'] = $e->getMessage();
                $this->set_process_state($decompression_key, $task_data, 10 * MINUTE_IN_SECONDS);
                if (!empty($task_data['is_upload']) && !empty($task_data['uploaded_gz_path'])) {
                    if ($this->wp_filesystem->exists($task_data['uploaded_gz_path'])) {
                        $this->wp_filesystem->delete($task_data['uploaded_gz_path']);
                    }
                }
                if ($this->wp_filesystem->exists($task_data['dest_path'])) {
                    $this->wp_filesystem->delete($task_data['dest_path']);
                }
                $this->log_failed_restore_operation($task_data['log_filename'], "Decompression/Restore Init failed: " . $e->getMessage());
            }
        }
        finally {
            @set_time_limit($original_time_limit);
        }
    }
    public function ajax_check_decompression_status() {
        check_ajax_referer("optistate_backup_nonce", "nonce");
        $decompression_key = isset($_POST['decompression_key']) ? sanitize_text_field($_POST['decompression_key']) : '';
        if (empty($decompression_key) || strpos($decompression_key, 'optistate_decompress_task_') !== 0) {
            wp_send_json_error(['status' => 'error', 'message' => __('Invalid decompression key.', 'optistate') ]);
            return;
        }
        $task_data = $this->get_process_state($decompression_key);
        if ($task_data === false) {
            wp_send_json_error(['status' => 'error', 'message' => __('Decompression session expired or not found. Please try again.', 'optistate') ]);
            return;
        }
        $status = $task_data['status'];
        if ($status === 'pending' || $status === 'decompressing') {
            wp_send_json_success(['status' => 'decompressing', 'message' => sprintf(__('DECOMPRESSING BACKUP ....', 'optistate')) ]);
        } elseif ($status === 'restore_starting') {
            $this->delete_process_state($decompression_key);
            wp_send_json_success(['status' => 'restore_starting', 'message' => esc_html__('Decompression complete! Initiating restore...', 'optistate'), 'master_restore_key' => $task_data['master_restore_key']]);
        } elseif ($status === 'error') {
            $this->delete_process_state($decompression_key);
            wp_send_json_error(['status' => 'error', 'message' => $task_data['message']??__('Decompression failed.', 'optistate') ]);
        } else {
            wp_send_json_error(['status' => 'error', 'message' => __('Unknown decompression status.', 'optistate') ]);
        }
    }
    public function run_restore_chunk_worker($master_restore_key) {
        $original_time_limit = ini_get('max_execution_time');
        @set_time_limit(300);
        try {
            $class_instance = $this;
            register_shutdown_function(function () use ($class_instance, $master_restore_key) {
                $error = error_get_last();
                if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                    return;
                }
                $master_state = $class_instance->get_process_state($master_restore_key);
                if ($master_state === false) {
                    if (get_option('optistate_maintenance_mode_active')) {
                        $class_instance->deactivate_maintenance_mode();
                    }
                    return;
                }
                $master_state['status'] = 'error';
                $master_state['message'] = esc_html__('Fatal error during restore: ', 'optistate') . esc_html($error['message']);
                $class_instance->set_process_state($master_restore_key, $master_state, 10 * MINUTE_IN_SECONDS);
                if ($class_instance->get_process_state('optistate_instant_rollback_tables')) {
                    $class_instance->trigger_async_rollback($master_restore_key);
                } else {
                    $class_instance->deactivate_maintenance_mode();
                    $class_instance->delete_process_state('optistate_restore_in_progress');
                    $class_instance->delete_process_state('optistate_last_restore_filename');
                    if (isset($master_state['restore_key'])) {
                        $class_instance->delete_process_state($master_state['restore_key']);
                    }
                }
            });
            if (empty($master_restore_key) || strpos($master_restore_key, 'optistate_master_restore_') !== 0) {
                return;
            }
            $master_state = $this->get_process_state($master_restore_key);
            if ($master_state === false || !isset($master_state['restore_key'])) {
                $this->deactivate_maintenance_mode();
                $this->delete_process_state('optistate_restore_in_progress');
                return;
            }
            $restore_key = $master_state['restore_key'];
            $restore_state = $this->get_process_state($restore_key);
            if ($restore_state === false) {
                $master_state['status'] = 'error';
                $master_state['message'] = esc_html__('Restore session expired.', 'optistate');
                $this->set_process_state($master_restore_key, $master_state, 10 * MINUTE_IN_SECONDS);
                $this->deactivate_maintenance_mode();
                $this->delete_process_state('optistate_restore_in_progress');
                return;
            }
            try {
                $start_time = time();
                $max_exec_time = (int)@ini_get('max_execution_time');
                if ($max_exec_time <= 0) {
                    $max_exec_time = 30;
                }
                $time_limit = max(20, $max_exec_time * 0.8);
                $result = ['status' => 'running', 'state' => $restore_state];
                $iterations = 0;
                while (time() - $start_time < $time_limit && $iterations < $this->get_chunks_per_run()) {
                    $result = $this->_perform_restore_core($restore_state);
                    $restore_state = $result['state'];
                    $iterations++;
                    if ($result['status'] === 'done') {
                        break;
                    } elseif ($result['status'] === 'error') {
                        throw new Exception($result['message']);
                    }
                }
                $state = $result['state'];
                if ($result['status'] === 'running') {
                    $this->set_process_state($restore_key, $state, DAY_IN_SECONDS);
                    $master_state['status'] = 'restore_running';
                    $master_state['message'] = sprintf(__('RESTORING DATABASE ....', 'optistate'));
                    $this->set_process_state($master_restore_key, $master_state, 2 * HOUR_IN_SECONDS);
                    wp_schedule_single_event(time() + $this->get_reschedule_delay(), "optistate_run_restore_chunk", [$master_restore_key]);
                } elseif ($result['status'] === 'done') {
                    $this->delete_process_state($restore_key);
                    if (!empty($restore_state['user_id'])) {
                        $this->process_store->delete('optistate_manual_backup_user_' . $restore_state['user_id']);
                    }
                    $master_state['status'] = 'done';
                    $master_state['message'] = $result['message'];
                    $this->set_process_state($master_restore_key, $master_state, 10 * MINUTE_IN_SECONDS);
                }
            }
            catch(Exception $e) {
                $error_message = $e->getMessage();
                $log_filename = $restore_state['log_filename']??'unknown_restore';
                if (!empty($restore_state['uploaded_file_info']['temp_filepath_to_delete'])) {
                    $temp_file = basename($restore_state['uploaded_file_info']['temp_filepath_to_delete']);
                    $this->cleanup_all_temp_sql_files($temp_file);
                }
                if (!empty($restore_state['uploaded_file_info']['temp_transient_to_delete'])) {
                    $this->delete_process_state($restore_state['uploaded_file_info']['temp_transient_to_delete']);
                }
                $this->cleanup_all_temp_sql_files();
                $this->log_failed_restore_operation($log_filename, "Restore failed: " . $error_message);
                if ($this->get_process_state('optistate_instant_rollback_tables')) {
                    $this->trigger_async_rollback($master_restore_key);
                    $master_state['status'] = 'rollback_starting';
                    $master_state['message'] = esc_html__('Restore failed! Rolling back...', 'optistate');
                    $this->deactivate_maintenance_mode();
                    $this->set_process_state($master_restore_key, $master_state, 10 * MINUTE_IN_SECONDS);
                } else {
                    $this->deactivate_maintenance_mode();
                    if (isset($restore_state['temp_tables_created']) && !empty($restore_state['temp_tables_created'])) {
                        try {
                            $db = $this->get_restore_db();
                            $this->cleanup_temp_tables($db, $restore_state['temp_tables_created']);
                        }
                        catch(Exception $db_e) {
                            $this->cleanup_old_tables_after_restore();
                        }
                    } else {
                        $this->cleanup_old_tables_after_restore();
                    }
                    $master_state['status'] = 'error';
                    $master_state['message'] = esc_html__('Restore failed: ', 'optistate') . esc_html($error_message);
                    $this->set_process_state($master_restore_key, $master_state, 10 * MINUTE_IN_SECONDS);
                }
                $this->close_restore_db();
                $this->delete_process_state('optistate_restore_in_progress');
                $this->delete_process_state('optistate_last_restore_filename');
                $this->delete_process_state($restore_key);
                $this->delete_process_state('optistate_current_restore_key');
            }
        }
        finally {
            @set_time_limit($original_time_limit);
        }
    }
    public function ajax_get_restore_status() {
        check_ajax_referer("optistate_backup_nonce", "nonce");
        $master_restore_key = isset($_POST['master_restore_key']) ? sanitize_text_field($_POST['master_restore_key']) : '';
        if (empty($master_restore_key) || strpos($master_restore_key, 'optistate_master_restore_') !== 0) {
            wp_send_json_error(['status' => 'error', 'message' => __('Invalid restore key.', 'optistate') ]);
        }
        $master_state = $this->get_process_state($master_restore_key);
        if ($master_state === false) {
            $global_lock = $this->get_process_state('optistate_restore_in_progress');
            if ($global_lock === false) {
                wp_send_json_success(['status' => 'not_running', 'message' => sprintf(__('Error! Restore process not found or finished.<br>If this issue persists, try deactivating and reactivating the plugin.', 'optistate')) ]);
            } else {
                wp_send_json_success(['status' => 'starting', 'message' => __('INITIALIZING ....', 'optistate') ]);
            }
            return;
        }
        wp_send_json_success(['status' => $master_state['status'], 'message' => $master_state['message'], 'button_selector' => $master_state['button_selector']??'']);
    }
    private function cleanup_old_tables_after_restore() {
        try {
            $db = OPTISTATE_DB_Wrapper::get_instance()->get_connection();
            $stray_tables_result = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES 
                                     WHERE TABLE_SCHEMA = '" . esc_sql(DB_NAME) . "' 
                                     AND (TABLE_NAME LIKE 'optistate_old_%' OR TABLE_NAME LIKE 'optistate_temp_%')");
            if ($stray_tables_result && $stray_tables_result->num_rows > 0) {
                $tables_to_drop = [];
                while ($row = $stray_tables_result->fetch_row()) {
                    if (strpos($row[0], 'optistate_old_') === 0 || strpos($row[0], 'optistate_temp_') === 0) {
                        $tables_to_drop[] = "`" . esc_sql($row[0]) . "`";
                    }
                }
                $stray_tables_result->free();
                if (!empty($tables_to_drop)) {
                    $db->query("SET FOREIGN_KEY_CHECKS = 0");
                    $db->query("DROP TABLE IF EXISTS " . implode(', ', $tables_to_drop));
                    $db->query("SET FOREIGN_KEY_CHECKS = 1");
                }
            }
            OPTISTATE_DB_Wrapper::get_instance()->close();
        }
        catch(Exception $e) {
            if (isset($db) && $db) {
                $db->query("SET FOREIGN_KEY_CHECKS = 1");
                OPTISTATE_DB_Wrapper::get_instance()->close();
            }
        }
    }
}
function optistate_init() {
    static $instance = null;
    if (null === $instance) $instance = new OPTISTATE();
    return $instance;
}
add_action("init", "optistate_init", 1);
register_activation_hook(__FILE__, 'optistate_activate');
function optistate_ensure_filesystem() {
    global $wp_filesystem;
    if (empty($wp_filesystem)) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        if (!WP_Filesystem()) {
            return false;
        }
    }
    return $wp_filesystem;
}
function optistate_activate() {
    if (is_multisite()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('<div style="max-width: 600px; margin: 50px auto; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;">' . '<h1 style="color: #d63638; border-bottom: 3px solid #d63638; padding-bottom: 15px;">' . '<span class="dashicons dashicons-warning" style="font-size: 32px; width: 32px; height: 32px; vertical-align: middle;"></span> ' . esc_html__('Multisite Not Supported', 'optistate') . '</h1>' . '<p style="font-size: 16px; line-height: 1.6;">' . '<strong>' . esc_html__('WP Optimal State cannot be activated on WordPress Multisite installations.', 'optistate') . '</strong>' . '</p>' . '<p style="font-size: 14px; line-height: 1.6; color: #666;">' . esc_html__('This plugin performs advanced database operations designed specifically for single-site WordPress installations. Running it on a multisite network could affect multiple sites and cause data integrity issues.', 'optistate') . '</p>' . '<p style="margin-top: 30px;">' . '<a href="' . esc_url(admin_url('plugins.php')) . '" class="button button-primary button-large">' . esc_html__('← Return to Plugins', 'optistate') . '</a>' . '</p>' . '</div>', esc_html__('Plugin Activation Blocked', 'optistate'), ['back_link' => false]);
    }
    $store = new OPTISTATE_Process_Store();
    $store->create_table();
    $upload_dir = wp_upload_dir();
    $wp_filesystem = optistate_ensure_filesystem();
    if (!$wp_filesystem) {
        return;
    }
    if (!$wp_filesystem->is_writable($upload_dir['basedir'])) {
        return;
    }
    $base_dir = trailingslashit($upload_dir['basedir']);
    $plugin_data_dir = $base_dir . OPTISTATE::SETTINGS_DIR_NAME . '/';
    $backup_dir = $base_dir . OPTISTATE::BACKUP_DIR_NAME . '/';
    $temp_dir = $base_dir . OPTISTATE::TEMP_DIR_NAME . '/';
    if (!$wp_filesystem->is_dir($plugin_data_dir)) {
        if (!wp_mkdir_p($plugin_data_dir)) {
            return;
        }
        $wp_filesystem->chmod($plugin_data_dir, 0755);
    }
    if (!$wp_filesystem->is_dir($backup_dir)) {
        if (wp_mkdir_p($backup_dir)) {
            $wp_filesystem->chmod($backup_dir, 0755);
        }
    }
    if (!$wp_filesystem->is_dir($temp_dir)) {
        if (wp_mkdir_p($temp_dir)) {
            $wp_filesystem->chmod($temp_dir, 0750);
            $rules = ['# WP Optimal State - Secure Temp Restore Directory', '# Prevent directory listing', 'Options -Indexes', '', '# Block all direct web access', '<IfModule mod_authz_core.c>', '    Require all denied', '</IfModule>', '<IfModule !mod_authz_core.c>', '    Order deny,allow', '    Deny from all', '</IfModule>', ];
            $htaccess_content = implode(PHP_EOL, $rules);
            $wp_filesystem->put_contents($temp_dir . '.htaccess', $htaccess_content, FS_CHMOD_FILE);
            $wp_filesystem->put_contents($temp_dir . 'index.php', '<?php // Silence is golden', FS_CHMOD_FILE);
        }
    }
    $secure_file_write = function ($filepath, $content) use ($plugin_data_dir, $upload_dir, $wp_filesystem) {
        $norm_file = wp_normalize_path($filepath);
        $norm_plugin_data_dir = wp_normalize_path($plugin_data_dir);
        $norm_uploads_dir = wp_normalize_path($upload_dir['basedir']);
        if (strpos($norm_file, $norm_plugin_data_dir) !== 0 && strpos($norm_file, $norm_uploads_dir) !== 0) {
            return false;
        }
        if (strpos($norm_file, '..') !== false) {
            return false;
        }
        $result = $wp_filesystem->put_contents($filepath, $content, FS_CHMOD_FILE);
        if ($result === false) {
            return false;
        }
        return true;
    };
    $settings_file = $plugin_data_dir . OPTISTATE::SETTINGS_FILE_NAME;
    if (!$wp_filesystem->exists($settings_file)) {
        $default_settings = ['max_backups' => 1, 'auto_optimize_days' => 0, 'auto_optimize_time' => '02:00', 'email_notifications' => false, 'performance_features' => [], 'allowed_users' => []];
        $json_data = json_encode($default_settings, JSON_PRETTY_PRINT);
        if ($json_data !== false) {
            $secure_file_write($settings_file, $json_data);
        }
    }
    $log_file = $plugin_data_dir . OPTISTATE::LOG_FILE_NAME;
    if (!$wp_filesystem->exists($log_file)) {
        $json_data = json_encode([], JSON_PRETTY_PRINT);
        if ($json_data !== false) {
            $secure_file_write($log_file, $json_data);
        }
    }
    $instance = optistate_init();
    if (method_exists($instance, 'log_optimization')) {
        $current_user = wp_get_current_user();
        $username = ($current_user && $current_user->exists()) ? $current_user->user_login : 'System';
        $instance->log_optimization('manual', '🔌 ' . sprintf(esc_html__('Plugin Activated by %s', 'optistate'), $username), '');
    }
    $default_struct = ['display' => 'N/A', 'value' => 0];
    $default_cache = ['score' => 0, 'fcp' => $default_struct, 'lcp' => $default_struct, 'cls' => $default_struct, 'si' => $default_struct, 'tti' => $default_struct, 'ttfb' => $default_struct, 'tbt' => $default_struct, 'timestamp' => current_time('mysql'), 'strategy' => 'mobile', 'tested_url' => home_url(), 'recommendations' => []];
    $cache_key = 'optistate_pagespeed_' . md5(home_url() . 'mobile');
    set_transient($cache_key, $default_cache, 30 * DAY_IN_SECONDS);
}
register_deactivation_hook(__FILE__, "optistate_deactivate");
function optistate_deactivate() {
    $instance = optistate_init();
    if (method_exists($instance, '_performance_remove_bot_blocking')) {
        $instance->_performance_remove_bot_blocking();
    }
    if (method_exists($instance, 'log_optimization')) {
        $current_user = wp_get_current_user();
        $username = ($current_user && $current_user->exists()) ? $current_user->user_login : 'System';
        $instance->log_optimization('manual', '🔌 ' . sprintf(esc_html__('Plugin Deactivated by %s', 'optistate'), $username), '');
    }
    wp_clear_scheduled_hook("optistate_scheduled_cleanup");
    wp_clear_scheduled_hook('optistate_daily_cleanup');
    wp_clear_scheduled_hook('optistate_background_preload_batch');
    wp_clear_scheduled_hook('optistate_run_rollback_cron');
    if (method_exists($instance, '_performance_remove_caching')) {
        $instance->_performance_remove_caching();
    }
    delete_option('optistate_maintenance_mode_active');
    $store = new OPTISTATE_Process_Store();
    $store->drop_table();
    global $wpdb;
    $stray_tables_result = $wpdb->get_results($wpdb->prepare("SELECT TABLE_NAME FROM information_schema.TABLES 
             WHERE TABLE_SCHEMA = %s 
             AND (TABLE_NAME LIKE 'optistate_old_%%' OR TABLE_NAME LIKE 'optistate_temp_%%')", DB_NAME));
    if ($stray_tables_result && !empty($stray_tables_result)) {
        $tables_to_drop = [];
        foreach ($stray_tables_result as $row) {
            $tables_to_drop[] = '`' . esc_sql($row->TABLE_NAME) . '`';
        }
        if (!empty($tables_to_drop)) {
            $wpdb->query("SET FOREIGN_KEY_CHECKS = 0");
            $wpdb->query("DROP TABLE IF EXISTS " . implode(', ', $tables_to_drop));
            $wpdb->query("SET FOREIGN_KEY_CHECKS = 1");
        }
    }
    $options_to_delete = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} 
             WHERE option_name LIKE %s", '%optistate_%'));
    if (!empty($options_to_delete)) {
        $placeholders = implode(',', array_fill(0, count($options_to_delete), '%s'));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name IN ($placeholders)", ...$options_to_delete));
    }
    $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'optistate_action_timestamps'");
    $wp_filesystem = optistate_ensure_filesystem();
    if ($wp_filesystem) {
        $upload_dir = wp_upload_dir();
        $base_dir = trailingslashit($upload_dir['basedir']);
        $dirs_to_clean = [$base_dir . OPTISTATE::CACHE_DIR_NAME . '/', $base_dir . OPTISTATE::TEMP_DIR_NAME . '/'];
        foreach ($dirs_to_clean as $dir) {
            if ($wp_filesystem->is_dir($dir)) {
                $wp_filesystem->delete($dir, true);
            }
        }
    }
}