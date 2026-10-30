<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A name for an account that has no employee record.
     *
     * Every name in the product is read through the linked Employee, so the
     * administrator who registers an organisation, who has none, was shown by
     * their email everywhere: sign-up asked for their full name, validated it
     * and dropped it (onboarding review 2026-10-30). An employee record's name
     * still wins wherever there is one (`User::name`).
     *
     * Adding a nullable column alters nothing a generated column depends on,
     * so unlike `2026_10_26_000003_make_users_email_nullable.php` this needs no
     * rebuild on SQLite. No `->after()`, for the reason given there.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('display_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('display_name');
        });
    }
};
