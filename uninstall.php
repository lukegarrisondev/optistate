<?php
/**
 * Optimal State - Uninstall Script
 *
 * This file is executed when the plugin is deleted from the WordPress admin.
 * It removes all plugin data including files, folders, options, and transients.
 */

// Exit if accessed directly or if not uninstalling
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Remove browser caching rules from .htaccess
 */
function optistate_remove_htaccess_rules() {
    global $wp_filesystem;
    if (empty($wp_filesystem)) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }
    
    if (!$wp_filesystem) {
        return;
    }
    
    $htaccess_path = get_home_path() . '.htaccess';
    
    if (!$wp_filesystem->exists($htaccess_path)) {
        return;
    }
    
    if (!$wp_filesystem->is_writable($htaccess_path)) {
        return;
    }
    
    $current_content = $wp_filesystem->get_contents($htaccess_path);
    if ($current_content === false) {
        return;
    }
    
    $begin_comment = '# BEGIN WP Optimal State Caching';
    $end_comment = '# END WP Optimal State Caching';
    $separator = '# ============================================================';
    
    // Remove the caching rules block
    $pattern = '/\s*' . preg_quote($separator, '/') . '\s*\n' .
               '\s*' . preg_quote($begin_comment, '/') . '.*?' . 
               preg_quote($end_comment, '/') . '\s*\n' .
               '\s*' . preg_quote($separator, '/') . '\s*\n?/s';
    
    $new_content = preg_replace($pattern, '', $current_content);
    $new_content = preg_replace("/\n{3,}/", "\n\n", trim($new_content));
    
    if (trim($new_content) !== trim($current_content)) {
        $wp_filesystem->put_contents($htaccess_path, $new_content, FS_CHMOD_FILE);
    }
}

/**
 * Delete all plugin-specific directories and files
 */
function optistate_delete_plugin_directories() {
    $upload_dir = wp_upload_dir();
    
    // Directories created by the plugin
    $directories_to_delete = array(
        trailingslashit($upload_dir['basedir']) . 'optistate-settings/',     // Settings and log files
        trailingslashit($upload_dir['basedir']) . 'optistate/db-backups/',   // Database backups
        trailingslashit($upload_dir['basedir']) . 'optistate/db-restore-temp/', // Temporary restore files
        trailingslashit($upload_dir['basedir']) . 'optistate/page-cache/', // Server-side cache directory
        trailingslashit($upload_dir['basedir']) . 'optistate/'              // Parent folder
    );
    
    // Initialize WP_Filesystem
    global $wp_filesystem;
    if (empty($wp_filesystem)) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }
    
    foreach ($directories_to_delete as $directory) {
        if ($wp_filesystem && $wp_filesystem->is_dir($directory)) {
            $wp_filesystem->delete($directory, true); // true = recursive delete
        }
    }
}

/**
 * Delete all plugin-specific WordPress options
 */
function optistate_delete_plugin_options() {
    // Plugin-specific options
    $options_to_delete = array(
        'optistate_activation_time',
        'optistate_maintenance_mode_active', // Maintenance mode flag
        'optistate_email_log'                // Email log
    );
    
    foreach ($options_to_delete as $option) {
        delete_option($option);
    }
}

/**
 * Delete all plugin-specific transients from the options table.
 *
 * This single function reliably and efficiently removes all transients
 * created by the plugin by matching their database prefixes.
 */
function optistate_delete_plugin_transients_and_options() {
    global $wpdb;
    
    // This single query efficiently removes all plugin data from the wp_options table, including:
    // 1. All standard transients (e.g., _transient_optistate_stats_cache, _transient_optistate_health_score)
    // 2. All transient timeouts (e.g., _transient_timeout_optistate_stats_cache)
    // 3. All cache preload transients (e.g., _transient_optistate_preload_running)
    // 4. All backup/restore process transients (e.g., _transient_optistate_backup_..., _transient_optistate_restore_...)
    // 5. All safety rollback transients (e.g., _transient_optistate_safety_backup, _transient_optistate_last_restore_error)
    // 6. All rate-limiting transients (e.g., _transient_optistate_rate_limit_...)
    // 7. All temporary file upload transients (e.g., _transient_optistate_temp_restore_...)
    
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE %s 
                OR option_name LIKE %s",
            '_transient_optistate_%',       // Matches all plugin transients
            '_transient_timeout_optistate_%'  // Matches all plugin transient timeouts
        )
    );
}

