<?php
/**
 * Generates and manages .htaccess rewrite rules for fast markdown serving.
 * When Apache mod_rewrite is available, markdown files are served directly
 * without bootstrapping WordPress/PHP at all (~2ms vs ~200ms).
 *
 * Falls back to PHP serving (MDP_Server) when mod_rewrite is not available.
 *
 * @package MarkdownPress
 */

if (!defined('ABSPATH')) {
    exit;
}

class MDP_Htaccess
{

    const MARKER = 'MarkdownPress';

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
        if (!file_exists($htaccess_file)) {
            return;
        }

        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if (!$wp_filesystem || !$wp_filesystem->is_writable($htaccess_file)) {
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
        if (is_multisite() && function_exists('get_sites')) {
            return self::generate_multisite_rules();
        }

        // Calculate the relative path from ABSPATH to the markdown files directory.
        $cache_rel = str_replace(ABSPATH, '', MDP_CACHE_DIR);
        $cache_rel = trim($cache_rel, '/');

        $rules = array();
        $rules[] = '<IfModule mod_rewrite.c>';
        $rules[] = 'RewriteEngine On';
        $rules = array_merge($rules, self::generate_charset_rules());
        $rules[] = '';

        // ─── llms.txt and llms-full.txt — always served directly ───
        $rules[] = '# Serve llms.txt directly';
        $rules[] = 'RewriteRule ^llms\.txt$ /' . $cache_rel . '/llms.txt [L,E=MDP:1,E=MDP_TEXT:1]';
        $rules[] = 'RewriteRule ^llms-full\.txt$ /' . $cache_rel . '/llms-full.txt [L,E=MDP:1,E=MDP_TEXT:1]';
        $rules[] = '';

        // ─── Accept: text/markdown header ───
        $rules[] = '# Serve markdown when Accept header contains text/markdown';
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} text/markdown [NC,OR]';
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} text/x-markdown [NC,OR]';
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} application/markdown [NC]';
        $rules[] = '# Check if cached markdown file exists for this URL';
        $rules[] = 'RewriteCond %{DOCUMENT_ROOT}/' . $cache_rel . '%{REQUEST_URI}index.md -f';
        $rules[] = 'RewriteRule ^(.*)$ /' . $cache_rel . '/$1index.md [L,E=MDP:1,E=MDP_MD:1]';
        $rules[] = '';

        // ─── Handle URLs without trailing slash ───
        $rules[] = '# Handle URLs without trailing slash';
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} text/markdown [NC,OR]';
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} text/x-markdown [NC,OR]';
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} application/markdown [NC]';

        $rules[] = 'RewriteCond %{DOCUMENT_ROOT}/' . $cache_rel . '%{REQUEST_URI}/index.md -f';
        $rules[] = 'RewriteRule ^(.*)$ /' . $cache_rel . '/$1/index.md [L,E=MDP:1,E=MDP_MD:1]';
        $rules[] = '';

        // ─── Homepage special case ───
        $rules[] = '# Homepage';
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} text/markdown [NC,OR]';
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} text/x-markdown [NC,OR]';
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} application/markdown [NC]';
        $rules[] = 'RewriteCond %{DOCUMENT_ROOT}/' . $cache_rel . '/index.md -f';
        $rules[] = 'RewriteRule ^$ /' . $cache_rel . '/index.md [L,E=MDP:1,E=MDP_MD:1]';
        $rules[] = '';

        // ─── Set proper headers for served files ───
        $rules[] = '# Set proper headers when serving markdown';
        $rules[] = '<IfModule mod_headers.c>';
        $rules[] = '    Header set Content-Type "text/markdown; charset=UTF-8" env=MDP_MD';
        $rules[] = '    Header set Content-Type "text/markdown; charset=UTF-8" env=REDIRECT_MDP_MD';
        $rules[] = '    Header set Content-Type "text/plain; charset=UTF-8" env=MDP_TEXT';
        $rules[] = '    Header set Content-Type "text/plain; charset=UTF-8" env=REDIRECT_MDP_TEXT';
        $rules[] = '    Header set X-Content-Source "markdownpress" env=MDP';
        $rules[] = '    Header set X-Content-Source "markdownpress" env=REDIRECT_MDP';
        $rules[] = '    Header set X-Robots-Tag "noindex" env=MDP';
        $rules[] = '    Header set X-Robots-Tag "noindex" env=REDIRECT_MDP';
        $rules[] = '    Header set Cache-Control "public, max-age=3600" env=MDP';
        $rules[] = '    Header set Cache-Control "public, max-age=3600" env=REDIRECT_MDP';
        $rules[] = '</IfModule>';

        $rules[] = '</IfModule>';

        return $rules;
    }

    /**
     * Generate host-aware rewrite rules for all public sites in a multisite network.
     */
    private static function generate_multisite_rules()
    {
        $rules = array();
        $rules[] = '<IfModule mod_rewrite.c>';
        $rules[] = 'RewriteEngine On';
        $rules = array_merge($rules, self::generate_charset_rules());
        $rules[] = '';

        $sites = get_sites(array(
            'number' => 0,
            'fields' => 'ids',
            'deleted' => 0,
            'archived' => 0,
            'spam' => 0,
        ));

        foreach ($sites as $blog_id) {
            $site = self::get_multisite_rule_site((int) $blog_id);
            if (!$site) {
                continue;
            }
            $rules = array_merge($rules, self::generate_multisite_site_rules($site));
        }

        $rules[] = '# Set proper headers when serving markdown';
        $rules[] = '<IfModule mod_headers.c>';
        $rules[] = '    Header set Content-Type "text/markdown; charset=UTF-8" env=MDP_MD';
        $rules[] = '    Header set Content-Type "text/markdown; charset=UTF-8" env=REDIRECT_MDP_MD';
        $rules[] = '    Header set Content-Type "text/plain; charset=UTF-8" env=MDP_TEXT';
        $rules[] = '    Header set Content-Type "text/plain; charset=UTF-8" env=REDIRECT_MDP_TEXT';
        $rules[] = '    Header set X-Content-Source "markdownpress" env=MDP';
        $rules[] = '    Header set X-Content-Source "markdownpress" env=REDIRECT_MDP';
        $rules[] = '    Header set X-Robots-Tag "noindex" env=MDP';
        $rules[] = '    Header set X-Robots-Tag "noindex" env=REDIRECT_MDP';
        $rules[] = '    Header set Cache-Control "public, max-age=3600" env=MDP';
        $rules[] = '    Header set Cache-Control "public, max-age=3600" env=REDIRECT_MDP';
        $rules[] = '</IfModule>';

        $rules[] = '</IfModule>';

        return $rules;
    }

    /**
     * Make static markdown/plain files advertise UTF-8 even when mod_headers is unavailable.
     */
    private static function generate_charset_rules()
    {
        return array(
            '<IfModule mod_mime.c>',
            '    AddType "text/markdown; charset=UTF-8" .md',
            '    AddType "text/plain; charset=UTF-8" .txt',
            '</IfModule>',
        );
    }

    /**
     * Build rewrite metadata for one multisite blog.
     */
    private static function get_multisite_rule_site($blog_id)
    {
        $details = get_blog_details($blog_id);
        if (!$details || empty($details->domain)) {
            return null;
        }

        $cache_dir = function_exists('mdp_get_default_cache_dir') ? mdp_get_default_cache_dir($blog_id) : MDP_CACHE_DIR;
        $cache_dir = apply_filters('mdp_cache_dir_for_site', $cache_dir, $blog_id);
        $path = isset($details->path) ? $details->path : '/';

        return array(
            'label' => $details->domain . $path,
            'host_regex' => '^' . preg_quote($details->domain, '#') . '$',
            'cache_rel' => self::cache_dir_to_relative_path($cache_dir),
            'path_prefix' => trim($path, '/'),
            'home_rule' => self::home_rule_from_path($path),
        );
    }

    /**
     * Generate host-aware rewrite rules for one multisite blog.
     */
    private static function generate_multisite_site_rules($site)
    {
        $host_cond = 'RewriteCond %{HTTP_HOST} ' . $site['host_regex'] . ' [NC]';
        $cache_rel = $site['cache_rel'];
        $llms_rule = self::site_file_rule($site['path_prefix'], 'llms\.txt');
        $llms_full_rule = self::site_file_rule($site['path_prefix'], 'llms-full\.txt');
        $rules = array();

        $rules[] = '# Site: ' . $site['label'];
        $rules[] = '# Serve llms.txt directly';
        $rules[] = $host_cond;
        $rules[] = 'RewriteCond %{DOCUMENT_ROOT}/' . $cache_rel . '/llms.txt -f';
        $rules[] = 'RewriteRule ' . $llms_rule . ' /' . $cache_rel . '/llms.txt [L,E=MDP:1,E=MDP_TEXT:1]';
        $rules[] = $host_cond;
        $rules[] = 'RewriteCond %{DOCUMENT_ROOT}/' . $cache_rel . '/llms-full.txt -f';
        $rules[] = 'RewriteRule ' . $llms_full_rule . ' /' . $cache_rel . '/llms-full.txt [L,E=MDP:1,E=MDP_TEXT:1]';
        $rules[] = '';

        $rules[] = '# Serve markdown when Accept header contains text/markdown';
        $rules[] = $host_cond;
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} text/markdown [NC,OR]';
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} text/x-markdown [NC,OR]';
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} application/markdown [NC]';
        $rules[] = 'RewriteCond %{DOCUMENT_ROOT}/' . $cache_rel . '%{REQUEST_URI}index.md -f';
        $rules[] = 'RewriteRule ^(.*)$ /' . $cache_rel . '/$1index.md [L,E=MDP:1,E=MDP_MD:1]';
        $rules[] = '';

        $rules[] = '# Handle URLs without trailing slash';
        $rules[] = $host_cond;
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} text/markdown [NC,OR]';
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} text/x-markdown [NC,OR]';
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} application/markdown [NC]';
        $rules[] = 'RewriteCond %{DOCUMENT_ROOT}/' . $cache_rel . '%{REQUEST_URI}/index.md -f';
        $rules[] = 'RewriteRule ^(.*)$ /' . $cache_rel . '/$1/index.md [L,E=MDP:1,E=MDP_MD:1]';
        $rules[] = '';

        $rules[] = '# Homepage';
        $rules[] = $host_cond;
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} text/markdown [NC,OR]';
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} text/x-markdown [NC,OR]';
        $rules[] = 'RewriteCond %{HTTP_ACCEPT} application/markdown [NC]';
        $rules[] = 'RewriteCond %{DOCUMENT_ROOT}/' . $cache_rel . '/index.md -f';
        $rules[] = 'RewriteRule ' . $site['home_rule'] . ' /' . $cache_rel . '/index.md [L,E=MDP:1,E=MDP_MD:1]';
        $rules[] = '';

        return $rules;
    }

    /**
     * Convert an absolute cache directory to a document-root relative path.
     */
    private static function cache_dir_to_relative_path($cache_dir)
    {
        $cache_dir = wp_normalize_path($cache_dir);
        $abspath = wp_normalize_path(ABSPATH);

        if (strpos($cache_dir, $abspath) === 0) {
            $cache_dir = substr($cache_dir, strlen($abspath));
        }

        return trim($cache_dir, '/');
    }

    /**
     * Build a rewrite pattern for the site's homepage.
     */
    private static function home_rule_from_path($path)
    {
        $path = trim($path, '/');
        if ($path === '') {
            return '^$';
        }

        return '^' . preg_quote($path, '#') . '/?$';
    }

    /**
     * Build a rewrite pattern for a site-root file such as llms.txt.
     */
    private static function site_file_rule($path_prefix, $file_pattern)
    {
        $path_prefix = trim($path_prefix, '/');
        if ($path_prefix === '') {
            return '^' . $file_pattern . '$';
        }

        return '^' . preg_quote($path_prefix, '#') . '/' . $file_pattern . '$';
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

        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if (!$wp_filesystem || !$wp_filesystem->is_writable($htaccess_file)) {
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
        $server_software = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : '';
        $server = strtolower($server_software);
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
        $cache_rel = str_replace(ABSPATH, '', MDP_CACHE_DIR);
        $cache_rel = '/' . trim($cache_rel, '/');

        $config = "# MarkdownPress — Nginx config\n";
        $config .= "# Add this to your server {} block\n\n";
        $config .= "# Serve llms.txt\n";
        $config .= "location = /llms.txt {\n";
        $config .= "    alias " . $cache_rel . "/llms.txt;\n";
        $config .= "    default_type text/plain;\n";
        $config .= "    charset utf-8;\n";
        $config .= "}\n\n";
        $config .= "location = /llms-full.txt {\n";
        $config .= "    alias " . $cache_rel . "/llms-full.txt;\n";
        $config .= "    default_type text/plain;\n";
        $config .= "    charset utf-8;\n";
        $config .= "}\n\n";
        $config .= "# ?format=markdown and Accept: text/markdown are handled by the PHP fallback.\n";
        $config .= "# No extra Nginx rewrite rule is required for Markdown pages.\n";

        return $config;
    }
}
