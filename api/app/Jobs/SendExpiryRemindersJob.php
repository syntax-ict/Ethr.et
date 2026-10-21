<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ContractStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ExpiryReminderNotification;
use App\Services\CurrentTenant;
use App\Traits\SendsNotifications;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Tells HR, ahead of time, about contracts, probation periods and documents
 * that are about to end.
 *
 * The data and the watchlists existed, but nothing reached anyone who had not
 * opened the right screen, so a fixed-term contract or a work permit lapsed
 * unnoticed (blueprint assessment E6, L4, L5, N3).
 *
 * An item is reminded on a few fixed days before its date, not every day, so
 * a month-long window sends three reminders rather than thirty. Each person
 * gets one notice listing everything due for them that day.
 *
 * Recipients: people who manage employee records (employee.update) and reach
 * the employee, as on every employee screen. For a probation period, the
 * employee's supervisor too, since they make the assessment.
 */
class SendExpiryRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SendsNotifications, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    /** Days before the date on which a contract or document is reminded. */
    public const AHEAD_DAYS = [30, 7, 1];

    /** Days before the end of probation on which it is reminded. */
    public const PROBATION_AHEAD_DAYS = [14, 1];

    public function __construct(private readonly int $tenantId) {}

    public function handle(CurrentTenant $currentTenant): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

        $currentTenant->set($tenant);
        $today = CarbonImmutable::today();
        // whereDate per day rather than whereIn: a `date` cast may be stored
        // with a time part, which a plain string comparison would never match.
        $on = fn (string $column, array $ahead) => function ($query) use ($column, $ahead, $today): void {
            foreach ($ahead as $days) {
                $query->orWhereDate($column, $today->addDays($days)->toDateString());
            }
        };

        /** @var list<array{kind: string, employee: Employee, date: string, label: string}> $items */
        $items = [];

        EmployeeContract::query()
            ->where('status', ContractStatus::ACTIVE)
            ->where($on('end_date', self::AHEAD_DAYS))
            ->whereHas('employee')
            ->with('employee')
            ->get()
            ->each(function (EmployeeContract $contract) use (&$items) {
                $items[] = ['kind' => 'contract', 'employee' => $contract->employee, 'date' => $contract->end_date->toDateString(), 'label' => ''];
            });

        Employee::query()
            ->where('status', EmployeeStatus::PROBATION)
            ->where($on('probation_end_date', self::PROBATION_AHEAD_DAYS))
            ->get()
            ->each(function (Employee $employee) use (&$items) {
                $items[] = ['kind' => 'probation', 'employee' => $employee, 'date' => $employee->probation_end_date->toDateString(), 'label' => ''];
            });

        EmployeeDocument::query()
            ->where($on('expiry_date', self::AHEAD_DAYS))
            ->whereHas('employee')
            ->with('employee')
            ->get()
            ->each(function (EmployeeDocument $document) use (&$items) {
                $employee = $document->employee;
                if ($employee instanceof Employee) {
                    $items[] = ['kind' => 'document', 'employee' => $employee, 'date' => CarbonImmutable::parse($document->expiry_date)->toDateString(), 'label' => (string) $document->title];
                }
            });

        if ($items === []) {
            return;
        }

        $managers = User::query()
            ->where('status', 'active')
            ->get()
            ->filter(fn (User $user) => $user->hasPermission('employee.update'));

        /** @var array<int, array{user: User, lines: list<array{kind: string, name: string, date: string, label: string}>}> $byRecipient */
        $byRecipient = [];
        $add = function (User $user, array $item) use (&$byRecipient): void {
            $byRecipient[$user->id] ??= ['user' => $user, 'lines' => []];
            $line = ['kind' => $item['kind'], 'name' => (string) $item['employee']->name, 'date' => $item['date'], 'label' => $item['label']];
            if (! in_array($line, $byRecipient[$user->id]['lines'], true)) {
                $byRecipient[$user->id]['lines'][] = $line;
            }
        };

        foreach ($items as $item) {
            foreach ($managers as $manager) {
                if ($manager->canAccessEmployee($item['employee'])) {
                    $add($manager, $item);
                }
            }

            if ($item['kind'] === 'probation') {
                $supervisorUser = User::query()
                    ->where('status', 'active')
                    ->where('employee_id', $item['employee']->supervisor_id)
                    ->first();
                if ($supervisorUser !== null && $item['employee']->supervisor_id !== null) {
                    $add($supervisorUser, $item);
                }
            }
        }

        foreach ($byRecipient as ['user' => $user, 'lines' => $lines]) {
            usort($lines, fn (array $a, array $b) => [$a['date'], $a['name']] <=> [$b['date'], $b['name']]);
            $this->notify($user, new ExpiryReminderNotification($lines));
        }
    }
}
