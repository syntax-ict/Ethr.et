<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of an exit clearance. `owner` says who may tick it (hr, finance,
 * supervisor; HR may tick any). `kind` is `obligations` for the line that
 * shows the leaver's outstanding loan and cost-sharing balance. A built-in
 * line carries its `key` and is shown in the reader's language; a line the
 * organisation wrote is shown as written.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $exit_clearance_id
 * @property int $position
 * @property string|null $key
 * @property string $label
 * @property string $owner
 * @property string $kind
 * @property Carbon|null $done_at
 * @property int|null $done_by
 * @property string|null $note
 */
class ExitClearanceItem extends Model
{
    use BelongsToTenant, HasPublicId;

    public const OWNER_HR = 'hr';

    public const OWNER_FINANCE = 'finance';

    public const OWNER_SUPERVISOR = 'supervisor';

    public const OWNERS = [self::OWNER_HR, self::OWNER_FINANCE, self::OWNER_SUPERVISOR];

    public const KIND_MANUAL = 'manual';

    public const KIND_OBLIGATIONS = 'obligations';

    protected $fillable = [
        'public_id',
        'tenant_id',
        'exit_clearance_id',
        'position',
        'key',
        'label',
        'owner',
        'kind',
        'done_at',
        'done_by',
        'note',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'exit_clearance_id',
        'done_by',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'done_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ExitClearance, $this> */
    public function clearance(): BelongsTo
    {
        return $this->belongsTo(ExitClearance::class, 'exit_clearance_id');
    }

    /** @return BelongsTo<User, $this> */
    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
