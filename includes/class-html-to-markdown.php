<?php
/**
 * Lightweight HTML → Markdown converter.
 * No external dependencies. Handles common HTML elements.
 *
 * @package MarkdownPress
 */

if (!defined('ABSPATH')) {
    exit;
}

class MDP_Html_To_Markdown
{

    /**
     * Convert HTML string to Markdown.
     *
     * @param  string $html Raw HTML content.
     * @return string       Markdown content.
     */
    public static function convert($html)
    {
        if (empty($html)) {
            return '';
        }

        // Remove scripts, styles, forms, nav, footer, header elements.
        $html = self::strip_unwanted_tags($html);

        // Normalize whitespace in the HTML.
        $html = preg_replace('/\r\n|\r/', "\n", $html);

        // Use DOMDocument for reliable parsing.
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="mdp-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $root = $doc->getElementById('mdp-root');
        if (!$root) {
            // Fallback: just strip tags.
            return self::fallback_strip($html);
        }

        $markdown = self::process_node($root);

        if (self::needs_builder_payload_fallback($markdown)) {
            $decoded_html = self::decode_builder_payloads($html);
            if ($decoded_html !== $html) {
                return self::convert($decoded_html);
            }
        }

        // Clean up excessive blank lines.
        $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);
        $markdown = trim($markdown);

