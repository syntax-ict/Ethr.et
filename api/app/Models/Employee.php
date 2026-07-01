<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmployeeStatus;
use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'user_id',
        'department_id',
        'branch_id',
        'position_id',
        'grade_id',
        'team_id',
        'cost_center_id',
        'supervisor_id',
        'name',
        'name_am',
        'email',
        'phone',
        'employee_code',
        'badge_number',
        'kiosk_pin',
        'gender',
        'date_of_birth',
        'nationality',
        'marital_status',
        'status',
        'hire_date',
        'probation_end_date',
        'confirmation_date',
        'termination_date',
        'salary_cents',
        'photo_path',
        'tin',
        'import_key',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'tin',
        'kiosk_pin',
    ];

    protected function casts(): array
    {
        return [
            'status' => EmployeeStatus::class,
            'date_of_birth' => 'date',
            'hire_date' => 'date',
            'probation_end_date' => 'date',
            'confirmation_date' => 'date',
            'termination_date' => 'date',
            'salary_cents' => 'integer',
            'tin' => 'encrypted',
        ];
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supervisor_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'supervisor_id');
    }

    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmployeeEmergencyContact::class);
    }

    public function bankDetails(): HasMany
    {
        return $this->hasMany(EmployeeBankDetail::class);
    }

    public function education(): HasMany
    {
        return $this->hasMany(EmployeeEducation::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(EmployeeTransition::class)->orderByDesc('created_at');
    }
}
