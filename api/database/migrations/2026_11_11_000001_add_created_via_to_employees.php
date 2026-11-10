<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How an employee record came to exist: the form, the API, a file, a staged
     * list, a terminal's roster, a hire, or a directory (SCIM, SSO).
     *
     * `EmployeeIntake` writes it and is the only code that creates an employee,
     * so the column is also the proof of that: a record made after this
     * migration with no value here did not come through the gateway.
     *
     * Nullable with no backfill. Records that predate it stay NULL, which is
     * the truth (nobody recorded it), and a backfill would run over HTTP inside
     * the host's 30 seconds. No `->after()`: MariaDB honours it and SQLite does
     * not, and Scramble reads column order.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('created_via', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('created_via');
        });
    }
};
