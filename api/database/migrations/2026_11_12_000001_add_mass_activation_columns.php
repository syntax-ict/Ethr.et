<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a whole list of people is brought in with, and codes issued for many
 * people at once (One employee identity, PR 9).
 *
 * `migration_batches.options` holds what the reviewer chose for the list when
 * they began adding it: the status its new people arrive with. It is kept
 * with the list because a long one is added over several requests, and one
 * resumed from another tab has to go on as it began.
 *
 * `account_activation_codes.issued_batch` groups the codes of one printed
 * sheet. A code issued for one person, face to face, has none.
 *
 * Both are nullable and nothing is backfilled: the release steps run over
 * HTTP inside thirty seconds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migration_batches', function (Blueprint $table) {
            $table->json('options')->nullable();
        });

        Schema::table('account_activation_codes', function (Blueprint $table) {
            $table->char('issued_batch', 26)->nullable();
            $table->index(['tenant_id', 'issued_batch'], 'activation_codes_issued_batch_index');
        });
    }

    public function down(): void
    {
        Schema::table('account_activation_codes', function (Blueprint $table) {
            $table->dropIndex('activation_codes_issued_batch_index');
            $table->dropColumn('issued_batch');
        });

        Schema::table('migration_batches', function (Blueprint $table) {
            $table->dropColumn('options');
        });
    }
};
