<?php
/**
 * Serves cached markdown content when the client sends
 * Accept: text/markdown (or equivalent) in the HTTP headers.
 *
 * Also serves llms.txt at /llms.txt.
 *
 * Runs at 'init' priority 1, after WordPress rewrite globals are available.
 *
 * @package MarkdownPress
 */

if (!defined('ABSPATH')) {
    exit;
}

class MDP_Server
{

    /**
     * Initialize early — check Accept header before WP does heavy lifting.
     */
    public static function init()
    {
        $options = mdp_get_options();
        if (!$options['enabled']) {
            return;
        }

        // Serve llms.txt at /llms.txt (and /llms-full.txt).
        self::maybe_serve_llms_txt();

        // Check Accept header for markdown requests.
        self::maybe_serve_markdown();
    }

    /**
     * Check if the current request wants markdown via Accept header
     * or via ?format=markdown query parameter.
     */
    private static function maybe_serve_markdown()
    {
        $wants_markdown = false;

        // Check Accept header.
        $http_accept = isset($_SERVER['HTTP_ACCEPT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT'])) : '';
        if ($http_accept) {
            $accept = strtolower($http_accept);
            if (
                strpos($accept, 'text/markdown') !== false ||
                strpos($accept, 'text/x-markdown') !== false ||
                strpos($accept, 'application/markdown') !== false
            ) {
                $wants_markdown = true;
            }
        }

        // Also support ?format=markdown query parameter.
        if (isset($_GET['format']) && 'markdown' === $_GET['format']) {
            $wants_markdown = true;
        }

        if (!$wants_markdown) {
            return;
        }

        // Determine file path from current URL.
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        // Remove query string.
        $path = strtok($request_uri, '?');
        $path = trim($path, '/');

        if (empty($path)) {
            $file = MDP_CACHE_DIR . 'index.md';
        } else {
            // Remove file extension if present.
            $path = preg_replace('/\.(html?|php)$/i', '', $path);
            $file = MDP_CACHE_DIR . $path . '/index.md';
        }

        // Also try without trailing /index.md (for direct .md file paths).
        if (!file_exists($file)) {
            $file = MDP_CACHE_DIR . $path . '.md';
        }

        if (!file_exists($file)) {
            // Try serving special files like _sitemap.md or _all.md.
            if ($path === '_sitemap' || strpos($path, '/_sitemap') !== false) {
                $file = MDP_CACHE_DIR . '_sitemap.md';
            } elseif ($path === '_all' || strpos($path, '/_all') !== false) {
                $file = MDP_CACHE_DIR . '_all.md';
            }
        }

        if (!file_exists($file)) {
            $generated_file = self::generate_markdown_for_current_request();
            if ($generated_file && file_exists($generated_file)) {
                self::send_markdown_response($generated_file);
            }

            // No cached version available and couldn't generate.
            // Since the user EXPLICITLY asked for markdown (Accept header or ?format=markdown),
            // and we cannot provide it, we should probably return a 404 or a message, 
            // instead of falling through to HTML which is confusing.
            status_header(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "Error: Markdown version not available for this URL.";
            exit;
        }

        // Serve the markdown file.
        self::send_markdown_response($file);
    }

    /**
     * Try to generate missing markdown for the current request.
     *
     * @return string Empty string on failure, generated markdown file path on success.
     */
    private static function generate_markdown_for_current_request()
    {
        $url = self::get_current_request_url();
        if (!$url) {
            return '';
        }

        // Never recurse back into the markdown endpoint during HTTP fallback.
        $url = remove_query_arg('format', $url);
        $converter = new MDP_Converter();

        $post_id = self::safe_url_to_postid($url);
        if ($post_id && $converter->convert_post($post_id)) {
            $file = $converter->url_to_cache_path(get_permalink($post_id));
            if (file_exists($file)) {
                return $file;
            }
        }

        if (trailingslashit($url) === trailingslashit(home_url('/')) && $converter->convert_homepage()) {
            $file = MDP_CACHE_DIR . 'index.md';
            if (file_exists($file)) {
                return $file;
            }
        }

        $options = mdp_get_options();
        if (!empty($options['include_taxonomies'])) {
            $term = self::find_term_by_url($url);
            if ($term && $converter->convert_term($term->term_id, $term->taxonomy)) {
                $term_link = get_term_link($term);
                if (!is_wp_error($term_link)) {
                    $file = $converter->url_to_cache_path($term_link);
                    if (file_exists($file)) {
                        return $file;
                    }
                }
            }
        }

        return self::generate_generic_markdown_for_url($url, $converter);
    }

    /**
     * Build the current absolute URL from server globals.
     */
    private static function get_current_request_url()
    {
        $http_host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';

        if (!$http_host || !$request_uri) {
            return '';
        }

        return (is_ssl() ? 'https://' : 'http://') . $http_host . $request_uri;
    }

