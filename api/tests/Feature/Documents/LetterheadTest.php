<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use App\Models\EmploymentLetterRequest;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Services\Letters\EmploymentLetters;
use App\Services\Payroll\PayslipPdfService;
use App\Support\Letterhead;
use Illuminate\Support\Facades\Storage;

/*
 * The top of every printed document, built once: logo, name, contact lines.
 * Everything is optional, and a missing line is left out, never a dash.
 */

const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

function letterheadDisk(): string
{
    Storage::fake((string) config('filesystems.default'));

    return (string) config('filesystems.default');
}

function letterheadTenant(array $settings = [], array $attributes = []): Tenant
{
    return createTenant(['name' => 'Habru Woreda', 'settings' => $settings, ...$attributes]);
}

test('a letterhead with nothing set is the name alone', function () {
    $tenant = letterheadTenant();

    expect(Letterhead::for($tenant, 'en'))->toBe(['name' => 'Habru Woreda', 'logo' => null, 'lines' => []]);
});

test('each contact line is labelled, and an empty or blank one is left out', function () {
    $tenant = letterheadTenant([
        'letterhead_address' => '  Muja Town  ',
        'letterhead_po_box' => '',
        'letterhead_phone' => '   ',
        'letterhead_email' => 'finance@habru.test',
        'employer_tin' => '0012345678',
    ]);

    $lines = Letterhead::for($tenant, 'en')['lines'];

    expect($lines)->toBe(['Muja Town', 'Email: finance@habru.test', 'TIN: 0012345678'])
        ->and(implode('|', $lines))->not->toContain('-')
        ->and(Letterhead::for($tenant, 'en', withTin: false)['lines'])->toBe(['Muja Town', 'Email: finance@habru.test']);
});

test('the labels follow the reader\'s language', function () {
    $tenant = letterheadTenant(['letterhead_po_box' => '123']);

    expect(Letterhead::for($tenant, 'am')['lines'][0])->toStartWith(__('letterhead.po_box', [], 'am'))
        ->and(__('letterhead.po_box', [], 'am'))->not->toBe(__('letterhead.po_box', [], 'en'))
        ->and(__('letterhead.po_box', [], 'om'))->not->toBe('letterhead.po_box');
});

test('an uploaded PNG logo is embedded as a data URI', function () {
    $disk = letterheadDisk();
    Storage::disk($disk)->put('tenants/x/branding/logo.png', base64_decode(PNG_1X1));
    $tenant = letterheadTenant(attributes: ['logo_storage_path' => 'tenants/x/branding/logo.png']);

    expect(Letterhead::for($tenant, 'en')['logo'])->toStartWith('data:image/png;base64,'.substr(PNG_1X1, 0, 20));
});

test('a missing, SVG or oversize logo is skipped without an error', function () {
    $disk = letterheadDisk();
    Storage::disk($disk)->put('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    Storage::disk($disk)->put('big.png', "\x89PNG\r\n\x1a\n".str_repeat('0', Letterhead::MAX_LOGO_BYTES));

    foreach (['gone.png', 'logo.svg', 'big.png'] as $path) {
        $tenant = letterheadTenant(attributes: ['logo_storage_path' => $path]);
        expect(Letterhead::for($tenant, 'en')['logo'])->toBeNull();
    }
});

test('one organisation\'s letterhead never carries another\'s details', function () {
    $disk = letterheadDisk();
    Storage::disk($disk)->put('b/logo.png', base64_decode(PNG_1X1));
    $a = letterheadTenant(['letterhead_phone' => '+251 11 111 1111', 'employer_tin' => '1111111111'], ['name' => 'Org A']);
    $b = letterheadTenant([
        'letterhead_phone' => '+251 22 222 2222', 'letterhead_address' => 'B Street', 'employer_tin' => '2222222222',
    ], ['name' => 'Org B', 'logo_storage_path' => 'b/logo.png']);

    $forA = Letterhead::for($a, 'en');
    $forB = Letterhead::for($b, 'en');

    expect(json_encode($forA))->toContain('Org A', '1111111111')
        ->not->toContain('Org B')->not->toContain('2222222222')->not->toContain('B Street')
        ->and($forA['logo'])->toBeNull()
        ->and($forB['logo'])->not->toBeNull();
});

test('settings accept and return the four document keys, and refuse a bad email', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => true], $tenant);
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/settings";

    test()->putJson($url, ['settings' => [
        'letterhead_address' => 'Muja Town',
        'letterhead_phone' => '+251 33 331 0000',
        'letterhead_email' => 'finance@habru.test',
        'letterhead_po_box' => '123',
    ]])->assertOk();

    test()->getJson($url)->assertOk()
        ->assertJsonPath('documents.letterhead_address', 'Muja Town')
        ->assertJsonPath('documents.letterhead_phone', '+251 33 331 0000')
        ->assertJsonPath('documents.letterhead_email', 'finance@habru.test')
        ->assertJsonPath('documents.letterhead_po_box', '123');

    test()->putJson($url, ['settings' => ['letterhead_email' => 'not-an-email']])
        ->assertUnprocessable()->assertJsonValidationErrors(['settings.letterhead_email']);

    test()->putJson($url, ['settings' => ['letterhead_address' => str_repeat('a', 201)]])
        ->assertUnprocessable()->assertJsonValidationErrors(['settings.letterhead_address']);
});

