<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\IssueStartingPasswordRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\Auth\StartingPasswordRefused;
use App\Services\Auth\StartingPasswords;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class StartingPasswordController extends Controller
{
    public function __construct(private readonly StartingPasswords $startingPasswords) {}

    /**
     * Give a batch of employees one starting password.
     *
     * For people with no email to be invited by. ETHR makes the password and
     * returns it once; it cannot be read back. Each person signs in with their
     * employee number and must choose their own password before anything else.
     * Unused after 72 hours, the account goes back to invited. Someone who
     * cannot be given one (already active, above the employee role, reachable
     * by an invitation) is left as they were and counted under `skipped`.
     */
    public function store(IssueStartingPasswordRequest $request): JsonResponse
    {
        Gate::authorize('users.issueStartingPassword');

        /** @var User $actor */
        $actor = $request->user();
        /** @var list<string> $ids */
        $ids = $request->validated('employee_ids');

        // The tenant scope keeps another organisation's employees out. One of
        // them, and one outside this person's reach, is answered exactly as an
        // id that matches nobody.
        $employees = Employee::query()->whereIn('public_id', $ids)->get();
        $known = $employees->count() === count($ids)
            && $employees->every(fn (Employee $employee): bool => $actor->canAccessEmployee($employee));
        if (! $known) {
            throw ValidationException::withMessages([
                'employee_ids' => __('validation.exists', ['attribute' => 'employee ids']),
            ]);
        }

        $ordered = $employees->keyBy('public_id');

        try {
            $issued = $this->startingPasswords->issue(
                array_values(array_filter(array_map(fn (string $id) => $ordered->get($id), $ids))),
                $actor,
            );
        } catch (StartingPasswordRefused $e) {
            return $this->noStore(response()->json([
                'type' => 'https://ethr.et/errors/starting-password-refused',
                'title' => 'Starting Password Not Issued',
                'status' => 422,
                'detail' => __('auth.starting_password_refused.'.$e->reason),
                'reason' => $e->reason,
                'skipped' => $e->skipped,
            ], 422));
        }

        return $this->noStore(response()->json([
            'password' => $issued['password'],
            'expires_at' => $issued['expires_at'],
            'issued' => count($issued['people']),
            'people' => $issued['people'],
            'skipped' => $issued['skipped'],
        ], 201));
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        return $response->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }
}
