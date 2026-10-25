<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AttendanceIntegrityFlag;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AttendanceHighRiskNotification;
use App\Services\FileStorageService;
use App\Support\ImageHash;
use App\Support\TenantTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How likely a punch is to be something other than the person, at the place,
 * at the time it says: a score from 0 to 100 with every point traced to a
 * named reason, so a reviewer (and an employee who disputes it) can see why.
 *
 * Explainable scoring, not a model: each signal has a fixed weight (WEIGHTS,
 * pinned by a test), and the comparison is against the organisation's own
 * records and, for place and time, the person's own habits. Nothing leaves
 * the server and nothing is learned that cannot be read back.
 *
 * It never refuses a punch (owner decision, 2026-10-24). At or above the
 * review threshold (organisation or branch) the punch is flagged
 * `high_risk` for the integrity queue, and payroll lists it before approval.
 */
final class AttendanceRiskScorer
{
    /** Points per reason. Changing one changes how punches are judged: the test pins them. */
    public const WEIGHTS = [
        // The integrity checks' own findings.
        'outside_geofence' => 20,
        'location_missing' => 10,
        'suspect_location' => 35,
        'low_accuracy' => 5,
        'impossible_travel' => 40,
        'branch_mismatch' => 15,
        'unverified_offline' => 30,
        'clock_suspect' => 30,
        'late_sync' => 5,
        'photo_missing' => 15,
        'device_clock_ahead' => 10,
        'low_confidence' => 10,
        // Signals read here.
        'shared_device' => 35,
        'ip_burst' => 20,
        'stale_fix' => 15,
        'automation' => 40,
        'missing_fix_details' => 15,
        'clock_skew' => 15,
        'photo_replayed' => 40,
        'photo_of_someone_else' => 50,
        'unusual_place' => 15,
        'unusual_time' => 10,
        'device_switch' => 10,
        'no_face' => 20,
        'liveness_failed' => 25,
        'device_unverified' => 35,
        'device_install_changed' => 15,
        'device_unverified_office_pc' => 5,
    ];

    /** A fix older than this was not taken for this punch. */
    private const STALE_FIX_MS = 120_000;

    private const CLOCK_SKEW_SECONDS = 300;

    private const SHARED_DEVICE_DAYS = 14;

    private const IP_BURST_SECONDS = 120;

    private const UNUSUAL_PLACE_METERS = 3000;

    /** Below this many past punches a person has no habits to compare against. */
    private const MIN_HISTORY = 10;

    public function __construct(
        private readonly ConfidenceScorer $geo,
        private readonly FileStorageService $storage,
    ) {}

