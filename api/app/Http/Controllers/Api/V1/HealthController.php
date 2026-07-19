<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $services = [];

        try {
            DB::connection()->getPdo();
            $services['database'] = 'healthy';
        } catch (\Throwable) {
            $services['database'] = 'unhealthy';
        }

        try {
            cache()->store('redis')->put('health_check', true, 5);
            $services['cache'] = cache()->store('redis')->get('health_check') ? 'healthy' : 'unhealthy';
        } catch (\Throwable) {
            $services['cache'] = 'unavailable';
        }

        try {
            Queue::size('default');
            $services['queue'] = 'healthy';
        } catch (\Throwable) {
            $services['queue'] = 'unavailable';
        }

        try {
            Storage::disk('minio')->exists('.health');
            $services['storage'] = 'healthy';
        } catch (\Throwable) {
            $services['storage'] = 'unavailable';
        }

        $services['api'] = 'healthy';

        $allHealthy = ! in_array('unhealthy', $services, true);

        return response()->json([
            'status' => $allHealthy ? 'healthy' : 'degraded',
            'services' => $services,
            'timestamp' => now()->toIso8601String(),
            'version' => '1.0.0',
        ], $allHealthy ? 200 : 503);
    }
}
