<?php

declare(strict_types=1);

namespace App\Enums;

enum AttendanceSource: string
{
    case BIOMETRIC = 'biometric';
    case MOBILE = 'mobile';
    case WEB = 'web';
    case MANUAL = 'manual';
    case CSV = 'csv';
    case KIOSK = 'kiosk';
    case QR = 'qr';
    case OFFLINE_MOBILE = 'offline_mobile';

    public function baseConfidence(): int
    {
        return match ($this) {
            self::BIOMETRIC => 100,
            self::MOBILE => 90,
            self::QR => 85,
            self::KIOSK => 80,
            self::WEB => 75,
            self::MANUAL => 60,
            self::CSV => 50,
            self::OFFLINE_MOBILE => 88,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::BIOMETRIC => 'Biometric Device',
            self::MOBILE => 'Mobile App',
            self::WEB => 'Web Portal',
            self::MANUAL => 'Manual Entry',
            self::CSV => 'CSV Import',
            self::KIOSK => 'Kiosk',
            self::QR => 'QR Code',
            self::OFFLINE_MOBILE => 'Offline Mobile',
        };
    }
}
