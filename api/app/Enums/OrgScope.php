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

    /**
     * How far the scope reaches, for comparing two: nobody may hand out a
     * custom role that reaches further than they do (audit N89).
     */
    public function breadth(): int
    {
        return match ($this) {
            self::SELF => 0,
            self::DIRECT_REPORTS => 1,
            self::TEAM => 2,
            self::DEPARTMENT => 3,
            self::BRANCH => 4,
            self::ALL => 5,
        };
    }

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
