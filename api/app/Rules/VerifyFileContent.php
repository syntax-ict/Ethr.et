<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class VerifyFileContent implements ValidationRule
{
    private const MAGIC_BYTES = [
        'image/jpeg' => ["\xFF\xD8\xFF"],
        'image/png' => ["\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"],
        'image/gif' => ['GIF87a', 'GIF89a'],
        'image/webp' => ['RIFF'],
        'application/pdf' => ['%PDF'],
        'application/zip' => ["PK\x03\x04"],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ["PK\x03\x04"],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ["PK\x03\x04"],
        'text/csv' => [],
        'text/plain' => [],
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        $declaredMime = $value->getClientMimeType();
        $detectedMime = $value->getMimeType();

        $declaredBase = $this->normalizeType($declaredMime);
        $detectedBase = $this->normalizeType($detectedMime);

        if ($declaredBase !== $detectedBase) {
            $fail(__('validation.file_content_mismatch'));

            return;
        }

        if (isset(self::MAGIC_BYTES[$detectedBase]) && count(self::MAGIC_BYTES[$detectedBase]) > 0) {
            $header = file_get_contents($value->getRealPath(), false, null, 0, 16);
            $matched = false;

            foreach (self::MAGIC_BYTES[$detectedBase] as $magic) {
                if (str_starts_with($header, $magic)) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                $fail(__('validation.file_magic_bytes_invalid'));

                return;
            }
        }

        if (str_starts_with($detectedBase, 'image/')) {
            $this->stripExif($value);
        }
    }

    private function normalizeType(?string $mime): string
    {
        if ($mime === null) {
            return '';
        }

        $mime = strtolower(trim($mime));

        $aliases = [
            'image/jpg' => 'image/jpeg',
            'application/x-zip-compressed' => 'application/zip',
        ];

        return $aliases[$mime] ?? $mime;
    }

    private function stripExif(UploadedFile $file): void
    {
        $mime = $file->getMimeType();
        $path = $file->getRealPath();

        if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
            $image = @imagecreatefromjpeg($path);
            if ($image !== false) {
                imagejpeg($image, $path, 95);
                imagedestroy($image);
            }
        }

        if ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
            $image = @imagecreatefrompng($path);
            if ($image !== false) {
                imagesavealpha($image, true);
                imagepng($image, $path, 9);
                imagedestroy($image);
            }
        }

        if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $image = @imagecreatefromwebp($path);
            if ($image !== false) {
                imagewebp($image, $path, 90);
                imagedestroy($image);
            }
        }
    }
}
