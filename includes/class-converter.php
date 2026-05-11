<?php
/**
 * Converts individual WordPress content items (posts, pages, terms, authors)
 * to Markdown files and stores them in the markdown files directory.
 *
 * Uses apply_filters('the_content') for builder compatibility.
 * Falls back to HTTP fetch if needed.
 *
 * @package MarkdownPress
 */

if (!defined('ABSPATH')) {
    exit;
}

class MDP_Converter
{
    /**
     * Store the last error message.
     */
    private $last_error = '';

    /**
     * Full HTML bodies fetched through HTTP during the current conversion run.
     */
    private $http_bodies = array();

    /**
     * Get the last error message.
     */
    public function get_last_error()
    {
        return $this->last_error;
    }

    /**
     * Set the last error message for callers that use converter helpers.
     */
    public function set_last_error($message)
    {
        $this->last_error = $message;
    }

    /**
     * Convert a single post/page/CPT to markdown and save it.
     *
     * @param  int  $post_id  The post ID.
     * @return bool           True on success.
     */
    public function convert_post($post_id)
    {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            $this->last_error = "Post not found or not published.";
            return false;
        }

        $options = mdp_get_options();

        // Check if this post type should be processed.
        $allowed_types = $this->get_allowed_post_types();
        if (!in_array($post->post_type, $allowed_types, true)) {
            $this->last_error = "Post type '{$post->post_type}' is not allowed in settings.";
            return false;
        }

        // Check exclusion.
        $permalink = get_permalink($post_id);
        if ($this->is_excluded($permalink)) {
            $this->last_error = "URL is excluded by rules.";
            return false;
        }

        // Get rendered content.
        $html = $this->get_rendered_content($post, $options['content_method']);
        if (empty(trim(wp_strip_all_tags($html)))) {
            $this->last_error = "Rendered content is empty (tried method: {$options['content_method']}).";
            return false;
        }

        // Convert to markdown.
        $markdown = MDP_Html_To_Markdown::convert($html);

        // Build frontmatter.
        $frontmatter = '';
        if ($options['frontmatter']) {
            $frontmatter = $this->build_frontmatter($post);
        }

        $full_content = MDP_Html_To_Markdown::normalize_text($frontmatter . $markdown);
        $full_content = $this->append_json_schema($full_content, $permalink);

        // Determine file path from URL.
        $file_path = $this->url_to_cache_path($permalink);

