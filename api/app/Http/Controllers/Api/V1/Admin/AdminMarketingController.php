<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReorderMarketingItemsRequest;
use App\Http\Requests\Admin\StoreMarketingItemRequest;
use App\Http\Requests\Admin\UpdateMarketingSectionRequest;
use App\Http\Requests\Admin\UploadMarketingImageRequest;
use App\Http\Resources\AdminMarketingItemResource;
use App\Http\Resources\AdminMarketingSectionResource;
use App\Models\AuditLog;
use App\Models\MarketingItem;
use App\Models\MarketingSection;
use App\Services\FileStorageService;
use App\Support\MarketingContent;
use App\Support\MarketingImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Super-admin editing of the public landing page.
 *
 * `admin.manage` is held only by SUPER_ADMIN, and the route group adds
 * EnsurePlatformContext, RequirePlatformMfa and the platform-admin throttle.
 * Every write is recorded in the audit log; a public cache is cleared by
 * the models themselves.
 */
class AdminMarketingController extends Controller
{
    public function __construct(private readonly FileStorageService $storage) {}

    public function index(): JsonResponse
    {
        Gate::authorize('admin.manage');

        // Every section and every row, published or not: this is the screen
        // where an operator publishes one.
        $sections = MarketingSection::query()->with('items')->orderBy('sort_order')->get();

        return response()->json([
            'data' => [
                'sections' => AdminMarketingSectionResource::collection($sections),
                'icons' => MarketingContent::ICONS,
            ],
        ]);
    }

    public function updateSection(UpdateMarketingSectionRequest $request, string $kind): JsonResponse
    {
        Gate::authorize('admin.manage');

        $section = MarketingSection::query()->where('kind', $kind)->firstOrFail();
        $changes = $request->validated();

        // Captured before the write: Eloquent re-syncs $original after save.
        $before = array_intersect_key($section->getOriginal(), $changes);
        $section->update($changes);

        AuditLog::record('platform.marketing.section_updated', $section, [
            'kind' => $kind,
            'changed' => array_keys($changes),
            'before' => $before,
            'after' => $changes,
        ]);

        return response()->json(['data' => new AdminMarketingSectionResource($section->fresh('items'))]);
    }

    public function storeItem(StoreMarketingItemRequest $request, string $kind): JsonResponse
    {
        Gate::authorize('admin.manage');

        $section = MarketingSection::query()->where('kind', $kind)->firstOrFail();
        $next = (int) MarketingItem::query()->where('section_kind', $kind)->max('sort_order') + 1;

        $item = MarketingItem::create([
            ...$request->validated(),
            'section_kind' => $section->kind,
            'sort_order' => $next,
        ]);

        AuditLog::record('platform.marketing.item_created', $item, ['kind' => $kind, 'slug' => $item->slug]);

        return response()->json(['data' => new AdminMarketingItemResource($item)], 201);
    }

    public function updateItem(StoreMarketingItemRequest $request, string $publicId): JsonResponse
    {
        Gate::authorize('admin.manage');

        $item = MarketingItem::query()->where('public_id', $publicId)->firstOrFail();
        $changes = $request->validated();

        $before = array_intersect_key($item->getOriginal(), $changes);
        $item->update($changes);

        AuditLog::record('platform.marketing.item_updated', $item, [
            'kind' => $item->section_kind,
            'slug' => $item->slug,
            'changed' => array_keys($changes),
            'before' => $before,
            'after' => $changes,
        ]);

        return response()->json(['data' => new AdminMarketingItemResource($item->fresh())]);
    }

