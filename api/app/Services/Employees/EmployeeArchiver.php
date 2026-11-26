<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\Auth\ActivationCodeService;
use App\Services\Migration\AdditionUse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Archive people who should not have a record: the one place that does it.
 *
 * Archiving is the ordinary delete (`Employee::delete()`), so everyone archived
 * can be restored. It is for a record that was added by mistake, and so it is
 * refused for anyone who has been used since (AdditionUse), who is kept with
 * the reasons. The two ways of choosing who, a list's undo (ListUndo) and a
 * selection (EmployeeBulkController::bulkArchive), both end here, so what counts
 * as use, what is audited and what happens to the person's login cannot differ.
 *
 * The caller owns the transaction, and the lock that makes "nobody used them
 * since the check" true.
 */
final class EmployeeArchiver
{
    public function __construct(
        private readonly AdditionUse $use,
        private readonly ActivationCodeService $codes,
    ) {}

    /**
     * The reasons each of these people is in use, by employee id; someone not
     * in use is not in the answer.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, list<string>>
     */
    public function reasons(int $tenantId, array $employeeIds): array
    {
        return $this->use->reasons($tenantId, $employeeIds);
    }

    /**
     * Archive each of these people who is unused and who the actor may delete.
     * Anyone else is left as they are. Returns the people archived.
     *
     * `$reasons` is a check already made for these same people, by a caller that
     * must make it inside its own lock; without it the check is made here.
     *
     * @param  Collection<int, Employee>  $people
     * @param  array<string, mixed>  $audit  recorded with each `employee.deleted`
     * @param  array<string, mixed>  $loginAudit  recorded with each `user.deactivated`
     * @param  array<int, list<string>>|null  $reasons
     * @return list<Employee>
     */
    public function archive(Collection $people, User $actor, array $audit, array $loginAudit, ?array $reasons = null): array
    {
        if ($people->isEmpty()) {
            return [];
        }

        $tenantId = (int) $people->first()->tenant_id;
        $reasons ??= $this->reasons($tenantId, $people->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $gate = Gate::forUser($actor);

        $archived = [];
        foreach ($people as $person) {
            if (isset($reasons[$person->id]) || ! $gate->allows('delete', $person)) {
                continue;
            }

            $person->delete();
            AuditLog::record('employee.deleted', $person, $audit);
            $archived[] = $person;
        }

        $this->closeLogins($archived, $tenantId, $actor, $loginAudit);

        return $archived;
    }

    /**
     * Close the login of a person archived, when nobody ever signed in to it:
     * it is made inactive, keeps its record and its role, and its sessions,
     * tokens and activation code are ended. An account that is used is never
     * here, because its owner would have been kept.
     *
     * @param  list<Employee>  $archived
     * @param  array<string, mixed>  $audit
     */
    private function closeLogins(array $archived, int $tenantId, User $actor, array $audit): void
    {
        if ($archived === []) {
            return;
        }

        $ids = array_map(fn (Employee $employee): int => $employee->id, $archived);
        $linked = array_values(array_filter(array_map(fn (Employee $employee): ?int => $employee->user_id === null ? null : (int) $employee->user_id, $archived)));

        $accounts = User::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($ids, $linked): void {
                $q->whereIn('employee_id', $ids);
                if ($linked !== []) {
                    $q->orWhereIn('id', $linked);
                }
            })
            ->whereIn('status', ['active', 'invited'])
            ->get();

        foreach ($accounts as $account) {
            $account->update(['status' => 'inactive']);
            $account->tokens()->delete();
            $this->codes->revoke($account, $actor);

            AuditLog::record('user.deactivated', $account, $audit);
        }
    }
}
