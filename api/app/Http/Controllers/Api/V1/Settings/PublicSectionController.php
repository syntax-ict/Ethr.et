<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UploadPublicItemImageRequest;
use App\Http\Requests\Settings\UpsertPublicItemRequest;
use App\Http\Requests\Settings\UpsertPublicSectionRequest;
use App\Models\AuditLog;
use App\Models\TenantPublicItem;
use App\Models\TenantPublicSection;
use App\Services\CurrentTenant;
use App\Services\FileStorageService;
use App\Support\TenantPublicAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Administering the blocks on a tenant's public landing page.
 *
 * Authorization is `settings.manage`, the same gate the rest of the settings
 * surface uses, stated once per action rather than delegated to the
 * FormRequests — which return true deliberately, so the rule lives in one
 * place.
 *
 * Every read and write goes through the tenant-scoped models, so
 * `BelongsToTenant` supplies isolation: a section ULID belonging to another
 * tenant resolves to nothing and answers 404, rather than being found and then
 * refused. That distinction matters — a 403 would confirm the row exists.
 */
class PublicSectionController extends Controller
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function index(): JsonResponse
    {
        Gate::authorize('settings.manage');

        $sections = TenantPublicSection::query()
            ->with('items')
            ->orderBy('position')
            ->get();

        return response()->json([
            'sections' => $sections->map(fn (TenantPublicSection $s) => $this->sectionPayload($s))->all(),
        ]);
    }

    public function store(UpsertPublicSectionRequest $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        $tenant = $this->currentTenant->get();

        $section = new TenantPublicSection($request->validated());
        $section->tenant_id = $tenant->id;
        // Appended, not inserted. Reordering is its own operation, so creating
        // never has to renumber anything.
        $section->position = (int) TenantPublicSection::query()->max('position') + 1;
        $section->save();

        AuditLog::record('settings.public_section_created', $tenant, [
            'kind' => $section->kind->value,
        ]);

        return response()->json($this->sectionPayload($section), 201);
    }

    public function update(UpsertPublicSectionRequest $request, string $section): JsonResponse
    {
        Gate::authorize('settings.manage');

        $model = $this->findSection($section);
        $model->fill($request->validated())->save();

        AuditLog::record('settings.public_section_updated', $this->currentTenant->get(), [
            'kind' => $model->kind->value,
        ]);

        return response()->json($this->sectionPayload($model->fresh(['items'])));
    }

    public function destroy(string $section): JsonResponse
    {
        Gate::authorize('settings.manage');

        $model = $this->findSection($section);
        $kind = $model->kind->value;

        // Items cascade in the database. Their images do not — deleting the
        // files is best-effort and deliberately not allowed to fail the
        // request, the same way the profile's hero replacement behaves.
        foreach ($model->items as $item) {
            $this->forgetImage($item->image_path);
        }

        $model->delete();

        AuditLog::record('settings.public_section_deleted', $this->currentTenant->get(), ['kind' => $kind]);

        return response()->json(['message' => 'Section removed']);
    }

    /**
     * Put the sections in the given order.
     *
     * Takes the whole ordered list rather than a single move, and rewrites
     * every position inside one transaction. Positions are renumbered densely
     * from zero, so there is no unique constraint to dance around and no way
     * for a half-applied reorder to leave two sections claiming one slot.
     */
    public function reorder(Request $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        $validated = $request->validate([
            'order' => ['required', 'array', 'max:'.UpsertPublicSectionRequest::MAX_SECTIONS],
            'order.*' => ['required', 'string', 'size:26'],
        ]);

        $tenant = $this->currentTenant->get();

        DB::transaction(function () use ($validated): void {
            $sections = TenantPublicSection::query()
                ->whereIn('public_id', $validated['order'])
                ->get()
                ->keyBy('public_id');

            $position = 0;

            foreach ($validated['order'] as $publicId) {
                $section = $sections->get($publicId);

                // Silently skipping an id that is not this tenant's is correct
                // here: the scoped query simply did not return it, and telling
                // the caller which of the ids it sent were real would confirm
                // the existence of another tenant's rows.
                if ($section === null) {
                    continue;
                }

                $section->update(['position' => $position++]);
            }
        });

        AuditLog::record('settings.public_section_reordered', $tenant);

        return $this->index();
    }

    // ── Items ───────────────────────────────────────────────────────────────

    public function storeItem(UpsertPublicItemRequest $request, string $section): JsonResponse
    {
        Gate::authorize('settings.manage');

        $model = $this->findSection($section);
        $request->assertSectionHasRoom($model->id);

        $item = new TenantPublicItem($request->validated());
        $item->tenant_id = $model->tenant_id;
        $item->section_id = $model->id;
        $item->position = (int) TenantPublicItem::query()->where('section_id', $model->id)->max('position') + 1;
        $item->save();

        AuditLog::record('settings.public_section_updated', $this->currentTenant->get(), [
            'kind' => $model->kind->value,
        ]);

        return response()->json($this->itemPayload($item), 201);
    }

    public function updateItem(UpsertPublicItemRequest $request, string $item): JsonResponse
    {
        Gate::authorize('settings.manage');

        $model = $this->findItem($item);
        $model->fill($request->validated())->save();

        return response()->json($this->itemPayload($model->fresh()));
    }

    public function destroyItem(string $item): JsonResponse
    {
        Gate::authorize('settings.manage');

        $model = $this->findItem($item);
        $this->forgetImage($model->image_path);
        $model->delete();

        return response()->json(['message' => 'Entry removed']);
    }

    /**
     * Attach an image, and the text describing it, to one entry.
     *
     * Reuses FileStorageService, which verifies the file's magic bytes against
     * its declared type and strips EXIF — so the location data in a photograph
     * taken on a phone does not get published along with it.
     */
    public function uploadItemImage(
        UploadPublicItemImageRequest $request,
        FileStorageService $storage,
        string $item,
    ): JsonResponse {
        Gate::authorize('settings.manage');

        $model = $this->findItem($item);
        $previous = $model->image_path;

        $stored = $storage->upload($request->file('image'), 'public/sections');

        $model->update([
            'image_path' => $stored['path'],
            'image_alt' => $request->string('alt')->value(),
            'image_alt_am' => $request->input('alt_am'),
        ]);

        $this->forgetImage($previous);

        AuditLog::record('settings.public_section_updated', $this->currentTenant->get(), [
            'image' => $stored['path'],
        ]);

        return response()->json($this->itemPayload($model->fresh()), 201);
    }

    // ── Lookups ─────────────────────────────────────────────────────────────

    /**
     * A section of this tenant, by ULID.
     *
     * `firstOrFail` on the scoped query, so another tenant's ULID is a 404 and
     * not a 403 — the row is not found rather than found and refused.
     */
    private function findSection(string $publicId): TenantPublicSection
    {
        return TenantPublicSection::query()
            ->with('items')
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function findItem(string $publicId): TenantPublicItem
    {
        return TenantPublicItem::query()->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * Delete a stored image, best effort.
     *
     * Only ever removes a path this platform wrote — the same guard the
     * profile's image replacement uses — and never fails the request, because
     * a file left behind is a housekeeping problem and a 500 is the
     * administrator's problem.
     */
    private function forgetImage(?string $path): void
    {
        if (! is_string($path) || $path === '' || ! str_starts_with($path, 'tenants/')) {
            return;
        }

        try {
            app(FileStorageService::class)->delete($path);
        } catch (\Throwable) {
            // Intentionally ignored.
        }
    }

    /** @return array<string, mixed> */
    private function sectionPayload(TenantPublicSection $section): array
    {
        return [
            'id' => $section->public_id,
            'kind' => $section->kind->value,
            'position' => $section->position,
            'is_visible' => $section->is_visible,
            'heading' => $section->heading,
            'heading_am' => $section->heading_am,
            'intro' => $section->intro,
            'intro_am' => $section->intro_am,
            'layout' => $section->layout,
            'has_items' => $section->kind->hasItems(),
            'allows_images' => $section->kind->allowsItemImages(),
            'reads_profile' => $section->kind->readsProfile(),
            'items' => $section->items->map(fn (TenantPublicItem $i) => $this->itemPayload($i))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function itemPayload(TenantPublicItem $item): array
    {
        return [
            'id' => $item->public_id,
            'position' => $item->position,
            'title' => $item->title,
            'title_am' => $item->title_am,
            'body' => $item->body,
            'body_am' => $item->body_am,
            // Presence, never the path. Where the file lives is this
            // platform's business, exactly as with the tenant logo.
            'has_image' => $item->hasImage(),
            'image_url' => $item->hasImage() ? TenantPublicAsset::sectionUrl($item) : null,
            'image_alt' => $item->image_alt,
            'image_alt_am' => $item->image_alt_am,
            'icon' => $item->icon,
            'link_url' => $item->link_url,
            'link_label' => $item->link_label,
            'link_label_am' => $item->link_label_am,
            'meta' => $item->meta ?? [],
        ];
    }
}
