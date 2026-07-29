<?php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Acceptance page — no Twig, no DB and no built assets, so `/` returns 200
 * after `composer install` alone. Anything that needs the Node build lives on
 * /assets, so a missing or failed build can never take the acceptance page
 * down.
 */
class HomeController extends AbstractController
{
    /** Bumped by hand to prove a redeploy actually pulled new commits. */
    public const DEPLOY_MARKER = 'v4';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $appEnv,
    ) {
    }

    /**
     * Every log line is stamped with the environment that wrote it.
     *
     * That stamp is the whole point. A log viewer that resolves the wrong file
     * name still shows *a* file, and if both files merely contained generic
     * text the mistake would read as a pass. Because each line names its own
     * environment, "the viewer opened dev.log while APP_ENV=prod" is visible in
     * the content rather than inferable only from the file name.
     */
    private function logVisit(string $route): void
    {
        $this->logger->info(sprintf(
            'QA visit env=%s route=%s marker=%s symfony=%s php=%s',
            $this->appEnv,
            $route,
            self::DEPLOY_MARKER,
            Kernel::VERSION,
            PHP_VERSION,
        ));
    }

    #[Route('/', name: 'home')]
    public function home(): Response
    {
        $this->logVisit('/');

        $sf = Kernel::VERSION;
        $php = PHP_VERSION;
        $marker = self::DEPLOY_MARKER;
        $env = $this->appEnv;
        $logFile = 'var/log/'.$env.'.log';
        $html = <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Symfony QA Site</title>
<style>body{font-family:system-ui,sans-serif;max-width:640px;margin:4rem auto;padding:0 1rem}
h1{color:#000}code{background:#f3f4f6;padding:.15rem .4rem;border-radius:4px}
.ok{color:#16a34a;font-weight:600}</style></head>
<body><h1>Symfony QA Site</h1>
<p class="ok">&#10003; Deploy OK — Symfony</p>
<p>Symfony: <code>{$sf}</code> · PHP: <code>{$php}</code> · Marker: <code>{$marker}</code></p>
<p>APP_ENV: <code>{$env}</code> · application log: <code>{$logFile}</code></p>
<p>Health JSON: <a href="/health">/health</a> · Built assets: <a href="/assets">/assets</a></p>
<p>Write a log line: <a href="/log-test">/log-test</a></p>
</body></html>
HTML;

        return new Response($html);
    }

    /**
     * One JSON document answering the questions a deploy tool's Symfony support
     * has to get right. Always 200 — the payload carries the verdict, never the
     * status code, so a broken sub-feature is still readable by a probe.
     */
    #[Route('/health', name: 'health')]
    public function health(): JsonResponse
    {
        $this->logVisit('/health');

        $root = \dirname(__DIR__, 2);

        return new JsonResponse([
            'app' => 'Symfony QA Site',
            'ok' => true,
            'deploy_marker' => self::DEPLOY_MARKER,
            'symfony_version' => Kernel::VERSION,
            'php_version' => PHP_VERSION,
            'time' => date('c'),

            // This branch commits a real .env, unlike the `php` branch which
            // ships neither .env nor .env.example. Between them they cover both
            // sides of "what does the environment editor have to read?".
            'env_file' => [
                'dotenv_present' => is_file($root.'/.env'),
                'dotenv_example_present' => is_file($root.'/.env.example'),
                'app_env' => $_ENV['APP_ENV'] ?? null,
                'app_debug' => $_ENV['APP_DEBUG'] ?? null,
            ],

            'logs' => $this->logReport($root),

            // Webpack Encore writes public/build/entrypoints.json. Its presence
            // is the proof that the Node build step actually ran on the server,
            // as opposed to being configured and silently skipped.
            'node_build' => [
                'expected' => true,
                'package_json_present' => is_file($root.'/package.json'),
                'lockfile_present' => is_file($root.'/package-lock.json'),
                'entrypoints_present' => is_file($root.'/public/build/entrypoints.json'),
                'manifest_present' => is_file($root.'/public/build/manifest.json'),
            ],

            'writable' => [
                'var' => is_writable($root.'/var'),
            ],
        ]);
    }

    /**
     * Writes one line at every level and reports where it went.
     *
     * Gives a tester a way to make the application log change on demand, so
     * "the viewer is showing live content" can be told apart from "the viewer
     * is showing a stale file it read once".
     */
    #[Route('/log-test', name: 'log_test')]
    public function logTest(): JsonResponse
    {
        $stamp = date('c');

        foreach (['debug', 'info', 'notice', 'warning', 'error'] as $level) {
            $this->logger->{$level}(sprintf(
                'QA log-test level=%s env=%s marker=%s at=%s',
                $level,
                $this->appEnv,
                self::DEPLOY_MARKER,
                $stamp,
            ));
        }

        return new JsonResponse([
            'ok' => true,
            'wrote_levels' => ['debug', 'info', 'notice', 'warning', 'error'],
            'stamp' => $stamp,
            'app_env' => $this->appEnv,
            'expected_file' => 'var/log/'.$this->appEnv.'.log',
            'note' => 'Under APP_ENV=prod the same lines are also written to php://stderr, which lands in the web server error log rather than in var/log.',
            'logs' => $this->logReport(\dirname(__DIR__, 2)),
        ]);
    }

    /**
     * What is actually on disk under var/log, and what the current environment
     * says should be there.
     *
     * `expected_file` is derived from APP_ENV the same way monolog.yaml derives
     * it from `%kernel.environment%`, so a viewer that hardcodes "prod.log" can
     * be caught by comparing the two without SSH access.
     */
    private function logReport(string $root): array
    {
        $dir = $root.'/var/log';
        $expected = $this->appEnv.'.log';

        $files = [];
        foreach (glob($dir.'/*.log') ?: [] as $path) {
            $files[basename($path)] = [
                'bytes' => filesize($path) ?: 0,
                'modified' => date('c', filemtime($path) ?: 0),
                'last_line' => self::lastLine($path),
            ];
        }

        ksort($files);

        return [
            'dir' => 'var/log',
            'dir_exists' => is_dir($dir),
            'dir_writable' => is_dir($dir) && is_writable($dir),
            'app_env' => $this->appEnv,

            // The name a log viewer must resolve at runtime. Hardcoding
            // "prod.log" gives an empty pane on a dev deployment.
            'expected_file' => $expected,
            'expected_file_present' => isset($files[$expected]),
            'expected_file_bytes' => $files[$expected]['bytes'] ?? 0,

            // True on a stock Symfony app in prod: upstream's monolog recipe
            // writes to php://stderr and produces no file. This branch adds a
            // file handler as well, so prod.log does fill here — but a customer
            // app with an empty prod.log is behaving normally, not broken.
            'prod_also_writes_stderr' => true,

            'files_present' => array_keys($files),
            'files' => $files,
        ];
    }

    /** Last non-empty line of a file, read from the tail rather than the whole file. */
    private static function lastLine(string $path): ?string
    {
        $size = filesize($path) ?: 0;
        if ($size === 0) {
            return null;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        $read = min($size, 8192);
        fseek($handle, -$read, SEEK_END);
        $tail = (string) fread($handle, $read);
        fclose($handle);

        $lines = array_values(array_filter(array_map('trim', explode("\n", $tail)), 'strlen'));

        return $lines === [] ? null : end($lines);
    }

    /**
     * The only route that needs `npm ci && npm run build`. Guarded so an
     * unbuilt site degrades to a 200 explanation instead of the 500 Encore
     * would otherwise throw on a missing entrypoints.json.
     */
    #[Route('/assets', name: 'assets')]
    public function assets(): Response
    {
        $this->logVisit('/assets');

        $root = \dirname(__DIR__, 2);

        if (!is_file($root.'/public/build/entrypoints.json')) {
            return new JsonResponse([
                'ok' => false,
                'reason' => 'Encore assets not built. Set the build command to `npm ci && npm run build` and redeploy.',
                'deploy_marker' => self::DEPLOY_MARKER,
            ]);
        }

        return $this->render('assets.html.twig', [
            'marker' => self::DEPLOY_MARKER,
            'symfony' => Kernel::VERSION,
            'php' => PHP_VERSION,
        ]);
    }
}
