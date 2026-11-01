<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Employee;
use App\Services\FileStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Who a shared kiosk terminal says it is talking to: a name, a job title and a
 * face, so the person at the screen and anyone watching can see that the punch
 * went to the right person. Deliberately nothing that identifies them further.
 * The terminal is signed out and anyone can type a code into it, so
 * EmployeeResource, with contact details and date of birth, never goes here.
 *
 * The photo link is signed for five minutes: long enough to show, too short
 * to be worth copying off the screen.
 *
 * @mixin Employee
 */
class KioskPersonResource extends JsonResource
{
    public const PHOTO_SIZE = 400;

    public const PHOTO_MINUTES = 5;

    public function toArray(Request $request): array
    {
        $position = $this->relationLoaded('position') ? $this->position?->title : null;

        return [
            'name' => $this->name,
            'name_am' => $this->name_am,
            'position' => $position,
            'photo_url' => $this->photo(),
        ];
    }

    private function photo(): ?string
    {
        return app(FileStorageService::class)->thumbnailUrlOrNull($this->photo_path, self::PHOTO_SIZE, self::PHOTO_MINUTES);
    }
}
