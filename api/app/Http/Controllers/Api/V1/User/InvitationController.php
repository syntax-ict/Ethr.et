<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\SendInvitationsRequest;
use App\Models\User;
use App\Services\Invitations\InvitationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Invitations for many people at once, sent from the queue
 * (InvitationDispatcher): the people picked on the users page, or everyone
 * still waiting to activate. One person's invitation is still
 * `POST /users/{user}/resend-invite`, sent at once.
 */
class InvitationController extends Controller
{
    public function __construct(private readonly InvitationDispatcher $dispatcher) {}

    public function store(SendInvitationsRequest $request): JsonResponse
    {
        Gate::authorize('users.invite');

        // The tenant scope keeps another organisation's public ids out; an id
        // that matches nobody is simply not invited.
        $users = $request->filled('user_ids')
            ? User::query()->whereIn('public_id', $request->validated('user_ids'))->get()
            : User::query()->where('status', 'invited')->get();

        $result = $this->dispatcher->queue($users, $request->user());

        return response()->json([
            'message' => __('user.invitation.queued'),
            'batch' => $result['batch'],
            'queued' => $result['queued'],
            'in_person' => $result['in_person'],
            'skipped' => $result['skipped'],
        ], 202);
    }

    public function show(string $batch): JsonResponse
    {
        Gate::authorize('users.invite');

        $summary = $this->dispatcher->summary($batch);

        if ($summary === null) {
            return response()->json([
                'type' => 'https://ethr.et/errors/not-found',
                'title' => 'Not Found',
                'status' => 404,
                'detail' => __('user.invitation.batch_not_found'),
            ], 404);
        }

        return response()->json($summary);
    }
}
