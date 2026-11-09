<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One statutory value in force over a period: a working-day divisor, the
 * overtime multipliers, a pension scheme's default, a fiscal year's first
 * month. Read through App\Services\Payroll\StatutoryRules, never directly.
 *
 * Platform rows (tenant_id NULL) carry the law and may be scoped to an
 * organisation type or a sector; they are written with DB::table, as the tax
 * ladder's are, because creating through this model stamps the current
 * tenant. An organisation's own row replaces the platform value only where
 * the platform row in force is not `locked` (see `enforcement`).
 *
 * `tenant_overridable` is kept as the projection `enforcement != locked`,
 * set on every save, because the column predates `enforcement` and cannot be
 * dropped.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $tenant_id
 * @property string $code
 * @property string|null $organization_type
 * @property mixed $value
 * @property bool $tenant_overridable
 * @property string $enforcement
 * @property string|null $floor_direction
 * @property string|null $sector
 * @property int|null $profile_version_id
 * @property string|null $legal_reference
 * @property bool $confirmed
 * @property string|null $note
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StatutoryParameter extends Model
{
    use BelongsToTenant, HasPublicId;

    /** The law's value; an organisation has no row of its own. */
    public const LOCKED = 'locked';

    /** An organisation may replace it with its own dated row. */
    public const DEFAULT = 'default';

    /** As DEFAULT, presented as guidance rather than a setting. */
    public const ADVISORY = 'advisory';

    public const ENFORCEMENTS = [self::LOCKED, self::DEFAULT, self::ADVISORY];

    protected static function booted(): void
    {
        static::saving(function (StatutoryParameter $row): void {
            $row->enforcement ??= self::LOCKED;
            $row->tenant_overridable = $row->enforcement !== self::LOCKED;
        });
    }

    protected $fillable = [
        'public_id',
        'tenant_id',
        'code',
        'organization_type',
        'value',
        'tenant_overridable',
        'enforcement',
        'floor_direction',
        'sector',
        'profile_version_id',
        'legal_reference',
        'confirmed',
        'note',
        'effective_from',
        'effective_to',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'json',
            'tenant_overridable' => 'boolean',
            'confirmed' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}
