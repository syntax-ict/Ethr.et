<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whether an employee can sign in, once.
 *
 * The employees list's `sign_in` column, the `missing=sign_in` filter and the
 * Next Steps count all read the logins through `logins()`, so the badge on a
 * row, the list it opens and the number on the card cannot disagree.
 *
 * "Whose login is it" follows Employee::hasLogin(): either side of the link
 * (`users.employee_id`, or `employees.user_id`), live rows only. The query is
 * a User query, so it carries the tenant scope; with no tenant resolved it
 * matches nothing, which is why the readiness scorer does not use this.
 */
final class EmployeeSignIn
{
    public const NONE = 'none';

    public const INVITED = 'invited';

    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    public const SUSPENDED = 'suspended';

    /** @return list<string> */
    public static function values(): array
    {
        return [self::NONE, self::INVITED, self::ACTIVE, self::INACTIVE, self::SUSPENDED];
    }

    /**
     * The logins naming the employee of the outer `employees` row from the
     * user's side (`users.employee_id`). One place per side of the link, so the
     * gap and the row state cannot drift. Two simple correlations, not one
     * OR: MariaDB plans the OR as a dependent subquery that reads the tenant's
     * users once per employee.
     *
     * @return Builder<User>
     */
    public static function loginsByEmployeeId(): Builder
    {
        return User::query()->whereColumn('users.employee_id', 'employees.id');
    }

    /**
     * The login the employee row names (`employees.user_id`).
     *
     * @return Builder<User>
     */
    public static function loginsByUserId(): Builder
    {
        return User::query()->whereColumn('users.id', 'employees.user_id');
    }

    /**
     * Employees with no login past `invited` on either side of the link: none
     * at all, or one that was invited and never activated.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public static function cannotSignIn(Builder $query): Builder
    {
        return $query
            ->whereNotExists(self::loginsByEmployeeId()->where('users.status', '!=', self::INVITED)->toBase())
            ->whereNotExists(self::loginsByUserId()->where('users.status', '!=', self::INVITED)->toBase());
    }

    /**
     * Adds the two columns `of()` reads: the status of the login on each side
     * of the link. Correlated subselects, not a query per row.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public static function withState(Builder $query): Builder
    {
        $byEmployee = self::loginsByEmployeeId()
            ->select('users.status')
            ->orderByRaw('users.status = ? asc', [self::INVITED])
            ->limit(1)
            ->toBase();
        $byUser = self::loginsByUserId()->select('users.status')->limit(1)->toBase();

        return $query
            ->addSelect('employees.*')
            ->selectSub($byEmployee, 'sign_in_status')
            ->selectSub($byUser, 'sign_in_status_linked');
    }

    /**
     * The five-value state of a row read with withState(): any status past
     * `invited` wins, else `invited`, else `none`.
     *
     * @return 'none'|'invited'|'active'|'inactive'|'suspended'
     */
    public static function of(?string $byEmployee, ?string $byUser = null): string
    {
        $statuses = array_values(array_filter([$byEmployee, $byUser], fn (?string $s): bool => $s !== null));

        foreach ($statuses as $status) {
            if ($status !== self::INVITED) {
                return match ($status) {
                    self::INACTIVE, self::SUSPENDED => $status,
                    default => self::ACTIVE,
                };
            }
        }

        return $statuses === [] ? self::NONE : self::INVITED;
    }
}
