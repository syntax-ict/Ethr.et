<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * A stored PDF handed to a browser as a download, never shown in the page:
 * `attachment`, its own type and no sniffing, so a document cannot act on
 * the origin that served it whatever it contains. The policy set here
 * sandboxes it; on the API routes the security-headers middleware replaces
 * it with its own `default-src 'none'`, which is as strict for a download.
 */
final class DocumentDownload
{
    public static function pdf(string $bytes, string $name, string $cacheControl): Response
    {
        // The UTF-8 name for browsers that read `filename*`, and a plain ASCII
        // one for those that do not; HeaderUtils refuses `%`, `/` and `\` in
        // the fallback, so only letters, digits and a few marks are kept.
        $fallback = trim((string) preg_replace('/[^A-Za-z0-9._ -]+/', '', Str::ascii($name)));

        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                'attachment',
                str_replace(['/', '\\'], ' ', $name),
                $fallback === '' || $fallback === '.pdf' ? 'document.pdf' : $fallback,
            ),
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => $cacheControl,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-site',
        ]);
    }
}
