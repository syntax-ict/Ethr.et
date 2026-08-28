<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Islamic holidays are auto-detected from the tabular Hijri calendar, which can
 * differ from Ethiopia's locally-sighted observance by a day either way. Flag
 * those rows so HR knows to confirm and adjust them rather than trusting the
 * computed date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->boolean('is_estimated')->default(false)->after('recurring');
        });
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropColumn('is_estimated');
        });
    }
};
