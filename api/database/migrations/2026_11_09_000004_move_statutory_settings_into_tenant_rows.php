<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Four statutory values an organisation could set before they were rows
     * lived in `tenants.settings`, and StatutoryRules read them there when
     * the organisation had no row of its own: two places for one value. Each
     * becomes the organisation's own statutory_parameters row, which is now
     * the only place the resolver looks.
     *
     * A setting carried no date and applied to every period, so its row runs
     * from the first day the platform has any row for the code: every period
     * resolves to the value it resolved to yesterday. The settings keys are
     * left in place, untouched, so code from before this migration still
     * reads what it read.
     *
     * Skipped where the organisation already has a row for the code, which
     * the resolver preferred over the setting anyway.
     */
    private const SETTINGS = [
        'pension.default_scheme' => 'pension_scheme',
        'fiscal_year.start_month' => 'fiscal_year_start_month',
        'pagume.proration' => 'pagumen_proration_strategy',
        'payroll.working_days_per_month' => 'working_days_per_month',
    ];

    private const NOTE = 'Moved from the organisation settings key on 2026-11-09; it applied to every period.';

    public function up(): void
    {
        $now = now();

        foreach (self::SETTINGS as $code => $key) {
            $platform = DB::table('statutory_parameters')
                ->whereNull('tenant_id')
                ->where('code', $code)
                ->orderBy('effective_from')
                ->first(['value', 'effective_from']);

            if ($platform === null) {
                continue;
            }

            $platformValue = json_decode((string) $platform->value, true);

            DB::table('tenants')->orderBy('id')->select(['id', 'settings'])->each(function (object $tenant) use ($code, $key, $platform, $platformValue, $now): void {
                $settings = is_string($tenant->settings) ? json_decode($tenant->settings, true) : null;
                $value = is_array($settings) ? ($settings[$key] ?? null) : null;

                if (! is_scalar($value) || $value === '') {
                    return;
                }

                $exists = DB::table('statutory_parameters')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $code)
                    ->exists();

                if ($exists) {
                    return;
                }

                DB::table('statutory_parameters')->insert([
                    'public_id' => (string) Str::ulid(),
                    'tenant_id' => $tenant->id,
                    'code' => $code,
                    'organization_type' => null,
                    'value' => json_encode(is_int($platformValue) && is_numeric($value) ? (int) $value : $value),
                    'tenant_overridable' => true,
                    'enforcement' => 'default',
                    'legal_reference' => null,
                    'confirmed' => false,
                    'note' => self::NOTE,
                    'effective_from' => $platform->effective_from,
                    'effective_to' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
        }
    }

    public function down(): void
    {
        DB::table('statutory_parameters')
            ->whereNotNull('tenant_id')
            ->where('note', self::NOTE)
            ->delete();
    }
};
