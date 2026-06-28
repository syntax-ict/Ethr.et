<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'type' => $this->type,
            'title' => $this->title,
            'file_path' => $this->file_path,
            'file_size' => $this->file_size,
            'mime_type' => $this->mime_type,
            'expiry_date' => $this->expiry_date?->format('Y-m-d'),
            'is_expired' => $this->expiry_date && $this->expiry_date->isPast(),
            'created_at' => $this->created_at,
        ];
    }
}
