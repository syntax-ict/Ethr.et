<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The repeatable entries inside a section — a service, a notice, a person.
 *
 * Carries `tenant_id` as well as `section_id`, which is redundant only if you
 * trust the join. It is here so `BelongsToTenant` can scope this table
 * directly: an item is reachable by ULID from an anonymous image route, and the
 * query that resolves it must fail closed on its own rather than inheriting
 * safety from whatever it was joined to.
 *
 * `image_alt` sits beside `image_path` rather than being optional metadata. An
 * image with no alternative text is a WCAG 1.1.1 failure, and this is a page
 * whose entire purpose is to be read by the public — including by people using
 * a screen reader. The FormRequest makes it `required_with`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_public_items', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')
                ->constrained('tenant_public_sections')
                ->cascadeOnDelete();

            $table->unsignedSmallInteger('position')->default(0);

            $table->string('title', 160)->nullable();
            $table->string('title_am', 160)->nullable();
            $table->text('body')->nullable();
            $table->text('body_am')->nullable();

            $table->string('image_path', 500)->nullable();
            $table->string('image_alt', 180)->nullable();
            $table->string('image_alt_am', 180)->nullable();

            // From a fixed allow-list, never free text — the name is
            // interpolated into an SVG sprite reference.
            $table->string('icon', 40)->nullable();

            $table->string('link_url')->nullable();
            $table->string('link_label', 160)->nullable();
            $table->string('link_label_am', 160)->nullable();

            // Kind-specific structured values: a stat's figure, an hours row's
            // open and close times. Shaped and validated per kind.
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'section_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_public_items');
    }
};
