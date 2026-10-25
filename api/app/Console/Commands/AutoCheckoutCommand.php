<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\BranchAttendanceOverride;
use App\Models\Tenant;
use App\Notifications\AttendanceAutoCheckedOutNotification;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendancePolicy;
use App\Services\Attendance\MyShiftService;
use App\Services\CurrentTenant;
use App\Support\TenantTime;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Closes check-ins left open past the end of the shift, for organisations
 * that turned auto check-out on.
 *
 * The check-out is set to the shift's scheduled end, not to the moment this
 * runs: the scheduler is driven by an external caller and can run hours late,
 * and "17:30" is a truthful default where "21:14" is not. The record is
 * flagged (`metadata.auto_checkout`), audited, and the employee is told and
 * pointed at corrections. A check-in made after the shift had already ended
 * is left alone - there is no scheduled end to close it at.
 *
 * Idempotent: a record it closed is no longer open, so a late or repeated run
 * changes nothing twice.
 */
class AutoCheckoutCommand extends Command
{
    protected $signature = 'attendance:auto-checkout';

    protected $description = 'Close check-ins left open past the end of the shift (where enabled)';

    private AttendancePolicy $policy;

    public function handle(CurrentTenant $currentTenant, AttendanceEngine $engine, AttendancePolicy $policy): int
    {
        $this->policy = $policy;
        $closed = 0;

        Tenant::operational()->each(function (Tenant $tenant) use ($currentTenant, $engine, &$closed): void {
            $currentTenant->set($tenant);

            try {
                // Off at the organisation can still be on at a branch
                // (AttendancePolicy), so a tenant is skipped only when neither
                // turns it on.
                $settings = AttendanceSetting::query()->first();
                $anyBranch = BranchAttendanceOverride::query()
                    ->where('overrides->auto_checkout_enabled', true)
                    ->exists();
                if ($settings === null || (! $settings->auto_checkout_enabled && ! $anyBranch)) {
                    return;
                }

                $closed += $this->closeFor($tenant, $settings, $engine);
            } catch (Throwable $e) {
                // One organisation's bad data must not stop the others.
                report($e);
            } finally {
                $currentTenant->forget();
            }
        });

        $this->info("Auto checked out {$closed} record(s).");

        return self::SUCCESS;
    }

    private function closeFor(Tenant $tenant, AttendanceSetting $settings, AttendanceEngine $engine): int
    {
        $zone = TenantTime::zone($tenant);
        $now = Carbon::now('UTC');
        $closed = 0;

        AttendanceRecord::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->whereNotNull('shift_id')
            // Bounded: anything older is a missing punch for a person to
            // resolve, not something to close automatically.
            ->where('check_in', '>=', $now->copy()->subDays(2))
            ->with(['shift', 'employee.user'])
            ->chunkById(200, function ($records) use ($zone, $now, $settings, $engine, &$closed): void {
                foreach ($records as $record) {
                    /** @var AttendanceRecord $record */
                    if ($record->shift === null || $record->date === null) {
                        continue;
                    }

                    // The rules for this person: their branch may differ.
                    $policy = $record->employee !== null
                        ? $this->policy->for($record->employee, $settings)
                        : $settings;
                    if (! $policy->auto_checkout_enabled) {
                        continue;
                    }

                    $day = Carbon::parse($record->date->format('Y-m-d'), $zone);
                    [, $end] = MyShiftService::window($record->shift, $day);
                    $endUtc = $end->copy()->utc();

                    if ($endUtc->copy()->addMinutes($policy->auto_checkout_after_minutes)->gt($now)) {
                        continue;
                    }
                    if ($endUtc->lte($record->check_in)) {
                        continue;
                    }

                    $record->update([
                        'check_out' => $endUtc,
                        'metadata' => array_merge($record->metadata ?? [], [
                            'auto_checkout' => true,
                            'auto_checkout_at' => $now->toIso8601String(),
                        ]),
                    ]);
                    $engine->reclassify($record);

                    AuditLog::record('attendance.auto_checkout', $record, [
                        'shift_end' => $endUtc->toIso8601String(),
                    ]);

                    $user = $record->employee?->user;
                    if ($user !== null) {
                        $user->notify(new AttendanceAutoCheckedOutNotification(
                            $record,
                            $end->format('H:i'),
                        ));
                    }

                    $closed++;
                }
            });

        return $closed;
    }
}
