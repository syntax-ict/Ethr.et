<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `key_prefix` was created as VARCHAR(8), and ApiKeyController::store
     * writes the first 12 characters of the key (`ethr_` and seven more) — the
     * prefix the settings page shows. Strict MySQL refused the insert, so in
     * production every "Create Key" answered 500 (production QA 2026-10-11,
     * P3). SQLite ignores VARCHAR length and the MariaDB test connection is not
     * strict, which is why no gate saw it. 16 leaves room without inviting a
     * longer prefix. SCIM tokens write 8 characters and are unaffected.
     */
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->string('key_prefix', 16)->change();
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->string('key_prefix', 8)->change();
        });
    }
};
