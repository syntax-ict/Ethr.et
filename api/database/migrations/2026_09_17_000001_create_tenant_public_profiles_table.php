<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The public face of a tenant — and nothing else.
     *
     * Deliberately a separate table rather than more columns on `tenants`. The
     * distinction it encodes is not cosmetic: every column here is intended to
     * be read by an unauthenticated visitor, while `tenants.settings` holds
     * operational configuration (`login_identifiers` among it) that must never
     * be. Keeping the two apart means "what may the public see" is answered by
     * a table name instead of by remembering which columns are safe.
     *
     * `is_published` defaults to false, so shipping this migration publishes
     * nothing. A tenant becomes visible on the internet only when one of its
     * administrators says so.
     */
    public function up(): void
    {
        Schema::create('tenant_public_profiles', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // The opt-in switch. False means the tenant host answers 404 at `/`
            // exactly as it did before this feature existed.
            $table->boolean('is_published')->default(false);

            // Published but deliberately not indexed is a real state — a tenant
            // that wants a link to hand out rather than a page search engines
            // rank. Separate from `is_published` because conflating them would
            // force that tenant to choose between a public page and privacy.
            $table->boolean('is_indexable')->default(true);

            $table->string('headline', 160)->nullable();
            $table->text('description')->nullable();
            $table->string('hero_image_path', 500)->nullable();

            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('address_line')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('region', 120)->nullable();

            $table->string('website_url')->nullable();
            $table->json('social_links')->nullable();

            $table->string('meta_description', 320)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            // One profile per tenant. The unique index is what makes
            // `firstOrCreate(['tenant_id' => ...])` safe under concurrency.
            $table->unique('tenant_id');

            // The landing page's only query: this tenant, is it published.
            $table->index(['tenant_id', 'is_published']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_public_profiles');
    }
};
