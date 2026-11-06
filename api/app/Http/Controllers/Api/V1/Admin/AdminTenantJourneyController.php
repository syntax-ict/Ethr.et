<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantJourneySnapshot;
use App\Services\Admin\TenantJourney\JourneyCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The console's view of how far each organisation has come between sign-up
 * and using ETHR (docs/operations/TENANT-JOURNEY.md). Read only: counts, dates
 * and reason codes, never a person's own record.
 */
class AdminTenantJourneyController extends Controller
{
    /** A snapshot older than this is recomputed when one organisation is opened. */
    private const FRESH_MINUTES = 60;

    /**
     * The funnel: how many organisations sit at each stage, in each health
     * state and in each lifecycle state. Deleted organisations are left out.
     *
     * @response array{data: array{total: int, not_computed: int, computed_at: string|null, stages: list<array{stage: string, count: int}>, health: array{ok: int, warning: int, critical: int, unknown: int}, lifecycle: array{trial: int, trial_expired: int, active: int, suspended: int, cancelled: int}}}
     */
    public function summary(): JsonResponse
    {
        Gate::authorize('admin.manage');

        $live = Tenant::withoutGlobalScopes()->whereNull('deleted_at');
        $total = (clone $live)->count();

        $snapshots = TenantJourneySnapshot::query()->whereIn('tenant_id', (clone $live)->select('id'));

        $stages = (clone $snapshots)->selectRaw('stage, count(*) as total')->groupBy('stage')->pluck('total', 'stage');
        $health = (clone $snapshots)->selectRaw('health_state, count(*) as total')->groupBy('health_state')->pluck('total', 'health_state');
        $lifecycle = (clone $snapshots)->selectRaw('lifecycle, count(*) as total')->groupBy('lifecycle')->pluck('total', 'lifecycle');
        $computed = (int) (clone $snapshots)->count();
        $newest = (clone $snapshots)->max('computed_at');

        return response()->json([
            'data' => [
                'total' => $total,
                'not_computed' => max(0, $total - $computed),
                'computed_at' => $newest === null ? null : Carbon::parse($newest)->toIso8601String(),
                'stages' => array_map(
                    static fn (string $stage): array => ['stage' => $stage, 'count' => (int) ($stages[$stage] ?? 0)],
                    JourneyCalculator::STAGES,
                ),
                'health' => [
                    'ok' => (int) ($health['ok'] ?? 0),
                    'warning' => (int) ($health['warning'] ?? 0),
                    'critical' => (int) ($health['critical'] ?? 0),
                    'unknown' => (int) ($health['unknown'] ?? 0) + max(0, $total - $computed),
                ],
                'lifecycle' => [
                    'trial' => (int) ($lifecycle['trial'] ?? 0),
                    'trial_expired' => (int) ($lifecycle['trial_expired'] ?? 0),
                    'active' => (int) ($lifecycle['active'] ?? 0),
                    'suspended' => (int) ($lifecycle['suspended'] ?? 0),
                    'cancelled' => (int) ($lifecycle['cancelled'] ?? 0),
                ],
            ],
        ]);
    }

    /**
     * One organisation's journey: stage, milestones, metrics, readiness gaps
     * and the lifecycle events behind them. A snapshot older than an hour, or
     * none yet, is recomputed first so the page never shows a stale picture.
     *
     * @response array{data: array{stage: string, stages: list<string>, stage_reached_at: string|null, lifecycle: string, health_state: string, reasons: list<string>, milestones: array<string, string>, metrics: array{employees: int, team_users: int, activated_users: int, invited_pending: int, users_logged_in_30d: int, punches_14d: int, punches_30d: int, punch_sources_30d: list<array{source: string, count: int}>, leave_requests_14d: int, leave_requests_30d: int, payroll_enabled: bool, payroll_runs: int, devices: int, devices_silent: int, open_support_requests: int, invoices_overdue: int, invoices_past_due: int, trial_days_left: int|null, last_login_at: string|null}, readiness: array{score: int, level: string, gaps: list<array{id: string, category: string, label: string}>}, last_activity_at: string|null, computed_at: string, events: list<array{action: string, created_at: string, by_platform: bool}>}}
     */
    public function show(string $publicId, JourneyCalculator $calculator): JsonResponse
    {
        Gate::authorize('admin.manage');

        $tenant = Tenant::withoutGlobalScopes()->where('public_id', $publicId)->firstOrFail();

        $snapshot = TenantJourneySnapshot::query()->where('tenant_id', $tenant->id)->first();
        if (! $snapshot instanceof TenantJourneySnapshot || $snapshot->computed_at->lt(now()->subMinutes(self::FRESH_MINUTES))) {
            $snapshot = $calculator->refresh($tenant);
        }

        // Only the organisation's lifecycle events, and whether the platform
        // made the change: who in the organisation did it stays out of the
        // console.
        $events = DB::table('audit_log')
            ->leftJoin('users', 'users.id', '=', 'audit_log.user_id')
            ->where('audit_log.tenant_id', $tenant->id)
            ->whereIn('audit_log.action', self::LIFECYCLE_ACTIONS)
            ->orderByDesc('audit_log.created_at')
            ->limit(20)
            ->get(['audit_log.action', 'audit_log.created_at', 'users.role'])
            ->map(static fn ($row): array => [
                'action' => $row->action,
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
                'by_platform' => $row->role === 'super_admin',
            ])
            ->all();

        return response()->json([
            'data' => [
                'stage' => $snapshot->stage,
                'stages' => JourneyCalculator::STAGES,
                'stage_reached_at' => $snapshot->stage_reached_at?->toIso8601String(),
                'lifecycle' => $snapshot->lifecycle,
                'health_state' => $snapshot->health_state,
                'reasons' => $snapshot->reasons,
                'milestones' => $snapshot->milestones,
                'metrics' => $snapshot->metrics,
                'readiness' => [
                    'score' => $snapshot->readiness_score,
                    'level' => $snapshot->readiness_level,
                    'gaps' => $snapshot->readiness_gaps,
                ],
                'last_activity_at' => $snapshot->last_activity_at?->toIso8601String(),
                'computed_at' => $snapshot->computed_at->toIso8601String(),
                'events' => $events,
            ],
        ]);
    }

    /** @var list<string> */
    private const LIFECYCLE_ACTIONS = [
        'tenant.registered',
        'onboarding.configuration_applied',
        'onboarding.access_updated',
        'onboarding.went_live',
        'billing.plan_changed',
        'billing.invoice_paid',
        'admin.tenant.status_changed',
        'admin.tenant.trial_extended',
        'admin.tenant.deleted',
        'admin.tenant.restored',
        'admin.tenant.impersonated',
        'admin.tenant.feature_override_set',
        'admin.tenant.feature_override_removed',
        'payroll.processed',
        'payroll.failed',
        'payroll.approved',
        'payroll.paid',
    ];
}
