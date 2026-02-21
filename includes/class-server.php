<?php
/**
 * Serves cached markdown content when the client sends
 * Accept: text/markdown (or equivalent) in the HTTP headers.
 *
 * Also serves llms.txt at /llms.txt.
 *
 * Runs at 'plugins_loaded' priority 1 for maximum performance.
 *
 * @package WP_Markdown_Cache
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPMC_Server
{

    /**
     * Initialize early — check Accept header before WP does heavy lifting.
     */
    public static function init()
    {
        $options = wpmc_get_options();
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
        if (isset($_SERVER['HTTP_ACCEPT'])) {
            $accept = strtolower($_SERVER['HTTP_ACCEPT']);
            if (
                strpos($accept, 'text/markdown') !== false ||
                strpos($accept, 'text/x-markdown') !== false ||
                strpos($accept, 'application/markdown') !== false
            ) {
                $wants_markdown = true;
            }
        }

        // Also support ?format=markdown query parameter.
        if (isset($_GET['format']) && $_GET['format'] === 'markdown') {
            $wants_markdown = true;
        }

        if (!$wants_markdown) {
            return;
        }

        // Determine file path from current URL.
        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        // Remove query string.
        $path = strtok($request_uri, '?');
        $path = trim($path, '/');

        if (empty($path)) {
            $file = WPMC_CACHE_DIR . 'index.md';
        } else {
            // Remove file extension if present.
            $path = preg_replace('/\.(html?|php)$/i', '', $path);
            $file = WPMC_CACHE_DIR . $path . '/index.md';
        }

        // Also try without trailing /index.md (for direct .md file paths).
        if (!file_exists($file)) {
            $file = WPMC_CACHE_DIR . $path . '.md';
        }

        // Try serving special files like _sitemap.md or _all.md.
        if (!file_exists($file)) {
            if ($path === '_sitemap' || $path === 'wp-markdown/_sitemap') {
                $file = WPMC_CACHE_DIR . '_sitemap.md';
            } elseif ($path === '_all' || $path === 'wp-markdown/_all') {
                $file = WPMC_CACHE_DIR . '_all.md';
            }
        }

        if (!file_exists($file)) {
            // No cached version available — let WP handle it normally.
            return;
        }

        // Serve the markdown file.
        self::send_markdown_response($file);
    }

    /**
     * Serve llms.txt or llms-full.txt.
     */
    private static function maybe_serve_llms_txt()
    {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $path = strtok($request_uri, '?');
        $path = trim($path, '/');

        if ($path === 'llms.txt') {
            $file = WPMC_CACHE_DIR . 'llms.txt';
            if (file_exists($file)) {
                header('Content-Type: text/plain; charset=UTF-8');
                header('X-Robots-Tag: noindex');
                header('Cache-Control: public, max-age=3600');
                readfile($file);
                exit;
            }
        }

        if ($path === 'llms-full.txt') {
            $file = WPMC_CACHE_DIR . 'llms-full.txt';
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
        if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            $since = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
            if ($since >= $modified) {
                header('HTTP/1.1 304 Not Modified');
                exit;
            }
        }

        header('Content-Type: text/markdown; charset=UTF-8');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
        header('Cache-Control: public, max-age=3600');
        header('X-Content-Source: wp-markdown-cache');
        header('Content-Length: ' . strlen($content));
        header('X-Robots-Tag: noindex');

        echo $content;
        exit;
    }
}
