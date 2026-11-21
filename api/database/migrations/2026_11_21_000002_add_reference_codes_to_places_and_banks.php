<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The code of the pick-list entry beside the text a record already keeps.
     *
     * Nothing here rewrites a row: `city` and `bank_name` stay exactly as they
     * were typed, and a record with no code simply shows its text. A code is
     * set only when a person picks from the list.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->string('region_code', 60)->nullable()->after('city');
            $table->string('town_code', 60)->nullable()->after('region_code');
        });

        Schema::table('employee_bank_details', function (Blueprint $table): void {
            $table->string('bank_code', 60)->nullable()->after('bank_name');
        });

        Schema::table('platform_settings', function (Blueprint $table): void {
            $table->string('bank_code', 60)->nullable()->after('bank_name');
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table): void {
            $table->dropColumn('bank_code');
        });

        Schema::table('employee_bank_details', function (Blueprint $table): void {
            $table->dropColumn('bank_code');
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn(['region_code', 'town_code']);
        });
    }
};
