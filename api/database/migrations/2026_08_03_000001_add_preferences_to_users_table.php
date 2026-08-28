<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user display preferences.
 *
 * `users.locale` has existed since the first migration but nothing ever wrote to
 * it — the language switcher in the header only set a localStorage key, so the
 * user's chosen language was lost on a new device and, worse, never reached the
 * server side that renders payslip PDFs, emails and SMS.
 *
 * The calendar and theme choices join it here rather than getting a column each:
 * they are display-only, read as a set, and the list will keep growing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('preferences')->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preferences');
        });
    }
};
