<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make every login-identifier lookup indexable.
     *
     * `AuthIdentifierResolver` resolves a login value by wrapping the column in
     * a SQL function — `LOWER(email)`, `LOWER(username)`, `LOWER(employee_code)`
     * and a six-deep `REPLACE()` chain over `phone`. A predicate on a function
     * of a column cannot use an index on that column, so all four fell back to
     * the only usable index, `tenant_id`, and then examined every row belonging
     * to that tenant. `BASELINE.md` risk #12.
     *
     * Three of the four already had a perfectly good composite index that the
     * query simply could not reach — `users_tenant_id_email_unique`,
     * `users_tenant_id_username_unique`, `employees_tenant_id_employee_code_unique`.
     * Measured with `EXPLAIN QUERY PLAN` before this migration:
     *
     *     SEARCH users USING INDEX users_tenant_id_index (tenant_id=?)
     *
     * and after it:
     *
     *     SEARCH users USING INDEX users_tenant_id_email_normalized_index
     *       (tenant_id=? AND email_normalized=?)
     *
     * **The generated columns carry the same expressions, character for
     * character.** That is the point: this migration must not change which
     * identifier matches which user, only how fast the database finds it.
     * `LoginIdentifierTest` is untouched and covers the behaviour; the new
     * `LoginIdentifierIndexTest` covers the index.
     *
     * **No `->after()`.** MySQL honours it and SQLite ignores it, so the two
     * engines end up with different column order for the same schema, and
     * Scramble builds the OpenAPI component from column order — a red contract
     * gate with no defect behind it. `2026_09_17_000002_add_catalog_columns_to_plans.php`
     * records that trap in full. Column position carries no meaning in either
     * engine.
     *
     * VIRTUAL rather than STORED, for the reason
     * `2026_09_23_000002_add_unique_index_to_device_serial_number.php` records:
     * SQLite can add a virtual generated column with `ALTER TABLE` and refuses
     * a stored one, and the suite runs on SQLite while production runs on
     * MariaDB. Virtual costs nothing here — the index materialises the value.
     *
     * Plain indexes, never unique. A unique index would be a behaviour change
     * and could fail on existing data: on SQLite the current
     * `unique(tenant_id, email)` compares BINARY, so `Bob@x` and `bob@x` are
     * two legal rows today, and `email_normalized` collapses them to one value.
     *
     * **Two normalisation asymmetries are preserved deliberately, not fixed.**
     * Both predate this change and both are matching semantics, which is not a
     * thing to alter inside a performance migration:
     *
     *  - The phone input is normalised in PHP with `preg_replace('/\D+/', '')`,
     *    which strips *every* non-digit; the column strips six specific
     *    characters. A number stored as `091/234-5678` has never matched, and
     *    still does not.
     *  - `LOWER()` is ASCII-only on SQLite and collation-aware on MariaDB,
     *    while the input side uses `mb_strtolower()`. A non-ASCII identifier
     *    therefore already behaves differently per driver.
     *
     * See `BASELINE.md` §15e.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email_normalized')
                ->nullable()
                ->virtualAs('LOWER(email)');

            $table->string('username_normalized')
                ->nullable()
                ->virtualAs('LOWER(username)');

            $table->string('phone_normalized')
                ->nullable()
                ->virtualAs(self::PHONE_EXPRESSION);
        });

        // Separate closure: SQLite cannot add a column and index it in one
        // ALTER, and Laravel emits one ALTER per Schema::table closure.
        Schema::table('users', function (Blueprint $table): void {
            $table->index(['tenant_id', 'email_normalized']);
            $table->index(['tenant_id', 'username_normalized']);
            $table->index(['tenant_id', 'phone_normalized']);
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->string('employee_code_normalized', 30)
                ->nullable()
                ->virtualAs('LOWER(employee_code)');

            $table->string('phone_normalized')
                ->nullable()
                ->virtualAs(self::PHONE_EXPRESSION);
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->index(['tenant_id', 'employee_code_normalized']);
            $table->index(['tenant_id', 'phone_normalized']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'email_normalized']);
            $table->dropIndex(['tenant_id', 'username_normalized']);
            $table->dropIndex(['tenant_id', 'phone_normalized']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['email_normalized', 'username_normalized', 'phone_normalized']);
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'employee_code_normalized']);
            $table->dropIndex(['tenant_id', 'phone_normalized']);
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['employee_code_normalized', 'phone_normalized']);
        });
    }

    /**
     * The six replacements `AuthIdentifierResolver` has always applied, copied
     * here verbatim. Changing this string changes who can log in.
     */
    private const PHONE_EXPRESSION =
        "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '+', ''), '-', ''), '(', ''), ')', ''), '.', '')";
};
