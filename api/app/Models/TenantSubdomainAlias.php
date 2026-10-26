<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An address the organisation used to have. It keeps resolving to the
 * organisation, which forwards it to the current one, and it stays reserved
 * so no other organisation can take it (Tenant::findBySlug,
 * Tenant::slugAvailableFor, TenantAddressService::changeSubdomain).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $subdomain
 * @property Carbon|null $created_at
 */
class TenantSubdomainAlias extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'subdomain',
    ];

    protected $hidden = ['id', 'tenant_id'];
}
