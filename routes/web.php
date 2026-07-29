<?php

use Illuminate\Support\Facades\Route;

/*
 | Bump by hand whenever a commit is added to this branch. Echoed on `/` and
 | in `/health`, it is the only reliable proof that a redeploy pulled new
 | commits rather than just restarting containers.
 */
const QA_DEPLOY_MARKER = 'l9-v1';

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
<title>Laravel 9 QA Site</title>
<style>body{font-family:system-ui,sans-serif;max-width:640px;margin:4rem auto;padding:0 1rem}
h1{color:#b45309}code{background:#f3f4f6;padding:.15rem .4rem;border-radius:4px}
.ok{color:#16a34a;font-weight:600}.warn{color:#b45309}</style></head>
<body><h1>Laravel 9 QA Site</h1>
<p class="ok">&#10003; Deploy OK — Laravel (end-of-life release line)</p>
<p>Laravel: <code>@{{laravel}}</code> · PHP: <code>@{{php}}</code></p>
<p>Deploy marker: <code>@{{marker}}</code></p>
<p class="warn">This branch pins an unsupported framework version on purpose.
See <code>/health</code> &rarr; <code>legacy</code>.</p>
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
 | `detect` answers the same shape as the Laravel 12 branches so the three are
 | diffable with one curl. It is all false here for the same reason as
 | `laravel-minimal`: none of those packages is installed. What this branch adds
 | is `legacy` — the facts that are true only because the framework is old.
 */
Route::get('/health', function () {
    return response()->json([
        'app' => 'Laravel 9 QA Site',
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

        /*
         | Laravel 9 is past end of life. A deploy tool that assumes "Laravel"
         | means "a currently supported Laravel" gets these wrong, and each one
         | is a separate way to break a real customer's legacy app:
         |
         | - `major` is 9, not 12. Version-gated behaviour must read it, not guess.
         | - The app skeleton still boots through `app/Http/Kernel.php` and
         |   `app/Console/Kernel.php`. Laravel 11 and 12 deleted both in favour of
         |   `bootstrap/app.php`, so anything that patches those files by path
         |   silently no-ops here — or worse, writes a file the app never reads.
         | - `public/hot` is Vite's dev-server marker. If a build leaves it behind,
         |   production pages point at a dev server that is not running.
         */
        'legacy' => [
            'major' => (int) explode('.', app()->version())[0],
            'end_of_life' => true,
            'http_kernel_file' => is_file(base_path('app/Http/Kernel.php')),
            'console_kernel_file' => is_file(base_path('app/Console/Kernel.php')),
            'vite_hot_file' => is_file(public_path('hot')),
            'build_manifest' => is_file(public_path('build/manifest.json')),
        ],
        'time' => now()->toIso8601String(),
    ]);
});
