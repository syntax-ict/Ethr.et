<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use App\Services\FileStorageService;

/**
 * Adds signed photo URLs to a resource. `photo_path` alone is an object key,
 * which a browser cannot render — clients need a presigned URL, and the small
 * thumbnail for list and avatar contexts.
 */
trait ExposesPhotoUrls
{
    /**
     * These are two `?string` methods rather than one array-returning helper on
     * purpose. Scramble infers native return types but not `@return array{...}`
     * shapes read through a subscript, so the array form generated
     * `photo_url: string` (non-nullable) in the OpenAPI spec — a lie for every
     * employee without a photo, and one the frontend types then compiled against.
     *
     * Each takes `mixed` because a JsonResource forwards attribute reads to the
     * underlying model, where the column type is not statically known.
     */
    protected function photoUrl(mixed $path): ?string
    {
        return app(FileStorageService::class)->temporaryUrlOrNull($path);
    }

    protected function photoThumbUrl(mixed $path, int $size = 150): ?string
    {
        return app(FileStorageService::class)->thumbnailUrlOrNull($path, $size);
    }
}
