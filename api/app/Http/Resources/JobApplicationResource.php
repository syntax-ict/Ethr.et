<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\JobApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An application as HR sees it. The offered salary is pay data, shown only to
 * someone who may see employees' pay.
 *
 * @mixin JobApplication
 */
class JobApplicationResource extends JsonResource
{
    /**
     * Set on a single application: other applications and current employees
     * sharing its e-mail or phone, for HR to judge.
     *
     * @var list<array{kind: string, public_id: string, name: string, vacancy: string|null}>|null
     */
    public ?array $possibleDuplicates = null;

    public function toArray(Request $request): array
    {
        $seesPay = (bool) $request->user()?->hasPermission('employee.viewFinancial');

        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'source' => $this->source,
            'cover_note' => $this->cover_note,
            'stage' => $this->stage->value,
            'vacancy' => $this->whenLoaded('vacancy', fn () => $this->vacancy === null ? null : [
                'public_id' => $this->vacancy->public_id,
                'title' => $this->vacancy->title,
            ]),
            'offer' => $this->offer_start_date === null ? null : [
                'start_date' => $this->offer_start_date->format('Y-m-d'),
                'salary_cents' => $seesPay ? $this->offer_salary_cents : null,
                'notes' => $this->offer_notes,
                'made_by' => $this->whenLoaded('offerMadeBy', fn () => $this->offerMadeBy?->getAttribute('name')),
                'made_by_me' => (bool) ($request->user() !== null && (int) $this->offer_made_by === $request->user()->id),
                'approved_by' => $this->whenLoaded('offerApprovedBy', fn () => $this->offerApprovedBy?->getAttribute('name')),
                'approved_at' => $this->offer_approved_at?->toIso8601String(),
            ],
            'employee' => $this->whenLoaded('employee', fn () => $this->employee === null ? null : [
                'public_id' => $this->employee->public_id,
                'name' => $this->employee->name,
                'employee_code' => $this->employee->employee_code,
            ]),
            'hired_at' => $this->hired_at?->toIso8601String(),
            'closed_reason' => $this->closed_reason,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'erased' => $this->erased_at !== null,
            'events' => JobApplicationEventResource::collection($this->whenLoaded('events')),
            'possible_duplicates' => $this->when($this->possibleDuplicates !== null, fn () => $this->possibleDuplicates),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
