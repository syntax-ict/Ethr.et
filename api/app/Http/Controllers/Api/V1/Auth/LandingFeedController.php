<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LandingNewsRequest;
use App\Models\Announcement;
use App\Models\Branch;
use App\Models\Holiday;
use App\Models\Vacancy;
use App\Services\CurrentTenant;
use App\Support\TenantLanding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The public side of an organisation's sign-in page (ethr.et/{slug}): what it
 * chose to tell anyone who opens it, read from the database on every request.
 *
 * Nothing here is shown unless two choices agree: the section is switched on
 * in the sign-in page settings, and the item itself was marked for the public
 * by whoever wrote it - an announcement for the whole organisation with
 * "show on sign-in page", a vacancy with public visibility. Everything else an
 * organisation writes stays behind sign-in.
 *
 * The tenant is the one ResolveTenant resolved for this request, as for
 * `/auth/tenant-context`; with none, every list is empty. Every query runs
 * through the tenant scope.
 */
class LandingFeedController extends Controller
{
    private const ANNOUNCEMENTS = 5;

    private const VACANCIES = 6;

    private const HOLIDAYS = 4;

    private const BRANCHES = 12;

    private const NEWS_PER_PAGE = 9;

    private const RELATED = 3;

    public function index(CurrentTenant $currentTenant): JsonResponse
    {
        $tenant = $currentTenant->get();
        $landing = $tenant !== null ? TenantLanding::present($tenant) : null;
        $sections = $landing['sections'] ?? ['announcements' => false, 'vacancies' => false, 'holidays' => false, 'contact' => false, 'about' => false];

        // The organisation's places, only when it chose to show them: the
        // name, city and phone a visitor would look for, nothing about who
        // works there. The count alone is a fact for the about section.
        $branches = $sections['contact'] && ($landing['show_branches'] ?? false)
            ? Branch::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(self::BRANCHES)
                ->get()
                ->map(fn (Branch $b): array => [
                    'public_id' => $b->public_id,
                    'name' => $b->name,
                    'name_am' => $b->name_am,
                    'city' => $b->city,
                    'address' => $b->address,
                    'phone' => TenantLanding::phone($b->phone),
                    'latitude' => $b->latitude === null ? null : (float) $b->latitude,
                    'longitude' => $b->longitude === null ? null : (float) $b->longitude,
                ])
                ->all()
            : [];
        $branchCount = $sections['about'] && ($landing['show_branch_count'] ?? false)
            ? Branch::query()->where('is_active', true)->count()
            : null;

        $announcements = $sections['announcements']
            ? self::newsOrder(Announcement::query()->publiclyListed())
                ->limit(self::ANNOUNCEMENTS)
                ->get()
                ->map(fn (Announcement $a): array => self::newsSummary($a, (string) $currentTenant->publicId()))
                ->all()
            : [];

        $vacancies = $sections['vacancies']
            ? Vacancy::query()
                ->publiclyListed()
                ->with(['department:id,name', 'branch:id,name'])
                ->orderByDesc('published_at')
                ->limit(self::VACANCIES)
                ->get()
                ->map(fn (Vacancy $v): array => self::vacancySummary($v))
                ->all()
            : [];

        $holidays = $sections['holidays']
            ? Holiday::query()
                ->where('is_active', true)
                ->whereNull('branch_id')
                ->whereDate('date', '>=', Carbon::today())
                ->whereDate('date', '<=', Carbon::today()->addDays(120))
                ->orderBy('date')
                ->limit(self::HOLIDAYS)
                ->get()
                ->map(fn (Holiday $h): array => [
                    'name' => $h->name,
                    'name_am' => $h->name_am,
                    'date' => $h->date->format('Y-m-d'),
                ])
                ->all()
            : [];

        // Cached briefly, like tenant-context: the portal is public and read
        // far more often than news is published, and Vary keeps one tenant's
        // feed from being served as another's through a shared cache.
        return response()->json([
            'announcements' => $announcements,
            'vacancies' => $vacancies,
            'holidays' => $holidays,
            'branches' => $branches,
            'branch_count' => $branchCount,
        ])->withHeaders(['Cache-Control' => 'public, max-age=120', 'Vary' => 'Host, X-Tenant']);
    }

    /**
     * The organisation's news page (`/news`): every public announcement, the
     * featured ones first, then newest, a page at a time, optionally narrowed
     * by a search over title and text and by category. Only what the feed
     * itself could show; with the news section off there is no news page,
     * and the answer is the same 404 as for an organisation that has none.
     */
    public function news(LandingNewsRequest $request, CurrentTenant $currentTenant): JsonResponse
    {
        $tenant = $currentTenant->get();
        if ($tenant === null || ! TenantLanding::present($tenant)['sections']['announcements']) {
            throw (new ModelNotFoundException)->setModel(Announcement::class);
        }

        $query = Announcement::query()->publiclyListed();
        $search = trim((string) $request->validated('q', ''));
        if ($search !== '') {
            // The search text is a literal: % and _ would otherwise be
            // wildcards a visitor could use to match everything.
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('body', 'like', $like));
        }
        $category = $request->validated('category');
        if (is_string($category)) {
            $query->where('category', $category);
        }

