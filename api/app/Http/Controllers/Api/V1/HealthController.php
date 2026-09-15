<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Observability\QueueHealth;
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

        // `services.queue` stays a REACHABILITY check, and deliberately so.
        //
        // This endpoint answers one question for one audience: can an uptime
        // monitor still reach a working instance? Its non-200 means "the site
        // is down, page someone". A scheduler that stopped an hour ago is a
        // real problem and emphatically not that one — the site is up, requests
        // are served, and somebody needs to look at cron in the morning.
        //
        // Wiring scheduler staleness into this status was tried and reverted in
        // the same change. It made /health return 503 on any deployment whose
        // scheduler had not yet beaten — including every fresh install, before
        // the first `schedule:run`. That is the failure this file already warns
        // about twice above: a probe that cries wolf gets muted, and then it is
        // worth nothing when the site really is down.
        //
        // The liveness picture is still published, under `queue_detail`, for
        // anything that wants it. The thing that acts on it is
        // `ethr:queue:check`, which runs from cron and exits non-zero — see
        // docs/operations/QUEUE-MONITORING.md.
        try {
            DB::table('jobs')->count();
            $services['queue'] = 'healthy';
        } catch (\Throwable) {
            $services['queue'] = 'unavailable';
        }

        try {
            $queueDetail = app(QueueHealth::class)->snapshot();
        } catch (\Throwable) {
            $queueDetail = null;
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

        // Outside `services` on purpose: everything in there feeds the 503
        // above, and scheduler staleness must not.
        return response()->json([
            'status' => $allHealthy ? 'healthy' : 'degraded',
            'services' => $services,
            'queue_detail' => $queueDetail,
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
