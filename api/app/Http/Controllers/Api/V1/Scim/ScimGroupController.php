<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Scim;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScimGroupController extends Controller
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant->get();
        $perPage = min((int) $request->query('count', '25'), 100);
        $startIndex = max((int) $request->query('startIndex', '1'), 1);
        $filter = $request->query('filter');

        $query = Department::where('tenant_id', $tenant->id);

        if ($filter && preg_match('/displayName\s+eq\s+"([^"]+)"/i', $filter, $m)) {
            $query->where('name', $m[1]);
        }

        $total = $query->count();
        $departments = $query->skip($startIndex - 1)->take($perPage)->get();

        return response()->json([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => $total,
            'startIndex' => $startIndex,
            'itemsPerPage' => $perPage,
            'Resources' => $departments->map(fn (Department $dept) => $this->toScimGroup($dept)),
        ]);
    }

    public function show(string $publicId): JsonResponse
    {
        $dept = Department::where('tenant_id', $this->currentTenant->get()->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        return response()->json($this->toScimGroup($dept));
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant->get();
        $data = $request->all();

        $displayName = $data['displayName'] ?? null;
        if (! $displayName) {
            return $this->scimError('displayName is required', 400);
        }

        $dept = Department::create([
            'tenant_id' => $tenant->id,
            'name' => $displayName,
            'is_active' => true,
        ]);

        if (! empty($data['members'])) {
            $this->syncMembers($dept, $data['members']);
        }

        AuditLog::record('scim.group_created', $dept, [
            'name' => $displayName,
        ]);

        return response()->json($this->toScimGroup($dept), 201)
            ->header('Location', url("/api/v1/scim/v2/Groups/{$dept->public_id}"));
    }

    public function update(Request $request, string $publicId): JsonResponse
    {
        $tenant = $this->currentTenant->get();
        $dept = Department::where('tenant_id', $tenant->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        $data = $request->all();

        DB::transaction(function () use ($dept, $data) {
            if (isset($data['displayName'])) {
                $dept->update(['name' => $data['displayName']]);
            }

            if (isset($data['members'])) {
                $this->syncMembers($dept, $data['members']);
            }

            AuditLog::record('scim.group_updated', $dept, [
                'changes' => array_keys($data),
            ]);
        });

        return response()->json($this->toScimGroup($dept->fresh()));
    }

    public function destroy(string $publicId): JsonResponse
    {
        $tenant = $this->currentTenant->get();
        $dept = Department::where('tenant_id', $tenant->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        $dept->update(['is_active' => false]);

        AuditLog::record('scim.group_deactivated', $dept, [
            'name' => $dept->name,
        ]);

        return response()->json(null, 204);
    }

    /**
     * SCIM `PUT` semantics are full-replace, not merge: the `members` array is
     * the *entire* desired membership, so anyone currently in this department
     * but absent from it must be removed, not just left alone. The previous
     * version only ever added members — an employee removed from the IdP-side
     * group and pushed back down through a PUT could never actually leave the
     * department via SCIM, since nothing here ever cleared `department_id`.
     */
    private function syncMembers(Department $dept, array $members): void
    {
        $publicIds = array_column($members, 'value');

        $newMemberIds = Employee::where('tenant_id', $dept->tenant_id)
            ->whereHas('user', fn ($q) => $q->whereIn('public_id', $publicIds))
            ->pluck('id');

        Employee::where('tenant_id', $dept->tenant_id)
            ->where('department_id', $dept->id)
            ->whereNotIn('id', $newMemberIds)
            ->update(['department_id' => null]);

        if ($newMemberIds->isNotEmpty()) {
            Employee::whereIn('id', $newMemberIds)->update(['department_id' => $dept->id]);
        }
    }

    private function toScimGroup(Department $dept): array
    {
        $members = Employee::where('department_id', $dept->id)
            ->with('user')
            ->get()
            ->filter(fn ($emp) => $emp->user)
            ->map(fn ($emp) => [
                'value' => $emp->user->public_id,
                'display' => $emp->name,
            ])->values()->all();

        return [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:Group'],
            'id' => $dept->public_id,
            'displayName' => $dept->name,
            'members' => $members,
            'meta' => [
                'resourceType' => 'Group',
                'created' => $dept->created_at?->toIso8601String(),
                'lastModified' => $dept->updated_at?->toIso8601String(),
                'location' => url("/api/v1/scim/v2/Groups/{$dept->public_id}"),
            ],
        ];
    }

    private function scimError(string $detail, int $status, string $scimType = 'invalidValue'): JsonResponse
    {
        return response()->json([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
            'detail' => $detail,
            'scimType' => $scimType,
            'status' => $status,
        ], $status);
    }
}
