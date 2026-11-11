<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\PasswordPolicy;
use App\Services\CurrentTenant;
use App\Services\UserProvisioningService;
use App\Support\LeaverAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One starting password for a batch of employees who have no email to be
 * invited by (owner decision 2026-11-10,
 * docs/decisions/OWNER-DECISION-MASS-ACTIVATION.md).
 *
 * ETHR makes the password, HR never types it, and it is returned once: only
 * its hash is kept, and it is hashed once for the whole batch, because 200
 * bcrypts at cost 12 do not fit in the host's 30 seconds. Each person signs in
 * with their employee number and is held at the change-password screen
 * (RequirePasswordChange) until they choose their own. Unused after 72 hours,
 * the account goes back to `invited`.
 *
 * The weakness the owner accepted: until a person first signs in, a colleague
 * who knows the batch password and their employee number can claim the
 * account. So it is never given to an account that is already active, to a
 * login above the employee role, or to someone an invitation can reach.
 */
final class StartingPasswords
{
    public const TTL_HOURS = 72;

    public const MAX_BATCH = 200;

    public const MIN_LENGTH = 12;

    /** What every password writer sets once the person has a password of their own. */
    public const CLEARED = [
        'password_change_required_at' => null,
        'starting_password_expires_at' => null,
    ];

    /** No I, L, O, 0 or 1: the password is read off a printed sheet and typed on a phone. */
    private const UPPER = 'ABCDEFGHJKMNPQRSTUVWXYZ';

    private const LOWER = 'abcdefghjkmnpqrstuvwxyz';

    private const DIGITS = '23456789';

