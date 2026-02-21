=== MarkdownPress ===
Contributors: Jitka Klingenbergová
Tags: markdown, ai, llm, cache, content
Requires at least: 5.5
Tested up to: 6.7
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPLv2 or later

Generates a MarkdownPress of your entire WordPress site for AI/LLM consumption. Serves content via Accept: text/markdown header. Generates llms.txt.

== Description ==

MarkdownPress automatically generates Markdown versions of all your WordPress content and serves them to AI crawlers and LLM tools.

**Features:**

* Converts all published posts, pages, and custom post types to clean Markdown
* Supports taxonomy archives, author pages, and the homepage
* Works with any page builder (Gutenberg, Elementor, Bricks, WPBakery, Divi, etc.)
* Generates `llms.txt` and `llms-full.txt` — the "robots.txt for AI"
* Serves markdown instantly via `Accept: text/markdown` HTTP header
* Also supports `?format=markdown` query parameter
* Smart batch processing to prevent server overload
* Event-driven updates: regenerates markdown when posts are saved
* Configurable cron schedule, batch size, and delay
* Admin dashboard with cache stats, progress bar, and one-click actions
* YAML frontmatter with title, URL, dates, categories, tags, featured image
* URL exclusion list for sensitive pages
* Generates `_all.md` (complete site content in one file) and `_sitemap.md`

**How it works:**

1. On a configurable schedule (or manually), the plugin queues all content for processing
2. Content is processed in batches (e.g., 20 pages every 2 minutes) to avoid server strain
3. Each page is rendered through WordPress filters (works with any builder), converted to Markdown, and saved to `wp-markdown/` in the WordPress root
4. When a client requests any page with `Accept: text/markdown` header, the cached Markdown is served directly — no WordPress rendering needed
5. `llms.txt` at the site root provides AI crawlers with a structured overview of your site

**File structure:**

    wp-markdown/
    ├── index.md                  # homepage
    ├── about/
    │   └── index.md
    ├── blog/
    │   ├── my-post/
    │   │   └── index.md
    │   └── ...
    ├── _sitemap.md               # list of all pages
    ├── _all.md                   # complete content
    ├── llms.txt                  # compact site overview
    └── llms-full.txt             # full content for LLMs

== Installation ==

1. Upload the `markdownpress` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings → MarkdownPress to configure
4. Click "Generate Now" or wait for the next scheduled cron run

== Frequently Asked Questions ==

= Does it work with Elementor / Bricks / WPBakery / Divi? =

Yes. The plugin uses `apply_filters('the_content')` which resolves all builder shortcodes and blocks. If that doesn't work for your setup, switch to "HTTP fetch" or "Both" rendering method in settings.

= How do I access markdown content? =

Two ways:
1. Add `?format=markdown` to any URL on your site
2. Send `Accept: text/markdown` in your HTTP request headers

= What is llms.txt? =

It's an emerging standard (llmstxt.org) for describing your website to AI tools — like robots.txt but for LLMs.

= Will this slow down my site? =

No. Generation happens in the background via WP Cron. Serving cached markdown files is faster than normal page rendering because it reads a static file.

== Changelog ==

= 1.1.0 =
* Renamed plugin to MarkdownPress.
* Added support for Bricks, Elementor, and other page builders.
* Improved content rendering using combined PHP and HTTP fetch methods.
* Changed default content source to XML Sitemap.
* Standardized internal prefixes back to MDP / mdp.

= 1.0.0 =
* Initial release
