<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Every organisation is named under "Trusted by" on ETHR's public site (owner
 * decision 2026-10-26), where until now only those whose administrator agreed
 * were. New organisations are listed on creation (Tenant::booted); this lists
 * the ones that already exist. A null `showcase_consented_at` cannot tell an
 * organisation that never decided from one that withdrew, and the owner asked
 * for all of them, so every null is set; an organisation that wants out
 * switches it off again in its settings. The platform's own veto
 * (`showcase_hidden_at`) is untouched, and an organisation still appears only
 * while it is in use.
 *
 * `tenants` is a global table with no tenant scope. Irreversible: the old
 * nulls meant two things a rollback could not separate.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenants')
            ->whereNull('showcase_consented_at')
            ->update(['showcase_consented_at' => now()]);

        Cache::forget(Tenant::SHOWCASE_CACHE_KEY);
    }

    public function down(): void
    {
        //
    }
};
