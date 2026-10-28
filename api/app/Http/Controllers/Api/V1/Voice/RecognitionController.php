<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Voice;

use App\Http\Controllers\Controller;
use App\Http\Requests\Voice\StoreRecognitionRequest;
use App\Models\Employee;
use App\Models\Recognition;
use App\Models\User;
use App\Notifications\RecognitionReceivedNotification;
use App\Services\CurrentTenant;
use App\Traits\SendsNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recognition: a colleague thanks a colleague, for one of the organisation's
 * values, on a wall everyone in the organisation can read.
 */
class RecognitionController extends Controller
{
    use SendsNotifications;

    public function index(Request $request): JsonResponse
    {
        $page = Recognition::query()
            ->with(['from', 'to'])
            ->latest()
            ->paginate(min($request->integer('per_page', 20), 50));

        return response()->json([
            'data' => collect($page->items())->map(fn (Recognition $r) => $this->present($r)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(StoreRecognitionRequest $request): JsonResponse
    {
        $from = $request->user()?->employee;
        if ($from === null) {
            return $this->problem(__('attendance.no_employee_linked'));
        }

        $to = Employee::query()->where('public_id', $request->validated('to_employee_public_id'))->first();
        if ($to === null) {
            return response()->json([
                'message' => __('validation.exists', ['attribute' => 'colleague']),
                'errors' => ['to_employee_public_id' => [__('validation.exists', ['attribute' => 'colleague'])]],
            ], 422);
        }

        if ($to->id === $from->id) {
            return $this->problem(__('voice.recognition_self'));
        }

        $recognition = Recognition::query()->create([
            'tenant_id' => app(CurrentTenant::class)->requireId(),
            'from_employee_id' => $from->id,
            'to_employee_id' => $to->id,
            'value' => $request->validated('value'),
            'message' => trim(strip_tags((string) $request->validated('message'))),
        ]);

        $this->notify(
            User::query()->where('employee_id', $to->id)->first(),
            new RecognitionReceivedNotification($recognition->load(['from', 'to'])),
        );

        return response()->json($this->present($recognition->load(['from', 'to'])), 201);
    }

    /** @return array<string, mixed> */
    private function present(Recognition $r): array
    {
        return [
            'public_id' => $r->public_id,
            'value' => $r->value,
            'message' => $r->message,
            'from' => $r->from === null ? null : ['public_id' => $r->from->public_id, 'name' => $r->from->name],
            'to' => $r->to === null ? null : ['public_id' => $r->to->public_id, 'name' => $r->to->name],
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    private function problem(string $detail): JsonResponse
    {
        return response()->json([
            'type' => 'https://ethr.et/errors/validation',
            'title' => 'Invalid',
            'status' => 422,
            'detail' => $detail,
        ], 422)->header('Content-Type', 'application/problem+json');
    }
}
