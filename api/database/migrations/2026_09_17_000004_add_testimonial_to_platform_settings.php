<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer quote that has to come from a customer.
 *
 * The landing page carried one as literal JSX: five filled stars, an invented
 * sentence, and "Abebe Kebede, HR Director, Addis Manufacturing PLC" — a person
 * who does not exist, attributed a claim about a product they have not used. It
 * is the same defect as the invented metrics, and worse in kind: a fabricated
 * number is a guess, a fabricated customer is a fabricated customer. Two
 * documents on this branch also said it had already been deleted while it was
 * still rendering, which is how it survived an audit written about exactly this.
 *
 * So it becomes a row with an owner, like the metrics did, and starts empty.
 *
 * `testimonial_consented_on` IS THE COLUMN THAT MAKES IT REAL. The other fields
 * would hold an invented quote just as willingly as a true one; this one cannot
 * be filled in by someone who never spoke to a customer without knowingly
 * writing down a date that did not happen. It is deliberately not published —
 * a visitor has no use for it — but the public resource refuses to publish the
 * quote without it, so "we have consent on file" is a precondition of the
 * section appearing rather than a thing anyone has to remember.
 *
 * Paired `_am` columns for the prose, following payment_instructions_am and the
 * site-content columns before it. `testimonial_author` and
 * `testimonial_organisation` have no pair on purpose: a person's name and a
 * company's registered name are not translated, and a column inviting a second
 * spelling of either would invite getting one of them wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No ->after(): MySQL honours it and SQLite ignores it, so the two
        // engines end up with different column order for the same schema, and
        // Scramble derives the OpenAPI component from column order. See
        // add_catalog_columns_to_plans for the contract-gate failure that
        // taught this.
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->string('testimonial_quote', 500)->nullable();
            $table->string('testimonial_quote_am', 500)->nullable();
            $table->string('testimonial_author', 120)->nullable();
            $table->string('testimonial_role', 150)->nullable();
            $table->string('testimonial_role_am', 150)->nullable();
            $table->string('testimonial_organisation', 150)->nullable();
            $table->date('testimonial_consented_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn([
                'testimonial_quote',
                'testimonial_quote_am',
                'testimonial_author',
                'testimonial_role',
                'testimonial_role_am',
                'testimonial_organisation',
                'testimonial_consented_on',
            ]);
        });
    }
};
