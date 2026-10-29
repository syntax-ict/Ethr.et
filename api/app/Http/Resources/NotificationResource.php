<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\NotificationMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/** @mixin DatabaseNotification */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => class_basename($this->type),
            'data' => NotificationMessage::localise($this->data),
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
