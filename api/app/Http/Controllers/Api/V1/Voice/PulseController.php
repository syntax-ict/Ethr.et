<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Voice;

use App\Http\Controllers\Controller;
use App\Http\Requests\Voice\StorePulseRequest;
use App\Models\Department;
use App\Models\PulseResponse;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The weekly pulse: "how was work this week?", 1 to 5, with optional topics
 * and a comment.
 *
 * Answers are anonymous by construction. The stored answer names no user and
 * no employee and carries the day, not the time; its public id is generated
 * for the start of that day, so even the id does not date it to the second.
 * That this person already answered this week is kept in the cache only.
 *
 * Results are shown in aggregate, and nothing is shown for a group smaller
 * than MIN_GROUP - with fewer, an average or a department line could point
 * to a person.
 */
class PulseController extends Controller
{
    public const MIN_GROUP = 5;

    private const WEEKS = 12;

    public function show(Request $request): JsonResponse
    {
        $week = PulseResponse::weekOf(Carbon::now());

        return response()->json([
            'week' => $week,
            'answered' => Cache::has($this->answeredKey($request, $week)),
            'tags' => PulseResponse::TAGS,
        ]);
    }

    public function store(StorePulseRequest $request): JsonResponse
    {
        $week = PulseResponse::weekOf(Carbon::now());
        $key = $this->answeredKey($request, $week);

        // Kept a little past the week, so the answer holds until it ends.
        if (! Cache::add($key, true, Carbon::now()->addDays(8))) {
            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-state',
                'title' => 'Already answered',
                'status' => 422,
                'detail' => __('voice.pulse_already_answered'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $today = Carbon::today();
        PulseResponse::query()->create([
            'public_id' => (string) Str::ulid($today),
            'tenant_id' => app(CurrentTenant::class)->get()->id,
            'week' => $week,
            'score' => (int) $request->validated('score'),
            'tags' => array_values(array_unique($request->validated('tags', []))),
            'comment' => $this->clean($request->validated('comment')),
            'department_id' => $request->user()?->employee?->department_id,
            'submitted_on' => $today->toDateString(),
        ]);

        return response()->json(['week' => $week, 'answered' => true], 201);
    }

    /** GET /voice/pulse/insights - for whoever holds voice.manage. */
    public function insights(): JsonResponse
    {
        Gate::authorize('voice.manage');

        $weeks = [];
        $cursor = Carbon::now()->startOfWeek();
        for ($i = self::WEEKS - 1; $i >= 0; $i--) {
            $weeks[] = PulseResponse::weekOf($cursor->copy()->subWeeks($i));
        }

        $byWeek = PulseResponse::query()
            ->whereIn('week', $weeks)
            ->selectRaw('week, count(*) as responses, avg(score) as average')
            ->groupBy('week')
            ->get()
            ->keyBy('week');

        $trend = array_map(function (string $week) use ($byWeek): array {
            $row = $byWeek->get($week);
            $count = is_object($row) ? (int) $row->getAttribute('responses') : 0;
            $average = is_object($row) ? (float) $row->getAttribute('average') : 0.0;

            return [
                'week' => $week,
                'responses' => $count,
                'average' => $count >= self::MIN_GROUP ? round($average, 2) : null,
            ];
        }, $weeks);

        $recentWeeks = array_slice($weeks, -4);
        $recent = PulseResponse::query()->whereIn('week', $recentWeeks)->get();
        $enough = $recent->count() >= self::MIN_GROUP;

        $distribution = [];
        foreach (range(1, 5) as $score) {
            $distribution[(string) $score] = $enough ? $recent->where('score', $score)->count() : null;
        }

        $tags = [];
        if ($enough) {
            foreach ($recent as $response) {
                foreach ($response->tags ?? [] as $tag) {
                    $tags[$tag] = ($tags[$tag] ?? 0) + 1;
                }
            }
            arsort($tags);
        }

        $names = Department::query()->pluck('name', 'id');
        $departments = $recent->groupBy('department_id')
            ->filter(fn ($group) => $group->count() >= self::MIN_GROUP)
            ->map(fn ($group, $id) => [
                'department' => $id === '' ? null : ($names[$id] ?? null),
                'responses' => $group->count(),
                'average' => round((float) $group->avg('score'), 2),
            ])
            ->values()
            ->all();

        // Comments only once the group is large enough, newest week first and
        // shuffled within it, so their order says nothing about who wrote them.
        $comments = $enough
            ? $recent->whereNotNull('comment')
                ->groupBy('week')
                ->sortKeysDesc()
                ->flatMap(fn ($group) => $group->shuffle())
                ->take(50)
                ->map(fn (PulseResponse $r) => [
                    'week' => $r->week,
                    'score' => $r->score,
                    'tags' => $r->tags ?? [],
                    'comment' => $r->comment,
                ])
                ->values()
                ->all()
            : [];

        return response()->json([
            'min_group' => self::MIN_GROUP,
            'trend' => $trend,
            'recent' => [
                'weeks' => $recentWeeks,
                'responses' => $recent->count(),
                'average' => $enough ? round((float) $recent->avg('score'), 2) : null,
                'distribution' => $distribution,
                'tags' => $tags,
                'departments' => $departments,
                'comments' => $comments,
            ],
        ]);
    }

    private function answeredKey(Request $request, string $week): string
    {
        return 'pulse:'.app(CurrentTenant::class)->id().':'.$request->user()?->id.':'.$week;
    }

    private function clean(mixed $text): ?string
    {
        if (! is_string($text)) {
            return null;
        }
        $text = trim(strip_tags($text));

        return $text === '' ? null : $text;
    }
}
