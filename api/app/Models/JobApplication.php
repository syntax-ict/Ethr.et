<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApplicationStage;
use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Someone applying for a vacancy. Moves through `ApplicationStage`; the hire
 * links it to exactly one employee record.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $vacancy_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string $source
 * @property string|null $cover_note
 * @property ApplicationStage $stage
 * @property Carbon|null $offer_start_date
 * @property int|null $offer_salary_cents
 * @property string|null $offer_notes
 * @property int|null $offer_made_by
 * @property int|null $offer_approved_by
 * @property Carbon|null $offer_approved_at
 * @property int|null $employee_id
 * @property int|null $hired_by
 * @property Carbon|null $hired_at
 * @property string|null $closed_reason
 * @property Carbon|null $closed_at
 * @property Carbon|null $erased_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property-read Vacancy|null $vacancy
 * @property-read Employee|null $employee
 * @property-read User|null $offerMadeBy
 * @property-read User|null $offerApprovedBy
 */
class JobApplication extends Model
{
    use BelongsToTenant, HasPublicId;

    public const SOURCES = ['email', 'walk_in', 'referral', 'internal', 'agency', 'website', 'other'];

    protected $fillable = [
        'tenant_id',
        'vacancy_id',
        'name',
        'email',
        'phone',
        'source',
        'cover_note',
        'stage',
        'offer_start_date',
        'offer_salary_cents',
        'offer_notes',
        'offer_made_by',
        'offer_approved_by',
        'offer_approved_at',
        'employee_id',
        'hired_by',
        'hired_at',
        'closed_reason',
        'closed_at',
        'erased_at',
        'created_by',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'stage' => ApplicationStage::class,
            'offer_start_date' => 'date',
            'offer_salary_cents' => 'integer',
            'offer_approved_at' => 'datetime',
            'hired_at' => 'datetime',
            'closed_at' => 'datetime',
            'erased_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Vacancy, $this> */
    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class)->withTrashed();
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<JobApplicationEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(JobApplicationEvent::class)->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function offerMadeBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'offer_made_by');
    }

    /** @return BelongsTo<User, $this> */
    public function offerApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'offer_approved_by');
    }
}
