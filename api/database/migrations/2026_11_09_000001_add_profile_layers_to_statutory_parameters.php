<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * How binding each statutory value is, which sector it is for, and which
     * published batch of the law it belongs to (docs/payroll/STATUTORY-PARAMETERS.md).
     *
     * `enforcement`:
     *  - `locked`   the law's value; an organisation has no row of its own.
     *               `floor_direction` says how an organisation's own mechanism
     *               elsewhere (the overtime rates card, a leave type's days)
     *               is checked against it: `min` may not go below, `max` may
     *               not go above.
     *  - `default`  the organisation may replace it with its own dated row.
     *  - `advisory` the same, shown as guidance.
     *
     * `tenant_overridable` stays, as the projection `enforcement != locked`,
     * because the column cannot be dropped. The backfill keeps every row's
     * meaning: each row that was overridable becomes `default`, every other
     * row `locked`, so no organisation's payroll moves.
     *
     * Every platform row in place is stamped with the first published batch,
     * 2026.11.1. A later change in the law is a new batch and new dated rows;
     * an organisation's own rows are never touched by one.
     */
    public function up(): void
    {
        Schema::create('profile_versions', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('version', 20)->unique();
            $table->string('organization_type', 40)->nullable();
            $table->timestamp('published_at')->useCurrent();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::table('statutory_parameters', function (Blueprint $table): void {
            $table->string('enforcement', 16)->default('locked');
            $table->string('floor_direction', 8)->nullable();
            $table->string('sector', 60)->nullable();
            $table->foreignId('profile_version_id')->nullable()->constrained('profile_versions')->nullOnDelete();

            $table->index(['code', 'sector'], 'stat_params_code_sector_idx');
        });

        DB::table('statutory_parameters')->where('tenant_overridable', true)->update(['enforcement' => 'default']);
        DB::table('statutory_parameters')->where('tenant_overridable', false)->update(['enforcement' => 'locked']);
        DB::table('statutory_parameters')->whereNull('tenant_id')->where('code', 'overtime.minimum_rates')->update(['floor_direction' => 'min']);

        $now = now();
        $versionId = DB::table('profile_versions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'version' => '2026.11.1',
            'organization_type' => null,
            'published_at' => $now,
            'notes' => 'The statutory parameters in force when profile versions were introduced.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('statutory_parameters')->whereNull('tenant_id')->update(['profile_version_id' => $versionId]);
    }

    public function down(): void
    {
        Schema::table('statutory_parameters', function (Blueprint $table): void {
            $table->dropIndex('stat_params_code_sector_idx');
            $table->dropConstrainedForeignId('profile_version_id');
            $table->dropColumn(['enforcement', 'floor_direction', 'sector']);
        });

        Schema::dropIfExists('profile_versions');
    }
};
