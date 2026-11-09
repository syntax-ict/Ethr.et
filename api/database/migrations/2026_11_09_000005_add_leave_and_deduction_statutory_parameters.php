<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * The statutory values leave, sick pay, the deduction cap and pension
     * coverage used as constants in code until this migration, as dated,
     * cited rows (docs/payroll/STATUTORY-PARAMETERS.md). Each value is the
     * constant the code held, with the article the code cited, so no figure
     * moves. All are `locked`: the law's value. Where the law sets a floor an
     * organisation may exceed through its own leave types, `floor_direction`
     * is `min`; the deduction cap is a ceiling an organisation may go below,
     * `max`.
     *
     * Every row starts 2016-07-08, the date the existing statutory rows
     * start from, because the constants applied to every period: a
     * reprocessed early period resolves exactly as it did.
     *
     * `confirmed` stays false until the accountant sign-off
     * (ACCOUNTANT-SIGN-OFF.md): the research behind the payroll rules did not
     * cover leave, and coverage of casual and consultancy contracts is open
     * (research P11).
     */
    public function up(): void
    {
        $versionId = DB::table('profile_versions')->where('version', '2026.11.2')->value('id');
        $now = now();

        if ($versionId === null) {
            $versionId = DB::table('profile_versions')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'version' => '2026.11.2',
                'organization_type' => null,
                'published_at' => $now,
                'notes' => 'Leave entitlements, Art. 85 sick pay, the Art. 59 deduction cap and pension coverage by contract type, moved from code.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (self::parameters() as $parameter) {
            $exists = DB::table('statutory_parameters')
                ->whereNull('tenant_id')
                ->whereNull('organization_type')
                ->where('code', $parameter['code'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('statutory_parameters')->insert([
                'public_id' => (string) Str::ulid(),
                'tenant_id' => null,
                'organization_type' => null,
                'code' => $parameter['code'],
                'value' => json_encode($parameter['value']),
                'tenant_overridable' => false,
                'enforcement' => 'locked',
                'floor_direction' => $parameter['floor'] ?? null,
                'profile_version_id' => $versionId,
                'legal_reference' => $parameter['reference'],
                'confirmed' => false,
                'note' => $parameter['note'] ?? null,
                'effective_from' => '2016-07-08',
                'effective_to' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('statutory_parameters')
            ->whereNull('tenant_id')
            ->whereIn('code', array_column(self::parameters(), 'code'))
            ->delete();
        DB::table('profile_versions')->where('version', '2026.11.2')->delete();
    }

    /** @return list<array{code: string, value: mixed, reference: string, floor?: string, note?: string}> */
    private static function parameters(): array
    {
        $labour = 'Labour Proclamation No. 1156/2019';

        return [
            ['code' => 'leave.annual.base_days', 'value' => 16, 'reference' => "{$labour}, Art. 77(1)", 'floor' => 'min',
                'note' => 'Working days of annual leave in the first year. A leave type may grant more, never less.'],
            ['code' => 'leave.annual.seniority', 'value' => ['every_years' => 2, 'extra_days' => 1], 'reference' => "{$labour}, Art. 77(1)", 'floor' => 'min',
                'note' => 'One more working day for every additional two years of service.'],
            ['code' => 'leave.maternity.days', 'value' => 120, 'reference' => "{$labour}, Art. 88", 'floor' => 'min',
                'note' => '30 days before and 90 after confinement, consecutive, so counted as calendar days.'],
            ['code' => 'leave.paternity.days', 'value' => 3, 'reference' => $labour, 'floor' => 'min',
                'note' => 'TODO(legal-verify): the article is not cited in code; ACCOUNTANT-SIGN-OFF.md.'],
            ['code' => 'leave.sick.tiers', 'value' => ['full_months' => 1, 'half_months' => 2, 'unpaid_months' => 3], 'reference' => "{$labour}, Art. 85",
                'note' => 'Within the window: the first month at full pay, the next two at half, the last three unpaid; a month is payroll.working_days_per_month working days.'],
            ['code' => 'leave.sick.window_months', 'value' => 12, 'reference' => "{$labour}, Art. 85",
                'note' => 'Counted from the first day of sickness.'],
            ['code' => 'leave.sick.half_pay_ratio', 'value' => ['numerator' => 1, 'denominator' => 2], 'reference' => "{$labour}, Art. 85"],
            ['code' => 'payroll.deduction_cap_ratio', 'value' => ['numerator' => 1, 'denominator' => 3], 'reference' => "{$labour}, Art. 59", 'floor' => 'max',
                'note' => 'Deductions from a month\'s wage may not pass this share of it, read as one total (decided 2026-10-28).'],
            ['code' => 'pension.applies_to', 'value' => ['permanent' => true, 'fixed_term' => true, 'probation' => true, 'casual' => true, 'consultancy' => true],
                'reference' => 'Private Organization Employees Pension Proclamation No. 1268/2022, Art. 2; research P11',
                'note' => 'Which contract types pension covers. TODO(legal-verify): casual and consultancy keep the behaviour from before this row; ACCOUNTANT-SIGN-OFF.md.'],
        ];
    }
};
