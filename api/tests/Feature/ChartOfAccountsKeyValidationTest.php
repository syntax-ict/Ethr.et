<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\ChartOfAccount;
use App\Services\Accounting\AccountingExportService;

/*
 * Audit N11: `accounts.*.key` was any string. The journal export reads exactly
 * the six keys in AccountingExportService::DEFAULTS, so a row saved under any
 * other key — a typo, or a key the UI never offered — was stored, reported as
 * saved, and never used.
 */

function chartUrl(): string
{
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    return "http://{$tenant->subdomain}.ethr.test/api/v1/accounting/chart-of-accounts";
}

test('an account key the journal never reads is refused', function () {
    $url = chartUrl();

    test()->putJson($url, ['accounts' => [
        ['key' => 'salary_expense', 'account_code' => '6100', 'account_name' => 'Staff Costs'],
        ['key' => 'salary_expence', 'account_code' => '6101', 'account_name' => 'Typo'],
    ]])->assertStatus(422)->assertJsonValidationErrors(['accounts.1.key']);

    expect(ChartOfAccount::count())->toBe(0);
});

test('the same key twice in one request is refused', function () {
    $url = chartUrl();

    test()->putJson($url, ['accounts' => [
        ['key' => 'tax_payable', 'account_code' => '2100', 'account_name' => 'A'],
        ['key' => 'tax_payable', 'account_code' => '2101', 'account_name' => 'B'],
    ]])->assertStatus(422);
});

test('every key the journal reads can be overridden', function () {
    $url = chartUrl();

    $accounts = array_map(
        fn (string $key) => ['key' => $key, 'account_code' => '9'.substr(md5($key), 0, 3), 'account_name' => "Custom {$key}"],
        array_keys(AccountingExportService::DEFAULTS),
    );

    test()->putJson($url, ['accounts' => $accounts])->assertOk();

    expect(ChartOfAccount::count())->toBe(count(AccountingExportService::DEFAULTS));
});