    private const SYMBOLS = '@#%+=?';

    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly UserProvisioningService $provisioning,
        private readonly ActivationCodeService $activationCodes,
    ) {}

    /**
     * Give these employees one starting password. Someone who cannot be given
     * one is left exactly as they were and counted under the reason.
     *
     * @param  iterable<Employee>  $employees
     * @return array{password: string, expires_at: Carbon, people: list<array{employee_public_id: string, name: string, employee_code: string}>, skipped: array{active: int, role: int, closed: int, reachable: int}}
     *
     * @throws StartingPasswordRefused
     */
    public function issue(iterable $employees, User $actor): array
    {
        $tenant = $this->currentTenant->get();
        if ($tenant === null) {
            throw new StartingPasswordRefused('tenant');
        }

        // The employee number is the only identifier these people have.
        if (! in_array(AuthIdentifierResolver::EMPLOYEE_CODE, AuthIdentifierResolver::enabledFor($tenant), true)) {
            throw new StartingPasswordRefused('identifier');
        }

        $skipped = ['active' => 0, 'role' => 0, 'closed' => 0, 'reachable' => 0];
        $eligible = [];

        foreach ($employees as $employee) {
            if ($employee->tenant_id !== $tenant->id) {
                throw new StartingPasswordRefused('tenant');
            }

            $user = $this->loginOf($employee, $tenant);
            $reason = $this->whyNot($employee, $user);

            if ($reason !== null) {
                $skipped[$reason]++;

                continue;
            }

            $eligible[] = [$employee, $user];
        }

        if ($eligible === []) {
            throw new StartingPasswordRefused('nobody', $skipped);
        }

        $password = $this->generate($tenant);
        $hash = Hash::make($password);
        $expiresAt = Carbon::now()->addHours(self::TTL_HOURS);

        $people = DB::transaction(function () use ($eligible, $hash, $expiresAt, $actor, $skipped): array {
            $people = [];

            foreach ($eligible as [$employee, $user]) {
                if ($user === null) {
                    $this->provisioning->provisionWithStartingPassword($employee, $hash, $expiresAt, $actor->id);
                } else {
                    $user->update([
                        'password' => $hash,
                        'status' => 'active',
                        'password_change_required_at' => Carbon::now(),
                        'starting_password_expires_at' => $expiresAt,
                    ]);
                    $user->tokens()->delete();
                }

                $people[] = [
                    'employee_public_id' => (string) $employee->public_id,
                    'name' => (string) $employee->name,
                    'employee_code' => (string) $employee->employee_code,
                ];
            }

            // One record for the batch. It names who, how many and until when,
            // and never the password.
            AuditLog::record('user.starting_password_issued', null, [
                'issued' => count($people),
                'skipped' => $skipped,
                'expires_at' => $expiresAt->toIso8601String(),
                'employee_ids' => array_column($people, 'employee_public_id'),
            ]);

            return $people;
        });

        return [
            'password' => $password,
            'expires_at' => $expiresAt,
            'people' => $people,
            'skipped' => $skipped,
        ];
    }

    /**
     * The starting password has run out: nothing signs in with it any more,
     * and the account can be invited or given an in-person code again.
     */
    public function expire(User $user): void
    {
        $user->forceFill([
            'status' => $user->status === 'active' ? 'invited' : $user->status,
            'password' => Hash::make(Str::random(40)),
            ...self::CLEARED,
        ])->save();

        $user->tokens()->delete();

        AuditLog::record('user.starting_password_expired', $user);
    }

    /**
     * Every account in every organisation whose starting password ran out
     * unused. Run by CleanupExpiredDataJob once a day; a sign-in and
     * RequirePasswordChange do the same for one account the moment it is used.
     *
     * No tenant is stated on purpose: this is the platform's own sweep, and
     * each row is picked by its own expiry.
     */
    public function expireOverdue(): int
    {
        $now = Carbon::now();

        $overdue = DB::table('users')
            ->whereNotNull('password_change_required_at')
            ->whereNotNull('starting_password_expires_at')
            ->where('starting_password_expires_at', '<', $now)
            ->get(['id', 'tenant_id']);

        if ($overdue->isEmpty()) {
            return 0;
        }

        $placeholder = Hash::make(Str::random(40));
        $expired = 0;

        foreach ($overdue->groupBy('tenant_id') as $tenantId => $rows) {
            $count = 0;

            foreach ($rows->pluck('id')->chunk(500) as $ids) {
                // The conditions are repeated so a person who chose their own
                // password since the read above keeps it.
                $count += DB::table('users')
                    ->whereIn('id', $ids->all())
                    ->whereNotNull('password_change_required_at')
                    ->where('starting_password_expires_at', '<', $now)
                    ->update([
                        'status' => DB::raw("CASE WHEN status = 'active' THEN 'invited' ELSE status END"),
                        'password' => $placeholder,
                        'updated_at' => $now,
                        ...self::CLEARED,
                    ]);

                DB::table('personal_access_tokens')
                    ->where('tokenable_type', (new User)->getMorphClass())
                    ->whereIn('tokenable_id', DB::table('users')
                        ->whereIn('id', $ids->all())
                        ->where('status', '!=', 'active')
                        ->select('id'))
                    ->delete();
            }

            $tenant = $count > 0 ? Tenant::query()->find((int) $tenantId) : null;
            if ($tenant !== null) {
                AuditLog::record('user.starting_password_expired', $tenant, ['accounts' => $count, 'via' => 'cleanup']);
            }

            $expired += $count;
        }

        return $expired;
    }

    /**
     * A password that satisfies the organisation's policy: at least 12
     * characters, with a capital, a small letter and a digit always, and a
     * symbol when the policy asks for one.
     */
    public function generate(?Tenant $tenant): string
    {
        $policy = PasswordPolicy::forTenant($tenant);
        $length = max(self::MIN_LENGTH, (int) $policy['min_length']);

        $sets = [self::UPPER, self::LOWER, self::DIGITS];
        if ($policy['require_symbol']) {
            $sets[] = self::SYMBOLS;
        }

        $characters = array_map(fn (string $set): string => $set[random_int(0, strlen($set) - 1)], $sets);

        $all = implode('', $sets);
        while (count($characters) < $length) {
            $characters[] = $all[random_int(0, strlen($all) - 1)];
        }

        // Fisher-Yates, so the guaranteed characters are not always in front.
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }

    /** The employee's login, linked from either side, or null when they have none. */
    private function loginOf(Employee $employee, Tenant $tenant): ?User
    {
        return User::query()
            ->where('tenant_id', $tenant->id)
            ->where(fn ($q) => $q
                ->where('employee_id', $employee->id)
                ->when($employee->user_id !== null, fn ($q) => $q->orWhere('id', $employee->user_id)))
            ->first();
    }

    /** Why this person gets no starting password, or null when they do. */
    private function whyNot(Employee $employee, ?User $user): ?string
    {
        if (trim((string) $employee->employee_code) === '' || LeaverAccess::hasLeft($employee)) {
            return 'closed';
        }

        if ($user !== null) {
            // A custom role can carry any permission, whatever the base role.
            if ($user->role !== UserRole::EMPLOYEE || $user->custom_role_id !== null) {
                return 'role';
            }

            if ($user->status === 'active') {
                return 'active';
            }

            if ($user->status !== 'invited') {
                return 'closed';
            }
        }

        return $this->activationCodes->reachable($employee, $user) ? 'reachable' : null;
    }
}
