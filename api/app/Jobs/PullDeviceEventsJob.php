<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AttendanceSource;
use App\Events\DeviceOffline;
use App\Events\DeviceSyncFailed;
use App\Models\Device;
use App\Models\DeviceSyncLog;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
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

    /** Safety ceiling on history-backfill pagination, so a misbehaving adapter can't loop forever. */
    private const MAX_HISTORY_PAGES = 200;

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

            $since = $this->sinceOverride ?? $this->device->last_sync_at?->format('Y-m-d\TH:i:sP');

            $processed = 0;
            $failed = 0;
            $found = 0;

            if ($this->sinceOverride !== null) {
                // History backfill: drain the device across pages, advancing the
                // cursor past the last event of each page. Device APIs cap a
                // response (~100 events), so a single pull would silently
                // truncate a long backfill.
                $cursor = $since;

                for ($page = 0; $page < self::MAX_HISTORY_PAGES; $page++) {
                    $events = $adapter->pullEvents($this->device, $cursor);
                    if ($events === []) {
                        break;
                    }

                    $found += count($events);
                    [$p, $f] = $this->processEvents($events, $engine, $resolver);
                    $processed += $p;
                    $failed += $f;

                    $maxTimestamp = max(array_column($events, 'timestamp'));
                    $next = Carbon::parse($maxTimestamp)->addSecond()->format('Y-m-d\TH:i:sP');
                    if ($next === $cursor) {
                        break; // no forward progress — stop rather than loop
                    }
                    $cursor = $next;
                }
            } else {
                $events = $adapter->pullEvents($this->device, $since);
                $found = count($events);
                [$processed, $failed] = $this->processEvents($events, $engine, $resolver);
            }

            // A history backfill must not move the incremental cursor — otherwise
            // the next scheduled sync would skip everything between the backfill
            // window and now.
            if ($this->sinceOverride === null) {
                $this->device->update(['last_sync_at' => now()]);
            }

            $logStatus = $failed > 0 && $processed > 0 ? 'partial' : ($failed > 0 ? 'failed' : 'success');

            $syncLog->update([
                'status' => $logStatus,
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
