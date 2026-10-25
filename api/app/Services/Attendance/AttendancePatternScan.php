<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceIntegrityFlag;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Tenant;
use App\Support\TenantTime;
use Illuminate\Support\Carbon;

/**
 * Patterns no single punch shows, found once a day across the last two weeks
 * and filed in the integrity queue (the anomaly scan before it only sent a
 * notification and kept nothing):
 *
 *  - buddy_pattern: two people punched in on the same install of the app
 *    within a minute of each other, three days or more. One phone checking
 *    in a friend is the commonest way attendance is cheated.
 *  - grace_edge: eight or more of someone's last ten arrivals in the last
 *    three minutes before they would count as late. Not cheating in itself,
 *    and flagged low: it is how a reviewer notices a clock that is being
 *    gamed.
 *
 * Idempotent: a finding is filed on the person's latest record in the window
 * and updated, not repeated, by the next run.
 */
final class AttendancePatternScan
{
    private const WINDOW_DAYS = 14;

    private const BUDDY_SECONDS = 60;

    private const BUDDY_DAYS = 3;

    /** @return array{buddy: int, grace_edge: int} findings filed */
    public function run(Tenant $tenant): array
    {
        $since = Carbon::now()->subDays(self::WINDOW_DAYS);

        return [
            'buddy' => $this->buddies($since),
            'grace_edge' => $this->graceEdge($tenant),
        ];
    }

    private function buddies(Carbon $since): int
    {
        $records = AttendanceRecord::query()
            ->whereNotNull('device_hash')
            ->whereNotNull('check_in')
            ->where('created_at', '>=', $since)
            ->where('status', '!=', AttendanceStatus::VOIDED)
            ->orderBy('device_hash')
            ->orderBy('check_in')
            ->get(['id', 'employee_id', 'device_hash', 'check_in', 'date']);

        // [pair key] => set of days the pair punched together.
        $pairs = [];
        foreach ($records->groupBy('device_hash') as $onDevice) {
            $list = $onDevice->values();
            for ($i = 0; $i < $list->count(); $i++) {
                for ($j = $i + 1; $j < $list->count(); $j++) {
                    $a = $list[$i];
                    $b = $list[$j];
                    if ($b->check_in->diffInSeconds($a->check_in, true) > self::BUDDY_SECONDS) {
                        break;
                    }
                    if ($a->employee_id === $b->employee_id) {
                        continue;
                    }
                    $key = min($a->employee_id, $b->employee_id).'-'.max($a->employee_id, $b->employee_id);
                    $pairs[$key][$a->date->format('Y-m-d')] = true;
                }
            }
        }

        $filed = 0;
        foreach ($pairs as $key => $days) {
            if (count($days) < self::BUDDY_DAYS) {
                continue;
            }
            [$first, $second] = array_map('intval', explode('-', $key));
            foreach ([[$first, $second], [$second, $first]] as [$employeeId, $partnerId]) {
                $filed += $this->file($employeeId, 'buddy_pattern', 'high', [
                    'partner' => Employee::query()->whereKey($partnerId)->value('public_id'),
                    'days' => count($days),
                ]);
            }
        }

        return $filed;
    }

    private function graceEdge(Tenant $tenant): int
    {
        $zone = TenantTime::zone($tenant);
        $filed = 0;

        $recent = AttendanceRecord::query()
            ->whereNotNull('shift_id')
            ->whereNotNull('check_in')
            ->where('status', AttendanceStatus::PRESENT)
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->with('shift:id,start_time,end_time,grace_minutes')
            ->orderByDesc('check_in')
            ->get(['id', 'employee_id', 'shift_id', 'check_in', 'date']);

        foreach ($recent->groupBy('employee_id') as $employeeId => $records) {
            $last = $records->take(10);
            if ($last->count() < 10) {
                continue;
            }

            $edge = $last->filter(function (AttendanceRecord $r) use ($zone): bool {
                if ($r->shift === null || (int) $r->shift->grace_minutes < 3) {
                    return false;
                }
                [$start] = $r->shift->windowOn($r->workDate($zone), $zone);
                $late = $start->copy()->addMinutes((int) $r->shift->grace_minutes);

                return $r->check_in->gte($late->copy()->subMinutes(3)) && $r->check_in->lte($late);
            })->count();

            if ($edge >= 8) {
                $filed += $this->file((int) $employeeId, 'grace_edge', 'low', ['of_last' => 10, 'at_edge' => $edge]);
            }
        }

        return $filed;
    }

    /**
     * File a finding on the person's latest record, once.
     *
     * @param  array<string, mixed>  $details
     */
    private function file(int $employeeId, string $type, string $severity, array $details): int
    {
        $record = AttendanceRecord::query()
            ->where('employee_id', $employeeId)
            ->where('status', '!=', AttendanceStatus::VOIDED)
            ->latest('check_in')
            ->first();
        if ($record === null) {
            return 0;
        }

        $already = AttendanceIntegrityFlag::query()
            ->where('employee_id', $employeeId)
            ->where('type', $type)
            ->where('created_at', '>=', Carbon::now()->subDays(self::WINDOW_DAYS))
            ->exists();
        if ($already) {
            return 0;
        }

        AttendanceIntegrityFlag::query()->create([
            'tenant_id' => $record->tenant_id,
            'attendance_record_id' => $record->id,
            'employee_id' => $employeeId,
            'punch' => 'pattern',
            'type' => $type,
            'severity' => $severity,
            'details' => $details,
            'status' => 'open',
        ]);

        return 1;
    }
}
