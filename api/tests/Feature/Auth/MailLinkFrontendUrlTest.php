<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\User;
use App\Notifications\AccountActivationNotification;
use App\Notifications\PasswordResetLinkNotification;
use App\Notifications\PayslipAvailableNotification;
use App\Support\FrontendUrl;
use Illuminate\Support\Facades\File;

/*
 * The reset and activation emails were built from config('app.frontend_url'),
 * a key no config file defined, with 'http://localhost:3000' as the fallback.
 * In production every such email therefore linked to a host no user can reach.
 */

function mailActionUrl(object $notification, User $user): string
{
    return $notification->toMail($user)->actionUrl;
}

it('links password reset mail to the configured frontend url', function () {
    config(['app.frontend_url' => 'https://www.ethr.test']);
    $user = User::factory()->make(['email' => 'a@acme.test']);

    $url = mailActionUrl(new PasswordResetLinkNotification('tok123', 'acme'), $user);

    expect($url)->toStartWith('https://www.ethr.test/login/reset?token=tok123')
        ->and($url)->toContain('email=a%40acme.test')
        ->and($url)->toContain('tenant=acme');
});

it('links activation mail to the configured frontend url', function () {
    config(['app.frontend_url' => 'https://www.ethr.test/']);
    $user = User::factory()->make(['email' => 'a@acme.test']);

    $url = mailActionUrl(new AccountActivationNotification('tok123', 'acme', 'Acme Ltd'), $user);

    expect($url)->toStartWith('https://www.ethr.test/login/reset?token=tok123');
});

it('resolves to the application url, never localhost:3000, outside a local environment', function () {
    // phpunit.xml pins FRONTEND_URL to blank and APP_ENV to testing: the
    // production shape. It is pinned rather than assumed because CI builds its
    // .env from .env.example, which sets FRONTEND_URL for local development —
    // the first version of this test read the ambient value and failed there.
    expect(env('FRONTEND_URL'))->toBeEmpty()
        ->and(app()->environment('local'))->toBeFalse()
        ->and(config('app.frontend_url'))->toBe(config('app.url'))
        ->and(config('app.frontend_url'))->not->toContain('localhost:3000');
});

it('builds no notification link from url(), which is the API host', function () {
    // Eight notifications linked with url('/approvals') and the like: the API
    // host from APP_URL, or `http://localhost` in a queue worker with no
    // request. The pages are on the frontend; FrontendUrl::to() builds those.
    // Tokenised, so a docblock that mentions url() does not count.
    $files = File::allFiles(app_path('Notifications'));
    $offenders = [];

    foreach ($files as $file) {
        $tokens = PhpToken::tokenize($file->getContents());

        foreach ($tokens as $i => $token) {
            if ($token->is(T_STRING) && strtolower($token->text) === 'url'
                && ($tokens[$i + 1]->text ?? null) === '('
                && ! in_array($tokens[$i - 1]->id ?? null, [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                $offenders[] = "{$file->getFilename()}:{$token->line}";
            }
        }
    }

    expect(count($files))->toBeGreaterThan(20)
        ->and($offenders)->toBe([]);
});

it('links every notification to a page the frontend actually has', function () {
    // PayslipAvailableNotification linked /payslips; the page is
    // /payroll/payslips, so the button opened the not-found page.
    $pages = base_path('../src/src/app/(dashboard)');
    $missing = [];
    $paths = [];

    foreach (File::allFiles(app_path('Notifications')) as $file) {
        preg_match_all("/FrontendUrl::to\\('([^']+)'\\)/", $file->getContents(), $m);

        foreach ($m[1] as $path) {
            $paths[] = $path;
            if (! is_file($pages.'/'.trim($path, '/').'/page.tsx')) {
                $missing[] = "{$file->getFilename()}: {$path}";
            }
        }
    }

    expect(count($paths))->toBeGreaterThanOrEqual(8)
        ->and($missing)->toBe([]);
})->skip(fn () => ! is_dir(base_path('../src/src/app/(dashboard)')), 'Needs the frontend source beside api/, as in a full checkout.');

it('joins the frontend url and a path with exactly one slash', function () {
    config(['app.frontend_url' => 'https://app.ethr.test/']);

    expect(FrontendUrl::to('/approvals'))->toBe('https://app.ethr.test/approvals')
        ->and(FrontendUrl::to('approvals'))->toBe('https://app.ethr.test/approvals');
});

it('sends the payslip email to the payslips page on the frontend', function () {
    config(['app.url' => 'https://api.ethr.test', 'app.frontend_url' => 'https://app.ethr.test']);
    $tenant = createTenant();
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'period_label' => 'Meskerem 2019']);
    $entry = PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => Employee::factory()->create(['tenant_id' => $tenant->id])->id,
    ]);
    $user = User::factory()->make(['email' => 'a@acme.test']);

    $mail = (new PayslipAvailableNotification($entry->fresh()))->toMail($user);

    expect($mail->actionUrl)->toBe('https://app.ethr.test/payroll/payslips')
        ->and($mail->subject)->not->toContain(':period')
        ->and($mail->subject)->toContain('Meskerem 2019');
});
