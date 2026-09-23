<?php

declare(strict_types=1);

namespace App\Http\Requests\Device\Concerns;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Reject a `serial_number` already registered to a live device of the same
 * adapter type.
 *
 * The database enforces this — see
 * `2026_09_23_000002_add_unique_index_to_device_serial_number`. This hook exists
 * so the API answers **422 with a translated message** instead of letting a
 * `QueryException` become a 500: the constraint is the guarantee, this is the
 * error surface for it.
 *
 * **Why a hook and not a rule.** `Rule::unique(...)` in `rules()` would express
 * it, and `ChangePlanRequest` records why this repository avoids that builder:
 * Scramble derives `src/src/api/generated.ts` from `rules()` and the contract
 * gate fails on drift. `rules()` is left byte-identical here for that reason —
 * `BASELINE.md` §11g cost a red gate learning that the rule is wider than
 * `rules()` alone.
 *
 * **The lookup is deliberately cross-tenant.** Uniqueness is global, because the
 * webhook lookup it protects does not state `tenant_id` and so cannot tell two
 * tenants' identical serials apart. That means this check reads across the
 * boundary on purpose, and the rejection tells the caller a serial exists
 * somewhere on the platform. §11h records that trade rather than hiding it: the
 * message names no tenant, no device and no owner, so what leaks is existence
 * and nothing else, to an authenticated operator registering hardware they
 * physically hold.
 *
 * Soft-deleted devices are excluded, matching the index, which is built on a
 * generated column that goes NULL once `deleted_at` is set. A serial freed by
 * deleting a device can be registered again.
 *
 * @mixin FormRequest
 */
trait ValidatesSerialUniqueness
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $serial = $this->input('serial_number');
            $adapterType = $this->input('adapter_type') ?? $this->deviceBeingUpdated()?->adapter_type;

            // Absent or null is allowed — the column is nullable and the index
            // permits any number of NULLs. A value another rule already
            // rejected is left alone so one field carries one message.
            if (! is_string($serial) || $serial === '' || ! is_string($adapterType)) {
                return;
            }

            if ($validator->errors()->has('serial_number')) {
                return;
            }

            $query = Device::withoutGlobalScope('tenant')
                ->where('serial_number', $serial)
                ->where('adapter_type', $adapterType);

            $current = $this->deviceBeingUpdated();

            if ($current !== null) {
                $query->whereKeyNot($current->getKey());
            }

            if (! $query->exists()) {
                return;
            }

            $validator->errors()->add('serial_number', __('device.serial_in_use'));
        });
    }

    /** The device this request updates, or null when it creates one. */
    private function deviceBeingUpdated(): ?Device
    {
        $device = $this->route('device');

        return $device instanceof Device ? $device : null;
    }
}
