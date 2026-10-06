<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Directory;

use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DirectoryResource;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DirectoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // Current staff only. The directory is readable by every signed-in
        // user and carries phone and e-mail, so listing people who have left
        // would keep publishing a former employee's contact details to the
        // whole organisation. See EmployeeStatus::current() for which count.
        $query = Employee::query()
            ->select([
                'id', 'public_id', 'tenant_id', 'name',
                'phone', 'email', 'photo_path',
                'department_id', 'position_id', 'branch_id',
            ])
            ->whereIn('status', EmployeeStatus::current())
            ->with(['department:id,name', 'position:id,title', 'branch:id,name']);

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->has('filter.department_id')) {
            $dep = Department::where('public_id', $request->input('filter.department_id'))->first();
            if ($dep) {
                $query->where('department_id', $dep->id);
            }
        }

        $employees = $query->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return DirectoryResource::collection($employees);
    }
}
