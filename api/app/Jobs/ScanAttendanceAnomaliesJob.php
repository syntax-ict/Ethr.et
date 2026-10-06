<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AttendanceAnomalyNotification;
use App\Services\Attendance\AttendanceIntelligence;
use App\Services\CurrentTenant;
use App\Traits\SendsNotifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * PHASE_05 S26: "AttendanceAnomalyNotification → supervisor (in-app)".
 *
 * Two halves were both dead: the notification class was never constructed, and
 * `AttendanceIntelligence::detectAnomalies()` — which decides what counts as an
 * anomaly — had no caller anywhere in the codebase. Excessive hours and excessive
 * overtime were detectable in principle and surfaced to nobody in practice.
 *
 * Runs per tenant on the same daily cadence as the missing-punch scan, and for
 * the same reason: the previous day's records are settled by then.
 */
class ScanAttendanceAnomaliesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SendsNotifications, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        private readonly int $tenantId,
        private readonly string $date,
    ) {}

    public function handle(AttendanceIntelligence $intelligence, CurrentTenant $currentTenant): void
    {
        $tenant = Tenant::find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $currentTenant->set($tenant);

        $records = AttendanceRecord::query()
            ->whereDate('date', $this->date)
            // detectAnomalies() reads $record->shift on its first line, so the
            // shift has to come along or the whole scan dies on the lazy-loading
            // guard — the same ('employee', 'shift') pairing every attendance
            // controller uses.
            ->with('employee.supervisor.user', 'shift')
            ->get();

        $notified = 0;

        foreach ($records as $record) {
            $anomalies = $intelligence->detectAnomalies($record);

            if ($anomalies === []) {
                continue;
            }

            $employee = $record->employee;
            $supervisor = $employee?->supervisor;
            $approver = $supervisor instanceof Employee ? $supervisor->user : null;

            if (! $approver instanceof User) {
                continue;
            }

            foreach ($anomalies as $anomaly) {
                $this->notify($approver, new AttendanceAnomalyNotification(
                    $employee->name,
                    $anomaly,
                    $this->date,
                ));
                $notified++;
            }
        }

        if ($notified > 0) {
            Log::info('Attendance anomalies notified', [
                'tenant_id' => $this->tenantId,
                'date' => $this->date,
                'notifications' => $notified,
            ]);
        }
    }
}
