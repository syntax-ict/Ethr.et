<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FileStorageService
{
    private string $disk;

    private const IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public function __construct(private readonly CurrentTenant $currentTenant)
    {
        $this->disk = 'minio';
    }

    public function upload(UploadedFile $file, string $directory): array
    {
        $this->verifyMimeType($file);

        $content = $file->getContent();
        $detectedMime = $file->getMimeType();

        if (in_array($detectedMime, self::IMAGE_MIME_TYPES, true)) {
            $content = $this->stripExif($content, $detectedMime);
        }

        $tenantPrefix = $this->tenantPrefix();
        $filename = Str::ulid().'.'.$file->getClientOriginalExtension();
        $path = "{$tenantPrefix}/{$directory}/{$filename}";

        Storage::disk($this->disk)->put($path, $content);

        return [
            'path' => $path,
            'filename' => $filename,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $detectedMime,
            'size' => strlen($content),
        ];
    }

    public function temporaryUrl(string $path, int $minutes = 15): string
    {
        return Storage::disk($this->disk)->temporaryUrl($path, now()->addMinutes($minutes));
    }

    public function delete(string $path): bool
    {
        return Storage::disk($this->disk)->delete($path);
    }

    public function exists(string $path): bool
    {
        return Storage::disk($this->disk)->exists($path);
    }

    private function tenantPrefix(): string
    {
        return 'tenants/'.$this->currentTenant->publicId();
    }

    private function verifyMimeType(UploadedFile $file): void
    {
        $declared = $file->getClientMimeType();
        $actual = $file->getMimeType();

        if ($declared && $actual && $declared !== $actual) {
            $declaredBase = explode('/', $declared)[0];
            $actualBase = explode('/', $actual)[0];

            if ($declaredBase !== $actualBase) {
                throw ValidationException::withMessages([
                    'file' => "File content type mismatch: declared {$declared}, actual {$actual}.",
                ]);
            }
        }
    }

    private function stripExif(string $content, string $mimeType): string
    {
        if (! extension_loaded('gd')) {
            return $content;
        }

        $image = @imagecreatefromstring($content);
        if ($image === false) {
            return $content;
        }

        ob_start();
        match ($mimeType) {
            'image/jpeg' => imagejpeg($image, null, 90),
            'image/png' => imagepng($image),
            'image/gif' => imagegif($image),
            'image/webp' => imagewebp($image, null, 90),
            default => null,
        };
        $stripped = ob_get_clean();
        imagedestroy($image);

        return $stripped ?: $content;
    }
}
