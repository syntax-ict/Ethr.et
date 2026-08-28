<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SsoSetting extends Model
{
    use BelongsToTenant, HasAuditLog, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'provider',
        'is_enabled',
        'idp_entity_id',
        'idp_sso_url',
        'idp_slo_url',
        'idp_certificate',
        'default_role',
        'auto_provision',
        'attribute_mapping',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'idp_certificate',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'auto_provision' => 'boolean',
            'idp_certificate' => 'encrypted',
            'attribute_mapping' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
