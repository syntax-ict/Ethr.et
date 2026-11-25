<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which version of the Terms of Service and Privacy Policy the
     * organisation accepted at sign-up, when, and by whom.
     *
     * Null for every organisation that registered before 2026-11-25: sign-up
     * recorded nothing then, and backfilling a version nobody saw would be a
     * record of something that did not happen. The IP address and browser of
     * the acceptance are on its `tenant.terms_accepted` audit row.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('terms_accepted_version', 32)->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->foreignId('terms_accepted_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('terms_accepted_by');
            $table->dropColumn(['terms_accepted_version', 'terms_accepted_at']);
        });
    }
};
