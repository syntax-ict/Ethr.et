<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileStorageService
{
    private string $disk;

    public function __construct(private readonly CurrentTenant $currentTenant)
    {
        $this->disk = 'minio';
    }

    public function upload(UploadedFile $file, string $directory): array
    {
        $tenantPrefix = $this->tenantPrefix();
        $filename = Str::ulid().'.'.$file->getClientOriginalExtension();
        $path = "{$tenantPrefix}/{$directory}/{$filename}";

        Storage::disk($this->disk)->put($path, $file->getContent());

        return [
            'path' => $path,
            'filename' => $filename,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
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
}
