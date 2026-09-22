<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ordered blocks that make up a tenant's public landing page.
 *
 * Tenant-scoped like every other content table, so `BelongsToTenant`'s
 * fail-closed global scope applies: with no resolved tenant these rows are
 * invisible rather than arbitrary.
 *
 * Text columns come in pairs — `heading` and `heading_am` — rather than a JSON
 * blob keyed by locale. Two reasons: a column can be length-validated and
 * indexed, and a blob invites a third locale being added to the data without
 * anything being added to the template that renders it.
 *
 * `position` is dense and normalised on every write rather than unique. A
 * unique constraint on (tenant_id, position) sounds tidier and makes reordering
 * a deadlock-prone dance of temporary values; renumbering the whole set inside
 * one transaction is simpler and cannot half-apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_public_sections', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 32);
            $table->unsignedSmallInteger('position')->default(0);

            // Sections seeded for a tenant that has not filled them in yet are
            // created hidden, so opting into the new layout never publishes an
            // empty block with a placeholder heading.
            $table->boolean('is_visible')->default(true);

            $table->string('heading', 160)->nullable();
            $table->string('heading_am', 160)->nullable();
            $table->text('intro')->nullable();
            $table->text('intro_am')->nullable();

            // A per-kind presentation variant (grid, list, …). Validated
            // against a fixed list per kind, never free text.
            $table->string('layout', 32)->nullable();

            // Per-kind settings that do not deserve a column each. Never
            // rendered directly — a partial reads the keys it knows.
            $table->json('options')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'position']);
            $table->index(['tenant_id', 'is_visible']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_public_sections');
    }
};
