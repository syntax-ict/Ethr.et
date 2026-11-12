<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeExternalIdentity;
use App\Models\ImportMapping;
use App\Support\EmployeeImportRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * What an employee is known as outside ETHR, and where their record came
 * from (One employee identity, PR 11).
 *
 * A person is one record here and several ids elsewhere: the number each
 * attendance terminal knows them by, the id another HR or payroll system
 * gave them. Those links are what stop a later import or a punch from an
 * unfamiliar number making a second person, and until now nobody could see
 * them. Here they are listed, and a wrong one can be confirmed or removed.
 */
class EmployeeIdentityController extends Controller
{
    /**
     * The ids this employee is known by elsewhere, and how their record was made.
     *
     * `created_via` is where the record came from: `manual`, `api`, `import`,
     * `migration`, `device`, `recruitment`, `scim` or `sso`; null for a
     * record older than 2026-11-11. Each identity is an id in one place:
     * `kind` is `device` for an attendance terminal's number or
     * `other_system` for another system's id, and `source` names the
     * terminal or the system when ETHR still knows it. `verified` says a
     * person confirmed the link, not that ETHR inferred it.
     */
    public function index(Employee $employee): JsonResponse
    {
        Gate::authorize('view', $employee);

        $identities = EmployeeExternalIdentity::query()
            ->where('employee_id', $employee->id)
            ->orderBy('id')
            ->get();

        return response()->json($this->payload($employee, $identities->all()));
    }

    /**
     * Confirm that an id elsewhere is this employee.
     *
     * A link ETHR made by itself (a terminal's number matched to an employee
     * number) is unverified, and an unverified link follows a better match
     * if one appears. Confirmed, it stays with this employee until a person
     * moves or removes it.
     */
    public function verify(Employee $employee, EmployeeExternalIdentity $identity): JsonResponse
    {
        Gate::authorize('update', $employee);
        abort_unless($identity->employee_id === $employee->id, 404);

        if ($identity->verified_at === null) {
            $identity->update(['verified_at' => now()]);
            AuditLog::record('identity.verified', $employee, $this->described($identity));
        }

        return response()->json(['verified' => true]);
    }

    /**
     * Remove a link between this employee and an id elsewhere.
     *
     * For a link that is wrong: the terminal's number 14 is somebody else.
     * The employee's record is not changed. The next punch or list that
     * brings the id is matched afresh, by the employee's own details, or
     * held for a person to assign.
     */
    public function destroy(Employee $employee, EmployeeExternalIdentity $identity): JsonResponse
    {
        Gate::authorize('update', $employee);
        abort_unless($identity->employee_id === $employee->id, 404);

        AuditLog::record('identity.unlinked', $employee, $this->described($identity));
        $identity->delete();

        return response()->json(null, 204);
    }

    /** A terminal's or a system's name, when ETHR still knows it. */
    private function named(mixed $name): ?string
    {
        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The link as an audit record keeps it. A national id is never written
     * there, whatever kind of identifier carried it.
     *
     * @return array<string, string|null>
     */
    private function described(EmployeeExternalIdentity $identity): array
    {
        return [
            'source_type' => $identity->source_type,
            'source_ref' => $identity->source_ref,
            'identifier_type' => $identity->identifier_type,
            'identifier_value' => $identity->identifier_type === 'national_id' ? null : $identity->identifier_value,
        ];
    }

    /**
     * @param  list<EmployeeExternalIdentity>  $identities
     * @return array{created_via: string|null, identities: list<array{public_id: string, kind: string, source: string|null, source_type: string, identifier_type: string, identifier_value: string|null, verified: bool, linked_at: string|null}>}
     */
    private function payload(Employee $employee, array $identities): array
    {
        $deviceIds = [];
        $slugs = [];
        foreach ($identities as $identity) {
            if (str_starts_with((string) $identity->source_ref, 'device:')) {
                $deviceIds[] = (int) substr((string) $identity->source_ref, 7);
            } elseif ($identity->source_type === EmployeeImportRow::EXTERNAL_SOURCE) {
                $slugs[] = (string) $identity->source_ref;
            }
        }

        // A terminal that has since been removed still names its links.
        $devices = $deviceIds === [] ? collect() : Device::withTrashed()
            ->where('tenant_id', $employee->tenant_id)
            ->whereIn('id', $deviceIds)
            ->pluck('name', 'id');
        $sources = $slugs === [] ? collect() : ImportMapping::query()
            ->where('tenant_id', $employee->tenant_id)
            ->whereIn('slug', $slugs)
            ->pluck('name', 'slug');

        /** @var list<array{public_id: string, kind: string, source: string|null, source_type: string, identifier_type: string, identifier_value: string|null, verified: bool, linked_at: string|null}> $rows */
        $rows = [];
        foreach ($identities as $identity) {
            $ref = (string) $identity->source_ref;
            $onDevice = str_starts_with($ref, 'device:');
            $source = $this->named($onDevice ? $devices->get((int) substr($ref, 7)) : $sources->get($ref));

            $rows[] = [
                'public_id' => (string) $identity->public_id,
                'kind' => $onDevice ? 'device' : ($identity->source_type === EmployeeImportRow::EXTERNAL_SOURCE ? 'other_system' : 'other'),
                'source' => $source,
                'source_type' => (string) $identity->source_type,
                'identifier_type' => (string) $identity->identifier_type,
                'identifier_value' => $identity->identifier_type === 'national_id' ? null : (string) $identity->identifier_value,
                'verified' => $identity->verified_at !== null,
                'linked_at' => $identity->created_at?->toIso8601String(),
            ];
        }

        return ['created_via' => $employee->created_via, 'identities' => $rows];
    }
}
