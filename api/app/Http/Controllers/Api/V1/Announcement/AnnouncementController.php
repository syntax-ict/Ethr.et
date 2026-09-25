<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Announcement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Announcement\StoreAnnouncementRequest;
use App\Http\Requests\Announcement\UpdateAnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Jobs\NotifyAnnouncementAudienceJob;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Services\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AnnouncementController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Announcement::query()
            ->published()
            ->notExpired()
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 WHEN 'low' THEN 4 END")
            ->orderByDesc('published_at');

        return AnnouncementResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function show(Request $request, Announcement $announcement): AnnouncementResource
    {
        // `index()` lists only published, unexpired announcements. Route-model
        // binding applies neither scope, so this action used to hand back any
        // announcement in the tenant to anyone holding its public id — including
        // a draft created with `publish_now: false`, which is the one state that
        // means "not visible yet". The rule is re-run through the same two
        // scopes rather than restated from the loaded model, so the list and the
        // single-read cannot come to disagree about what "published" means.
        $visible = Announcement::query()
            ->published()
            ->notExpired()
            ->whereKey($announcement->getKey())
            ->exists();

        // Managers keep access. `store()` can create a draft and `update()`
        // edits one, so a drafting UI has to be able to read it back.
        $canManage = $request->user()?->hasPermission('announcement.manage') ?? false;

        if (! $visible && ! $canManage) {
            // The same exception route-model binding raises for an id that
            // resolves to nothing, constructed the same way, so the two produce
            // a byte-identical body. A draft is not a forbidden resource — it is
            // one that does not exist yet from this caller's side — and a
            // distinguishable response would confirm it across that line.
            throw (new ModelNotFoundException)->setModel(Announcement::class, [$announcement->public_id]);
        }

        return new AnnouncementResource($announcement);
    }

    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        Gate::authorize('announcement.manage');

        $tenant = app(CurrentTenant::class)->get();
        $user = $request->user();
        $data = $request->validated();

        $announcement = Announcement::create([
            'tenant_id' => $tenant->id,
            'title' => $data['title'],
            'body' => $data['body'],
            'priority' => $data['priority'] ?? 'normal',
            'target_type' => $data['target_type'] ?? 'all',
            'target_id' => $data['target_id'] ?? null,
            'published_by' => $user->id,
            'published_at' => ($data['publish_now'] ?? true) ? now() : null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        AuditLog::record('announcement.created', $announcement);

        // Publishing is what makes an announcement visible; a draft scheduled for
        // later is notified when the job runs against a published_at in the past.
        NotifyAnnouncementAudienceJob::dispatch($announcement->id, $announcement->tenant_id)->onQueue('notifications');

        return (new AnnouncementResource($announcement))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateAnnouncementRequest $request, Announcement $announcement): AnnouncementResource
    {
        Gate::authorize('announcement.manage');

        $announcement->update($request->validated());

        AuditLog::record('announcement.updated', $announcement);

        return new AnnouncementResource($announcement);
    }

    public function destroy(Announcement $announcement): JsonResponse
    {
        Gate::authorize('announcement.manage');

        AuditLog::record('announcement.deleted', $announcement);

        $announcement->delete();

        return response()->json(null, 204);
    }
}
