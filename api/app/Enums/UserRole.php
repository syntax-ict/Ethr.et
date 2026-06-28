<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case SUPER_ADMIN = 'super_admin';
    case TENANT_ADMIN = 'tenant_admin';
    case HR_ADMIN = 'hr_admin';
    case FINANCE_ADMIN = 'finance_admin';
    case DEPT_ADMIN = 'dept_admin';
    case SUPERVISOR = 'supervisor';
    case EMPLOYEE = 'employee';

    public function isAtLeast(self $role): bool
    {
        return $this->level() >= $role->level();
    }

    public function level(): int
    {
        return match ($this) {
            self::SUPER_ADMIN => 100,
            self::TENANT_ADMIN => 90,
            self::HR_ADMIN => 70,
            self::FINANCE_ADMIN => 70,
            self::DEPT_ADMIN => 50,
            self::SUPERVISOR => 30,
            self::EMPLOYEE => 10,
        };
    }
}
