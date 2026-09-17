<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The facts the public site states about ETHR, given an owner.
 *
 * This table's own rationale for existing was the same problem one field
 * earlier: a placeholder bank account shipped as literal JSX, with the bank
 * name stored as a translation key so the payment destination could differ
 * between the English and Amharic UI. The argument applies verbatim to
 * `info@ethr.et` and `+251 11 123 4567`, hardcoded at contact-content.tsx, and
 * to the E-in-a-box logo duplicated across the marketing header and footer.
 *
 * No new table, deliberately. `platform_settings` is already a global model
 * with no `tenant_id`, already on TenantIsolationTest's GLOBAL_MODELS
 * allow-list, already gated by `admin.manage`, already audited. Adding columns
 * inherits all of that; a new table would re-litigate every one of those
 * decisions for no gain.
 *
 * PAIRED `_am` COLUMNS, not a JSON {am, en} blob. This table already carries
 * payment_instructions / payment_instructions_am, so the pattern is the one
 * this repository established: typed, individually validatable, and understood
 * by the FormRequest and Resource that already exist. A second bilingual
 * convention competing with the first would be the cost of no benefit.
 *
 * `logo_url` IS A URL, NOT AN UPLOAD, for three reasons the repo already
 * documented when it made the same call for tenant logos:
 *   - FileStorageService::tenantPrefix() hardcodes `tenants/{publicId}`, and
 *     platform-admin routes run with no tenant resolved, so reusing upload()
 *     would write to a malformed path.
 *   - temporaryUrl() returns 15-minute signed URLs, unusable on a cached
 *     public marketing page.
 *   - next.config.ts and branding-card.tsx already chose a validated URL
 *     string for tenant logos, and said why: every consumer renders the value
 *     straight into an <img src>, so a storage key renders broken.
 *
 * THE METRICS COLUMNS EXIST SO THEY CAN BE EMPTY. The landing page currently
 * states "500+ organisations", "50,000+ employees" and "99.9% uptime", and
 * nobody can substantiate any of them — there is no production deployment at
 * all. Null here renders nothing rather than a number somebody invented, which
 * is the entire point of moving them out of the component.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No ->after() anywhere: MySQL honours it and SQLite ignores it, so the
        // two engines end up with different column order for the same schema —
        // and Scramble derives the OpenAPI component from column order, which
        // made the contract gate disagree between a local run and CI. See
        // add_catalog_columns_to_plans.
        Schema::table('platform_settings', function (Blueprint $table) {
            // Identity.
            $table->string('platform_name')->nullable();
            $table->string('platform_name_am')->nullable();
            $table->string('tagline', 300)->nullable();
            $table->string('tagline_am', 300)->nullable();
            $table->string('logo_url', 500)->nullable();

            // How to reach a human. Hardcoded in contact-content.tsx today.
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->string('office_address', 500)->nullable();
            $table->string('office_address_am', 500)->nullable();

            // Where the footer's inert links should point once they exist.
            $table->string('social_linkedin', 500)->nullable();
            $table->string('social_x', 500)->nullable();
            $table->string('social_facebook', 500)->nullable();

            // Nullable integers, not defaults of zero: "no figure published" and
            // "we have zero customers" are different statements, and a default
            // would publish the second.
            $table->unsignedInteger('metric_organisations')->nullable();
            $table->unsignedInteger('metric_employees')->nullable();

            // Free text rather than a number, because the honest answer today is
            // "no SLA exists" — which the terms page already says — and a
            // percentage column invites someone to fill in 99.9 again.
            $table->string('metric_uptime_note', 200)->nullable();
            $table->string('metric_uptime_note_am', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn([
                'platform_name',
                'platform_name_am',
                'tagline',
                'tagline_am',
                'logo_url',
                'contact_email',
                'contact_phone',
                'office_address',
                'office_address_am',
                'social_linkedin',
                'social_x',
                'social_facebook',
                'metric_organisations',
                'metric_employees',
                'metric_uptime_note',
                'metric_uptime_note_am',
            ]);
        });
    }
};
