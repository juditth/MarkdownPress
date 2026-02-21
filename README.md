# MarkdownPress

Created by Jitka Klingenbergová

MarkdownPress automatically generates Markdown versions of all your WordPress content and serves them to AI crawlers and LLM tools.

### Plugin Details
- **Tested up to:** 6.7
- **Stable tag:** 1.2.5
- **License:** GPLv2 or later
- **License URI:** https://www.gnu.org/licenses/gpl-2.0.html

## Features

- **Converts** all published posts, pages, and custom post types to clean Markdown.
- **Smart Serving**: Detects `Accept: text/markdown` header and serves MD files automatically.
- **LLM Ready**: Generates `llms.txt` and `llms-full.txt` (following the /llms.txt standard).
- **Fast Response**: Uses `.htaccess` rewrite rules for ultra-fast direct serving (~2ms).
- **Page Builder Support**: Special handling for Bricks, Elementor, and other builders using HTTP fallback rendering.
- **Batch Processing**: Generates content in small batches via Cron to prevent server overload.
- **SEO & Robot friendly**: Adds `X-Robots-Tag: noindex` to all Markdown files.
- **Auto-Update**: Built-in support for self-hosted updates via GitHub.

## Technical Details

### Database / Storage
MarkdownPress **does not use any custom database tables**. All data is stored in:
1.  **WP Options**: Plugin settings are stored in a single `mdp_options` record.
2.  **Filesystem**: Generated Markdown files, queue state, and status are stored in `wp-content/markdown-cache/`.

### Update System
This plugin includes an integrated update checker that connects to GitHub for updates. You don't need to manually upload new versions.

### Performance
The plugin is designed to be invisible to your human visitors.
- Generation happens in the background via WordPress Cron.
- Serving is handled by Apache (`.htaccess`) where possible, bypassing PHP entirely.

## Installation

1. Upload the plugin to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to **Settings > MarkdownPress** to configure your preferences.
4. Click **Generate Now** to build your first markdown mirror.

## Serving Markdown

You can access the markdown version of any page by either:
- Sending an HTTP header `Accept: text/markdown`.
- Adding `?format=markdown` to the URL.

Example: `curl -H "Accept: text/markdown" https://your-site.com/about/`

## Changelog

= 1.2.5 =
* Fixed security issues (escaping, sanitization).
* Replaced direct PHP filesystem calls with WordPress alternatives.
* Consistent Text Domain usage.
* Added nonce verification for downloads.

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
