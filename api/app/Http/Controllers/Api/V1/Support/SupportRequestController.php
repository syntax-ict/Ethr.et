<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\EscalateSupportRequest;
use App\Http\Requests\Support\StoreSupportRequest;
use App\Http\Requests\Support\SupportMessageRequest;
use App\Models\SupportRequest;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\Support\SupportDesk;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Get help, inside an organisation. Anyone signed in asks; the
 * organisation's support people (support.manage) see every request, answer,
 * pass one to ETHR, and close it. A request is visible to the person who
 * asked and to support.manage only.
 *
 * The flow and its notifications live in SupportDesk; ETHR's side is
 * AdminSupportController.
 */
class SupportRequestController extends Controller
{
    public function __construct(private readonly SupportDesk $desk) {}

    /** POST /support-requests */
    public function store(StoreSupportRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validated();

        abort_if(
            ($data['send_to'] ?? 'organisation') === SupportRequest::HANDLED_BY_ETHR && ! $user->hasPermission('support.manage'),
            403,
            __('support.send_to_forbidden'),
        );

        $created = $this->desk->open($user, app(CurrentTenant::class)->requireId(), $data, $request);

        return response()->json($this->presentOne($created, SupportDesk::VIEWER_REQUESTER), 201);
    }

    /** GET /support-requests/mine - the caller's own requests, newest activity first. */
    public function mine(Request $request): JsonResponse
    {
        $page = SupportRequest::query()
            ->where('requester_user_id', $this->user($request)->id)
            ->latest('updated_at')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json($this->presentPage($page->items(), $page, SupportDesk::VIEWER_REQUESTER));
    }

    /** GET /support-requests - every request in the organisation, for support.manage. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('support.manage');

        $query = SupportRequest::query()
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'answered' THEN 1 ELSE 2 END")
            ->latest('updated_at');

        $status = $request->input('filter.status');
        if (is_string($status) && in_array($status, SupportRequest::STATUSES, true)) {
            $query->where('status', $status);
        }

        $page = $query->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            ...$this->presentPage($page->items(), $page, SupportDesk::VIEWER_ORGANISATION),
            'counts' => SupportRequest::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    /** GET /support-requests/{id} - the person who asked, or support.manage. */
    public function show(Request $request, string $supportRequest): JsonResponse
    {
        [$model, $viewer] = $this->visible($request, $supportRequest);

        return response()->json($this->presentOne($model, $viewer));
    }

    /** POST /support-requests/{id}/messages */
    public function message(SupportMessageRequest $request, string $supportRequest): JsonResponse
    {
        [$model, $viewer] = $this->visible($request, $supportRequest);

        $body = trim(strip_tags((string) $request->validated('body')));
        abort_if($body === '', 422, __('support.message_required'));

        $this->desk->reply($model, $viewer, $this->user($request), $body);

        return response()->json($this->presentOne($model->refresh(), $viewer), 201);
    }

    /** POST /support-requests/{id}/escalate - pass it to ETHR, support.manage only. */
    public function escalate(EscalateSupportRequest $request, string $supportRequest): JsonResponse
    {
        Gate::authorize('support.manage');

        $model = SupportRequest::query()->where('public_id', $supportRequest)->firstOrFail();
        abort_if($model->isWithEthr(), 409, __('support.already_with_ethr'));

        $this->desk->escalate($model, $this->user($request), $request->validated('note'));

        return response()->json($this->presentOne($model->refresh(), SupportDesk::VIEWER_ORGANISATION));
    }

    /** POST /support-requests/{id}/resolve - the person who asked, or support.manage. */
    public function resolve(Request $request, string $supportRequest): JsonResponse
    {
        [$model, $viewer] = $this->visible($request, $supportRequest);

        $this->desk->resolve($model, $viewer, $this->user($request));

        return response()->json($this->presentOne($model->refresh(), $viewer));
    }

    /**
     * The request, and who the caller is to it. The person who asked is the
     * requester even when they also hold support.manage, so their own
     * request reads as theirs. Anyone else gets the same 404 as a request
     * that does not exist.
     *
     * @return array{0: SupportRequest, 1: string}
     */
    private function visible(Request $request, string $publicId): array
    {
        $user = $this->user($request);
        $model = SupportRequest::query()->where('public_id', $publicId)->first();

        if ($model !== null && $model->requester_user_id === $user->id) {
            return [$model, SupportDesk::VIEWER_REQUESTER];
        }

        abort_unless($model !== null && $user->hasPermission('support.manage'), 404);

        return [$model, SupportDesk::VIEWER_ORGANISATION];
    }

    /** @return array<string, mixed> */
    private function presentOne(SupportRequest $model, string $viewer): array
    {
        $messages = $model->messages()->get();
        $ids = [$model->requester_user_id, $model->escalated_by_user_id, ...$messages->pluck('author_user_id')->all()];

        return $this->desk->present($model, $viewer, $this->people($ids), $messages);
    }

    /**
     * @param  array<int, SupportRequest>  $items
     * @param  LengthAwarePaginator<int, SupportRequest>  $page
     * @return array<string, mixed>
     */
    private function presentPage(array $items, LengthAwarePaginator $page, string $viewer): array
    {
        $people = $this->people(collect($items)->flatMap(fn (SupportRequest $r) => [$r->requester_user_id, $r->escalated_by_user_id])->all());

        return [
            'data' => array_map(fn (SupportRequest $r) => $this->desk->present($r, $viewer, $people), $items),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }

    /**
     * The organisation's own people a request names. ETHR's platform accounts
     * belong to no tenant and stay out of this scoped read, so their replies
     * read as "ETHR support" here.
     *
     * @param  array<int, int|null>  $ids
     * @return Collection<int, User>
     */
    private function people(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? new Collection : User::query()->whereIn('id', $ids)->get()->keyBy('id');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw (new ModelNotFoundException)->setModel(User::class);
        }

        return $user;
    }
}
