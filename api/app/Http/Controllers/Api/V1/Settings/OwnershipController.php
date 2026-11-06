<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TransferOwnershipRequest;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\Tenancy\OrganisationOwnership;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

/**
 * The organisation's owner, and handing ownership to another active
 * administrator. Only the owner may give it away, and only after typing
 * their password again: it changes who ETHR's support calls and who alone
 * may hand it on next.
 */
class OwnershipController extends Controller
{
    public function show(CurrentTenant $currentTenant): JsonResponse
    {
        Gate::authorize('users.viewAny');

        $tenant = $currentTenant->get();
        $owner = $tenant?->owner_user_id !== null ? User::query()->find($tenant->owner_user_id) : null;

        $isOwner = $owner !== null && $owner->id === request()->user()?->id;
        $candidates = $isOwner
            ? User::query()
                ->where('role', UserRole::TENANT_ADMIN)
                ->where('status', 'active')
                ->whereKeyNot($owner->id)
                ->orderBy('id')
                ->get()
                ->map(fn (User $u) => ['public_id' => $u->public_id, 'name' => $u->name])
                ->values()
                ->all()
            : [];

        return response()->json([
            'owner_public_id' => $owner?->public_id,
            'owner_name' => $owner?->name,
            'is_owner' => $isOwner,
            'candidates' => $candidates,
        ]);
    }

    public function transfer(TransferOwnershipRequest $request, CurrentTenant $currentTenant, OrganisationOwnership $ownership): JsonResponse
    {
        Gate::authorize('settings.manage');

        $actor = $request->user();
        $tenant = $currentTenant->get();

        if ($tenant === null || ! OrganisationOwnership::owns($actor, $tenant)) {
            return response()->json([
                'type' => 'https://ethr.et/errors/forbidden',
                'title' => 'Forbidden',
                'status' => 403,
                'detail' => __('user.errors.only_owner_transfers'),
            ], 403)->header('Content-Type', 'application/problem+json');
        }

        if (! Hash::check((string) $request->validated('password'), (string) $actor->password)) {
            return response()->json([
                'type' => 'https://ethr.et/errors/validation',
                'title' => 'Validation Failed',
                'status' => 422,
                'detail' => __('user.errors.password_incorrect'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $to = User::query()
            ->where('public_id', (string) $request->validated('user_public_id'))
            ->where('role', UserRole::TENANT_ADMIN)
            ->where('status', 'active')
            ->first();

        if ($to === null || $to->id === $actor->id) {
            return response()->json([
                'type' => 'https://ethr.et/errors/validation',
                'title' => 'Validation Failed',
                'status' => 422,
                'detail' => __('user.errors.owner_must_be_active_admin'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $ownership->transfer($tenant, $actor, $to);

        return response()->json(['owner_public_id' => $to->public_id]);
    }
}
