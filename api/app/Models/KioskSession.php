<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

class KioskSession extends Model
{
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'branch_id',
        'name',
        'token',
        'admin_pin',
        'device_identifier',
        'status',
        'last_activity_at',
        'activated_at',
        'deactivated_at',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'branch_id',
        'token',
        'admin_pin',
    ];

    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function verifyAdminPin(string $pin): bool
    {
        return Hash::check($pin, $this->admin_pin);
    }

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function touchActivity(): void
    {
        $this->update(['last_activity_at' => now()]);
    }
}
