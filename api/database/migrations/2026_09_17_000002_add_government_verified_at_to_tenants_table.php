<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform verification that a tenant really is a government body.
 *
 * Without this the "government" landing-page preset would be gated on data the
 * tenant supplies about itself: `settings['industry']` is chosen at onboarding
 * and `tenants.type` is validated as `string|max:50` with no `in:` rule at all.
 * Anyone wanting official-looking state branding for a private company need
 * only claim to be a ministry.
 *
 * So the grant lives here, written only through the `admin.manage` platform
 * surface. Nullable and null by default: no existing tenant is verified by
 * this migration, which means no existing tenant silently gains the ability to
 * present itself as a government office.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('government_verified_at')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('government_verified_at');
        });
    }
};
