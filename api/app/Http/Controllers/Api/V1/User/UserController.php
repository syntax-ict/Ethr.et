<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\User;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\CustomRole;
use App\Models\Employee;
use App\Models\User;
use App\Services\UserProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function __construct(private readonly UserProvisioningService $provisioning) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('users.viewAny');

        $query = User::query()->with('employee');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('email', 'like', "%{$search}%");
        }

        if ($request->has('filter.role')) {
            $query->where('role', $request->input('filter.role'));
        }

        if ($request->has('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        $query->orderBy('email');

        return UserResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        $employee = isset($data['employee_id'])
            ? Employee::where('public_id', $data['employee_id'])->first()
            : null;

        $customRole = isset($data['custom_role_id'])
            ? CustomRole::where('public_id', $data['custom_role_id'])->first()
            : null;

        $user = $this->provisioning->provision(
            email: $data['email'],
            role: UserRole::from($data['role']),
            employee: $employee,
            invitedBy: $request->user()->id,
            customRoleId: $customRole?->id,
            sendActivation: (bool) ($data['send_activation'] ?? true),
            username: $data['username'] ?? null,
        );

        // The unique rule on `email` guarantees a fresh account here; guard anyway.
        if ($user === null) {
            return $this->problem(422, __('user.errors.already_exists'));
        }

        return (new UserResource($user->load('employee')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        Gate::authorize('users.update');
        $this->guardTargetLevel($request->user(), $user);

        $data = $request->validated();

        // Match the provisioning path: handles are stored lower-cased so the
        // case-insensitive login lookup and the unique index agree.
        if (array_key_exists('username', $data)) {
            $data['username'] = is_string($data['username']) && trim($data['username']) !== ''
                ? Str::lower(trim($data['username']))
                : null;
        }

        if (array_key_exists('custom_role_id', $data)) {
            $data['custom_role_id'] = $data['custom_role_id']
                ? optional(CustomRole::where('public_id', $data['custom_role_id'])->first())->id
                : null;
        }

        // Stamp activation the first time an invited/inactive account goes active.
        if (($data['status'] ?? null) === 'active' && $user->activated_at === null) {
            $data['activated_at'] = now();
        }

        $before = $user->only(['role', 'status', 'custom_role_id', 'locale']);
        $user->update($data);

        AuditLog::record('user.updated', $user, [
            'before' => $before,
            'after' => $user->only(['role', 'status', 'custom_role_id', 'locale']),
        ]);

        return new UserResource($user->load('employee'));
    }

    public function resendInvite(Request $request, User $user): JsonResponse
    {
        Gate::authorize('users.invite');

        if ($user->status !== 'invited') {
            return $this->problem(422, __('user.errors.not_pending'));
        }

        $sent = $this->provisioning->sendActivationLink($user);

        AuditLog::record('user.invite_resent', $user);

        return response()->json([
            'message' => __('user.invite_resent'),
            'sent' => $sent,
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        Gate::authorize('users.delete');

        if ($user->id === $request->user()->id) {
            return $this->problem(422, __('user.errors.cannot_delete_self'));
        }

        $this->guardTargetLevel($request->user(), $user);

        $user->update(['status' => 'inactive']);
        $user->tokens()->delete();
        $user->delete();

        AuditLog::record('user.deactivated', $user);

        return response()->json(null, 204);
    }

    /**
     * Prevent acting on a user whose role outranks the actor (unless super admin).
     */
    private function guardTargetLevel(User $actor, User $target): void
    {
        if (! $actor->isSuperAdmin() && $target->role->level() > $actor->role->level()) {
            abort(403, __('user.errors.cannot_modify_higher'));
        }
    }

    private function problem(int $status, string $detail): JsonResponse
    {
        return response()->json([
            'type' => 'https://ethr.et/errors/business-rule',
            'title' => 'Request Rejected',
            'status' => $status,
            'detail' => $detail,
        ], $status);
    }
}
