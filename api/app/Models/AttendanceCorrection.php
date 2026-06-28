<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CorrectionStatus;
use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceCorrection extends Model
{
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'attendance_record_id',
        'employee_id',
        'reason',
        'proposed_check_in',
        'proposed_check_out',
        'status',
        'approval_chain',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'attendance_record_id',
        'employee_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => CorrectionStatus::class,
            'proposed_check_in' => 'datetime',
            'proposed_check_out' => 'datetime',
            'approval_chain' => 'array',
        ];
    }

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
