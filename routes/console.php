<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 | Scheduler proof. When the site's scheduler is enabled the host runs
 | `php artisan schedule:run` at the configured interval; this task records that
 | it fired, and /health reads the breadcrumb back. Without it, "the scheduler
 | works" could only be inferred from configuration rather than observed.
 */
Schedule::call(function () {
    $dir = storage_path('app/qa');

    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $previous = is_file($dir.'/scheduler.json')
        ? json_decode((string) file_get_contents($dir.'/scheduler.json'), true)
        : null;

    file_put_contents($dir.'/scheduler.json', json_encode([
        'ran_at' => now()->toIso8601String(),
        'run_count' => ($previous['run_count'] ?? 0) + 1,
        'hostname' => gethostname(),
    ], JSON_PRETTY_PRINT));
})->everyMinute()->name('qa-scheduler-heartbeat');
