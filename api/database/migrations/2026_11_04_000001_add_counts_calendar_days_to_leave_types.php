<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a leave type is counted in consecutive calendar days rather
     * than working days. Labour Proclamation 1156/2019 Art. 88 grants
     * maternity leave as 120 consecutive days; counted in working days, a
     * 120-day balance covered some 170 calendar days (A-Z review E-10).
     * Every organisation's maternity type is switched on here; anything else
     * stays as it is until an organisation decides.
     */
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table): void {
            $table->boolean('counts_calendar_days')->default(false);
        });

        DB::table('leave_types')->where('code', 'maternity')->update(['counts_calendar_days' => true]);
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table): void {
            $table->dropColumn('counts_calendar_days');
        });
    }
};
