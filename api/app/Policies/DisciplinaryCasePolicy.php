<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrgScope;
use App\Models\Employee;
use App\Models\User;

/**
 * Who may read and act on an employee's disciplinary cases.
 *
 * Every route sits under `employees/{employee}`, so each decision is about
 * that employee, the case's subject:
 *
 *  - Reading needs `disciplinary_case.viewAny` and the subject inside the
 *    caller's org scope. It used to need the permission alone, so every
 *    supervisor in the tenant read every case — their peers' and their own
 *    manager's — though the grant is for "their team's".
 *  - Opening and appealing need `disciplinary_case.manage` and the same scope.
 *  - Deciding — notes, the decision, the appeal outcome, closing — also
 *    refuses the subject themselves. Nothing compared the two, so an HR admin
 *    who was the subject could record `not_guilty` on their own case or
 *    uphold their own appeal and erase the sanction. Leave and attendance
 *    corrections already refuse deciding one's own.
 *
 * Method names carry no dot so `Gate::before` (which answers only for known
 * permission names) never intercepts them.
 */
class DisciplinaryCasePolicy
{
    public function view(User $user, Employee $subject): bool
    {
        return $user->hasPermission('disciplinary_case.viewAny')
            && $this->reaches($user, $subject);
    }

    public function open(User $user, Employee $subject): bool
    {
        return $user->hasPermission('disciplinary_case.manage')
            && $this->reaches($user, $subject);
    }

    public function decide(User $user, Employee $subject): bool
    {
        return $this->open($user, $subject)
            && ! $this->isSubject($user, $subject);
    }

    private function reaches(User $user, Employee $subject): bool
    {
        return $user->orgScope() === OrgScope::ALL || $user->canAccessEmployee($subject);
    }

    private function isSubject(User $user, Employee $subject): bool
    {
        return $user->employee_id !== null && $user->employee_id === $subject->id;
    }
}
