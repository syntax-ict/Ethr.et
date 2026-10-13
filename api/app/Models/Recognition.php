<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A colleague thanking a colleague, for one of the organisation's values.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $from_employee_id
 * @property int $to_employee_id
 * @property string $value
 * @property string $message
 * @property Carbon|null $created_at
 * @property-read Employee|null $from
 * @property-read Employee|null $to
 */
class Recognition extends Model
{
    use BelongsToTenant, HasPublicId;

    public const VALUES = ['teamwork', 'customer_care', 'integrity', 'initiative', 'excellence', 'helping_hand'];

    protected $fillable = ['tenant_id', 'from_employee_id', 'to_employee_id', 'value', 'message'];

    /** @return BelongsTo<Employee, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_employee_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function to(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_employee_id');
    }
}
