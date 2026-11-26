<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One salary an employee has had or will have, and the day it takes effect.
 *
 * Written by the Employee model's `saved` hook and by the writers that date a
 * change in the future (a scheduled personnel action, a future contract). A
 * payroll run pays the row in force on its period's last day
 * (App\Services\Payroll\SalaryInForce).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $employee_id
 * @property int $salary_cents
 * @property Carbon $effective_date
 * @property string $source personnel_action|contract|edit|import|hire|backfill
 * @property int|null $source_id
 * @property int|null $recorded_by
 * @property int|null $superseded_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Employee $employee
 */
class EmployeeSalaryHistory extends Model
{
    use BelongsToTenant;

    protected $table = 'employee_salary_history';

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'salary_cents',
        'effective_date',
        'source',
        'source_id',
        'recorded_by',
        'superseded_by',
    ];

    protected $hidden = ['tenant_id'];

    protected function casts(): array
    {
        return [
            'salary_cents' => 'integer',
            'effective_date' => 'date',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
