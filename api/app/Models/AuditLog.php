<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Auth\ImpersonationToken;
use App\Services\CurrentTenant;
use App\Traits\BelongsToTenant;
use App\Traits\NeverDelete;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Request;

class AuditLog extends Model
{
    use BelongsToTenant, NeverDelete;

    public $timestamps = false;

    protected $table = 'audit_log';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'payload',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function update(array $attributes = [], array $options = []): never
    {
        throw new \LogicException('Audit log entries cannot be updated.');
    }

    public static function record(string $action, ?Model $auditable = null, array $payload = []): self
    {
        $tenant = app(CurrentTenant::class);
        $user = auth()->user();

        // The `?? null` is load-bearing: under session-cookie auth
        // currentAccessToken() is a TransientToken, which has no `name` at all,
        // and only the null-coalescing read suppresses the undefined-property
        // warning that Laravel would otherwise promote to an ErrorException.
        $tokenName = $user?->currentAccessToken()->name ?? null;

        $impersonatorId = ImpersonationToken::impersonatorId($tokenName);
        if ($impersonatorId !== null) {
            $payload['impersonated_by'] = $impersonatorId;
        }

        $tenantId = $tenant->id();

        // A queue worker resolves no tenant, and `audit_log.tenant_id` is
        // nullable — so the insert succeeded and the row was written with no
        // owner. Not a missing entry: an entry the tenant cannot see, because
        // their audit view scopes by `tenant_id` and NULL never matches, while
        // the platform view shows it unattributed. `ProcessPayrollJob` records
        // `payroll.processed` and `payroll.failed` that way, which are close to
        // the most audit-relevant events this product has.
        //
        // The auditable is already here and already carries the owner, so this
        // corrects all 206 call sites without touching one of them. A resolved
        // tenant still wins, and an action with no auditable stays unowned
        // rather than having an owner invented for it.
        if ($tenantId === null && $auditable !== null) {
            $ownerId = $auditable->getAttribute('tenant_id');
            $tenantId = is_int($ownerId) ? $ownerId : null;
        }

        return self::create([
            'tenant_id' => $tenantId,
            'user_id' => $user?->id,
            'action' => $action,
            'auditable_type' => $auditable ? get_class($auditable) : null,
            'auditable_id' => $auditable?->id,
            'payload' => $payload ?: null,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'created_at' => now(),
        ]);
    }
}
