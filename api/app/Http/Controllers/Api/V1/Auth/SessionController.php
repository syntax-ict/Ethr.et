<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\SessionResource;
use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Active session management (PHASE_00 S03).
 *
 * Sessions are Sanctum personal access tokens. Every query is scoped through
 * `$request->user()->tokens()`, so a user can only ever see or revoke their own —
 * there is no path here that takes a caller-supplied user.
 */
class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sessions = $request->user()
            ->tokens()
            // An expired token is not an active session; showing it would imply a
            // device still has access when it does not.
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get();

        // Wrapped explicitly: `JsonResource::withoutWrapping()` is global, so an
        // unpaginated collection would otherwise return a bare array while every
        // paginated endpoint returns `{ data: … }`. Matches TaxBracketController
        // and the sibling TrustedDeviceController.
        return response()->json([
            'data' => SessionResource::collection($sessions)->resolve($request),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $token = $user->tokens()->whereKey($id)->first();

        if ($token === null) {
            return response()->json([
                'type' => 'https://ethr.et/errors/session-not-found',
                'title' => 'Session Not Found',
                'status' => 404,
                'detail' => __('auth.session_not_found'),
            ], 404);
        }

        $isCurrent = PersonalAccessToken::currentIdFor($user) === $token->getKey();

        $token->delete();

        AuditLog::record('user.session_revoked', $user, [
            'session_id' => (int) $id,
            'was_current' => $isCurrent,
        ]);

        return response()->json([
            'message' => __('auth.session_revoked'),
            'was_current' => $isCurrent,
        ]);
    }

    /**
     * Revoke every session except the one making the request.
     *
     * Keeping the caller signed in is the point: this is the "something looks
     * wrong, sign everything else out" control, and logging the user out of the
     * device they are actively securing from would be hostile.
     */
    public function revokeAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentId = PersonalAccessToken::currentIdFor($user);

        $query = $user->tokens();

        if ($currentId !== null) {
            $query->whereKeyNot($currentId);
        }

        $revoked = $query->delete();

        AuditLog::record('user.sessions_revoked', $user, ['revoked' => $revoked]);

        return response()->json([
            'message' => __('auth.sessions_revoked'),
            'revoked' => $revoked,
        ]);
    }
}
