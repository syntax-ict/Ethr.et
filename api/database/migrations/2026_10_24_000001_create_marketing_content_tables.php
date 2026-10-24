<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public landing page's content, owned by the platform operator.
 *
 * Until now every heading, capability, step, industry and trust point on
 * www.ethr.et was a translation key read by a hard-coded array in
 * `landing-content.tsx`. A sentence in a component has no owner and no audit
 * trail, and the only way to correct it was a deploy. These two tables follow
 * `docs/PLATFORM_MANAGED_CONTENT_PLAN.md`: a typed registry rather than a block
 * editor. The section kinds are fixed in `App\Support\MarketingContent`; what an
 * operator controls is the values, the order, the visibility and the images.
 *
 * Global, like `plans` and `platform_settings`: there is one marketing site,
 * every visitor reads the same rows, and no tenant owns them. Both models are
 * on `TenantIsolationTest`'s allow-list for that reason.
 *
 * Bilingual columns are `_am` twins, the pattern `platform_settings` set.
 *
 * `slug`, not `key`: KEY is a MariaDB reserved word, and the column is written
 * in raw index DDL below.
 *
 * No `->after()`: MySQL honours it and SQLite ignores it, and Scramble builds
 * the contract from column order, so the clause makes the two engines publish
 * different schemas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_sections', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26);
            $table->string('kind', 32);
            $table->string('heading', 300);
            $table->string('heading_am', 300)->nullable();
            $table->string('subheading', 600)->nullable();
            $table->string('subheading_am', 600)->nullable();
            $table->boolean('is_published')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            // A section image is used by the hero alone; the registry says so.
            $table->string('image_path', 255)->nullable();
            $table->char('image_version', 16)->nullable();
            $table->unsignedSmallInteger('image_width')->nullable();
            $table->unsignedSmallInteger('image_height')->nullable();
            $table->json('image_variants')->nullable();
            $table->string('image_alt', 300)->nullable();
            $table->string('image_alt_am', 300)->nullable();
            $table->timestamps();

            // Named: the generated names pass MariaDB's 64-character limit.
            $table->unique('public_id', 'marketing_sections_public_id');
            $table->unique('kind', 'marketing_sections_kind');
        });

        Schema::create('marketing_items', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26);
            $table->string('section_kind', 32);
            $table->string('slug', 64);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->string('icon', 40)->nullable();
            $table->string('title', 200);
            $table->string('title_am', 200)->nullable();
            $table->string('body', 1000)->nullable();
            $table->string('body_am', 1000)->nullable();
            $table->string('detail', 1000)->nullable();
            $table->string('detail_am', 1000)->nullable();
            $table->string('link_path', 200)->nullable();
            $table->string('image_path', 255)->nullable();
            $table->char('image_version', 16)->nullable();
            $table->unsignedSmallInteger('image_width')->nullable();
            $table->unsignedSmallInteger('image_height')->nullable();
            $table->json('image_variants')->nullable();
            $table->string('image_alt', 300)->nullable();
            $table->string('image_alt_am', 300)->nullable();
            $table->timestamps();

            // Named: the generated names pass MariaDB's 64-character limit.
            $table->unique('public_id', 'marketing_items_public_id');
            $table->unique(['section_kind', 'slug'], 'marketing_items_section_slug');
            $table->index(['section_kind', 'is_published', 'sort_order'], 'marketing_items_section_published_sort');
            $table->foreign('section_kind', 'marketing_items_section_kind_fk')
                ->references('kind')
                ->on('marketing_sections')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_items');
        Schema::dropIfExists('marketing_sections');
    }
};
