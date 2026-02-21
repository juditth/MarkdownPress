<?php
/**
 * Plugin Name: WP Markdown Cache
 * Plugin URI:  https://github.com/juditth/wordpress-to-markdown
 * Description: Generates a Markdown cache of your entire WordPress site for AI/LLM consumption. Serves content via Accept: text/markdown header. Generates llms.txt.
 * Version:     1.0.0
 * Author:      Jitka Klingenbergová
 * Author URI:  https://vyladeny-web.cz/
 * License:     GPLv2 or later
 * Text Domain: wp-markdown-cache
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ───────────────────────────── Constants ───────────────────────────── */

define('WPMC_VERSION', '1.0.0');
define('WPMC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPMC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPMC_PLUGIN_FILE', __FILE__);

// Where the markdown files live (same level as wp-content/)
define('WPMC_CACHE_DIR', ABSPATH . 'wp-markdown/');

/* ───────────────────────────── Includes ───────────────────────────── */

require_once WPMC_PLUGIN_DIR . 'includes/class-html-to-markdown.php';
require_once WPMC_PLUGIN_DIR . 'includes/class-converter.php';
require_once WPMC_PLUGIN_DIR . 'includes/class-generator.php';
require_once WPMC_PLUGIN_DIR . 'includes/class-server.php';
require_once WPMC_PLUGIN_DIR . 'includes/class-llms-txt.php';
require_once WPMC_PLUGIN_DIR . 'admin/class-admin.php';

/* ───────────────────────────── Bootstrap ───────────────────────────── */

/**
 * Get plugin options with defaults.
 */
function wpmc_get_options()
{
    $defaults = array(
        'enabled' => true,
        'source' => 'all',        // 'all' | 'sitemap'
        'post_types' => array('post', 'page'),
        'include_archives' => true,
        'include_authors' => false,
        'include_taxonomies' => true,
        'cron_time' => '02:00',
        'batch_size' => 20,
        'batch_delay' => 2,            // minutes between batches
        'generate_all_md' => true,
        'generate_llms_txt' => true,
        'exclude_urls' => '',
        'frontmatter' => true,
        'regenerate_on_save' => true,
        'content_method' => 'filter',     // 'filter' | 'http'
    );
    $options = get_option('wpmc_options', array());
    return wp_parse_args($options, $defaults);
}

/**
 * Plugin activation.
 */
function wpmc_activate()
{
    // Create cache directory.
    if (!file_exists(WPMC_CACHE_DIR)) {
        wp_mkdir_p(WPMC_CACHE_DIR);
    }

    // Add .htaccess to protect direct directory listing.
    $htaccess = WPMC_CACHE_DIR . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Options -Indexes\n");
    }

    // Schedule initial cron.
    wpmc_schedule_cron();

    // Set default options.
    if (false === get_option('wpmc_options')) {
        update_option('wpmc_options', array(), false);
    }
}
register_activation_hook(__FILE__, 'wpmc_activate');

/**
 * Plugin deactivation.
 */
function wpmc_deactivate()
{
    wp_clear_scheduled_hook('wpmc_cron_generate');
    wp_clear_scheduled_hook('wpmc_cron_batch');
}
register_deactivation_hook(__FILE__, 'wpmc_deactivate');

/* ───────────────────────────── Cron ───────────────────────────── */

add_filter('cron_schedules', function ($schedules) {
    $options = wpmc_get_options();
    $schedules['wpmc_batch_interval'] = array(
        'interval' => max(1, intval($options['batch_delay'])) * 60,
        'display' => __('WP Markdown Cache batch interval', 'wp-markdown-cache'),
    );
    return $schedules;
});

/**
 * Schedule daily cron event based on user-configured time.
 */
function wpmc_schedule_cron()
{
    wp_clear_scheduled_hook('wpmc_cron_generate');

    $options = wpmc_get_options();
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

    wp_schedule_event($next->getTimestamp(), 'daily', 'wpmc_cron_generate');
}

/**
 * Cron: start full regeneration — just queues all URLs.
 */
add_action('wpmc_cron_generate', function () {
    $generator = new WPMC_Generator();
    $generator->queue_all();
    // Schedule batch processing if not already running.
    if (!wp_next_scheduled('wpmc_cron_batch')) {
        wp_schedule_event(time(), 'wpmc_batch_interval', 'wpmc_cron_batch');
    }
});

/**
 * Cron: process next batch from queue.
 */
add_action('wpmc_cron_batch', function () {
    $generator = new WPMC_Generator();
    $remaining = $generator->process_batch();
    // If everything is done, clear the batch cron and generate summary files.
    if ($remaining === 0) {
        wp_clear_scheduled_hook('wpmc_cron_batch');
        $generator->generate_summary_files();
        // Generate llms.txt.
        $options = wpmc_get_options();
        if ($options['generate_llms_txt']) {
            WPMC_Llms_Txt::generate();
        }
    }
});

/* ───────────────────────────── Event-driven updates ───────────────────────────── */

add_action('save_post', function ($post_id, $post) {
    $options = wpmc_get_options();
    if (!$options['enabled'] || !$options['regenerate_on_save']) {
        return;
    }
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    if ($post->post_status !== 'publish') {
        // If unpublished, delete the cached markdown.
        $converter = new WPMC_Converter();
        $converter->delete_cache_for_post($post_id);
        return;
    }
    // Regenerate this single post.
    $converter = new WPMC_Converter();
    $converter->convert_post($post_id);
}, 20, 2);

add_action('delete_post', function ($post_id) {
    $converter = new WPMC_Converter();
    $converter->delete_cache_for_post($post_id);
});

/* ───────────────────────────── Markdown serving ───────────────────────────── */

// This must run VERY early.
add_action('plugins_loaded', array('WPMC_Server', 'init'), 1);

/* ───────────────────────────── Admin ───────────────────────────── */

if (is_admin()) {
    new WPMC_Admin();
}
