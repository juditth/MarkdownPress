<?php
/**
 * Generates llms.txt and llms-full.txt files.
 *
 * llms.txt follows the emerging standard for LLM-readable site descriptions.
 * @see https://llmstxt.org/
 *
 * @package WP_Markdown_Cache
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPMC_Llms_Txt
{

    /**
     * Generate llms.txt and llms-full.txt files.
     */
    public static function generate()
    {
        self::generate_llms_txt();
        self::generate_llms_full_txt();
    }

    /**
     * Generate the compact llms.txt file.
     * This is the "summary" version — enough for an LLM to understand the site.
     */
    private static function generate_llms_txt()
    {
        $site_name = get_bloginfo('name');
        $site_desc = get_bloginfo('description');
        $site_url = home_url('/');

        $txt = "# {$site_name}\n\n";
        $txt .= "> {$site_desc}\n\n";

        // About section.
        $txt .= "## About\n\n";
        $txt .= "Website: {$site_url}\n";
        $txt .= "Language: " . get_bloginfo('language') . "\n\n";

        // Main sections — top-level pages.
        $pages = get_pages(array(
            'parent' => 0,
            'sort_column' => 'menu_order,post_title',
            'post_status' => 'publish',
        ));

        if (!empty($pages)) {
            $txt .= "## Main Sections\n\n";
            foreach ($pages as $page) {
                $url = get_permalink($page->ID);
                $md_url = $url . (strpos($url, '?') !== false ? '&' : '?') . 'format=markdown';
                $excerpt = $page->post_excerpt ?: wp_trim_words(strip_shortcodes($page->post_content), 20, '...');
                $excerpt = wp_strip_all_tags($excerpt);
                $txt .= "- [{$page->post_title}]({$md_url}): {$excerpt}\n";
            }
            $txt .= "\n";
        }

        // Blog posts — most recent.
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 20,
        ));

        if (!empty($posts)) {
            $txt .= "## Recent Posts\n\n";
            foreach ($posts as $post) {
                $url = get_permalink($post->ID);
                $md_url = $url . (strpos($url, '?') !== false ? '&' : '?') . 'format=markdown';
                $date = get_the_date('Y-m-d', $post->ID);
                $txt .= "- [{$post->post_title}]({$md_url}) ({$date})\n";
            }
            $txt .= "\n";
        }

        // Custom post types.
        $custom_types = get_post_types(array(
            'public' => true,
            '_builtin' => false,
        ), 'objects');

        if (!empty($custom_types)) {
            $txt .= "## Content Types\n\n";
            foreach ($custom_types as $cpt) {
                $count = wp_count_posts($cpt->name);
                $publish_count = isset($count->publish) ? $count->publish : 0;
                if ($publish_count > 0) {
                    $archive_url = get_post_type_archive_link($cpt->name);
                    $txt .= "- **{$cpt->label}** ({$publish_count} items)";
                    if ($archive_url) {
                        $txt .= " — [{$archive_url}]({$archive_url})";
                    }
                    $txt .= "\n";
                }
            }
            $txt .= "\n";
        }

        // Resources.
        $txt .= "## Resources\n\n";
        $txt .= "- [Full Markdown Content](" . home_url('/llms-full.txt') . "): Complete site content in markdown\n";
        $txt .= "- [Sitemap](" . home_url('/?format=markdown') . "): Homepage / sitemap in markdown format\n";
        $txt .= "- [XML Sitemap](" . home_url('/wp-sitemap.xml') . "): Standard XML sitemap\n";
        $txt .= "\n";

        // How to access.
        $txt .= "## Access\n\n";
        $txt .= "Any page on this site can be retrieved as Markdown by:\n";
        $txt .= "1. Adding `?format=markdown` to any URL\n";
        $txt .= "2. Sending `Accept: text/markdown` in the HTTP request headers\n";

        file_put_contents(WPMC_CACHE_DIR . 'llms.txt', $txt);
    }

    /**
     * Generate the full llms-full.txt — all page content concatenated.
     */
    private static function generate_llms_full_txt()
    {
        $all_md_file = WPMC_CACHE_DIR . '_all.md';
        if (file_exists($all_md_file)) {
            // Just copy _all.md as llms-full.txt.
            copy($all_md_file, WPMC_CACHE_DIR . 'llms-full.txt');
        } else {
            // Generate a simpler version.
            $txt = "# " . get_bloginfo('name') . " — Full Content\n\n";
            $txt .= "Generated: " . current_time('Y-m-d H:i:s') . "\n\n---\n\n";

            $post_types = get_post_types(array('public' => true), 'names');
            $posts = get_posts(array(
                'post_type' => $post_types,
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'orderby' => 'menu_order title',
                'order' => 'ASC',
            ));

            foreach ($posts as $post) {
                $txt .= "## {$post->post_title}\n\n";
                $txt .= "URL: " . get_permalink($post->ID) . "\n";
                $txt .= "Type: {$post->post_type}\n\n";
                $content = apply_filters('the_content', $post->post_content);
                $txt .= WPMC_Html_To_Markdown::convert($content) . "\n\n---\n\n";
            }

            file_put_contents(WPMC_CACHE_DIR . 'llms-full.txt', $txt);
        }
    }
}
