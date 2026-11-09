<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One live login per employee, held by the database.
     *
     * #670 refused a second login in `StoreUserRequest` and
     * `UserProvisioningService::provision()`. Every other path that writes
     * `users.employee_id` (SSO and SCIM provisioning, the phone and in-person
     * accounts, linking an existing account) and two requests racing past
     * the same check could still make one. Now the second insert fails.
     *
     * **A generated column, not a plain unique index**, for the reason
     * `2026_09_23_000002_add_unique_index_to_device_serial_number.php` gives:
     * `User` soft-deletes and `UserController::destroy()` soft-deletes, so a
     * plain index would let a deleted account block that employee's next login
     * forever. MariaDB has no partial indexes. `employee_id_active` is NULL
     * once the row is deleted, and NULLs repeat in a unique index on MariaDB and
     * SQLite alike. Clearing `employee_id` on delete would also work, but it
     * would erase whose account a deleted one was, and every delete path
     * would have to remember to do it.
     *
     * VIRTUAL, because SQLite can add a virtual generated column with `ALTER
     * TABLE` and refuses a stored one. No `->after()`: MariaDB honours it and
     * SQLite does not, and Scramble reads column order
     * (`2026_09_23_000003_index_login_identifier_lookups.php`). Hidden on the
     * model, like the `*_normalized` columns. `DatabaseDumper` leaves generated
     * columns out of its INSERTs by reading the schema, so backups need no change.
     *
     * Global, not `(tenant_id, …)`: an employee id belongs to one tenant, so
     * the two are the same constraint, and the global one also refuses an
     * account in one tenant linked to another tenant's employee.
     *
     * The previous migration has already resolved existing duplicates. The
     * guard below catches only one written between the two, and names it.
     */
    public function up(): void
    {
        $this->guardAgainstDuplicates();

        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('employee_id_active')
                ->nullable()
                ->virtualAs('CASE WHEN deleted_at IS NULL THEN employee_id ELSE NULL END');
        });

        // Separate closure: SQLite cannot add a column and index it in one
        // ALTER, and Laravel emits one ALTER per Schema::table closure.
        Schema::table('users', function (Blueprint $table): void {
            $table->unique('employee_id_active', 'users_live_employee_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_live_employee_unique');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('employee_id_active');
        });
    }

    private function guardAgainstDuplicates(): void
    {
        $employeeIds = DB::table('users')
            ->whereNotNull('employee_id')
            ->whereNull('deleted_at')
            ->groupBy('employee_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('employee_id');

        if ($employeeIds->isEmpty()) {
            return;
        }

        $accounts = DB::table('users')
            ->whereIn('employee_id', $employeeIds->all())
            ->whereNull('deleted_at')
            ->orderBy('employee_id')
            ->orderBy('id')
            ->get(['public_id', 'tenant_id', 'employee_id'])
            ->map(fn ($row) => sprintf('  %s (tenant %d, employee #%d)', $row->public_id, $row->tenant_id, $row->employee_id))
            ->implode("\n");

        throw new RuntimeException(sprintf(
            "Cannot make users.employee_id unique: %d employee(s) still have more than one live login.\n\n%s\n\n"
            .'A login was linked between the previous migration and this one. On each employee, set employee_id '
            .'NULL on every account above except the one employees.user_id names, as '
            .'2026_11_09_000001_unlink_second_logins_from_employees does, then migrate again.',
            $employeeIds->count(),
            $accounts,
        ));
    }
};
