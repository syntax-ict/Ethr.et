<?php

declare(strict_types=1);

namespace App\Services\Invitations;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\InvitationSend;
use App\Models\User;
use App\Services\UserProvisioningService;

/**
 * A way to sign in for employees who have none: a login for each, then one
 * queued batch of invitations. The one place that decides, per person, which
 * kind of login they get, so the import, the device migration and the
 * "send invitations" step cannot drift apart.
 *
 * - An email: an invited login, invited by email.
 * - A phone and no email, while text invitations are on: an invited login,
 *   invited by text.
 * - Neither, or texts off: no login yet. HR activates them in person, and
 *   the in-person code makes the account when it is issued.
 */
final class EmployeeLogins
{
    public const INVITED = 'invited';

    public const IN_PERSON = 'in_person';

    public const EMAIL_IN_USE = 'email_in_use';

    public function __construct(
        private readonly UserProvisioningService $provisioning,
        private readonly InvitationDispatcher $invitations,
    ) {}

    /**
     * A login for one employee with none, nothing sent. Returns the user to
     * invite, or why there is none: IN_PERSON, or EMAIL_IN_USE when the
     * address already belongs to another login of this organisation.
     */
    public function provision(Employee $employee, ?User $by = null): User|string
    {
        if (is_string($employee->email) && trim($employee->email) !== '') {
            return $this->provisioning->provision(
                email: $employee->email,
                role: UserRole::EMPLOYEE,
                employee: $employee,
                invitedBy: $by?->id,
                sendActivation: false,
            ) ?? self::EMAIL_IN_USE;
        }

        if (is_string($employee->phone) && trim($employee->phone) !== '' && $this->invitations->textsEnabled()) {
            return $this->provisioning->provisionWithPhone($employee, $by?->id);
        }

        return self::IN_PERSON;
    }

    /**
     * Give each employee a way in and queue the invitations. Someone already
     * invited and not yet activated is invited again (the dispatcher skips one
     * invited in the last few minutes); someone active is left alone.
     *
     * @param  iterable<Employee>  $employees
     * @return array{created: int, active: int, in_person: int, email_in_use: int, batch: string|null, queued: int}
     */
    public function forEmployees(iterable $employees, ?User $by = null): array
    {
        $result = ['created' => 0, 'active' => 0, 'in_person' => 0, 'email_in_use' => 0];
        $invitees = [];

        foreach ($employees as $employee) {
            $user = $employee->user;

            if ($user !== null) {
                $user->status === 'invited' ? $invitees[] = $user : $result['active']++;

                continue;
            }

            $outcome = $this->provision($employee, $by);
            if ($outcome instanceof User) {
                $result['created']++;
                $invitees[] = $outcome;
            } else {
                $result[$outcome]++;
            }
        }

        $queued = $invitees === []
            ? ['batch' => null, 'queued' => 0, 'in_person' => 0]
            : $this->invitations->queue($invitees, $by);

        return [
            ...$result,
            'in_person' => $result['in_person'] + $queued['in_person'],
            'batch' => $queued['queued'] > 0 ? $queued['batch'] : null,
            'queued' => $queued['queued'],
        ];
    }

    /**
     * The employees among these who cannot sign in yet, and how each would be
     * invited.
     *
     * @param  iterable<Employee>  $employees
     * @return list<array{employee_public_id: string, name: string, email: string|null, phone: string|null, channel: string, invited: bool}>
     */
    public function pending(iterable $employees): array
    {
        $pending = [];

        foreach ($employees as $employee) {
            $user = $employee->user;
            if ($user !== null && $user->status !== 'invited') {
                continue;
            }

            $pending[] = [
                'employee_public_id' => $employee->public_id,
                'name' => $employee->name,
                'email' => $employee->email,
                'phone' => $employee->phone,
                'channel' => $this->channel($employee),
                'invited' => $user !== null,
            ];
        }

        return $pending;
    }

    private function channel(Employee $employee): string
    {
        if (is_string($employee->email) && trim($employee->email) !== '') {
            return InvitationSend::EMAIL;
        }

        return is_string($employee->phone) && trim($employee->phone) !== '' && $this->invitations->textsEnabled()
            ? InvitationSend::SMS
            : InvitationSend::IN_PERSON;
    }
}