        return self::normalize_text($markdown);
    }

    /**
     * Normalize text to UTF-8 and repair common Czech mojibake.
     */
    public static function normalize_text($text)
    {
        if (!is_string($text) || $text === '') {
            return $text;
        }

        if (!self::is_utf8($text)) {
            if (function_exists('mb_convert_encoding')) {
                $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1250,ISO-8859-2,ISO-8859-1');
            } elseif (function_exists('iconv')) {
                $converted = @iconv('Windows-1250', 'UTF-8//IGNORE', $text);
                if ($converted) {
                    $text = $converted;
                }
            }
        }

        return self::repair_mojibake($text);
    }

    /**
     * Repair text that was UTF-8 bytes incorrectly interpreted as Windows-1250.
     */
    private static function repair_mojibake($text)
    {
        if (!preg_match('/[\x{0102}\x{00C4}\x{0139}\x{00C2}][\x{0080}-\x{02FF}]?/u', $text)) {
            return $text;
        }

        if (!function_exists('mb_convert_encoding') && !function_exists('iconv')) {
            return $text;
        }

        if (function_exists('mb_convert_encoding')) {
            $candidate = @mb_convert_encoding($text, 'Windows-1250', 'UTF-8');
        } else {
            $candidate = @iconv('UTF-8', 'Windows-1250//IGNORE', $text);
        }

        if (!$candidate || !self::is_utf8($candidate)) {
            return $text;
        }

        return (self::mojibake_score($candidate) < self::mojibake_score($text)) ? $candidate : $text;
    }

    /**
     * Score text by how many mojibake markers it contains.
     */
    private static function mojibake_score($text)
    {
        preg_match_all('/(?:[\x{0102}\x{00C4}\x{0139}\x{00C2}].)/u', $text, $matches);
        return count($matches[0]);
    }

    /**
     * Check whether a string is valid UTF-8.
     */
    private static function is_utf8($text)
    {
        if (function_exists('mb_check_encoding')) {
            return mb_check_encoding($text, 'UTF-8');
        }

        return (bool) preg_match('//u', $text);
    }

    /**
     * Process a DOM node recursively.
     */
    private static function process_node(DOMNode $node)
    {
        $output = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $text = $child->textContent;
                // Collapse spaces but keep existing single newlines.
                $text = preg_replace('/[ \t]+/', ' ', $text);
                $output .= $text;
                continue;
            }

            if (!($child instanceof DOMElement)) {
                continue;
            }

            $tag = strtolower($child->tagName);

            switch ($tag) {
                // ─── Headings ───
                case 'h1':
                case 'h2':
                case 'h3':
                case 'h4':
                case 'h5':
                case 'h6':
                    $level = intval(substr($tag, 1));
                    $prefix = str_repeat('#', $level);
                    $inner = trim(self::process_node($child));
                    if ($inner !== '') {
                        $output = self::ensure_block_prefix($output) . "{$prefix} {$inner}\n\n";
                    }
                    break;

                // ─── Paragraphs ───
                case 'p':
                    $inner = trim(self::process_node($child));
                    if ($inner !== '') {
                        $output = self::ensure_block_prefix($output) . "{$inner}\n\n";
                    }
                    break;

                // ─── Line break ───
                case 'br':
                    $output = rtrim($output) . "  \n";
                    break;

                // ─── Horizontal rule ───
                case 'hr':
                    $output = rtrim($output) . "\n\n---\n\n";
                    break;

                // ─── Bold ───
                case 'strong':
                case 'b':
                    $inner = self::process_node($child);
                    $output .= "**{$inner}**";
                    break;

                // ─── Italic ───
                case 'em':
                case 'i':
                    $inner = self::process_node($child);
                    $output .= "*{$inner}*";
                    break;

                // ─── Links ───
                case 'a':
                    $href = $child->getAttribute('href');
                    $inner = trim(self::process_node($child));
                    if ($href && $inner) {
                        $output .= "[{$inner}]({$href})";
                    } elseif ($inner) {
                        $output .= $inner;
                    }
                    break;

                // ─── Images ───
                case 'img':
                    $src = $child->getAttribute('src');
                    $alt = $child->getAttribute('alt') ?: '';
                    if ($src) {
                        // Ensure absolute URL.
                        if (strpos($src, 'http') !== 0 && strpos($src, '//') !== 0) {
                            $src = home_url($src);
                        }
                        $output = self::ensure_block_prefix($output) . "![{$alt}]({$src})\n\n";
                    }
                    break;

                // ─── Unordered list ───
                case 'ul':
                    $list_content = trim(self::process_list($child, 'ul', 0));
                    if ($list_content !== '') {
                        $output = rtrim($output) . "\n\n" . $list_content . "\n\n";
                    }
                    break;

                // ─── Ordered list ───
                case 'ol':
                    $list_content = trim(self::process_list($child, 'ol', 0));
                    if ($list_content !== '') {
                        $output = rtrim($output) . "\n\n" . $list_content . "\n\n";
                    }
                    break;

                // ─── Blockquote ───
                case 'blockquote':
                    $inner = trim(self::process_node($child));
                    if ($inner !== '') {
                        $lines = explode("\n", $inner);
                        $quoted = array_map(function ($l) {
                            return '> ' . $l;
                        }, $lines);
                        $output = rtrim($output) . "\n\n" . implode("\n", $quoted) . "\n\n";
                    }
                    break;

                // ─── Preformatted / code ───
                case 'pre':
                    $code = $child->textContent;
                    $lang = '';
                    // Try to get language from <code> child.
                    $code_el = $child->getElementsByTagName('code')->item(0);
                    if ($code_el) {
                        $class = $code_el->getAttribute('class');
                        if (preg_match('/language-(\w+)/', $class, $m)) {
                            $lang = $m[1];
                        }
                        $code = $code_el->textContent;
                    }
                    $output = rtrim($output) . "\n\n```{$lang}\n{$code}\n```\n\n";
                    break;

                case 'code':
                    // Inline code (not inside <pre>).
                    $inner = $child->textContent;
                    $output .= "`{$inner}`";
                    break;

                // ─── Table ───
                case 'table':
                    $table_md = self::process_table($child);
                    if ($table_md !== '') {
                        $output = rtrim($output) . "\n\n" . $table_md . "\n\n";
                    }
                    break;

                // ─── Block containers ───
                case 'div':
                case 'section':
                case 'article':
                case 'main':
                case 'figure':
                case 'figcaption':
                case 'details':
                case 'summary':
                case 'address':
                case 'dl':
                case 'fieldset':
                case 'hgroup':
                    $inner = trim(self::process_node($child));
                    if ($inner !== '') {
                        $output = self::ensure_block_prefix($output) . $inner . "\n\n";
                    }
                    break;

                // ─── Inline pass-through ───
                case 'span':
                case 'mark':
                case 'small':
                case 'sup':
                case 'sub':
                case 'abbr':
                case 'cite':
                case 'time':
                case 'dt':
                case 'dd':
                case 'label':
                case 'legend':
                    $output .= self::process_node($child);
                    break;

                // ─── Skip known unwanted ───
                case 'script':
                case 'style':
                case 'noscript':
                case 'iframe':
                case 'form':
                case 'input':
                case 'textarea':
                case 'select':
                case 'button':
                case 'svg':
                case 'canvas':
                case 'video':
                case 'audio':
                case 'map':
                case 'object':
                case 'embed':
                case 'nav':
                case 'header':
                case 'footer':
                case 'aside':
                    break;

                // ─── Everything else — process children ───
                default:
                    $output .= self::process_node($child);
                    break;
            }
        }

        return $output;
    }

    /**
     * Decode supported builder payloads that store content as Base64 serialized data.
     */
    private static function decode_builder_payloads($html)
    {
        $decoded = self::decode_builder_payload($html);
        if ($decoded !== '') {
            return $decoded;
        }

        return preg_replace_callback('/(?<![A-Za-z0-9+\/_\-=])([A-Za-z0-9+\/_\-]{40,}={0,2})(?![A-Za-z0-9+\/_\-=])/', function ($matches) {
            $decoded_payload = self::decode_builder_payload($matches[1]);
            return ($decoded_payload !== '') ? $decoded_payload : $matches[0];
        }, $html);
    }

    /**
     * Use builder decoding only when normal HTML conversion produced raw encoded payloads.
     */
    private static function needs_builder_payload_fallback($markdown)
    {
        $text = trim($markdown);
        if ($text === '') {
            return false;
        }

        return (bool) preg_match('/(?<![A-Za-z0-9+\/_\-=])[A-Za-z0-9+\/_\-]{120,}={0,2}(?![A-Za-z0-9+\/_\-=])/', $text);
    }

    /**
     * Decode one Base64 serialized builder payload into HTML/text content.
     */
    private static function decode_builder_payload($payload)
    {
        $payload = trim($payload);
        $payload = trim($payload, "\"' \t\n\r\0\x0B");

        if (strlen($payload) < 40 || !preg_match('/^[A-Za-z0-9+\/_\-]+={0,2}$/', $payload)) {
            return '';
        }

        $serialized = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($serialized === false || !self::looks_like_serialized($serialized)) {
            return '';
        }

        try {
            $data = @unserialize($serialized, array('allowed_classes' => false));
        } catch (Throwable $e) {
            return '';
        }
        if ($data === false || !is_array($data)) {
            return '';
        }

        $parts = array();
        self::extract_builder_content($data, $parts);
        $parts = array_values(array_unique(array_filter(array_map('trim', $parts))));

        return implode("\n\n", $parts);
    }

    /**
     * Check whether decoded Base64 looks like PHP serialized data.
     */
    private static function looks_like_serialized($value)
    {
        $value = ltrim($value);
        return (bool) preg_match('/^(a|s|i|d|b|N):/', $value);
    }

    /**
     * Pull only human-facing content out of nested builder arrays.
     */
    private static function extract_builder_content($value, array &$parts, $key = '')
    {
        if (is_array($value)) {
            foreach ($value as $child_key => $child_value) {
                self::extract_builder_content($child_value, $parts, is_string($child_key) ? $child_key : '');
            }
            return;
        }

        if (!is_string($value) || $value === '') {
            return;
        }

        if (!self::is_builder_content_key($key) || self::is_builder_noise($value)) {
            return;
        }

        $parts[] = $value;
    }

    /**
     * Builder keys that usually contain visible page copy.
     */
    private static function is_builder_content_key($key)
    {
        return in_array($key, array(
            'content',
            'text',
            'title',
            'subtitle',
            'heading',
            'description',
            'caption',
            'button_text',
            'link_text',
            'popup_text',
            'popup_title',
            'book_title',
            'book_author',
            'name',
            'company',
        ), true);
    }

    /**
     * Skip styling, URLs, SVG/icon markup, and short configuration values.
     */
    private static function is_builder_noise($value)
    {
        $trimmed = trim($value);
        if ($trimmed === '' || strlen($trimmed) < 3) {
            return true;
        }

        if (preg_match('/^#?[a-f0-9]{3,8}$/i', $trimmed)) {
            return true;
        }

        if (preg_match('/^(rgba?\(|https?:\/\/|\/wp-content\/|data:image\/|[0-9.]+%?$)/i', $trimmed)) {
            return true;
        }

        if (stripos($trimmed, '<svg') !== false || stripos($trimmed, '<use ') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Process a list element.
     */
    private static function process_list(DOMElement $list, $type, $depth)
    {
        $output = '';
        $counter = 1;
        $indent = str_repeat('  ', $depth);

        foreach ($list->childNodes as $child) {
            if (!($child instanceof DOMElement) || strtolower($child->tagName) !== 'li') {
                continue;
            }

            $prefix = ($type === 'ol') ? "{$counter}. " : '- ';
            $inner = '';

            // Process li children, handling nested lists separately.
            foreach ($child->childNodes as $li_child) {
                if ($li_child instanceof DOMElement) {
                    $li_tag = strtolower($li_child->tagName);
                    if ($li_tag === 'ul' || $li_tag === 'ol') {
                        $inner .= "\n" . self::process_list($li_child, $li_tag, $depth + 1);
                        continue;
                    }
                }
                if ($li_child instanceof DOMText) {
                    $inner .= preg_replace('/\s+/', ' ', $li_child->textContent);
                } elseif ($li_child instanceof DOMElement) {
                    $inner .= self::process_node($li_child);
                }
            }

            $inner = trim($inner);
            $output .= "{$indent}{$prefix}{$inner}\n";
            $counter++;
        }

        return $output;
    }

    /**
     * Process a table element into Markdown table.
     */
    private static function process_table(DOMElement $table)
    {
        $rows = array();
        $has_header = false;

        // Gather all rows from thead, tbody, tfoot.
        foreach ($table->childNodes as $section) {
            if (!($section instanceof DOMElement)) {
                continue;
            }
            $section_tag = strtolower($section->tagName);
            if ($section_tag === 'thead' || $section_tag === 'tbody' || $section_tag === 'tfoot') {
                foreach ($section->getElementsByTagName('tr') as $tr) {
                    $cells = array();
                    $is_header = ($section_tag === 'thead');
                    foreach ($tr->childNodes as $td) {
                        if ($td instanceof DOMElement && in_array(strtolower($td->tagName), array('td', 'th'))) {
                            $cells[] = trim(self::process_node($td));
                            if (strtolower($td->tagName) === 'th') {
                                $is_header = true;
                            }
                        }
                    }
                    if (!empty($cells)) {
                        $rows[] = array('cells' => $cells, 'header' => $is_header);
                        if ($is_header) {
                            $has_header = true;
                        }
                    }
                }
            } elseif ($section_tag === 'tr') {
                $cells = array();
                foreach ($section->childNodes as $td) {
                    if ($td instanceof DOMElement && in_array(strtolower($td->tagName), array('td', 'th'))) {
                        $cells[] = trim(self::process_node($td));
                    }
                }
                if (!empty($cells)) {
                    $rows[] = array('cells' => $cells, 'header' => false);
                }
            }
        }

        if (empty($rows)) {
            return '';
        }

        // Determine column count.
        $col_count = max(array_map(function ($r) {
            return count($r['cells']);
        }, $rows));

        $output = '';
        $header_done = false;

        foreach ($rows as $row) {
            // Pad cells.
            while (count($row['cells']) < $col_count) {
                $row['cells'][] = '';
            }
            $line = '| ' . implode(' | ', $row['cells']) . ' |';
            $output .= $line . "\n";

            // Add separator after header.
            if ($row['header'] && !$header_done) {
                $sep = '| ' . implode(' | ', array_fill(0, $col_count, '---')) . ' |';
                $output .= $sep . "\n";
                $header_done = true;
            }
        }

        // If no header row was found, add separator after first row.
        if (!$header_done && !empty($rows)) {
            $lines = explode("\n", trim($output));
            $first = array_shift($lines);
            $sep = '| ' . implode(' | ', array_fill(0, $col_count, '---')) . ' |';
            $output = $first . "\n" . $sep . "\n" . implode("\n", $lines) . "\n";
        }

        return trim($output);
    }

    /**
     * Strip unwanted tags before DOM parsing.
     */
    private static function strip_unwanted_tags($html)
    {
        // Remove script, style, noscript tags and their content.
        $html = preg_replace('/<(script|style|noscript)[^>]*>.*?<\/\1>/si', '', $html);
        // Remove HTML comments.
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        return $html;
    }

    /**
     * Ensure we have two newlines before a block element.
     */
    private static function ensure_block_prefix($output)
    {
        if (empty($output)) {
            return '';
        }
        $output = rtrim($output);
        return $output . "\n\n";
    }

    /**
     * Fallback: simple tag stripping for broken HTML.
     */
    private static function fallback_strip($html)
    {
        $text = wp_strip_all_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }
}
