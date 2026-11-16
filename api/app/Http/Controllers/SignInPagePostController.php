<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A POST to a sign-in page Laravel serves: `/login` on an organisation's own
 * host and `ethr.et/{slug}`.
 *
 * The sign-in forms post natively when a person presses the button before the
 * page has hydrated, so these addresses can receive one. The body is never
 * read: the answer is 303 See Other to the same path as a GET. Only `org` and
 * `lang` are carried over, and only when well formed; anything else in the
 * query string, a stray `password` included, is not echoed into Location.
 * Runs without the web group, like the GET routes: no session, no CSRF, since
 * it reads nothing and changes nothing. Location is relative, so the scheme and
 * host are the browser's own and not rebuilt from the request.
 */
final class SignInPagePostController
{
    public function __invoke(Request $request): Response
    {
        $query = [];

        $org = $request->query('org');
        if (is_string($org) && preg_match('/\A[a-z0-9][a-z0-9-]{0,62}\z/i', $org) === 1) {
            $query['org'] = $org;
        }

        $lang = $request->query('lang');
        if (is_string($lang) && preg_match('/\A[a-z]{2,3}\z/', $lang) === 1) {
            $query['lang'] = $lang;
        }

        $path = '/'.ltrim($request->path(), '/');

        $target = $path.($query === [] ? '' : '?'.http_build_query($query));

        return response('', 303)->header('Location', $target);
    }
}
