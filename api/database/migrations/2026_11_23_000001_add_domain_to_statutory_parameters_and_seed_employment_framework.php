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
     * Statutory rules gain a domain. Payroll reads `payroll`, which every
     * existing row becomes; the HR rules start as `employment`, so payroll,
     * its calculation log and the Statutory rules page never see them
     * (docs/payroll/STATUTORY-PARAMETERS.md, "Domains").
     *
     * The first employment rows say which body of law governs each kind of
     * organisation. All are advisory and unconfirmed. Child kinds inherit
     * through their parent; `regional_government` has its own row so it does
     * not inherit `government`'s. A row starts on the date of the law it
     * cites, so a date before that has no framework on file.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('statutory_parameters', 'domain')) {
            Schema::table('statutory_parameters', function (Blueprint $table): void {
                $table->string('domain', 32)->default('payroll')->after('code');
                $table->index(['domain', 'code'], 'stat_params_domain_code_idx');
            });
        }

        $now = now();
        $versionId = DB::table('profile_versions')->where('version', '2026.11.3')->value('id');

        if ($versionId === null) {
            $versionId = DB::table('profile_versions')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'version' => '2026.11.3',
                'organization_type' => null,
                'published_at' => $now,
                'notes' => 'Employment framework by organisation kind, the first rows of the employment domain.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (self::frameworks() as $row) {
            $exists = DB::table('statutory_parameters')
                ->whereNull('tenant_id')
                ->where('code', 'employment.framework')
                ->where('organization_type', $row['type'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('statutory_parameters')->insert([
                'public_id' => (string) Str::ulid(),
                'tenant_id' => null,
                'code' => 'employment.framework',
                'domain' => 'employment',
                'organization_type' => $row['type'],
                'value' => json_encode($row['value']),
                'tenant_overridable' => true,
                'enforcement' => 'advisory',
                'floor_direction' => null,
                'sector' => null,
                'profile_version_id' => $versionId,
                'legal_reference' => $row['reference'],
                'confirmed' => false,
                'note' => $row['note'],
                'effective_from' => $row['from'],
                'effective_to' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Every employment row goes, an organisation's own included: once the
        // column is dropped nothing would mark them as outside payroll.
        DB::table('statutory_parameters')->where('domain', 'employment')->delete();
        DB::table('profile_versions')->where('version', '2026.11.3')->delete();

        Schema::table('statutory_parameters', function (Blueprint $table): void {
            $table->dropIndex('stat_params_domain_code_idx');
            $table->dropColumn('domain');
        });
    }

    /** @return list<array{type: string, value: string, from: string, reference: string|null, note: string}> */
    private static function frameworks(): array
    {
        $labour = 'Labour Proclamation No. 1156/2019, Art. 3';
        $civil = 'Federal Civil Servants Proclamation No. 1353/2024';

        return [
            ['type' => 'private', 'value' => 'labour_proclamation', 'from' => '2019-09-05', 'reference' => $labour,
                'note' => 'Managerial employees and others listed in Art. 3(2) are outside it.'],
            ['type' => 'ngo', 'value' => 'labour_proclamation', 'from' => '2019-09-05', 'reference' => $labour,
                'note' => 'Art. 3(3) lets the Council of Ministers exclude religious or charitable organisations by regulation; whether one exists is not verified.'],
            ['type' => 'public_enterprise', 'value' => 'labour_proclamation', 'from' => '2019-09-05', 'reference' => "{$labour}; {$civil}, Art. 2",
                'note' => 'A reading, not an express text: a public enterprise is outside the definition of a government office.'],
            ['type' => 'government', 'value' => 'federal_civil_service', 'from' => '2025-01-09', 'reference' => "{$civil}, Arts. 2 and 3",
                'note' => 'Two Gazette prints exist (10 December 2024 and 9 January 2025); the later date is used.'],
            ['type' => 'regional_government', 'value' => 'regional_civil_service', 'from' => '2025-01-09', 'reference' => "{$civil}, Arts. 2 and 3",
                'note' => 'The federal proclamation covers federal offices only. Each region has its own law; only a secondary copy of one (Amhara 171/2010) has been seen, and nothing relies on it.'],
            ['type' => 'defence_police', 'value' => 'unspecified', 'from' => '2019-09-05', 'reference' => 'Labour Proclamation No. 1156/2019, Art. 3(2)(e)',
                'note' => 'Governed by special laws that have not been read.'],
            ['type' => 'international_organization', 'value' => 'unspecified', 'from' => '2019-09-05', 'reference' => null,
                'note' => 'No framework is recorded for international organisations and missions.'],
        ];
    }
};
