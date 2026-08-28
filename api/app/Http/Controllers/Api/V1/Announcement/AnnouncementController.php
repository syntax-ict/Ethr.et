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

    public function show(Announcement $announcement): AnnouncementResource
    {
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
        NotifyAnnouncementAudienceJob::dispatch($announcement->id)->onQueue('notifications');

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
