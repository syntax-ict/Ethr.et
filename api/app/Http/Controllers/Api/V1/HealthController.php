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

        $services['database_read'] = $this->checkReadReplica();

        // The configured cache store, not `redis` by name.
        //
        // Hardcoding the store meant this probe answered a question nobody
        // asked: "is Redis reachable?" rather than "is the cache this
        // application actually uses working?". On any deployment that sets
        // CACHE_STORE to something else — `database` on shared hosting, where
        // there is no Redis at all — it reported the cache permanently
        // `unavailable` while the real cache was fine, so the one endpoint an
        // uptime monitor watches would have cried wolf forever.
        try {
            cache()->store()->put('health_check', true, 5);
            $services['cache'] = cache()->store()->get('health_check') ? 'healthy' : 'unhealthy';
        } catch (\Throwable) {
            $services['cache'] = 'unavailable';
        }

        try {
            Queue::size('default');
            $services['queue'] = 'healthy';
        } catch (\Throwable) {
            $services['queue'] = 'unavailable';
        }

        // Same reasoning as the cache probe: check the disk the application
        // stores files on, which is what FileStorageService now resolves too.
        try {
            Storage::disk()->exists('.health');
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

    private function checkReadReplica(): string
    {
        // The connection in use, not `mariadb` by name. Same defect class as the
        // cache and storage probes above: on a deployment running the `mysql`
        // connection this inspected a connection the app never opens, so a
        // configured replica reported `not_configured` and an absent one
        // reported the same — the probe could not distinguish them.
        $connection = DB::connection()->getName();

        if (! config("database.connections.{$connection}.read")) {
            return 'not_configured';
        }

        try {
            $readPdo = DB::connection()->getReadPdo();
            $readPdo->query('SELECT 1');

            return 'healthy';
        } catch (\Throwable) {
            return 'unhealthy';
        }
    }
}