    /**
     * Score a punch, store the score and its reasons on the record, and flag
     * it for review when it reaches the threshold.
     *
     * @param  list<AttendanceIntegrityFlag>  $flags  what IntegrityInspector found for this punch
     */
    public function score(
        AttendanceRecord $record,
        AttendanceInput $input,
        Employee $employee,
        AttendanceSetting $policy,
        string $punch,
        array $flags,
    ): int {
        if ($record->status === AttendanceStatus::VOIDED) {
            return 0;
        }

        $meta = $input->metadata;
        $reasons = [];

        foreach ($flags as $flag) {
            if (isset(self::WEIGHTS[$flag->type])) {
                $reasons[$flag->type] = self::WEIGHTS[$flag->type];
            }
        }

        $selfService = in_array($input->source, [AttendanceSource::MOBILE, AttendanceSource::OFFLINE_MOBILE, AttendanceSource::WEB, AttendanceSource::QR], true);
        $device = is_string($meta['device'] ?? null) ? $meta['device'] : null;

        if ($selfService && $device !== null && $this->deviceUsedByOthers($record, $device)) {
            $reasons['shared_device'] = self::WEIGHTS['shared_device'];
        }

        if ($selfService && $input->ipAddress !== null && $this->ipBurst($record, $employee, $input->ipAddress)) {
            $reasons['ip_burst'] = self::WEIGHTS['ip_burst'];
        }

        if (is_int($meta['fix_age_ms'] ?? null) && $meta['fix_age_ms'] > self::STALE_FIX_MS) {
            $reasons['stale_fix'] = self::WEIGHTS['stale_fix'];
        }

        if (($meta['webdriver'] ?? false) === true) {
            $reasons['automation'] = self::WEIGHTS['automation'];
        }

        if ($input->latitude !== null && $selfService && $this->fixDetailsVanished($record, $meta)) {
            $reasons['missing_fix_details'] = self::WEIGHTS['missing_fix_details'];
        }

        if (is_int($meta['client_offset_seconds'] ?? null) && abs($meta['client_offset_seconds']) > self::CLOCK_SKEW_SECONDS) {
            $reasons['clock_skew'] = self::WEIGHTS['clock_skew'];
        }

        if ($input->photoPath !== null) {
            $reasons += $this->photoReuse($record, $employee, $input->photoPath, $punch);
        }

        if ($punch === 'check_in' && $input->latitude !== null && $input->longitude !== null && $this->unusualPlace($record, $input->latitude, $input->longitude)) {
            $reasons['unusual_place'] = self::WEIGHTS['unusual_place'];
        }

        if ($punch === 'check_in' && $this->unusualTime($record)) {
            $reasons['unusual_time'] = self::WEIGHTS['unusual_time'];
        }

        if ($punch === 'check_out' && $device !== null && $record->device_hash !== null && $record->device_hash !== $device) {
            $reasons['device_switch'] = self::WEIGHTS['device_switch'];
        }

        // The device gate's verdict (AttendanceDeviceGate): a punch from a phone
        // that is not the employee's registered device, kept under `flag`.
        $verdict = $meta['device_verdict'] ?? null;
        if (in_array($verdict, ['unregistered', 'pending', 'foreign', 'invalid', 'wrong_host', 'exempt'], true)) {
            $reasons['device_unverified'] = self::WEIGHTS['device_unverified'];
        } elseif ($verdict === 'office_pc') {
            $reasons['device_unverified_office_pc'] = self::WEIGHTS['device_unverified_office_pc'];
        }
        if (($meta['device_install_changed'] ?? false) === true) {
            $reasons['device_install_changed'] = self::WEIGHTS['device_install_changed'];
        }

        $liveness = $meta['liveness'] ?? null;
        if ($liveness === 'no_face') {
            $reasons['no_face'] = self::WEIGHTS['no_face'];
        } elseif ($liveness === 'failed') {
            $reasons['liveness_failed'] = self::WEIGHTS['liveness_failed'];
        }

        // Check-in and check-out are one day: its score is the worse of the
        // two and its reasons both punches' together.
        $previous = (array) ($record->risk_reasons ?? []);
        $merged = $previous + array_map(fn (int $points): int => $points, $reasons);
        $score = min(100, array_sum($merged));

        $record->forceFill([
            'risk_score' => $score,
            'risk_reasons' => $merged === [] ? null : $merged,
            'device_hash' => $record->device_hash ?? $device,
        ])->save();

        $threshold = (int) ($policy->risk_review_threshold ?? 40);
        if ($score >= $threshold && $reasons !== []) {
            $flag = AttendanceIntegrityFlag::query()->updateOrCreate(
                ['attendance_record_id' => $record->id, 'punch' => 'day', 'type' => 'high_risk'],
                [
                    'tenant_id' => $record->tenant_id,
                    'employee_id' => $record->employee_id,
                    'severity' => $score >= 70 ? 'high' : 'medium',
                    'details' => ['score' => $score, 'threshold' => $threshold, 'reasons' => array_keys($merged)],
                    'status' => 'open',
                ],
            );

            // Reviewers hear of a serious one as it opens, not when they next
            // look at the queue. Once per punch-day.
            if ($flag->wasRecentlyCreated && $score >= 70) {
                User::query()
                    ->where('tenant_id', $record->tenant_id)
                    ->whereIn('role', [UserRole::TENANT_ADMIN->value, UserRole::HR_ADMIN->value])
                    ->where('status', 'active')
                    ->get()
                    ->each(fn (User $reviewer) => $reviewer->notify(new AttendanceHighRiskNotification($flag, $employee->name, $score)));
            }
        }

        return $score;
    }

    /** This install punched for someone else in the last fortnight. */
    private function deviceUsedByOthers(AttendanceRecord $record, string $device): bool
    {
        return AttendanceRecord::query()
            ->where('device_hash', $device)
            ->where('employee_id', '!=', $record->employee_id)
            ->where('created_at', '>=', Carbon::now()->subDays(self::SHARED_DEVICE_DAYS))
            ->where('status', '!=', AttendanceStatus::VOIDED)
            ->exists();
    }

    /**
     * Someone else punched from this IP within two minutes, and the IP is not
     * an office network the branch is known to use: IPs seen on punches
     * verified inside the branch area are learned as the office's, with no
     * one listing them.
     */
    private function ipBurst(AttendanceRecord $record, Employee $employee, string $ip): bool
    {
        $around = $record->check_out ?? $record->check_in ?? Carbon::now();
        $others = AttendanceRecord::query()
            ->where('employee_id', '!=', $record->employee_id)
            ->where('metadata->ip_address', $ip)
            ->whereBetween('check_in', [$around->copy()->subSeconds(self::IP_BURST_SECONDS), $around->copy()->addSeconds(self::IP_BURST_SECONDS)])
            ->exists();

        if (! $others) {
            return false;
        }

        $officeUses = AttendanceRecord::query()
            ->whereHas('employee', fn ($q) => $q->where('branch_id', $employee->branch_id))
            ->where('metadata->ip_address', $ip)
            ->where('geofence_verified', true)
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->count();

        return $officeUses < 3;
    }

