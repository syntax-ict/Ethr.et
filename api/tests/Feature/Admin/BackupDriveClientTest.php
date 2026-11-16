<?php

declare(strict_types=1);

use App\Models\BackupDriveSetting;
use App\Models\BackupStorageSetting;
use App\Services\Backup\BackupCipher;
use App\Services\Backup\BackupDriveDisk;
use App\Services\Backup\BackupStorageDisk;
use App\Services\Backup\GoogleDrive\GoogleDriveClient;
use App\Services\Backup\GoogleDrive\GoogleDriveException;
use App\Support\OutboundHost;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeGoogleDrive;

/**
 * The Google Drive client and the Flysystem adapter over it, against an
 * in-memory fake of Google (Tests\Support\FakeGoogleDrive). Nothing here
 * reaches Google, and nothing proves Google's real behaviour: that waits for
 * the owner to connect a real account.
 */
function driveClient(int $chunk = 262144): GoogleDriveClient
{
    return new GoogleDriveClient('client.apps.googleusercontent.com', 'client-secret', 'refresh-token-1', 'folder-1', $chunk);
}

function driveTempFile(int $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'drv');
    file_put_contents($path, random_bytes($bytes));

    return $path;
}

beforeEach(function () {
    Cache::flush();
    config(['backup.google_drive.chunk_bytes' => 262144]);
});

it('fetches an access token once and reuses it until it is nearly out of date', function () {
    $drive = FakeGoogleDrive::install();
    $client = driveClient();

    $client->list();
    $client->list();
    $client->list('a.txt');

    expect($drive->tokenRequests)->toBe(1);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/token')
        && $r['grant_type'] === 'refresh_token'
        && $r['refresh_token'] === 'refresh-token-1');
});

it('replaces a token Google no longer accepts and repeats the call once', function () {
    $stale = 0;
    $drive = new FakeGoogleDrive;
    Http::fake(function (Request $request) use ($drive, &$stale) {
        if (str_contains($request->url(), '/drive/v3/files') && $stale++ === 0) {
            return Http::response(['error' => ['message' => 'Invalid Credentials']], 401);
        }

        return $drive->handle($request);
    });

    expect(driveClient()->list())->toBe([])
        ->and($drive->tokenRequests)->toBe(2);
});

it('uploads in 256 KiB multiples with the right Content-Range, and treats 308 as resume, not a redirect', function () {
    $drive = FakeGoogleDrive::install();
    $path = driveTempFile(600000);

    $stream = fopen($path, 'rb');
    $done = driveClient()->upload('ethr-1.tar.gz.ethrbak', $stream);
    fclose($stream);

    expect($drive->contentRanges)->toBe([
        'bytes 0-262143/600000',
        'bytes 262144-524287/600000',
        'bytes 524288-599999/600000',
    ])
        ->and($drive->files[$done['id']]['body'])->toBe(file_get_contents($path))
        ->and($done['size'])->toBe(600000);

    unlink($path);
});

it('keeps asking Google where it got to when a chunk was only partly kept', function () {
    $state = ['calls' => 0];
    $held = '';
    Http::fake(function (Request $request) use (&$state, &$held) {
        $url = $request->url();
        if (str_contains($url, 'oauth2.googleapis.com')) {
            return Http::response(['access_token' => 't', 'expires_in' => 3599]);
        }
        if (str_contains($url, '/drive/v3/files') && $request->method() === 'GET') {
            return Http::response(['files' => []]);
        }
        if (str_contains($url, '/upload/drive/v3/files')) {
            return Http::response('', 200, ['Location' => 'https://upload.fake.test/s1']);
        }

        $state['calls']++;
        preg_match('#^bytes (\d+)-(\d+)/(\d+)$#', $request->header('Content-Range')[0], $m);

        // The first reply says only the first 262144 bytes are held, though
        // 300000 arrived; the client must resend from there.
        if ($state['calls'] === 1) {
            $held = substr($request->body(), 0, 262144);

            return Http::response('', 308, ['Range' => 'bytes=0-262143']);
        }

        $held .= $request->body();

        return Http::response(['id' => 'f1', 'size' => (string) strlen($held)]);
    });

    $path = driveTempFile(300000);
    $stream = fopen($path, 'rb');
    // A chunk of 512 KiB would take the whole file at once; the first reply
    // proves the resume path.
    $done = (new GoogleDriveClient('c', 's', 'r', 'folder-1', 524288))->upload('x.ethrbak', $stream);
    fclose($stream);

    expect($held)->toBe(file_get_contents($path))
        ->and($done['id'])->toBe('f1')
        ->and($state['calls'])->toBe(2);

    unlink($path);
});

