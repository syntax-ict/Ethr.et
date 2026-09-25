<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledReport extends Model
{
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId;

    protected $fillable = [
        'tenant_id',
        'saved_report_id',
        'frequency',
        'recipients',
        'last_run_at',
        'last_error',
        'next_run_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function savedReport(): BelongsTo
    {
        return $this->belongsTo(SavedReport::class);
    }
}
