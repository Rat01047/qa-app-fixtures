<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Leaves a breadcrumb on disk when a queue worker picks it up, so /health can
 * prove the worker (or Horizon) really drained the queue rather than just
 * reporting that the container is running.
 */
class QaHeartbeatJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $jobId) {}

    public function handle(): void
    {
        $dir = storage_path('app/qa');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($dir.'/queue.json', json_encode([
            'job_id' => $this->jobId,
            'processed_at' => now()->toIso8601String(),
            'hostname' => gethostname(),
            'connection' => config('queue.default'),
        ], JSON_PRETTY_PRINT));
    }
}
