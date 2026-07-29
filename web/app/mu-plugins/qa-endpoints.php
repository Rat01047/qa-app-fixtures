<?php
/**
 * Plugin Name:  QA Endpoints
 * Description:  Acceptance and health endpoints for the Bedrock QA fixture. Adds /qa and /qa-health without touching the WordPress site itself.
 * Version:      1.0.0
 * Author:       QA fixtures
 * License:      MIT
 *
 * Unlike the other fixture branches, `/` is left alone on purpose: a Bedrock
 * fixture is only worth anything if the WordPress site it deploys is a real
 * WordPress site. So the QA surface lives on its own paths instead.
 *
 * These run on `muplugins_loaded`, which fires before WordPress decides whether
 * it is installed and before any theme or query work. That means /qa and
 * /qa-health still answer on a site whose database is reachable but whose
 * WordPress tables have not been created yet — which is exactly the window
 * right after a first deploy.
 */

namespace AppTypeQA;

if (! defined('ABSPATH')) {
    exit;
}

/** Bumped by hand to prove a redeploy actually pulled new commits. */
const DEPLOY_MARKER = 'bedrock-v2';

/**
 * Run a probe that may legitimately fail (no DB, no Redis) without fataling.
 */
function probe(callable $fn): array
{
    try {
        return ['ok' => true] + $fn();
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * The request path, without query string, normalised to a leading slash and no
 * trailing slash. Works under a subdirectory install too.
 */
function request_path(): string
{
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    return '/' . trim($path, '/');
}

/**
 * Is WordPress actually installed? Checked without booting the query layer, so
 * this is safe to call at muplugins_loaded.
 */
function wp_installed(): bool
{
    global $wpdb;

    if (! isset($wpdb) || ! $wpdb instanceof \wpdb) {
        return false;
    }

    $suppress = $wpdb->suppress_errors();
    $found = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->options}'");
    $wpdb->suppress_errors($suppress);

    return $found === $wpdb->options;
}

/**
 * Everything a tester needs in one document. Always HTTP 200 — the payload,
 * never the status code, carries the verdict, so a broken sub-feature is
 * reported rather than hidden behind a 500.
 */
function health_payload(): array
{
    global $wpdb;

    $root = dirname(ABSPATH, 2);

    return [
        'app' => 'Bedrock QA Site',
        'ok' => true,
        'deploy_marker' => DEPLOY_MARKER,
        'wp_version' => get_bloginfo('version'),
        'php_version' => PHP_VERSION,
        'time' => gmdate('c'),

        // Bedrock's whole point: config comes from .env, and the web root is
        // separated from the project root. Report both so a deploy tool's
        // Environment editor can be diffed against what is actually on disk.
        'bedrock' => [
            'wp_env' => defined('WP_ENV') ? WP_ENV : null,
            'content_dir' => defined('CONTENT_DIR') ? CONTENT_DIR : null,
            'abspath_is_web_wp' => str_ends_with(rtrim(ABSPATH, '/'), '/web/wp'),
            'dotenv_present' => is_file($root . '/.env'),
            'dotenv_example_present' => is_file($root . '/.env.example'),
            'salts_are_placeholders' => defined('AUTH_KEY') && AUTH_KEY === 'generateme',
            'disallow_file_mods' => defined('DISALLOW_FILE_MODS') ? DISALLOW_FILE_MODS : null,
        ],

        'database' => probe(static function () use ($wpdb) {
            return [
                'connected' => (bool) $wpdb->db_version(),
                'wp_installed' => wp_installed(),
                'table_prefix' => $wpdb->prefix,
            ];
        }),

        // The object cache only reports as active once the redis-cache plugin
        // has dropped in object-cache.php, so this distinguishes "Redis is
        // configured" from "Redis is actually serving the site".
        'redis' => [
            'host' => defined('WP_REDIS_HOST') ? WP_REDIS_HOST : null,
            'prefix' => defined('WP_REDIS_PREFIX') ? WP_REDIS_PREFIX : null,
            'dropin_present' => is_file(WP_CONTENT_DIR . '/object-cache.php'),
            'ext_redis_loaded' => extension_loaded('redis'),
        ],

        // Composer-managed WordPress is the reason to use Bedrock at all, so
        // prove the install actually came from composer rather than a tarball.
        'composer' => probe(static function () use ($root) {
            $lock = json_decode((string) @file_get_contents($root . '/composer.lock'), true) ?: [];
            $versions = [];

            foreach (($lock['packages'] ?? []) as $package) {
                $name = $package['name'];

                if ($name === 'roots/wordpress' || str_starts_with($name, 'wpackagist-')) {
                    $versions[$name] = $package['version'];
                }
            }

            return [
                'vendor_present' => is_dir($root . '/vendor'),
                'wp_core_installed_to' => is_dir($root . '/web/wp'),
                'locked' => $versions,
            ];
        }),

        // Plugins and themes are declared in composer.json but installed into
        // web/app by composer/installers, and .gitignore keeps them out of the
        // repository. So "the lock file lists it" and "it is on the deployed
        // disk" are two different claims — report both and let them be diffed.
        // Scanned from disk rather than via get_plugins(), which is not loaded
        // this early in the request.
        'content' => probe(static function () {
            $scan = static function (string $dir): array {
                if (! is_dir($dir)) {
                    return [];
                }

                $found = array_filter(
                    (array) scandir($dir),
                    static fn ($entry) => $entry[0] !== '.' && is_dir($dir . '/' . $entry)
                );

                sort($found);

                return array_values($found);
            };

            return [
                'plugins' => $scan(WP_CONTENT_DIR . '/plugins'),
                'themes' => $scan(WP_CONTENT_DIR . '/themes'),
                'mu_plugins' => $scan(WPMU_PLUGIN_DIR),
            ];
        }),
    ];
}

/**
 * Plain-HTML acceptance page. Deliberately needs no theme, no query and no
 * posts table, so it answers even before `wp-admin/install.php` has been run.
 */
function landing_html(): string
{
    $wp = esc_html(get_bloginfo('version'));
    $php = esc_html(PHP_VERSION);
    $marker = esc_html(DEPLOY_MARKER);
    $env = esc_html(defined('WP_ENV') ? WP_ENV : 'unknown');
    $installed = wp_installed() ? 'yes' : 'not yet';
    $content = health_payload()['content'];
    $plugins = esc_html(implode(', ', $content['plugins'] ?? []) ?: 'none');
    $themes = esc_html(implode(', ', $content['themes'] ?? []) ?: 'none');

    return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bedrock QA Site</title>
<style>body{font-family:system-ui,sans-serif;max-width:640px;margin:4rem auto;padding:0 1rem}
h1{color:#525ddc}code{background:#f3f4f6;padding:.15rem .4rem;border-radius:4px}
.ok{color:#16a34a;font-weight:600}</style></head>
<body><h1>Bedrock QA Site</h1>
<p class="ok">&#10003; Deploy OK — Bedrock</p>
<p>WordPress: <code>{$wp}</code> · PHP: <code>{$php}</code> · Marker: <code>{$marker}</code></p>
<p>WP_ENV: <code>{$env}</code> · WordPress installed: <code>{$installed}</code></p>
<p>Plugins on disk: <code>{$plugins}</code></p>
<p>Themes on disk: <code>{$themes}</code></p>
<p>Health JSON: <a href="/qa-health">/qa-health</a> · Site: <a href="/">/</a></p>
</body></html>
HTML;
}

/**
 * Serve the QA paths and get out of the way for everything else.
 */
function dispatch(): void
{
    switch (request_path()) {
        case '/qa-health':
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            status_header(200);
            echo json_encode(health_payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;

        case '/qa':
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            status_header(200);
            echo landing_html();
            exit;
    }
}

add_action('muplugins_loaded', __NAMESPACE__ . '\\dispatch', 0);