it('lists a folder across pages', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3599]),
        'www.googleapis.com/drive/v3/files*' => Http::sequence()
            ->push(['files' => [['id' => 'a', 'name' => 'one', 'size' => '5']], 'nextPageToken' => 'page-2'])
            ->push(['files' => [['id' => 'b', 'name' => 'two']]]),
    ]);

    $files = driveClient()->list();

    expect($files)->toBe([
        ['id' => 'a', 'name' => 'one', 'size' => 5],
        ['id' => 'b', 'name' => 'two', 'size' => null],
    ]);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'pageToken=page-2') && str_contains(urldecode($r->url()), "'folder-1' in parents"));
});

it('escapes a quote in a name it searches for', function () {
    $drive = FakeGoogleDrive::install();

    driveClient()->list("it's");

    Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), "name='it\\'s'"));
    expect($drive->tokenRequests)->toBe(1);
});

it('reads a file back and deletes it, and a missing file is already deleted', function () {
    $drive = FakeGoogleDrive::install();
    $drive->files['f1'] = ['name' => 'a.txt', 'body' => 'hello'];

    $stream = driveClient()->download('f1');
    expect(stream_get_contents($stream))->toBe('hello');

    driveClient()->delete('f1');
    driveClient()->delete('f1');
    expect($drive->files)->toBe([]);
});

it('replaces a file of the same name only after the new one is whole', function () {
    $drive = FakeGoogleDrive::install();
    $drive->files['old'] = ['name' => 'same.ethrbak', 'body' => 'old'];
    $path = driveTempFile(1000);

    $stream = fopen($path, 'rb');
    $done = driveClient()->upload('same.ethrbak', $stream);
    fclose($stream);

    expect(array_keys($drive->files))->toBe([$done['id']]);
    unlink($path);
});

it('shows Google\'s own message and never the token', function () {
    $drive = FakeGoogleDrive::install();
    $drive->failWith = 403;

    try {
        driveClient()->list();
        $this->fail('Expected the call to fail.');
    } catch (GoogleDriveException $e) {
        expect($e->getMessage())->toContain('quota exceeded')
            ->and($e->getMessage())->not->toContain(FakeGoogleDrive::ACCESS_TOKEN)
            ->and($e->getMessage())->not->toContain('refresh-token-1')
            ->and($e->getMessage())->not->toContain('client-secret');
    }
});

it('cuts a very long Google message short', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3599]),
        '*' => Http::response(['error' => ['message' => str_repeat('x', 2000)]], 500),
    ]);

    try {
        driveClient()->list();
    } catch (GoogleDriveException $e) {
        expect(mb_strlen($e->getMessage()))->toBeLessThanOrEqual(500);
    }
});

it('gives no email, and carries on, when the account lookup fails', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3599]),
        'www.googleapis.com/drive/v3/about*' => Http::response(['error' => ['message' => 'insufficient scope']], 403),
    ]);

    expect(driveClient()->accountEmail())->toBeNull();
});

it('reads the account email when Google gives it', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3599]),
        'www.googleapis.com/drive/v3/about*' => Http::response(['user' => ['emailAddress' => 'me@example.com']]),
    ]);

    expect(driveClient()->accountEmail())->toBe('me@example.com');
});

