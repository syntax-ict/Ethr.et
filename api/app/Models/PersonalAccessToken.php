<?php

declare(strict_types=1);

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

class PersonalAccessToken extends SanctumToken
{
    public function tokenable()
    {
        return $this->morphTo('tokenable')->withoutGlobalScopes();
    }

    /**
     * The database id of the token authenticating the given user, or null.
     *
     * Sanctum returns a `TransientToken` when the request is authenticated by
     * session cookie rather than a bearer token. That class has no id — reading
     * `->id` on it raises "Undefined property" and, with warnings escalated to
     * exceptions, turns the request into a 500. Any code that compares "is this
     * the current token?" has to go through this guard.
     */
    public static function currentIdFor(mixed $user): mixed
    {
        $token = $user?->currentAccessToken();

        return $token instanceof self ? $token->getKey() : null;
    }
}