/**
 * Clear scheduled cron events
 */
function optistate_clear_scheduled_events() {
    // Clear main optimization cron
    $timestamp = wp_next_scheduled('optistate_scheduled_cleanup');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'optistate_scheduled_cleanup');
    }
    wp_clear_scheduled_hook('optistate_scheduled_cleanup');
    
    // Clear daily cleanup cron
    $timestamp = wp_next_scheduled('optistate_daily_cleanup');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'optistate_daily_cleanup');
    }
    wp_clear_scheduled_hook('optistate_daily_cleanup');
}

/**
 * Clean up any user meta related to the plugin
 */
function optistate_delete_user_meta() {
    global $wpdb;
    
    // Delete any plugin-specific user meta (if any were created)
    $wpdb->query(
        "DELETE FROM {$wpdb->usermeta} 
         WHERE meta_key LIKE 'optistate_%'"
    );
}

/**
 * Delete all plugin-specific custom database tables
 */
function optistate_delete_custom_tables() {
    global $wpdb;
    
    $tables_to_drop = [];

    // 1. Add the main processes table
    // We add it without checking, DROP TABLE IF EXISTS is safe.
    $tables_to_drop[] = "`" . esc_sql($wpdb->prefix . 'optistate_processes') . "`";

    // 2. Add the backup metadata table
    $tables_to_drop[] = "`" . esc_sql($wpdb->prefix . 'optistate_backup_metadata') . "`";

    // 3. Find 'old' safety tables and 'temp' restore tables
    $stray_tables_query = $wpdb->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES 
         WHERE TABLE_SCHEMA = %s 
         AND (TABLE_NAME LIKE %s OR TABLE_NAME LIKE %s)",
        DB_NAME,
        $wpdb->esc_like($wpdb->prefix . 'optistate_old_') . '%',
        $wpdb->esc_like($wpdb->prefix . 'optistate_temp_') . '%'
    );
    
    $stray_tables = $wpdb->get_col($stray_tables_query);
    
    if (!empty($stray_tables)) {
        foreach ($stray_tables as $table) {
            // Double-check prefix just in case
            if (strpos($table, $wpdb->prefix . 'optistate_old_') === 0 || strpos($table, $wpdb->prefix . 'optistate_temp_') === 0) {
                 $tables_to_drop[] = "`" . esc_sql($table) . "`";
            }
        }
    }

    // 3. Drop all found tables in one go
    if (count($tables_to_drop) > 0) {
        // Ensure no duplicates
        $tables_to_drop = array_unique($tables_to_drop);
        
        $wpdb->query("SET FOREIGN_KEY_CHECKS = 0");
        $wpdb->query("DROP TABLE IF EXISTS " . implode(', ', $tables_to_drop));
        $wpdb->query("SET FOREIGN_KEY_CHECKS = 1");
    }
}


/**
 * Main uninstall execution
 */
function optistate_uninstall() {
    // Remove browser caching rules from .htaccess FIRST
    // (before deleting directories, in case we need filesystem access)
    optistate_remove_htaccess_rules();
    
    // Delete plugin directories and files (including page cache, backups, and settings)
    optistate_delete_plugin_directories();
    
    // Delete plugin options
    optistate_delete_plugin_options();
    
    // Delete all transients and pattern-matched options
    optistate_delete_plugin_transients_and_options();

    // Delete custom database tables
    optistate_delete_custom_tables();
    
    // Clear scheduled cron jobs
    optistate_clear_scheduled_events();
    
    // Delete user meta
    optistate_delete_user_meta();
    
    // Clear any cached data
    wp_cache_flush();
}

// Execute the uninstall
optistate_uninstall();