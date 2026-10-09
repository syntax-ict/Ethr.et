<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Permission extends Model
{
    use HasPublicId;

    protected $fillable = [
        'public_id',
        'name',
        'module',
        'action',
        'description',
    ];

    protected $hidden = [
        'id',
    ];

    /**
     * Abilities that belong to the platform, never to a tenant: held by the
     * super admin alone and never delegable through a role. `admin.manage` is
     * in the catalogue so `Gate::before` resolves it, which also made it
     * grantable through a custom role (audit N86).
     */
    public const PLATFORM_ONLY = ['admin.manage'];

    /**
     * Every ability defined in the catalogue.
     *
     * @return list<string>
     */
    public static function allNames(): array
    {
        return Cache::remember('permissions:known_abilities', 3600, function () {
            return DB::table('permissions')->pluck('name')->all();
        });
    }

    public static function isKnownAbility(string $ability): bool
    {
        return in_array($ability, self::allNames(), true);
    }

    public static function permissionsForRole(string $role): array
    {
        return Cache::remember("role_permissions:{$role}", 3600, function () use ($role) {
            return DB::table('role_permissions')
                ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->where('role_permissions.role', $role)
                ->whereNotIn('permissions.name', self::PLATFORM_ONLY)
                ->pluck('permissions.name')
                ->all();
        });
    }

    public static function permissionsForCustomRole(int $customRoleId): array
    {
        return Cache::remember("custom_role_permissions:{$customRoleId}", 3600, function () use ($customRoleId) {
            return DB::table('custom_role_permissions')
                ->join('permissions', 'permissions.id', '=', 'custom_role_permissions.permission_id')
                ->where('custom_role_permissions.custom_role_id', $customRoleId)
                // Whatever a row says, a role never carries a platform
                // ability: only a super admin holds one, through allNames()
                // (audit N86).
                ->whereNotIn('permissions.name', self::PLATFORM_ONLY)
                ->pluck('permissions.name')
                ->all();
        });
    }

    public static function clearCache(?string $role = null): void
    {
        Cache::forget('permissions:known_abilities');

        if ($role) {
            Cache::forget("role_permissions:{$role}");
        } else {
            foreach (UserRole::cases() as $case) {
                Cache::forget("role_permissions:{$case->value}");
            }
        }
    }

    public static function clearCacheForCustomRole(int $customRoleId): void
    {
        Cache::forget("custom_role_permissions:{$customRoleId}");
    }
}
