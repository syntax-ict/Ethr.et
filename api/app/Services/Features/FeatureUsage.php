<?php

declare(strict_types=1);

namespace App\Services\Features;

use App\Enums\Feature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How many organisations used a feature lately, for the console's Disable
 * confirmation (docs/FEATURE-CONTROL-CENTER-DESIGN.md §7): the admin should
 * know whom a switch reaches before throwing it.
 *
 * A feature is counted only where a table records its use with a time. Where
 * none does (reading reports, the audit log, the public pages), the answer is
 * null, and the console says it is not measured rather than showing a zero
 * that looks like nobody.
 *
 * Across every organisation by intent: the console runs on the platform host
 * with no tenant. The queries use the query builder, which carries no tenant
 * scope, and count `tenant_id` itself; no row leaves this class.
 */
final class FeatureUsage
{
    public const DAYS = 30;

    /** @var array<string, array{0: string, 1: string}> feature => [table, time column] */
    private const SIGNALS = [
        'attendance' => ['attendance_records', 'created_at'],
        'leave' => ['leave_requests', 'created_at'],
        'employee_management' => ['employees', 'updated_at'],
        'payroll' => ['payroll_runs', 'created_at'],
        'custom_reports' => ['saved_reports', 'updated_at'],
        'notifications' => ['notifications', 'created_at'],
        'api_access' => ['api_keys', 'last_used_at'],
        'webhooks' => ['webhooks', 'last_triggered_at'],
        'webhooks_outbound' => ['webhooks', 'last_triggered_at'],
        'web_push' => ['push_subscriptions', 'last_used_at'],
    ];

    /** Organisations that used the feature in the last {@see DAYS} days, or null when nothing records its use. */
    public function organisationsUsing(Feature $feature): ?int
    {
        $signal = self::SIGNALS[$feature->value] ?? null;
        if ($signal === null) {
            return null;
        }

        [$table, $column] = $signal;

        return DB::table($table)
            ->whereNotNull('tenant_id')
            ->where($column, '>=', Carbon::now()->subDays(self::DAYS))
            ->distinct()
            ->count('tenant_id');
    }
}
