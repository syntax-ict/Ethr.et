<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a user exist with no email address.
     *
     * An employee with neither an email nor a phone is activated in person by
     * HR (`account_activation_codes`), so the account has nothing to put in
     * `users.email`. A placeholder address was rejected: a fake value leaks
     * into exports, SCIM and notification sends and has to be filtered out of
     * every one of them. NULL says what is true.
     *
     * `unique(tenant_id, email)` keeps its meaning: both MariaDB and SQLite
     * allow any number of NULLs in a unique index, so two no-email employees
     * in one organisation do not collide.
     *
     * `users` carries three generated columns (`email_normalized`,
     * `username_normalized`, `phone_normalized`) with an index each. One
     * depends on `email`, and neither engine will alter a column something
     * generated depends on. On SQLite the change rebuilds the whole table, and
     * that rebuild turned `phone_normalized` into a plain column, so phone
     * sign-in stopped matching. All three are therefore dropped, `email` is
     * changed, and they are put back with the same expressions. `LOWER(NULL)` is NULL, which no
     * lookup matches. See `2026_09_23_000003_index_login_identifier_lookups.php`
     * for why the column is VIRTUAL and why there is no `->after()`.
     */
    public function up(): void
    {
        $this->dropDependants();

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
        });

        $this->restoreDependants();
    }

    /**
     * Refuses when a no-email user exists: putting NOT NULL back would either
     * fail or invent an address, and neither is a rollback.
     */
    public function down(): void
    {
        if (DB::table('users')->whereNull('email')->exists()) {
            throw new RuntimeException('Cannot make users.email NOT NULL: accounts without an email exist.');
        }

        $this->dropDependants();

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable(false)->change();
        });

        $this->restoreDependants();
    }

    private function dropDependants(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'email']);
            $table->dropIndex(['tenant_id', 'email_normalized']);
            $table->dropIndex(['tenant_id', 'username_normalized']);
            $table->dropIndex(['tenant_id', 'phone_normalized']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['email_normalized', 'username_normalized', 'phone_normalized']);
        });
    }

    private function restoreDependants(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email_normalized')->nullable()->virtualAs('LOWER(email)');
            $table->string('username_normalized')->nullable()->virtualAs('LOWER(username)');
            $table->string('phone_normalized')->nullable()->virtualAs(self::PHONE_EXPRESSION);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'email']);
            $table->index(['tenant_id', 'email_normalized']);
            $table->index(['tenant_id', 'username_normalized']);
            $table->index(['tenant_id', 'phone_normalized']);
        });
    }

    /**
     * The expression `2026_09_23_000003_index_login_identifier_lookups.php`
     * gave `phone_normalized`, copied verbatim. Changing it changes who can
     * sign in by phone.
     */
    private const PHONE_EXPRESSION =
        "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '+', ''), '-', ''), '(', ''), ')', ''), '.', '')";
};
