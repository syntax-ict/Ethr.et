<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStatutoryLayoutRequest;
use App\Http\Resources\StatutoryLayoutResource;
use App\Models\AuditLog;
use App\Models\StatutoryLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The dated layouts of the monthly statutory schedules, from the platform
 * console. When MoR, POESSA or PSSSA publish a form (owner action O3), a new
 * version is added here and takes effect from its date — no deploy. A version
 * is never edited: adding one closes the previous version of the same
 * schedule and scheme the day before.
 */
class AdminStatutoryLayoutController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('admin.manage');

        $layouts = StatutoryLayout::query()
            ->orderBy('schedule')
            ->orderBy('scheme')
            ->orderByDesc('effective_from')
            ->get();

        return response()->json(['data' => StatutoryLayoutResource::collection($layouts)]);
    }

    public function store(StoreStatutoryLayoutRequest $request): JsonResponse
    {
        Gate::authorize('admin.manage');

        $data = $request->validated();
        $from = Carbon::parse($data['effective_from'])->startOfDay();
        $scheme = $data['schedule'] === 'pension' ? $data['scheme'] : null;

        $latest = StatutoryLayout::query()
            ->where('schedule', $data['schedule'])
            ->when($scheme === null, fn ($q) => $q->whereNull('scheme'), fn ($q) => $q->where('scheme', $scheme))
            ->orderByDesc('effective_from')
            ->first();

        abort_if(
            $latest !== null && $latest->effective_from->greaterThanOrEqualTo($from),
            422,
            'A new version must start after '.$latest?->effective_from->format('Y-m-d').'.',
        );

        $layout = DB::transaction(function () use ($data, $from, $scheme, $latest): StatutoryLayout {
            if ($latest !== null && ($latest->effective_to === null || $latest->effective_to->greaterThanOrEqualTo($from))) {
                $latest->update(['effective_to' => $from->copy()->subDay()->toDateString()]);
            }

            return StatutoryLayout::create([
                'schedule' => $data['schedule'],
                'scheme' => $scheme,
                'agency' => $data['agency'],
                'title' => $data['title'],
                'note' => $data['note'] ?? null,
                'columns' => array_map(
                    static fn (array $c): array => ['key' => (string) $c['key'], 'label' => (string) $c['label']],
                    $data['columns'],
                ),
                'effective_from' => $from->toDateString(),
            ]);
        });

        AuditLog::record('platform.statutory_layout.created', $layout, ['schedule' => $layout->schedule, 'scheme' => $layout->scheme]);

        return (new StatutoryLayoutResource($layout))->response()->setStatusCode(201);
    }
}
