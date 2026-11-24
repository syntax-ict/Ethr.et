<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\NationalityStatus;
use App\Enums\OrgScope;
use App\Http\Resources\Concerns\ExposesPhotoUrls;
use App\Models\Employee;
use App\Services\Attendance\AttendancePolicy;
use App\Support\EmployeeSignIn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Employee */
class EmployeeResource extends JsonResource
{
    use ExposesPhotoUrls;

    public function toArray(Request $request): array
    {
        $photoPath = $this->photo_path;

        // Pay is shown to the employee themselves and to holders of
        // employee.viewFinancial, the permission that exists for exactly this.
        // Until 2026-10-20 every caller with employee.view, supervisors
        // included, read their team's salaries here (blueprint D4).
        $viewer = $request->user();
        $showPay = $viewer !== null
            && ($viewer->hasPermission('employee.viewFinancial')
                || ($viewer->employee_id !== null && (int) $viewer->employee_id === (int) $this->id));

        $mayUpdate = $this->viewerMayUpdate($viewer);

        $signIn = array_key_exists('sign_in_status', $this->resource->getAttributes())
            ? EmployeeSignIn::of(
                $this->resource->getAttribute('sign_in_status'),
                $this->resource->getAttribute('sign_in_status_linked'),
            )
            : null;

        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'name_am' => $this->name_am,
            'email' => $this->email,
            'phone' => $this->phone,
            'employee_code' => $this->employee_code,
            'badge_number' => $this->badge_number,
            'has_kiosk_pin' => $this->kiosk_pin !== null,
            'attendance_mode' => AttendancePolicy::modeOf($this->resource)->value,
            'attendance_device_exempt_until' => $this->attendance_device_exempt_until?->format('Y-m-d'),
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
            'nationality' => $this->nationality,
            'nationality_status' => $this->nationality_status->value ?? NationalityStatus::ETHIOPIAN->value,
            'nationality_needs_review' => (bool) $this->nationality_needs_review,
            'marital_status' => $this->marital_status,
            'address_region_code' => $this->address_region_code,
            'address_region_text' => $this->address_region_text,
            'address_sub_city_code' => $this->address_sub_city_code,
            'address_zone_or_sub_city_text' => $this->address_zone_or_sub_city_text,
            'address_town_code' => $this->address_town_code,
            'address_town_text' => $this->address_town_text,
            'address_woreda' => $this->address_woreda,
            'address_kebele' => $this->address_kebele,
            'address_house_number' => $this->address_house_number,
            'status' => $this->status?->value,
            'hire_date' => $this->hire_date?->format('Y-m-d'),
            'probation_end_date' => $this->probation_end_date?->format('Y-m-d'),
            'confirmation_date' => $this->confirmation_date?->format('Y-m-d'),
            'termination_date' => $this->termination_date?->format('Y-m-d'),
            'outside_employment_framework' => $this->when($mayUpdate, fn () => $this->whenHas('outside_employment_framework')),
            'employment_framework_note' => $this->when($mayUpdate, fn () => $this->whenHas('employment_framework_note')),
            'salary_cents' => $this->when($showPay, fn () => $this->whenHas('salary_cents')),
            'sign_in' => $this->when($signIn !== null, $signIn),
            'photo_path' => $photoPath,
            'photo_url' => $this->photoUrl($photoPath),
            'photo_thumb_url' => $this->photoThumbUrl($photoPath),
            'department' => new DepartmentResource($this->whenLoaded('department')),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'position' => new PositionResource($this->whenLoaded('position')),
            'grade' => new GradeResource($this->whenLoaded('grade')),
            'team' => new TeamResource($this->whenLoaded('team')),
            'cost_center' => new CostCenterResource($this->whenLoaded('costCenter')),
            'supervisor' => new EmployeeSummaryResource($this->whenLoaded('supervisor')),
            'emergency_contacts' => EmergencyContactResource::collection($this->whenLoaded('emergencyContacts')),
            'bank_details' => BankDetailResource::collection($this->whenLoaded('bankDetails')),
            'education' => EducationResource::collection($this->whenLoaded('education')),
            'transitions' => EmployeeTransitionResource::collection($this->whenLoaded('transitions')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Whether the viewer may update this employee: the permission, and reach
     * over the person. Whether someone is outside the organisation's
     * employment law is a legal characterisation of them, so it is shown only
     * to those who maintain the record, as sensitive document types are. This
     * resource is embedded in leave, attendance and payroll entries, many of
     * which load a few columns; reach is judged only from columns that were
     * loaded, and a viewer who is not an all-organisation role is refused when
     * they were not.
     */
    private function viewerMayUpdate(mixed $viewer): bool
    {
        if ($viewer === null || ! $viewer->hasPermission('employee.update')) {
            return false;
        }

        if ($viewer->orgScope() === OrgScope::ALL) {
            return true;
        }

        $loaded = $this->resource->getAttributes();
        foreach (['id', 'branch_id', 'department_id', 'team_id', 'supervisor_id'] as $column) {
            if (! array_key_exists($column, $loaded)) {
                return false;
            }
        }

        return $viewer->canAccessEmployee($this->resource);
    }
}
