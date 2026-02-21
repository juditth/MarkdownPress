<?php
/**
 * Batch generator — queues all content items and processes them in batches
 * to avoid server overload. Also generates summary files (_all.md, _sitemap.md).
 *
 * @package MarkdownPress
 */

if (!defined('ABSPATH')) {
    exit;
}

class MDP_Generator
{

    /**
     * File paths for queue and status (no database!).
     */
    public static function get_queue_file()
    {
        return MDP_CACHE_DIR . '_queue.json';
    }

    public static function get_status_file()
    {
        return MDP_CACHE_DIR . '_status.json';
    }

    /**
     * Queue all content items for markdown generation.
     * This doesn't generate anything — it just builds the queue.
     */
    public function queue_all()
    {
        $options = mdp_get_options();
        $queue = array();

        if ($options['source'] === 'sitemap') {
            $queue = $this->get_urls_from_sitemap();
        } else {
            $queue = $this->get_all_content_items();
        }

        // Ensure cache directory exists.
        if (!file_exists(MDP_CACHE_DIR)) {
            wp_mkdir_p(MDP_CACHE_DIR);
        }

        // Store queue to JSON file (no database).
        file_put_contents(self::get_queue_file(), wp_json_encode($queue));
        file_put_contents(self::get_status_file(), wp_json_encode(array(
            'total' => count($queue),
            'processed' => 0,
            'started' => current_time('mysql'),
            'finished' => null,
            'errors' => 0,
        )));

        return count($queue);
    }

    /**
     * Process next batch from queue.
     *
     * @return int Number of remaining items.
     */
    public function process_batch()
    {
        $options = mdp_get_options();
        $batch_size = max(1, intval($options['batch_size']));
        $queue = self::read_json(self::get_queue_file(), array());
        $status = self::read_json(self::get_status_file(), array());

        if (empty($queue)) {
            $status['finished'] = current_time('mysql');
            file_put_contents(self::get_status_file(), wp_json_encode($status));
            // Remove empty queue file.
            if (file_exists(self::get_queue_file())) {
                @unlink(self::get_queue_file());
            }
            return 0;
        }

        $converter = new MDP_Converter();
        $batch = array_splice($queue, 0, $batch_size);

        foreach ($batch as $item) {
            $success = false;

            switch ($item['type']) {
                case 'post':
                    $success = $converter->convert_post($item['id']);
                    break;
                case 'term':
                    $success = $converter->convert_term($item['id'], $item['taxonomy']);
                    break;
                case 'author':
                    $success = $converter->convert_author($item['id']);
                    break;
                case 'homepage':
                    $success = $converter->convert_homepage();
                    break;
                case 'sitemap_url':
                    $success = $this->convert_sitemap_url($item['url']);
                    break;
            }

            $status['processed']++;
            if (!$success) {
                $status['errors']++;
            }
        }

        // Update queue and status files.
        file_put_contents(self::get_queue_file(), wp_json_encode($queue));
        file_put_contents(self::get_status_file(), wp_json_encode($status));

        return count($queue);
    }

    /**
     * Get all content items to process.
     *
     * @return array Queue items.
     */
    private function get_all_content_items()
    {
        $options = mdp_get_options();
        $converter = new MDP_Converter();
        $items = array();

        // 1. Homepage.
        $items[] = array('type' => 'homepage', 'id' => 0);

        // 2. All published posts of allowed types.
        $post_types = $converter->get_allowed_post_types();
        foreach ($post_types as $post_type) {
            $posts = get_posts(array(
                'post_type' => $post_type,
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'no_found_rows' => true,
            ));
            foreach ($posts as $post_id) {
                $items[] = array('type' => 'post', 'id' => $post_id);
            }
        }

        // 3. Taxonomy term archives.
        if ($options['include_taxonomies']) {
            $taxonomies = get_taxonomies(array('public' => true), 'names');
            foreach ($taxonomies as $taxonomy) {
                $terms = get_terms(array(
                    'taxonomy' => $taxonomy,
                    'hide_empty' => true,
                    'fields' => 'ids',
                ));
                if (!is_wp_error($terms)) {
                    foreach ($terms as $term_id) {
                        $items[] = array('type' => 'term', 'id' => $term_id, 'taxonomy' => $taxonomy);
                    }
                }
            }
        }

        // 4. Author archives.
        if ($options['include_authors']) {
            $users = get_users(array(
                'has_published_posts' => true,
                'fields' => 'ID',
            ));
            foreach ($users as $user_id) {
                $items[] = array('type' => 'author', 'id' => $user_id);
            }
        }

        return $items;
    }

