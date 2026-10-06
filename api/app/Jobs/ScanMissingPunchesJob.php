<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\MissingPunchNotification;
use App\Services\Attendance\AttendanceIntelligence;
use App\Services\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs daily after business hours. Scans the previous workday for employees
 * who checked in but never checked out (or vice-versa), then notifies the
 * employee and their supervisor.
 *
 * Dispatched per-tenant so each tenant gets isolated processing and errors
 * don't cascade between tenants.
 */
class ScanMissingPunchesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        private readonly int $tenantId,
        private readonly string $date, // Y-m-d of the day to scan
    ) {}

    public function handle(AttendanceIntelligence $intelligence, CurrentTenant $currentTenant): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

        $currentTenant->set($tenant);

        $date = Carbon::parse($this->date);
        $missing = $intelligence->getMissingPunches($this->tenantId, $this->date);

        if ($missing->isEmpty()) {
            return;
        }

        Log::info('ScanMissingPunches: found missing punches', [
            'tenant_id' => $this->tenantId,
            'date' => $this->date,
            'count' => $missing->count(),
        ]);

        foreach ($missing as $record) {
            /** @var Employee|null $employee */
            $employee = $record->employee;
            if (! $employee) {
                continue;
            }

            $type = $record->check_in ? 'missing_check_out' : 'missing_check_in';

            // Notify the employee (via their linked user account)
            if ($employee->user) {
                $employee->user->notify(
                    (new MissingPunchNotification($employee, $this->date, $type))
                        ->onQueue('notifications')
                );
            }

            // Notify their supervisor
            if ($employee->supervisor_id) {
                $supervisor = Employee::withoutGlobalScope('tenant')
                    ->where('id', $employee->supervisor_id)
                    ->first();

                if ($supervisor?->user) {
                    $supervisor->user->notify(
                        (new MissingPunchNotification($employee, $this->date, $type))
                            ->onQueue('notifications')
                    );
                }
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        // Self-healing: missing punches for this date are still catchable by
        // a later corrections review. Visible in the admin failed-jobs
        // dashboard; no alert needed.
        Log::error('ScanMissingPunchesJob failed', [
            'tenant_id' => $this->tenantId,
            'date' => $this->date,
            'error' => $exception->getMessage(),
        ]);
    }
}
