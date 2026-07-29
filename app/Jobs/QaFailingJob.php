<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Throws on purpose so the failed-job path is reachable.
 *
 * Without it, `failed_jobs` is always empty and the worker's --tries setting is
 * unfalsifiable: a worker that silently drops failures looks identical to one
 * that retries correctly. Each attempt appends to a breadcrumb, so /health can
 * show that the configured number of retries actually happened.
 */
class QaFailingJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $jobId, public int $tries = 3) {}

    public function handle(): void
    {
        $dir = storage_path('app/qa');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = $dir.'/queue-failures.json';
        $log = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $log = is_array($log) ? $log : [];

        $log[] = [
            'job_id' => $this->jobId,
            'attempt' => $this->attempts(),
            'attempted_at' => now()->toIso8601String(),
            'hostname' => gethostname(),
        ];

        // Keep the file bounded; only the recent tail is ever interesting.
        file_put_contents($path, json_encode(array_slice($log, -20), JSON_PRETTY_PRINT));

        throw new RuntimeException("QaFailingJob {$this->jobId} failed on purpose (attempt {$this->attempts()}).");
    }
}
