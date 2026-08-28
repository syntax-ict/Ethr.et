<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin EmployeeDocument */
class EmployeeDocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // Resolved once. The `date` cast makes this a Carbon at runtime, but its
        // static type stays string (schema-derived), so parsing here keeps the
        // date arithmetic below both correct and analysable.
        $expiry = $this->expiry_date === null ? null : Carbon::parse($this->expiry_date);

        $employee = $this->employee;
        $employee = $employee instanceof Employee ? $employee : null;

        return [
            'public_id' => $this->public_id,
            'type' => $this->type,
            'title' => $this->title,
            'file_path' => $this->file_path,
            'file_size' => $this->file_size,
            'mime_type' => $this->mime_type,
            'expiry_date' => $expiry?->format('Y-m-d'),
            'is_expired' => $expiry !== null && $expiry->isPast(),
            // Drives the S12 badge rule: amber within 30 days, red once expired.
            // Mutually exclusive with is_expired so the UI never has to choose.
            'expires_soon' => $expiry !== null
                && $expiry->isFuture()
                && $expiry->lessThanOrEqualTo(now()->addDays(30)),
            'days_until_expiry' => $expiry === null
                ? null
                : (int) now()->startOfDay()->diffInDays($expiry, false),
            // Populated only on the tenant-wide expiring list, where the reader
            // needs to know whose document is lapsing.
            'employee_name' => $this->whenLoaded('employee', fn () => $employee?->name),
            'employee_public_id' => $this->whenLoaded('employee', fn () => $employee?->public_id),
            'created_at' => $this->created_at,
        ];
    }
}
