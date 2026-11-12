<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Identity\PossibleDuplicates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class PossibleDuplicatesController extends Controller
{
    /**
     * Current employees who may be one person recorded twice.
     *
     * Read-only. Two or more records are put in a group when they share
     * something a person has only one of: a national id, an email address,
     * a phone number, a badge number, or a name together with a date of
     * birth. `reasons` says which; the values themselves are not returned.
     * Only people employed now are compared, among the employees the reader
     * may see. At most 100 groups are returned, the likeliest first, and
     * `truncated` says when there were more.
     */
    public function __invoke(Request $request, PossibleDuplicates $duplicates): JsonResponse
    {
        Gate::authorize('employee.create');

        $query = Employee::query();
        $request->user()->scopeAccessibleEmployees($query);

        $found = $duplicates->among($query);

        return response()->json($this->payload($found['groups'], $found['truncated']));
    }

    /**
     * @param  list<array{reasons: list<string>, employees: Collection<int, Employee>}>  $found
     * @return array{groups: list<array{reasons: list<string>, employees: list<array{public_id: string, name: string, employee_code: string|null, status: string, department: string|null, created_via: string|null, created_at: string|null}>}>, truncated: bool}
     */
    private function payload(array $found, bool $truncated): array
    {
        /** @var list<array{reasons: list<string>, employees: list<array{public_id: string, name: string, employee_code: string|null, status: string, department: string|null, created_via: string|null, created_at: string|null}>}> $groups */
        $groups = [];
        foreach ($found as $group) {
            /** @var list<array{public_id: string, name: string, employee_code: string|null, status: string, department: string|null, created_via: string|null, created_at: string|null}> $people */
            $people = [];
            foreach ($group['employees'] as $employee) {
                $people[] = [
                    'public_id' => (string) $employee->public_id,
                    'name' => (string) $employee->name,
                    'employee_code' => $employee->employee_code,
                    'status' => $employee->status->value,
                    'department' => $employee->department?->name,
                    'created_via' => $employee->created_via,
                    'created_at' => $employee->created_at?->toIso8601String(),
                ];
            }

            /** @var list<string> $reasons */
            $reasons = [];
            foreach ($group['reasons'] as $reason) {
                $reasons[] = $reason;
            }

            $groups[] = ['reasons' => $reasons, 'employees' => $people];
        }

        return ['groups' => $groups, 'truncated' => $truncated];
    }
}
