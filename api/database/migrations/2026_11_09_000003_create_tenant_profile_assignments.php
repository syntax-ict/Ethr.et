<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Which organisation type, and which sector, an organisation was from a
     * date on: the profile its statutory rules resolve against.
     *
     * `tenants.organization_type` held only the type today, so reprocessing
     * an earlier period after a change of type would have used the new one,
     * and a change could not be scheduled ahead. The column stays, kept equal
     * to the assignment in force by App\Services\Tenancy\OrganisationProfile,
     * the only writer of either.
     *
     * Each existing organisation gets one assignment, from the day it
     * registered, holding the type it has now and the industry its
     * configuration recorded as its sector. No rule it resolves changes.
     */
    public function up(): void
    {
        Schema::create('tenant_profile_assignments', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('organization_type', 40);
            $table->string('sector', 60)->nullable();
            $table->date('effective_from');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'effective_from'], 'tenant_profile_assignments_tenant_from_unique');
        });

        $now = now();

        DB::table('tenants')->orderBy('id')->select(['id', 'organization_type', 'settings', 'created_at'])->each(function (object $tenant) use ($now): void {
            $settings = is_string($tenant->settings) ? json_decode($tenant->settings, true) : null;
            $industry = is_array($settings) && is_string($settings['industry'] ?? null) ? $settings['industry'] : null;

            DB::table('tenant_profile_assignments')->insert([
                'public_id' => (string) Str::ulid(),
                'tenant_id' => $tenant->id,
                'organization_type' => is_string($tenant->organization_type) && $tenant->organization_type !== '' ? $tenant->organization_type : 'private',
                'sector' => $industry !== null ? substr($industry, 0, 60) : null,
                'effective_from' => substr((string) ($tenant->created_at ?? $now->toDateString()), 0, 10),
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_profile_assignments');
    }
};
