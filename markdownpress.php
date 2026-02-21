<?php
/**
 * Plugin Name: MarkdownPress
 * Plugin URI:  https://github.com/juditth/wordpress-to-markdown
 * Description: Creates a markdown mirror of your WordPress site. Serves content via Accept: text/markdown header, generates llms.txt for AI crawlers.
 * Version:     1.2.5
 * Author:      Jitka Klingenbergová
 * Author URI:  https://vyladeny-web.cz/
 * License:     GPLv2 or later
 * Text Domain: markdownpress
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ───────────────────────────── Constants ───────────────────────────── */

define('MDP_VERSION', '1.2.5');
define('MDP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MDP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MDP_PLUGIN_FILE', __FILE__);

// Where the markdown files live — inside wp-content/ for guaranteed write permissions.
// Use filter 'mdp_cache_dir' to override in wp-config.php or a mu-plugin if needed.
define('MDP_CACHE_DIR', apply_filters('mdp_cache_dir', WP_CONTENT_DIR . '/markdownpress/'));

/* ───────────────────────────── Includes ───────────────────────────── */

require_once MDP_PLUGIN_DIR . 'includes/class-html-to-markdown.php';
require_once MDP_PLUGIN_DIR . 'includes/class-converter.php';
require_once MDP_PLUGIN_DIR . 'includes/class-generator.php';
require_once MDP_PLUGIN_DIR . 'includes/class-server.php';
require_once MDP_PLUGIN_DIR . 'includes/class-llms-txt.php';
require_once MDP_PLUGIN_DIR . 'includes/class-htaccess.php';
require_once MDP_PLUGIN_DIR . 'admin/class-admin.php';

/* ───────────────────────────── Bootstrap ───────────────────────────── */

/**
 * Get plugin options with defaults.
 */
function mdp_get_options()
{
    $defaults = array(
        'enabled' => true,
        'source' => 'sitemap',        // 'all' | 'sitemap'
        'post_types' => array('post', 'page'),
        'include_archives' => true,
        'include_authors' => false,
        'include_taxonomies' => true,
        'cron_time' => '02:00',
        'batch_size' => 10,
        'batch_delay' => 2,            // minutes between batches
        'generate_all_md' => true,
        'generate_llms_txt' => true,
        'exclude_urls' => '',
        'frontmatter' => true,
        'regenerate_on_save' => true,
        'content_method' => 'both',     // 'filter' | 'http' | 'both'
    );
    $options = get_option('mdp_options', array());
    return wp_parse_args($options, $defaults);
}

/**
 * Plugin activation.
 */
function mdp_activate()
{
    // Create markdown files directory.
    if (!file_exists(MDP_CACHE_DIR)) {
        wp_mkdir_p(MDP_CACHE_DIR);
    }

    // Add .htaccess to protect direct directory listing.
    $htaccess = MDP_CACHE_DIR . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Options -Indexes\n");
    }

    // Insert .htaccess rewrite rules for fast serving (Apache/LiteSpeed).
    MDP_Htaccess::add_rules();

    // Check for existing settings.
    $old_options = get_option('wpmc_options');
    $current_options = get_option('mdp_options');

    // MIGRATION: If old settings exist and no new settings are found, migrate them.
    if ($old_options && (false === $current_options || empty($current_options))) {
        $new_options = array();
        foreach ($old_options as $key => $val) {
            $new_key = $key;
            if ($key === 'schedule_time')
                $new_key = 'cron_time';
            if ($key === 'include_tax')
                $new_key = 'include_taxonomies';
            $new_options[$new_key] = $val;
        }
        update_option('mdp_options', $new_options);
        $current_options = $new_options;
    }

    // INITIALIZE: If still no settings, save defaults.
    if (false === $current_options) {
        update_option('mdp_options', mdp_get_options());
    }
}
register_activation_hook(__FILE__, 'mdp_activate');

/**
 * Plugin deactivation.
 */
function mdp_deactivate()
{
    wp_clear_scheduled_hook('mdp_cron_generate');
    wp_clear_scheduled_hook('mdp_cron_batch');

    // Remove .htaccess rules.
    MDP_Htaccess::remove_rules();
}
register_deactivation_hook(__FILE__, 'mdp_deactivate');

/* ───────────────────────────── Cron ───────────────────────────── */

add_filter('cron_schedules', function ($schedules) {
    $options = mdp_get_options();
    $schedules['mdp_batch_interval'] = array(
        'interval' => max(1, intval($options['batch_delay'])) * 60,
        'display' => __('MarkdownPress batch interval', 'markdownpress'),
    );
    return $schedules;
});

/**
 * Schedule daily cron event based on user-configured time.
 */
function mdp_schedule_cron()
{
    wp_clear_scheduled_hook('mdp_cron_generate');

    $options = mdp_get_options();
    $time_parts = explode(':', $options['cron_time']);
    $hour = isset($time_parts[0]) ? intval($time_parts[0]) : 2;
    $minute = isset($time_parts[1]) ? intval($time_parts[1]) : 0;

    // Calculate next occurrence in site's timezone.
    $tz = wp_timezone();
    $now = new DateTime('now', $tz);
    $next = new DateTime('today', $tz);
    $next->setTime($hour, $minute);

    if ($next <= $now) {
        $next->modify('+1 day');
    }

    wp_schedule_event($next->getTimestamp(), 'daily', 'mdp_cron_generate');
}

/**
 * Cron: start full regeneration — just queues all URLs.
 */
add_action('mdp_cron_generate', function () {
    $generator = new MDP_Generator();
    $generator->queue_all();
    // Schedule batch processing if not already running.
    if (!wp_next_scheduled('mdp_cron_batch')) {
        wp_schedule_event(time(), 'mdp_batch_interval', 'mdp_cron_batch');
    }
});

/**
 * Cron: process next batch from queue.
 */
add_action('mdp_cron_batch', function () {
    $generator = new MDP_Generator();
    $remaining = $generator->process_batch();
    // If everything is done, clear the batch cron and generate summary files.
    if ($remaining === 0) {
        wp_clear_scheduled_hook('mdp_cron_batch');
        $generator->generate_summary_files();
        // Generate llms.txt.
        $options = mdp_get_options();
        if ($options['generate_llms_txt']) {
            MDP_Llms_Txt::generate();
        }
    }
});

/* ───────────────────────────── Event-driven updates ───────────────────────────── */

add_action('save_post', function ($post_id, $post) {
    $options = mdp_get_options();
    if (!$options['enabled'] || !$options['regenerate_on_save']) {
        return;
    }
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    if ($post->post_status !== 'publish') {
        // If unpublished, delete the cached markdown.
        $converter = new MDP_Converter();
        $converter->delete_cache_for_post($post_id);
        return;
    }
    // Regenerate this single post.
    $converter = new MDP_Converter();
    $converter->convert_post($post_id);
}, 20, 2);

add_action('delete_post', function ($post_id) {
    $converter = new MDP_Converter();
    $converter->delete_cache_for_post($post_id);
});

/* ───────────────────────────── Markdown serving ───────────────────────────── */

// This must run early, but after most core functions are available.
add_action('plugins_loaded', array('MDP_Server', 'init'), 10);

/* ───────────────────────────── Admin ───────────────────────────── */

if (is_admin()) {
    new MDP_Admin();

    // Initialize Plugin Update Checker (only if library exists).
    $puc_path = plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';
    if (file_exists($puc_path)) {
        require_once $puc_path;
        \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://vyladeny-web.cz/plugins/markdownpress/info.json',
            __FILE__,
            'markdownpress'
        );
    }
}
