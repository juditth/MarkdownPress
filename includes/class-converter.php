<?php
/**
 * Converts individual WordPress content items (posts, pages, terms, authors)
 * to Markdown files and stores them in the cache directory.
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
     * Convert a single post/page/CPT to markdown and save it.
     *
     * @param  int  $post_id  The post ID.
     * @return bool           True on success.
     */
    public function convert_post($post_id)
    {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            return false;
        }

        $options = mdp_get_options();

        // Check if this post type should be processed.
        $allowed_types = $this->get_allowed_post_types();
        if (!in_array($post->post_type, $allowed_types, true)) {
            return false;
        }

        // Check exclusion.
        $permalink = get_permalink($post_id);
        if ($this->is_excluded($permalink)) {
            return false;
        }

        // Get rendered content.
        $html = $this->get_rendered_content($post, $options['content_method']);
        if (empty($html)) {
            return false;
        }

        // Convert to markdown.
        $markdown = MDP_Html_To_Markdown::convert($html);

        // Build frontmatter.
        $frontmatter = '';
        if ($options['frontmatter']) {
            $frontmatter = $this->build_frontmatter($post);
        }

        $full_content = $frontmatter . $markdown;

        // Determine file path from URL.
        $file_path = $this->url_to_cache_path($permalink);

        // Ensure directory exists.
        $dir = dirname($file_path);
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        // Write file.
        return (bool) file_put_contents($file_path, $full_content);
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
            return false;
        }

        $term_link = get_term_link($term);
        if (is_wp_error($term_link)) {
            return false;
        }

        if ($this->is_excluded($term_link)) {
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

        return (bool) file_put_contents($file_path, $md);
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
            return false;
        }

        $author_url = get_author_posts_url($user_id);
        if ($this->is_excluded($author_url)) {
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

        return (bool) file_put_contents($file_path, $md);
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
        return (bool) file_put_contents($file_path, $md);
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
            unlink($file_path);
        }
        // Try removing empty parent directories.
        $dir = dirname($file_path);
        while ($dir !== MDP_CACHE_DIR && is_dir($dir)) {
            $files = glob($dir . '/*');
            if (empty($files)) {
                rmdir($dir);
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
            if (empty(trim(strip_tags($html))) && class_exists('\Bricks\Frontend')) {
                $html = \Bricks\Frontend::render_data($post->ID);
            }

            // Restore original.
            if ($original_post) {
                $GLOBALS['post'] = $original_post;
                setup_postdata($original_post);
            } else {
                wp_reset_postdata();
            }

            if (!empty(trim(strip_tags($html)))) {
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
    private function fetch_content_via_http($url)
    {
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'user-agent' => 'MarkdownPress/1.0',
            'headers' => array(
                'Accept' => 'text/html',
            ),
        ));

        if (is_wp_error($response)) {
            return '';
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return '';
        }

        // Try to extract main content area.
        // Look for common content selectors across various themes and builders.
        $selectors = array(
            '<div[^>]*id="bricks-content"[^>]*>(.*?)<\/div>',
            '<div[^>]*class="[^"]*elementor[^"]*"[^>]*>(.*?)<\/div>',
            '<div[^>]*class="[^"]*fl-builder-content[^"]*"[^>]*>(.*?)<\/div>',
            '<div[^>]*class="[^"]*et_builder_inner_content[^"]*"[^>]*>(.*?)<\/div>',
            '<main[^>]*>(.*?)<\/main>',
            '<article[^>]*>(.*?)<\/article>',
            '<div[^>]*class="[^"]*entry-content[^"]*"[^>]*>(.*?)<\/div>',
            '<div[^>]*class="[^"]*post-content[^"]*"[^>]*>(.*?)<\/div>',
            '<div[^>]*class="[^"]*page-content[^"]*"[^>]*>(.*?)<\/div>',
            '<div[^>]*id="content"[^>]*>(.*?)<\/div>',
        );

        foreach ($selectors as $selector) {
            if (preg_match('/' . $selector . '/si', $body, $matches)) {
                return $matches[1];
            }
        }

        // If nothing found, return body between <body> tags.
        if (preg_match('/<body[^>]*>(.*?)<\/body>/si', $body, $matches)) {
            return $matches[1];
        }

        return $body;
    }

    /* ───────────────────────────── Helpers ───────────────────────────── */

    /**
     * Convert a URL to a file path in the cache directory.
     * Example: https://example.com/about/team/ → wp-markdown/about/team/index.md
     */
    public function url_to_cache_path($url)
    {
        $parsed = wp_parse_url($url);
        $path = isset($parsed['path']) ? $parsed['path'] : '/';
        $path = trim($path, '/');

        if (empty($path)) {
            return MDP_CACHE_DIR . 'index.md';
        }

        // Remove .html / .php extension if present.
        $path = preg_replace('/\.(html?|php)$/i', '', $path);

        return MDP_CACHE_DIR . $path . '/index.md';
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
        $str = wp_strip_all_tags($str);
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
