<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An organisation's export of its own data (ExportTenantDataJob). `status`
 * moves queued, running, then ready or failed; a ready archive becomes
 * `expired` and is deleted after EXPIRES_DAYS.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int|null $requested_by
 * @property string $status
 * @property string|null $path
 * @property int|null $size_bytes
 * @property int|null $file_count
 * @property string|null $error
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 */
class TenantExport extends Model
{
    use BelongsToTenant, HasPublicId;

    public const EXPIRES_DAYS = 7;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    protected $fillable = [
        'public_id',
        'tenant_id',
        'requested_by',
        'status',
        'path',
        'size_bytes',
        'file_count',
        'error',
        'expires_at',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'requested_by',
        'path',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'file_count' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'public_id' => $this->public_id,
            'status' => $this->status,
            'size_bytes' => $this->size_bytes,
            'file_count' => $this->file_count,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
