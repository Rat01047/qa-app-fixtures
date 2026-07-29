<?php

use Illuminate\Support\Facades\Route;

/*
 | Bump by hand whenever a commit is added to this branch. Echoed on `/` and
 | in `/health`, it is the only reliable proof that a redeploy pulled new
 | commits rather than just restarting containers.
 */
const QA_DEPLOY_MARKER = 'v3';

/*
 | QA landing — self-contained (no Vite build, no DB) so the homepage
 | returns 200 after `composer install` + `php artisan key:generate` alone.
 | The stock resources/views/welcome.blade.php uses @vite and would 500 in
 | production unless assets are built, so we do not use it here.
 */
Route::get('/', function () {
    $html = <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laravel QA Site</title>
<style>body{font-family:system-ui,sans-serif;max-width:640px;margin:4rem auto;padding:0 1rem}
h1{color:#ef4444}code{background:#f3f4f6;padding:.15rem .4rem;border-radius:4px}
.ok{color:#16a34a;font-weight:600}</style></head>
<body><h1>Laravel QA Site</h1>
<p class="ok">&#10003; Deploy OK — Laravel</p>
<p>Laravel: <code>@{{laravel}}</code> · PHP: <code>@{{php}}</code></p>
<p>Deploy marker: <code>@{{marker}}</code></p>
<p>Health JSON: <a href="/health">/health</a></p></body></html>
HTML;
    $html = strtr($html, [
        '@{{laravel}}' => app()->version(),
        '@{{php}}' => PHP_VERSION,
        '@{{marker}}' => QA_DEPLOY_MARKER,
    ]);
    return response($html);
});

/*
 | Always 200 — the payload carries the verdict, never the status code.
 |
 | `detect` is the point of this branch. It reports the same facts a deploy
 | tool's Laravel feature detection reads out of `composer.json`, and here
 | every one of them is false because none of these packages is installed.
 | A tool that offers Horizon, Octane or SSR controls on this site is
 | over-detecting, and this is what proves it without SSH.
 */
Route::get('/health', function () {
    return response()->json([
        'app' => 'Laravel QA Site',
        'ok' => true,
        'deploy_marker' => QA_DEPLOY_MARKER,
        'laravel_version' => app()->version(),
        'php_version' => PHP_VERSION,
        'detect' => [
            'horizon' => class_exists(\Laravel\Horizon\Horizon::class),
            'octane' => class_exists(\Laravel\Octane\Octane::class),
            'inertia' => class_exists(\Inertia\Inertia::class),
            'ssr_ready' => is_file(base_path('bootstrap/ssr/ssr.js')),
            'maintenance' => app()->isDownForMaintenance(),
        ],
        'time' => now()->toIso8601String(),
    ]);
});
