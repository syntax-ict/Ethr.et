<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The organisation type, and the sector, an organisation is from a date on.
 * Its statutory rules resolve against the assignment in force on the
 * payroll date, so an earlier period keeps the type it was paid under.
 *
 * Written only by App\Services\Tenancy\OrganisationProfile, which keeps
 * `tenants.organization_type` equal to the assignment in force today.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $organization_type
 * @property string|null $sector
 * @property Carbon $effective_from
 * @property int|null $created_by
 */
class TenantProfileAssignment extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'organization_type',
        'sector',
        'effective_from',
        'created_by',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
        ];
    }

    /**
     * BelongsToTenant's relation, with its types stated: larastan cannot
     * infer them for this model from the trait alone.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
