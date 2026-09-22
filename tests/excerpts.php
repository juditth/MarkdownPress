<?php
/**
 * Integration regression test, for a disposable local WordPress installation.
 * Run: php tests/excerpts.php C:/path/to/wordpress/wp-load.php
 * Requires the current MarkdownPress plugin to be active. Creates and removes
 * one test page and a narrowly scoped frontend renderer in mu-plugins.
 */
if (PHP_SAPI !== 'cli' || empty($argv[1])) {
    exit("Usage: php tests/excerpts.php /path/to/wp-load.php\n");
}
$_SERVER['HTTP_HOST'] = 'vibewp.test';
$_SERVER['SERVER_NAME'] = 'vibewp.test';
$_SERVER['SERVER_PORT'] = 80;
$_SERVER['REQUEST_URI'] = '/wp-admin/';
$_SERVER['REQUEST_METHOD'] = 'POST';
define('DISABLE_WP_CRON', true);
define('DONOTCACHEPAGE', true);
require $argv[1];

function check_excerpt($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
    echo "PASS: $message\n";
}

$saved_options = get_option('mdp_options');
$options = mdp_get_options();
$options['content_method'] = 'http';
$options['frontmatter'] = true;
$options['regenerate_on_save'] = false;
update_option('mdp_options', $options);
$fixture = WPMU_PLUGIN_DIR . '/mdp-excerpt-regression.php';
if (file_exists($fixture)) { throw new RuntimeException('Fixture already exists'); }
$post_id = 0;
$cache_path = '';
try {
    wp_mkdir_p(WPMU_PLUGIN_DIR);
    file_put_contents($fixture, <<<'PHP'
<?php
// Temporary frontend-only Divi simulation, limited to the regression page.
add_action('template_redirect', function () {
    if (!is_page('mdp-excerpt-regression-20260922')) { return; }
    foreach (array('et_pb_section', 'et_pb_row', 'et_pb_text') as $tag) {
        add_shortcode($tag, function ($attrs, $content) { return '<div>' . do_shortcode($content ?? '') . '</div>'; });
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><body><main>' . do_shortcode(get_post()->post_content) . '</main></body></html>';
    exit;
}, 0);
PHP
    );
    $post_id = wp_insert_post(array(
        'post_type' => 'page', 'post_status' => 'publish',
        'post_title' => 'MarkdownPress – test excerptu',
        'post_name' => 'mdp-excerpt-regression-20260922',
        'post_content' => '[et_pb_section admin_label="Interní nastavení"][et_pb_row][et_pb_text]<h1>Statistická data</h1><p>Přehled českého vápenictví a výroby vápna. Čistý text s <strong>diakritikou</strong> a <a href="https://example.com/">odkazem</a>.</p>[/et_pb_text][/et_pb_row][/et_pb_section]',
    ), true);
    if (is_wp_error($post_id)) { throw new RuntimeException($post_id->get_error_message()); }
    $post = get_post($post_id);
    $converter = new MDP_Converter();
    $url = get_permalink($post_id);
    $cache_path = $converter->url_to_cache_path($url);
    check_excerpt(!shortcode_exists('et_pb_section'), 'Divi shortcodes are unregistered during generation');
    check_excerpt(strpos(wp_trim_words($post->post_content, 30), '[et_pb_section') !== false, 'Original YAML implementation reproduces the bug');
    check_excerpt(strpos(wp_trim_words(strip_shortcodes($post->post_content), 20), '[et_pb_section') !== false, 'Original llms implementation reproduces the bug');
    check_excerpt($converter->convert_post($post_id), 'HTTP Fetch converts the test page');
    $markdown = file_get_contents($cache_path);
    check_excerpt(strpos($markdown, 'et_pb_') === false, 'Whole generated page including YAML is free of Divi codes');
    check_excerpt(strpos($markdown, 'excerpt: "Statistická data Přehled českého vápenictví') !== false, 'YAML excerpt contains rendered Czech text');
    $requests = 0;
    $count_http = function ($pre) use (&$requests) { $requests++; return $pre; };
    add_filter('pre_http_request', $count_http);
    $excerpt = $converter->get_post_excerpt($post, 20);
    remove_filter('pre_http_request', $count_http);
    check_excerpt($requests === 0 && strpos($excerpt, 'Statistická data') === 0, 'Summary reuses cached body without another HTTP request');
    check_excerpt(strpos($excerpt, 'https://') === false && strpos($excerpt, '**') === false, 'Summary removes link targets and bold formatting');

    $llms = new ReflectionMethod('MDP_Llms_Txt', 'generate_llms_txt');
    $llms->invoke(null);
    $txt = file_get_contents(MDP_CACHE_DIR . 'llms.txt');
    preg_match('/^- \[MarkdownPress – test excerptu\].*$/m', $txt, $line);
    check_excerpt(!empty($line) && strpos($line[0], 'et_pb_') === false && strpos($line[0], 'Přehled českého') !== false, 'llms.txt contains the clean test-page description');
    $response = wp_remote_get(add_query_arg('format', 'markdown', $url));
    check_excerpt(!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200 && wp_remote_retrieve_body($response) === $markdown, 'Public markdown endpoint serves the verified file');

    $options['frontmatter'] = false;
    update_option('mdp_options', $options);
    check_excerpt($converter->convert_post($post_id), 'Conversion succeeds with YAML disabled');
    check_excerpt(strpos(file_get_contents($cache_path), '---') !== 0 && $converter->get_post_excerpt($post, 20) === $excerpt, 'Disabling YAML does not change the summary');
    wp_delete_file($cache_path);
    check_excerpt($converter->get_post_excerpt($post, 20) === $excerpt, 'Missing cache uses configured HTTP rendering');

    $post->post_excerpt = '<p>Ručně napsaný <strong>český popis</strong>.</p>';
    check_excerpt($converter->get_post_excerpt($post) === 'Ručně napsaný český popis.', 'Manual excerpt retains precedence');
    $post->post_excerpt = '[et_pb_section][et_pb_text]Čistý ruční popis[/et_pb_text][/et_pb_section]';
    check_excerpt($converter->get_post_excerpt($post) === 'Čistý ruční popis', 'Manual excerpt removes unregistered Divi wrappers but preserves text');
    $post->post_excerpt = '';
    check_excerpt($converter->get_post_excerpt($post, 20, "---\ntitle: Metadata\nexcerpt: old\n---\n\nČistý obsah\n\n## JSON Schema\n\n```json\n{}\n```") === 'Čistý obsah', 'Old frontmatter and schema are excluded');
    check_excerpt($converter->get_post_excerpt($post, 3, 'jedna dvě tři čtyři pět') === 'jedna dvě tři...', 'Automatic descriptions respect the word limit');
    echo "All excerpt regression checks passed.\n";
} finally {
    if ($post_id && !is_wp_error($post_id)) { wp_delete_post($post_id, true); }
    if ($cache_path && file_exists($cache_path)) { wp_delete_file($cache_path); }
    if (file_exists($fixture)) { wp_delete_file($fixture); }
    update_option('mdp_options', $saved_options);
    $llms = new ReflectionMethod('MDP_Llms_Txt', 'generate_llms_txt');
    $llms->invoke(null);
}
