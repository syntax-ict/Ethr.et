<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedJson;
use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One discovered person inside a migration batch, with the resolver's verdict
 * and the reviewer's chosen action. See ONBOARDING_V2.md D6.
 *
 * `raw` is the person's details as the source gave them and `changes` is what
 * committing the row would write to the employee it matched
 * (Services\Migration\StagedChanges). Both are stored encrypted.
 *
 * @property array<string, mixed> $raw
 * @property array<int, array<string, mixed>>|null $changes
 * @property bool|null $created_employee whether this create row made its person (true) or only linked to someone already here (false); null for any other row
 */
class MigrationStagingRow extends Model
{
    use BelongsToTenant, HasPublicId;

    public const ACTION_MERGE = 'merge';

    public const ACTION_CREATE = 'create';

    public const ACTION_SKIP = 'skip';

    public const ACTION_DEFER = 'defer';

    public const ACTION_PENDING = 'pending';

    /**
     * Bring back the archived person the row matched and write this row's
     * details over their record: the corrected file after an undo.
     */
    public const ACTION_RESTORE = 'restore';

    /**
     * Beside IdentityMatch's outcomes: nobody matched on anything a person
     * has only one of, and someone is already here under exactly this name,
     * in the department the row names (SameNameRoster). Held for review.
     */
    public const OUTCOME_SAME_NAME = 'same_name';

    protected $fillable = [
        'public_id',
        'tenant_id',
        'batch_id',
        'raw',
        'display_name',
        'external_identifier',
        'match_outcome',
        'match_confidence',
        'candidates',
        'resolved_employee_id',
        'action',
        'changes',
        'processed_at',
        'created_employee',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'batch_id',
        'resolved_employee_id',
    ];

    protected function casts(): array
    {
        return [
            'raw' => EncryptedJson::class,
            'changes' => EncryptedJson::class,
            'candidates' => 'array',
            'match_confidence' => 'float',
            'processed_at' => 'datetime',
            'created_employee' => 'boolean',
        ];
    }

    /** @return BelongsTo<MigrationBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(MigrationBatch::class, 'batch_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function resolvedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'resolved_employee_id');
    }
}
