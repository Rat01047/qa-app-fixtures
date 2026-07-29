<?php

namespace App;

class App
{
    public const NAME = 'PHP QA Site';

    /** Bumped by hand to prove a redeploy actually pulled new commits. */
    public const DEPLOY_MARKER = 'v2';

    public static function status(): array
    {
        return [
            'app' => self::NAME,
            'ok' => true,
            'deploy_marker' => self::DEPLOY_MARKER,
            'php_version' => PHP_VERSION,
            'time' => date('c'),

            // A plain PHP app still has package.json, so the Node install step
            // runs and is followed by the site's build command. This reports
            // whether that Node step actually ran on the server.
            'build' => self::buildStamp(),

            // The host injects no .env for PHP sites and this repo ships neither
            // .env nor .env.example, so the Environment editor has nothing to
            // read. Reported here so a deploy tool's behaviour can be compared
            // against ground truth.
            'env_file' => [
                'dotenv_present' => is_file(dirname(__DIR__).'/.env'),
                'dotenv_example_present' => is_file(dirname(__DIR__).'/.env.example'),
            ],
        ];
    }

    /**
     * Written by `npm run build` (see build.mjs). Absent means the Node build
     * never ran — either no build command is configured or it failed.
     */
    private static function buildStamp(): array
    {
        $path = dirname(__DIR__).'/public/assets/build-stamp.json';

        if (! is_file($path)) {
            return [
                'ran' => false,
                'hint' => 'Set the site build command to `npm run build` and redeploy.',
            ];
        }

        return ['ran' => true] + (json_decode((string) file_get_contents($path), true) ?: []);
    }
}
