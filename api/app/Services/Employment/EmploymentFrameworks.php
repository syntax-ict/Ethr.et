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
use App\Services\Payroll\StatutoryRuleSet;
use App\Support\TenantTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

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
 * A write takes the organisation's row lock first, so two saves cannot both
 * see "no row open" and each open one, and it ends every own row open on the
 * day, so a duplicate that got in cannot survive a change or a reset.
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

        $platform = $this->platformSet($set, $on)->describe(self::CODE);
        $reading = $platform !== null && is_string($platform['value']) && EmploymentFramework::tryFrom($platform['value']) !== null
            ? $platform['value']
            : null;

        // Confirming ETHR's own reading must not lose its citation.
        $reference = $found['legal_reference'] ?? null;
        if ($reference === null && $framework !== null && $found['layer'] === 'tenant' && $reading === $framework->value) {
            $reference = $platform['legal_reference'] ?? null;
        }

        return [
            'framework' => $framework?->value,
            'confirmed' => (bool) ($found['confirmed'] ?? false),
            'source' => $found === null ? null : ($found['layer'] === 'tenant' ? 'organisation' : 'ethr'),
            'legal_reference' => $reference,
            'has_statutory_rules' => (bool) $framework?->hasStatutoryRules(),
            'ethr_reading' => $reading,
        ];
    }

    /**
     * Records the organisation's own framework, from today in its own zone.
     *
     * @throws ValidationException when ETHR holds the rule locked for the organisation's kind
     */
    public function setOwn(Tenant $tenant, EmploymentFramework $framework): void
    {
        $today = TenantTime::today($tenant);

        $platform = $this->platformSet($this->rules->employment($tenant, $today), $today)->describe(self::CODE);
        if ($platform !== null && $platform['enforcement'] === StatutoryParameter::LOCKED) {
            throw ValidationException::withMessages(['employment_framework' => __('validation.employment_framework_locked')]);
        }

        DB::transaction(function () use ($tenant, $framework, $today): void {
            $this->lock($tenant);

            $open = $this->ownInForce($tenant, $today);
            $newest = $open->first();
            $before = $newest !== null ? json_decode((string) $newest->value, true) : null;

            if ($open->count() === 1 && $before === $framework->value) {
                return;
            }

            $encoded = json_encode($framework->value);
            $now = now();

            if ($newest !== null && $open->count() === 1 && $newest->effective_from === $today->toDateString()) {
                DB::table('statutory_parameters')
                    ->where('tenant_id', $tenant->id)
                    ->where('id', $newest->id)
                    ->update(['value' => $encoded, 'confirmed' => true, 'updated_at' => $now]);
            } else {
                $this->end($tenant, $open, $today);

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
    public function resetOwn(Tenant $tenant): void
    {
        $today = TenantTime::today($tenant);

        DB::transaction(function () use ($tenant, $today): void {
            $this->lock($tenant);

            $open = $this->ownInForce($tenant, $today);
            $newest = $open->first();

            if ($newest === null) {
                return;
            }

            $this->end($tenant, $open, $today);

            AuditLog::record('statutory_parameter.reset', $tenant, ['code' => self::CODE, 'from' => json_decode((string) $newest->value, true)]);
        });

        $this->rules->forget();
    }

    /** The framework governing one person, or null when they are marked outside it or none is on file. */
    public function forEmployee(Employee $employee, CarbonInterface $on): ?EmploymentFramework
    {
        $tenantId = $employee->getAttribute('tenant_id');
        if (! is_int($tenantId)) {
            return null;
        }

        // A model loaded with a few columns has not said whether the person is
        // outside the framework, and an unread column must not mean "covered".
        if (array_key_exists('outside_employment_framework', $employee->getAttributes())) {
            $outside = (bool) $employee->outside_employment_framework;
        } else {
            $stored = DB::table('employees')
                ->where('tenant_id', $tenantId)
                ->where('id', $employee->id)
                ->value('outside_employment_framework');

            if ($stored === null) {
                return null;
            }
            $outside = (bool) $stored;
        }

        if ($outside) {
            return null;
        }

        $tenant = app(CurrentTenant::class)->tenantFor($tenantId);

        return $tenant === null ? null : $this->rules->framework($tenant, $on);
    }

    /** The platform's own rules for the kind and sector this set was resolved for. */
    private function platformSet(StatutoryRuleSet $set, CarbonInterface $on): StatutoryRuleSet
    {
        return $this->rules->platform($on, $set->organizationType, $set->sector, StatutoryParameter::DOMAIN_EMPLOYMENT);
    }

    /** Serialises writers for one organisation; Tenant is global, so this needs no scope bypass. */
    private function lock(Tenant $tenant): void
    {
        Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();
    }

    /**
     * Every own row of the code in force on the date, newest first. There is
     * one unless a duplicate got in; the writers close or delete all of them.
     *
     * @return Collection<int, stdClass>
     */
    private function ownInForce(Tenant $tenant, CarbonInterface $on): Collection
    {
        $date = $on->toDateString();

        return DB::table('statutory_parameters')
            ->where('tenant_id', $tenant->id)
            ->where('code', self::CODE)
            ->where('domain', StatutoryParameter::DOMAIN_EMPLOYMENT)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get(['id', 'value', 'effective_from'])
            ->map(function (stdClass $row): stdClass {
                $row->effective_from = substr((string) $row->effective_from, 0, 10);

                return $row;
            });
    }

    /**
     * Ends rows so none is in force from the date: a row that started today is
     * deleted, any earlier one is closed the day before.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    private function end(Tenant $tenant, Collection $rows, CarbonInterface $today): void
    {
        foreach ($rows as $row) {
            $query = DB::table('statutory_parameters')->where('tenant_id', $tenant->id)->where('id', $row->id);

            if ($row->effective_from === $today->toDateString()) {
                $query->delete();
            } else {
                $query->update(['effective_to' => $today->subDay()->toDateString(), 'updated_at' => now()]);
            }
        }
    }
}