    /**
     * A real receiver reports altitude, speed or heading. A phone that did on
     * this person's earlier punches and reports none of them now has most
     * likely had its location fed in.
     *
     * @param  array<string, mixed>  $meta
     */
    private function fixDetailsVanished(AttendanceRecord $record, array $meta): bool
    {
        $hasDetails = isset($meta['altitude']) || isset($meta['speed']) || isset($meta['heading']);
        if ($hasDetails || ! isset($meta['device'])) {
            return false;
        }

        return AttendanceRecord::query()
            ->where('employee_id', $record->employee_id)
            ->where('id', '!=', $record->id)
            ->where('device_hash', $meta['device'])
            ->whereNotNull('metadata->altitude')
            ->exists();
    }

    /**
     * The selfie is a picture already seen: the person's own earlier one
     * (replayed), or someone else's (a photo of a photo).
     *
     * @return array<string, int>
     */
    private function photoReuse(AttendanceRecord $record, Employee $employee, string $path, string $punch): array
    {
        $bytes = $this->storage->read($path);
        $hash = $bytes === null ? null : ImageHash::dHash($bytes);
        if ($hash === null) {
            return [];
        }

        $record->forceFill(['metadata' => array_merge((array) ($record->metadata ?? []), [
            $punch === 'check_out' ? 'checkout_photo_hash' : 'photo_hash' => $hash,
        ])])->save();

        /** @var Collection<int, AttendanceRecord> $recent */
        $recent = AttendanceRecord::query()
            ->where('id', '!=', $record->id)
            ->whereNotNull('metadata->photo_hash')
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->latest('id')
            ->limit(500)
            ->get(['id', 'employee_id', 'metadata']);

        foreach ($recent as $other) {
            $otherHash = (string) data_get($other->metadata, 'photo_hash', '');
            if ($otherHash === '' || ImageHash::distance($hash, $otherHash) > ImageHash::SAME_PICTURE_BITS) {
                continue;
            }

            return $other->employee_id === $employee->id
                ? ['photo_replayed' => self::WEIGHTS['photo_replayed']]
                : ['photo_of_someone_else' => self::WEIGHTS['photo_of_someone_else']];
        }

        return [];
    }

    /** More than 3 km from where this person usually punches in. */
    private function unusualPlace(AttendanceRecord $record, float $lat, float $lng): bool
    {
        $history = AttendanceRecord::query()
            ->where('employee_id', $record->employee_id)
            ->where('id', '!=', $record->id)
            ->whereNotNull('latitude')
            ->where('status', '!=', AttendanceStatus::VOIDED)
            ->latest('check_in')
            ->limit(30)
            ->get(['latitude', 'longitude']);

        if ($history->count() < self::MIN_HISTORY) {
            return false;
        }

        $medianLat = $this->median($history->pluck('latitude')->map(fn ($v): float => (float) $v)->all());
        $medianLng = $this->median($history->pluck('longitude')->map(fn ($v): float => (float) $v)->all());

        return $this->geo->distanceMeters($lat, $lng, $medianLat, $medianLng) > self::UNUSUAL_PLACE_METERS;
    }

    /** An arrival far outside this person's own pattern (more than three deviations). */
    private function unusualTime(AttendanceRecord $record): bool
    {
        if ($record->check_in === null) {
            return false;
        }

        $zone = TenantTime::zone(Tenant::query()->find($record->tenant_id));
        $minutes = AttendanceRecord::query()
            ->where('employee_id', $record->employee_id)
            ->where('id', '!=', $record->id)
            ->whereNotNull('check_in')
            ->where('status', '!=', AttendanceStatus::VOIDED)
            ->latest('check_in')
            ->limit(30)
            ->pluck('check_in')
            ->map(fn ($t): int => (int) Carbon::parse($t)->setTimezone($zone)->format('G') * 60 + (int) Carbon::parse($t)->setTimezone($zone)->format('i'))
            ->all();

        if (count($minutes) < self::MIN_HISTORY) {
            return false;
        }

        $mean = array_sum($minutes) / count($minutes);
        $variance = array_sum(array_map(fn (int $m): float => ($m - $mean) ** 2, $minutes)) / count($minutes);
        $deviation = max(sqrt($variance), 15.0);
        $now = $record->check_in->copy()->setTimezone($zone);
        $minute = (int) $now->format('G') * 60 + (int) $now->format('i');

        return abs($minute - $mean) > 3 * $deviation;
    }

    /** @param  list<float>  $values */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
