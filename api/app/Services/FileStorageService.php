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
        // The configured disk, not a hardcoded one.
        //
        // This read `'minio'` literally, so FILESYSTEM_DISK was inert for every
        // upload, download, thumbnail and delete in the application — the whole
        // of file storage was pinned to a MinIO server whether or not one
        // existed. It looked harmless because production does set
        // FILESYSTEM_DISK=minio, so the literal and the config agreed.
        //
        // They stop agreeing the moment the deployment target has no MinIO to
        // run (shared hosting), and then nothing about pointing the app at
        // local storage or an external S3-compatible endpoint would have taken
        // effect. config/filesystems.php already defines `local`, `public`,
        // `s3` and `minio`; this is what lets the deployment choose.
        //
        // `temporaryUrl()` — see below — works on `local` too: config's local
        // disk sets `serve => true`, which registers Laravel's signed
        // /storage/{path} route. So swapping the disk does not silently break
        // signed downloads.
        $this->disk = (string) config('filesystems.default');
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

    /** Longest edge of a stored organisation logo; ample for a header or a panel. */
    public const LOGO_MAX_DIMENSION = 512;

    /**
     * Store an organisation's logo for its public sign-in page.
     *
     * Every format is decoded and re-encoded as PNG, bounded to
     * {@see LOGO_MAX_DIMENSION} with transparency kept: re-encoding drops EXIF
     * and anything appended to the file, so what is served publicly is only
     * the pixels. `upload()` keeps a PNG's bytes, which is right for documents
     * and wrong for an image every visitor's browser decodes.
     *
     * @return array{path: string, version: string, mime_type: string, size: int}
     */
    public function uploadLogo(UploadedFile $file): array
    {
        $image = $this->decodeImage($file, 'logo');
        $content = $this->encodePng($this->replaceWithScaled($image, self::LOGO_MAX_DIMENSION));

        $version = substr(hash('sha256', $content), 0, 16);
        $path = "{$this->tenantPrefix()}/branding/logo-{$version}.png";

        Storage::disk($this->disk)->put($path, $content);

        return [
            'path' => $path,
            'version' => $version,
            'mime_type' => 'image/png',
            'size' => strlen($content),
        ];
    }

    /** The square mark's canvas edge, and the share of it the image may fill. */
    public const MARK_SIZE = 512;

    public const MARK_SAFE_AREA = 0.8;

    /** Longest edge of a stored cover photo, and the narrower widths it is also encoded at. */
    public const COVER_MAX_DIMENSION = 1920;

    public const COVER_VARIANT_WIDTHS = [1280, 640];

    /**
     * Store one of an organisation's other brand images, re-encoded as the
     * logo is, so only pixels are served:
     *
     * - `logo_dark`: as the logo, a PNG with its transparency, at most 512 px;
     * - `mark`: a 512 px square PNG with the image centred inside 80% of it,
     *   the margin a favicon, an app icon or a link preview needs;
     * - `cover`: a JPEG at most 1920 px on the longest edge, plus 1280 and
     *   640 px wide copies for `srcset`, at a quality a photo keeps.
     *
     * @return array{path: string, version: string, mime_type: string, size: int, width: int, height: int, variants: array<int, string>}
     */
    public function uploadBrandImage(UploadedFile $file, string $kind): array
    {
        $image = $this->decodeImage($file, 'image');
        /** @var array<int, string> $encodedVariants */
        $encodedVariants = [];

        switch ($kind) {
            case 'logo_dark':
                $image = $this->replaceWithScaled($image, self::LOGO_MAX_DIMENSION);
                $content = $this->encodePng($image);
                $extension = 'png';
                break;
            case 'mark':
                $fitted = $this->replaceWithScaled($image, (int) round(self::MARK_SIZE * self::MARK_SAFE_AREA), allowUpscale: true);
                $image = $this->centreOnSquare($fitted, self::MARK_SIZE);
                imagedestroy($fitted);
                $content = $this->encodePng($image);
                $extension = 'png';
                break;
            case 'cover':
                $image = $this->replaceWithScaled($image, self::COVER_MAX_DIMENSION);
                [$content, $encodedVariants] = $this->encodeCover($image);
                $extension = 'jpg';
                break;
            default:
                imagedestroy($image);
                throw new InvalidArgumentException("Unknown brand image kind: {$kind}");
        }

        $width = imagesx($image);
        $height = imagesy($image);
        imagedestroy($image);

        $version = substr(hash('sha256', $content), 0, 16);
        $slug = str_replace('_', '-', $kind);
        $path = "{$this->tenantPrefix()}/branding/{$slug}-{$version}.{$extension}";
        Storage::disk($this->disk)->put($path, $content);

        $variants = [];
        foreach ($encodedVariants as $variantWidth => $bytes) {
            $variantPath = "{$this->tenantPrefix()}/branding/{$slug}-{$version}-{$variantWidth}.{$extension}";
            Storage::disk($this->disk)->put($variantPath, $bytes);
            $variants[$variantWidth] = $variantPath;
        }

        return [
            'path' => $path,
            'version' => $version,
            'mime_type' => $extension === 'png' ? 'image/png' : 'image/jpeg',
            'size' => strlen($content),
            'width' => $width,
            'height' => $height,
            'variants' => $variants,
        ];
    }

    /**
     * Store a news story's cover photo, encoded exactly as the organisation's
     * own cover is (a JPEG at most 1920 px, with 1280 and 640 px copies), under
     * the story's public id and a content hash. NewsCoverController serves it
     * from that path alone, so a new upload is a new URL.
     *
     * @return array{path: string, version: string, width: int, height: int, variants: array<int, string>}
     */
    public function uploadNewsCover(UploadedFile $file, string $storyPublicId): array
    {
        $image = $this->replaceWithScaled($this->decodeImage($file, 'image'), self::COVER_MAX_DIMENSION);
        [$content, $encodedVariants] = $this->encodeCover($image);
        $width = imagesx($image);
        $height = imagesy($image);
        imagedestroy($image);

        $version = substr(hash('sha256', $content), 0, 16);
        $path = self::newsCoverPath($this->currentTenant->publicId(), $storyPublicId, $version);
        Storage::disk($this->disk)->put($path, $content);

        $variants = [];
        foreach ($encodedVariants as $variantWidth => $bytes) {
            $variantPath = self::newsCoverPath($this->currentTenant->publicId(), $storyPublicId, $version, $variantWidth);
            Storage::disk($this->disk)->put($variantPath, $bytes);
            $variants[$variantWidth] = $variantPath;
        }

        return [
            'path' => $path,
            'version' => $version,
            'width' => $width,
            'height' => $height,
            'variants' => $variants,
        ];
    }

    /**
     * Remove a story's cover and its narrower copies, best effort: an orphaned
     * file is storage waste, not a failed request. Called when a cover is
     * replaced or removed and when its story is deleted, so a deleted story's
     * photo stops being served.
     *
     * @param  array{path: string, variants: array<int, string>}  $cover
     */
    public function deleteNewsCover(array $cover): void
    {
        $this->delete($cover['path']);
        foreach ($cover['variants'] as $path) {
            $this->delete($path);
        }
    }

    /**
     * Store a document attached to a news story, as uploaded: a PDF whose
     * content the request has already checked. Kept under the story's public
     * id with a fresh ULID, so a name cannot collide and is never a path. The
     * name it was uploaded under is kept for the download, cleaned of path
     * parts and control characters and ending in `.pdf`.
     *
     * @return array{id: string, name: string, size: int, path: string}
     */
    public function storeNewsFile(UploadedFile $file, string $storyPublicId): array
    {
        $id = (string) Str::ulid();
        $content = $file->getContent();
        $path = "{$this->tenantPrefix()}/news/{$storyPublicId}-files/{$id}.pdf";
        Storage::disk($this->disk)->put($path, $content);

        $name = (string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', basename($file->getClientOriginalName()));
        $name = trim(mb_substr((string) preg_replace('/\.pdf$/i', '', trim($name)), 0, 120));

        return [
            'id' => $id,
            'name' => ($name === '' ? 'document' : $name).'.pdf',
            'size' => strlen($content),
            'path' => $path,
        ];
    }

    /**
     * Where a story's cover, or one of its narrower copies, is stored. One
     * definition for the upload and for NewsCoverController, which reads the
     * file by this path and nothing else.
     */
    public static function newsCoverPath(string $tenantPublicId, string $storyPublicId, string $version, ?int $width = null): string
    {
        return "tenants/{$tenantPublicId}/news/{$storyPublicId}-{$version}".($width === null ? '' : "-{$width}").'.jpg';
    }

    /**
     * A cover photo as JPEG, plus a copy at each of the narrower widths in
     * {@see COVER_VARIANT_WIDTHS} that is smaller than the photo itself.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private function encodeCover(\GdImage $image): array
    {
        $variants = [];
        foreach (self::COVER_VARIANT_WIDTHS as $width) {
            if ($width >= imagesx($image)) {
                continue;
            }
            $narrower = imagescale($image, $width, -1);
            if ($narrower === false) {
                continue;
            }
            $variants[$width] = $this->encodeJpeg($narrower, 82);
            imagedestroy($narrower);
        }

        return [$this->encodeJpeg($image, 85), $variants];
    }

    /**
     * Decode an uploaded image for re-encoding, as true colour with its
     * alpha channel kept. The field name is for the validation message.
     */
    private function decodeImage(UploadedFile $file, string $field): \GdImage
    {
        $this->verifyMimeType($file);

        $image = extension_loaded('gd') ? @imagecreatefromstring($file->getContent()) : false;
        if ($image === false) {
            throw ValidationException::withMessages([
                $field => __('validation.image', ['attribute' => $field]),
            ]);
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    private function encodePng(\GdImage $image): string
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }

    /** A transparent `$edge` square with `$image` centred on it. */
    private function centreOnSquare(\GdImage $image, int $edge): \GdImage
    {
        $canvas = imagecreatetruecolor($edge, $edge);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        $width = imagesx($image);
        $height = imagesy($image);
        imagecopy($canvas, $image, intdiv($edge - $width, 2), intdiv($edge - $height, 2), 0, 0, $width, $height);

        return $canvas;
    }

    /** The bytes of a stored file, or null when it is gone. */
    public function read(string $path): ?string
    {
        return Storage::disk($this->disk)->exists($path) ? Storage::disk($this->disk)->get($path) : null;
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
    private function replaceWithScaled(\GdImage $image, int $maxDimension, bool $allowUpscale = false): \GdImage
    {
        $scaled = $this->scaleToFit($image, $maxDimension, $allowUpscale);

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
