<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Announcement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Announcement\UploadAnnouncementCoverRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Services\FileStorageService;
use Illuminate\Support\Facades\Gate;

/**
 * A story's cover photo on the publishing desk: upload one (replacing the one
 * before it) or remove it. The photo is re-encoded and served publicly by
 * NewsCoverController at a URL that changes with every upload, so a page
 * that cached the old one never shows a stale image under the new address.
 */
class AnnouncementCoverController extends Controller
{
    public function __construct(private readonly FileStorageService $storage) {}

    public function store(UploadAnnouncementCoverRequest $request, Announcement $announcement): AnnouncementResource
    {
        Gate::authorize('announcement.manage');

        $previous = $announcement->coverImage();
        $stored = $this->storage->uploadNewsCover($request->file('image'), $announcement->public_id);

        $announcement->forceFill(['cover' => $stored])->save();

        // Best effort: an orphaned old image is storage waste, not a failed upload.
        if ($previous !== null && $previous['path'] !== $stored['path']) {
            $this->storage->deleteNewsCover($previous);
        }

        AuditLog::record('announcement.cover_updated', $announcement, ['version' => $stored['version']]);

        return new AnnouncementResource($announcement);
    }

    public function destroy(Announcement $announcement): AnnouncementResource
    {
        Gate::authorize('announcement.manage');

        $cover = $announcement->coverImage();
        if ($cover !== null) {
            $announcement->forceFill(['cover' => null])->save();
            $this->storage->deleteNewsCover($cover);
            AuditLog::record('announcement.cover_removed', $announcement);
        }

        return new AnnouncementResource($announcement);
    }
}
