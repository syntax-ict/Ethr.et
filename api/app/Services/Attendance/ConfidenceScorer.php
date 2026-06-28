<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceSource;
use App\Models\Branch;

final class ConfidenceScorer
{
    public function calculate(AttendanceInput $input, ?bool $geofenceVerified = null): int
    {
        $score = $input->source->baseConfidence();

        if ($input->source === AttendanceSource::MOBILE) {
            $score = $this->adjustMobileScore($input, $geofenceVerified);
        }

        if ($input->source === AttendanceSource::BIOMETRIC && $input->offlineToken !== null) {
            $score = 95;
        }

        return min(100, max(0, $score));
    }

    private function adjustMobileScore(AttendanceInput $input, ?bool $geofenceVerified): int
    {
        if ($input->photoPath !== null) {
            return 95;
        }

        if ($geofenceVerified === true) {
            return 90;
        }

        if ($input->latitude !== null && $input->longitude !== null) {
            return 88;
        }

        return 75;
    }

    public function verifyGeofence(float $lat, float $lon, Branch $branch): bool
    {
        if ($branch->latitude === null || $branch->longitude === null || $branch->geofence_radius_meters === null) {
            return false;
        }

        $distance = $this->haversineDistance(
            $lat,
            $lon,
            (float) $branch->latitude,
            (float) $branch->longitude,
        );

        return $distance <= $branch->geofence_radius_meters;
    }

    private function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
