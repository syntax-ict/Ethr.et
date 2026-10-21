<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'parent_id',
        'branch_id',
        'head_employee_id',
        'name',
        'name_am',
        'code',
        'is_active',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'parent_id',
        'branch_id',
        'head_employee_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function childrenRecursive(): HasMany
    {
        return $this->children()
            ->withCount('employees')
            ->orderBy('name')
            ->with('childrenRecursive');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Who heads the department, for approval chains with a department-head
     * step. Not a reporting line: supervisors stay on each employee.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function head(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'head_employee_id');
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
