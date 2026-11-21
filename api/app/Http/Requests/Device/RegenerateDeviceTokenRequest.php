<?php

declare(strict_types=1);

namespace App\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Why a terminal's address is being replaced. A request with no reason is
 * read as `lost`, the one that closes the old address at once: a cached page
 * that still posts nothing must not leave a stolen address open.
 */
class RegenerateDeviceTokenRequest extends FormRequest
{
    public const LOST = 'lost';

    public const MOVING = 'moving';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', Rule::in([self::LOST, self::MOVING])],
        ];
    }

    public function reason(): string
    {
        return $this->validated('reason') ?? self::LOST;
    }
}
