<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Voice;

use App\Http\Controllers\Controller;
use App\Http\Requests\Voice\ConcernMessageRequest;
use App\Http\Requests\Voice\StoreConcernRequest;
use App\Http\Requests\Voice\TrackConcernRequest;
use App\Http\Requests\Voice\UpdateConcernRequest;
use App\Models\AuditLog;
use App\Models\Concern;
use App\Models\ConcernMessage;
use App\Models\User;
use App\Notifications\ConcernRaisedNotification;
use App\Notifications\ConcernUpdatedNotification;
use App\Services\CurrentTenant;
use App\Traits\SendsNotifications;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Speak up: an employee raises what worries them - unsafe work, harassment,
 * a manager, pay - named or anonymously, and follows it to an answer.
 *
 * Anonymous means no reporter is stored and nothing is written to the audit
 * log at submission (the audit log names its actor). The reporter gets a
 * one-time follow-up code, stored only as a hash, to read HR's replies and
 * answer them. HR sees the concern, never who raised it.
 *
 * Named concerns are visible to their reporter and to voice.manage only.
 */
class ConcernController extends Controller
{
    use SendsNotifications;

    /** POST /voice/concerns */
    public function store(StoreConcernRequest $request): JsonResponse
    {
        $anonymous = (bool) $request->validated('anonymous');
        $code = $anonymous ? self::newCode() : null;

        $concern = Concern::query()->create([
            'tenant_id' => app(CurrentTenant::class)->requireId(),
            'reporter_user_id' => $anonymous ? null : $request->user()?->id,
            'anonymous' => $anonymous,
            'tracking_hash' => $code === null ? null : Concern::hashCode($code),
            'category' => $request->validated('category'),
            'subject' => strip_tags((string) $request->validated('subject')),
            'description' => strip_tags((string) $request->validated('description')),
            'status' => 'new',
        ]);

        if (! $anonymous) {
            AuditLog::record('voice.concern_raised', $concern, ['category' => $concern->category]);
        }

        $this->notify($this->handlers(), new ConcernRaisedNotification($concern));

        return response()->json([
            ...$this->present($concern, forHr: false),
            // Shown once. It is the only way back to an anonymous concern.
            'tracking_code' => $code,
        ], 201);
    }

    /** GET /voice/concerns/mine - the caller's named concerns. */
    public function mine(Request $request): JsonResponse
    {
        $concerns = Concern::query()
            ->where('reporter_user_id', $request->user()?->id)
            ->latest()
            ->get();

        return response()->json(['data' => $concerns->map(fn (Concern $c) => $this->present($c, forHr: false))]);
    }

    /** POST /voice/concerns/track - an anonymous concern, by its code. */
    public function track(TrackConcernRequest $request): JsonResponse
    {
        $concern = $this->byCode((string) $request->validated('code'));

        return response()->json($this->present($concern->load('messages'), forHr: false, withMessages: true));
    }

    /** POST /voice/concerns/track/reply - the anonymous reporter answers HR. */
    public function trackReply(TrackConcernRequest $request): JsonResponse
    {
        $concern = $this->byCode((string) $request->validated('code'));
        $body = trim(strip_tags((string) $request->validated('body', '')));
        if ($body === '') {
            return $this->problem(422, __('voice.message_required'));
        }

        $this->addMessage($concern, 'reporter', null, $body);
        $this->notify($this->handlers(), new ConcernUpdatedNotification($concern, 'reporter_replied'));

        return response()->json($this->present($concern->load('messages'), forHr: false, withMessages: true), 201);
    }

    /** GET /voice/concerns - every concern, for voice.manage. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('voice.manage');

        $query = Concern::query()->with('reporter')->withCount('messages')
            ->orderByRaw("CASE status WHEN 'new' THEN 0 WHEN 'in_review' THEN 1 ELSE 2 END")
            ->latest();

        $status = $request->input('filter.status');
        if (is_string($status) && in_array($status, Concern::STATUSES, true)) {
            $query->where('status', $status);
        }

        $page = $query->paginate(min($request->integer('per_page', 25), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (Concern $c) => $this->present($c, forHr: true)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'counts' => Concern::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    /** GET /voice/concerns/{id} - its reporter (named) or voice.manage. */
    public function show(Request $request, string $concern): JsonResponse
    {
        $model = $this->visible($request, $concern);
        $forHr = $this->isHandler($request);

        return response()->json($this->present($model->load(['messages.authorUser', 'reporter']), forHr: $forHr, withMessages: true));
    }

