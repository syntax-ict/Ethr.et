<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmployeeStatus;
use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Larastan types columns from the schema, which cannot see the date casts below —
 * without these the Carbon instances read as plain strings.
 *
 * Every org assignment below is a nullable foreign key — an employee can exist
 * before they have a department, position, branch, grade or supervisor.
 *
 * @property Carbon|null $date_of_birth
 * @property Carbon|null $hire_date
 * @property-read Department|null $department
 * @property-read Position|null $position
 * @property-read Branch|null $branch
 * @property-read Grade|null $grade
 * @property-read Employee|null $supervisor
 */
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
        'national_id',
        'marital_status',
        'status',
        'hire_date',
        'probation_end_date',
        'confirmation_date',
        'termination_date',
        'salary_cents',
        'salary_step',
        'photo_path',
        'tin',
        'import_key',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'tin',
        'kiosk_pin',
        'national_id',
        'national_id_hash',
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
            'national_id' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        // Keep the blind-index hash in step with the encrypted national ID so
        // IdentityResolver can match on it without decrypting. Guarded by isDirty
        // so an unrelated update — or a save of a partially-selected model where
        // national_id wasn't loaded — never wipes an existing hash.
        static::saving(function (Employee $employee): void {
            if ($employee->isDirty('national_id')) {
                $employee->national_id_hash = self::hashNationalId($employee->national_id);
            }
        });
    }

    /** Deterministic HMAC of a normalized national ID, for equality lookups. */
    public static function hashNationalId(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Strip every separator (spaces, dashes, dots, slashes) and upper-case so
        // the same ID written in different styles hashes identically.
        $normalized = mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '');

        if ($normalized === '') {
            return null;
        }

        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            $boolean = str_replace(['@', '+', '-', '<', '>', '(', ')', '~', '*', '"'], '', $term);

            return $query->whereRaw(
                'MATCH (name, name_am, email, employee_code) AGAINST (? IN BOOLEAN MODE)',
                [$boolean.'*'],
            );
        }

        return $query->where(function (Builder $q) use ($term) {
            $like = "%{$term}%";
            $q->where('name', 'like', $like)
                ->orWhere('name_am', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('employee_code', 'like', $like);
        });
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Position, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** @return BelongsTo<Grade, $this> */
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

    /** @return BelongsTo<self, $this> */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supervisor_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'supervisor_id');
    }

    /**
     * The reporting subtree beneath this employee, eager-loaded to any depth for
     * the org reporting chart. Each level carries its position for display and
     * orders reports by name so the tree renders deterministically.
     *
     * @return HasMany<self, $this>
     */
    public function directReportsRecursive(): HasMany
    {
        return $this->directReports()
            ->with(['position', 'directReportsRecursive'])
            ->orderBy('name');
    }

    /** @return HasMany<EmployeeEmergencyContact, $this> */
    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmployeeEmergencyContact::class);
    }

    /** @return HasMany<PersonnelAction, $this> */
    public function personnelActions(): HasMany
    {
        return $this->hasMany(PersonnelAction::class);
    }

    /** @return HasMany<DisciplinaryCase, $this> */
    public function disciplinaryCases(): HasMany
    {
        return $this->hasMany(DisciplinaryCase::class);
    }

    /** @return HasMany<EmployeeContract, $this> */
    public function contracts(): HasMany
    {
        return $this->hasMany(EmployeeContract::class);
    }

    /** @return HasMany<RetirementCase, $this> */
    public function retirementCases(): HasMany
    {
        return $this->hasMany(RetirementCase::class);
    }

    /** @return HasMany<EmployeeBankDetail, $this> */
    public function bankDetails(): HasMany
    {
        return $this->hasMany(EmployeeBankDetail::class);
    }

    public function education(): HasMany
    {
        return $this->hasMany(EmployeeEducation::class);
    }

    /** @return HasMany<ProfileUpdateRequest, $this> */
    public function profileUpdateRequests(): HasMany
    {
        return $this->hasMany(ProfileUpdateRequest::class);
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
