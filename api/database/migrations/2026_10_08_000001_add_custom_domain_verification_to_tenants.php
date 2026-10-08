<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A custom domain resolves only once its owner has proved control of it.
 *
 * Until now a platform admin typing `hr.acme.com` made it resolve at once,
 * with nothing to show that Acme controlled the name or that it pointed at the
 * platform. Assignment now issues a token the organisation publishes as a TXT
 * record; the domain stays pending until that record and the CNAME to the
 * platform target both check out.
 *
 * A domain already assigned is NOT grandfathered as verified: it gets a token
 * and becomes pending, so nothing resolves on the strength of an unchecked
 * assignment. Production had no organisations when this shipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('custom_domain_token', 64)->nullable()->after('custom_domain');
            $table->timestamp('custom_domain_verified_at')->nullable()->after('custom_domain_token');
        });

        DB::table('tenants')
            ->whereNotNull('custom_domain')
            ->orderBy('id')
            ->each(function (object $tenant): void {
                DB::table('tenants')
                    ->where('id', $tenant->id)
                    ->update(['custom_domain_token' => bin2hex(random_bytes(16))]);
            });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['custom_domain_token', 'custom_domain_verified_at']);
        });
    }
};
