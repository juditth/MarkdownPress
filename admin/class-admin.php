<?php
/**
 * Admin settings page for WP Markdown Cache.
 * Adds a menu page under Settings, handles options, manual triggers, and AJAX status check.
 *
 * @package WP_Markdown_Cache
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPMC_Admin
{

    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        // AJAX handlers.
        add_action('wp_ajax_wpmc_generate_now', array($this, 'ajax_generate_now'));
        add_action('wp_ajax_wpmc_clear_cache', array($this, 'ajax_clear_cache'));
        add_action('wp_ajax_wpmc_get_status', array($this, 'ajax_get_status'));
    }

    /**
     * Add settings page.
     */
    public function add_menu()
    {
        add_options_page(
            'WP Markdown Cache',
            'Markdown Cache',
            'manage_options',
            'wp-markdown-cache',
            array($this, 'render_page')
        );
    }

    /**
     * Enqueue admin CSS and JS.
     */
    public function enqueue_assets($hook)
    {
        if ($hook !== 'settings_page_wp-markdown-cache') {
            return;
        }
        wp_enqueue_style('wpmc-admin', WPMC_PLUGIN_URL . 'admin/assets/admin.css', array(), WPMC_VERSION);
        wp_enqueue_script('wpmc-admin', WPMC_PLUGIN_URL . 'admin/assets/admin.js', array('jquery'), WPMC_VERSION, true);
        wp_localize_script('wpmc-admin', 'wpmcAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpmc_admin_nonce'),
        ));
    }

    /**
     * Register settings.
     */
    public function register_settings()
    {
        register_setting('wpmc_options_group', 'wpmc_options', array(
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
        wpmc_schedule_cron();

        return $sanitized;
    }

    /**
     * Render the settings page.
     */
    public function render_page()
    {
        $options = wpmc_get_options();
        $status = WPMC_Generator::get_status();
        $all_post_types = get_post_types(array('public' => true), 'objects');
        $cache_size = $this->get_cache_size();
        $cache_files = $this->count_cache_files();
        $next_cron = wp_next_scheduled('wpmc_cron_generate');
        $is_processing = !empty(get_option(WPMC_Generator::QUEUE_OPTION, array()));
        ?>
        <div class="wrap wpmc-wrap">
            <h1>
                <span class="dashicons dashicons-media-text" style="font-size: 30px; margin-right: 8px;"></span>
                WP Markdown Cache
            </h1>

            <!-- Status cards -->
            <div class="wpmc-status-cards">
                <div class="wpmc-card">
                    <div class="wpmc-card-icon dashicons dashicons-media-document"></div>
                    <div class="wpmc-card-content">
                        <div class="wpmc-card-number">
                            <?php echo number_format($cache_files); ?>
                        </div>
                        <div class="wpmc-card-label">Cached files</div>
                    </div>
                </div>
                <div class="wpmc-card">
                    <div class="wpmc-card-icon dashicons dashicons-database"></div>
                    <div class="wpmc-card-content">
                        <div class="wpmc-card-number">
                            <?php echo size_format($cache_size); ?>
                        </div>
                        <div class="wpmc-card-label">Cache size</div>
                    </div>
                </div>
                <div class="wpmc-card">
                    <div class="wpmc-card-icon dashicons dashicons-clock"></div>
                    <div class="wpmc-card-content">
                        <div class="wpmc-card-number">
                            <?php echo $next_cron ? wp_date('H:i', $next_cron) : '—'; ?>
                        </div>
                        <div class="wpmc-card-label">Next cron run</div>
                    </div>
                </div>
                <div class="wpmc-card <?php echo $is_processing ? 'wpmc-card-active' : ''; ?>">
                    <div
                        class="wpmc-card-icon dashicons dashicons-<?php echo $is_processing ? 'update wpmc-spin' : 'yes-alt'; ?>">
                    </div>
                    <div class="wpmc-card-content">
                        <div class="wpmc-card-number" id="wpmc-status-text">
                            <?php echo $is_processing ? 'Processing...' : 'Idle'; ?>
                        </div>
                        <div class="wpmc-card-label" id="wpmc-status-detail">
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
            <div class="wpmc-actions">
                <button id="wpmc-generate-now" class="button button-primary button-hero" <?php echo $is_processing ? 'disabled' : ''; ?>>
                    <span class="dashicons dashicons-controls-play"></span>
                    Generate Now
                </button>
                <button id="wpmc-clear-cache" class="button button-secondary">
                    <span class="dashicons dashicons-trash"></span>
                    Clear Cache
                </button>

                <?php if (file_exists(WPMC_CACHE_DIR . 'llms.txt')): ?>
                    <a href="<?php echo home_url('/llms.txt'); ?>" target="_blank" class="button">
                        <span class="dashicons dashicons-external"></span>
                        View llms.txt
                    </a>
                <?php endif; ?>
            </div>

            <!-- Progress bar -->
            <div id="wpmc-progress" class="wpmc-progress" style="<?php echo $is_processing ? '' : 'display:none;'; ?>">
                <div class="wpmc-progress-bar">
                    <div class="wpmc-progress-fill"
                        style="width: <?php echo $status['total'] > 0 ? round($status['processed'] / $status['total'] * 100) : 0; ?>%">
                    </div>
                </div>
                <div class="wpmc-progress-text" id="wpmc-progress-text">
                    <?php if ($status['total'] > 0): ?>
                        <?php echo $status['processed']; ?> /
                        <?php echo $status['total']; ?> pages
                    <?php endif; ?>
                </div>
            </div>

            <!-- Settings form -->
            <form method="post" action="options.php" class="wpmc-settings-form">
                <?php settings_fields('wpmc_options_group'); ?>

                <div class="wpmc-settings-grid">

                    <!-- General settings -->
                    <div class="wpmc-settings-section">
                        <h2>General</h2>
                        <table class="form-table">
                            <tr>
                                <th>Enable</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="wpmc_options[enabled]" value="1" <?php checked($options['enabled']); ?> />
                                        Enable markdown cache and serving
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th>Content source</th>
                                <td>
                                    <label>
                                        <input type="radio" name="wpmc_options[source]" value="all" <?php checked($options['source'], 'all'); ?> />
                                        All published content (posts, pages, CPT, taxonomies, authors)
                                    </label><br>
                                    <label>
                                        <input type="radio" name="wpmc_options[source]" value="sitemap" <?php checked($options['source'], 'sitemap'); ?> />
                                        URLs from XML Sitemap only
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th>Post types</th>
                                <td>
                                    <?php foreach ($all_post_types as $pt): ?>
                                        <label style="display: inline-block; margin-right: 16px; margin-bottom: 6px;">
                                            <input type="checkbox" name="wpmc_options[post_types][]"
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
                                        <input type="checkbox" name="wpmc_options[include_taxonomies]" value="1" <?php checked($options['include_taxonomies']); ?> />
                                        Taxonomy archives (categories, tags, custom taxonomies)
                                    </label><br>
                                    <label>
                                        <input type="checkbox" name="wpmc_options[include_authors]" value="1" <?php checked($options['include_authors']); ?> />
                                        Author archives
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th>Rendering method</th>
                                <td>
                                    <select name="wpmc_options[content_method]">
                                        <option value="filter" <?php selected($options['content_method'], 'filter'); ?>
                                            >WordPress filters (fast, works with most builders)</option>
                                        <option value="http" <?php selected($options['content_method'], 'http'); ?>>HTTP fetch
                                            (slow, but 100% accurate)</option>
                                        <option value="both" <?php selected($options['content_method'], 'both'); ?>>Try
                                            filters first, fallback to HTTP</option>
                                    </select>
                                    <p class="description">
                                        <strong>Filters</strong> — uses <code>apply_filters('the_content')</code>, works with
                                        Gutenberg, Elementor, WPBakery, ACF, etc.<br>
                                        <strong>HTTP</strong> — fetches each page as HTML (like a browser), extracts main
                                        content. Slower but works with any setup.<br>
                                        <strong>Both</strong> — tries filters first, falls back to HTTP if content is empty.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Schedule settings -->
                    <div class="wpmc-settings-section">
                        <h2>Schedule & Performance</h2>
                        <table class="form-table">
                            <tr>
                                <th>Cron time</th>
                                <td>
                                    <input type="time" name="wpmc_options[cron_time]"
                                        value="<?php echo esc_attr($options['cron_time']); ?>" />
                                    <p class="description">Daily full regeneration time (server timezone:
                                        <?php echo wp_timezone_string(); ?>)
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th>Batch size</th>
                                <td>
                                    <input type="number" name="wpmc_options[batch_size]"
                                        value="<?php echo esc_attr($options['batch_size']); ?>" min="1" max="100" step="1"
                                        style="width: 80px;" />
                                    <p class="description">Number of pages to process per batch (1–100). Lower = gentler on
                                        server.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Batch delay</th>
                                <td>
                                    <input type="number" name="wpmc_options[batch_delay]"
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
                                        <input type="checkbox" name="wpmc_options[regenerate_on_save]" value="1" <?php checked($options['regenerate_on_save']); ?> />
                                        Regenerate markdown when a post is published/updated
                                    </label>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Output settings -->
                    <div class="wpmc-settings-section">
                        <h2>Output</h2>
                        <table class="form-table">
                            <tr>
                                <th>YAML frontmatter</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="wpmc_options[frontmatter]" value="1" <?php checked($options['frontmatter']); ?> />
                                        Include title, URL, dates, categories, tags in each markdown file
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th>Generate _all.md</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="wpmc_options[generate_all_md]" value="1" <?php checked($options['generate_all_md']); ?> />
                                        Concatenate all pages into a single file (for LLM context windows)
                                    </label>
                                    <p class="description">Warning: can be very large on big sites.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Generate llms.txt</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="wpmc_options[generate_llms_txt]" value="1" <?php checked($options['generate_llms_txt']); ?> />
                                        Generate <code>/llms.txt</code> and <code>/llms-full.txt</code>
                                    </label>
                                    <p class="description">Like robots.txt but for LLMs. Describes your site structure for AI
                                        crawlers.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Exclude URLs</th>
                                <td>
                                    <textarea name="wpmc_options[exclude_urls]" rows="5"
                                        class="large-text code"><?php echo esc_textarea($options['exclude_urls']); ?></textarea>
                                    <p class="description">One URL pattern per line. Pages matching any pattern will be
                                        excluded.<br>
                                        Built-in exclusions: <code>/wp-admin</code>, <code>/wp-login</code>,
                                        <code>/wp-json</code>, <code>/feed</code>, <code>/cart</code>, <code>/checkout</code>,
                                        <code>/my-account</code></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <p class="wpmc-cache-dir-info">
                    <strong>Cache directory:</strong> <code><?php echo esc_html(WPMC_CACHE_DIR); ?></code>
                </p>

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
        check_ajax_referer('wpmc_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $generator = new WPMC_Generator();
        $total = $generator->queue_all();

        // Process first batch immediately.
        $remaining = $generator->process_batch();

        // Schedule subsequent batches.
        if ($remaining > 0 && !wp_next_scheduled('wpmc_cron_batch')) {
            wp_schedule_event(time() + 10, 'wpmc_batch_interval', 'wpmc_cron_batch');
        }

        // If everything was done in one batch, generate summary files.
        if ($remaining === 0) {
            $generator->generate_summary_files();
            $options = wpmc_get_options();
            if ($options['generate_llms_txt']) {
                WPMC_Llms_Txt::generate();
            }
        }

        wp_send_json_success(array(
            'total' => $total,
            'remaining' => $remaining,
            'status' => WPMC_Generator::get_status(),
        ));
    }

    /**
     * AJAX: Clear cache.
     */
    public function ajax_clear_cache()
    {
        check_ajax_referer('wpmc_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        WPMC_Generator::clear_cache();

        // Also clear queue and status.
        delete_option(WPMC_Generator::QUEUE_OPTION);
        delete_option(WPMC_Generator::STATUS_OPTION);

        wp_send_json_success(array('message' => 'Cache cleared.'));
    }

    /**
     * AJAX: Get current generation status.
     */
    public function ajax_get_status()
    {
        check_ajax_referer('wpmc_admin_nonce', 'nonce');
        $status = WPMC_Generator::get_status();
        $queue = get_option(WPMC_Generator::QUEUE_OPTION, array());

        wp_send_json_success(array(
            'status' => $status,
            'remaining' => count($queue),
            'files' => $this->count_cache_files(),
            'size' => size_format($this->get_cache_size()),
        ));
    }

    /* ───────────────────────────── Helpers ───────────────────────────── */

    /**
     * Get total cache directory size in bytes.
     */
    private function get_cache_size()
    {
        if (!file_exists(WPMC_CACHE_DIR)) {
            return 0;
        }
        $size = 0;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(WPMC_CACHE_DIR, RecursiveDirectoryIterator::SKIP_DOTS)
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
        if (!file_exists(WPMC_CACHE_DIR)) {
            return 0;
        }
        $count = 0;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(WPMC_CACHE_DIR, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if (preg_match('/\.(md|txt)$/i', $file->getFilename())) {
                $count++;
            }
        }
        return $count;
    }
}
