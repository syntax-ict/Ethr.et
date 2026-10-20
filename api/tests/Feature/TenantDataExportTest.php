<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\CleanupExpiredDataJob;
use App\Jobs\ExportTenantDataJob;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Tenant;
use App\Models\TenantExport;
use App\Notifications\TenantExportFinishedNotification;
use App\Services\CurrentTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * An organisation's export of its own data (blueprint assessment A12, P12).
 * What matters most is what the archive leaves out: another organisation's
 * rows, and the secrets and encrypted identity numbers of this one.
 */
beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-10-20 08:00:00', 'UTC'));

    $this->tenant = createTenant();
    $this->admin = createUser(['role' => UserRole::TENANT_ADMIN], $this->tenant);
});

function exportUrl(Tenant $tenant, string $path = ''): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/settings/data-export{$path}";
}

/** @return array<string, string> name in the archive => contents */
function readExportArchive(TenantExport $export): array
{
    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('local')->path((string) $export->path)))->toBeTrue();
    $files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $files[$name] = (string) $zip->getFromIndex($i);
    }
    $zip->close();

    return $files;
}

/** @return list<array<string, mixed>> */
function exportRows(array $files, string $table): array
{
    $lines = array_filter(explode("\n", $files["data/{$table}.jsonl"] ?? ''));

    return array_values(array_map(fn (string $line): array => json_decode($line, true), $lines));
}

function runExportFor(Tenant $tenant, int $requestedBy): TenantExport
{
    app(CurrentTenant::class)->set($tenant);
    $export = TenantExport::query()->create([
        'tenant_id' => $tenant->id,
        'requested_by' => $requestedBy,
        'status' => TenantExport::QUEUED,
    ]);
    (new ExportTenantDataJob($tenant->id, $export->id))->handle(app(CurrentTenant::class));

    return $export->refresh();
}

it('exports the organisation\'s rows and documents and nothing of another organisation', function () {
    $ours = Employee::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Abebe Kebede', 'tin' => '0012345678']);
    Storage::disk('local')->put('documents/ours.pdf', '%PDF-ours');
    EmployeeDocument::query()->create([
        'tenant_id' => $this->tenant->id, 'employee_id' => $ours->id, 'type' => 'contract',
        'title' => 'Contract', 'file_path' => 'documents/ours.pdf', 'file_size' => 9, 'mime_type' => 'application/pdf',
    ]);

    $other = createTenant();
    $theirs = Employee::factory()->create(['tenant_id' => $other->id, 'name' => 'Hidden Person']);
    Storage::disk('local')->put('documents/theirs.pdf', '%PDF-theirs');
    EmployeeDocument::query()->create([
        'tenant_id' => $other->id, 'employee_id' => $theirs->id, 'type' => 'contract',
        'title' => 'Theirs', 'file_path' => 'documents/theirs.pdf', 'file_size' => 11, 'mime_type' => 'application/pdf',
    ]);

    $export = runExportFor($this->tenant, $this->admin->id);

    expect($export->status)->toBe(TenantExport::READY)
        ->and($export->size_bytes)->toBeGreaterThan(0)
        ->and($export->expires_at?->toDateString())->toBe('2026-10-27');

    $files = readExportArchive($export);
    $employees = exportRows($files, 'employees');

    expect(array_column($employees, 'public_id'))->toBe([$ours->public_id])
        ->and($employees[0])->toHaveKey('id')
        ->and($employees[0])->not->toHaveKeys(['tin', 'national_id', 'national_id_hash', 'tenant_id'])
        ->and(json_encode($files))->not->toContain('Hidden')
        ->and(json_encode($files))->not->toContain('0012345678');

    $documents = array_filter(array_keys($files), fn (string $name): bool => str_starts_with($name, 'files/documents/'));
    expect(array_values($documents))->toHaveCount(1)
        ->and($files[array_values($documents)[0]])->toBe('%PDF-ours')
        ->and($files)->toHaveKey('README.txt');
});

