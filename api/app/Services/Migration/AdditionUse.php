<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Whether anyone has used a person since a list made them.
 *
 * A person a list made has a record and nothing else: no attendance, no pay,
 * no leave taken, no sign-in. Anything of that kind is work a person, or HR
 * for a person, did afterwards, and undoing the list must not archive someone
 * whose work is attached to them. What the list itself writes (a contract, a
 * bank account, an emergency contact, an external identity, a terminal's
 * commands) and what the system writes by itself (a leave balance that is all
 * zeros) are not use.
 *
 * Read a table at a time, not a person at a time. Every query states
 * `tenant_id` itself; the ones that go through a user's id take it from users
 * read with `tenant_id` stated. A table that soft-deletes is read through the
 * query builder, so a deleted leave request is still use.
 */
final class AdditionUse
{
    public const ATTENDANCE = 'attendance';

    public const SIGNED_IN = 'signed_in';

    public const PAYROLL = 'payroll';

    public const LEAVE = 'leave';

    public const OVERTIME = 'overtime';

    public const DOCUMENTS = 'documents';

    public const HR_RECORDS = 'hr_records';

    public const IN_CHARGE = 'in_charge';

    public const ASSIGNED = 'assigned';

    /** Ids per query. */
    private const CHUNK = 500;

    /**
     * Tables with an `employee_id` that hold what a person did or what was done for them.
     *
     * @var array<string, list<string>>
     */
    private const BY_EMPLOYEE = [
        self::ATTENDANCE => [
            'attendance_records', 'attendance_corrections', 'attendance_conflicts', 'attendance_integrity_flags',
            'attendance_visits', 'attendance_presence_checks', 'unmatched_device_punches', 'attendance_devices',
        ],
        self::PAYROLL => ['payroll_entries', 'employee_loans', 'employee_cost_sharing'],
        self::LEAVE => ['leave_requests'],
        self::OVERTIME => ['overtime_requests'],
        self::DOCUMENTS => ['employee_documents', 'employee_education'],
        self::HR_RECORDS => [
            'personnel_actions', 'disciplinary_cases', 'retirement_cases', 'exit_clearances',
            'employment_letter_requests', 'employee_transitions', 'profile_update_requests', 'job_applications',
        ],
    ];

    /**
     * The reasons these people are in use, by employee id. Someone not in use is not in the answer.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, list<string>>
     */
    public function reasons(int $tenantId, array $employeeIds): array
    {
        $found = [];

        foreach (array_chunk(array_values(array_unique($employeeIds)), self::CHUNK) as $ids) {
            foreach (self::BY_EMPLOYEE as $reason => $tables) {
                foreach ($tables as $table) {
                    foreach ($this->owners($table, 'employee_id', $tenantId, $ids) as $id) {
                        $found[$id][$reason] = true;
                    }
                }
            }

            foreach ($this->owners('recognitions', 'from_employee_id', $tenantId, $ids) as $id) {
                $found[$id][self::HR_RECORDS] = true;
            }
            foreach ($this->owners('recognitions', 'to_employee_id', $tenantId, $ids) as $id) {
                $found[$id][self::HR_RECORDS] = true;
            }

            $balances = DB::table('leave_balances')
                ->where('tenant_id', $tenantId)
                ->whereIn('employee_id', $ids)
                ->where(fn (Builder $q) => $q->where('used_days', '>', 0)->orWhere('pending_days', '>', 0)->orWhere('carried_days', '>', 0))
                ->distinct()
                ->pluck('employee_id');
            foreach ($balances as $id) {
                $found[(int) $id][self::LEAVE] = true;
            }

            foreach ($this->owners('employees', 'supervisor_id', $tenantId, $ids, current: true) as $id) {
                $found[$id][self::IN_CHARGE] = true;
            }
            foreach ($this->owners('departments', 'head_employee_id', $tenantId, $ids, current: true) as $id) {
                $found[$id][self::IN_CHARGE] = true;
            }

            foreach (['shift_assignments', 'attendance_site_assignments'] as $table) {
                $assigned = DB::table($table)
                    ->where('tenant_id', $tenantId)
                    ->where('assignable_type', Employee::class)
                    ->whereIn('assignable_id', $ids)
                    ->distinct()
                    ->pluck('assignable_id');
                foreach ($assigned as $id) {
                    $found[(int) $id][self::ASSIGNED] = true;
                }
            }

            foreach ($this->signedIn($tenantId, $ids) as $id) {
                $found[$id][self::SIGNED_IN] = true;
            }
        }

        $order = [
            self::ATTENDANCE, self::SIGNED_IN, self::PAYROLL, self::LEAVE, self::OVERTIME,
            self::DOCUMENTS, self::HR_RECORDS, self::IN_CHARGE, self::ASSIGNED,
        ];

        $reasons = [];
        foreach ($found as $id => $set) {
            $reasons[$id] = array_values(array_filter($order, fn (string $reason): bool => isset($set[$reason])));
        }

        return $reasons;
    }

