<?php
/**
 * Admin settings page for MarkdownPress.
 * Adds a menu page under Settings, handles options, manual triggers, and AJAX status check.
 *
 * @package MarkdownPress
 */

if (!defined('ABSPATH')) {
    exit;
}

class MDP_Admin
{

    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        // AJAX handlers.
        add_action('wp_ajax_mdp_generate_now', array($this, 'ajax_generate_now'));
        add_action('wp_ajax_mdp_stop_generation', array($this, 'ajax_stop_generation'));
        add_action('wp_ajax_mdp_clear_cache', array($this, 'ajax_clear_cache'));
        add_action('wp_ajax_mdp_get_status', array($this, 'ajax_get_status'));

        // Handle ZIP download.
        add_action('admin_init', array($this, 'handle_zip_download'));

        // Add settings link to plugins page.
        add_filter('plugin_action_links_' . plugin_basename(MDP_PLUGIN_FILE), array($this, 'add_settings_link'));
    }

    /**
     * Add settings page.
     */
    public function add_menu()
    {
        add_options_page(
            'MarkdownPress',
            'MarkdownPress',
            'manage_options',
            'markdownpress',
            array($this, 'render_page')
        );
    }

    /**
     * Add "Settings" link to the plugins page.
     */
    public function add_settings_link($links)
    {
        $settings_link = '<a href="options-general.php?page=markdownpress">' . __('Settings', 'markdownpress') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Enqueue admin CSS and JS.
     */
    public function enqueue_assets($hook)
    {
        if ($hook !== 'settings_page_markdownpress') {
            return;
        }
        wp_enqueue_style('mdp-admin', MDP_PLUGIN_URL . 'admin/assets/admin.css', array(), MDP_VERSION);
        wp_enqueue_script('mdp-admin', MDP_PLUGIN_URL . 'admin/assets/admin.js', array('jquery'), MDP_VERSION, true);
        wp_localize_script('mdp-admin', 'mdpAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mdp_admin_nonce'),
        ));
    }

    /**
     * Register settings.
     */
    public function register_settings()
    {
        register_setting('mdp_options_group', 'mdp_options', array(
            'sanitize_callback' => array($this, 'sanitize_options'),
        ));
    }

    /**
     * Sanitize options on save.
     */
    public function sanitize_options($input)
    {
        $sanitized = array();

        $sanitized['enabled'] = !empty($input['enabled']);
        $sanitized['source'] = in_array($input['source'] ?? '', array('all', 'sitemap')) ? $input['source'] : 'all';
        $sanitized['post_types'] = isset($input['post_types']) && is_array($input['post_types']) ? array_map('sanitize_text_field', $input['post_types']) : array('post', 'page');
        $sanitized['include_archives'] = !empty($input['include_archives']);
        $sanitized['include_authors'] = !empty($input['include_authors']);
        $sanitized['include_taxonomies'] = !empty($input['include_taxonomies']);
        $sanitized['cron_time'] = preg_match('/^\d{2}:\d{2}$/', $input['cron_time'] ?? '') ? $input['cron_time'] : '02:00';
        $sanitized['batch_size'] = max(1, min(100, intval($input['batch_size'] ?? 20)));
        $sanitized['batch_delay'] = max(1, min(30, intval($input['batch_delay'] ?? 2)));
        $sanitized['generate_all_md'] = !empty($input['generate_all_md']);
        $sanitized['generate_llms_txt'] = !empty($input['generate_llms_txt']);
        $sanitized['exclude_urls'] = sanitize_textarea_field($input['exclude_urls'] ?? '');
        $sanitized['frontmatter'] = !empty($input['frontmatter']);
        $sanitized['regenerate_on_save'] = !empty($input['regenerate_on_save']);
        $sanitized['content_method'] = in_array($input['content_method'] ?? '', array('filter', 'http', 'both')) ? $input['content_method'] : 'filter';

        // Reschedule cron if time changed.
        mdp_schedule_cron();

        return $sanitized;
    }

    /**
     * Render the settings page.
     */
    public function render_page()
    {
        $options = mdp_get_options();
        $status = MDP_Generator::get_status();
        $all_post_types = get_post_types(array('public' => true), 'objects');
        $cache_size = $this->get_cache_size();
        $cache_files = $this->count_cache_files();
        $next_cron = wp_next_scheduled('mdp_cron_generate');
        $is_processing = MDP_Generator::has_queue();
        ?>
        <div class="wrap mdp-wrap">
            <h1>
                <span class="dashicons dashicons-media-text" style="font-size: 30px; margin-right: 8px;"></span>
                MarkdownPress
            </h1>

            <!-- Status cards -->
            <div class="mdp-status-cards">
                <div class="mdp-card">
                    <div class="mdp-card-icon dashicons dashicons-media-document"></div>
                    <div class="mdp-card-content">
                        <div class="mdp-card-number">
                            <?php echo number_format($cache_files); ?>
                        </div>
                        <div class="mdp-card-label">Processed files</div>
                    </div>
                </div>
                <div class="mdp-card">
                    <div class="mdp-card-icon dashicons dashicons-database"></div>
                    <div class="mdp-card-content">
                        <div class="mdp-card-number">
                            <?php echo size_format($cache_size); ?>
                        </div>
                        <div class="mdp-card-label">Markdown files folder size</div>
                    </div>
                </div>
                <div class="mdp-card">
                    <div class="mdp-card-icon dashicons dashicons-clock"></div>
                    <div class="mdp-card-content">
                        <div class="mdp-card-number">
                            <?php echo $next_cron ? wp_date('H:i', $next_cron) : '—'; ?>
                        </div>
                        <div class="mdp-card-label">Next cron run</div>
                    </div>
                </div>
                <div class="mdp-card <?php echo $is_processing ? 'mdp-card-active' : ''; ?>">
                    <div
                        class="mdp-card-icon dashicons dashicons-<?php echo $is_processing ? 'update mdp-spin' : 'yes-alt'; ?>">
                    </div>
                    <div class="mdp-card-content">
                        <div class="mdp-card-number" id="mdp-status-text">
                            <?php echo $is_processing ? 'Processing...' : 'Idle'; ?>
                        </div>
                        <div class="mdp-card-label" id="mdp-status-detail">
                            <?php
                            if ($status['total'] > 0) {
                                echo $status['processed'] . ' / ' . $status['total'];
                                if ($status['errors'] > 0) {
                                    echo ' (' . $status['errors'] . ' errors)';
                                }
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="mdp-actions">
                <button id="mdp-generate-now" class="button button-primary button-hero" <?php echo $is_processing ? 'disabled' : ''; ?>>
                    <span class="dashicons dashicons-controls-play"></span>
                    Generate Now
                </button>
                <button id="mdp-stop-generation" class="button button-secondary button-hero button-stop" style="<?php echo $is_processing ? '' : 'display:none;'; ?>">
                    <span class="dashicons dashicons-controls-pause"></span>
                    Stop
                </button>
                <button id="mdp-clear-cache" class="button button-secondary">
                    <span class="dashicons dashicons-trash"></span>
                    Clear Cache
                </button>

                <?php if (file_exists(MDP_CACHE_DIR . 'llms.txt')): ?>
                    <a href="<?php echo home_url('/llms.txt'); ?>" target="_blank" class="button">
                        <span class="dashicons dashicons-external"></span>
                        View llms.txt
                    </a>
                <?php endif; ?>

                <?php if ($cache_files > 0): ?>
                    <a href="<?php echo esc_url(add_query_arg('mdp_download_zip', '1', admin_url('options-general.php?page=markdownpress'))); ?>"
                        class="button">
                        <span class="dashicons dashicons-archive"></span>
                        Download ZIP
                    </a>
                <?php endif; ?>
            </div>

            <!-- Progress bar -->
            <div id="mdp-progress" class="mdp-progress" style="<?php echo $is_processing ? '' : 'display:none;'; ?>">
                <div class="mdp-progress-bar">
                    <div class="mdp-progress-fill"
                        style="width: <?php echo $status['total'] > 0 ? round($status['processed'] / $status['total'] * 100) : 0; ?>%">
                    </div>
                </div>
                <div class="mdp-progress-text" id="mdp-progress-text">
                    <?php if ($status['total'] > 0): ?>
                        <?php echo $status['processed']; ?> /
                        <?php echo $status['total']; ?> pages
                    <?php endif; ?>
                </div>
            </div>

            <!-- Settings form -->
            <form method="post" action="options.php" class="mdp-settings-form">
                <?php settings_fields('mdp_options_group'); ?>

                <div class="mdp-settings-grid">

                    <!-- General settings -->
                    <div class="mdp-settings-section">
                        <h2>General</h2>
                        <table class="form-table">
                            <tr>
                                <th>Enable</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="mdp_options[enabled]" value="1" <?php checked($options['enabled']); ?> />
                                        Enable MarkdownPress and serving
                                    </label>
                                    <p class="description">
                                        Allows serving Markdown automatically when a request is made with
                                        <code>Accept: text/markdown</code> in header.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th>Content source</th>
                                <td>
                                    <label>
                                        <input type="radio" name="mdp_options[source]" value="sitemap" <?php checked($options['source'], 'sitemap'); ?> />
                                        XML Sitemap (Best for builders)
                                    </label><br>
                                    <label>
                                        <input type="radio" name="mdp_options[source]" value="all" <?php checked($options['source'], 'all'); ?> />
                                        All published content (Core only - posts, pages, etc.)
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th>Post types</th>
                                <td>
                                    <?php foreach ($all_post_types as $pt): ?>
                                        <label style="display: inline-block; margin-right: 16px; margin-bottom: 6px;">
                                            <input type="checkbox" name="mdp_options[post_types][]"
                                                value="<?php echo esc_attr($pt->name); ?>" <?php checked(in_array($pt->name, $options['post_types'])); ?> />
                                            <?php echo esc_html($pt->label); ?> (
                                            <?php echo esc_html($pt->name); ?>)
                                        </label>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Include</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="mdp_options[include_taxonomies]" value="1" <?php checked($options['include_taxonomies']); ?> />
                                        Taxonomy archives (categories, tags, custom taxonomies)
                                    </label><br>
                                    <label>
                                        <input type="checkbox" name="mdp_options[include_authors]" value="1" <?php checked($options['include_authors']); ?> />
                                        Author archives
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th>Rendering method</th>
                                <td>
                                    <select name="mdp_options[content_method]">
                                        <option value="both" <?php selected($options['content_method'], 'both'); ?>>Combined
                                            (Smart Fallback)</option>
                                        <option value="filter" <?php selected($options['content_method'], 'filter'); ?>>
                                            WordPress Filters (Fast)</option>
                                        <option value="http" <?php selected($options['content_method'], 'http'); ?>>HTTP Fetch
                                            (100% Accurate)</option>
                                    </select>
                                    <p class="description">
                                        <strong>Combined (Smart Fallback)</strong> — Recommended. Uses WordPress filters first
                                        for speed, then automatically falls back to HTTP Fetch for page builders like Bricks or
                                        Elementor if the content appears empty.<br>
                                        <strong>WordPress Filters (Fast)</strong> — Standard method using
                                        <code>apply_filters('the_content')</code>. Works well for Gutenberg and classic
                                        sites.<br>
                                        <strong>HTTP Fetch (100% Accurate)</strong> — Fetches the page exactly like a browser.
                                        Slowest method, but bypasses all builder limitations to get the true final content.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Schedule settings -->
                    <div class="mdp-settings-section">
                        <h2>Schedule & Performance</h2>
                        <table class="form-table">
                            <tr>
                                <th>Cron time</th>
                                <td>
                                    <input type="time" name="mdp_options[cron_time]"
                                        value="<?php echo esc_attr($options['cron_time']); ?>" />
                                    <p class="description">Daily full regeneration time (server timezone:
                                        <?php echo wp_timezone_string(); ?>)
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th>Batch size</th>
                                <td>
                                    <input type="number" name="mdp_options[batch_size]"
                                        value="<?php echo esc_attr($options['batch_size']); ?>" min="1" max="100" step="1"
                                        style="width: 80px;" />
                                    <p class="description">Number of pages to process per batch (1–100). Lower = gentler on
                                        server.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Batch delay</th>
                                <td>
                                    <input type="number" name="mdp_options[batch_delay]"
                                        value="<?php echo esc_attr($options['batch_delay']); ?>" min="1" max="30" step="1"
                                        style="width: 80px;" /> minutes
                                    <p class="description">Pause between batches (1–30 min). Also helps prevent overloading the
                                        server.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Auto-regenerate</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="mdp_options[regenerate_on_save]" value="1" <?php checked($options['regenerate_on_save']); ?> />
                                        Regenerate markdown when a post is published/updated
                                    </label>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Output settings -->
                    <div class="mdp-settings-section">
                        <h2>Output</h2>
                        <table class="form-table">
                            <tr>
                                <th>YAML frontmatter</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="mdp_options[frontmatter]" value="1" <?php checked($options['frontmatter']); ?> />
                                        Include title, URL, dates, categories, tags in each markdown file
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th>Generate _all.md</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="mdp_options[generate_all_md]" value="1" <?php checked($options['generate_all_md']); ?> />
                                        Concatenate all pages into a single file (for LLM context windows)
                                    </label>
                                    <p class="description">Warning: can be very large on big sites.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Generate llms.txt</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="mdp_options[generate_llms_txt]" value="1" <?php checked($options['generate_llms_txt']); ?> />
                                        Generate <code>/llms.txt</code> and <code>/llms-full.txt</code>
                                    </label>
                                    <p class="description">Like robots.txt but for LLMs. Describes your site structure for AI
                                        crawlers.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Exclude URLs</th>
                                <td>
                                    <textarea name="mdp_options[exclude_urls]" rows="5"
                                        class="large-text code"><?php echo esc_textarea($options['exclude_urls']); ?></textarea>
                                    <p class="description">One URL pattern per line. Pages matching any pattern will be
                                        excluded.<br>
                                        Built-in exclusions: <code>/wp-admin</code>, <code>/wp-login</code>,
                                        <code>/wp-json</code>, <code>/feed</code>, <code>/cart</code>, <code>/checkout</code>,
                                        <code>/my-account</code>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <p class="mdp-cache-dir-info">
                    <strong>Markdown files directory:</strong> <code><?php echo esc_html(MDP_CACHE_DIR); ?></code>
                </p>

                <?php
                $has_htaccess = MDP_Htaccess::has_rules();
                $is_apache = isset($_SERVER['SERVER_SOFTWARE']) && (
                    stripos($_SERVER['SERVER_SOFTWARE'], 'apache') !== false ||
                    stripos($_SERVER['SERVER_SOFTWARE'], 'litespeed') !== false
                );
                ?>
                <div class="mdp-cache-dir-info" style="margin-top: 8px;">
                    <strong>Fast serving:</strong>
                    <?php if ($has_htaccess): ?>
                        <span style="color: #00a32a;">✅ Active (.htaccess rules installed)</span>
                        <p class="description">Markdown files are served directly by Apache — no PHP/WordPress overhead (~2ms
                            response).</p>
                    <?php elseif ($is_apache): ?>
                        <span style="color: #dba617;">⚠️ Not active</span>
                        <p class="description">.htaccess is not writable. <a href="#"
                                onclick="MDP_Htaccess.add_rules(); return false;">Try to install rules</a> or add them manually.
                            Files are served via PHP fallback (~50-200ms).</p>
                    <?php else: ?>
                        <span style="color: #72777c;">ℹ️ Nginx detected — PHP fallback active</span>
                        <details style="margin-top: 8px;">
                            <summary>Show Nginx config snippet</summary>
                            <pre
                                style="background: #1d2327; color: #c3c4c7; padding: 12px; border-radius: 4px; overflow-x: auto; margin-top: 8px;"><?php echo esc_html(MDP_Htaccess::get_nginx_config()); ?></pre>
                        </details>
                    <?php endif; ?>
                </div>

                <?php submit_button('Save Settings'); ?>
            </form>
        </div>
        <?php
    }

    /* ───────────────────────────── AJAX Handlers ───────────────────────────── */

    /**
     * AJAX: Start generation now.
     */
    public function ajax_generate_now()
    {
        check_ajax_referer('mdp_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $generator = new MDP_Generator();
        $total = $generator->queue_all();

        // Process first batch immediately.
        $remaining = $generator->process_batch();

        // Schedule subsequent batches.
        if ($remaining > 0 && !wp_next_scheduled('mdp_cron_batch')) {
            wp_schedule_event(time() + 10, 'mdp_batch_interval', 'mdp_cron_batch');
        }

        // If everything was done in one batch, generate summary files.
        if ($remaining === 0) {
            $generator->generate_summary_files();
            $options = mdp_get_options();
            if ($options['generate_llms_txt']) {
                MDP_Llms_Txt::generate();
            }
        }

        wp_send_json_success(array(
            'total' => $total,
            'remaining' => $remaining,
            'status' => MDP_Generator::get_status(),
        ));
    }

    /**
     * AJAX: Stop generation.
     */
    public function ajax_stop_generation()
    {
        check_ajax_referer('mdp_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        // 1. Clear CRON.
        wp_clear_scheduled_hook('mdp_cron_batch');

        // 2. Delete queue file.
        if (file_exists(MDP_CACHE_DIR . '_queue.json')) {
            @unlink(MDP_CACHE_DIR . '_queue.json');
        }

        // 3. Finalize status.
        if (file_exists(MDP_CACHE_DIR . '_status.json')) {
            $status = json_decode(file_get_contents(MDP_CACHE_DIR . '_status.json'), true);
            $status['finished'] = current_time('mysql');
            file_put_contents(MDP_CACHE_DIR . '_status.json', wp_json_encode($status));
        }

        wp_send_json_success(array('message' => 'Generation stopped.'));
    }

    /**
     * AJAX: Clear cache.
     */
    public function ajax_clear_cache()
    {
        check_ajax_referer('mdp_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        MDP_Generator::clear_cache();

        // Queue and status are cleaned as part of clear_cache (they're JSON files in the cache dir).

        wp_send_json_success(array('message' => 'Cache cleared.'));
    }

    /**
     * AJAX: Get current generation status.
     */
    public function ajax_get_status()
    {
        check_ajax_referer('mdp_admin_nonce', 'nonce');
        $status = MDP_Generator::get_status();
        $remaining = MDP_Generator::get_queue_count();

        wp_send_json_success(array(
            'status' => $status,
            'remaining' => $remaining,
            'files' => $this->count_cache_files(),
            'size' => size_format($this->get_cache_size()),
        ));
    }

    /* ───────────────────────────── Helpers ───────────────────────────── */

    /**
     * Handle the ZIP download request.
     */
    public function handle_zip_download()
    {
        if (!isset($_GET['mdp_download_zip']) || !current_user_can('manage_options')) {
            return;
        }

        if (!class_exists('ZipArchive')) {
            wp_die('ZipArchive PHP extension is not enabled on your server.');
        }

        $zip_file = tempnam(sys_get_temp_dir(), 'mdp');
        $zip = new ZipArchive();

        if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            wp_die('Could not create temporary ZIP file.');
        }

        $root_path = MDP_CACHE_DIR;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $file_path = $file->getRealPath();
                $relative_path = substr($file_path, strlen($root_path));
                $zip->addFile($file_path, $relative_path);
            }
        }

        $zip->close();

        // Serve the file.
        $filename = 'markdown-cache-' . date('Y-m-d') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($zip_file));
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($zip_file);
        unlink($zip_file);
        exit;
    }

    /**
     * Get total markdown files directory size in bytes.
     */
    private function get_cache_size()
    {
        if (!file_exists(MDP_CACHE_DIR)) {
            return 0;
        }
        $size = 0;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(MDP_CACHE_DIR, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            $size += $file->getSize();
        }
        return $size;
    }

    /**
     * Count total .md files in cache.
     */
    private function count_cache_files()
    {
        if (!file_exists(MDP_CACHE_DIR)) {
            return 0;
        }
        $count = 0;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(MDP_CACHE_DIR, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if (preg_match('/\.(md|txt)$/i', $file->getFilename())) {
                $count++;
            }
        }
        return $count;
    }
}