it('leaves out passwords, secrets and sign-in tables', function () {
    $files = readExportArchive(runExportFor($this->tenant, $this->admin->id));
    $users = exportRows($files, 'users');

    expect($users)->not->toBeEmpty()
        ->and($users[0])->not->toHaveKeys(['password', 'remember_token', 'mfa_secret'])
        ->and($files)->not->toHaveKeys(['data/otp_codes.jsonl', 'data/trusted_devices.jsonl', 'data/kiosk_sessions.jsonl', 'data/tenant_exports.jsonl']);
});

it('tells the requester when the export is ready', function () {
    runExportFor($this->tenant, $this->admin->id);

    Notification::assertSentTo($this->admin, TenantExportFinishedNotification::class);
});

it('queues an export for an administrator and refuses a second while one is waiting', function () {
    Queue::fake();
    $this->actingAs($this->admin);

    $this->postJson(exportUrl($this->tenant))
        ->assertStatus(202)
        ->assertJsonPath('status', 'queued')
        ->assertJsonMissingPath('id');
    Queue::assertPushedOn('exports', ExportTenantDataJob::class);

    $this->postJson(exportUrl($this->tenant))
        ->assertStatus(409)
        ->assertJsonPath('type', 'https://ethr.et/errors/export-in-progress');
});

it('does not let an export whose worker died block a new one', function () {
    Queue::fake();
    $this->actingAs($this->admin);
    $stale = TenantExport::query()->create(['tenant_id' => $this->tenant->id, 'status' => TenantExport::RUNNING]);
    $this->travel(4)->hours();

    $this->postJson(exportUrl($this->tenant))->assertStatus(202);
    expect($stale->refresh()->status)->toBe(TenantExport::FAILED);
});

it('limits an organisation to three exports a day', function () {
    Queue::fake();
    $this->actingAs($this->admin);
    foreach (range(1, 3) as $i) {
        TenantExport::query()->create(['tenant_id' => $this->tenant->id, 'status' => TenantExport::FAILED]);
    }

    $this->postJson(exportUrl($this->tenant))
        ->assertStatus(429)
        ->assertJsonPath('type', 'https://ethr.et/errors/export-limit');
});

it('refuses anyone without settings.manage', function (UserRole $role) {
    $this->actingAs(createUser(['role' => $role], $this->tenant));

    $this->getJson(exportUrl($this->tenant))->assertForbidden();
    $this->postJson(exportUrl($this->tenant))->assertForbidden();
})->with([UserRole::HR_ADMIN, UserRole::FINANCE_ADMIN, UserRole::EMPLOYEE]);

it('downloads a ready export and audits it, and another organisation cannot', function () {
    $export = runExportFor($this->tenant, $this->admin->id);
    $this->actingAs($this->admin);

    $this->getJson(exportUrl($this->tenant))
        ->assertOk()
        ->assertJsonPath('data.0.public_id', $export->public_id)
        ->assertJsonPath('data.0.status', 'ready')
        ->assertJsonMissingPath('data.0.path');

    $this->get(exportUrl($this->tenant, "/{$export->public_id}/download"))
        ->assertOk()
        ->assertDownload('ethr-export-2026-10-20.zip');
    $this->assertDatabaseHas('audit_log', ['tenant_id' => $this->tenant->id, 'action' => 'settings.data_export_downloaded']);

    $other = createTenant();
    $this->app['auth']->forgetGuards();
    $this->actingAs(createUser(['role' => UserRole::TENANT_ADMIN], $other));
    $this->get(exportUrl($other, "/{$export->public_id}/download"))->assertNotFound();
});

it('deletes the archive when it expires and refuses the download after', function () {
    $export = runExportFor($this->tenant, $this->admin->id);
    $path = (string) $export->path;
    expect(Storage::disk('local')->exists($path))->toBeTrue();

    $this->travel(8)->days();
    (new CleanupExpiredDataJob)->handle();

    expect(Storage::disk('local')->exists($path))->toBeFalse()
        ->and($export->refresh()->status)->toBe(TenantExport::EXPIRED);

    $this->actingAs($this->admin);
    $this->get(exportUrl($this->tenant, "/{$export->public_id}/download"))->assertStatus(410);
});
