<?php

declare(strict_types=1);

namespace App\Services\Admin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class SystemHealthService
{
    public function check(): array
    {
        return [
            'services' => [
                'api'      => $this->apiStatus(),
                'database' => $this->databaseStatus(),
                'redis'    => $this->redisStatus(),
                'storage'  => $this->storageStatus(),
                'reverb'   => ['status' => 'unknown', 'note' => 'WebSocket server — check Horizon'],
            ],
            'queue'       => $this->queueStatus(),
            'failed_jobs' => $this->failedJobsCount(),
            'resources'   => $this->resourceUsage(),
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

    private function storageStatus(): array
    {
        try {
            $disk = Storage::disk('minio');
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
        $queues = ['default', 'high', 'low'];
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
