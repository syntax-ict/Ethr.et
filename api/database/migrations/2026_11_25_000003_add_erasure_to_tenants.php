<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When an organisation that left was erased, by whom, and the certificate
     * sent to it (App\Services\Tenancy\OrganisationErasure).
     *
     * The row itself stays, as a tombstone, so the address is never reissued
     * and the audit log still names an organisation. `erased_by` is null when
     * the erasure ran from the maintenance endpoint, which has a token and no
     * user.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->timestamp('erased_at')->nullable();
            $table->foreignId('erased_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('erasure_certificate')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('erased_by');
            $table->dropColumn(['erased_at', 'erasure_certificate']);
        });
    }
};
