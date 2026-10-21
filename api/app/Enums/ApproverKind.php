<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who decides one step of a workflow. `approver_ref` names the role (a
 * `UserRole` value) or the user (a public id); the other kinds carry none.
 */
enum ApproverKind: string
{
    /** Anyone holding the request's permission who reaches the employee: today's rule. */
    case PERMISSION = 'permission';

    /** The requester's supervisor. */
    case SUPERVISOR = 'supervisor';

    /** The head of the requester's department (`departments.head_employee_id`). */
    case DEPARTMENT_HEAD = 'department_head';

    /** Anyone with this system role who holds the permission and reaches the employee. */
    case ROLE = 'role';

    /** One named user. */
    case USER = 'user';
}
