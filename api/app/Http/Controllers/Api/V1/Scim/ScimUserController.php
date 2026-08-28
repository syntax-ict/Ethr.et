<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Scim;

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Support\EthiopianPhone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScimUserController extends Controller
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant->get();
        $perPage = min((int) $request->query('count', '25'), 100);
        $startIndex = max((int) $request->query('startIndex', '1'), 1);
        $filter = $request->query('filter');

        $query = User::where('tenant_id', $tenant->id)->with('employee');

        if ($filter) {
            $query = $this->applyFilter($query, $filter);
        }

        $total = $query->count();
        $users = $query->skip($startIndex - 1)->take($perPage)->get();

        return response()->json([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => $total,
            'startIndex' => $startIndex,
            'itemsPerPage' => $perPage,
            'Resources' => $users->map(fn (User $user) => $this->toScimUser($user)),
        ]);
    }

    public function show(string $publicId): JsonResponse
    {
        $user = User::where('tenant_id', $this->currentTenant->get()->id)
            ->where('public_id', $publicId)
            ->with('employee')
            ->firstOrFail();

        return response()->json($this->toScimUser($user));
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant->get();
        $data = $request->all();

        $email = $data['emails'][0]['value'] ?? ($data['userName'] ?? null);
        if (! $email) {
            return $this->scimError('userName or emails[0].value is required', 400);
        }

        $existing = User::where('tenant_id', $tenant->id)->where('email', $email)->first();
        if ($existing) {
            return $this->scimError('User already exists', 409, 'uniqueness');
        }

        $ssoSettings = $tenant->ssoSetting;
        $defaultRole = UserRole::tryFrom($ssoSettings?->default_role ?? '') ?? UserRole::EMPLOYEE;

        $user = DB::transaction(function () use ($tenant, $data, $email, $defaultRole) {
            $name = $data['name'] ?? [];
            $fullName = trim(($name['givenName'] ?? '').' '.($name['familyName'] ?? '')) ?: $email;

            $isActive = $data['active'] ?? true;

            $employee = Employee::create([
                'tenant_id' => $tenant->id,
                'name' => $fullName,
                'email' => $email,
                'phone' => EthiopianPhone::canonicalOrRaw($data['phoneNumbers'][0]['value'] ?? null),
                'status' => $isActive ? EmployeeStatus::HIRED : EmployeeStatus::SUSPENDED,
                'hire_date' => now(),
            ]);

            $user = User::create([
                'tenant_id' => $tenant->id,
                'employee_id' => $employee->id,
                'email' => $email,
                'password' => '',
                'role' => $defaultRole,
                'status' => $isActive ? 'active' : 'inactive',
                'locale' => 'en',
            ]);

            AuditLog::record('scim.user_created', $user, [
                'email' => $email,
            ]);

            return $user->load('employee');
        });

        return response()->json($this->toScimUser($user), 201)
            ->header('Location', url("/api/v1/scim/v2/Users/{$user->public_id}"));
    }

    public function update(Request $request, string $publicId): JsonResponse
    {
        $tenant = $this->currentTenant->get();
        $user = User::where('tenant_id', $tenant->id)
            ->where('public_id', $publicId)
            ->with('employee')
            ->firstOrFail();

        $data = $request->all();
        $name = $data['name'] ?? [];

        DB::transaction(function () use ($user, $data, $name) {
            if ($user->employee) {
                $updates = [];
                $fullName = trim(($name['givenName'] ?? '').' '.($name['familyName'] ?? ''));
                if ($fullName) {
                    $updates['name'] = $fullName;
                }
                if (isset($data['phoneNumbers'][0]['value'])) {
                    $updates['phone'] = EthiopianPhone::canonicalOrRaw($data['phoneNumbers'][0]['value']);
                }
                if (isset($data['active'])) {
                    $updates['status'] = $data['active'] ? EmployeeStatus::CONFIRMED : EmployeeStatus::SUSPENDED;
                }
                if ($updates) {
                    $user->employee->update($updates);
                }
            }

            $userUpdates = [];
            if (isset($data['active'])) {
                $userUpdates['status'] = $data['active'] ? 'active' : 'inactive';
            }
            if ($userUpdates) {
                $user->update($userUpdates);
            }

            AuditLog::record('scim.user_updated', $user, [
                'changes' => array_keys($data),
            ]);
        });

        $user->refresh()->load('employee');

        return response()->json($this->toScimUser($user));
    }

    public function destroy(string $publicId): JsonResponse
    {
        $tenant = $this->currentTenant->get();
        $user = User::where('tenant_id', $tenant->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        DB::transaction(function () use ($user) {
            $user->update(['status' => 'inactive']);
            if ($user->employee) {
                $user->employee->update(['status' => EmployeeStatus::SUSPENDED]);
            }

            AuditLog::record('scim.user_deactivated', $user, [
                'email' => $user->email,
            ]);
        });

        return response()->json(null, 204);
    }

    private function toScimUser(User $user): array
    {
        $employee = $user->employee;
        $nameParts = $employee ? explode(' ', $employee->name, 2) : ['', ''];

        return [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
            'id' => $user->public_id,
            'userName' => $user->email,
            'name' => [
                'givenName' => $nameParts[0] ?? '',
                'familyName' => $nameParts[1] ?? '',
                'formatted' => $employee?->name ?? $user->email,
            ],
            'emails' => [
                [
                    'value' => $user->email,
                    'type' => 'work',
                    'primary' => true,
                ],
            ],
            'phoneNumbers' => $employee?->phone ? [
                [
                    'value' => $employee->phone,
                    'type' => 'work',
                ],
            ] : [],
            'active' => $user->status === 'active' || $user->status === 'Active',
            'meta' => [
                'resourceType' => 'User',
                'created' => $user->created_at?->toIso8601String(),
                'lastModified' => $user->updated_at?->toIso8601String(),
                'location' => url("/api/v1/scim/v2/Users/{$user->public_id}"),
            ],
        ];
    }

    private function applyFilter(Builder $query, string $filter): Builder
    {
        if (preg_match('/userName\s+eq\s+"([^"]+)"/i', $filter, $m)) {
            return $query->where('email', $m[1]);
        }
        if (preg_match('/emails\.value\s+eq\s+"([^"]+)"/i', $filter, $m)) {
            return $query->where('email', $m[1]);
        }
        if (preg_match('/externalId\s+eq\s+"([^"]+)"/i', $filter, $m)) {
            return $query->where('public_id', $m[1]);
        }

        return $query;
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
