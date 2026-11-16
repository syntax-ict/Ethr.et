<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A small in-memory Google Drive behind `Http::fake`, enough for the client,
 * the adapter and the console flow: token refresh, folder listing with a name
 * filter, resumable uploads in chunks (308 until the last), download and
 * delete. No test calls Google.
 */
final class FakeGoogleDrive
{
    public const ACCESS_TOKEN = 'ya29.fake-access-token-never-logged';

    /** @var array<string, array{name: string, body: string}> */
    public array $files = [];

    /** @var array<string, array{name: string, body: string}> */
    private array $sessions = [];

    /** @var list<string> */
    public array $contentRanges = [];

    public int $tokenRequests = 0;

    /** When set, every Drive call but the token answers with this status. */
    public ?int $failWith = null;

    public static function install(): self
    {
        $fake = new self;
        Http::fake(fn (Request $request) => $fake->handle($request));

        return $fake;
    }

    public function handle(Request $request): mixed
    {
        $url = $request->url();
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if (str_contains($url, 'oauth2.googleapis.com/token')) {
            $this->tokenRequests++;

            return Http::response(['access_token' => self::ACCESS_TOKEN, 'expires_in' => 3599]);
        }

        if ($this->failWith !== null) {
            return Http::response(['error' => ['message' => 'Drive says no: quota exceeded']], $this->failWith);
        }

        if (str_starts_with($url, 'https://upload.fake.test/')) {
            return $this->chunk($request, basename($path));
        }

        if (str_contains($url, '/upload/drive/v3/files') && $request->method() === 'POST') {
            $session = 's'.(count($this->sessions) + 1);
            $this->sessions[$session] = ['name' => (string) $request['name'], 'body' => ''];

            return Http::response('', 200, ['Location' => 'https://upload.fake.test/'.$session]);
        }

        if ($path === '/drive/v3/files' && $request->method() === 'GET') {
            return Http::response(['files' => $this->matching((string) ($query['q'] ?? ''))]);
        }

        if ($path === '/drive/v3/files' && $request->method() === 'POST') {
            return Http::response(['id' => 'folder-1']);
        }

        if ($path === '/drive/v3/about') {
            return Http::response(['user' => ['emailAddress' => 'owner@example.com']]);
        }

        if (preg_match('#^/drive/v3/files/([^/]+)$#', $path, $m)) {
            $id = rawurldecode($m[1]);

            if ($request->method() === 'DELETE') {
                unset($this->files[$id]);

                return Http::response('', 204);
            }

            return isset($this->files[$id])
                ? Http::response($this->files[$id]['body'], 200)
                : Http::response(['error' => ['message' => 'File not found']], 404);
        }

        return Http::response(['error' => ['message' => 'Unexpected call '.$url]], 500);
    }

    private function chunk(Request $request, string $session): mixed
    {
        $range = (string) ($request->header('Content-Range')[0] ?? '');
        $this->contentRanges[] = $range;

        if (! preg_match('#^bytes (\d+)-(\d+)/(\d+)$#', $range, $m)) {
            return Http::response(['error' => ['message' => 'Bad Content-Range']], 400);
        }

        $this->sessions[$session]['body'] .= $request->body();

        if ((int) $m[2] + 1 < (int) $m[3]) {
            return Http::response('', 308, ['Range' => 'bytes=0-'.$m[2]]);
        }

        $id = 'file-'.(count($this->files) + 1);
        $this->files[$id] = $this->sessions[$session];

        return Http::response(['id' => $id, 'size' => (string) strlen($this->files[$id]['body'])]);
    }

    /** @return list<array{id: string, name: string, size: string}> */
    private function matching(string $q): array
    {
        $name = preg_match("/ and name='((?:[^'\\\\]|\\\\.)*)'/", $q, $m) ? stripslashes($m[1]) : null;
        $found = [];

        foreach ($this->files as $id => $file) {
            if ($name === null || $file['name'] === $name) {
                $found[] = ['id' => $id, 'name' => $file['name'], 'size' => (string) strlen($file['body'])];
            }
        }

        return $found;
    }
}
