<?php

declare(strict_types=1);

use Database\Seeders\TaxBracketSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Statutory rules as data, and aware of the kind of organisation.
     *
     * Until now the payroll engine fell back to constants in code: 22 working
     * days, 8 hours, the Art. 68 overtime multipliers, the Art. 67 limits, the
     * night window, the list of pension schemes. Each becomes a dated row in
     * `statutory_parameters`, seeded below with the value the engine already
     * used, so no payslip moves (docs/payroll/STATUTORY-PARAMETERS.md).
     *
     * A row may be scoped to an organisation type. The engine reads the
     * organisation's own override where the rule allows one, then the
     * platform row for its type, then the platform row for every type
     * (App\Services\Payroll\StatutoryRules).
     *
     * `organization_types` is the catalogue of those types, global and edited
     * from the platform console. `tenants.organization_type` is distinct from
     * `tenants.type`, which stays the industry chosen at registration.
     *
     * Platform rows are written with DB::table: creating through the model
     * stamps the current tenant, as for `pension_rules`.
     */
    public function up(): void
    {
        Schema::create('organization_types', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->string('name_am', 100);
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('statutory_parameters', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('organization_type', 40)->nullable();
            $table->json('value');
            $table->boolean('tenant_overridable')->default(false);
            $table->string('legal_reference', 200)->nullable();
            $table->boolean('confirmed')->default(false);
            $table->string('note', 500)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['code', 'organization_type', 'effective_from'], 'statutory_parameters_code_type_from_index');
            $table->index(['tenant_id', 'code'], 'statutory_parameters_tenant_code_index');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('organization_type', 40)->default('private');
            $table->boolean('organization_type_needs_review')->default(false);
        });

        Schema::table('tax_brackets', function (Blueprint $table): void {
            $table->string('organization_type', 40)->nullable();
        });

        Schema::table('pension_rules', function (Blueprint $table): void {
            $table->string('organization_type', 40)->nullable();
        });

        $now = now();

        foreach (self::types() as $order => [$code, $name, $nameAm, $description]) {
            DB::table('organization_types')->insert([
                'public_id' => (string) Str::ulid(),
                'code' => $code,
                'name' => $name,
                'name_am' => $nameAm,
                'description' => $description,
                'sort_order' => ($order + 1) * 10,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (self::parameters() as $parameter) {
            DB::table('statutory_parameters')->insert([
                'public_id' => (string) Str::ulid(),
                'tenant_id' => null,
                'organization_type' => $parameter['type'] ?? null,
                'code' => $parameter['code'],
                'value' => json_encode($parameter['value']),
                'tenant_overridable' => $parameter['overridable'] ?? false,
                'legal_reference' => $parameter['reference'],
                'confirmed' => $parameter['confirmed'],
                'note' => $parameter['note'] ?? null,
                'effective_from' => $parameter['from'] ?? '2016-07-08',
                'effective_to' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        self::ensureSupersededTaxLadder($now);
        self::backfillTenants();
    }

    /**
     * The 979/2016 ladder as platform rows, where the database has none.
     *
     * TaxCalculator used to fall back to it in code for a period before
     * 7 July 2025, and that fallback is gone. A deployment seeded with
     * TaxBracketSeeder already has these rows; one that only ran migrations
     * did not, and would otherwise refuse to reprocess a pre-amendment month.
     */
    private static function ensureSupersededTaxLadder(Carbon $now): void
    {
        $present = DB::table('tax_brackets')
            ->whereNull('tenant_id')
            ->whereDate('effective_from', '<', '2025-07-07')
            ->exists();

        if ($present) {
            return;
        }

        foreach (TaxBracketSeeder::PROCLAMATION_979_2016 as $bracket) {
            DB::table('tax_brackets')->insert([
                'public_id' => (string) Str::ulid(),
                'tenant_id' => null,
                'min_amount_cents' => $bracket['min'],
                'max_amount_cents' => $bracket['max'] ?? 0,
                'rate' => $bracket['rate'],
                'deduction_cents' => $bracket['deduction'],
                'effective_from' => '2016-07-08',
                'effective_to' => '2025-07-06',
                'legal_reference' => 'Income Tax Proclamation No. 979/2016',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('pension_rules', function (Blueprint $table): void {
            $table->dropColumn('organization_type');
        });
        Schema::table('tax_brackets', function (Blueprint $table): void {
            $table->dropColumn('organization_type');
        });
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['organization_type', 'organization_type_needs_review']);
        });
        Schema::dropIfExists('statutory_parameters');
        Schema::dropIfExists('organization_types');
    }

    /**
     * Every existing organisation is given the type its own settings suggest
     * and asked to confirm it. Nothing below is known, only inferred: the
     * pension scheme it chose, the policy profile it took at onboarding, the
     * free-text type it registered with.
     */
    private static function backfillTenants(): void
    {
        DB::table('tenants')->orderBy('id')->select(['id', 'type', 'settings'])->chunk(200, function ($tenants): void {
            foreach ($tenants as $tenant) {
                $settings = is_string($tenant->settings) ? (json_decode($tenant->settings, true) ?: []) : [];
                $scheme = $settings['pension_scheme'] ?? null;
                $policy = $settings['configuration_profile']['policy_profile'] ?? null;
                $type = strtolower((string) $tenant->type);

                $organizationType = match (true) {
                    $scheme === 'public_military' => 'defence_police',
                    $scheme === 'public', $policy === 'ethiopia_public', $type === 'government' => 'government',
                    $type === 'ngo' => 'ngo',
                    default => 'private',
                };

                DB::table('tenants')->where('id', $tenant->id)->update([
                    'organization_type' => $organizationType,
                    'organization_type_needs_review' => true,
                ]);
            }
        });
    }

    /** @return list<array{string, string, string, string}> */
    private static function types(): array
    {
        return [
            ['private', 'Private organisation', 'የግል ድርጅት', 'Companies, banks, private schools and clinics, and other private employers.'],
            ['public_enterprise', 'Public enterprise', 'የመንግሥት የልማት ድርጅት', 'Enterprises owned by the federal or a regional government.'],
            ['government', 'Government office (civil service)', 'የመንግሥት መሥሪያ ቤት', 'Federal and regional ministries, bureaus, agencies and public universities.'],
            ['ngo', 'Charity or society', 'በጎ አድራጎት ድርጅት ወይም ማኅበር', 'Charities, societies and other civil society organisations.'],
            ['international_organization', 'International organisation or embassy', 'ዓለም አቀፍ ድርጅት ወይም ኤምባሲ', 'International and regional organisations and diplomatic missions.'],
            ['defence_police', 'Defence or police', 'መከላከያ ወይም ፖሊስ', 'Defence forces and police, under the military pension scheme.'],
        ];
    }

    /**
     * The values the engine used as constants until this migration, with the
     * law each comes from. `confirmed` is true only where the value is the
     * text of the law; an ETHR convention or reading is false until the
     * accountant sign-off settles it (docs/payroll/STATUTORY-PARAMETERS.md).
     *
     * @return list<array<string, mixed>>
     */
    private static function parameters(): array
    {
        $labour = 'Labour Proclamation No. 1156/2019';
        $privatePension = 'Private Organization Employees Pension Proclamation No. 1268/2022';
        $publicPension = 'Public Servants Pension Proclamation No. 1267/2022';

        return [
            ['code' => 'payroll.working_days_per_month', 'value' => 22, 'reference' => 'ETHR convention (ACCOUNTANT-SIGN-OFF.md)', 'confirmed' => false,
                'note' => 'The monthly salary is divided by this to give the daily rate for unpaid leave and the hourly rate for overtime.'],
            ['code' => 'payroll.hours_per_day', 'value' => 8, 'reference' => "{$labour}, Art. 61(1)", 'confirmed' => true],
            ['code' => 'overtime.minimum_rates', 'value' => ['normal' => 1.5, 'night' => 1.75, 'rest_day' => 2.0, 'holiday' => 2.5, 'holiday_night' => 2.5],
                'reference' => "{$labour}, Art. 68(1)", 'confirmed' => true,
                'note' => 'The floor for each rate. An organisation may pay more on its overtime rates card, never less.'],
            ['code' => 'overtime.night_window', 'value' => ['start_hour' => 22, 'end_hour' => 6], 'reference' => "{$labour}, Art. 68(1)(b)", 'confirmed' => true],
            ['code' => 'overtime.legal_daily_minutes', 'value' => 240, 'reference' => "{$labour}, Art. 67", 'confirmed' => false,
                'note' => 'Used only to flag a run. Overtime worked is paid whatever the limit (Art. 68).'],
            ['code' => 'overtime.legal_weekly_minutes', 'value' => 720, 'reference' => "{$labour}, Art. 67", 'confirmed' => false,
                'note' => 'Used only to flag a run.'],
            ['code' => 'overtime.limited_types', 'value' => ['normal', 'night'], 'reference' => "ETHR reading of {$labour}, Art. 67", 'confirmed' => false,
                'note' => 'Rest-day and holiday work is paid at its own rate and not counted against the limits.'],
            ['code' => 'pension.schemes', 'value' => ['private', 'public', 'public_military'], 'reference' => "{$privatePension}; {$publicPension}", 'confirmed' => true],
            ['code' => 'pension.default_scheme', 'value' => 'private', 'reference' => $privatePension, 'confirmed' => true, 'overridable' => true],
            ['code' => 'pension.default_scheme', 'type' => 'public_enterprise', 'value' => 'public', 'reference' => $publicPension, 'confirmed' => false, 'overridable' => true,
                'note' => 'Coverage of public enterprise staff to be confirmed (research O1).'],
            ['code' => 'pension.default_scheme', 'type' => 'government', 'value' => 'public', 'reference' => "{$publicPension}, Art. 10", 'confirmed' => true, 'overridable' => true],
            ['code' => 'pension.default_scheme', 'type' => 'defence_police', 'value' => 'public_military', 'reference' => "{$publicPension}, Art. 11", 'confirmed' => true, 'overridable' => true],
            ['code' => 'fiscal_year.start_month', 'value' => 1, 'reference' => 'ETHR default (Meskerem)', 'confirmed' => false, 'overridable' => true],
            ['code' => 'fiscal_year.start_month', 'type' => 'government', 'value' => 11, 'reference' => 'Federal government fiscal year (Hamle 1)', 'confirmed' => false, 'overridable' => true],
            ['code' => 'pagume.proration', 'value' => 'full_month', 'reference' => 'ETHR default', 'confirmed' => false, 'overridable' => true,
                'note' => 'full_month pays Pagume with the month; daily_rate pays annual salary ÷ 365 per Pagume day.'],
        ];
    }
};
