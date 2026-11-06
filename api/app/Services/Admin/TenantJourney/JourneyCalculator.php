<?php

declare(strict_types=1);

namespace App\Services\Admin\TenantJourney;

use App\Enums\EmployeeStatus;
use App\Enums\Feature;
use App\Enums\UserRole;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantJourneySnapshot;
use App\Services\Features\FeatureGate;
use App\Services\Onboarding\ReadinessScorer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Works out where one organisation is between sign-up and using ETHR, from
 * records that already exist (docs/operations/TENANT-JOURNEY.md). Nothing here
 * writes a record of its own except the snapshot, and no milestone needs a hook
 * anywhere else: their dates are read back out of history.
 *
 * Every query states `tenant_id` and returns a count, a date or a list of ids,
 * never an employee's or a user's own record. The platform sees how an
 * organisation is doing, not who its people are (blueprint P15).
 */
final class JourneyCalculator
{
    /** @var list<string> Ordered: a later stage is further along. */
    public const STAGES = ['registered', 'configured', 'people_added', 'team_activated', 'first_use', 'successfully_using'];

    public const ACTIVITY_DAYS = 14;

    public const STALLED_DAYS = 14;

    public const TRIAL_SOON_DAYS = 7;

    private const SILENT_DEVICE_DAYS = 3;

    private const PENDING_INVITE_DAYS = 7;

    /** @var list<string> Reasons that need action now. */
    public const CRITICAL_REASONS = ['trial_expired_not_converted', 'payroll_run_failed', 'invoice_past_due'];

    public function __construct(
        private readonly ReadinessScorer $readiness,
        private readonly FeatureGate $features,
    ) {}

