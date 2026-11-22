<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The level an education record sits at, beside `degree`, which stays the
 * free-text title. Nullable and never backfilled: a title such as "BSc" is not
 * parsed into a level, so a row written before this column says nothing rather
 * than something guessed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_education', function (Blueprint $table) {
            $table->string('level', 20)->nullable()->after('degree');
        });
    }

    public function down(): void
    {
        Schema::table('employee_education', function (Blueprint $table) {
            $table->dropColumn('level');
        });
    }
};
