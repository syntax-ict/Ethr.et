<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollEntry extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'payroll_run_id',
        'employee_id',
        'basic_salary_cents',
        'allowances',
        'deductions',
        'gross_cents',
        'income_tax_cents',
        'employee_pension_cents',
        'employer_pension_cents',
        'other_deductions_cents',
        'net_cents',
        'calculation_log',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'basic_salary_cents' => 'integer',
            'allowances' => 'array',
            'deductions' => 'array',
            'gross_cents' => 'integer',
            'income_tax_cents' => 'integer',
            'employee_pension_cents' => 'integer',
            'employer_pension_cents' => 'integer',
            'other_deductions_cents' => 'integer',
            'net_cents' => 'integer',
            'calculation_log' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $entry) {
            if ($entry->isDirty('calculation_log') && $entry->getOriginal('calculation_log') !== null) {
                throw new \LogicException('calculation_log is immutable after initial creation.');
            }
        });
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
