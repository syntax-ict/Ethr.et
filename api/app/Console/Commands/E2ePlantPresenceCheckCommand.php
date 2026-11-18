<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AttendancePresenceCheck;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Puts a presence check on a checked-in employee's phone, now, for the
 * attendance browser journeys (src/e2e/attendance-payroll/presence-check.spec.ts).
 *
 *     php artisan e2e:plant-presence-check e2e-att ATT-0005 --slot=0
 *
 * A check lives for 15 minutes and is sent at a moment the scheduler derives,
 * so a journey cannot wait for one: it plants the row the push would have
 * created and then answers it from the browser. Like E2eAttendanceSeeder it is
 * for local and testing only and refuses anywhere else.
 */
class E2ePlantPresenceCheckCommand extends Command
{
    protected $signature = 'e2e:plant-presence-check
        {tenant : The organisation\'s address, such as e2e-att}
        {employee : The employee code}
        {--slot=0 : The slot; one record holds one check per slot}';

    protected $description = 'Plant a pending presence check for an end-to-end journey (local and testing only)';

    public function handle(CurrentTenant $currentTenant): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('e2e:plant-presence-check plants checks for browser journeys; it runs in local and testing only.');

            return self::FAILURE;
        }

        $tenant = Tenant::where('subdomain', (string) $this->argument('tenant'))->first();
        if ($tenant === null) {
            $this->error('No organisation has that address.');

            return self::FAILURE;
        }
        $currentTenant->set($tenant);

        $employee = Employee::query()->where('employee_code', (string) $this->argument('employee'))->first();
        $record = $employee === null ? null : AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->latest('check_in')
            ->first();
        if ($employee === null || $record === null) {
            $this->error('That employee does not exist, or is not checked in.');

            return self::FAILURE;
        }

        $now = Carbon::now('UTC');
        $check = AttendancePresenceCheck::query()->updateOrCreate(
            ['attendance_record_id' => $record->id, 'slot' => (int) $this->option('slot')],
            [
                'public_id' => (string) Str::ulid(),
                'tenant_id' => $tenant->id,
                'employee_id' => $employee->id,
                'sent_at' => $now->copy()->subMinute(),
                'expires_at' => $now->copy()->addMinutes(14),
                'answered_at' => null,
                'status' => AttendancePresenceCheck::SENT,
            ],
        );

        $this->info("Planted presence check {$check->public_id}, open until {$check->expires_at->toIso8601String()}.");

        return self::SUCCESS;
    }
}
