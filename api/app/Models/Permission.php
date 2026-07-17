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

    public static function isKnownAbility(string $ability): bool
    {
        $known = Cache::remember('permissions:known_abilities', 3600, function () {
            return DB::table('permissions')->pluck('name')->all();
        });

        return in_array($ability, $known, true);
    }

    public static function permissionsForRole(string $role): array
    {
        return Cache::remember("role_permissions:{$role}", 3600, function () use ($role) {
            return DB::table('role_permissions')
                ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->where('role_permissions.role', $role)
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
