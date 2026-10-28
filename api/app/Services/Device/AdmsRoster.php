<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceCommandType;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceEnrollmentSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Who a ZKTeco ADMS terminal says is enrolled on it.
 *
 * ETHR cannot call an ADMS device, so it cannot read the list the way it reads
 * a Hikvision or a networked ZKTeco. It asks with a QUERY_USERS command, and
 * the answer arrives at the device's next check-in as upload lines
 * (AdmsProtocol::userLines). People enrolled or changed at the terminal arrive
 * the same way as it happens (EnrollUser, ChgUser in the handshake).
 *
 * The firmware never says "that was everyone", so the list is only as fresh
 * as `seen_at`. The one cleanup: when the device confirms a roster request it
 * answered, anyone it did not mention in that answer is no longer on it.
 */
final class AdmsRoster
{
    /** A roster request is not repeated by the device's start-up for this long. */
    public const AUTO_REQUEST_EVERY_HOURS = 24;

    /**
     * Store what an upload said, one row per PIN.
     *
     * @param  array<string, array{pin: string, user: bool, name: string|null, card: string|null, is_admin: bool, fingerprints: int, face: bool}>  $people
     * @return int the number of people recorded
     */
    public function record(Device $device, array $people, ?Carbon $at = null): int
    {
        $at ??= now();
        $recorded = 0;

        foreach ($people as $person) {
            $row = DeviceEnrollmentSnapshot::query()
                ->where('tenant_id', $device->tenant_id)
                ->where('device_id', $device->id)
                ->where('device_user_id', $person['pin'])
                ->first();

            // Template lines alone update a known person and invent nobody.
            if (! $person['user'] && $row === null) {
                continue;
            }

            $row ??= new DeviceEnrollmentSnapshot([
                'tenant_id' => $device->tenant_id,
                'device_id' => $device->id,
                'device_user_id' => $person['pin'],
            ]);

            if ($person['user']) {
                $row->fill([
                    'name' => $person['name'],
                    'card_number' => $person['card'],
                    'is_admin' => $person['is_admin'],
                ]);
            }

            // Only what this upload showed: no template lines means the
            // device did not send them, not that the person has none.
            if ($person['fingerprints'] > 0) {
                $row->fingerprint_count = min($person['fingerprints'], 10);
            }
            if ($person['face']) {
                $row->face_registered = true;
            }

            $row->seen_at = $at;
            $row->save();
            $recorded++;
        }

        return $recorded;
    }

    /** The device confirmed it holds this person, as ETHR sent them. */
    public function enrolled(Device $device, string $pin, ?string $name): void
    {
        $row = $this->rows($device)->where('device_user_id', $pin)->first()
            ?? new DeviceEnrollmentSnapshot([
                'tenant_id' => $device->tenant_id,
                'device_id' => $device->id,
                'device_user_id' => $pin,
            ]);

        $row->name = $name === null || trim($name) === '' ? $row->name : mb_substr(trim($name), 0, 100);
        $row->seen_at = now();
        $row->save();
    }

    /** The device confirmed it removed this person. */
    public function removed(Device $device, string $pin): void
    {
        $this->rows($device)->where('device_user_id', $pin)->delete();
    }

    /**
     * The device confirmed a roster request. Anyone not mentioned since it was
     * sent is no longer on the device, provided the device did answer: with
     * nothing received, the confirmation may have overtaken the data, and the
     * list is left as it was.
     */
    public function answered(Device $device, DeviceCommand $request): void
    {
        $since = $request->sent_at;
        if ($since === null || ! $this->rows($device)->where('seen_at', '>=', $since)->exists()) {
            return;
        }

        $this->rows($device)->where('seen_at', '<', $since)->delete();
    }

    /**
     * @return list<array{device_user_id: string, name: ?string, card_number: ?string, department: ?string, fingerprint_count: ?int, face_registered: ?bool}>
     */
    public function enrollments(Device $device): array
    {
        return $this->rows($device)
            ->orderBy('device_user_id')
            ->get()
            ->map(fn (DeviceEnrollmentSnapshot $row) => [
                'device_user_id' => $row->device_user_id,
                'name' => $row->name,
                'card_number' => $row->card_number,
                'department' => null,
                'fingerprint_count' => $row->fingerprint_count,
                'face_registered' => $row->face_registered,
            ])
            ->values()
            ->all();
    }

    /**
     * When the list last changed, and the roster request still waiting for
     * the device, if any.
     *
     * @return array{received_at: ?Carbon, pending: ?DeviceCommand}
     */
    public function state(Device $device): array
    {
        $received = $this->rows($device)->max('seen_at');

        return [
            'received_at' => $received === null ? null : Carbon::parse($received),
            'pending' => $this->pendingRequest($device),
        ];
    }

    public function pendingRequest(Device $device): ?DeviceCommand
    {
        return $device->commands()
            ->where('type', DeviceCommandType::QUERY_USERS->value)
            ->whereIn('status', [DeviceCommandStatus::PENDING->value, DeviceCommandStatus::SENT->value])
            ->latest('id')
            ->first();
    }

    /** Whether a device starting up should be asked for its list: none held, none asked for lately. */
    public function wantsRequest(Device $device): bool
    {
        if ($this->rows($device)->exists()) {
            return false;
        }

        return ! $device->commands()
            ->where('type', DeviceCommandType::QUERY_USERS->value)
            ->where('created_at', '>=', now()->subHours(self::AUTO_REQUEST_EVERY_HOURS))
            ->exists();
    }

    /** @return Builder<DeviceEnrollmentSnapshot> */
    private function rows(Device $device)
    {
        return DeviceEnrollmentSnapshot::query()
            ->where('tenant_id', $device->tenant_id)
            ->where('device_id', $device->id);
    }
}
