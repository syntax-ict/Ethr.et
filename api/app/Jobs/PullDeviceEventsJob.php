<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\DeviceAdapter;
use App\Enums\AttendanceSource;
use App\Events\DeviceOffline;
use App\Events\DeviceSyncFailed;
use App\Models\Device;
use App\Models\DeviceSyncLog;
use App\Models\Tenant;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\CurrentTenant;
use App\Services\Device\DeviceManager;
use App\Services\Identity\IdentityResolver;
use App\Services\Identity\IdentitySignals;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class PullDeviceEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    /** Safety ceiling on pages per run, so a misbehaving adapter can't loop forever. */
    private const MAX_PAGES_PER_RUN = 200;

    public function __construct(
        private readonly Device $device,
        private readonly string $triggeredBy = 'schedule',
        /**
         * A backfill start time (ISO-8601). When set this is a one-off history
         * import from that date rather than an incremental sync, so the device's
         * `last_sync_at` cursor is left untouched. Pulled events are dated at
         * their real event time (AttendanceInput::$occurredAt) and de-duplicated
         * by idempotency key, so a history import can safely overlap live syncs.
         */
        private readonly ?string $sinceOverride = null,
    ) {}

    public function handle(DeviceManager $manager, AttendanceEngine $engine, IdentityResolver $resolver): void
    {
        // A worker resolves no tenant, and the attendance engine and shift
        // matching run through the tenant scope: without this a pulled punch
        // matched no employee or shift and recorded nothing (audit N21). The
        // device is tenant-owned, so its tenant is the one these punches
        // belong to. CurrentTenant is a scoped binding, flushed between jobs.
        $tenant = Tenant::query()->find($this->device->tenant_id);
        if ($tenant !== null) {
            app(CurrentTenant::class)->set($tenant);
        }

        $startedAt = now();
        $syncLog = DeviceSyncLog::create([
            'tenant_id' => $this->device->tenant_id,
            'device_id' => $this->device->id,
            'status' => 'running',
            'triggered_by' => $this->triggeredBy,
            'started_at' => $startedAt,
        ]);

        try {
            $adapter = $manager->adapter($this->device);
            $status = $adapter->getStatus($this->device);

            if (! ($status['online'] ?? false)) {
                $wasOnline = $this->device->status !== 'offline';
                $this->device->update(['status' => 'offline']);

                if ($wasOnline) {
                    DeviceOffline::dispatch($this->device);
                }

                $syncLog->update([
                    'status' => 'offline',
                    'error_message' => 'Device is not reachable',
                    'completed_at' => now(),
                    'duration_ms' => $startedAt->diffInMilliseconds(now()),
                ]);

                return;
            }

            if ($this->device->status !== 'online') {
                $this->device->update(['status' => 'online']);
            }

            // `full` is a history import with no start date, so whether this is
            // a backfill cannot be read off `sinceOverride` alone. It used to be,
            // which sent `full` down the incremental path: one page, then the
            // cursor moved to now.
            $backfill = $this->sinceOverride !== null || $this->triggeredBy === 'history_import';
            $since = $backfill
                ? $this->sinceOverride
                : $this->device->last_sync_at?->format('Y-m-d\TH:i:sP');

            $run = $this->drain($adapter, $since, $startedAt, $engine, $resolver);

            if ($run['outcome'] === 'budget') {
                // The rest goes to a fresh job, which gets its own budget. It
                // carries the cursor as `since`, so it is a backfill and leaves
                // last_sync_at alone; an incremental sync moves it below.
                self::dispatch($this->device, $this->triggeredBy, $run['cursor']);
            }

            // A history backfill must not move the incremental cursor, or the
            // next scheduled sync would skip everything between the backfill
            // window and now. An incremental sync moves it to when this run
            // began, not to now: a punch recorded while the pages were being
            // read is after the last page and belongs to the next sync. If this
            // run handed work on, the continuation covers from its cursor.
            if (! $backfill) {
                $this->device->update(['last_sync_at' => $startedAt]);
            }

            [$found, $processed, $failed] = [$run['found'], $run['processed'], $run['failed']];
            $logStatus = $failed > 0 && $processed > 0 ? 'partial' : ($failed > 0 ? 'failed' : 'success');

            $syncLog->update([
                'status' => $run['outcome'] === 'stalled' ? 'partial' : $logStatus,
                'error_message' => $run['outcome'] === 'stalled'
                    ? "Stopped at {$run['cursor']}: the device returned a full page inside one second, and its API offers no way past it by time."
                    : null,
                'events_found' => $found,
                'events_processed' => $processed,
                'events_failed' => $failed,
                'completed_at' => now(),
                'duration_ms' => $startedAt->diffInMilliseconds(now()),
            ]);

            Log::info('Device event pull complete', [
                'device_id' => $this->device->id,
                'events_found' => $found,
                'processed' => $processed,
                'failed' => $failed,
            ]);
        } catch (Throwable $e) {
            $this->device->update(['status' => 'error']);

            $syncLog->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
                'duration_ms' => $startedAt->diffInMilliseconds(now()),
            ]);

            Log::error('Device sync failed', [
                'device_id' => $this->device->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * All retries are spent and the sync never completed.
     *
     * `handle()` sets the device to `error` and rethrows on every attempt, so
     * the status is already right by the time this runs — what was missing was
     * telling anyone. `docs/CLAUDE.md`'s queue-recovery table claimed this path
     * "triggers DeviceOffline, notifies admin"; in fact `DeviceOffline` fires
     * only from the SUCCESS path, when the adapter reports the device
     * unreachable. So an unreachable device notified an admin and a device
     * whose sync threw twice notified nobody — an asymmetry the table itself
     * flagged as needing a decision rather than a doc edit.
     *
     * The decision taken: notify, but with `DeviceSyncFailed` rather than
     * `DeviceOffline`. `offline` and `error` are different states, the model
     * distinguishes them, and an admin needs to know which one they have — a
     * device that is off is someone else's problem to power on, and a device
     * that is erroring is ours. Collapsing them would throw that away to reuse
     * an event.
     *
     * Dispatched rather than notified inline so the admin lookup stays in a
     * listener beside `NotifyDeviceOffline`, where the tenant-scope bypass such
     * a lookup needs is already justified and pinned.
     */
    public function failed(Throwable $e): void
    {
        DeviceSyncFailed::dispatch($this->device, $e->getMessage());
    }

    /**
     * Page through the device from `$since` until it is empty, the time budget
     * runs out, or the cursor cannot advance.
     *
     * The cursor moves to the latest timestamp on each page, not one second past
     * it. Device APIs disagree on whether `since` is inclusive (Hikvision's
     * `startTime` is), and stepping past the last event lost the punch one
     * second later on a strictly-after API, or a second punch in the same second
     * on an inclusive one. Staying on the last timestamp loses neither. An
     * inclusive API then returns the boundary punches again; they are skipped
     * here, and across runs by the engine's idempotency key.
     *
     * @return array{outcome: 'drained'|'budget'|'stalled', cursor: ?string, found: int, processed: int, failed: int}
     */
    private function drain(DeviceAdapter $adapter, ?string $since, Carbon $startedAt, AttendanceEngine $engine, IdentityResolver $resolver): array
    {
        $deadline = $startedAt->copy()->addSeconds((int) config('devices.pull_time_budget_seconds', 20));
        $cursor = $since;
        $seen = [];
        $largestPage = 0;
        [$found, $processed, $failed] = [0, 0, 0];
        $outcome = 'drained';

        for ($page = 0; ; $page++) {
            if ($page >= self::MAX_PAGES_PER_RUN || now()->greaterThanOrEqualTo($deadline)) {
                $outcome = 'budget';
                break;
            }

            $events = $adapter->pullEvents($this->device, $cursor);
            if ($events === []) {
                break;
            }
            $largestPage = max($largestPage, count($events));

            $fresh = [];
            foreach ($events as $event) {
                $key = $event['employee_badge'].'|'.$event['timestamp'];
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $fresh[] = $event;
                }
            }

            $found += count($fresh);
            [$p, $f] = $this->processEvents($fresh, $engine, $resolver);
            $processed += $p;
            $failed += $f;

            $next = $this->latestTimestamp($events);
            if ($next === null || $next === $cursor) {
                // Nothing on this page is later than the cursor. A short page is
                // the end of the data. A full one, every punch in the same second,
                // means more may be waiting that a time cursor cannot reach.
                $outcome = count($events) >= $largestPage && $largestPage > 1 && $next !== null
                    ? 'stalled'
                    : 'drained';
                break;
            }
            $cursor = $next;

            // Nothing after the moment this run began is its business: the next
            // incremental sync starts its cursor exactly there. Without this, an
            // adapter that never runs dry (MockAdapter invents punches relative
            // to `since`; a device clock running fast does the same) would page
            // forward forever and hand the same endless work to continuations.
            if (strtotime($next) >= $startedAt->getTimestamp()) {
                break;
            }
        }

        return [
            'outcome' => $outcome,
            'cursor' => $cursor,
            'found' => $found,
            'processed' => $processed,
            'failed' => $failed,
        ];
    }

    /**
     * The page's latest timestamp, exactly as the device wrote it, so the
     * device reads its own format back as the next `since`.
     *
     * @param  array<int, array{employee_badge: string, timestamp: string, type: string}>  $events
     */
    private function latestTimestamp(array $events): ?string
    {
        $latest = null;
        $latestAt = null;

        foreach ($events as $event) {
            $at = strtotime($event['timestamp']);
            if ($at !== false && ($latestAt === null || $at > $latestAt)) {
                [$latest, $latestAt] = [$event['timestamp'], $at];
            }
        }

        return $latest;
    }

    /**
     * Resolve and record one page of device events.
     *
     * @param  array<int, array{employee_badge: string, timestamp: string, type: string}>  $events
     * @return array{0: int, 1: int} [processed, failed]
     */
    private function processEvents(array $events, AttendanceEngine $engine, IdentityResolver $resolver): array
    {
        $processed = 0;
        $failed = 0;

        foreach ($events as $event) {
            $badge = $event['employee_badge'];

            // Resolve the device's user id to a master employee. A prior mapping
            // resolves instantly; otherwise the badge is scored against
            // employee_code / badge_number. Only an actionable match is recorded —
            // an ambiguous or unknown badge is left for manual review rather than
            // guessed at (never create/attach blindly).
            $signals = new IdentitySignals(
                employeeCode: $badge,
                badgeNumber: $badge,
                sourceType: $this->device->adapter_type,
                sourceRef: 'device:'.$this->device->id,
                identifierType: 'device_user_id',
                identifierValue: $badge,
            );

            $match = $resolver->resolve($this->device->tenant_id, $signals);

            if (! $match->isActionable()) {
                $failed++;
                Log::info('Device event: no confident employee match', [
                    'device_id' => $this->device->id,
                    'badge' => $badge,
                    'outcome' => $match->outcome,
                    'candidates' => $match->candidates,
                ]);

                continue;
            }

            $employee = $match->employee;

            // Remember the mapping so future events from this device resolve in
            // one lookup. Auto-links stay unverified for later admin review.
            $resolver->link($employee, $signals, $match->confidence);

            $idempotencyKey = "device:{$this->device->id}:{$badge}:{$event['timestamp']}";

            try {
                $engine->record(new AttendanceInput(
                    employeeId: $employee->id,
                    tenantId: $this->device->tenant_id,
                    source: AttendanceSource::BIOMETRIC,
                    type: $event['type'],
                    idempotencyKey: $idempotencyKey,
                    deviceId: $this->device->id,
                    occurredAt: $event['timestamp'],
                ));
                $processed++;
            } catch (Throwable $e) {
                $failed++;
                Log::warning('Device event processing failed', [
                    'device_id' => $this->device->id,
                    'badge' => $badge,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [$processed, $failed];
    }
}
