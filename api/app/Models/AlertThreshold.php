<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant-defined rule over one of AlertEvaluator::METRICS — see its
 * docblock for what "operator" and "metric" mean and the full evaluation
 * logic. This model is deliberately just storage; all comparison logic lives
 * in AlertEvaluator so the digest job and the API endpoint can't drift into
 * evaluating a threshold differently.
 */
class AlertThreshold extends Model
{
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId;

    protected $fillable = [
        'tenant_id',
        'created_by',
        'metric',
        'operator',
        'threshold_value',
        'severity',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'threshold_value' => 'float',
            'is_active' => 'boolean',
        ];
    }
}
