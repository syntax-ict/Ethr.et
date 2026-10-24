<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\AttendanceSite;
use App\Models\Department;
use App\Models\Employee;

/**
 * Where a person may punch: their branch's circle and every active work site
 * open to them (at their branch, or assigned to them or their department).
 *
 * The one answer to "is this punch inside?", used by the engine, the
 * integrity checks, automatic check-in and the punch assistant, so the phone
 * and the server agree about which places count.
 */
final class AttendancePlaces
{
    /**
     * How much of a fix's reported accuracy is given the benefit of the
     * doubt. A phone saying "here, give or take 30 m" just outside a 100 m
     * circle may well be inside it; past 50 m the fix itself is the problem,
     * and IntegrityInspector flags it as weak instead.
     */
    public const MAX_ACCURACY_SLACK_METERS = 50;

    public function __construct(private readonly ConfidenceScorer $scorer) {}

    /**
     * @return list<array{kind: string, public_id: string|null, name: string, latitude: float, longitude: float, radius_meters: int}>
     */
    public function for(Employee $employee): array
    {
        $places = [];

        $branch = $employee->branch;
        if ($branch !== null && $branch->latitude !== null && $branch->longitude !== null && (int) $branch->geofence_radius_meters > 0) {
            $places[] = [
                'kind' => 'branch',
                'public_id' => $branch->public_id,
                'name' => $branch->name,
                'latitude' => (float) $branch->latitude,
                'longitude' => (float) $branch->longitude,
                'radius_meters' => (int) $branch->geofence_radius_meters,
            ];
        }

        $sites = AttendanceSite::query()
            ->where('is_active', true)
            ->where(function ($query) use ($employee): void {
                if ($employee->branch_id !== null) {
                    $query->orWhere('branch_id', $employee->branch_id);
                }
                $query->orWhereHas('assignments', function ($q) use ($employee): void {
                    $q->where(function ($owner) use ($employee): void {
                        $owner->where(fn ($e) => $e->where('assignable_type', Employee::class)->where('assignable_id', $employee->id));
                        if ($employee->department_id !== null) {
                            $owner->orWhere(fn ($d) => $d->where('assignable_type', Department::class)->where('assignable_id', $employee->department_id));
                        }
                    });
                });
            })
            ->orderBy('name')
            ->get();

        foreach ($sites as $site) {
            $places[] = [
                'kind' => 'site',
                'public_id' => $site->public_id,
                'name' => $site->name,
                'latitude' => (float) $site->latitude,
                'longitude' => (float) $site->longitude,
                'radius_meters' => (int) $site->radius_meters,
            ];
        }

        return $places;
    }

    /**
     * Whether a fix is inside any of the places, and the nearest one.
     * `inside` is null when there are no places to be inside.
     *
     * @param  list<array{kind: string, public_id: string|null, name: string, latitude: float, longitude: float, radius_meters: int}>  $places
     * @return array{inside: bool|null, nearest: array{kind: string, public_id: string|null, name: string, latitude: float, longitude: float, radius_meters: int}|null, distance_meters: float|null}
     */
    public function check(array $places, float $lat, float $lng, ?float $accuracy = null): array
    {
        if ($places === []) {
            return ['inside' => null, 'nearest' => null, 'distance_meters' => null];
        }

        $slack = min(max((float) ($accuracy ?? 0), 0.0), (float) self::MAX_ACCURACY_SLACK_METERS);
        $inside = false;
        $nearest = $places[0];
        $distance = null;
        $best = null;

        foreach ($places as $place) {
            $d = $this->scorer->distanceMeters($lat, $lng, $place['latitude'], $place['longitude']);
            // Nearest by distance past the edge, so a big site you are in
            // beats a small one whose centre happens to be closer.
            $pastEdge = $d - $place['radius_meters'];

            if ($d - $slack <= $place['radius_meters']) {
                $inside = true;
            }
            if ($best === null || $pastEdge < $best) {
                $best = $pastEdge;
                $nearest = $place;
                $distance = $d;
            }
        }

        return ['inside' => $inside, 'nearest' => $nearest, 'distance_meters' => $distance];
    }
}