it('bounds every call, and never follows a redirect on a chunk', function () {
    config([
        'backup.google_drive.connect_timeout' => 7,
        'backup.google_drive.timeout' => 33,
        'backup.google_drive.chunk_timeout' => 99,
    ]);
    $drive = new FakeGoogleDrive;
    $seen = [];
    Http::fake(function (Request $request, array $options) use ($drive, &$seen) {
        $seen[] = [
            'url' => $request->url(),
            'connect' => $options['connect_timeout'] ?? null,
            'timeout' => $options['timeout'] ?? null,
            'redirects' => $options['allow_redirects'] ?? null,
        ];

        return $drive->handle($request);
    });
    $path = driveTempFile(1000);

    $stream = fopen($path, 'rb');
    driveClient()->upload('a.ethrbak', $stream);
    fclose($stream);
    unlink($path);

    $calls = collect($seen);
    $chunk = $calls->first(fn (array $c) => str_starts_with($c['url'], 'https://upload.fake.test/'));
    $listing = $calls->first(fn (array $c) => str_contains($c['url'], '/drive/v3/files'));

    expect($calls->every(fn (array $c) => $c['connect'] === 7))->toBeTrue()
        ->and($chunk['timeout'])->toBe(99)
        ->and($chunk['redirects'])->toBeFalse()
        ->and($listing['timeout'])->toBe(33);
});

it('gives the S3 disk bounded timeouts too', function () {
    config(['backup.storage_http' => ['connect_timeout' => 4, 'timeout' => 45]]);
    OutboundHost::resolveUsing(fn () => ['93.184.216.34']);

    $config = (new BackupStorageDisk)->diskConfig(new BackupStorageSetting([
        'endpoint' => 'https://obsv3.example.com',
        'region' => 'af-east-1',
        'bucket' => 'ethr-backups',
        'access_key_id' => 'k',
        'secret_access_key' => 's',
        'path_style' => true,
    ]));

    expect($config['http'])->toBe(['connect_timeout' => 4, 'timeout' => 45]);
    OutboundHost::resolveUsing(null);
});

describe('the disk over the adapter', function () {
    function connectedDriveSetting(): BackupDriveSetting
    {
        $setting = new BackupDriveSetting(['client_id' => 'client.apps.googleusercontent.com', 'client_secret' => 'client-secret']);
        $setting->forceFill([
            'encryption_key' => BackupCipher::generateKey(),
            'refresh_token' => 'refresh-token-1',
            'folder_id' => 'folder-1',
            'enabled' => true,
        ])->save();

        return $setting;
    }

    it('writes, reads, sizes, lists and deletes under backups/ like any disk', function () {
        $drive = FakeGoogleDrive::install();
        $disk = (new BackupDriveDisk)->build(connectedDriveSetting());

        $disk->put('backups/ethr-20261115-010000.tar.gz.ethrbak', 'sealed-bytes');

        expect($disk->exists('backups/ethr-20261115-010000.tar.gz.ethrbak'))->toBeTrue()
            ->and($disk->size('backups/ethr-20261115-010000.tar.gz.ethrbak'))->toBe(12)
            ->and($disk->get('backups/ethr-20261115-010000.tar.gz.ethrbak'))->toBe('sealed-bytes')
            ->and($disk->files('backups'))->toBe(['backups/ethr-20261115-010000.tar.gz.ethrbak']);

        $disk->delete('backups/ethr-20261115-010000.tar.gz.ethrbak');
        expect($disk->exists('backups/ethr-20261115-010000.tar.gz.ethrbak'))->toBeFalse()
            ->and($drive->files)->toBe([]);
    });

    it('refuses any path outside backups/ and any name that is not plain', function () {
        FakeGoogleDrive::install();
        $disk = (new BackupDriveDisk)->build(connectedDriveSetting());

        expect(fn () => $disk->put('elsewhere/file', 'x'))->toThrow(Exception::class)
            ->and(fn () => $disk->put("backups/it's", 'x'))->toThrow(Exception::class)
            ->and(fn () => $disk->put('backups/../file', 'x'))->toThrow(Exception::class);
    });

    it('passes its probe, and reports Google\'s message when the probe fails', function () {
        $drive = FakeGoogleDrive::install();
        $setting = connectedDriveSetting();

        expect((new BackupDriveDisk)->probe($setting))->toBeNull()
            ->and($drive->files)->toBe([]);

        $drive->failWith = 403;
        expect((new BackupDriveDisk)->probe($setting))->toContain('quota exceeded');
    });

    it('will not build a disk for a setting that is not connected', function () {
        $setting = new BackupDriveSetting(['client_id' => 'c', 'client_secret' => 's']);

        expect(fn () => (new BackupDriveDisk)->build($setting))->toThrow(RuntimeException::class, 'not connected');
    });
});
