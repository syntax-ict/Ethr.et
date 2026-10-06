<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreTeamRequest;
use App\Http\Requests\Organization\UpdateTeamRequest;
use App\Http\Resources\TeamResource;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class TeamController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('org.viewAny');

        $query = Team::query()
            ->with('department')
            ->withCount('employees');

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->input('search')}%");
        }

        if ($request->has('filter.is_active')) {
            $query->where('is_active', $request->boolean('filter.is_active'));
        }

        if ($request->filled('filter.department_public_id')) {
            $dept = Department::where('public_id', $request->input('filter.department_public_id'))->first();
            if ($dept) {
                $query->where('department_id', $dept->id);
            }
        }

        $query->orderBy('name');

        return TeamResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function store(StoreTeamRequest $request): JsonResponse
    {
        Gate::authorize('org.create');

        $data = $request->validated();

        if (! empty($data['department_public_id'])) {
            $dept = Department::where('public_id', $data['department_public_id'])->firstOrFail();
            $data['department_id'] = $dept->id;
        }
        unset($data['department_public_id']);

        $team = Team::create($data);
        $team->load('department');

        AuditLog::record('team.created', $team);

        return (new TeamResource($team))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Team $team): TeamResource
    {
        Gate::authorize('org.view');

        $team->load('department');
        $team->loadCount('employees');

        return new TeamResource($team);
    }

    public function update(UpdateTeamRequest $request, Team $team): TeamResource
    {
        Gate::authorize('org.update');

        $data = $request->validated();

        if (array_key_exists('department_public_id', $data)) {
            if ($data['department_public_id']) {
                $dept = Department::where('public_id', $data['department_public_id'])->firstOrFail();
                $data['department_id'] = $dept->id;
            } else {
                $data['department_id'] = null;
            }
        }
        unset($data['department_public_id']);

        $team->update($data);
        $team->load('department');

        AuditLog::record('team.updated', $team);

        return new TeamResource($team);
    }

    public function destroy(Team $team): JsonResponse
    {
        Gate::authorize('org.delete');

        $team->delete();

        AuditLog::record('team.deleted', $team);

        return response()->json(null, 204);
    }
}