    /**
     * Which of these ids appear in the column, in rows of this organisation.
     * `$current` leaves out deleted rows, for a table where a deleted row is
     * not work (a report who has been archived, a department that is gone).
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function owners(string $table, string $column, int $tenantId, array $ids, bool $current = false): array
    {
        return DB::table($table)
            ->where('tenant_id', $tenantId)
            ->when($current, fn (Builder $q) => $q->whereNull('deleted_at'))
            ->whereIn($column, $ids)
            ->distinct()
            ->pluck($column)
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * People whose login has been used: it recorded a sign-in, holds a token, has a
     * successful sign-in in its history, redeemed an activation code, or has a
     * trusted browser or a push subscription (both are made from a signed-in session).
     * A login is found from either side of its link to the employee, and a deleted
     * login counts.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function signedIn(int $tenantId, array $ids): array
    {
        $linked = Employee::withTrashed()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->whereNotNull('user_id')
            ->pluck('user_id', 'id');

        $users = User::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($ids, $linked): void {
                $q->whereIn('employee_id', $ids);
                if ($linked->isNotEmpty()) {
                    $q->orWhereIn('id', $linked->values()->all());
                }
            })
            ->get(['id', 'employee_id', 'last_login_at']);

        if ($users->isEmpty()) {
            return [];
        }

        // user id => the employee ids it is the login of
        $owners = [];
        foreach ($users as $user) {
            if ($user->employee_id !== null && in_array((int) $user->employee_id, $ids, true)) {
                $owners[(int) $user->id][(int) $user->employee_id] = true;
            }
        }
        foreach ($linked as $employeeId => $userId) {
            $owners[(int) $userId][(int) $employeeId] = true;
        }

        $used = $users->whereNotNull('last_login_at')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $userIds = $users->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $used = [
            ...$used,
            ...DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $userIds)
                ->distinct()->pluck('tokenable_id')->map(fn ($id): int => (int) $id)->all(),
            ...DB::table('login_histories')
                ->where('tenant_id', $tenantId)
                ->whereIn('user_id', $userIds)
                ->where('status', 'success')
                ->distinct()->pluck('user_id')->map(fn ($id): int => (int) $id)->all(),
            ...DB::table('account_activation_codes')
                ->where('tenant_id', $tenantId)
                ->whereIn('user_id', $userIds)
                ->whereNotNull('consumed_at')
                ->distinct()->pluck('user_id')->map(fn ($id): int => (int) $id)->all(),
            ...DB::table('trusted_devices')
                ->whereIn('user_id', $userIds)
                ->distinct()->pluck('user_id')->map(fn ($id): int => (int) $id)->all(),
            ...DB::table('push_subscriptions')
                ->where('tenant_id', $tenantId)
                ->whereIn('user_id', $userIds)
                ->distinct()->pluck('user_id')->map(fn ($id): int => (int) $id)->all(),
        ];

        $people = [];
        foreach (array_unique($used) as $userId) {
            foreach (array_keys($owners[$userId] ?? []) as $employeeId) {
                $people[$employeeId] = $employeeId;
            }
        }

        return array_values($people);
    }
}
