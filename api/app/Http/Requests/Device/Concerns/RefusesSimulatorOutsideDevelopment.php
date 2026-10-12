<?php

declare(strict_types=1);

namespace App\Http\Requests\Device\Concerns;

use App\Support\DeviceSimulator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The `mock` adapter is for development and tests only.
 *
 * Its pull invents check-ins and check-outs for up to ten of the tenant's real
 * employees. On production on 2026-10-12 it was the Register form's default
 * adapter, and one pull wrote `biometric` punches — one with a check-out an
 * hour in the future — that reached reports and raised conflicts like any real
 * punch (attendance QA A3, docs/audit/QA-ATTENDANCE-PRODUCTION-2026-10-12.md).
 *
 * A hook rather than a change to `rules()`: Scramble builds the published API
 * contract from `rules()`, and the adapter enum there stays as it is.
 *
 * @mixin FormRequest
 */
trait RefusesSimulatorOutsideDevelopment
{
    protected function refuseSimulatorOutsideDevelopment(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('adapter_type') === 'mock' && ! DeviceSimulator::available()) {
                $validator->errors()->add('adapter_type', __('device.simulator_unavailable'));
            }
        });
    }
}
