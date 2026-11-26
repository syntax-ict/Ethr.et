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
use App\Services\Access\PositionAccess;
use App\Services\CurrentTenant;
use App\Services\MfaService;
use App\Services\Tenancy\OrganisationOwnership;
use App\Services\UserProvisioningService;
use App\Support\CustomRoleAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function __construct(
        private readonly UserProvisioningService $provisioning,
        private readonly PositionAccess $positionAccess,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('users.viewAny');

        $query = User::query()->with(['employee.position', 'latestInvitation', 'customRole']);

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

        // A public id, read through the tenant scope: another organisation's
        // role finds no one rather than raising an error.
        $customRoleFilter = $request->input('filter.custom_role');

        if (is_string($customRoleFilter) && $customRoleFilter !== '') {
            $query->where('custom_role_id', CustomRole::where('public_id', $customRoleFilter)->value('id') ?? 0);
        }

        if ($request->input('filter.title_access') === 'held') {
            $query->whereIn('id', $this->positionAccess->heldQuery()->select('users.id'));
        }

        $query->orderBy('email');

        return UserResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    /**
     * The custom roles the signed-in person may give, for the Users page's
     * picker. Settings > Roles needs `settings.manage`, which an HR admin does
     * not hold, so this is the read for someone who may only assign.
     */
    public function assignableRoles(Request $request): JsonResponse
    {
        Gate::authorize('users.update');

        $actor = $request->user();

        // Below an organisation admin, a role can be given only to someone at a
        // lower built-in level (a peer is refused, a senior gets a 403). The
        // lowest level has no one below it, so it is offered nothing rather
        // than a picker whose every save is refused.
        $nobodyBelow = ! $actor->isSuperAdmin() && $actor->role->level() <= UserRole::EMPLOYEE->level();

        $roles = CustomRole::query()
            ->where('is_active', true)
            ->when($nobodyBelow, fn ($query) => $query->whereKey([]))
            ->withCount('permissions')
            ->orderBy('name')
            ->get()
            ->filter(fn (CustomRole $role): bool => CustomRoleAssignment::mayAssign($actor, $role))
            ->values()
            ->map(fn (CustomRole $role): array => [
                'public_id' => $role->public_id,
                'name' => $role->name,
                'description' => $role->description,
                'org_scope' => $role->org_scope,
                'permissions_count' => (int) $role->permissions_count,
            ]);

        return response()->json(['data' => $roles]);
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

        return (new UserResource($user->load('employee.position')))
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

        $audited = ['role', 'role_follows_position', 'status', 'custom_role_id', 'locale'];
        $before = $user->only($audited);

        // Following the job title wins over a role in the same request. A role
        // given by hand, and different from the one held, ends the following.
        $follows = array_key_exists('role_follows_position', $data) ? (bool) $data['role_follows_position'] : null;
        if ($follows === true) {
            unset($data['role']);
        } elseif (isset($data['role']) && $data['role'] !== $user->role->value) {
            $data['role_follows_position'] = false;
        }

        $user->update($data);

        if ($follows === true) {
            $this->positionAccess->sync($user, $request->user());
        }

        AuditLog::record('user.updated', $user, [
            'before' => $before,
            'after' => $user->only($audited),
        ]);

        return new UserResource($user->load(['employee.position', 'customRole']));
    }

    /**
     * Bring an account's role to the one its job title gives, now, as the
     * signed-in person. Refused with 422 when they may not give that role.
     */
    public function titleAccess(Request $request, User $user): UserResource|JsonResponse
    {
        Gate::authorize('users.update');
        $this->guardTargetLevel($request->user(), $user);

        $outcome = $this->positionAccess->sync($user, $request->user());

        if ($outcome === PositionAccess::HELD) {
            return $this->problem(422, __('user.errors.title_access_held'));
        }

        return new UserResource($user->load(['employee.position', 'customRole']));
    }

    public function resendInvite(Request $request, User $user): JsonResponse
    {
        Gate::authorize('users.invite');

        if ($user->status !== 'invited') {
            return $this->problem(422, __('user.errors.not_pending'));
        }

        $sent = $this->provisioning->sendActivationLink($user, $request->user()->id);

        AuditLog::record('user.invite_resent', $user);

        return response()->json([
            'message' => __('user.invite_resent'),
            'sent' => $sent,
        ]);
    }

    /**
     * Turn off two-factor authentication for someone who lost their
     * authenticator. They sign in with their password and set it up again; a
     * tenant that requires MFA sends them straight to enrolment. Their trusted
     * browsers are forgotten, and they are emailed that it happened.
     */
    public function resetMfa(Request $request, User $user, MfaService $mfa): UserResource|JsonResponse
    {
        Gate::authorize('users.resetMfa');

        if ($user->id === $request->user()->id) {
            return $this->problem(422, __('user.errors.cannot_reset_own_mfa'));
        }

        $this->guardTargetLevel($request->user(), $user);

        if (! $user->mfa_enabled) {
            return $this->problem(409, __('user.errors.mfa_not_enabled'));
        }

        // Until 2026-10-09 there was no way back from a lost device: no
        // recovery codes, and no one who could turn MFA off for another user.
        // The sign-in screen pointed at a recovery page that did not exist.
        $mfa->reset($user, by: 'tenant_admin');

        return new UserResource($user->load('employee.position'));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        Gate::authorize('users.delete');

        if ($user->id === $request->user()->id) {
            return $this->problem(422, __('user.errors.cannot_delete_self'));
        }

        $this->guardTargetLevel($request->user(), $user);

        if (OrganisationOwnership::owns($user, app(CurrentTenant::class)->get())) {
            return $this->problem(422, __('user.errors.owner_transfer_first'));
        }

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
