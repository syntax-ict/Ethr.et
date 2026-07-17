<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PayrollRun extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'period_label',
        'period_start',
        'period_end',
        'idempotency_key',
        'status',
        'employee_count',
        'gross_total_cents',
        'net_total_cents',
        'tax_total_cents',
        'processed_by',
        'approved_by',
        'processed_at',
        'approved_at',
        'voided_at',
        'voided_by',
        'void_reason',
        'reprocessed_from_id',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'employee_count' => 'integer',
            'gross_total_cents' => 'integer',
            'net_total_cents' => 'integer',
            'tax_total_cents' => 'integer',
            'processed_at' => 'datetime',
            'approved_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }

    public function processedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function voidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function reprocessedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reprocessed_from_id');
    }

    public function reprocessedInto(): HasOne
    {
        return $this->hasOne(self::class, 'reprocessed_from_id');
    }
}
