<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates an inline `data:image/...;base64,...` payload — the form a browser
 * canvas produces for a selfie. Applies the same magic-byte guarantee as
 * {@see VerifyFileContent} does for multipart uploads (convention #15).
 */
class Base64Image implements ValidationRule
{
    private const MAGIC_BYTES = [
        'image/jpeg' => ["\xFF\xD8\xFF"],
        'image/png' => ["\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"],
        'image/webp' => ['RIFF'],
    ];

    /**
     * @param  int  $maxBytes  Cap on the *decoded* image, before compression.
     */
    public function __construct(private readonly int $maxBytes = 8_388_608) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('validation.base64_image_invalid'));

            return;
        }

        if (! preg_match('#^data:(image/[a-z0-9.+-]+);base64,(.+)$#is', $value, $matches)) {
            $fail(__('validation.base64_image_invalid'));

            return;
        }

        $mime = strtolower($matches[1]);
        $mime = $mime === 'image/jpg' ? 'image/jpeg' : $mime;

        if (! isset(self::MAGIC_BYTES[$mime])) {
            $fail(__('validation.base64_image_unsupported_type'));

            return;
        }

        $binary = base64_decode($matches[2], true);

        if ($binary === false || $binary === '') {
            $fail(__('validation.base64_image_invalid'));

            return;
        }

        if (strlen($binary) > $this->maxBytes) {
            $fail(__('validation.base64_image_too_large', [
                'max' => (int) round($this->maxBytes / 1024),
            ]));

            return;
        }

        $matched = false;
        foreach (self::MAGIC_BYTES[$mime] as $magic) {
            if (str_starts_with($binary, $magic)) {
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            $fail(__('validation.file_magic_bytes_invalid'));

            return;
        }

        if (@imagecreatefromstring($binary) === false) {
            $fail(__('validation.base64_image_invalid'));
        }
    }
}
