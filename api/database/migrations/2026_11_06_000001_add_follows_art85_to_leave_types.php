<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a leave type is sick leave as Labour Proclamation 1156/2019
     * Art. 85 grants it: up to six months within twelve, counted from the
     * first day of sickness, the first month at full pay, the next two at
     * half pay and the last three unpaid (`SickLeaveTiers`). It was one paid
     * bucket of 30 days (A-Z review E-08). Every organisation's `sick` type
     * is switched on here; any other stays as it is until an organisation
     * decides.
     */
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table): void {
            $table->boolean('follows_art85')->default(false);
        });

        DB::table('leave_types')->where('code', 'sick')->update(['follows_art85' => true]);
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table): void {
            $table->dropColumn('follows_art85');
        });
    }
};
