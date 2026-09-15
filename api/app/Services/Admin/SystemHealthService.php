<?php

declare(strict_types=1);

namespace App\Services\Admin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class SystemHealthService
{
    public function check(): array
    {
        return [
            'services' => [
                'api' => $this->apiStatus(),
                'database' => $this->databaseStatus(),
                'redis' => $this->redisStatus(),
                'storage' => $this->storageStatus(),
                'reverb' => $this->broadcastStatus(),
            ],
            'queue' => $this->queueStatus(),
            'failed_jobs' => $this->failedJobsCount(),
            'resources' => $this->resourceUsage(),
        ];
    }

    private function apiStatus(): array
    {
        return ['status' => 'healthy', 'response_ms' => 0];
    }

    private function databaseStatus(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $ms = (int) ((microtime(true) - $start) * 1000);

            return ['status' => 'healthy', 'response_ms' => $ms];
        } catch (Throwable $e) {
            return ['status' => 'unhealthy', 'error' => $e->getMessage()];
        }
    }

    private function redisStatus(): array
    {
        try {
            $start = microtime(true);
            Cache::put('_health_check', 1, 5);
            Cache::get('_health_check');
            $ms = (int) ((microtime(true) - $start) * 1000);

            return ['status' => 'healthy', 'response_ms' => $ms];
        } catch (Throwable $e) {
            return ['status' => 'unhealthy', 'error' => $e->getMessage()];
        }
    }

    /**
     * Whether broadcasting is configured, rather than whether Reverb is up.
     *
     * This row used to read "check Horizon", which was wrong twice over: Horizon
     * is a queue dashboard and never had anything to say about the WebSocket
     * server, and Horizon has since been removed entirely (it hard-required
     * ext-pcntl and ext-posix, which shared hosting does not provide).
     *
     * On the shared-hosting target Reverb is not deployed at all - no shared
     * tier offers a WebSocket server - so BROADCAST_CONNECTION is `log` and
     * "disabled" is the correct, healthy answer rather than a fault.
     */
    private function broadcastStatus(): array
    {
        $connection = config('broadcasting.default');

        if (! in_array($connection, ['reverb', 'pusher'], true)) {
            return ['status' => 'disabled', 'driver' => (string) $connection];
        }

        return ['status' => 'unknown', 'driver' => (string) $connection,
            'note' => 'configured, but liveness is not probed from here'];
    }

    private function storageStatus(): array
    {
        try {
            // Resolve from configuration, not a hardcoded name. This was the one
            // site 80cac67 missed when it fixed the other four, so on any
            // non-MinIO deployment the admin health page showed storage
            // permanently red - and a panel that is always red is a panel people
            // stop reading, which is worse than no panel at all.
            $disk = Storage::disk(config('filesystems.default'));
            $start = microtime(true);
            // Lightweight check: list root (may throw if MinIO is down)
            $disk->directories('/');
            $ms = (int) ((microtime(true) - $start) * 1000);

            return ['status' => 'healthy', 'response_ms' => $ms];
        } catch (Throwable $e) {
            return ['status' => 'unhealthy', 'error' => $e->getMessage()];
        }
    }

    private function queueStatus(): array
    {
        // The queues this application actually dispatches onto. It used to poll
        // 'default', 'high' and 'low' - names nothing in the codebase uses - so
        // the panel reported depth for three queues that do not exist while
        // ignoring the four that do. A bare `queue:work` reads only `default`
        // (DB_QUEUE), so a misconfigured worker starves the other three; this is
        // the panel that has to make that visible.
        $queues = ['default', 'attendance', 'notifications', 'exports'];
        $depths = [];

        foreach ($queues as $queue) {
            try {
                $size = Queue::size($queue);
                $depths[$queue] = ['depth' => $size];
            } catch (Throwable) {
                $depths[$queue] = ['depth' => null, 'error' => 'unavailable'];
            }
        }

        return $depths;
    }

    private function failedJobsCount(): int
    {
        try {
            return (int) DB::table('failed_jobs')->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function resourceUsage(): array
    {
        return [
            'php_memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 1),
            'php_peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
            'disk_free_gb' => function_exists('disk_free_space') && disk_free_space('/') !== false
                ? round(disk_free_space('/') / 1024 / 1024 / 1024, 1)
                : null,
        ];
    }
}
