<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Two webhook columns convention 4 and the secrets rule did not cover (audit N18).
 *
 * `webhooks.secret` signs every delivery, and was stored as plaintext in a
 * 64-character column: a database read was enough to forge a subscriber's
 * payloads. It is now under the `encrypted` cast, like `Employee.tin`. An
 * encrypted value is several times longer than 64 characters, so the column
 * widens to TEXT before the existing secrets are encrypted in place.
 *
 * `webhook_deliveries` had no `public_id`, so the `X-ETHR-Delivery` header a
 * subscriber deduplicates on was the numeric primary key. Same shape as
 * `2026_10_01_000001_add_public_id_to_shift_assignments`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->text('secret')->change();
        });

        DB::table('webhooks')->orderBy('id')->select(['id', 'secret'])->chunk(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('webhooks')
                    ->where('id', $row->id)
                    ->update(['secret' => Crypt::encryptString((string) $row->secret)]);
            }
        });

        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->char('public_id', 26)->nullable()->after('id');
        });

        DB::table('webhook_deliveries')->orderBy('id')->select('id')->chunk(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('webhook_deliveries')
                    ->where('id', $row->id)
                    ->update(['public_id' => (string) Str::ulid()]);
            }
        });

        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->char('public_id', 26)->nullable(false)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropUnique('webhook_deliveries_public_id_unique');
            $table->dropColumn('public_id');
        });

        DB::table('webhooks')->orderBy('id')->select(['id', 'secret'])->chunk(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('webhooks')
                    ->where('id', $row->id)
                    ->update(['secret' => Crypt::decryptString((string) $row->secret)]);
            }
        });

        Schema::table('webhooks', function (Blueprint $table) {
            $table->string('secret', 64)->change();
        });
    }
};
