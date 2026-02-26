=== MarkdownPress ===
Contributors: juditth
Tags: markdown, ai, llms, sitemap, bricks, elementor
Requires at least: 5.7
Tested up to: 6.7
Stable tag: 1.2.7
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Creates a clean Markdown mirror of your WordPress site for AI crawlers and LLM tools.

== Description ==

MarkdownPress automatically generates Markdown versions of all your WordPress content and serves them to AI crawlers and LLM tools.

= Features =

* Converts all published posts, pages, and custom post types to clean Markdown.
* Smart Serving: Detects Accept: text/markdown header and serves MD files automatically.
* LLM Ready: Generates llms.txt and llms-full.txt (following the /llms.txt standard).
* Fast Response: Uses .htaccess rewrite rules for ultra-fast direct serving (~2ms).
* Page Builder Support: Special handling for Bricks, Elementor, and other builders using HTTP fallback rendering.
* Batch Processing: Generates content in small batches via Cron to prevent server overload.
* SEO & Robot friendly: Adds X-Robots-Tag: noindex to all Markdown files.
* Auto-Update: Built-in support for self-hosted updates.

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to **Settings > MarkdownPress** to configure your preferences.
4. Click **Generate Now** to build your first markdown mirror.

== Changelog ==

= 1.2.7 =
* Added integrated Error Log viewer to the admin dashboard.
* Added "Refresh" and "Clear logs" actions directly in the UI.
* Fixed progress bar visibility issues.

= 1.2.6 =
* Fixed PHP 8.4 fatal error (moved initialization to 'init' hook).
* Improved content extraction for WooCommerce and Bricks Builder.
* Added support for on-the-fly markdown generation for missed URLs.
* Fixed .htaccess path resolution and added detailed error logging.
* Updated settings UI with clearer descriptions for rendering methods.

= 1.2.5 =
* Fixed security issues (escaping, sanitization).
* Replaced direct PHP filesystem calls with WordPress alternatives.
* Consistent Text Domain usage.
* Added nonce verification for downloads.
* Removed duplicate settings link.

= 1.2.4 =
* Forced absolute URLs for all images in Markdown output for better AI compatibility.

= 1.2.3 =
* Added "Stop Generation" button to halt active processing.
* Fixed button icon alignment in the admin interface.

= 1.2.2 =
* Added error logging system.
* Support for ZIP download of all Markdown files.
* UI improvements for the status dashboard.

= 1.0.0 =
* Initial release.
