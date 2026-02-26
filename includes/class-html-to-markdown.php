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

        // Clean up excessive blank lines.
        $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);
        $markdown = trim($markdown);

        return $markdown;
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
                // Collapse whitespace but preserve intentional newlines.
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
                    $output .= "\n\n{$prefix} {$inner}\n\n";
                    break;

                // ─── Paragraphs ───
                case 'p':
                    $inner = trim(self::process_node($child));
                    if ($inner !== '') {
                        $output .= "\n\n{$inner}\n\n";
                    }
                    break;

                // ─── Line break ───
                case 'br':
                    $output .= "  \n";
                    break;

                // ─── Horizontal rule ───
                case 'hr':
                    $output .= "\n\n---\n\n";
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
                        $output .= "![{$alt}]({$src})";
                    }
                    break;

                // ─── Unordered list ───
                case 'ul':
                    $output .= "\n" . self::process_list($child, 'ul', 0) . "\n";
                    break;

                // ─── Ordered list ───
                case 'ol':
                    $output .= "\n" . self::process_list($child, 'ol', 0) . "\n";
                    break;

                // ─── Blockquote ───
                case 'blockquote':
                    $inner = trim(self::process_node($child));
                    $lines = explode("\n", $inner);
                    $quoted = array_map(function ($l) {
                        return '> ' . $l;
                    }, $lines);
                    $output .= "\n\n" . implode("\n", $quoted) . "\n\n";
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
                    $output .= "\n\n```{$lang}\n{$code}\n```\n\n";
                    break;

                case 'code':
                    // Inline code (not inside <pre>).
                    $inner = $child->textContent;
                    $output .= "`{$inner}`";
                    break;

                // ─── Table ───
                case 'table':
                    $output .= "\n\n" . self::process_table($child) . "\n\n";
                    break;

                // ─── Divs, sections, articles — pass through ───
                case 'div':
                case 'section':
                case 'article':
                case 'main':
                case 'span':
                case 'figure':
                case 'figcaption':
                case 'details':
                case 'summary':
                case 'mark':
                case 'small':
                case 'sup':
                case 'sub':
                case 'abbr':
                case 'cite':
                case 'time':
                case 'address':
                case 'dl':
                case 'dt':
                case 'dd':
                case 'label':
                case 'legend':
                case 'fieldset':
                case 'hgroup':
                    $output .= self::process_node($child);
                    break;

                // ─── Definition list items ───
                // Already handled above via pass-through.

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
