<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Afaan Oromoo twins for the platform's own wording, beside the `_am` ones:
 * the site-wide labels in `platform_settings` (name, tagline, office address,
 * uptime note, testimonial, the "Powered by" mark, payment instructions) and
 * the plan catalogue's description and selling points.
 *
 * Until now an Oromo reader saw the English of each, on the public site, the
 * pricing page, the sidebar mark and the billing page. Nullable, as the `_am`
 * columns are: an empty Oromo field falls back to English.
 *
 * Appended, never `->after()`: Scramble builds the contract from column
 * order, and only MySQL honours the clause.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->text('payment_instructions_om')->nullable();
            $table->string('platform_name_om')->nullable();
            $table->string('tagline_om', 300)->nullable();
            $table->string('office_address_om', 500)->nullable();
            $table->string('metric_uptime_note_om', 200)->nullable();
            $table->string('testimonial_quote_om', 500)->nullable();
            $table->string('testimonial_role_om', 150)->nullable();
            $table->string('product_mark_label_om', 60)->nullable();
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->string('description_om', 500)->nullable();
            $table->json('marketing_features_om')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['description_om', 'marketing_features_om']);
        });

        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn([
                'payment_instructions_om',
                'platform_name_om',
                'tagline_om',
                'office_address_om',
                'metric_uptime_note_om',
                'testimonial_quote_om',
                'testimonial_role_om',
                'product_mark_label_om',
            ]);
        });
    }
};
