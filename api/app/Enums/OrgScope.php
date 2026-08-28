<?php

declare(strict_types=1);

namespace App\Enums;

enum OrgScope: string
{
    case ALL = 'all';
    case BRANCH = 'branch';
    case DEPARTMENT = 'department';
    case TEAM = 'team';
    case DIRECT_REPORTS = 'direct_reports';
    case SELF = 'self';

    public static function fromRole(UserRole $role): self
    {
        return match ($role) {
            UserRole::SUPER_ADMIN, UserRole::TENANT_ADMIN, UserRole::HR_ADMIN, UserRole::FINANCE_ADMIN => self::ALL,
            UserRole::DEPT_ADMIN => self::DEPARTMENT,
            UserRole::SUPERVISOR => self::DIRECT_REPORTS,
            UserRole::EMPLOYEE => self::SELF,
        };
    }
}