    /**
     * Get URLs from site's XML sitemap.
     */
    private function get_urls_from_sitemap()
    {
        $items = array();

        // Try WP Core sitemap first (WP 5.5+).
        $sitemap_url = home_url('/wp-sitemap.xml');

        $response = wp_remote_get($sitemap_url, array(
            'timeout' => 15,
            'user-agent' => 'markdownpress/1.0',
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            // Try Yoast sitemap.
            $sitemap_url = home_url('/sitemap_index.xml');
            $response = wp_remote_get($sitemap_url, array(
                'timeout' => 15,
                'user-agent' => 'markdownpress/1.0',
            ));
        }

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            // Fallback to all content.
            return $this->get_all_content_items();
        }

        $body = wp_remote_retrieve_body($response);
        $urls = $this->parse_sitemap($body);

        foreach ($urls as $url) {
            $items[] = array('type' => 'sitemap_url', 'url' => $url);
        }

        return $items;
    }

    /**
     * Parse a sitemap XML and extract all URLs (including sub-sitemaps).
     */
    private function parse_sitemap($xml_string)
    {
        $urls = array();

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_string);
        if (!$xml) {
            return $urls;
        }

        // Register namespace.
        $xml->registerXPathNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        // Check if it's a sitemap index.
        $sitemaps = $xml->xpath('//sm:sitemap/sm:loc');
        if (!empty($sitemaps)) {
            foreach ($sitemaps as $sitemap_loc) {
                $sub_response = wp_remote_get((string) $sitemap_loc, array(
                    'timeout' => 15,
                ));
                if (!is_wp_error($sub_response)) {
                    $sub_body = wp_remote_retrieve_body($sub_response);
                    $sub_urls = $this->parse_sitemap($sub_body);
                    $urls = array_merge($urls, $sub_urls);
                }
            }
        }

        // Get individual URLs.
        $locs = $xml->xpath('//sm:url/sm:loc');
        if (!empty($locs)) {
            foreach ($locs as $loc) {
                $urls[] = (string) $loc;
            }
        }

