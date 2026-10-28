<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Vacancy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vacancy\VacancyRequest;
use App\Http\Resources\VacancyResource;
use App\Models\AuditLog;
use App\Models\Vacancy;
use App\Services\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Vacancies: open positions the organisation advertises. Employees see what
 * is advertised now - internal and public alike; whoever holds
 * `vacancy.manage` also sees drafts and closed ones, to edit and reopen them.
 */
class VacancyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->visibleTo($request)
            ->with(['department', 'branch'])
            ->orderByRaw("CASE status WHEN 'open' THEN 1 WHEN 'draft' THEN 2 ELSE 3 END")
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        $status = $request->query('status');
        if (is_string($status) && in_array($status, Vacancy::STATUSES, true)) {
            $query->where('status', $status);
        }

        return VacancyResource::collection($query->paginate(min($request->integer('per_page', 25), 100)));
    }

    public function show(Request $request, string $vacancy): VacancyResource
    {
        $model = $this->visibleTo($request)->with(['department', 'branch'])->where('public_id', $vacancy)->first();

        // A draft or closed vacancy is not forbidden to an employee - from
        // their side it does not exist, and the response says exactly that.
        if ($model === null) {
            throw (new ModelNotFoundException)->setModel(Vacancy::class, [$vacancy]);
        }

        return new VacancyResource($model);
    }

    public function store(VacancyRequest $request): JsonResponse
    {
        Gate::authorize('vacancy.manage');

        $data = $request->vacancyAttributes();
        $data['status'] ??= 'draft';
        $data['visibility'] ??= 'internal';

        $vacancy = new Vacancy([
            ...$data,
            'tenant_id' => app(CurrentTenant::class)->requireId(),
            'created_by' => $request->user()?->id,
            'published_at' => $data['status'] === 'open' ? now() : null,
        ]);
        $vacancy->save();

        AuditLog::record('vacancy.created', $vacancy, ['status' => $vacancy->status, 'visibility' => $vacancy->visibility]);

        return (new VacancyResource($vacancy->load(['department', 'branch'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(VacancyRequest $request, string $vacancy): VacancyResource
    {
        Gate::authorize('vacancy.manage');

        $model = Vacancy::query()->where('public_id', $vacancy)->firstOrFail();
        $before = $model->only(['status', 'visibility', 'closes_on']);

        $model->fill($request->vacancyAttributes());
        // Opening it for the first time is publishing it; reopening keeps the
        // original date, so "posted on" stays honest.
        if ($model->status === 'open' && $model->published_at === null) {
            $model->published_at = now();
        }
        $model->save();

        AuditLog::record('vacancy.updated', $model, [
            'before' => $before,
            'after' => $model->only(['status', 'visibility', 'closes_on']),
        ]);

        return new VacancyResource($model->load(['department', 'branch']));
    }

    public function destroy(string $vacancy): JsonResponse
    {
        Gate::authorize('vacancy.manage');

        $model = Vacancy::query()->where('public_id', $vacancy)->firstOrFail();
        AuditLog::record('vacancy.deleted', $model);
        $model->delete();

        return response()->json(null, 204);
    }

    /** @return Builder<Vacancy> */
    private function visibleTo(Request $request): Builder
    {
        $query = Vacancy::query();

        return $request->user()?->hasPermission('vacancy.manage')
            ? $query
            : $query->advertised();
    }
}
