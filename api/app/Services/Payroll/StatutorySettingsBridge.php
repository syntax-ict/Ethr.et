<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Four statutory values an organisation could set as `tenants.settings` keys
 * before they were rows. The resolver no longer reads the keys: this writes
 * each one through to the organisation's own statutory_parameters row
 * whenever the settings are saved, so the row is the single place the value
 * lives and every existing path that writes the key (the settings page, the
 * platform console, onboarding) keeps working.
 *
 * A key carried no date and applied to every period, so its row runs from
 * the first day the platform has any row for the code. Removing the key
 * removes the row. A dated row an organisation sets on the rules page is a
 * different row and is never touched here.
 *
 * Every write is audited here, whichever path made it:
 * `statutory_parameter.overridden` (from, to) and `statutory_parameter.reset`
 * (from).
 */
final class StatutorySettingsBridge
{
    /** @var array<string, string> code => settings key */
    public const SETTINGS = [
        'pension.default_scheme' => 'pension_scheme',
        'fiscal_year.start_month' => 'fiscal_year_start_month',
        'pagume.proration' => 'pagumen_proration_strategy',
        'payroll.working_days_per_month' => 'working_days_per_month',
    ];

    /** Marks the rows this bridge owns; the 2026-11-09 migration wrote the same. */
    public const NOTE = 'Moved from the organisation settings key on 2026-11-09; it applied to every period.';

    public static function sync(Tenant $tenant): void
    {
        if ($tenant->wasChanged('organization_type')) {
            app(StatutoryRules::class)->forget();
        }

        if (! $tenant->wasRecentlyCreated && ! $tenant->wasChanged('settings')) {
            return;
        }

        $settings = $tenant->getAttribute('settings');
        $settings = is_array($settings) ? $settings : [];
        $now = now();
        $changed = false;

        foreach (self::SETTINGS as $code => $key) {
            $value = $settings[$key] ?? null;
            $own = DB::table('statutory_parameters')
                ->where('tenant_id', $tenant->id)
                ->where('code', $code)
                ->where('note', self::NOTE);

            if (! is_scalar($value) || $value === '') {
                $removed = (clone $own)->first(['value']);
                if ($removed !== null && $own->delete() > 0) {
                    AuditLog::record('statutory_parameter.reset', $tenant, ['code' => $code, 'from' => json_decode((string) $removed->value, true)]);
                    $changed = true;
                }

                continue;
            }

            $platform = DB::table('statutory_parameters')
                ->whereNull('tenant_id')
                ->where('code', $code)
                ->orderBy('effective_from')
                ->first(['value', 'effective_from']);

            if ($platform === null) {
                continue;
            }

            $platformValue = json_decode((string) $platform->value, true);
            $encoded = json_encode(is_int($platformValue) && is_numeric($value) ? (int) $value : $value);

            $existing = (clone $own)->first(['id', 'value']);
            if ($existing !== null) {
                if ($existing->value !== $encoded) {
                    DB::table('statutory_parameters')->where('id', $existing->id)->update(['value' => $encoded, 'updated_at' => $now]);
                    AuditLog::record('statutory_parameter.overridden', $tenant, ['code' => $code, 'from' => json_decode((string) $existing->value, true), 'to' => json_decode((string) $encoded, true)]);
                    $changed = true;
                }

                continue;
            }

            DB::table('statutory_parameters')->insert([
                'public_id' => (string) Str::ulid(),
                'tenant_id' => $tenant->id,
                'code' => $code,
                'organization_type' => null,
                'value' => $encoded,
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
            AuditLog::record('statutory_parameter.overridden', $tenant, ['code' => $code, 'from' => null, 'to' => json_decode((string) $encoded, true)]);
            $changed = true;
        }

        if ($changed) {
            app(StatutoryRules::class)->forget();
        }
    }
}
