<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceSource;
use App\Models\Employee;
use App\Services\Attendance\Device\AttendanceDeviceGate;
use App\Services\Attendance\Device\DeviceVerdict;
use Illuminate\Http\Request;

/**
 * A phone proving it is the employee's own and where it is, without a punch:
 * the step a field visit and a presence check have in common. Kept in one
 * place so the two cannot drift apart on what "from the right phone" or
 * "at the workplace" means.
 */
final class PlaceProof
{
    public function __construct(
        private readonly AttendanceDeviceGate $devices,
        private readonly AttendancePlaces $places,
    ) {}

    /**
     * The device verdict for the request, then where the position falls
     * against the employee's places.
     *
     * A live request must come from the employee's registered phone under
     * `enforce` (a refusal throws DeviceBindingRefused, which renders as a
     * problem response). One that was captured earlier ($live false) cannot
     * answer a fresh challenge, so it is kept with its verdict for review.
     *
     * @param  array<string, mixed>|null  $proof
     * @return array{verdict: DeviceVerdict, check: array{inside: bool|null, nearest: array{kind: string, public_id: string|null, name: string, latitude: float, longitude: float, radius_meters: int}|null, distance_meters: float|null}}
     */
    public function take(Employee $employee, Request $request, ?array $proof, bool $live, float $lat, float $lng, ?float $accuracy): array
    {
        $verdict = $live
            ? $this->devices->enforce($employee, $request, $proof, AttendanceSource::MOBILE)
            : $this->devices->verdict($employee, $request, $proof, AttendanceSource::MOBILE);

        return [
            'verdict' => $verdict,
            'check' => $this->places->check($this->places->for($employee), $lat, $lng, $accuracy),
        ];
    }
}
