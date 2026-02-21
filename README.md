# MarkdownPress

Creates a markdown mirror of your WordPress site. Serves content via `Accept: text/markdown` header, generates `llms.txt` for AI crawlers.

## Features

- **Converts** all published posts, pages, and custom post types to clean Markdown.
- **Supports** taxonomy archives, author pages, and the homepage.
- **Works with any page builder** (Gutenberg, Elementor, Bricks, WPBakery, Divi, etc.).
- **Generates `llms.txt` and `llms-full.txt`** — the "robots.txt for AI".
- **Serves markdown instantly** via `Accept: text/markdown` HTTP header or `?format=markdown` query parameter.
- **Smart batch processing** to prevent server overload.
- **Event-driven updates**: regenerates markdown when posts are saved.
- **YAML frontmatter** with title, URL, dates, categories, tags, featured image.
- **On-the-fly generation**: missed cache items are now generated immediately when requested.

## Installation

1. Upload the `markdownpress` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **Settings → MarkdownPress** to configure
4. Click "Generate Now" or wait for the next scheduled cron run

## How to use for AI

To let an AI agent read your site, point it to `your-domain.com/llms.txt`.
To fetch any specific page as Markdown, use the following header:
`Accept: text/markdown`

---

*Note: For official WordPress.org repository details, see `readme.txt`.*
