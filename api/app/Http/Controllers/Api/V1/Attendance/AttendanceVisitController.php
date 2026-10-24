<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\StoreAttendanceVisitRequest;
use App\Http\Resources\AttendanceVisitResource;
use App\Models\AttendanceSite;
use App\Models\AttendanceVisit;
use App\Models\Employee;
use App\Services\Attendance\AttendancePlaces;
use App\Services\Attendance\AttendancePolicy;
use App\Services\FileStorageService;
use App\Support\PunchSignals;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Field visits: stops a field worker logs during the day. Evidence of work,
 * never a punch: they do not open or close attendance or change hours.
 */
class AttendanceVisitController extends Controller
{
    public function __construct(
        private readonly AttendancePolicy $policy,
        private readonly AttendancePlaces $places,
        private readonly FileStorageService $storage,
    ) {}

    public function store(StoreAttendanceVisitRequest $request): JsonResponse
    {
        Gate::authorize('attendance.checkIn');

        $employee = $request->user()->employee;
        if (! $employee instanceof Employee) {
            return $this->problem(404, 'Not Found', __('attendance.no_employee_linked'));
        }

        // A replay from a phone that lost the response gets the visit back.
        $existing = AttendanceVisit::query()->where('idempotency_key', $request->validated('idempotency_key'))->first();
        if ($existing !== null) {
            return (new AttendanceVisitResource($existing->load('site')))->response()->setStatusCode(200);
        }

        if (! $this->policy->for($employee)->isMethodEnabled('mobile')) {
            return $this->problem(422, 'Method Disabled', __('attendance.method_disabled'));
        }

        // A visit logged offline arrives later, dated when it happened, inside
        // the same window offline punches are held to.
        $occurred = $request->filled('occurred_at')
            ? Carbon::parse($request->validated('occurred_at'))->utc()
            : Carbon::now('UTC');
        $oldest = Carbon::now('UTC')->subDays((int) config('attendance.offline_max_age_days'));
        $latest = Carbon::now('UTC')->addMinutes((int) config('attendance.offline_future_skew_minutes'));
        if ($occurred->lt($oldest) || $occurred->gt($latest)) {
            return $this->problem(422, 'Validation Failed', __('attendance.offline_outside_window', [
                'days' => (int) config('attendance.offline_max_age_days'),
            ]));
        }

        $lat = (float) $request->validated('latitude');
        $lng = (float) $request->validated('longitude');
        $accuracy = $request->validated('accuracy');
        $check = $this->places->check($this->places->for($employee), $lat, $lng, is_numeric($accuracy) ? (float) $accuracy : null);
        $site = $check['inside'] === true && ($check['nearest']['kind'] ?? null) === 'site'
            ? $check['nearest']['public_id']
            : null;

        $visit = AttendanceVisit::create([
            'employee_id' => $employee->id,
            'attendance_site_id' => $site === null ? null : AttendanceSite::query()->where('public_id', $site)->value('id'),
            'latitude' => $lat,
            'longitude' => $lng,
            'accuracy' => is_numeric($accuracy) ? (float) $accuracy : null,
            'occurred_at' => $occurred,
            'note' => $request->validated('note'),
            'photo_path' => filled($request->validated('photo'))
                ? $this->storage->uploadDataUrlImage((string) $request->validated('photo'), 'visits')['path']
                : null,
            'idempotency_key' => $request->validated('idempotency_key'),
            'metadata' => array_filter([
                'ip_address' => $request->ip(),
                ...PunchSignals::from($request->validated(), $request->userAgent(), online: ! $request->filled('occurred_at')),
            ], fn ($v) => $v !== null),
        ]);

        return (new AttendanceVisitResource($visit->load('site')))->response()->setStatusCode(201);
    }

    /** The signed-in employee's own visits, newest first. */
    public function mine(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('attendance.checkIn');

        $employee = $request->user()->employee;

        return AttendanceVisitResource::collection(
            AttendanceVisit::query()
                ->where('employee_id', $employee instanceof Employee ? $employee->id : 0)
                ->with('site')
                ->latest('occurred_at')
                ->paginate(min(100, $request->integer('per_page', 25)))
        );
    }

    /** Visits of the people the caller may see, filtered by person and day. */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('attendance.viewAll');

        $user = $request->user();
        $query = AttendanceVisit::query()
            ->whereHas('employee', fn ($q) => $user->scopeAccessibleEmployees($q))
            ->with(['employee', 'site'])
            ->latest('occurred_at');

        if ($request->filled('employee')) {
            $query->whereHas('employee', fn ($q) => $q->where('public_id', (string) $request->input('employee')));
        }
        if ($request->filled('date')) {
            $query->whereDate('occurred_at', (string) $request->input('date'));
        }

        return AttendanceVisitResource::collection($query->paginate(min(100, $request->integer('per_page', 25))));
    }

    private function problem(int $status, string $title, string $detail): JsonResponse
    {
        return response()->json([
            'type' => 'https://ethr.et/errors/'.($status === 404 ? 'not-found' : 'validation'),
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], $status)->header('Content-Type', 'application/problem+json');
    }
}