        return $urls;
    }

    /**
     * Convert a URL from sitemap to markdown (via HTTP fetch).
     */
    private function convert_sitemap_url($url)
    {
        $converter = new MDP_Converter();

        // First, try to find a matching post/term/author.
        $post_id = url_to_postid($url);
        if ($post_id) {
            return $converter->convert_post($post_id);
        }

        // Check if it's the homepage.
        if (trailingslashit($url) === trailingslashit(home_url())) {
            return $converter->convert_homepage();
        }

        // For other URLs (archives, etc.), use HTTP fetch.
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'user-agent' => 'markdownpress/1.0',
            'headers' => array('Accept' => 'text/html'),
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return false;
        }

        // Extract content.
        $html = $body;
        if (preg_match('/<main[^>]*>(.*?)<\/main>/si', $body, $m)) {
            $html = $m[1];
        } elseif (preg_match('/<article[^>]*>(.*?)<\/article>/si', $body, $m)) {
            $html = $m[1];
        }

        $markdown = MDP_Html_To_Markdown::convert($html);

        $options = mdp_get_options();
        $fm = '';
        if ($options['frontmatter']) {
            // Extract title from HTML.
            $title = '';
            if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $body, $m)) {
                $title = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }
            $fm = "---\n";
            $fm .= 'title: "' . str_replace('"', '\\"', $title) . '"' . "\n";
            $fm .= 'url: "' . $url . '"' . "\n";
            $fm .= 'type: "sitemap_page"' . "\n";
            $fm .= "---\n\n";
        }

        $file_path = $converter->url_to_cache_path($url);
        $dir = dirname($file_path);
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        return (bool) file_put_contents($file_path, $fm . $markdown);
    }

    /**
     * Generate summary files: _all.md and _sitemap.md.
     */
    public function generate_summary_files()
    {
        $options = mdp_get_options();

        // _sitemap.md — list of all pages with titles and URLs.
        $sitemap_md = "# Sitemap — " . get_bloginfo('name') . "\n\n";
        $sitemap_md .= "Generated: " . current_time('Y-m-d H:i:s') . "\n\n";

        $files = $this->scan_cache_files(MDP_CACHE_DIR);
        sort($files);

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $rel_path = str_replace(MDP_CACHE_DIR, '', $file);
            $title = $this->extract_title_from_md($content);
            $url = $this->extract_url_from_md($content);
            $sitemap_md .= "- [{$title}]({$url}) — `{$rel_path}`\n";
        }

        file_put_contents(MDP_CACHE_DIR . '_sitemap.md', $sitemap_md);

        // _all.md — full content of all pages concatenated.
        if ($options['generate_all_md']) {
            $all_md = "# Complete Content — " . get_bloginfo('name') . "\n\n";
            $all_md .= "Generated: " . current_time('Y-m-d H:i:s') . "\n";
            $all_md .= "Total pages: " . count($files) . "\n\n";
            $all_md .= "---\n\n";

            foreach ($files as $file) {
                $content = file_get_contents($file);
                $rel_path = str_replace(MDP_CACHE_DIR, '', $file);
                $all_md .= "<!-- Source: {$rel_path} -->\n\n";
                $all_md .= $content . "\n\n---\n\n";
            }

            file_put_contents(MDP_CACHE_DIR . '_all.md', $all_md);
        }
    }

    /**
     * Recursively scan cache directory for .md files.
     */
    private function scan_cache_files($dir)
    {
        $files = array();
        $items = glob($dir . '*', GLOB_MARK);

        foreach ($items as $item) {
            if (substr($item, -1) === DIRECTORY_SEPARATOR || substr($item, -1) === '/') {
                $files = array_merge($files, $this->scan_cache_files($item));
            } elseif (preg_match('/\.md$/i', $item) && basename($item)[0] !== '_') {
                $files[] = $item;
            }
        }

        return $files;
    }

    /**
     * Extract title from markdown content (from frontmatter or first heading).
     */
    private function extract_title_from_md($content)
    {
        // From frontmatter.
        if (preg_match('/^---\s*\n.*?title:\s*"([^"]+)"/s', $content, $m)) {
            return $m[1];
        }
        // From first heading.
        if (preg_match('/^#\s+(.+)$/m', $content, $m)) {
            return trim($m[1]);
        }
        return 'Untitled';
    }

    /**
     * Extract URL from markdown frontmatter.
     */
    private function extract_url_from_md($content)
    {
        if (preg_match('/^---\s*\n.*?url:\s*"([^"]+)"/s', $content, $m)) {
            return $m[1];
        }
        return '#';
    }

    /**
     * Get current generation status from JSON file.
     */
    public static function get_status()
    {
        return self::read_json(self::get_status_file(), array(
            'total' => 0,
            'processed' => 0,
            'started' => null,
            'finished' => null,
            'errors' => 0,
        ));
    }

    /**
     * Check if generation queue has items.
     */
    public static function has_queue()
    {
        $file = self::get_queue_file();
        if (!file_exists($file)) {
            return false;
        }
        $queue = self::read_json($file, array());
        return !empty($queue);
    }

    /**
     * Get remaining queue count.
     */
    public static function get_queue_count()
    {
        $queue = self::read_json(self::get_queue_file(), array());
        return count($queue);
    }

    /**
     * Read a JSON file and decode it.
     *
     * @param  string $file    File path.
     * @param  mixed  $default Default value if file doesn't exist.
     * @return mixed
     */
    private static function read_json($file, $default = array())
    {
        if (!file_exists($file)) {
            return $default;
        }
        $content = file_get_contents($file);
        $data = json_decode($content, true);
        return ($data !== null) ? $data : $default;
    }

    /**
     * Clear the entire cache directory.
     */
    public static function clear_cache()
    {
        if (!file_exists(MDP_CACHE_DIR)) {
            return;
        }
        self::delete_directory_contents(MDP_CACHE_DIR);
    }

    /**
     * Delete all files in a directory (but keep the directory).
     */
    private static function delete_directory_contents($dir)
    {
        $items = glob($dir . '{,.}[!.,!..]*', GLOB_BRACE | GLOB_MARK);
        foreach ($items as $item) {
            if (basename($item) === '.htaccess') {
                continue;
            }
            if (is_dir($item)) {
                self::delete_directory_contents($item);
                @rmdir($item);
            } else {
                unlink($item);
            }
        }
    }
}
