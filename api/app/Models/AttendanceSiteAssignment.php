<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A work site opened to one employee or a whole department.
 */
class AttendanceSiteAssignment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'attendance_site_id',
        'assignable_type',
        'assignable_id',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    /** @return BelongsTo<AttendanceSite, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(AttendanceSite::class, 'attendance_site_id');
    }
}
