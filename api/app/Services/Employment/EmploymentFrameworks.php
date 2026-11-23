<?php

declare(strict_types=1);

namespace App\Services\Employment;

use App\Enums\EmploymentFramework;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\StatutoryParameter;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\Payroll\StatutoryRules;
use App\Support\TenantTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Which body of employment law governs an organisation's staff, and so one
 * person's.
 *
 * ETHR seeds a reading per organisation kind (an advisory, unconfirmed
 * `employment.framework` row). An organisation may record its own, which is
 * dated like any other rule: a change closes the row in force the day before
 * and starts a new one today, so a question about an earlier date still gets
 * the answer it had then. Only a change made on the day a row started edits it
 * in place. Every write is audited, as the statutory settings' are
 * (StatutorySettingsBridge); the audit row's user is the signed-in one.
 *
 * An employee can sit outside the organisation's framework (a manager of a
 * private company, mixed staff of a public enterprise). `forEmployee()` then
 * answers null rather than the organisation's value.
 */
final class EmploymentFrameworks
{
    private const CODE = 'employment.framework';

    public function __construct(private readonly StatutoryRules $rules) {}

    /**
     * @return array{framework: string|null, confirmed: bool, source: string|null, legal_reference: string|null, has_statutory_rules: bool, ethr_reading: string|null}
     */
    public function describe(Tenant $tenant, CarbonInterface $on): array
    {
        $set = $this->rules->employment($tenant, $on);
        $rule = $set->describe(self::CODE);

        $framework = $rule !== null && is_string($rule['value']) ? EmploymentFramework::tryFrom($rule['value']) : null;
        $found = $framework !== null ? $rule : null;

        $reading = $this->rules
            ->platform($on, $set->organizationType, $set->sector, StatutoryParameter::DOMAIN_EMPLOYMENT)
            ->find(self::CODE);

        return [
            'framework' => $framework?->value,
            'confirmed' => (bool) ($found['confirmed'] ?? false),
            'source' => $found === null ? null : ($found['layer'] === 'tenant' ? 'organisation' : 'ethr'),
            'legal_reference' => $found['legal_reference'] ?? null,
            'has_statutory_rules' => (bool) $framework?->hasStatutoryRules(),
            'ethr_reading' => is_string($reading) && EmploymentFramework::tryFrom($reading) !== null ? $reading : null,
        ];
    }

    /** Records the organisation's own framework, from today in its own zone. */
    public function setOwn(Tenant $tenant, EmploymentFramework $framework, ?int $userId): void
    {
        $today = TenantTime::today($tenant);

        DB::transaction(function () use ($tenant, $framework, $today): void {
            $own = $this->ownInForce($tenant, $today);
            $before = $own !== null ? json_decode((string) $own->value, true) : null;

            if ($before === $framework->value) {
                return;
            }

            $encoded = json_encode($framework->value);
            $now = now();

            if ($own !== null && $own->effective_from === $today->toDateString()) {
                DB::table('statutory_parameters')
                    ->where('tenant_id', $tenant->id)
                    ->where('id', $own->id)
                    ->update(['value' => $encoded, 'confirmed' => true, 'updated_at' => $now]);
            } else {
                if ($own !== null) {
                    DB::table('statutory_parameters')
                        ->where('tenant_id', $tenant->id)
                        ->where('id', $own->id)
                        ->update(['effective_to' => $today->subDay()->toDateString(), 'updated_at' => $now]);
                }

                DB::table('statutory_parameters')->insert([
                    'public_id' => (string) Str::ulid(),
                    'tenant_id' => $tenant->id,
                    'code' => self::CODE,
                    'domain' => StatutoryParameter::DOMAIN_EMPLOYMENT,
                    'organization_type' => null,
                    'value' => $encoded,
                    'tenant_overridable' => true,
                    'enforcement' => StatutoryParameter::ADVISORY,
                    'floor_direction' => null,
                    'sector' => null,
                    'profile_version_id' => null,
                    'legal_reference' => null,
                    'confirmed' => true,
                    'note' => null,
                    'effective_from' => $today->toDateString(),
                    'effective_to' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            AuditLog::record('statutory_parameter.overridden', $tenant, ['code' => self::CODE, 'from' => $before, 'to' => $framework->value]);
        });

        $this->rules->forget();
    }

    /** Ends the organisation's own framework, so ETHR's reading applies again. */
    public function resetOwn(Tenant $tenant, ?int $userId): void
    {
        $today = TenantTime::today($tenant);

        DB::transaction(function () use ($tenant, $today): void {
            $own = $this->ownInForce($tenant, $today);

            if ($own === null) {
                return;
            }

            $row = DB::table('statutory_parameters')->where('tenant_id', $tenant->id)->where('id', $own->id);

            if ($own->effective_from === $today->toDateString()) {
                $row->delete();
            } else {
                $row->update(['effective_to' => $today->subDay()->toDateString(), 'updated_at' => now()]);
            }

            AuditLog::record('statutory_parameter.reset', $tenant, ['code' => self::CODE, 'from' => json_decode((string) $own->value, true)]);
        });

        $this->rules->forget();
    }

    /** The framework governing one person, or null when they are marked outside it or none is on file. */
    public function forEmployee(Employee $employee, CarbonInterface $on): ?EmploymentFramework
    {
        if ($employee->outside_employment_framework) {
            return null;
        }

        $tenant = app(CurrentTenant::class)->tenantFor($employee->tenant_id);

        return $tenant === null ? null : $this->rules->framework($tenant, $on);
    }

    private function ownInForce(Tenant $tenant, CarbonInterface $on): ?\stdClass
    {
        $date = $on->toDateString();

        $row = DB::table('statutory_parameters')
            ->where('tenant_id', $tenant->id)
            ->where('code', self::CODE)
            ->where('domain', StatutoryParameter::DOMAIN_EMPLOYMENT)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first(['id', 'value', 'effective_from']);

        if ($row === null) {
            return null;
        }

        $row->effective_from = substr((string) $row->effective_from, 0, 10);

        return $row;
    }
}
