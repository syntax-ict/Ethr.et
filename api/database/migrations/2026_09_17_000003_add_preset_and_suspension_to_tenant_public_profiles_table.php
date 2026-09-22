<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns, each with a deliberately chosen null meaning.
 *
 * `preset` null means "render the classic layout". Every row that exists when
 * this migration runs gets null, so **deploying this changes no live page** —
 * a tenant moves to the new layout only when an administrator opts in. That is
 * the whole rollout guarantee, and it is a default rather than a code branch
 * precisely so it cannot be forgotten.
 *
 * `suspended_at` is the platform's takedown switch. `*.ethr.et` is ETHR's own
 * domain, so there has to be a way to pull a page that is fraudulent or
 * impersonating, and until now there was none. It is separate from
 * `is_published` on purpose: suspending must not destroy the tenant's own
 * publication state, so restoring is one column write rather than a guess
 * about what the tenant had wanted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_public_profiles', function (Blueprint $table) {
            $table->string('preset', 32)->nullable()->after('is_indexable');
            $table->timestamp('suspended_at')->nullable()->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_public_profiles', function (Blueprint $table) {
            $table->dropColumn(['preset', 'suspended_at']);
        });
    }
};
