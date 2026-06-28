<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StorePositionRequest;
use App\Http\Requests\Organization\UpdatePositionRequest;
use App\Http\Resources\PositionResource;
use App\Models\AuditLog;
use App\Models\Position;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class PositionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('org.viewAny');

        $query = Position::query()->withCount('employees');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->has('filter.is_active')) {
            $query->where('is_active', $request->boolean('filter.is_active'));
        }

        $sortField = $request->input('sort', 'title');
        $sortDir = str_starts_with($sortField, '-') ? 'desc' : 'asc';
        $sortField = ltrim($sortField, '-');
        $allowed = ['title', 'code', 'created_at'];
        if (in_array($sortField, $allowed, true)) {
            $query->orderBy($sortField, $sortDir);
        }

        return PositionResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function store(StorePositionRequest $request): JsonResponse
    {
        Gate::authorize('org.create');

        $position = Position::create($request->validated());

        AuditLog::record('position.created', $position);

        return (new PositionResource($position))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Position $position): PositionResource
    {
        Gate::authorize('org.view');

        $position->loadCount('employees');

        return new PositionResource($position);
    }

    public function update(UpdatePositionRequest $request, Position $position): PositionResource
    {
        Gate::authorize('org.update');

        $position->update($request->validated());

        AuditLog::record('position.updated', $position);

        return new PositionResource($position);
    }

    public function destroy(Position $position): JsonResponse
    {
        Gate::authorize('org.delete');

        $position->delete();

        AuditLog::record('position.deleted', $position);

        return response()->json(null, 204);
    }
}