    /**
     * Resolve a URL to a post ID without letting early rewrite state cause a fatal error.
     */
    private static function safe_url_to_postid($url)
    {
        global $wp_rewrite;

        if (!function_exists('url_to_postid') || !is_object($wp_rewrite) || !method_exists($wp_rewrite, 'wp_rewrite_rules')) {
            return 0;
        }

        try {
            return (int) url_to_postid($url);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Find a public taxonomy term whose archive URL matches the request URL.
     */
    private static function find_term_by_url($url)
    {
        $taxonomies = get_taxonomies(array('public' => true), 'names');
        if (empty($taxonomies)) {
            return null;
        }

        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms(array(
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
            ));

            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                $term_link = get_term_link($term);
                if (!is_wp_error($term_link) && self::urls_match($term_link, $url)) {
                    return $term;
                }
            }
        }

        return null;
    }

    /**
     * Fetch and cache non-post URLs such as archives that do not resolve to a post ID.
     */
    private static function generate_generic_markdown_for_url($url, MDP_Converter $converter)
    {
        $html = $converter->fetch_content_via_http($url);
        if (empty($html)) {
            return '';
        }

        $markdown = MDP_Html_To_Markdown::convert($html);
        if (empty(trim($markdown))) {
            return '';
        }

        $options = mdp_get_options();
        $content = '';
        if (!empty($options['frontmatter'])) {
            $content .= "---\n";
            $content .= 'title: "' . self::fallback_title_from_url($url) . '"' . "\n";
            $content .= 'url: "' . esc_url_raw($url) . '"' . "\n";
            $content .= 'type: "archive"' . "\n";
            $content .= "---\n\n";
        }
        $content .= $markdown;

        $file = $converter->url_to_cache_path($url);
        $dir = dirname($file);
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        return file_put_contents($file, $content) ? $file : '';
    }

    /**
     * Compare URLs by host and path, ignoring query strings and trailing slashes.
     */
    private static function urls_match($a, $b)
    {
        $a_parts = wp_parse_url($a);
        $b_parts = wp_parse_url($b);

        $a_host = isset($a_parts['host']) ? strtolower($a_parts['host']) : '';
        $b_host = isset($b_parts['host']) ? strtolower($b_parts['host']) : '';
        $a_path = isset($a_parts['path']) ? untrailingslashit($a_parts['path']) : '';
        $b_path = isset($b_parts['path']) ? untrailingslashit($b_parts['path']) : '';

        return $a_host === $b_host && $a_path === $b_path;
    }

    /**
     * Create a readable fallback title for generic archive markdown.
     */
    private static function fallback_title_from_url($url)
    {
        $parsed = wp_parse_url($url);
        $path = isset($parsed['path']) ? trim($parsed['path'], '/') : '';
        $slug = $path ? basename($path) : get_bloginfo('name');

        $title = ucwords(str_replace(array('-', '_'), ' ', $slug));
        $title = wp_strip_all_tags($title);

        return trim(str_replace(array('"', "\n", "\r"), array('\\"', ' ', ''), $title));
    }

    /**
     * Serve llms.txt or llms-full.txt.
     */
    private static function maybe_serve_llms_txt()
    {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $path = strtok($request_uri, '?');
        $path = trim($path, '/');

        if ($path === 'llms.txt') {
            $file = MDP_CACHE_DIR . 'llms.txt';
            if (file_exists($file)) {
                header('Content-Type: text/plain; charset=UTF-8');
                header('X-Robots-Tag: noindex');
                header('Cache-Control: public, max-age=3600');
                readfile($file);
                exit;
            }
        }

        if ($path === 'llms-full.txt') {
            $file = MDP_CACHE_DIR . 'llms-full.txt';
            if (file_exists($file)) {
                header('Content-Type: text/plain; charset=UTF-8');
                header('X-Robots-Tag: noindex');
                header('Cache-Control: public, max-age=3600');
                readfile($file);
                exit;
            }
        }
    }

    /**
     * Send a markdown response and exit.
     */
    private static function send_markdown_response($file)
    {
        $content = file_get_contents($file);
        $modified = filemtime($file);

        // Handle If-Modified-Since for caching.
        $if_modified_since = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_IF_MODIFIED_SINCE'])) : '';
        if ($if_modified_since) {
            $since = strtotime($if_modified_since);
            if ($since >= $modified) {
                header('HTTP/1.1 304 Not Modified');
                exit;
            }
        }

        header('Content-Type: text/markdown; charset=UTF-8');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
        header('Cache-Control: public, max-age=3600');
        header('X-Content-Source: markdownpress');
        header('Content-Length: ' . strlen($content));
        header('X-Robots-Tag: noindex');

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $content;
        exit;
    }
}
