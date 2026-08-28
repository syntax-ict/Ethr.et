<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ExposesPhotoUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DirectoryResource extends JsonResource
{
    use ExposesPhotoUrls;

    public function toArray(Request $request): array
    {
        $photoPath = $this->photo_path;

        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'photo_path' => $photoPath,
            'photo_url' => $this->photoUrl($photoPath),
            'photo_thumb_url' => $this->photoThumbUrl($photoPath),
            'department' => $this->whenLoaded('department', fn () => $this->department?->name),
            'position' => $this->whenLoaded('position', fn () => $this->position?->title),
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->name),
        ];
    }
}
