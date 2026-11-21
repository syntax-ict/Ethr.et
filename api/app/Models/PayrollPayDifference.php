<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The pay difference a change to someone's salary or leaving date left in a
 * month already released. See App\Services\Payroll\PayDifferenceRecorder.
 *
 * `pending` has no amounts yet; `open` is waiting for finance; `failed` could
 * not be worked out (`detail.error`) and can only be dismissed; `settled` and
 * `dismissed` are handled and stay as the history the next difference is
 * measured from; `superseded` belonged to a run that was voided.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $employee_id
 * @property int $payroll_run_id
 * @property int $payroll_entry_id
 * @property string|null $kind underpaid|overpaid
 * @property int|null $gross_cents
 * @property int|null $net_cents
 * @property int|null $paid_gross_cents
 * @property int|null $paid_net_cents
 * @property int|null $due_gross_cents
 * @property int|null $due_net_cents
 * @property array<string, mixed>|null $detail
 * @property string $status pending|open|failed|settled|dismissed|superseded
 * @property Carbon $changed_from
 * @property int|null $settled_by
 * @property Carbon|null $settled_at
 * @property string|null $note
 * @property-read Employee|null $employee
 * @property-read PayrollRun|null $payrollRun
 * @property-read PayrollEntry|null $entry
 */
class PayrollPayDifference extends Model
{
    use BelongsToTenant, HasPublicId;

    public const PENDING = 'pending';

    public const OPEN = 'open';

    public const SETTLED = 'settled';

    public const DISMISSED = 'dismissed';

    public const SUPERSEDED = 'superseded';

    public const FAILED = 'failed';

    /** @var list<string> */
    public const LIVE = [self::PENDING, self::OPEN, self::FAILED];

    protected $fillable = [
        'public_id',
        'tenant_id',
        'employee_id',
        'payroll_run_id',
        'payroll_entry_id',
        'kind',
        'gross_cents',
        'net_cents',
        'paid_gross_cents',
        'paid_net_cents',
        'due_gross_cents',
        'due_net_cents',
        'detail',
        'status',
        'changed_from',
        'settled_by',
        'settled_at',
        'note',
    ];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'gross_cents' => 'integer',
            'net_cents' => 'integer',
            'paid_gross_cents' => 'integer',
            'paid_net_cents' => 'integer',
            'due_gross_cents' => 'integer',
            'due_net_cents' => 'integer',
            'detail' => 'array',
            'changed_from' => 'date',
            'settled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<PayrollRun, $this> */
    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    /** @return BelongsTo<PayrollEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class, 'payroll_entry_id');
    }
}
