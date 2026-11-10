<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EmployeeNumbering;
use App\Services\PlanLimitService;
use Closure;

/**
 * The one way an employee record is made.
 *
 * One employee record, many attendance sources, one attendance engine: that
 * only holds while a person has one record. Six paths made employees (the
 * form, the file import, the staged list, a hire, SCIM and SSO) and they asked
 * "is this someone we already have?" in five different ways. Two of them did
 * not ask at all, so a directory pushing someone HR had already added made a
 * second person.
 *
 * Every path now presents the person here. admit() asks IdentityResolver with
 * every detail the record would be created from, and creates only when the
 * answer is "nobody we know", or when the caller accepts the answer it got: a
 * person on the form ticking "add as a new person", a reviewer who chose
 * "create" on a staged row. `EmployeeCreationInventoryTest` fails when an
 * employee is created anywhere else.
 *
 * **The seat lock is the intake lock.** Every path already held
 * PlanLimitService's lock for the plan's limit, and that lock is not
 * re-entrant, so this takes no second one: serialised() is that lock under the
 * name the callers mean. Asking and creating have to sit inside it together,
 * or two requests for one person both hear "nobody we know".
 */
final class EmployeeIntake
{
    public const VIA_MANUAL = 'manual';

    public const VIA_API = 'api';

    public const VIA_IMPORT = 'import';

    public const VIA_MIGRATION = 'migration';

    public const VIA_DEVICE = 'device';

    public const VIA_RECRUITMENT = 'recruitment';

    public const VIA_SCIM = 'scim';

    public const VIA_SSO = 'sso';

    public function __construct(
        private readonly IdentityResolver $resolver,
        private readonly EmployeeNumbering $numbering,
        private readonly PlanLimitService $planLimits,
    ) {}

    /**
     * Run `$work` as the organisation's only writer of employees. `$work` must
     * commit before it returns: a transaction still open when the lock is
     * released hides its rows from the next writer's question.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function serialised(Tenant $tenant, Closure $work): mixed
    {
        return $this->planLimits->serialised($tenant, 'employees', $work);
    }

    /**
     * Present a person: ask, and create when the answer allows it. The caller
     * holds the lock (serialised(), or PlanLimitService::lock() where a
     * controller releases it itself).
     *
     * `$accept` lists the answers the caller creates on regardless, from
     * IntakeResult's constants. Empty means "only someone nobody knows".
     *
     * `$seatChecked` is for a batch that checked the plan's limit for all its
     * rows before the first one: asking again per row is a count each.
     *
     * `$pool` is a batch's employee numbers, read once
     * (EmployeeNumbering::createFromPool).
     *
     * @param  array<string, mixed>  $attributes  the employee's own columns
     * @param  list<string>  $accept
     * @param  list<string>|null  $pool
     *
     * @param-out ($pool is null ? null : list<string>) $pool
     */
    public function admit(
        Tenant $tenant,
        array $attributes,
        IdentitySignals $signals,
        string $via,
        array $accept = [],
        bool $seatChecked = false,
        ?array &$pool = null,
    ): IntakeResult {
        $asked = $this->ask($tenant, $signals);

        if (! $asked->unknown() && ! in_array($asked->outcome, $accept, true)) {
            return $asked;
        }

        return $this->create($tenant, $attributes, $via, $asked, $seatChecked, $pool);
    }

    /**
     * Who this person looks like, and nothing else. For a caller with a check
     * of its own to make between the question and the record: the file import
     * refuses a badge number someone else holds.
     */
    public function ask(Tenant $tenant, IdentitySignals $signals): IntakeResult
    {
        $match = $this->resolver->resolve($tenant->id, $signals);

        return new IntakeResult($this->outcomeOf($match), $match->employee, $match);
    }

    /**
     * Make the record. `$asked` is the answer ask() gave for this person: a
     * record cannot be made without the question having been put.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>|null  $pool
     *
     * @param-out ($pool is null ? null : list<string>) $pool
     */
    public function create(
        Tenant $tenant,
        array $attributes,
        string $via,
        IntakeResult $asked,
        bool $seatChecked = false,
        ?array &$pool = null,
    ): IntakeResult {
        if (! $seatChecked) {
            $this->planLimits->assertCanAdd($tenant, 'employees');
        }

        $attributes = ['tenant_id' => $tenant->id, ...$attributes, 'created_via' => $via];

        $employee = $pool === null
            ? $this->numbering->create($tenant, $attributes)
            : $this->numbering->createFromPool($tenant, $attributes, $pool);

        return new IntakeResult(IntakeResult::CREATED, $employee, $asked->match);
    }

    /**
     * Present a person a directory sent (SCIM, SSO), where nobody is there to
     * ask. The person arrives as a login, so the answer is either a record to
     * hang it on or a refusal:
     *
     *  - nobody we know, or only a deleted record: a new employee;
     *  - one current employee, with no login yet and no other e-mail: that
     *    employee (`matched`), and no seat is used;
     *  - anything else (a tie, a weaker match, a leaver, someone who already
     *    signs in under another address): `needs_review`, and nothing is made.
     *
     * A deleted record does not block a directory. Deleting was a person's
     * decision that the record should not be in use, and the directory sending
     * the same person again is not a reason to undo it or to refuse them.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function admitUnattended(Tenant $tenant, array $attributes, string $via): IntakeResult
    {
        $asked = $this->ask($tenant, IdentitySignals::fromAttributes($attributes));

        if ($asked->unknown() || $asked->outcome === IntakeResult::ARCHIVED) {
            return $this->create($tenant, $attributes, $via, $asked);
        }

        $email = $attributes['email'] ?? null;
        if ($asked->outcome === IntakeResult::MATCHED
            && $asked->employee !== null
            && $this->canTakeLogin($asked->employee, is_string($email) ? $email : null)) {
            return $asked;
        }

        return new IntakeResult(IntakeResult::NEEDS_REVIEW, $asked->employee, $asked->match);
    }

    /**
     * A leaver is `former_employee` whether the match was certain or only
     * likely: either way the question for the caller is "the same person,
     * back?", and recruitment answers it differently from a directory.
     */
    private function outcomeOf(IdentityMatch $match): string
    {
        return match (true) {
            $match->outcome === IdentityMatch::NEW => IntakeResult::UNKNOWN,
            $match->outcome === IdentityMatch::ARCHIVED => IntakeResult::ARCHIVED,
            $match->isFormerEmployee() => IntakeResult::FORMER_EMPLOYEE,
            $match->outcome === IdentityMatch::MATCHED => IntakeResult::MATCHED,
            default => IntakeResult::NEEDS_REVIEW,
        };
    }

    /**
     * Whether a directory's login may be hung on this employee: they have none,
     * and the address on their record is this one or blank. An employee who
     * already signs in under another address is someone else's account to the
     * directory, and guessing which is right is how one person ends up able to
     * read another's payslips.
     *
     * The login lookup states the tenant itself, from the employee in hand.
     * Left to the scope, the answer would depend on which organisation the
     * request happened to have resolved, and "none" or "another" both read as
     * "no login": the unsafe answer. A deleted login does not count; the
     * person may be given a new one.
     */
    private function canTakeLogin(Employee $employee, ?string $email): bool
    {
        $own = trim((string) $employee->email);
        if ($own !== '' && ($email === null || mb_strtolower($own) !== mb_strtolower(trim($email)))) {
            return false;
        }

        return ! User::withoutGlobalScope('tenant')
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->exists();
    }
}
