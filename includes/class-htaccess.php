<?php
/**
 * Generates and manages .htaccess rewrite rules for fast markdown serving.
 * When Apache mod_rewrite is available, markdown files are served directly
 * without bootstrapping WordPress/PHP at all (~2ms vs ~200ms).
 *
 * Falls back to PHP serving (WPMC_Server) when mod_rewrite is not available.
 *
 * @package WP_Markdown_Cache
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPMC_Htaccess
{

    const MARKER = 'WP Markdown Cache';

    /**
     * Generate and insert .htaccess rules.
     * Should be called on activation and when settings are saved.
     */
    public static function add_rules()
    {
        // Only works on Apache.
        if (!self::is_apache()) {
            return false;
        }

        $rules = self::generate_rules();
        return self::insert_rules($rules);
    }

    /**
     * Remove .htaccess rules.
     * Should be called on deactivation.
     */
    public static function remove_rules()
    {
        if (!self::is_apache()) {
            return;
        }

        $htaccess_file = self::get_htaccess_path();
        if (!file_exists($htaccess_file) || !is_writable($htaccess_file)) {
            return;
        }

        $content = file_get_contents($htaccess_file);
        $content = self::remove_section($content);
        file_put_contents($htaccess_file, $content);
    }

    /**
     * Generate the rewrite rules.
     */
    private static function generate_rules()
    {
        // Calculate the relative path from ABSPATH to the cache directory.
        $cache_rel = str_replace(ABSPATH, '', WPMC_CACHE_DIR);
        $cache_rel = trim($cache_rel, '/');

        $rules = array();
        $rules[] = '<IfModule mod_rewrite.c>';
        $rules[] = 'RewriteEngine On';
        $rules[] = '';

        // ─── llms.txt and llms-full.txt — always served directly ───
        $rules[] = '# Serve llms.txt directly';
        $rules[] = 'RewriteRule ^llms\.txt$ /' . $cache_rel . '/llms.txt [L,T=text/plain]';
        $rules[] = 'RewriteRule ^llms-full\.txt$ /' . $cache_rel . '/llms-full.txt [L,T=text/plain]';
        $rules[] = '';

        // ─── Accept: text/markdown header ───
        $rules[] = '# Serve markdown when Accept header contains text/markdown';
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} text/markdown [NC,OR]';
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} text/x-markdown [NC,OR]';
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} application/markdown [NC]';
        $rules[] = '# Check if cached markdown file exists for this URL';
        $rules[] = 'RewriteCond %{DOCUMENT_ROOT}/' . $cache_rel . '%{REQUEST_URI}index.md -f';
        $rules[] = 'RewriteRule ^(.*)$ /' . $cache_rel . '/$1index.md [L,T=text/markdown,E=WPMC:1]';
        $rules[] = '';

        // ─── Handle URLs without trailing slash ───
        $rules[] = '# Handle URLs without trailing slash';
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} text/markdown [NC,OR]';
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} text/x-markdown [NC,OR]';
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} application/markdown [NC]';
        $rules[] = 'RewriteCond %{DOCUMENT_ROOT}/' . $cache_rel . '%{REQUEST_URI}/index.md -f';
        $rules[] = 'RewriteRule ^(.*)$ /' . $cache_rel . '/$1/index.md [L,T=text/markdown,E=WPMC:1]';
        $rules[] = '';

        // ─── ?format=markdown query parameter ───
        $rules[] = '# Serve markdown when ?format=markdown is in the query string';
        $rules[] = 'RewriteCond %{QUERY_STRING} (^|&)format=markdown($|&) [NC]';
        $rules[] = 'RewriteCond %{DOCUMENT_ROOT}/' . $cache_rel . '%{REQUEST_URI}index.md -f';
        $rules[] = 'RewriteRule ^(.*)$ /' . $cache_rel . '/$1index.md [L,T=text/markdown,E=WPMC:1]';
        $rules[] = '';

        // ─── ?format=markdown without trailing slash ───
        $rules[] = 'RewriteCond %{QUERY_STRING} (^|&)format=markdown($|&) [NC]';
        $rules[] = 'RewriteCond %{DOCUMENT_ROOT}/' . $cache_rel . '%{REQUEST_URI}/index.md -f';
        $rules[] = 'RewriteRule ^(.*)$ /' . $cache_rel . '/$1/index.md [L,T=text/markdown,E=WPMC:1]';
        $rules[] = '';

        // ─── Homepage special case ───
        $rules[] = '# Homepage';
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} text/markdown [NC,OR]';
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} text/x-markdown [NC,OR]';
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} application/markdown [NC,OR]';
        $rules[] = 'RewriteCond %{QUERY_STRING} (^|&)format=markdown($|&) [NC]';
        $rules[] = 'RewriteCond %{DOCUMENT_ROOT}/' . $cache_rel . '/index.md -f';
        $rules[] = 'RewriteRule ^$ /' . $cache_rel . '/index.md [L,T=text/markdown,E=WPMC:1]';
        $rules[] = '';

        // ─── Set proper headers for served files ───
        $rules[] = '# Set proper headers when serving markdown';
        $rules[] = '<IfModule mod_headers.c>';
        $rules[] = '    Header set X-Content-Source "wp-markdown-cache" env=WPMC';
        $rules[] = '    Header set X-Robots-Tag "noindex" env=WPMC';
        $rules[] = '    Header set Cache-Control "public, max-age=3600" env=WPMC';
        $rules[] = '</IfModule>';

        $rules[] = '</IfModule>';

        return $rules;
    }

    /**
     * Insert rules into the .htaccess file, BEFORE the WordPress block.
     */
    private static function insert_rules($rules)
    {
        $htaccess_file = self::get_htaccess_path();

        if (!file_exists($htaccess_file)) {
            // No .htaccess — can't insert.
            return false;
        }

        if (!is_writable($htaccess_file)) {
            return false;
        }

        $content = file_get_contents($htaccess_file);

        // Remove existing section first.
        $content = self::remove_section($content);

        // Build new section.
        $section = '# BEGIN ' . self::MARKER . "\n";
        $section .= implode("\n", $rules) . "\n";
        $section .= '# END ' . self::MARKER . "\n\n";

        // Insert BEFORE the WordPress block.
        $wp_marker = '# BEGIN WordPress';
        $pos = strpos($content, $wp_marker);
        if ($pos !== false) {
            $content = substr($content, 0, $pos) . $section . substr($content, $pos);
        } else {
            // No WordPress block found — just prepend.
            $content = $section . $content;
        }

        return (bool) file_put_contents($htaccess_file, $content);
    }

    /**
     * Remove our section from .htaccess content.
     */
    private static function remove_section($content)
    {
        $pattern = '/# BEGIN ' . preg_quote(self::MARKER, '/') . '.*?# END ' . preg_quote(self::MARKER, '/') . '\s*/s';
        return preg_replace($pattern, '', $content);
    }

    /**
     * Get .htaccess file path.
     */
    private static function get_htaccess_path()
    {
        return ABSPATH . '.htaccess';
    }

    /**
     * Check if running on Apache.
     */
    private static function is_apache()
    {
        if (function_exists('apache_get_version')) {
            return true;
        }
        $server = isset($_SERVER['SERVER_SOFTWARE']) ? strtolower($_SERVER['SERVER_SOFTWARE']) : '';
        return strpos($server, 'apache') !== false || strpos($server, 'litespeed') !== false;
    }

    /**
     * Check if our rules are currently active in .htaccess.
     *
     * @return bool
     */
    public static function has_rules()
    {
        $htaccess_file = self::get_htaccess_path();
        if (!file_exists($htaccess_file)) {
            return false;
        }
        $content = file_get_contents($htaccess_file);
        return strpos($content, '# BEGIN ' . self::MARKER) !== false;
    }

    /**
     * Generate Nginx config snippet (for display in admin, not auto-applied).
     */
    public static function get_nginx_config()
    {
        $cache_rel = str_replace(ABSPATH, '', WPMC_CACHE_DIR);
        $cache_rel = '/' . trim($cache_rel, '/');

        $config = <<<NGINX
# WP Markdown Cache — Nginx config
# Add this to your server {} block

# Serve llms.txt
location = /llms.txt {
    alias {$cache_rel}/llms.txt;
    default_type text/plain;
}

location = /llms-full.txt {
    alias {$cache_rel}/llms-full.txt;
    default_type text/plain;
}

# Serve markdown when Accept header or ?format=markdown
location / {
    # Check for ?format=markdown
    if (\$arg_format = "markdown") {
        set \$wpmc_serve 1;
    }

    # Check Accept header
    if (\$http_accept ~* "text/markdown|text/x-markdown|application/markdown") {
        set \$wpmc_serve 1;
    }

    # Try to serve cached markdown
    if (\$wpmc_serve = 1) {
        rewrite ^(.*)/\$ {$cache_rel}\$1/index.md break;
        rewrite ^(.*)\$ {$cache_rel}\$1/index.md break;
    }
}
NGINX;
        return $config;
    }
}