    /**
     * Computes and stores the organisation's snapshot.
     */
    public function refresh(Tenant $tenant): TenantJourneySnapshot
    {
        $data = $this->compute($tenant);

        return TenantJourneySnapshot::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            $data + ['computed_at' => now()],
        );
    }

    /**
     * Refreshes the organisations whose snapshot is missing or older than
     * `$maxAgeMinutes`, oldest first, at most `$limit` of them. One that fails
     * is logged and skipped so a single bad record cannot stop the rest.
     *
     * @return int How many were refreshed.
     */
    public function refreshStale(int $limit = 200, int $maxAgeMinutes = 50): int
    {
        $tenants = Tenant::withoutGlobalScopes()
            ->leftJoin('tenant_journey_snapshots as s', 's.tenant_id', '=', 'tenants.id')
            ->where(fn ($q) => $q->whereNull('s.computed_at')->orWhere('s.computed_at', '<', now()->subMinutes($maxAgeMinutes)))
            ->orderBy('s.computed_at')
            ->select('tenants.*')
            ->limit($limit)
            ->get();

        $done = 0;
        foreach ($tenants as $tenant) {
            try {
                $this->refresh($tenant);
                $done++;
            } catch (\Throwable $e) {
                Log::warning('Tenant journey refresh failed', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
            }
        }

        return $done;
    }

    /**
     * @return array<string, mixed> The snapshot's columns, without `computed_at`.
     */
    public function compute(Tenant $tenant): array
    {
        $id = $tenant->id;
        $now = now();

        // The plan check inside the readiness score reads the subscription
        // through the tenant relation, which fails closed with no tenant
        // resolved. Set it by hand, and forget every decision a previous
        // organisation in the same process left behind.
        $this->features->flush();
        $tenant->setRelation('subscription', Subscription::latestForTenant($id));
        $payrollEnabled = $this->features->allows($tenant, Feature::Payroll);
        $readiness = $this->readiness->score($id);
        $this->features->flush();

        $founderId = $tenant->owner_user_id ?? $this->firstAdminId($id);
        $team = $this->team($id, $founderId);

        $employees = DB::table('employees')->where('tenant_id', $id)->whereNull('deleted_at')
            ->whereIn('status', array_map(static fn (EmployeeStatus $s): string => $s->value, EmployeeStatus::current()));
        $employeeCount = (clone $employees)->count();

        $activated = (clone $team)->where(fn (Builder $q) => $q->whereNotNull('activated_at')->orWhereNotNull('last_login_at'));
        $activatedCount = (clone $activated)->count();
        $invitedPending = (clone $team)->where('status', 'invited')
            ->where('invited_at', '<', $now->copy()->subDays(self::PENDING_INVITE_DAYS))->count();

        $teamIds = (clone $team)->pluck('id');
        $logins = DB::table('login_histories')->where('tenant_id', $id)->where('status', 'success');
        $firstTeamLogin = $teamIds->isEmpty() ? null : (clone $logins)->whereIn('user_id', $teamIds)->min('created_at');
        $lastLogin = (clone $logins)->max('created_at');
        $loggedIn30 = (clone $logins)->where('created_at', '>=', $now->copy()->subDays(30))->distinct()->count('user_id');

        // A punch is a check-in that was not a merged duplicate and did not come
        // from a bulk import of history: an import says the data arrived, not
        // that anyone is using the product.
        $punches = DB::table('attendance_records')->where('tenant_id', $id)
            ->whereNotNull('check_in')->where('status', '!=', 'voided')->where('source', '!=', 'csv');
        $leave = DB::table('leave_requests')->where('tenant_id', $id)->whereNull('deleted_at');

        $activitySince = $now->copy()->subDays(self::ACTIVITY_DAYS);
        $punches14 = (clone $punches)->where('created_at', '>=', $activitySince)->count();
        $leave14 = (clone $leave)->where('created_at', '>=', $activitySince)->count();
        $punches30 = (clone $punches)->where('created_at', '>=', $now->copy()->subDays(30))->count();
        $leave30 = (clone $leave)->where('created_at', '>=', $now->copy()->subDays(30))->count();
        $sources = (clone $punches)->where('created_at', '>=', $now->copy()->subDays(30))
            ->selectRaw('source, count(*) as total')->groupBy('source')->pluck('total', 'source')
            ->map(static fn ($n, $source): array => ['source' => (string) $source, 'count' => (int) $n])->values()->all();

        $firstUse = $this->earliest((clone $punches)->min('created_at'), (clone $leave)->min('created_at'));
        $lastUse = $this->latest((clone $punches)->max('created_at'), (clone $leave)->max('created_at'));

        $counted = DB::table('payroll_runs')->where('tenant_id', $id)->whereIn('status', ['completed', 'approved', 'paid']);
        $countedRuns = (clone $counted)->count();
        $firstPayroll = (clone $counted)->min('created_at');
        $payrollFailed = DB::table('audit_log')->where('tenant_id', $id)->where('action', 'payroll.failed')
            ->where('created_at', '>=', $activitySince)
            ->when($firstPayroll !== null, fn (Builder $q) => $q->where('created_at', '>', (clone $counted)->max('created_at')))
            ->exists();

        $devices = DB::table('devices')->where('tenant_id', $id)->whereNull('deleted_at');
        $silentBefore = $now->copy()->subDays(self::SILENT_DEVICE_DAYS);
        $silentDevices = (clone $devices)
            ->where(fn (Builder $q) => $q->where('last_sync_at', '<', $silentBefore)
                ->orWhere(fn (Builder $n) => $n->whereNull('last_sync_at')->where('created_at', '<', $silentBefore)))
            ->count();

        $openSupport = DB::table('support_requests')->where('tenant_id', $id)
            ->whereNotNull('escalated_at')->where('status', '!=', 'resolved')->count();
        $invoices = DB::table('invoices')->where('tenant_id', $id);
        $pastDue = (clone $invoices)->where('status', 'past_due')->count();
        $overdue = (clone $invoices)->where('status', 'overdue')->count();

        $settings = $tenant->getAttribute('settings');
        $configuredAt = is_array($settings) && ! empty($settings['configuration_applied_at'])
            ? (string) $settings['configuration_applied_at'] : null;

        $milestones = array_filter([
            'registered' => $this->iso($tenant->created_at),
            'configured' => $this->iso($this->audit($id, 'onboarding.configuration_applied') ?? $configuredAt),
            'people_added' => $this->iso($employeeCount > 0 ? DB::table('employees')->where('tenant_id', $id)->whereNull('deleted_at')->min('created_at') : null),
            'team_activated' => $this->iso($this->earliest(
                $activatedCount > 0 ? (clone $team)->min('activated_at') : null,
                $firstTeamLogin,
            ) ?? ($activatedCount > 0 ? (clone $team)->min('last_login_at') : null)),
            'first_use' => $this->iso($firstUse),
            'first_payroll' => $this->iso($firstPayroll),
            'went_live' => $this->iso($this->audit($id, 'onboarding.went_live')
                ?? DB::table('onboarding_progress')->where('tenant_id', $id)->value('completed_at')),
            'converted' => $this->iso($this->audit($id, 'billing.plan_changed')),
        ], static fn ($v): bool => $v !== null);

        $holds = [
            'registered' => true,
            'configured' => $configuredAt !== null || isset($milestones['configured']),
            'people_added' => $employeeCount > 0,
            'team_activated' => $activatedCount > 0,
            'first_use' => $firstUse !== null,
        ];
        $holds['successfully_using'] = $holds['configured'] && $holds['people_added'] && $holds['team_activated']
            && $holds['first_use'] && ($punches14 + $leave14) > 0 && (! $payrollEnabled || $countedRuns > 0);

        $stage = 'registered';
        foreach (self::STAGES as $candidate) {
            if ($holds[$candidate]) {
                $stage = $candidate;
            }
        }

        $stageReachedAt = $this->stageReachedAt($stage, $milestones, $payrollEnabled);

        $lifecycle = $this->lifecycle($tenant);
        $trialDaysLeft = $lifecycle === 'trial' && $tenant->trial_ends_at !== null
            ? max(0, (int) ceil($now->diffInDays($tenant->trial_ends_at, false))) : null;

        $reasons = $this->reasons([
            'lifecycle' => $lifecycle,
            'stage' => $stage,
            'stage_reached_at' => $stageReachedAt ?? $this->iso($tenant->created_at),
            'trial_days_left' => $trialDaysLeft,
            'payroll_failed' => $payrollFailed,
            'past_due' => $pastDue,
            'overdue' => $overdue,
            'last_login' => $lastLogin,
            'registered_at' => $tenant->created_at,
            'uses' => $holds['first_use'],
            'recent_use' => ($punches14 + $leave14) > 0,
            'invited_pending' => $invitedPending,
            'silent_devices' => $silentDevices,
            'open_support' => $openSupport,
        ], $now);

        $state = $this->state($lifecycle, $reasons);

        $lastActivity = $this->latest($lastUse, $lastLogin);

        return [
            'stage' => $stage,
            'stage_reached_at' => $stageReachedAt,
            'lifecycle' => $lifecycle,
            'health_state' => $state,
            'reasons' => $reasons,
            'milestones' => $milestones,
            'metrics' => [
                'employees' => $employeeCount,
                'team_users' => (clone $team)->count(),
                'activated_users' => $activatedCount,
                'invited_pending' => $invitedPending,
                'users_logged_in_30d' => $loggedIn30,
                'punches_14d' => $punches14,
                'punches_30d' => $punches30,
                'punch_sources_30d' => $sources,
                'leave_requests_14d' => $leave14,
                'leave_requests_30d' => $leave30,
                'payroll_enabled' => $payrollEnabled,
                'payroll_runs' => $countedRuns,
                'devices' => (clone $devices)->count(),
                'devices_silent' => $silentDevices,
                'open_support_requests' => $openSupport,
                'invoices_overdue' => $overdue,
                'invoices_past_due' => $pastDue,
                'trial_days_left' => $trialDaysLeft,
                'last_login_at' => $this->iso($lastLogin),
            ],
            'readiness_score' => $readiness['overall_score'],
            'readiness_level' => $readiness['level'],
            'readiness_gaps' => array_map(static fn (array $g): array => [
                'id' => $g['id'],
                'category' => $g['category'],
                'label' => $g['label'],
            ], $readiness['gaps']),
            'last_activity_at' => $lastActivity === null ? null : Carbon::parse($lastActivity),
        ];
    }

    /**
     * Everyone in the organisation except its founder and platform staff.
     */
    private function team(int $tenantId, ?int $founderId): Builder
    {
        return DB::table('users')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->where('role', '!=', UserRole::SUPER_ADMIN->value)
            ->when($founderId !== null, fn (Builder $q) => $q->where('id', '!=', $founderId));
    }

    private function firstAdminId(int $tenantId): ?int
    {
        $id = DB::table('users')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->where('role', UserRole::TENANT_ADMIN->value)->min('id');

        return $id === null ? null : (int) $id;
    }

    private function audit(int $tenantId, string $action): mixed
    {
        return DB::table('audit_log')->where('tenant_id', $tenantId)->where('action', $action)->min('created_at');
    }

    private function lifecycle(Tenant $tenant): string
    {
        if ($tenant->deleted_at !== null) {
            return 'deleted';
        }

        if ($tenant->isTrialExpired()) {
            return 'trial_expired';
        }

        return $tenant->status->value;
    }

    /**
     * @param  array<string, mixed>  $f
     * @return list<string>
     */
    private function reasons(array $f, Carbon $now): array
    {
        if (! in_array($f['lifecycle'], ['trial', 'active', 'trial_expired'], true)) {
            return ['not_operating'];
        }

        $reasons = [];

        if ($f['lifecycle'] === 'trial_expired') {
            $reasons[] = 'trial_expired_not_converted';
        }
        if ($f['payroll_failed']) {
            $reasons[] = 'payroll_run_failed';
        }
        if ($f['past_due'] > 0) {
            $reasons[] = 'invoice_past_due';
        }
        if ($f['overdue'] > 0) {
            $reasons[] = 'invoice_overdue';
        }

        $age = $f['registered_at'] instanceof Carbon ? $f['registered_at'] : Carbon::parse($f['registered_at']);
        if ($age->lt($now->copy()->subDays(self::ACTIVITY_DAYS))
            && ($f['last_login'] === null || Carbon::parse($f['last_login'])->lt($now->copy()->subDays(self::ACTIVITY_DAYS)))) {
            $reasons[] = 'no_login_14d';
        }
        if ($f['uses'] && ! $f['recent_use']) {
            $reasons[] = 'no_activity_14d_after_first_use';
        }
        if ($f['lifecycle'] === 'trial' && $f['trial_days_left'] !== null && $f['trial_days_left'] <= self::TRIAL_SOON_DAYS
            && array_search($f['stage'], self::STAGES, true) < array_search('first_use', self::STAGES, true)) {
            $reasons[] = 'trial_ends_soon_not_ready';
        }
        if ($f['invited_pending'] > 0 && $f['stage'] !== 'successfully_using') {
            $reasons[] = 'invitations_unactivated';
        }
        if ($f['silent_devices'] > 0) {
            $reasons[] = 'devices_silent';
        }
        if ($f['stage'] !== 'successfully_using'
            && Carbon::parse($f['stage_reached_at'])->lt($now->copy()->subDays(self::STALLED_DAYS))) {
            $reasons[] = 'stalled_at_stage';
        }
        if ($f['open_support'] > 0) {
            $reasons[] = 'open_support_request';
        }

        return $reasons;
    }

    /**
     * @param  list<string>  $reasons
     */
    private function state(string $lifecycle, array $reasons): string
    {
        if ($reasons === ['not_operating']) {
            return 'unknown';
        }
        if (array_intersect($reasons, self::CRITICAL_REASONS) !== []) {
            return 'critical';
        }

        return $reasons === [] ? 'ok' : 'warning';
    }

    /**
     * When the organisation reached `$stage`: the date its last prerequisite
     * was met, since a stage is held only when every condition is.
     *
     * @param  array<string, string>  $milestones
     */
    private function stageReachedAt(string $stage, array $milestones, bool $payrollEnabled): ?Carbon
    {
        $keys = match ($stage) {
            'registered' => ['registered'],
            'configured' => ['configured'],
            'people_added' => ['people_added'],
            'team_activated' => ['team_activated'],
            'first_use' => ['first_use'],
            default => array_merge(
                ['configured', 'people_added', 'team_activated', 'first_use'],
                $payrollEnabled ? ['first_payroll'] : [],
            ),
        };

        $dates = array_filter(array_map(static fn (string $k): ?string => $milestones[$k] ?? null, $keys));

        if ($dates === []) {
            return null;
        }

        return Carbon::parse(max($dates));
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toIso8601String();
    }

    private function earliest(mixed $a, mixed $b): mixed
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return Carbon::parse($a)->lte(Carbon::parse($b)) ? $a : $b;
    }

    private function latest(mixed $a, mixed $b): mixed
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return Carbon::parse($a)->gte(Carbon::parse($b)) ? $a : $b;
    }
}
