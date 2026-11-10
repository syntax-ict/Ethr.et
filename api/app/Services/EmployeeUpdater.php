<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Support\EmployeeAuditMask;
use App\Support\EmployeeRelations;
use App\Support\EthiopianPhone;
use App\Traits\DispatchesWebhooks;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * An edit to an employee's record: the one place it is applied.
 *
 * This was the body of `EmployeeController::update`. It was lifted out so a
 * reviewed import can change an existing employee (One employee identity,
 * PR 5) and leave exactly what an ordinary edit leaves: the same pay gate, the
 * same stored values, the same `employee.updated` audit record and the same
 * webhook. `EmployeeUpdaterParityTest` holds the route and this class together.
 *
 * What the caller still owns:
 *
 *   - **Authorization to edit this employee at all** (`employee.update` and
 *     reach over the person). Only the pay gate is here, because it depends on
 *     which values change.
 *   - **Validation.** `$attributes` is what `UpdateEmployeeRequest` produces:
 *     its fields, with relations still as `public_id`s. `ValidatesRelationTenancy`
 *     is what makes a `public_id` from another organisation a 422; one that
 *     reaches here unvalidated resolves to `null`, as it always did.
 *   - **Status.** It is not an attribute of an edit. It moves only through
 *     `EmployeeTransitionService`, which records its own history.
 *
 * The audit record never holds a national id or a TIN. Both are encrypted at
 * rest and reading them off the model decrypts them, so the record written
 * here used to copy the old and the new value into `audit_log` in clear text.
 * `EmployeeAuditMask` replaces them: no value stays `null`, a value before is
 * `[redacted]`, a value after is `[changed]` or, when the form resent the one
 * already stored, `[unchanged]`. Forward-only; `docs/audit/BASELINE.md` §25j.
 *
 * The audit row's `user_id` is the signed-in user (`AuditLog::record()` reads
 * the guard), which is `$actor` for every caller there is. A caller running
 * with no signed-in user would get an unattributed row.
 */
final class EmployeeUpdater
{
    use DispatchesWebhooks;

    /**
     * @param  array<string, mixed>  $attributes  validated fields; relations as `public_id`s
     *
     * @throws AuthorizationException when the edit changes salary or TIN and `$actor` may not
     */
    public function update(Employee $employee, array $attributes, User $actor): Employee
    {
        $data = $attributes;

        // Salary and TIN are pay data: changing either needs
        // employee.updateFinancial, which employee.update alone never implied
        // (blueprint D4). A form that resends the unchanged value is not a
        // change, so it is not refused.
        $changesPay = (array_key_exists('salary_cents', $data) && (int) $data['salary_cents'] !== (int) $employee->salary_cents)
            || (array_key_exists('tin', $data) && (string) $data['tin'] !== (string) $employee->tin);
        if ($changesPay) {
            Gate::forUser($actor)->authorize('updateFinancial', $employee);
        }

        $data = EmployeeRelations::resolveIds($data);

        if (isset($data['phone'])) {
            $data['phone'] = EthiopianPhone::canonicalOrRaw($data['phone']);
        }

        // Saving a status is the review the 2026-10-14 backfill asked for: a
        // person has now said whether this employee is an Ethiopian national.
        if (($data['nationality_status'] ?? null) === null) {
            unset($data['nationality_status']);
        } else {
            $data['nationality_needs_review'] = false;
        }

        $before = $employee->only(array_keys($data));
        $employee->update($data);

        AuditLog::record(
            'employee.updated',
            $employee,
            EmployeeAuditMask::apply($before, $employee->only(array_keys($data))),
        );
        $this->webhook($employee->tenant_id, 'employee.updated', ['public_id' => $employee->public_id, 'name' => $employee->name]);

        return $employee;
    }
}
