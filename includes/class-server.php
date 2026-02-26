<?php
/**
 * Serves cached markdown content when the client sends
 * Accept: text/markdown (or equivalent) in the HTTP headers.
 *
 * Also serves llms.txt at /llms.txt.
 *
 * Runs at 'plugins_loaded' priority 1 for maximum performance.
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
            // On-the-fly generation: Try to resolve URL to a post.
            $http_host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
            $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            $full_url = (is_ssl() ? 'https://' : 'http://') . $http_host . $request_uri;
            $post_id = url_to_postid($full_url);

            if ($post_id) {
                // Ensure MDP_Converter is initialized with necessary includes if needed (though already done in markdownpress.php)
                $converter = new MDP_Converter();
                if ($converter->convert_post($post_id)) {
                    // Re-calculate file path using the same logic as converter to be 100% sure
                    $permalink = get_permalink($post_id);
                    $file = $converter->url_to_cache_path($permalink);

                    if (file_exists($file)) {
                        self::send_markdown_response($file);
                    }
                }
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
