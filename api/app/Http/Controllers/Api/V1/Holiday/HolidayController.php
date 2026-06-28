<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Holiday;

use App\Http\Controllers\Controller;
use App\Http\Requests\Holiday\StoreHolidayRequest;
use App\Http\Requests\Holiday\UpdateHolidayRequest;
use App\Http\Resources\HolidayResource;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Holiday;
use App\Services\CurrentTenant;
use App\Services\Holiday\HolidayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class HolidayController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('holiday.viewAny');

        $query = Holiday::query()->with('branch');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('name_am', 'like', "%{$search}%");
            });
        }

        if ($request->has('filter.year')) {
            $year = $request->input('filter.year');
            $query->whereYear('date', $year);
        }

        if ($request->has('filter.is_active')) {
            $query->where('is_active', $request->boolean('filter.is_active'));
        }

        $query->orderBy('date');

        return HolidayResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function store(StoreHolidayRequest $request): JsonResponse
    {
        Gate::authorize('holiday.create');

        $data = $request->validated();

        if (isset($data['branch_public_id'])) {
            $branch = Branch::where('public_id', $data['branch_public_id'])->first();
            $data['branch_id'] = $branch?->id;
            unset($data['branch_public_id']);
        }

        $holiday = Holiday::create($data);

        AuditLog::record('holiday.created', $holiday);

        $holiday->load('branch');

        return (new HolidayResource($holiday))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Holiday $holiday): HolidayResource
    {
        Gate::authorize('holiday.view');

        $holiday->load('branch');

        return new HolidayResource($holiday);
    }

    public function update(UpdateHolidayRequest $request, Holiday $holiday): HolidayResource
    {
        Gate::authorize('holiday.update');

        $data = $request->validated();

        if (isset($data['branch_public_id'])) {
            $branch = Branch::where('public_id', $data['branch_public_id'])->first();
            $data['branch_id'] = $branch?->id;
            unset($data['branch_public_id']);
        }

        $holiday->update($data);

        AuditLog::record('holiday.updated', $holiday);

        $holiday->load('branch');

        return new HolidayResource($holiday);
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        Gate::authorize('holiday.delete');

        AuditLog::record('holiday.deleted', $holiday);
        $holiday->delete();

        return response()->json(null, 204);
    }

    public function autoDetect(Request $request, HolidayService $holidayService): JsonResponse
    {
        Gate::authorize('holiday.create');

        $tenant = app(CurrentTenant::class)->get();
        $year = $request->integer('year', now()->year);

        $created = $holidayService->autoDetect($tenant->id, $year);

        AuditLog::record('holiday.auto_detected', null, [
            'year' => $year,
            'created' => $created,
        ]);

        return response()->json([
            'year' => $year,
            'created' => $created,
        ]);
    }
}
