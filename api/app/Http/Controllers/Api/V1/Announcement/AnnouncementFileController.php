<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Announcement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Announcement\UploadAnnouncementFileRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Services\FileStorageService;
use App\Support\DocumentDownload;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * A story's documents: attached and removed on the publishing desk, and
 * downloaded in the app by anyone who may read the story. The public copy of
 * a public story's documents is NewsFileController's.
 */
class AnnouncementFileController extends Controller
{
    public function __construct(private readonly FileStorageService $storage) {}

    public function store(UploadAnnouncementFileRequest $request, Announcement $announcement): AnnouncementResource
    {
        Gate::authorize('announcement.manage');

        $stored = $this->storage->storeNewsFile($request->file('file'), $announcement->public_id);
        $announcement->forceFill(['attachments' => [...$announcement->attachedFiles(), $stored]])->save();

        AuditLog::record('announcement.file_attached', $announcement, ['file' => $stored['id'], 'name' => $stored['name']]);

        return new AnnouncementResource($announcement);
    }

    public function destroy(Announcement $announcement, string $file): AnnouncementResource
    {
        Gate::authorize('announcement.manage');

        $removed = $announcement->attachedFile($file);
        abort_if($removed === null, 404);

        $announcement->forceFill([
            'attachments' => array_values(array_filter($announcement->attachedFiles(), fn (array $f): bool => $f['id'] !== $file)),
        ])->save();
        $this->storage->delete($removed['path']);

        AuditLog::record('announcement.file_removed', $announcement, ['file' => $file, 'name' => $removed['name']]);

        return new AnnouncementResource($announcement);
    }

    /**
     * Download one document, on the terms the story itself is read on
     * (`AnnouncementController::show()`): published, unexpired and addressed
     * to the reader, or any state for whoever manages announcements. Anything
     * else is the same 404 as a story that does not exist.
     */
    public function download(Request $request, Announcement $announcement, string $file): Response
    {
        $visible = Announcement::query()
            ->published()
            ->notExpired()
            ->visibleTo($request->user())
            ->whereKey($announcement->getKey())
            ->exists();
        $canManage = $request->user()?->hasPermission('announcement.manage') ?? false;

        if (! $visible && ! $canManage) {
            throw (new ModelNotFoundException)->setModel(Announcement::class, [$announcement->public_id]);
        }

        $entry = $announcement->attachedFile($file);
        abort_if($entry === null, 404);
        $bytes = $this->storage->read($entry['path']);
        abort_if($bytes === null, 404);

        return DocumentDownload::pdf($bytes, $entry['name'], 'private, no-store');
    }
}
