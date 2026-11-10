<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\AuditLog;
use App\Models\PensionRule;
use App\Models\TaxBracket;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * An organisation's own tax ladder or pension rule, saved before 2026-11-09
 * when both became the law's alone (decision D6). One is still honoured for
 * its dates, flagged as a blocker on the readiness checklist
 * (`statutory_law_followed`), and ended here, never deleted: closing it keeps
 * every period it was applied to reproducible.
 */
final class OwnStatutoryRates
{
    /**
     * Narrows a query on `tax_brackets` or `pension_rules` to the
     * organisation's own rows in force on the day. The caller states how the
     * query is scoped: the readiness score runs for a tenant that may not be
     * the one resolved, and passes an unscoped builder with that tenant's id.
     *
     * @template TModel of TaxBracket|PensionRule
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function inForce(Builder $query, int $tenantId, ?CarbonInterface $on = null): Builder
    {
        $day = ($on ?? Carbon::today())->toDateString();

        return $query->where('tenant_id', $tenantId)
            ->whereDate('effective_from', '<=', $day)
            ->where(fn (Builder $open) => $open->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day));
    }

    /** Whether the resolved organisation applies a ladder or rule of its own today. */
    public function anyInForce(Tenant $tenant): bool
    {
        return self::inForce(TaxBracket::query(), $tenant->id)->exists()
            || self::inForce(PensionRule::query(), $tenant->id)->exists();
    }

    /**
     * Ends every ladder band and pension rule of the organisation's own that
     * is in force or still to come: each closes yesterday, so the law applies
     * from today and earlier periods keep what they were paid under. A row
     * that had not started yet closes before it begins, and is never applied.
     *
     * @return array{tax_brackets: int, pension_rules: int}
     */
    public function end(Tenant $tenant): array
    {
        $yesterday = Carbon::yesterday()->toDateString();
        $open = fn (Builder $q) => $q->where('tenant_id', $tenant->id)
            ->where(fn (Builder $o) => $o->whereNull('effective_to')->orWhereDate('effective_to', '>', $yesterday));

        $ended = [
            'tax_brackets' => $open(TaxBracket::query())->update(['effective_to' => $yesterday]),
            'pension_rules' => $open(PensionRule::query())->update(['effective_to' => $yesterday]),
        ];

        if ($ended['tax_brackets'] + $ended['pension_rules'] > 0) {
            AuditLog::record('statutory_parameter.own_rates_ended', $tenant, [...$ended, 'effective_to' => $yesterday]);
        }

        return $ended;
    }
}
