<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maps external identifiers (a ZKTeco user id, a Hikvision card number, an
 * import UUID, an AD object id) to the one internal employee they belong to.
 *
 * ETHR is the master identity: an employee may carry many external identities
 * across many devices and systems, but each external identity resolves to
 * exactly one employee. The unique key spans (source_ref, identifier) so the
 * same user id on two different devices is two distinct mappings — that is what
 * makes multi-device sync safe. See ONBOARDING_V2.md decision D4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_external_identities', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // Where the identity came from and, when relevant, which instance —
            // e.g. source_type 'zkteco', source_ref 'device:5'. Non-null so the
            // unique index below is deterministic across databases.
            $table->string('source_type', 50);
            $table->string('source_ref', 100)->default('');

            // What kind of external id this is and its value — e.g.
            // identifier_type 'device_user_id', identifier_value '1102'.
            $table->string('identifier_type', 40);
            $table->string('identifier_value', 191);

            // Confidence at the time the mapping was made; verified_at is set once
            // an admin confirms it (an unverified auto-link can be re-reviewed).
            $table->decimal('confidence', 3, 2)->default(1.00);
            $table->timestamp('verified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'source_type', 'source_ref', 'identifier_type', 'identifier_value'],
                'eei_unique_mapping'
            );
            $table->index(['tenant_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_external_identities');
    }
};