        // Which categories this organisation has actually published under,
        // so the filter offers only ones that lead somewhere.
        $categories = Announcement::query()
            ->publiclyListed()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->all();

        $page = self::newsOrder($query)->paginate(self::NEWS_PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn (Announcement $a): array => self::newsSummary($a, (string) $currentTenant->publicId()))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'categories' => array_values(array_filter($categories, 'is_string')),
        ])->withHeaders(['Cache-Control' => 'public, max-age=120', 'Vary' => 'Host, X-Tenant']);
    }

    /**
     * One public announcement in full, for its article page. Only what the
     * news page itself lists can be opened: published, not expired, marked for
     * the public and addressed to everyone; anything else is the same 404.
     * Up to three related stories follow: the same category first, then the
     * newest.
     */
    public function announcement(CurrentTenant $currentTenant, string $announcement): JsonResponse
    {
        $tenant = $currentTenant->get();
        $model = $tenant === null || ! TenantLanding::present($tenant)['sections']['announcements']
            ? null
            : Announcement::query()
                ->publiclyListed()
                ->where('public_id', $announcement)
                ->first();

        if ($model === null) {
            throw (new ModelNotFoundException)->setModel(Announcement::class, [$announcement]);
        }

        $related = self::newsOrder(
            Announcement::query()
                ->publiclyListed()
                ->whereKeyNot($model->getKey())
                ->when(
                    $model->category !== null,
                    fn ($q) => $q->orderByRaw('CASE WHEN category = ? THEN 0 ELSE 1 END', [$model->category]),
                ),
        )
            ->limit(self::RELATED)
            ->get()
            ->map(fn (Announcement $a): array => self::newsSummary($a, (string) $currentTenant->publicId()))
            ->all();

        return response()->json([
            ...self::newsSummary($model, $tenant->public_id),
            'body' => self::excerpt($model->body, 10000),
            'related' => $related,
        ]);
    }

    /**
     * Featured first, then urgent and important, then newest.
     *
     * @param  Builder<Announcement>  $query
     * @return Builder<Announcement>
     */
    private static function newsOrder(Builder $query): Builder
    {
        return $query
            ->orderByDesc('featured')
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 ELSE 3 END")
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    /**
     * @return array{public_id: string, title: string, excerpt: string, category: string|null, featured: bool, priority: string, published_at: string|null, cover: array{url: string, srcset: string, width: int, height: int}|null}
     */
    private static function newsSummary(Announcement $a, string $tenantPublicId): array
    {
        return [
            'public_id' => $a->public_id,
            'title' => Str::limit($a->title, 150),
            'excerpt' => self::excerpt($a->body, 280),
            'category' => $a->category,
            'featured' => (bool) $a->featured,
            'priority' => $a->priority,
            'published_at' => Carbon::make($a->published_at)?->toIso8601String(),
            'cover' => $a->coverPresented($tenantPublicId),
        ];
    }

    /**
     * One public vacancy in full, for its detail view on the sign-in page. A
     * draft, closed, internal or unknown one is the same 404.
     */
    public function vacancy(CurrentTenant $currentTenant, string $vacancy): JsonResponse
    {
        $tenant = $currentTenant->get();
        $model = $tenant === null || ! TenantLanding::present($tenant)['sections']['vacancies']
            ? null
            : Vacancy::query()
                ->publiclyListed()
                ->with(['department:id,name', 'branch:id,name'])
                ->where('public_id', $vacancy)
                ->first();

        if ($model === null) {
            throw (new ModelNotFoundException)->setModel(Vacancy::class, [$vacancy]);
        }

        return response()->json([
            ...self::vacancySummary($model),
            'description' => self::excerpt($model->description, 5000),
            'requirements' => $model->requirements === null ? null : self::excerpt($model->requirements, 5000),
        ]);
    }

    /**
     * @return array{public_id: string, title: string, employment_type: string, location: string|null, department: string|null, branch: string|null, positions: int, published_at: string|null, closes_on: string|null, apply_email: string|null, apply_url: string|null}
     */
    private static function vacancySummary(Vacancy $v): array
    {
        return [
            'public_id' => $v->public_id,
            'title' => $v->title,
            'employment_type' => $v->employment_type,
            'location' => $v->location,
            'department' => $v->department?->name,
            'branch' => $v->branch?->name,
            'positions' => $v->positions,
            'published_at' => $v->published_at?->toIso8601String(),
            'closes_on' => $v->closes_on?->format('Y-m-d'),
            'apply_email' => is_string($v->apply_email) && filter_var($v->apply_email, FILTER_VALIDATE_EMAIL) !== false
                ? $v->apply_email
                : null,
            'apply_url' => TenantLanding::httpsUrl($v->apply_url),
        ];
    }

    /** Plain text only: whatever markup a body holds never reaches the page. */
    private static function excerpt(string $text, int $max): string
    {
        $plain = trim((string) preg_replace('/[ \t]+/', ' ', strip_tags($text)));

        return Str::limit($plain, $max);
    }
}
