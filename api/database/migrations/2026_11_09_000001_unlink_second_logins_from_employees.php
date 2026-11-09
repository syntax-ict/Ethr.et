<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Leave each employee with at most one live login, so the next migration
     * can make `users.employee_id` unique.
     *
     * A login is whose punches, payslips, salary and leave an account acts on.
     * Until #670 nothing stopped a second one on the same record (an HR admin
     * could create one on the CEO from an inbox they control), so production
     * may hold some. This session could not read production, so the migration
     * resolves whatever it finds rather than refusing: a refusal would stop
     * `migrate` here, and every later release's migration behind it, until
     * someone edited data by hand on a host with no shell (BASELINE §25f).
     *
     * **Which account keeps the link — owner decision 2026-11-09.** The one the
     * employee record itself names in `employees.user_id`. Before #670,
     * `UserProvisioningService::provision()` set that only when it was empty,
     * so it is the first login, and the later ones are what the defect made.
     * When it names none of the live duplicates, the oldest (lowest id) keeps it.
     *
     * The others lose only `employee_id`: the account, its role and its
     * sign-in stay as they are, so an organisation admin who still uses one is
     * not locked out, and each unlink is written to `audit_log` as
     * `user.employee_unlinked` in that organisation, naming both accounts. An
     * account left with no employee reads no one's payslips.
     *
     * Soft-deleted accounts are left alone: the unique index ignores them
     * (next migration), and their link is the history of whose account it was.
     *
     * Raw queries, no models: a migration must not change behaviour when a
     * model does. No tenant scope applies, and none is wanted; the predicate
     * here is the employee's own id, which belongs to exactly one tenant.
     */
    public function up(): void
    {
        $employeeIds = DB::table('users')
            ->whereNotNull('employee_id')
            ->whereNull('deleted_at')
            ->groupBy('employee_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('employee_id');

        foreach ($employeeIds as $employeeId) {
            DB::transaction(fn () => $this->resolve((int) $employeeId));
        }
    }

    /**
     * Nothing to undo. Which account was second is in `audit_log`, and putting
     * the link back would recreate the defect.
     */
    public function down(): void {}

    private function resolve(int $employeeId): void
    {
        $accounts = DB::table('users')
            ->where('employee_id', $employeeId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'public_id', 'tenant_id']);

        if ($accounts->count() < 2) {
            return;
        }

        $employee = DB::table('employees')
            ->where('id', $employeeId)
            ->first(['public_id', 'user_id']);

        $keeper = $accounts->firstWhere('id', $employee?->user_id) ?? $accounts->first();
        $now = now();

        foreach ($accounts as $account) {
            if ($account->id === $keeper->id) {
                continue;
            }

            DB::table('users')
                ->where('id', $account->id)
                ->update(['employee_id' => null, 'updated_at' => $now]);

            DB::table('audit_log')->insert([
                'tenant_id' => $account->tenant_id,
                'user_id' => null,
                'action' => 'user.employee_unlinked',
                'auditable_type' => 'App\\Models\\User',
                'auditable_id' => $account->id,
                'payload' => json_encode([
                    'employee_id' => $employee?->public_id,
                    'kept_user_id' => $keeper->public_id,
                    'reason' => 'second_login',
                    'migration' => '2026_11_09_000001_unlink_second_logins_from_employees',
                ]),
                'created_at' => $now,
            ]);
        }
    }
};
