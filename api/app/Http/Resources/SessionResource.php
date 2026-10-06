<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PersonalAccessToken;
use App\Services\Auth\DeviceFingerprint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PersonalAccessToken */
class SessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // Null under session-cookie auth, where there is no stored token to match.
        $currentId = PersonalAccessToken::currentIdFor($request->user());

        // The app authenticates statefully, so `currentAccessToken()` is usually a
        // TransientToken and the id comparison alone would never mark anything as
        // the current device — leaving the user unable to tell which row is the
        // browser they are sitting at. The device cookie identifies it either way.
        $currentDevice = app(DeviceFingerprint::class)->existingHash($request);

        return [
            // The numeric token id is the session handle the client revokes with.
            // Sanctum tokens carry no public_id, and this id is meaningless outside
            // the owning user's own token list, so it leaks nothing about other
            // tenants — convention #4 targets business entities, not auth tokens.
            'id' => $this->id,
            'device' => app(DeviceFingerprint::class)->label($this->user_agent),
            'ip_address' => $this->ip_address,
            'last_used_at' => $this->last_used_at,
            'created_at' => $this->created_at,
            'expires_at' => $this->expires_at,
            // Lets the UI label the row "This device" and disable its revoke button,
            // so a user cannot accidentally sign themselves out while tidying up.
            'is_current' => ($currentId !== null && $currentId === $this->id)
                || ($currentId === null
                    && $currentDevice !== null
                    && $currentDevice === $this->device_hash),
        ];
    }
}
