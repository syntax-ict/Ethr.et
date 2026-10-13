<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Models\AttendanceIntegrityFlag;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use Carbon\Carbon;

/**
 * Reads every punch for what cannot be trusted on its face, and writes each
 * finding as an AttendanceIntegrityFlag for a person to accept or reject.
 *
 * It refuses nothing itself - the hard rules (a mobile punch outside a
 * required geofence, a reused QR code, a locked kiosk PIN) are enforced where
 * the punch arrives. What it catches are the signs a refusal would get wrong:
 * a genuine punch made offline with no signal, or a cheat that looks valid.
 * A person decides, with the evidence in `details`, and rejecting voids it.
 *
 * Each check is a plain rule over data the punch already carries, so a flag
 * can always say exactly why it was raised.
 */
final class IntegrityInspector
{
    /** A punch made this long after the previous one, this far away, could not have been made in person. */
    private const MAX_TRAVEL_KMH = 120;

    /** Below this distance GPS noise, not travel, explains the gap. */
    private const MIN_TRAVEL_METERS = 2000;

    /** How many earlier punches are compared for a coordinate repeated exactly. */
    private const REPEAT_LOOKBACK = 30;

    /** Self-service sources: the person's own phone or browser says where they were. */
    private const SELF_SERVICE = ['mobile', 'offline_mobile', 'web', 'qr'];

    public function __construct(private readonly ConfidenceScorer $scorer) {}

    /**
     * @return list<AttendanceIntegrityFlag>
     */
    public function inspect(
        AttendanceRecord $record,
        AttendanceInput $input,
        Employee $employee,
        ?AttendanceSetting $settings,
        string $punch,
        Carbon $moment,
    ): array {
        if ($record->status === AttendanceStatus::VOIDED) {
            return [];
        }

        $findings = [];
        $source = $input->source->value;
        $lat = $input->latitude;
        $lon = $input->longitude;
        $branch = $employee->branch;

        // Below the organisation's own confidence threshold.
        $threshold = $settings->confidence_threshold ?? 70;
        if ($punch === 'check_in' && $record->confidence_score < $threshold) {
            $findings[] = ['low_confidence', 'low', [
                'confidence' => $record->confidence_score,
                'threshold' => $threshold,
            ]];
        }

        // Where the person was, for sources that report it.
        if ($lat !== null && $lon !== null && $branch !== null
            && $branch->latitude !== null && $branch->longitude !== null && (int) $branch->geofence_radius_meters > 0) {
            $distance = $this->scorer->distanceMeters($lat, $lon, (float) $branch->latitude, (float) $branch->longitude);
            if ($distance > $branch->geofence_radius_meters) {
                $findings[] = ['outside_geofence', in_array($source, ['offline_mobile', 'web'], true) ? 'high' : 'medium', [
                    'distance_meters' => (int) round($distance),
                    'radius_meters' => $branch->geofence_radius_meters,
                ]];
            }
        } elseif (($settings->geofence_required ?? false) && in_array($source, ['offline_mobile', 'web'], true) && ($lat === null || $lon === null)) {
            $findings[] = ['location_missing', 'medium', ['source' => $source]];
        }

        if ($lat !== null && $lon !== null && in_array($source, self::SELF_SERVICE, true)) {
            $accuracy = $input->metadata['accuracy'] ?? null;
            $threshold = $settings->mobile_accuracy_threshold_meters ?? 100;
            if (is_numeric($accuracy) && (float) $accuracy <= 0.0) {
                // A real receiver never reports perfect accuracy; location
                // simulators commonly do.
                $findings[] = ['suspect_location', 'high', ['reason' => 'zero_accuracy']];
            } elseif (is_numeric($accuracy) && (float) $accuracy > $threshold) {
                $findings[] = ['low_accuracy', 'low', ['accuracy_meters' => (int) $accuracy, 'threshold_meters' => $threshold]];
            }

            if ($this->repeatsExactCoordinates($record, $lat, $lon)) {
                // GPS jitters in the last decimals from one day to the next;
                // the same seven decimals on another day is a fixed, fed-in
                // location.
                $findings[] = ['suspect_location', 'high', ['reason' => 'identical_coordinates']];
            }

            $travel = $this->impossibleTravel($record, $lat, $lon, $moment);
            if ($travel !== null) {
                $findings[] = ['impossible_travel', 'high', $travel];
            }
        }

        // A kiosk or QR code that belongs to another branch.
        $placeBranch = $input->metadata['kiosk_branch_id'] ?? $input->metadata['qr_branch_id'] ?? null;
        if ($placeBranch !== null && $employee->branch_id !== null && (int) $placeBranch !== $employee->branch_id) {
            $findings[] = ['branch_mismatch', 'medium', ['via' => isset($input->metadata['kiosk_branch_id']) ? 'kiosk' : 'qr']];
        }

        // Offline - a phone, or a kiosk through a power cut: the device's
        // clock is the only witness, bounded by its grant.
        $kioskOffline = ($input->metadata['kiosk_offline'] ?? false) === true;
        if ($input->source === AttendanceSource::OFFLINE_MOBILE || $kioskOffline) {
            $grant = $input->metadata['offline_grant'] ?? null;
            if ($grant === 'invalid' || $grant === 'missing') {
                $findings[] = ['unverified_offline', 'high', ['grant' => $grant]];
            } elseif ($grant === 'clock_outside_window') {
                $findings[] = ['clock_suspect', 'high', ['reason' => 'outside_offline_window']];
            }

            $hoursLate = $moment->diffInHours(Carbon::now('UTC'), absolute: true);
            if ($hoursLate >= 24) {
                $findings[] = ['late_sync', 'low', ['hours' => (int) $hoursLate]];
            }

            if (! $kioskOffline && ($settings->mobile_photo_required ?? false) && $input->photoPath === null) {
                $findings[] = ['photo_missing', 'medium', []];
            }
        }

        $flags = [];
        foreach ($findings as [$type, $severity, $details]) {
            $flags[] = AttendanceIntegrityFlag::query()->firstOrCreate(
                ['attendance_record_id' => $record->id, 'punch' => $punch, 'type' => $type],
                [
                    'tenant_id' => $record->tenant_id,
                    'employee_id' => $record->employee_id,
                    'severity' => $severity,
                    'details' => ['source' => $source, ...$details],
                    'status' => 'open',
                ],
            );
        }

        return $flags;
    }

