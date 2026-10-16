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
use App\Services\FileStorageService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class AnnouncementController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // The publishing view: every announcement whatever its state (drafts,
        // scheduled, archived), newest first, for whoever may manage them.
        // Asked for explicitly so the reading list everyone else gets - and
        // the dashboard card built on it - keeps its rules and its order.
        if ($request->boolean('manage')) {
            Gate::authorize('announcement.manage');

            return AnnouncementResource::collection(
                Announcement::query()
                    ->orderByRaw('published_at IS NULL DESC')
                    ->orderByDesc('published_at')
                    ->orderByDesc('id')
                    ->paginate($request->integer('per_page', 25))
            );
        }

        $query = Announcement::query()
            ->published()
            ->notExpired()
            ->visibleTo($request->user())
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
            ->visibleTo($request->user())
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
        $target = $request->resolvedTarget();

        // A publication time wins; without one, `publish_now` decides between
        // now and a draft, as it always has.
        $publishedAt = array_key_exists('published_at', $data)
            ? ($data['published_at'] === null ? null : Carbon::parse($data['published_at']))
            : (($data['publish_now'] ?? true) ? now() : null);

        $announcement = Announcement::create([
            'tenant_id' => $tenant->id,
            'title' => $data['title'],
            'body' => $data['body'],
            'category' => $data['category'] ?? null,
            'priority' => $data['priority'] ?? 'normal',
            'target_type' => $target['target_type'] ?? 'all',
            'target_id' => $target['target_id'] ?? null,
            'published_by' => $user->id,
            'published_at' => $publishedAt,
            'expires_at' => $data['expires_at'] ?? null,
            // Only a whole-organisation announcement can be public; the
            // request refuses the other combinations.
            'show_on_landing' => (bool) ($data['show_on_landing'] ?? false),
            'featured' => (bool) ($data['featured'] ?? false),
        ]);

        AuditLog::record('announcement.created', $announcement);

        self::notifyWhenPublished($announcement);

        return (new AnnouncementResource($announcement))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateAnnouncementRequest $request, Announcement $announcement): AnnouncementResource
    {
        Gate::authorize('announcement.manage');

        $data = $request->validated();
        $target = $request->resolvedTarget();
        if ($target !== null) {
            $data = array_merge($data, $target);
        }

        // Narrowing the audience takes it off the public page as well.
        if (($data['target_type'] ?? $announcement->target_type) !== 'all') {
            $data['show_on_landing'] = false;
        }

        $wasStatus = $announcement->status();
        $announcement->update($data);

        // Publishing, unpublishing, scheduling and archiving are recorded as
        // what they are, not as an edit, so the audit log answers "who made
        // this public, and when".
        $status = $announcement->status();
        AuditLog::record(
            $status === $wasStatus ? 'announcement.updated' : match ($status) {
                'draft' => 'announcement.unpublished',
                default => "announcement.{$status}",
            },
            $announcement,
        );

        if (array_key_exists('published_at', $data)) {
            self::notifyWhenPublished($announcement);
        }

        return new AnnouncementResource($announcement);
    }

    /**
     * Tell the audience once the announcement is out: now if it is published,
     * at its time if it is scheduled, never for a draft. The job notifies at
     * most once (`notified_at`), so publishing again, rescheduling or
     * unpublishing and republishing does not repeat the message.
     */
    private static function notifyWhenPublished(Announcement $announcement): void
    {
        if ($announcement->published_at === null || $announcement->notified_at !== null) {
            return;
        }

        $job = NotifyAnnouncementAudienceJob::dispatch($announcement->id, $announcement->tenant_id)->onQueue('notifications');
        if ($announcement->published_at->isFuture()) {
            $job->delay($announcement->published_at);
        }
    }

    public function destroy(Announcement $announcement, FileStorageService $storage): JsonResponse
    {
        Gate::authorize('announcement.manage');

        AuditLog::record('announcement.deleted', $announcement);

        $cover = $announcement->coverImage();
        $announcement->delete();
        if ($cover !== null) {
            $storage->deleteNewsCover($cover);
        }

        return response()->json(null, 204);
    }
}
