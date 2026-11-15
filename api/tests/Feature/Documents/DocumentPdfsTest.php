<?php

declare(strict_types=1);

use App\Enums\TenantAddressMode;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Support\FrontendUrl;
use App\Support\PdfPages;
use App\Support\ReaderDates;
use Barryvdh\DomPDF\Facade\Pdf;

/*
 * The receipt printed an Amharic organisation name as question marks and its
 * address as a subdomain that does not exist; long PDFs carried no page
 * numbers; the report export did not say whose it was.
 */

/** Every Flate stream of a PDF, inflated, as one string. */
function inflatedStreams(string $pdf): string
{
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m);

    return implode("\n", array_map(fn (string $s) => (string) @gzuncompress($s), $m[1]));
}

it('prints an Amharic organisation name on the receipt, in a small file', function () {
    $tenant = createTenant(['name' => 'አበበ ንግድ ሃላፊነቱ የተወሰነ']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $invoice = Invoice::factory()->create(['tenant_id' => $tenant->id, 'status' => 'paid', 'paid_at' => now()]);

    $response = test()->get("http://{$tenant->subdomain}.ethr.test/api/v1/billing/invoices/{$invoice->public_id}/receipt")
        ->assertOk();
    $bytes = (string) $response->getContent();

    expect($bytes)->toContain('NotoSansEthiopic')
        ->and(strlen($bytes))->toBeLessThan(200_000);
});

it('prints the organisation\'s real address on the receipt, not a subdomain', function () {
    $tenant = createTenant(['address_mode' => TenantAddressMode::PATH]);
    $invoice = Invoice::factory()->create(['tenant_id' => $tenant->id]);

    $html = view('receipts.invoice', [
        'invoice' => $invoice,
        'tenant' => $tenant,
        'address' => 'ethr.et/'.$tenant->subdomain,
        'line_items' => [],
        'dates' => ReaderDates::for(null, 'en'),
    ])->render();

    expect($html)->toContain('ethr.et/'.$tenant->subdomain)
        ->and($html)->not->toContain($tenant->subdomain.'.ethr.et');
});

it('numbers the pages of a long PDF', function () {
    $body = str_repeat('<p style="page-break-after: always">A page</p>', 3);
    $pdf = Pdf::loadHTML('<html><body>'.$body.'</body></html>');
    $pdf->setPaper('a4', 'landscape');

    $bytes = PdfPages::render($pdf);

    expect(inflatedStreams($bytes))->toContain('1 / 3');
});

it('names the organisation on the printed report', function () {
    $html = view('reports.export', [
        'organisation' => 'Habru Textile',
        'columns' => ['Name'],
        'rows' => [['Name' => 'Abebe']],
        'locale' => 'en',
        'generated_at' => '2026-11-15',
    ])->render();

    expect($html)->toContain('<h1>Habru Textile</h1>');
});

it('addresses a path-mode organisation at ethr.et/slug, with no scheme', function () {
    $tenant = createTenant(['address_mode' => TenantAddressMode::PATH]);
    $address = FrontendUrl::addressOf($tenant);

    expect($address)->toEndWith('/'.$tenant->subdomain)
        ->and($address)->not->toContain('://');
});

it('addresses an organisation with a verified custom domain at that domain', function () {
    $tenant = createTenant([
        'address_mode' => TenantAddressMode::CUSTOM_DOMAIN,
        'custom_domain' => 'hr.acme.com',
        'custom_domain_verified_at' => now(),
    ]);

    expect(FrontendUrl::addressOf($tenant))->toStartWith('hr.acme.com')
        ->and(FrontendUrl::addressOf($tenant))->not->toContain('://');
});
