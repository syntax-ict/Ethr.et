<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttendanceSource;
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
        'grace_period_minutes',
        'ot_daily_cap_minutes',
        'confidence_threshold',
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
            'grace_period_minutes' => 'integer',
            'ot_daily_cap_minutes' => 'integer',
            'confidence_threshold' => 'integer',
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
            'grace_period_minutes' => 15,
            'ot_daily_cap_minutes' => 120,
            'confidence_threshold' => 70,
        ];
    }

    public function isMethodEnabled(string $method): bool
    {
        // A punch a phone captured offline is a mobile punch that arrived late,
        // not a method an organisation picks: it is allowed when mobile is and
        // offline sync is switched on. It used to be looked up in
        // `enabled_methods`, where it never is — the defaults omit it and the
        // settings request refuses it — so every offline punch was rejected at
        // sync, `offline_sync_enabled: true` or not (attendance QA A2).
        if ($method === AttendanceSource::OFFLINE_MOBILE->value) {
            return $this->offline_sync_enabled
                && $this->isMethodEnabled(AttendanceSource::MOBILE->value);
        }

        return in_array($method, $this->enabled_methods ?? [], true);
    }
}
