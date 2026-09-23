<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use App\Traits\ScopesEmployeeAccess;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * The datetime properties are declared for the same reason as on Employee:
 * Larastan reads the schema, which cannot see the casts below.
 *
 * @property UserRole $role
 * @property array<string, mixed>|null $preferences
 * @property Carbon|null $last_login_at
 * @property Carbon|null $email_verified_at
 * @property-read Employee|null $employee A user need not be an employee — `users.employee_id` is nullable.
 * @property-read string $name Display name, computed — see name().
 */
class User extends Authenticatable
{
    use BelongsToTenant, HasApiTokens, HasAuditLog, HasFactory, HasPublicId, Notifiable, ScopesEmployeeAccess, SoftDeletes;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'employee_id',
        'email',
        'username',
        'phone',
        'password',
        'password_changed_at',
        'role',
        'custom_role_id',
        'status',
        'invited_by',
        'invited_at',
        'activated_at',
        'email_verified_at',
        'mfa_enabled',
        'mfa_secret',
        'locale',
        'preferences',
        'last_login_at',
    ];

    // The three `*_normalized` entries are generated columns that exist only so
    // the login-identifier lookups can use an index. They duplicate `email`,
    // `username` and `phone` in normalised form and carry nothing a client
    // needs, so they are hidden for the same reason `national_id_hash` is on
    // `Employee`: a derived column is not part of the resource. Added by
    // `2026_09_23_000003_index_login_identifier_lookups.php`. The explanation
    // sits on the statement rather than above the keys because Scramble
    // publishes comments above array keys as OpenAPI descriptions.
    protected $hidden = [
        'id',
        'tenant_id',
        'password',
        'mfa_secret',
        'remember_token',
        'email_normalized',
        'username_normalized',
        'phone_normalized',
    ];

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'preferences' => 'array',
            'mfa_enabled' => 'boolean',
            'mfa_secret' => 'encrypted',
            'password' => 'hashed',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'invited_at' => 'datetime',
            'activated_at' => 'datetime',
            'email_verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * `users` carries no display name column of its own — every name in the
     * product is read through the linked Employee — so fall back to the
     * login email for an account with no employee record (an HR-only login,
     * e.g. a super admin). loadMissing rather than a bare relation read:
     * `preventLazyLoading` is on outside production, so a caller that forgot
     * to eager-load `employee` would 500 here instead of just costing a query.
     *
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $this->loadMissing('employee');
                $employee = $this->employee;

                return $employee instanceof Employee ? $employee->name : $this->email;
            },
        );
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'invited_by');
    }

    public function isInvited(): bool
    {
        return $this->status === 'invited';
    }

    public function hasRole(UserRole $role): bool
    {
        return $this->role === $role;
    }

    public function hasAnyRole(array $roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isAtLeast(UserRole $role): bool
    {
        return $this->role->isAtLeast($role);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SUPER_ADMIN;
    }

    public function isTenantAdmin(): bool
    {
        return $this->role === UserRole::TENANT_ADMIN;
    }

    public function customRole(): BelongsTo
    {
        return $this->belongsTo(CustomRole::class);
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->role === UserRole::SUPER_ADMIN) {
            return true;
        }

        return in_array($permission, $this->permissionNames(), true);
    }

    /**
     * The abilities this user holds, resolved exactly as hasPermission() resolves
     * them: a custom role replaces the base role's set entirely. super_admin has no
     * role_permissions rows because hasPermission() short-circuits, so it gets the
     * whole catalogue here instead of an empty list.
     *
     * @return list<string>
     */
    public function permissionNames(): array
    {
        if ($this->role === UserRole::SUPER_ADMIN) {
            return Permission::allNames();
        }

        if ($this->custom_role_id) {
            return Permission::permissionsForCustomRole($this->custom_role_id);
        }

        return Permission::permissionsForRole($this->role->value);
    }

    public function receivesBroadcastNotificationsOn(): string
    {
        return 'private-user.'.$this->public_id;
    }
}
