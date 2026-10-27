<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\NextSteps;

use App\Enums\NextStepId;
use App\Http\Controllers\Controller;
use App\Http\Requests\NextSteps\DismissNextStepRequest;
use App\Http\Resources\NextStepsResource;
use App\Services\CurrentTenant;
use App\Services\NextSteps\NextStepsResult;
use App\Services\NextSteps\NextStepsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class NextStepsController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly NextStepsService $steps,
    ) {}

    /**
     * The things this person should do next, in the order to do them.
     *
     * Built from who they are: their own profile, requests waiting on them, the
     * employee records they keep, the setup they can finish. Each source checks
     * its own permission, org scope and plan, so this route has no single
     * permission. A platform super admin belongs to no organisation and gets an
     * empty list.
     */
    public function index(Request $request): NextStepsResource
    {
        $tenant = $this->currentTenant->get();

        if ($tenant === null) {
            return new NextStepsResource(new NextStepsResult([], null));
        }

        return new NextStepsResource($this->steps->forUser($request->user(), $tenant));
    }

    /**
     * Put a step away until it changes, or for a number of days. A blocker can
     * only be snoozed.
     */
    public function dismiss(DismissNextStepRequest $request, NextStepId $step): Response
    {
        abort_unless($this->currentTenant->resolved(), 404);

        $this->steps->dismiss(
            $request->user(),
            $step,
            (string) $request->validated('signature'),
            $request->filled('snooze_days') ? $request->integer('snooze_days') : null,
        );

        return response()->noContent();
    }

    /** Bring back every step this person put away. */
    public function restore(Request $request): Response
    {
        abort_unless($this->currentTenant->resolved(), 404);

        $this->steps->restore($request->user());

        return response()->noContent();
    }
}
