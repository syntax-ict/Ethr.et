<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\SupportMessageRequest;
use App\Models\SupportRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Support\PlatformSupportInbox;
use App\Services\Support\SupportDesk;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * ETHR's side of Get help: every request an organisation passed on, with the
 * organisation, the person and what their screen knew, answered and closed
 * from the console. Reads go through PlatformSupportInbox.
 */
class AdminSupportController extends Controller
{
    public function __construct(
        private readonly PlatformSupportInbox $inbox,
        private readonly SupportDesk $desk,
    ) {}

    /** GET /admin/support-requests */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('admin.manage');

        $status = $request->input('filter.status');
        $page = $this->inbox->page(is_string($status) ? $status : null, min(max($request->integer('per_page', 25), 1), 100));

        /** @var array<int, SupportRequest> $items */
        $items = $page->items();
        $tenants = $this->tenants($items);

        return response()->json([
            'data' => array_map(function (SupportRequest $r) use ($tenants): array {
                $people = $r->requester === null ? [] : [$r->requester->id => $r->requester];

                return $this->desk->present($r, SupportDesk::VIEWER_ETHR, new Collection($people), null, $tenants[$r->tenant_id] ?? null);
            }, $items),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'counts' => $this->inbox->counts(),
        ]);
    }

    /** GET /admin/support-requests/{id} */
    public function show(string $supportRequest): JsonResponse
    {
        Gate::authorize('admin.manage');

        return response()->json($this->presentOne($this->inbox->find($supportRequest)));
    }

    /** POST /admin/support-requests/{id}/messages - ETHR answers. */
    public function message(SupportMessageRequest $request, string $supportRequest): JsonResponse
    {
        Gate::authorize('admin.manage');

        $model = $this->inbox->find($supportRequest);
        $body = trim(strip_tags((string) $request->validated('body')));
        abort_if($body === '', 422, __('support.message_required'));

        $this->desk->reply($model, SupportDesk::VIEWER_ETHR, $this->user($request), $body);

        return response()->json($this->presentOne($this->inbox->find($supportRequest)), 201);
    }

    /** POST /admin/support-requests/{id}/resolve */
    public function resolve(Request $request, string $supportRequest): JsonResponse
    {
        Gate::authorize('admin.manage');

        $model = $this->inbox->find($supportRequest);
        $this->desk->resolve($model, SupportDesk::VIEWER_ETHR, $this->user($request));

        return response()->json($this->presentOne($this->inbox->find($supportRequest)));
    }

    /** @return array<string, mixed> */
    private function presentOne(SupportRequest $model): array
    {
        $messages = $this->inbox->messages($model);
        $people = $this->inbox->people($model, [
            $model->requester_user_id,
            $model->escalated_by_user_id,
            ...$messages->pluck('author_user_id')->all(),
        ]);

        $tenant = Tenant::withTrashed()->find($model->tenant_id);

        return $this->desk->present($model, SupportDesk::VIEWER_ETHR, $people, $messages, $tenant);
    }

    /**
     * The organisations a page of requests belongs to, keyed by id. Tenant is
     * a global model: there is no scope to bypass. A closed organisation's
     * requests still name it.
     *
     * @param  array<int, SupportRequest>  $items
     * @return array<int, Tenant>
     */
    private function tenants(array $items): array
    {
        $ids = array_values(array_unique(array_map(fn (SupportRequest $r): int => $r->tenant_id, $items)));

        return $ids === [] ? [] : Tenant::withTrashed()->whereIn('id', $ids)->get()->keyBy('id')->all();
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
