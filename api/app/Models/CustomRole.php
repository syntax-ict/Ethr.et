<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomRole extends Model
{
    use BelongsToTenant, HasAuditLog, HasPublicId, SoftDeletes;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'name',
        'description',
        'is_active',
        'org_scope',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * `description` is optional in the UI and FormRequest (`nullable`), but the
     * column is NOT NULL (defaults to ''). Laravel's ConvertEmptyStringsToNull
     * middleware turns a blank description into null, which would violate the
     * constraint on insert/update — so coerce null back to an empty string.
     *
     * @return Attribute<string, string>
     */
    protected function description(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): string => $value ?? '',
        );
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'custom_role_permissions');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
