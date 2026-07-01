<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class AttendanceSetting extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'enabled_methods',
        'geofence_required',
        'mobile_photo_required',
        'kiosk_pin_required',
        'qr_expiry_minutes',
        'qr_auto_refresh',
        'qr_single_use_limit',
        'mobile_accuracy_threshold_meters',
        'offline_sync_enabled',
        'kiosk_auto_reset_seconds',
    ];

    protected function casts(): array
    {
        return [
            'enabled_methods' => 'array',
            'geofence_required' => 'boolean',
            'mobile_photo_required' => 'boolean',
            'kiosk_pin_required' => 'boolean',
            'qr_expiry_minutes' => 'integer',
            'qr_auto_refresh' => 'boolean',
            'qr_single_use_limit' => 'integer',
            'mobile_accuracy_threshold_meters' => 'integer',
            'offline_sync_enabled' => 'boolean',
            'kiosk_auto_reset_seconds' => 'integer',
        ];
    }

    public static function defaults(): array
    {
        return [
            'enabled_methods' => ['biometric', 'mobile', 'qr', 'kiosk', 'web', 'manual', 'csv'],
            'geofence_required' => false,
            'mobile_photo_required' => false,
            'kiosk_pin_required' => false,
            'qr_expiry_minutes' => 30,
            'qr_auto_refresh' => true,
            'qr_single_use_limit' => 0,
            'mobile_accuracy_threshold_meters' => 100,
            'offline_sync_enabled' => true,
            'kiosk_auto_reset_seconds' => 4,
        ];
    }

    public function isMethodEnabled(string $method): bool
    {
        return in_array($method, $this->enabled_methods ?? [], true);
    }
}
