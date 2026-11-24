<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether an account's role comes from its employee's job title.
     *
     * Backfilled true for the plain employee logins, which is what they would
     * be given anyway while no job title maps to a role, so nothing changes
     * for anyone at deploy. Every other account stays as it was set by hand.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('role_follows_position')->default(false);
        });

        DB::table('users')
            ->where('role', 'employee')
            ->whereNotNull('employee_id')
            ->whereNull('custom_role_id')
            ->whereNull('deleted_at')
            ->update(['role_follows_position' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role_follows_position');
        });
    }
};
