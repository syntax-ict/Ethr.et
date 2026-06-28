<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
