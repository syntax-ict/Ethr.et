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

        return self::create([
            'tenant_id' => $tenant->id(),
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