test('the payslip shows the employer TIN and only the end of the employee\'s', function () {
    $tenant = letterheadTenant(['employer_tin' => '0012345678', 'letterhead_address' => 'Muja Town']);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'tin' => '9876543210']);
    EmployeeBankDetail::create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'bank_name' => 'Awash Bank',
        'account_number' => '1000123456789', 'is_primary' => true,
    ]);
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'paid_at' => '2026-10-28 09:00:00']);
    $entry = PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
        'allowances' => [['name' => '', 'type' => 'fixed', 'amount_cents' => 100000], ['name' => 'Transport', 'type' => 'fixed', 'amount_cents' => 50000]],
    ]);

    $html = view('payslip', app(PayslipPdfService::class)->data($entry))->render();

    expect($html)->toContain('0012345678')
        ->toContain('Muja Town')
        ->toContain('******3210')
        ->not->toContain('9876543210')
        ->toContain('Awash Bank')
        ->toContain('*********6789')
        ->not->toContain('1000123456789')
        ->toContain('Fixed allowance')
        ->toContain('Transport')
        ->not->toContain('>fixed<');
});

test('a payslip prints no TIN, bank or pay date rows when it has none', function () {
    $tenant = letterheadTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'tin' => null]);
    $entry = PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'paid_at' => null])->id,
        'employee_id' => $employee->id,
    ]);

    $html = view('payslip', app(PayslipPdfService::class)->data($entry))->render();

    expect($html)->not->toContain(__('payslip.employee_tin'))
        ->not->toContain(__('payslip.pay_date'))
        ->not->toContain(__('payslip.bank'));
});

test('masking keeps the last four characters and nothing else', function () {
    expect(PayslipPdfService::masked('9876543210'))->toBe('******3210')
        ->and(PayslipPdfService::masked('123'))->toBe('***')
        ->and(PayslipPdfService::masked('  '))->toBeNull()
        ->and(PayslipPdfService::masked(null))->toBeNull();
});

test('the employment letter has a signature line, a stamp box and its reference in the footer', function () {
    $tenant = letterheadTenant(['letterhead_phone' => '+251 33 331 0000']);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    $letters = app(EmploymentLetters::class);
    $issued = $letters->issue($letters->request($employee, ['purpose' => 'Bank']), $hr);
    expect($issued)->toBeInstanceOf(EmploymentLetterRequest::class);

    $html = view('letters.employment', $letters->data($issued))->render();

    expect($html)->toContain('class="stamp-box"')
        ->toContain(__('letters.stamp'))
        ->toContain(__('letters.signature_line'))
        ->toContain('+251 33 331 0000')
        ->toContain('Reference '.$issued->reference.', issued')
        ->and(strlen($letters->pdf($issued)))->toBeGreaterThan(1000);
});
