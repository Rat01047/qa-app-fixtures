<?php

use App\Jobs\QaFailingJob;
use App\Jobs\QaHeartbeatJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;

/*
 | QA landing — self-contained (no Vite build, no DB) so the homepage
 | returns 200 after `composer install` + `php artisan key:generate` alone.
 | The stock resources/views/welcome.blade.php uses @vite and would 500 in
 | production unless assets are built, so we do not use it here.
 |
 | Everything that needs a build, a database or a worker lives on its own
 | route, so a broken sub-feature can never take the acceptance page down.
 */

/*
 | `artisan optimize` (config:cache, route:cache) boots a second application in
 | the same PHP process, so this file is included twice per command. Top-level
 | declarations must therefore be guarded — an unguarded `function` fatals with
 | "Cannot redeclare", naming this same file and line as both declarations.
 */

/** Bumped by hand to prove a redeploy actually pulled new commits. */
if (! defined('QA_DEPLOY_MARKER')) {
    define('QA_DEPLOY_MARKER', 'v3');
}

if (! function_exists('qa_probe')) {
    /** Run a probe that may legitimately fail (no DB, no Redis) without 500ing. */
    function qa_probe(callable $fn): array
    {
        try {
            return ['ok' => true] + $fn();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

if (! function_exists('qa_breadcrumb')) {
    /** Read a JSON breadcrumb written by the scheduler or the queue worker. */
    function qa_breadcrumb(string $name): ?array
    {
        $path = storage_path("app/qa/{$name}.json");

        return is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    }
}

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
<p>Laravel: <code>@{{laravel}}</code> · PHP: <code>@{{php}}</code> · Marker: <code>@{{marker}}</code></p>
<p>Health JSON: <a href="/health">/health</a> · Inertia SSR: <a href="/inertia">/inertia</a></p>
<p>Queue: <a href="/queue/dispatch">dispatch a job</a></p></body></html>
HTML;
    $html = strtr($html, [
        '@{{laravel}}' => app()->version(),
        '@{{php}}' => PHP_VERSION,
        '@{{marker}}' => QA_DEPLOY_MARKER,
    ]);

    return response($html);
});

/*
 | One JSON document answering every Laravel-settings question a deploy tool can ask,
 | so a tester can verify scheduler / queue / Horizon / SSR without SSH.
 | Every probe is defensive, so /health stays 200 even when a feature is broken
 | — the payload, not the status code, carries the verdict.
 */
Route::get('/health', function () {
    $composer = json_decode((string) @file_get_contents(base_path('composer.json')), true) ?: [];
    $require = array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);

    return response()->json([
        'app' => 'Laravel QA Site',
        'ok' => true,
        'deploy_marker' => QA_DEPLOY_MARKER,
        'laravel_version' => app()->version(),
        'php_version' => PHP_VERSION,
        'time' => now()->toIso8601String(),

        // The same facts a deploy tool's Laravel feature detection reads out of
        // composer.json, so its result can be diffed against ground truth.
        'detect' => [
            'horizon' => array_key_exists('laravel/horizon', $require),
            'octane' => array_key_exists('laravel/octane', $require),
            'inertia' => array_key_exists('inertiajs/inertia-laravel', $require),
            'ssr_ready' => is_file(base_path('bootstrap/ssr/ssr.js')),
            'maintenance' => is_file(storage_path('framework/down')),
        ],

        'env' => [
            'app_env' => config('app.env'),
            'queue_connection' => config('queue.default'),
            'db_connection' => config('database.default'),
            'redis_client' => config('database.redis.client'),
            'redis_host' => config('database.redis.default.host'),
            'redis_prefix' => config('database.redis.options.prefix'),
            'horizon_prefix' => config('horizon.prefix'),
        ],

        // Written by the scheduled task in routes/console.php. Proves
        // `artisan schedule:run` actually fired — not merely that it is
        // configured to.
        'scheduler' => qa_probe(fn () => ['last_run' => qa_breadcrumb('scheduler')]),

        // Written by QaHeartbeatJob. Proves a worker (or Horizon) drained the
        // queue — not merely that the container is up.
        'queue' => qa_probe(fn () => [
            'pending_jobs' => DB::table('jobs')->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'last_processed' => qa_breadcrumb('queue'),
            // Every attempt QaFailingJob made, so the worker's --tries setting
            // can be checked against how many retries actually happened.
            'recent_failed_attempts' => qa_breadcrumb('queue-failures'),
        ]),

        'redis' => qa_probe(fn () => ['ping' => (string) Redis::connection()->ping()]),
    ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
});

/*
 | Dispatches a job onto the configured queue. Nothing consumes it unless the
 | site's Laravel settings set queue_mode to worker or horizon — which is what
 | makes it a test: pending_jobs stays above zero while the queue is off, and
 | drops to zero once a worker runs.
 */
Route::get('/queue/dispatch', function (Request $request) {
    $id = (string) Str::uuid();

    // ?fail=1 dispatches a job that throws every time, so the failed-job path
    // and the worker's --tries setting are reachable. Without it a worker that
    // silently drops failures looks identical to one that retries correctly.
    $fail = $request->boolean('fail');

    $fail ? QaFailingJob::dispatch($id) : QaHeartbeatJob::dispatch($id);

    return response()->json([
        'dispatched' => true,
        'job_id' => $id,
        'will_fail' => $fail,
        'queue_connection' => config('queue.default'),
        'verify' => url('/health'),
    ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
});

/*
 | The only route that needs a Vite build. With SSR enabled,
 | `php artisan inertia:start-ssr` renders it server-side and the HTML arrives
 | already populated; with SSR off the same page hydrates in the browser.
 | Guarded so an unbuilt site degrades to a 200 explanation, not a 500.
 */
Route::get('/inertia', function () {
    if (! is_file(public_path('build/manifest.json'))) {
        return response()->json([
            'ok' => false,
            'reason' => 'Vite assets not built. Set the site build command to `npm run build` and redeploy.',
            'ssr_bundle_present' => is_file(base_path('bootstrap/ssr/ssr.js')),
        ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    return Inertia::render('Welcome', [
        'marker' => QA_DEPLOY_MARKER,
        'laravel' => app()->version(),
        'php' => PHP_VERSION,
    ]);
});
