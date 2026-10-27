<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every organisation in use is listed in search results by default (owner
 * decision 2026-10-26). Until now listing was off by default, and the
 * settings form saves the switch with every other portal field, so a stored
 * `false` cannot tell an organisation that chose to stay out from one that
 * only saved its headline. The owner asked for every current organisation
 * to be findable, so each stored `false` is removed and reads as the new
 * default; an organisation that wants out switches it off again, and that
 * `false` is then its choice.
 *
 * Read and written as raw JSON on `tenants`, a global table with no tenant
 * scope. Irreversible: the old values meant nothing a rollback could restore.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenants')
            ->whereNotNull('landing')
            ->select(['id', 'landing'])
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $landing = json_decode((string) $row->landing, true);
                    if (! is_array($landing) || ! array_key_exists('listed', $landing) || $landing['listed'] === true) {
                        continue;
                    }
                    unset($landing['listed']);
                    DB::table('tenants')->where('id', $row->id)->update(['landing' => json_encode($landing)]);
                }
            });
    }

    public function down(): void
    {
        //
    }
};
