<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ExposesPhotoUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    use ExposesPhotoUrls;

    public function toArray(Request $request): array
    {
        $photoPath = $this->photo_path;

        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'name_am' => $this->name_am,
            'email' => $this->email,
            'phone' => $this->phone,
            'employee_code' => $this->employee_code,
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
            'nationality' => $this->nationality,
            'marital_status' => $this->marital_status,
            'status' => $this->status?->value,
            'hire_date' => $this->hire_date?->format('Y-m-d'),
            'probation_end_date' => $this->probation_end_date?->format('Y-m-d'),
            'confirmation_date' => $this->confirmation_date?->format('Y-m-d'),
            'termination_date' => $this->termination_date?->format('Y-m-d'),
            'salary_cents' => $this->whenHas('salary_cents'),
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
}
