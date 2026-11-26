<?php

declare(strict_types=1);

namespace App\Services\Backup\GoogleDrive;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;

/**
 * The folder of backups in Google Drive, behind the Flysystem interface, so
 * BackupService treats it exactly like the S3 disk.
 *
 * It knows one directory, `backups/`: a key `backups/<name>` is the file
 * `<name>` in the connected folder. Anything else is refused, which is also
 * what keeps a name from ever being a path or a Drive query.
 */
class GoogleDriveAdapter implements FilesystemAdapter
{
    private const PREFIX = 'backups/';

    public function __construct(private readonly GoogleDriveClient $client) {}

    public function fileExists(string $path): bool
    {
        try {
            return $this->find($this->name($path)) !== null;
        } catch (GoogleDriveException $e) {
            throw UnableToCheckFileExistence::forLocation($path, $e);
        }
    }

    public function directoryExists(string $path): bool
    {
        if (rtrim($path, '/') !== rtrim(self::PREFIX, '/')) {
            return false;
        }

        try {
            $this->client->list();

            return true;
        } catch (GoogleDriveException $e) {
            throw UnableToCheckDirectoryExistence::forLocation($path, $e);
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $contents);
        rewind($stream);

        try {
            $this->writeStream($path, $stream, $config);
        } finally {
            fclose($stream);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        try {
            $this->client->upload($this->name($path), $contents);
        } catch (GoogleDriveException $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function read(string $path): string
    {
        $stream = $this->readStream($path);

        try {
            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    public function readStream(string $path)
    {
        try {
            $file = $this->find($this->name($path));

            if ($file === null) {
                throw new GoogleDriveException('The file is not in the Drive folder.');
            }

            return $this->client->download($file['id']);
        } catch (GoogleDriveException $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    public function delete(string $path): void
    {
        try {
            foreach ($this->client->list($this->name($path)) as $file) {
                $this->client->delete($file['id']);
            }
        } catch (GoogleDriveException $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function deleteDirectory(string $path): void
    {
        throw UnableToDeleteDirectory::atLocation($path, 'The Drive folder is not deleted from here.');
    }

    public function createDirectory(string $path, Config $config): void
    {
        // The folder exists from the moment Drive is connected.
        if (rtrim($path, '/') !== rtrim(self::PREFIX, '/')) {
            throw UnableToCreateDirectory::atLocation($path, 'Only backups/ exists on Drive.');
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'Drive files have no visibility here.');
    }

    public function visibility(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::visibility($path, 'Drive files have no visibility here.');
    }

    public function mimeType(string $path): FileAttributes
    {
        return new FileAttributes($path, mimeType: 'application/octet-stream');
    }

    public function lastModified(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::lastModified($path, 'Not read from Drive.');
    }

    public function fileSize(string $path): FileAttributes
    {
        try {
            $file = $this->find($this->name($path));
        } catch (GoogleDriveException $e) {
            throw UnableToRetrieveMetadata::fileSize($path, $e->getMessage(), $e);
        }

        if ($file === null || $file['size'] === null) {
            throw UnableToRetrieveMetadata::fileSize($path, 'The file is not in the Drive folder.');
        }

        return new FileAttributes($path, $file['size']);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        if (rtrim($path, '/') !== rtrim(self::PREFIX, '/')) {
            return;
        }

        foreach ($this->client->list() as $file) {
            yield new FileAttributes(self::PREFIX.$file['name'], $file['size']);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        throw UnableToMoveFile::fromLocationTo($source, $destination);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        throw UnableToCopyFile::fromLocationTo($source, $destination);
    }

    /** @return array{id: string, name: string, size: int|null}|null */
    private function find(string $name): ?array
    {
        return $this->client->list($name)[0] ?? null;
    }

    /**
     * The file name behind a `backups/<name>` key.
     *
     * @throws GoogleDriveException
     */
    private function name(string $path): string
    {
        $path = ltrim($path, '/');
        $name = str_starts_with($path, self::PREFIX) ? substr($path, strlen(self::PREFIX)) : '';

        if (! preg_match('/^[A-Za-z0-9._-]{1,200}$/', $name)) {
            throw new GoogleDriveException('Drive holds only files directly under backups/.');
        }

        return $name;
    }
}
