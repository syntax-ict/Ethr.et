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

    /**
     * Whether the point is inside the branch's circle, or null when the branch
     * has no circle to be inside. Null is not "outside": a branch nobody has
     * placed yet cannot be checked, and treating it as outside refused every
     * phone punch there once a geofence was required.
     */
    public function verifyGeofence(float $lat, float $lon, Branch $branch): ?bool
    {
        if ($branch->latitude === null || $branch->longitude === null || (int) $branch->geofence_radius_meters <= 0) {
            return null;
        }

        $distance = $this->distanceMeters(
            $lat,
            $lon,
            (float) $branch->latitude,
            (float) $branch->longitude,
        );

        return $distance <= $branch->geofence_radius_meters;
    }

    /** Great-circle distance in metres. */
    public function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
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
