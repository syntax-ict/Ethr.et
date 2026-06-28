<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'subdomain' => $this->subdomain,
            'type' => $this->type,
            'status' => $this->status,
            'default_locale' => $this->default_locale,
            'timezone' => $this->timezone,
            'ethiopian_calendar' => $this->ethiopian_calendar,
            'logo_path' => $this->logo_path,
            'theme' => $this->theme,
            'created_at' => $this->created_at,
        ];
    }
}
