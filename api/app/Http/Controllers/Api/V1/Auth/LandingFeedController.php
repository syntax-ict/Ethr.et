<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Holiday;
use App\Models\Vacancy;
use App\Services\CurrentTenant;
use App\Support\TenantLanding;
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

    public function index(CurrentTenant $currentTenant): JsonResponse
    {
        $tenant = $currentTenant->get();
        $sections = $tenant !== null
            ? TenantLanding::present($tenant)['sections']
            : ['announcements' => false, 'vacancies' => false, 'holidays' => false];

        $announcements = $sections['announcements']
            ? Announcement::query()
                ->published()
                ->notExpired()
                ->where('show_on_landing', true)
                ->where('target_type', 'all')
                ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 ELSE 3 END")
                ->orderByDesc('published_at')
                ->limit(self::ANNOUNCEMENTS)
                ->get()
                ->map(fn (Announcement $a): array => [
                    'public_id' => $a->public_id,
                    'title' => Str::limit($a->title, 150),
                    'excerpt' => self::excerpt($a->body, 280),
                    'priority' => $a->priority,
                    'published_at' => Carbon::make($a->published_at)?->toIso8601String(),
                ])
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

        return response()->json([
            'announcements' => $announcements,
            'vacancies' => $vacancies,
            'holidays' => $holidays,
        ]);
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
