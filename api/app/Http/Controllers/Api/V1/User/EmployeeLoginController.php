<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\CreateEmployeeLoginsRequest;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\Invitations\EmployeeLogins;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Give employees who cannot sign in a way to: a login for each, and one queued
 * batch of invitations. Used after a device migration, which creates employees
 * and no logins, so HR can check the names first. Nothing is sent inside the
 * request, so a few hundred people fit well inside the host's time limit.
 */
class EmployeeLoginController extends Controller
{
    public function __construct(private readonly EmployeeLogins $logins) {}

    public function store(CreateEmployeeLoginsRequest $request): JsonResponse
    {
        Gate::authorize('users.invite');
        Gate::authorize('employee.update');

        /** @var list<string> $ids */
        $ids = $request->validated('employee_ids');

        // The tenant scope keeps another organisation's employees out, and
        // one of them is answered exactly as an id that matches nobody.
        $employees = Employee::query()->whereIn('public_id', $ids)->with('user')->get();
        if ($employees->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'employee_ids' => __('validation.exists', ['attribute' => 'employee ids']),
            ]);
        }

        $ordered = $employees->keyBy('public_id');
        $result = $this->logins->forEmployees(
            array_values(array_filter(array_map(fn (string $id) => $ordered->get($id), $ids))),
            $request->user(),
        );

        AuditLog::record('user.logins_created', null, ['employees' => count($ids), ...$result]);

        return response()->json([
            'message' => __('user.invitation.queued'),
            'created' => $result['created'],
            'active' => $result['active'],
            'in_person' => $result['in_person'],
            'email_in_use' => $result['email_in_use'],
            'batch' => $result['batch'],
            'queued' => $result['queued'],
        ], 202);
    }
}