        // Ensure directory exists.
        $dir = dirname($file_path);
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        // Write file.
        $result = (bool) file_put_contents($file_path, $full_content);
        if (!$result) {
            $this->last_error = "Could not write file to: {$file_path}";
        }
        return $result;
    }

    /**
     * Convert a taxonomy term archive page to markdown.
     *
     * @param  int    $term_id  The term ID.
     * @param  string $taxonomy The taxonomy slug.
     * @return bool
     */
    public function convert_term($term_id, $taxonomy)
    {
        $term = get_term($term_id, $taxonomy);
        if (!$term || is_wp_error($term)) {
            $this->last_error = "Term not found or invalid (ID: {$term_id}, Tax: {$taxonomy}).";
            return false;
        }

        $term_link = get_term_link($term);
        if (is_wp_error($term_link)) {
            $this->last_error = "Could not get permalink for term '{$term->name}'.";
            return false;
        }

        if ($this->is_excluded($term_link)) {
            $this->last_error = "Term URL is excluded by rules.";
            return false;
        }

        $options = mdp_get_options();

        // Build content: term description + list of posts.
        $md = '';

        if ($options['frontmatter']) {
            $md .= "---\n";
            $md .= 'title: "' . $this->escape_yaml($term->name) . '"' . "\n";
            $md .= 'url: "' . $term_link . '"' . "\n";
            $md .= 'type: "taxonomy_archive"' . "\n";
            $md .= 'taxonomy: "' . $taxonomy . '"' . "\n";
            $md .= 'description: "' . $this->escape_yaml($term->description) . '"' . "\n";
            $md .= 'post_count: ' . $term->count . "\n";
            $md .= "---\n\n";
        }

        $md .= "# {$term->name}\n\n";

        if (!empty($term->description)) {
            $md .= MDP_Html_To_Markdown::convert($term->description) . "\n\n";
        }

        // List posts in this term.
        $posts = get_posts(array(
            'post_type' => 'any',
            'posts_per_page' => 200,
            'tax_query' => array(
                array(
                    'taxonomy' => $taxonomy,
                    'field' => 'term_id',
                    'terms' => $term_id,
                ),
            ),
        ));

        if (!empty($posts)) {
            $md .= "## Příspěvky\n\n";
            foreach ($posts as $p) {
                $md .= '- [' . $p->post_title . '](' . get_permalink($p->ID) . ")\n";
            }
        }

        $file_path = $this->url_to_cache_path($term_link);
        $dir = dirname($file_path);
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        $md = $this->append_json_schema($md, $term_link);

        return (bool) file_put_contents($file_path, MDP_Html_To_Markdown::normalize_text($md));
    }

    /**
     * Convert an author archive page to markdown.
     *
     * @param  int  $user_id  The user ID.
     * @return bool
     */
    public function convert_author($user_id)
    {
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            $this->last_error = "User not found (ID: {$user_id}).";
            return false;
        }

        $author_url = get_author_posts_url($user_id);
        if (!$author_url) {
            $this->last_error = "Could not get author archive URL for ID: {$user_id}.";
            return false;
        }

        if ($this->is_excluded($author_url)) {
            $this->last_error = "Author URL is excluded by rules.";
            return false;
        }

        $options = mdp_get_options();
        $md = '';

        if ($options['frontmatter']) {
            $md .= "---\n";
            $md .= 'title: "' . $this->escape_yaml($user->display_name) . '"' . "\n";
            $md .= 'url: "' . $author_url . '"' . "\n";
            $md .= 'type: "author_archive"' . "\n";
            $md .= 'bio: "' . $this->escape_yaml(get_the_author_meta('description', $user_id)) . '"' . "\n";
            $md .= "---\n\n";
        }

        $md .= "# {$user->display_name}\n\n";

        $bio = get_the_author_meta('description', $user_id);
        if ($bio) {
            $md .= "{$bio}\n\n";
        }

        // List author's posts.
        $posts = get_posts(array(
            'author' => $user_id,
            'post_type' => 'any',
            'posts_per_page' => 200,
            'post_status' => 'publish',
        ));

        if (!empty($posts)) {
            $md .= "## Příspěvky\n\n";
            foreach ($posts as $p) {
                $md .= '- [' . $p->post_title . '](' . get_permalink($p->ID) . ")\n";
            }
        }

        $file_path = $this->url_to_cache_path($author_url);
        $dir = dirname($file_path);
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        $md = $this->append_json_schema($md, $author_url);

        return (bool) file_put_contents($file_path, MDP_Html_To_Markdown::normalize_text($md));
    }

    /**
     * Convert the homepage to markdown.
     *
     * @return bool
     */
    public function convert_homepage()
    {
        $home_url = home_url('/');
        $options = mdp_get_options();

        if ($this->is_excluded($home_url)) {
            $this->last_error = "Homepage is excluded by rules.";
            return false;
        }

        // Check if it's a static page.
        $front_page_id = get_option('page_on_front');
        if ($front_page_id && get_option('show_on_front') === 'page') {
            return $this->convert_post($front_page_id);
        }

        // Blog index: list recent posts.
        $md = '';
        if ($options['frontmatter']) {
            $md .= "---\n";
            $md .= 'title: "' . $this->escape_yaml(get_bloginfo('name')) . '"' . "\n";
            $md .= 'url: "' . $home_url . '"' . "\n";
            $md .= 'type: "homepage"' . "\n";
            $md .= 'description: "' . $this->escape_yaml(get_bloginfo('description')) . '"' . "\n";
            $md .= "---\n\n";
        }

        $md .= '# ' . get_bloginfo('name') . "\n\n";
        $md .= get_bloginfo('description') . "\n\n";

        $recent = get_posts(array(
            'post_type' => 'post',
            'posts_per_page' => 50,
            'post_status' => 'publish',
        ));

        if (!empty($recent)) {
            $md .= "## Nejnovější příspěvky\n\n";
            foreach ($recent as $p) {
                $date = get_the_date('Y-m-d', $p->ID);
                $md .= "- [{$p->post_title}](" . get_permalink($p->ID) . ") ({$date})\n";
            }
        }

        $file_path = MDP_CACHE_DIR . 'index.md';
        $md = $this->append_json_schema($md, $home_url);

        return (bool) file_put_contents($file_path, MDP_Html_To_Markdown::normalize_text($md));
    }

    /**
     * Delete cached markdown for a post.
     */
    public function delete_cache_for_post($post_id)
    {
        $permalink = get_permalink($post_id);
        if (!$permalink) {
            return;
        }
        $file_path = $this->url_to_cache_path($permalink);
        if (file_exists($file_path)) {
            wp_delete_file($file_path);
        }
        // Try removing empty parent directories.
        $dir = dirname($file_path);

        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        while ($dir !== MDP_CACHE_DIR && is_dir($dir)) {
            $files = glob($dir . '/*');
            if (empty($files)) {
                if ($wp_filesystem) {
                    $wp_filesystem->rmdir($dir);
                } else {
                    @rmdir($dir); // Fallback.
                }
                $dir = dirname($dir);
            } else {
                break;
            }
        }
    }

    /* ───────────────────────────── Content rendering ───────────────────────────── */

    /**
     * Get rendered HTML content for a post.
     * Tries multiple strategies for maximum builder compatibility.
     */
    private function get_rendered_content(WP_Post $post, $method = 'filter')
    {
        // Strategy 1: apply_filters — works with Gutenberg, Elementor, WPBakery, ACF, etc.
        if ($method === 'filter' || $method === 'both') {
            // Set up global post context (needed for some builders & shortcodes).
            $original_post = isset($GLOBALS['post']) ? $GLOBALS['post'] : null;
            $GLOBALS['post'] = $post;
            setup_postdata($post);

            $html = apply_filters('the_content', $post->post_content);

            // SPECIAL CASE: Bricks builder. 
            // If the content is empty but it's a Bricks page, try explicit render.
            if (empty(trim(wp_strip_all_tags($html))) && class_exists('\Bricks\Frontend')) {
                $html = \Bricks\Frontend::render_data($post->ID);
            }

            // Restore original.
            if ($original_post) {
                $GLOBALS['post'] = $original_post;
                setup_postdata($original_post);
            } else {
                wp_reset_postdata();
            }

            if (!empty(trim(wp_strip_all_tags($html)))) {
                return $html;
            }
        }

        // Strategy 2: HTTP fetch — ultimate fallback, gets exactly what a browser would see.
        if ($method === 'http' || $method === 'both') {
            return $this->fetch_content_via_http(get_permalink($post->ID));
        }

        // Strategy 3: raw post_content as last resort.
        return $post->post_content;
    }

    /**
     * Fetch page content via HTTP and extract the main content area.
     */
    public function fetch_content_via_http($url)
    {
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'user-agent' => 'MarkdownPress/1.0',
            'headers' => array(
                'Accept' => 'text/html',
            ),
        ));

        if (is_wp_error($response)) {
            $this->last_error = "HTTP Fetch failed: " . $response->get_error_message();
            return '';
        }

        $body = MDP_Html_To_Markdown::normalize_text(wp_remote_retrieve_body($response));
        if (empty($body)) {
            $this->last_error = "HTTP Fetch returned empty body (Status: " . wp_remote_retrieve_response_code($response) . ").";
            return '';
        }

        $this->http_bodies[$url] = $body;

        $extracted = $this->extract_main_content($body);
        if (empty(trim(wp_strip_all_tags($extracted)))) {
            $this->last_error = "HTTP Fetch succeeded, but content extraction (<body>) resulted in empty text.";
            return '';
        }

        return $extracted;
    }

    /**
     * Universal content extraction: returns the content between <body> tags.
     * Filtering of unwanted elements (nav, footer, etc.) is handled by the converter.
     */
    public function extract_main_content($body)
    {
        if (empty($body)) {
            return '';
        }

        $doc = new DOMDocument();
        // Handle UTF-8 properly and suppress warnings for invalid HTML.
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $body, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $body_nodes = $doc->getElementsByTagName('body');
        if ($body_nodes->length > 0) {
            $inner = '';
            foreach ($body_nodes->item(0)->childNodes as $child) {
                $inner .= $doc->saveHTML($child);
            }
            return $inner;
        }

        return $body;
    }

    /**
     * Append JSON-LD schema blocks published in the public page HTML.
     *
     * MarkdownPress does not invent schema from post metadata. It only appends
     * schema that already exists in HTML fetched through the HTTP Fetch renderer.
     */
    public function append_json_schema($markdown, $url)
    {
        $options = mdp_get_options();
        if (empty($options['append_json_schema']) || empty($url)) {
            return $markdown;
        }

        if (($options['content_method'] ?? '') !== 'http') {
            return $markdown;
        }

        if (empty($this->http_bodies[$url])) {
            return $markdown;
        }

        $schemas = $this->extract_json_schema_blocks($this->http_bodies[$url]);
        if (empty($schemas)) {
            return $markdown;
        }

        $appendix = "\n\n## JSON Schema\n\n";
        foreach ($schemas as $schema) {
            $appendix .= "```json\n" . $schema . "\n```\n\n";
        }

        return rtrim($markdown) . $appendix;
    }

    /**
     * Extract and normalize JSON-LD blocks from HTML.
     */
    private function extract_json_schema_blocks($html)
    {
        $schemas = array();

        if (preg_match_all('/<script\b(?=[^>]*\btype=["\']?application\/ld\+json\b[^>]*>)(?:[^>]*)>(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $raw_json) {
                $json = $this->normalize_json_schema_block($raw_json);
                if ($json !== '') {
                    $schemas[] = $json;
                }
            }
        }

        $doc = new DOMDocument();

        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        foreach ($doc->getElementsByTagName('script') as $script) {
            $type = strtolower(trim($script->getAttribute('type')));
            if (strpos($type, 'application/ld+json') !== 0) {
                continue;
            }

            $json = $this->normalize_json_schema_block($script->textContent);
            if ($json === '') {
                continue;
            }

            $schemas[] = $json;
        }

        return array_values(array_unique($schemas));
    }

    /**
     * Normalize one JSON-LD script block and apply schema filtering.
     */
    private function normalize_json_schema_block($raw_json)
    {
        $json = trim(html_entity_decode($raw_json, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($json === '') {
            return '';
        }

        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $json;
        }

        $decoded = $this->remove_ignored_schema_types($decoded);
        if (empty($decoded)) {
            return '';
        }

        return wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Remove low-value schema types from JSON-LD before appending it.
     */
    private function remove_ignored_schema_types($schema)
    {
        $ignored_types = apply_filters('mdp_ignored_json_schema_types', array('BreadcrumbList'));

        if (isset($schema['@graph']) && is_array($schema['@graph'])) {
            $schema['@graph'] = array_values(array_filter($schema['@graph'], function ($item) use ($ignored_types) {
                return !$this->schema_has_ignored_type($item, $ignored_types);
            }));

            if (empty($schema['@graph'])) {
                return array();
            }

            return $schema;
        }

        if ($this->is_list_array($schema)) {
            $schema = array_values(array_filter($schema, function ($item) use ($ignored_types) {
                return !$this->schema_has_ignored_type($item, $ignored_types);
            }));
        }

        if ($this->schema_has_ignored_type($schema, $ignored_types)) {
            return array();
        }

        return $schema;
    }

    /**
     * Check whether a JSON-LD item has a type that should be skipped.
     */
    private function schema_has_ignored_type($schema, $ignored_types)
    {
        if (!is_array($schema) || empty($schema['@type'])) {
            return false;
        }

        $types = is_array($schema['@type']) ? $schema['@type'] : array($schema['@type']);
        foreach ($types as $type) {
            if (in_array($type, $ignored_types, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect arrays that represent a JSON list rather than an object.
     */
    private function is_list_array($value)
    {
        if (!is_array($value)) {
            return false;
        }

        if (function_exists('array_is_list')) {
            return array_is_list($value);
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    /* ───────────────────────────── Helpers ───────────────────────────── */

    /**
     * Convert a URL to a file path in the markdown files directory.
     * Example: https://example.com/about/team/ → wp-markdown/about/team/index.md
     */
    public function url_to_cache_path($url)
    {
        $parsed = wp_parse_url($url);
        $path = isset($parsed['path']) ? $parsed['path'] : '/';
        $path = $this->sanitize_url_path($path);

        if (empty($path)) {
            return MDP_CACHE_DIR . 'index.md';
        }

        // Remove .html / .php extension if present.
        $path = preg_replace('/\.(html?|php)$/i', '', $path);

        return MDP_CACHE_DIR . $path . '/index.md';
    }

    /**
     * Normalize a URL path into safe cache path segments.
     */
    private function sanitize_url_path($path)
    {
        $path = str_replace('\\', '/', (string) $path);
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }

        $safe_segments = array();
        foreach (explode('/', $path) as $segment) {
            $decoded = rawurldecode($segment);
            if ($decoded === '' || $decoded === '.' || $decoded === '..') {
                continue;
            }

            if (strpos($decoded, '/') !== false || strpos($decoded, '\\') !== false) {
                continue;
            }

            $segment = preg_replace('/[\x00-\x1F\x7F\\\\]/', '', $segment);
            if ($segment !== '') {
                $safe_segments[] = $segment;
            }
        }

        return implode('/', $safe_segments);
    }

    /**
     * Build YAML frontmatter for a post.
     */
    private function build_frontmatter(WP_Post $post)
    {
        $fm = "---\n";
        $fm .= 'title: "' . $this->escape_yaml($post->post_title) . '"' . "\n";
        $fm .= 'url: "' . get_permalink($post->ID) . '"' . "\n";
        $fm .= 'type: "' . $post->post_type . '"' . "\n";
        $fm .= 'date: "' . $post->post_date . '"' . "\n";
        $fm .= 'modified: "' . $post->post_modified . '"' . "\n";

        // Author.
        $author = get_the_author_meta('display_name', $post->post_author);
        if ($author) {
            $fm .= 'author: "' . $this->escape_yaml($author) . '"' . "\n";
        }

        // Excerpt / meta description.
        $excerpt = $post->post_excerpt ?: wp_trim_words($post->post_content, 30);
        $fm .= 'excerpt: "' . $this->escape_yaml($excerpt) . '"' . "\n";

        // Categories.
        $categories = wp_get_post_categories($post->ID, array('fields' => 'names'));
        if (!empty($categories) && !is_wp_error($categories)) {
            $fm .= 'categories: [' . implode(', ', array_map(function ($c) {
                return '"' . $this->escape_yaml($c) . '"';
            }, $categories)) . "]\n";
        }

        // Tags.
        $tags = wp_get_post_tags($post->ID, array('fields' => 'names'));
        if (!empty($tags) && !is_wp_error($tags)) {
            $fm .= 'tags: [' . implode(', ', array_map(function ($t) {
                return '"' . $this->escape_yaml($t) . '"';
            }, $tags)) . "]\n";
        }

        // Featured image.
        $thumb = get_the_post_thumbnail_url($post->ID, 'full');
        if ($thumb) {
            $fm .= 'featured_image: "' . $thumb . '"' . "\n";
        }

        $fm .= "---\n\n";
        return $fm;
    }

    /**
     * Escape a string for YAML.
     */
    private function escape_yaml($str)
    {
        $str = MDP_Html_To_Markdown::normalize_text(wp_strip_all_tags($str));
        $str = str_replace(array('"', "\n", "\r"), array('\\"', ' ', ''), $str);
        return trim($str);
    }

    /**
     * Check if a URL is excluded.
     */
    private function is_excluded($url)
    {
        $options = mdp_get_options();
        $excludes = array_filter(array_map('trim', explode("\n", $options['exclude_urls'])));

        // Always exclude admin, login, wp-json.
        $excludes = array_merge($excludes, array(
            '/wp-admin',
            '/wp-login',
            '/wp-json',
            '/wp-cron',
            '/feed',
            '/xmlrpc',
            '/cart',
            '/checkout',
            '/my-account',
        ));

        foreach ($excludes as $pattern) {
            if (strpos($url, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get list of allowed post types to process.
     */
    public function get_allowed_post_types()
    {
        $options = mdp_get_options();

        // If user has selected specific types, use those.
        if (!empty($options['post_types']) && is_array($options['post_types'])) {
            return $options['post_types'];
        }

        // Default: all public post types.
        return get_post_types(array('public' => true), 'names');
    }
}
