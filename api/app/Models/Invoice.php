<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'subscription_id',
        'amount_cents',
        'tax_cents',
        'total_cents',
        'status',
        'line_items',
        'paid_at',
        'due_date',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'subscription_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'line_items' => 'array',
            'paid_at' => 'datetime',
            'due_date' => 'date',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