    private function repeatsExactCoordinates(AttendanceRecord $record, float $lat, float $lon): bool
    {
        return AttendanceRecord::query()
            ->where('employee_id', $record->employee_id)
            ->where('id', '!=', $record->id)
            ->whereDate('date', '!=', $record->date->format('Y-m-d'))
            ->whereNotNull('latitude')
            ->orderByDesc('id')
            ->limit(self::REPEAT_LOOKBACK)
            ->get(['latitude', 'longitude'])
            ->contains(fn (AttendanceRecord $r): bool => round((float) $r->latitude, 7) === round($lat, 7)
                && round((float) $r->longitude, 7) === round($lon, 7));
    }

    /**
     * @return array{distance_km: float, minutes: int, speed_kmh: int}|null
     */
    private function impossibleTravel(AttendanceRecord $record, float $lat, float $lon, Carbon $moment): ?array
    {
        $previous = AttendanceRecord::query()
            ->where('employee_id', $record->employee_id)
            ->where('id', '!=', $record->id)
            ->whereNotNull('latitude')
            ->whereNotNull('check_in')
            ->where('check_in', '>=', $moment->copy()->subHours(12))
            ->where('check_in', '<=', $moment)
            ->where('status', '!=', AttendanceStatus::VOIDED)
            ->orderByDesc('check_in')
            ->first();

        if ($previous === null) {
            return null;
        }

        $meters = $this->scorer->distanceMeters($lat, $lon, (float) $previous->latitude, (float) $previous->longitude);
        if ($meters < self::MIN_TRAVEL_METERS) {
            return null;
        }

        $minutes = max(1, $previous->check_in->diffInMinutes($moment, absolute: true));
        $kmh = ($meters / 1000) / ($minutes / 60);

        return $kmh > self::MAX_TRAVEL_KMH
            ? ['distance_km' => round($meters / 1000, 1), 'minutes' => (int) $minutes, 'speed_kmh' => (int) round($kmh)]
            : null;
    }
}