    public function destroyItem(string $publicId): JsonResponse
    {
        Gate::authorize('admin.manage');

        $item = MarketingItem::query()->where('public_id', $publicId)->firstOrFail();
        $stored = MarketingImage::stored($item);

        $item->delete();

        // Best effort: an orphaned file is storage waste, not a failed delete.
        if ($stored !== null) {
            $this->storage->deleteMarketingImage($stored);
        }

        AuditLog::record('platform.marketing.item_deleted', null, [
            'kind' => $item->section_kind,
            'slug' => $item->slug,
            'public_id' => $item->public_id,
        ]);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function reorder(ReorderMarketingItemsRequest $request, string $kind): JsonResponse
    {
        Gate::authorize('admin.manage');

        $section = MarketingSection::query()->where('kind', $kind)->firstOrFail();
        /** @var list<string> $order */
        $order = $request->validated('order');

        // Listed rows take their new positions; any row left out keeps its
        // place after them, so a partial list cannot lose a row.
        DB::transaction(function () use ($kind, $order): void {
            $position = 1;
            foreach ($order as $publicId) {
                MarketingItem::query()->where('section_kind', $kind)->where('public_id', $publicId)->update(['sort_order' => $position++]);
            }
            $rest = MarketingItem::query()->where('section_kind', $kind)->whereNotIn('public_id', $order)->orderBy('sort_order')->get();
            foreach ($rest as $item) {
                $item->update(['sort_order' => $position++]);
            }
        });

        AuditLog::record('platform.marketing.item_reordered', $section, ['kind' => $kind, 'order' => $order]);

        return response()->json(['data' => new AdminMarketingSectionResource($section->fresh('items'))]);
    }

    public function uploadItemImage(UploadMarketingImageRequest $request, string $publicId): JsonResponse
    {
        Gate::authorize('admin.manage');

        $item = MarketingItem::query()->where('public_id', $publicId)->firstOrFail();
        if (! MarketingContent::spec($item->section_kind)['images']) {
            abort(422, __('validation.marketing_field_unused', ['kind' => $item->section_kind]));
        }

        $this->replaceImage($item, $request);

        AuditLog::record('platform.marketing.image_updated', $item, ['kind' => $item->section_kind, 'slug' => $item->slug, 'version' => $item->image_version]);

        return response()->json(['data' => new AdminMarketingItemResource($item->fresh())], 201);
    }

    public function destroyItemImage(string $publicId): JsonResponse
    {
        Gate::authorize('admin.manage');

        $item = MarketingItem::query()->where('public_id', $publicId)->firstOrFail();
        $this->removeImage($item);

        AuditLog::record('platform.marketing.image_removed', $item, ['kind' => $item->section_kind, 'slug' => $item->slug]);

        return response()->json(['data' => new AdminMarketingItemResource($item->fresh())]);
    }

    public function uploadSectionImage(UploadMarketingImageRequest $request, string $kind): JsonResponse
    {
        Gate::authorize('admin.manage');

        $section = MarketingSection::query()->where('kind', $kind)->firstOrFail();
        if (! MarketingContent::spec($kind)['section_image']) {
            abort(422, __('validation.marketing_field_unused', ['kind' => $kind]));
        }

        $this->replaceImage($section, $request);

        AuditLog::record('platform.marketing.image_updated', $section, ['kind' => $kind, 'version' => $section->image_version]);

        return response()->json(['data' => new AdminMarketingSectionResource($section->fresh('items'))], 201);
    }

    public function destroySectionImage(string $kind): JsonResponse
    {
        Gate::authorize('admin.manage');

        $section = MarketingSection::query()->where('kind', $kind)->firstOrFail();
        $this->removeImage($section);

        AuditLog::record('platform.marketing.image_removed', $section, ['kind' => $kind]);

        return response()->json(['data' => new AdminMarketingSectionResource($section->fresh('items'))]);
    }

    private function replaceImage(MarketingItem|MarketingSection $owner, UploadMarketingImageRequest $request): void
    {
        $previous = MarketingImage::stored($owner);
        $stored = $this->storage->uploadMarketingImage($request->file('image'), $owner->public_id);

        $owner->forceFill(MarketingImage::columns($stored))->save();

        // Best effort: an orphaned old image is storage waste, not a failed upload.
        if ($previous !== null && $previous['path'] !== $stored['path']) {
            $this->storage->deleteMarketingImage($previous);
        }
    }

    private function removeImage(MarketingItem|MarketingSection $owner): void
    {
        $stored = MarketingImage::stored($owner);
        if ($stored === null) {
            return;
        }
        $owner->forceFill(MarketingImage::emptyColumns())->save();
        $this->storage->deleteMarketingImage($stored);
    }
}
