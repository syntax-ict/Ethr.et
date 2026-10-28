<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A personnel action dated in the future is held until its effective date.
     *
     * Until now every action wrote its new grade, position or salary to the
     * employee the moment it was recorded, so a promotion effective next month
     * raised this month's payroll. `applied_at` is NULL while an action waits,
     * and `pending_values` holds the employee columns it will write then, by
     * id: `changes` keeps only labels, which cannot be written back.
     *
     * Every existing row was applied when it was recorded, so it is backfilled
     * with its own `created_at`.
     */
    public function up(): void
    {
        Schema::table('personnel_actions', function (Blueprint $table): void {
            $table->json('pending_values')->nullable()->after('changes');
            $table->timestamp('applied_at')->nullable()->after('is_temporary');

            $table->index(['tenant_id', 'applied_at']);
        });

        DB::table('personnel_actions')->update(['applied_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('personnel_actions', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'applied_at']);
            $table->dropColumn(['pending_values', 'applied_at']);
        });
    }
};
