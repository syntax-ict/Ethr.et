<?php

declare(strict_types=1);

use App\Enums\UserRole;
use Illuminate\Support\Facades\Route;
use Tests\Support\ParameterizedFixtures;

test('parameterized GET api/v1 endpoints never 5xx with valid bound records', function () {
    $tenant = createTenant();

    $fixtures = ParameterizedFixtures::build($tenant);
    $params = $fixtures['params'];
    actingAsUser(['role' => UserRole::TENANT_ADMIN, 'employee_id' => $fixtures['employee']->id], $tenant);

    // Skipped: DomPDF payslip (memory-heavy) and the invoice receipt (needs a Subscription chain).
    $skipUris = [
        'api/v1/payroll/payslips/{payrollEntry}/pdf',
        'api/v1/billing/invoices/{invoice}/receipt',
    ];

    $failures = [];
    $unresolved = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }
        $uri = $route->uri();
        if (! str_starts_with($uri, 'api/v1/') || ! str_contains($uri, '{')) {
            continue;
        }
        if (in_array($uri, $skipUris, true)) {
            continue;
        }

        preg_match_all('/\{(\w+)\}/', $uri, $m);
        $names = $m[1];
        $resolved = $uri;
        $ok = true;
        foreach ($names as $name) {
            if (! array_key_exists($name, $params)) {
                $ok = false;
                break;
            }
            $resolved = str_replace('{'.$name.'}', (string) $params[$name], $resolved);
        }
        if (! $ok) {
            $unresolved[] = $uri;

            continue;
        }

        $status = test()->get("http://{$tenant->subdomain}.ethr.test/{$resolved}")->getStatusCode();
        if ($status >= 500) {
            $failures[$uri] = $status;
        }
    }

    if ($failures !== []) {
        $lines = collect($failures)->map(fn (int $s, string $u) => "  {$u} => {$s}")->implode("\n");
        test()->fail("Endpoints returning 5xx:\n".$lines);
    }

    expect($failures)->toBe([]);
    // Coverage guard: every non-skipped parameterized route must have been resolvable and hit,
    // so a newly added route with an unmapped param fails loudly instead of being silently skipped.
    expect($unresolved)->toBe([]);
});
