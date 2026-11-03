<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Afaan Oromoo twins for the landing page's text, beside the `_am` ones.
 *
 * `/om` is a prerendered public route since Oromo shipped (2026-11-02), and
 * every section on it was the operator's English: the page's chrome was
 * Oromo and its body was not. Nullable, as the `_am` columns are: an empty
 * Oromo field falls back to English on the page.
 *
 * The built-in Oromo copy is written by MarketingContentSeeder, which
 * ProductionSeeder runs on every release, and only into a row whose English
 * is still the seeded English, so an operator's own wording is never paired
 * with a translation of something else.
 *
 * Appended, never `->after()`: Scramble builds the contract from column
 * order, and only MySQL honours the clause (see the create migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_sections', function (Blueprint $table) {
            $table->string('heading_om', 300)->nullable();
            $table->string('subheading_om', 600)->nullable();
            $table->string('image_alt_om', 300)->nullable();
        });

        Schema::table('marketing_items', function (Blueprint $table) {
            $table->string('title_om', 200)->nullable();
            $table->string('body_om', 1000)->nullable();
            $table->string('detail_om', 1000)->nullable();
            $table->string('image_alt_om', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('marketing_items', function (Blueprint $table) {
            $table->dropColumn(['title_om', 'body_om', 'detail_om', 'image_alt_om']);
        });

        Schema::table('marketing_sections', function (Blueprint $table) {
            $table->dropColumn(['heading_om', 'subheading_om', 'image_alt_om']);
        });
    }
};
