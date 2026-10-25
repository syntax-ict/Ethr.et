<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\PushSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Attendance\MyShiftService;
use App\Services\CurrentTenant;
use App\Services\Push\WebPushSender;
use App\Support\TenantTime;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Punch reminders as web push, for phones where ETHR is closed: before the
 * shift starts, when grace runs out with no check-in, and at the shift's end
 * while still checked in.
 *
 * The in-app reminders (punch-reminders.tsx) fire only while the app is open.
 * This reaches the same people by the same rules (MyShiftService decides the
 * shift, the working day, leave, holidays and the reminder switches) and only
 * those who subscribed a device. Every five minutes, each reminder in a
 * five-minute window, sent at most once per person per day.
 */
class PushPunchRemindersCommand extends Command
{
    protected $signature = 'attendance:push-reminders';

    protected $description = 'Send punch reminders as web push to subscribed phones';

    private const WINDOW_MINUTES = 5;

    public function handle(CurrentTenant $currentTenant, MyShiftService $shifts, WebPushSender $sender): int
    {
        if (! $sender->enabled()) {
            $this->info('Web push is not configured.');

            return self::SUCCESS;
        }

        $sent = 0;
        $now = Carbon::now('UTC');

        Tenant::operational()->each(function (Tenant $tenant) use ($currentTenant, $shifts, $sender, $now, &$sent): void {
            $currentTenant->set($tenant);

            try {
                $userIds = PushSubscription::query()->distinct()->pluck('user_id');
                if ($userIds->isEmpty()) {
                    return;
                }

                User::query()->whereIn('id', $userIds)->whereNotNull('employee_id')->with('employee')->get()
                    ->each(function (User $user) use ($tenant, $shifts, $sender, $now, &$sent): void {
                        $employee = $user->employee;
                        if (! $employee instanceof Employee) {
                            return;
                        }

                        $reminder = $this->due($shifts->forEmployee($employee, $user, $tenant), $now);
                        if ($reminder === null) {
                            return;
                        }

                        [$kind, $at, $date] = $reminder;
                        if (! Cache::add("push-reminder:{$user->id}:{$date}:{$kind}", true, now()->addDay())) {
                            return;
                        }

                        $time = Carbon::parse($at)->setTimezone(TenantTime::zone($tenant))->format('H:i');
                        $sent += $sender->toUser($user->id, [
                            'title' => 'ETHR',
                            'body' => __("attendance.push_reminder_{$kind}", ['time' => $time], $user->locale ?: 'en'),
                            'url' => '/attendance/mobile',
                            'tag' => "punch-{$kind}",
                        ]);
                    });
            } catch (Throwable $e) {
                report($e);
            } finally {
                $currentTenant->forget();
            }
        });

        $this->info("Push reminders sent: {$sent}.");

        return self::SUCCESS;
    }

    /**
     * The reminder due now for this person, if any.
     *
     * @param  array<string, mixed>  $shift  MyShiftService::forEmployee()
     * @return array{0: string, 1: string, 2: string}|null [kind, instant, date]
     */
    private function due(array $shift, Carbon $now): ?array
    {
        if (! ($shift['working_day'] ?? false) || ! ($shift['assistant']['reminders'] ?? false) || ! isset($shift['shift'])) {
            return null;
        }

        $status = $shift['attendance']['status'] ?? 'not_checked_in';
        $start = Carbon::parse($shift['shift']['starts_at']);
        $end = Carbon::parse($shift['shift']['ends_at']);
        $lead = (int) ($shift['assistant']['reminder_lead_minutes'] ?? 10);
        $grace = (int) ($shift['shift']['grace_minutes'] ?? 0);

        $candidates = [
            ['before_start', $start->copy()->subMinutes($lead), $status === 'not_checked_in'],
            ['not_checked_in', $start->copy()->addMinutes($grace), $status === 'not_checked_in'],
            ['shift_end', $end, $status === 'checked_in'],
        ];

        foreach ($candidates as [$kind, $at, $applies]) {
            if ($applies && $now->gte($at) && $now->lt($at->copy()->addMinutes(self::WINDOW_MINUTES))) {
                $instant = $kind === 'shift_end' ? $end : $start;

                return [$kind, $instant->toIso8601String(), (string) $shift['date']];
            }
        }

        return null;
    }
}
