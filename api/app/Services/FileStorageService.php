<?php

declare(strict_types=1);

namespace App\Services;

use App\Rules\Base64Image;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class FileStorageService
{
    private string $disk;

    private const IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    /** Longest-edge cap applied to stored photos before compression. */
    public const PHOTO_MAX_DIMENSION = 1600;

    /** Byte budget for a stored profile photo (PHASE_09 S37). */
    public const PHOTO_MAX_BYTES = 512_000;

    /** Byte budget for an attendance selfie — smaller, it is captured on mobile data. */
    public const SELFIE_MAX_BYTES = 204_800;

    /** Longest edge for a selfie; enough for face verification, cheap to sync. */
    public const SELFIE_MAX_DIMENSION = 1080;

    /** Thumbnail edges generated alongside every stored photo. */
    public const THUMBNAIL_SIZES = [150, 400];

    /** Presigned URLs are reused for this long instead of being re-signed per request. */
    private const URL_CACHE_MINUTES = 5;

    public function __construct(private readonly CurrentTenant $currentTenant)
    {
        $this->disk = 'minio';
    }

    public function upload(UploadedFile $file, string $directory): array
    {
        $this->verifyMimeType($file);

        $content = $file->getContent();
        $detectedMime = $file->getMimeType();
        $extension = $file->getClientOriginalExtension();
        $variants = [];

        if (in_array($detectedMime, self::IMAGE_MIME_TYPES, true)) {
            $content = $this->stripExif($content, $detectedMime);

            // Photos are re-encoded to a bounded JPEG; other images (logos with
            // transparency, GIFs) keep their format and are only EXIF-stripped.
            if ($detectedMime === 'image/jpeg' || $detectedMime === 'image/webp') {
                $content = $this->compressImage($content, self::PHOTO_MAX_DIMENSION, self::PHOTO_MAX_BYTES);
                $detectedMime = 'image/jpeg';
                $extension = 'jpg';
            }
        }

        $tenantPrefix = $this->tenantPrefix();
        $directory = $this->safeDirectory($directory);
        $filename = Str::ulid().'.'.$extension;
        $path = "{$tenantPrefix}/{$directory}/{$filename}";

        Storage::disk($this->disk)->put($path, $content);

        if ($detectedMime === 'image/jpeg') {
            $variants = $this->storeThumbnails($content, $path);
        }

        return [
            'path' => $path,
            'filename' => $filename,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $detectedMime,
            'size' => strlen($content),
            'variants' => $variants,
        ];
    }

    /**
     * Store an inline `data:image/...;base64,...` payload (a canvas-captured
     * selfie) as a compressed JPEG. Validate with {@see Base64Image}
     * before calling — this method assumes the payload already parsed cleanly.
     *
     * @return array{path: string, filename: string, mime_type: string, size: int}
     */
    public function uploadDataUrlImage(
        string $dataUrl,
        string $directory,
        int $maxDimension = self::SELFIE_MAX_DIMENSION,
        int $maxBytes = self::SELFIE_MAX_BYTES,
    ): array {
        if (! preg_match('#^data:image/[a-z0-9.+-]+;base64,(.+)$#is', $dataUrl, $matches)) {
            throw ValidationException::withMessages([
                'photo' => __('validation.base64_image_invalid'),
            ]);
        }

        $binary = base64_decode($matches[1], true);

        if ($binary === false || $binary === '' || @imagecreatefromstring($binary) === false) {
            throw ValidationException::withMessages([
                'photo' => __('validation.base64_image_invalid'),
            ]);
        }

        $content = $this->compressImage($binary, $maxDimension, $maxBytes);

        $directory = $this->safeDirectory($directory);
        $filename = Str::ulid().'.jpg';
        $path = "{$this->tenantPrefix()}/{$directory}/{$filename}";

        Storage::disk($this->disk)->put($path, $content);

        return [
            'path' => $path,
            'filename' => $filename,
            'mime_type' => 'image/jpeg',
            'size' => strlen($content),
        ];
    }

    public function temporaryUrl(string $path, int $minutes = 15): string
    {
        // Re-signing the same object on every request is pure overhead; reuse the
        // URL for a window well inside its own validity.
        $ttl = min(self::URL_CACHE_MINUTES, $minutes);

        return Cache::remember(
            'file-url:'.$this->disk.':'.$minutes.':'.sha1($path),
            now()->addMinutes($ttl),
            fn (): string => Storage::disk($this->disk)->temporaryUrl($path, now()->addMinutes($minutes)),
        );
    }

    /**
     * Signed URL for an optional path. Returns null instead of throwing so a
     * missing photo — or a storage backend that cannot sign — degrades to the
     * initials avatar rather than failing the whole listing. Accepts `mixed`
     * because callers read the path off a model attribute.
     */
    public function temporaryUrlOrNull(mixed $path, int $minutes = 15): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        try {
            return $this->temporaryUrl($path, $minutes);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Signed URL for a stored thumbnail. Existence is not checked — an object
     * HEAD per row would be an N+1 across a listing; clients fall back to the
     * full-size URL if a legacy photo predates thumbnail generation.
     */
    public function thumbnailUrlOrNull(mixed $path, int $size, int $minutes = 15): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        return $this->temporaryUrlOrNull($this->thumbnailPath($path, $size), $minutes);
    }

    /**
     * Deterministic path of a stored thumbnail — no schema column needed.
     */
    public function thumbnailPath(string $path, int $size): string
    {
        $directory = dirname($path);
        $name = pathinfo($path, PATHINFO_FILENAME);

        return "{$directory}/thumbs/{$name}_{$size}.jpg";
    }

    public function delete(string $path): bool
    {
        return Storage::disk($this->disk)->delete($path);
    }

    public function exists(string $path): bool
    {
        return Storage::disk($this->disk)->exists($path);
    }

    /**
     * Reject any directory that could climb out of the tenant prefix.
     *
     * The prefix is the *only* thing separating one tenant's objects from
     * another's — one shared bucket, no per-tenant IAM policy — so a `..`
     * segment defeats the entire isolation model. It is not theoretical:
     * uploading with the directory `documents/../..` produced the key
     * `tenants/{public_id}/documents/../../file.pdf`, which Flysystem
     * normalised on write to `tenants/file.pdf` — outside the tenant's space
     * and beside every other tenant's folder.
     *
     * No current caller passes anything attacker-controlled (directories are
     * literals, or interpolate a ULID from a tenant-scoped bound model), so
     * this closes a latent hole rather than a live one. It throws rather than
     * silently sanitising: a caller asking for a traversal has a bug, and
     * quietly rewriting their path would hide it.
     */
    private function safeDirectory(string $directory): string
    {
        $normalised = trim(str_replace('\\', '/', $directory), '/');

        foreach (explode('/', $normalised) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException(
                    'Storage directory must not contain traversal or empty segments.'
                );
            }
        }

        return $normalised;
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

    /**
     * Downscale to `$maxDimension` on the longest edge, then step JPEG quality
     * down until the encoded image fits `$maxBytes`. Returns the original bytes
     * unchanged when GD is unavailable or the image cannot be decoded.
     */
    private function compressImage(string $content, int $maxDimension, int $maxBytes): string
    {
        if (! extension_loaded('gd')) {
            return $content;
        }

        $image = @imagecreatefromstring($content);
        if ($image === false) {
            return $content;
        }

        $image = $this->replaceWithScaled($image, $maxDimension);

        $encoded = $content;
        foreach ([85, 75, 65, 55, 45] as $quality) {
            $encoded = $this->encodeJpeg($image, $quality);

            if (strlen($encoded) <= $maxBytes) {
                imagedestroy($image);

                return $encoded;
            }
        }

        // Still over budget at the lowest quality — shrink the canvas instead of
        // degrading quality further, which is where artefacts become obvious.
        $width = imagesx($image);
        for ($step = 0; $step < 4 && strlen($encoded) > $maxBytes; $step++) {
            $width = (int) round($width * 0.75);
            $image = $this->replaceWithScaled($image, max($width, 200));
            $encoded = $this->encodeJpeg($image, 60);
        }

        imagedestroy($image);

        return $encoded;
    }

    /**
     * @return array<int, string> thumbnail edge size => stored path
     */
    private function storeThumbnails(string $content, string $path): array
    {
        if (! extension_loaded('gd')) {
            return [];
        }

        $image = @imagecreatefromstring($content);
        if ($image === false) {
            return [];
        }

        $variants = [];

        foreach (self::THUMBNAIL_SIZES as $size) {
            $thumb = $this->scaleToFit($image, $size, allowUpscale: false);
            $thumbPath = $this->thumbnailPath($path, $size);

            Storage::disk($this->disk)->put($thumbPath, $this->encodeJpeg($thumb, 82));

            if ($thumb !== $image) {
                imagedestroy($thumb);
            }

            $variants[$size] = $thumbPath;
        }

        imagedestroy($image);

        return $variants;
    }

    /**
     * Scale in place: frees the source handle when a new one is produced. A full
     * resolution photo is tens of megabytes as a GD resource, so holding on to
     * superseded copies exhausts the PHP memory limit.
     */
    private function replaceWithScaled(\GdImage $image, int $maxDimension): \GdImage
    {
        $scaled = $this->scaleToFit($image, $maxDimension);

        if ($scaled !== $image) {
            imagedestroy($image);
        }

        return $scaled;
    }

    private function scaleToFit(\GdImage $image, int $maxDimension, bool $allowUpscale = false): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest <= $maxDimension && ! $allowUpscale) {
            return $image;
        }

        $ratio = $maxDimension / $longest;
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $resized = imagescale($image, $newWidth, $newHeight);

        return $resized === false ? $image : $resized;
    }

    private function encodeJpeg(\GdImage $image, int $quality): string
    {
        // JPEG has no alpha channel; flatten onto white so transparent PNG
        // sources do not come out with black backgrounds.
        $flattened = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($flattened, 0, 0, (int) imagecolorallocate($flattened, 255, 255, 255));
        imagecopy($flattened, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        ob_start();
        imagejpeg($flattened, null, $quality);
        $encoded = (string) ob_get_clean();

        imagedestroy($flattened);

        return $encoded;
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
