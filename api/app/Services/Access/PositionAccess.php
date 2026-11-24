<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\Tenancy\OrganisationOwnership;
use App\Support\BackgroundWork;
use App\Support\RoleAssignment;
use Illuminate\Database\Eloquent\Builder;

/**
 * An account's access following its person's job title.
 *
 * One rule governs everything here: a job-title change does automatically only
 * what the person making it could have done by hand on the Users page
 * (RoleAssignment::mayChange). Anything else is left as it was and shows as
 * waiting. Nothing is stored for "waiting": an account that follows its title
 * and whose role differs from what the title gives is waiting, which is what
 * heldQuery() asks.
 *
 * The owner, an account with a custom role, a platform account and an account
 * with no employee are never moved by a title.
 */
final class PositionAccess
{
    public const APPLIED = 'applied';

    public const UNCHANGED = 'unchanged';

    public const HELD = 'held';

    /**
     * The roles a job title may give. Organisation-admin access is only ever
     * given by hand.
     *
     * @var list<string>
     */
    public const MAPPABLE = ['supervisor', 'dept_admin', 'hr_admin', 'finance_admin'];

    /** The role this account's job title gives, or null when it does not follow one. */
    public function titleRole(User $user): ?UserRole
    {
        if (! $user->role_follows_position || $user->employee_id === null
            || $user->custom_role_id !== null || $user->isSuperAdmin()) {
            return null;
        }

        if (OrganisationOwnership::owns($user, app(CurrentTenant::class)->get())) {
            return null;
        }

        $user->loadMissing('employee.position');

        $mapped = $user->employee?->position?->access_role;

        return $mapped !== null && in_array($mapped, self::MAPPABLE, true)
            ? UserRole::from($mapped)
            : UserRole::EMPLOYEE;
    }

    /**
     * Bring one account's role to its job title's, if $actor may give it.
     *
     * @return self::APPLIED|self::UNCHANGED|self::HELD
     */
    public function sync(User $user, ?User $actor): string
    {
        $to = $this->titleRole($user);

        if ($to === null || $user->role === $to) {
            return self::UNCHANGED;
        }

        if ($actor === null || ! RoleAssignment::mayChange($actor, $user, $to)) {
            return self::HELD;
        }

        $before = $user->role;
        $user->update(['role' => $to]);

        AuditLog::record('user.role_from_title', $user, [
            'before' => $before->value,
            'after' => $to->value,
            'position' => $user->employee?->position?->public_id,
            'by' => $actor->id,
        ]);

        return self::APPLIED;
    }

    /**
     * The login of an employee whose job title just changed. The signed-in
     * person is the actor; a queue or console run has none, so it holds.
     */
    public function syncEmployee(Employee $employee, ?User $actor): void
    {
        $user = User::query()->where('employee_id', $employee->id)->first();

        if ($user !== null) {
            $this->sync($user, $actor);
        }
    }

    /**
     * The signed-in person, or nobody. Inside a queue drain or the scheduler
     * the signed-in person is whoever happened to load a page that ran the
     * work after the response, and the job may be another organisation's, so
     * there is no actor (App\Support\BackgroundWork, as AuditLog does).
     */
    public static function currentActor(): ?User
    {
        if (BackgroundWork::active()) {
            return null;
        }

        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * Every following account whose employee holds this job title.
     *
     * @return array{applied: int, held: int}
     */
    public function syncPosition(Position $position, ?User $actor): array
    {
        $result = ['applied' => 0, 'held' => 0];

        User::query()
            ->where('role_follows_position', true)
            ->whereHas('employee', fn (Builder $q) => $q->where('position_id', $position->id))
            ->chunkById(200, function ($users) use ($actor, &$result): void {
                foreach ($users as $user) {
                    $outcome = $this->sync($user, $actor);

                    if ($outcome === self::APPLIED) {
                        $result['applied']++;
                    } elseif ($outcome === self::HELD) {
                        $result['held']++;
                    }
                }
            });

        return $result;
    }

    /**
     * Accounts that follow their job title and whose role differs from what it
     * gives. Written with scoped relations only, so the tenant predicate is the
     * model's own.
     *
     * @return Builder<User>
     */
    public function heldQuery(): Builder
    {
        $ownerId = app(CurrentTenant::class)->get()?->owner_user_id;

        return User::query()
            ->where('role_follows_position', true)
            ->whereNotNull('employee_id')
            ->whereNull('custom_role_id')
            ->where('role', '!=', UserRole::SUPER_ADMIN->value)
            ->when($ownerId !== null, fn (Builder $q) => $q->where('id', '!=', $ownerId))
            ->where(function (Builder $q): void {
                $q->where(function (Builder $none): void {
                    $none->where('role', '!=', UserRole::EMPLOYEE->value)
                        ->where(function (Builder $unmapped): void {
                            $unmapped->whereDoesntHave('employee.position')
                                ->orWhereHas('employee.position', fn (Builder $p) => $p->whereNull('access_role'));
                        });
                });

                foreach (self::MAPPABLE as $role) {
                    $q->orWhere(function (Builder $mapped) use ($role): void {
                        $mapped->where('role', '!=', $role)
                            ->whereHas('employee.position', fn (Builder $p) => $p->where('access_role', $role));
                    });
                }
            });
    }
}
