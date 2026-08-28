<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A non-working day for a tenant, optionally scoped to a single branch.
 *
 * `is_estimated` marks a holiday whose date was computed from the tabular
 * Hijri calendar: Ethiopia observes Islamic feasts by local moon sighting, so
 * the stored date can be a day out and HR is expected to confirm it.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int|null $branch_id
 * @property string $name
 * @property string|null $name_am
 * @property Carbon $date
 * @property bool $ethiopian_calendar
 * @property bool $recurring
 * @property bool $is_estimated
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch|null $branch
 */
class Holiday extends Model
{
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'branch_id',
        'name',
        'name_am',
        'date',
        'ethiopian_calendar',
        'recurring',
        'is_estimated',
        'is_active',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'branch_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'ethiopian_calendar' => 'boolean',
            'recurring' => 'boolean',
            'is_estimated' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
