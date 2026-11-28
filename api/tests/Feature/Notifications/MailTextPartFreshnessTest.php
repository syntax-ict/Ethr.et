<?php

declare(strict_types=1);

use App\Support\Mail\FreshTextMarkdown;
use Illuminate\Mail\Markdown;
use Illuminate\View\Engines\CompilerEngine;

// The plain-text part of an email must follow a changed template without anyone
// clearing compiled views. Seen on production 2026-11-28: HTML said the new
// greeting, text said the old one.

it('resolves the freshness-checking renderer for the Markdown class', function () {
    expect(app(Markdown::class))->toBeInstanceOf(FreshTextMarkdown::class);
});

it('re-renders the text part from a template changed after the html part was rendered', function () {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ethr-mail-fresh-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $file = $dir.DIRECTORY_SEPARATOR.'probe.blade.php';

    $write = function (string $greeting) use ($file): void {
        file_put_contents($file, "@component('mail::message')\n# {$greeting}\n\nBody line.\n@endcomponent\n");
    };

    view()->addNamespace('freshprobe', $dir);
    $engine = fn (): CompilerEngine => app('view')->getEngineResolver()->resolve('blade');

    try {
        $write('Hello OLD');
        touch($file, time() - 60);

        $markdown = app(Markdown::class);
        expect((string) $markdown->render('freshprobe::probe'))->toContain('Hello OLD')
            ->and((string) $markdown->renderText('freshprobe::probe'))->toContain('Hello OLD');

        $write('Hello NEW');
        touch($file, time() + 10);

        // A new request starts with an empty memory of what was checked.
        $engine()->forgetCompiledOrNotExpired();

        expect((string) $markdown->render('freshprobe::probe'))->toContain('Hello NEW')
            ->and((string) $markdown->renderText('freshprobe::probe'))->toContain('Hello NEW')
            ->and((string) $markdown->renderText('freshprobe::probe'))->not->toContain('Hello OLD');
    } finally {
        $compiler = $engine()->getCompiler();
        // Both compiled files: the default echo format and the HTML one.
        @unlink($compiler->getCompiledPath($file));
        $compiler->usingEchoFormat('new \Illuminate\Support\EncodedHtmlString(%s)', function () use ($compiler, $file): void {
            @unlink($compiler->getCompiledPath($file));
        });
        @unlink($file);
        @rmdir($dir);
    }
});
