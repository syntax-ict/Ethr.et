<?php

declare(strict_types=1);

namespace App\Services\Backup\GoogleDrive;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google Drive's REST API for the one folder the backups live in, over
 * Laravel's HTTP client. No Google SDK: it is large, and composer installs on
 * this project are fragile.
 *
 * All hosts are fixed Google hosts. Scope is `drive.file`, so only files this
 * app created are visible, and a listing of the folder is exactly its own
 * backups.
 *
 * An upload is resumable and sent in chunks read from the stream one at a
 * time, so memory stays near one chunk on a 128 MB host.
 */
class GoogleDriveClient
{
    private const API = 'https://www.googleapis.com/drive/v3';

    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    /** Google requires every chunk but the last to be a multiple of this. */
    private const GRANULE = 262144;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $refreshToken,
        private readonly string $folderId,
        private readonly ?int $chunkBytes = null,
    ) {}

    /**
     * Use a token just obtained from the code exchange, so the first calls of
     * a new connection do not spend a refresh.
     */
    public function rememberAccessToken(string $token, int $expiresIn): void
    {
        Cache::put($this->tokenKey(), Crypt::encryptString($token), max(60, $expiresIn - 120));
    }

    public function forgetAccessToken(): void
    {
        Cache::forget($this->tokenKey());
    }

    /** @throws GoogleDriveException */
    public function createFolder(string $name): string
    {
        $json = $this->json($this->send(fn (PendingRequest $r) => $r->post(self::API.'/files?fields=id', [
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
        ])));

        return (string) $json['id'];
    }

    /**
     * The account's email, shown in the console. Google's reference lists
     * `drive.file` among the scopes about.get accepts, but this is only a
     * label, so any failure gives null and the connection carries on.
     */
    public function accountEmail(): ?string
    {
        try {
            $response = $this->send(fn (PendingRequest $r) => $r->get(self::API.'/about', ['fields' => 'user(emailAddress)']));
            $email = $response->successful() ? $response->json('user.emailAddress') : null;

            return is_string($email) && $email !== '' ? mb_substr($email, 0, 255) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Files of the folder, optionally only those called `$name`.
     *
     * @return list<array{id: string, name: string, size: int|null}>
     *
     * @throws GoogleDriveException
     */
    public function list(?string $name = null): array
    {
        $query = "'{$this->folderId}' in parents and trashed=false";
        if ($name !== null) {
            $query .= " and name='".str_replace(['\\', "'"], ['\\\\', "\\'"], $name)."'";
        }

        $files = [];
        $page = null;

        do {
            $params = ['q' => $query, 'fields' => 'nextPageToken,files(id,name,size)', 'pageSize' => 1000];
            if ($page !== null) {
                $params['pageToken'] = $page;
            }

            $json = $this->json($this->send(fn (PendingRequest $r) => $r->get(self::API.'/files', $params)));

            foreach ((array) ($json['files'] ?? []) as $file) {
                $files[] = [
                    'id' => (string) $file['id'],
                    'name' => (string) $file['name'],
                    'size' => isset($file['size']) ? (int) $file['size'] : null,
                ];
            }

            $page = is_string($json['nextPageToken'] ?? null) ? $json['nextPageToken'] : null;
        } while ($page !== null);

        return $files;
    }

    /**
     * Upload a stream as `$name` in the folder. A file already called that is
     * removed once the new one is whole, never before.
     *
     * @param  resource  $stream
     * @return array{id: string, size: int|null}
     *
     * @throws GoogleDriveException
     */
    public function upload(string $name, $stream): array
    {
        $previous = $this->list($name);

        [$stream, $total] = $this->sizedStream($stream);

        $init = $this->send(fn (PendingRequest $r) => $r->withHeaders([
            'X-Upload-Content-Type' => 'application/octet-stream',
            'X-Upload-Content-Length' => (string) $total,
        ])->post(self::UPLOAD.'?uploadType=resumable&fields=id,size', [
            'name' => $name,
            'parents' => [$this->folderId],
        ]));

        $session = $init->header('Location');
        if (! $init->successful() || $session === '') {
            throw new GoogleDriveException($init->successful() ? 'Google did not open an upload session.' : GoogleDriveOAuth::errorMessage($init));
        }

        $done = $this->sendChunks($session, $stream, $total);

        foreach ($previous as $old) {
            if ($old['id'] !== $done['id']) {
                $this->delete($old['id']);
            }
        }

        return $done;
    }

    /**
     * @return resource
     *
     * @throws GoogleDriveException
     */
    public function download(string $id)
    {
        $response = $this->send(fn (PendingRequest $r) => $r->timeout((int) config('backup.google_drive.chunk_timeout', 120))
            ->get(self::API.'/files/'.rawurlencode($id), ['alt' => 'media']));

        if (! $response->successful()) {
            throw new GoogleDriveException(GoogleDriveOAuth::errorMessage($response));
        }

        $stream = $response->resource();
        rewind($stream);

        return $stream;
    }

    /** @throws GoogleDriveException */
    public function delete(string $id): void
    {
        $response = $this->send(fn (PendingRequest $r) => $r->delete(self::API.'/files/'.rawurlencode($id)));

        // Already gone is what was asked for.
        if (! $response->successful() && $response->status() !== 404) {
            throw new GoogleDriveException(GoogleDriveOAuth::errorMessage($response));
        }
    }

    /**
     * @param  resource  $stream
     * @return array{id: string, size: int|null}
     */
    private function sendChunks(string $session, $stream, int $total): array
    {
        if ($total === 0) {
            return $this->finished($this->putChunk($session, '', null));
        }

        $chunk = $this->chunkSize();
        $offset = 0;
        // A resume can ask for bytes again; this only stops a server that
        // never moves forward from holding the run.
        $budget = (int) ceil($total / $chunk) * 3 + 10;

        while ($budget-- > 0) {
            fseek($stream, $offset);
            $body = $this->readChunk($stream, min($chunk, $total - $offset));

            if ($body === '') {
                throw new GoogleDriveException('The file ended before its size said it would.');
            }

            $last = $offset + strlen($body) - 1;
            $response = $this->putChunk($session, $body, "bytes {$offset}-{$last}/{$total}");

            if (in_array($response->status(), [200, 201], true)) {
                return $this->finished($response);
            }

            if ($response->status() !== 308) {
                throw new GoogleDriveException(GoogleDriveOAuth::errorMessage($response));
            }

            // 308 means "resume": Range says how much Google holds.
            $offset = preg_match('/bytes=0-(\d+)/', $response->header('Range'), $m) ? ((int) $m[1]) + 1 : 0;
        }

        throw new GoogleDriveException('Google kept asking for the same part of the file.');
    }

    private function putChunk(string $session, string $body, ?string $range): Response
    {
        try {
            return Http::connectTimeout((int) config('backup.google_drive.connect_timeout', 10))
                ->timeout((int) config('backup.google_drive.chunk_timeout', 120))
                ->withHeaders($range === null ? [] : ['Content-Range' => $range])
                ->withBody($body, 'application/octet-stream')
                // 308 here is "resume", not a redirect: Guzzle must not follow it.
                ->withoutRedirecting()
                ->retry(2, 500, fn (?Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()), throw: false)
                ->put($session);
        } catch (ConnectionException $e) {
            throw new GoogleDriveException('Could not reach Google: '.mb_substr($e->getMessage(), 0, 200), previous: $e);
        }
    }

    /** @return array{id: string, size: int|null} */
    private function finished(Response $response): array
    {
        if (! $response->successful() || ! is_string($response->json('id'))) {
            throw new GoogleDriveException(GoogleDriveOAuth::errorMessage($response));
        }

        $size = $response->json('size');

        return ['id' => (string) $response->json('id'), 'size' => $size === null ? null : (int) $size];
    }

    /** @param  resource  $stream */
    private function readChunk($stream, int $want): string
    {
        $buffer = '';

        while (strlen($buffer) < $want && ! feof($stream)) {
            $read = fread($stream, $want - strlen($buffer));
            if ($read === false || $read === '') {
                break;
            }
            $buffer .= $read;
        }

        return $buffer;
    }

    /**
     * The stream and its length. A stream that cannot report its size is
     * spooled to a temporary file first; Google wants the total up front.
     *
     * @param  resource  $stream
     * @return array{0: resource, 1: int}
     */
    private function sizedStream($stream): array
    {
        $size = fstat($stream)['size'] ?? null;
        $seekable = (bool) (stream_get_meta_data($stream)['seekable'] ?? false);

        if (is_int($size) && $size > 0 && $seekable) {
            return [$stream, $size];
        }

        $spool = tmpfile();
        $copied = stream_copy_to_stream($stream, $spool);
        rewind($spool);

        return [$spool, (int) $copied];
    }

    private function chunkSize(): int
    {
        $bytes = $this->chunkBytes ?? (int) config('backup.google_drive.chunk_bytes', 8 * 1024 * 1024);

        return max(self::GRANULE, intdiv($bytes, self::GRANULE) * self::GRANULE);
    }

    /**
     * An authenticated call. A 401 means the cached token went stale early, so
     * it is replaced once and the call repeated.
     *
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws GoogleDriveException
     */
    private function send(callable $call): Response
    {
        try {
            $response = $call($this->request($this->accessToken()));

            if ($response->status() === 401) {
                $this->forgetAccessToken();
                $response = $call($this->request($this->accessToken()));
            }

            return $response;
        } catch (ConnectionException $e) {
            throw new GoogleDriveException('Could not reach Google: '.mb_substr($e->getMessage(), 0, 200), previous: $e);
        }
    }

    private function request(string $token): PendingRequest
    {
        return GoogleDriveOAuth::http()->withToken($token);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws GoogleDriveException
     */
    private function json(Response $response): array
    {
        if (! $response->successful()) {
            throw new GoogleDriveException(GoogleDriveOAuth::errorMessage($response));
        }

        return (array) $response->json();
    }

    /** @throws GoogleDriveException */
    private function accessToken(): string
    {
        $cached = Cache::get($this->tokenKey());

        if (is_string($cached)) {
            try {
                return Crypt::decryptString($cached);
            } catch (Throwable) {
                // Falls through to a fresh token.
            }
        }

        $fresh = GoogleDriveOAuth::refresh($this->clientId, $this->clientSecret, $this->refreshToken);
        $this->rememberAccessToken($fresh['access_token'], $fresh['expires_in']);

        return $fresh['access_token'];
    }

    private function tokenKey(): string
    {
        return 'backup-drive:token:'.hash('sha256', $this->clientId.'|'.$this->refreshToken);
    }
}