    /** POST /voice/concerns/{id}/messages */
    public function message(ConcernMessageRequest $request, string $concern): JsonResponse
    {
        $model = $this->visible($request, $concern);
        $forHr = $this->isHandler($request);
        $user = $request->user();

        $this->addMessage($model, $forHr ? 'hr' : 'reporter', $user?->id, trim(strip_tags((string) $request->validated('body'))));

        if ($forHr) {
            if ($model->status === 'new') {
                $model->update(['status' => 'in_review']);
            }
            AuditLog::record('voice.concern_replied', $model);
            $this->notify($model->reporter, new ConcernUpdatedNotification($model, 'hr_replied'));
        } else {
            $this->notify($this->handlers(), new ConcernUpdatedNotification($model, 'reporter_replied'));
        }

        return response()->json($this->present($model->load(['messages.authorUser', 'reporter']), forHr: $forHr, withMessages: true), 201);
    }

    /** PUT /voice/concerns/{id} - status and resolution, voice.manage only. */
    public function update(UpdateConcernRequest $request, string $concern): JsonResponse
    {
        Gate::authorize('voice.manage');

        $model = Concern::query()->where('public_id', $concern)->firstOrFail();
        $before = $model->status;
        $status = (string) $request->validated('status');

        $model->update([
            'status' => $status,
            'resolution' => $request->validated('resolution') ?? $model->resolution,
            'resolved_at' => in_array($status, ['resolved', 'closed'], true) ? ($model->resolved_at ?? now()) : null,
        ]);

        AuditLog::record('voice.concern_updated', $model, ['before' => $before, 'after' => $status]);
        $this->notify($model->reporter, new ConcernUpdatedNotification($model, 'status_changed'));

        return response()->json($this->present($model->load(['messages.authorUser', 'reporter']), forHr: true, withMessages: true));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Concern $concern, bool $forHr, bool $withMessages = false): array
    {
        return [
            'public_id' => $concern->public_id,
            'category' => $concern->category,
            'subject' => $concern->subject,
            'description' => $concern->description,
            'status' => $concern->status,
            'resolution' => $concern->resolution,
            'anonymous' => $concern->anonymous,
            'reporter_name' => $forHr && ! $concern->anonymous ? $concern->reporter?->name : null,
            'messages_count' => $concern->messages_count ?? null,
            'created_at' => $concern->created_at?->toIso8601String(),
            'updated_at' => $concern->updated_at?->toIso8601String(),
            'resolved_at' => $concern->resolved_at?->toIso8601String(),
            'messages' => $withMessages
                ? $concern->messages->map(fn (ConcernMessage $m) => [
                    'public_id' => $m->public_id,
                    'author' => $m->author,
                    // The reporter sees "HR"; HR sees which colleague answered.
                    'author_name' => $forHr && $m->author === 'hr' ? $m->authorUser?->name : null,
                    'body' => $m->body,
                    'created_at' => $m->created_at?->toIso8601String(),
                ])->all()
                : null,
        ];
    }

    private function addMessage(Concern $concern, string $author, ?int $userId, string $body): void
    {
        ConcernMessage::query()->create([
            'tenant_id' => $concern->tenant_id,
            'concern_id' => $concern->id,
            'author' => $author,
            'author_user_id' => $userId,
            'body' => $body,
        ]);
        $concern->touch();
    }

    private function visible(Request $request, string $publicId): Concern
    {
        $concern = Concern::query()->where('public_id', $publicId)->first();

        $allowed = $concern !== null && ($this->isHandler($request)
            || ($concern->reporter_user_id !== null && $concern->reporter_user_id === $request->user()?->id));

        if (! $allowed) {
            throw (new ModelNotFoundException)->setModel(Concern::class, [$publicId]);
        }

        return $concern;
    }

    private function byCode(string $code): Concern
    {
        $concern = Concern::query()->where('tracking_hash', Concern::hashCode($code))->first();
        if ($concern === null) {
            throw (new ModelNotFoundException)->setModel(Concern::class);
        }

        return $concern;
    }

    private function isHandler(Request $request): bool
    {
        return (bool) $request->user()?->hasPermission('voice.manage');
    }

    /** @return Collection<int, User> */
    private function handlers(): Collection
    {
        return User::query()->get()->filter(fn (User $u): bool => $u->hasPermission('voice.manage'))->values();
    }

    /** Sixteen characters in four groups, 80 bits: too many to guess. */
    private static function newCode(): string
    {
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $chars = '';
        for ($i = 0; $i < 16; $i++) {
            $chars .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return implode('-', str_split($chars, 4));
    }

    private function problem(int $status, string $detail): JsonResponse
    {
        return response()->json([
            'type' => 'https://ethr.et/errors/validation',
            'title' => 'Invalid',
            'status' => $status,
            'detail' => $detail,
        ], $status)->header('Content-Type', 'application/problem+json');
    }
}
