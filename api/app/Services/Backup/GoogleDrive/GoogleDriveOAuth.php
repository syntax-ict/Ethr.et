<?php

declare(strict_types=1);

namespace App\Services\Backup\GoogleDrive;

use App\Support\FrontendUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The OAuth half of the Google Drive fallback: the consent URL, the code
 * exchange and the revoke.
 *
 * Every host here is a fixed Google host, never taken from input, so the
 * ExternalUrl and OutboundHost checks the S3 endpoint needs have nothing to
 * check.
 *
 * Scope `drive.file` only: the app can reach just the files and folders it
 * created itself, never the rest of the owner's Drive.
 */
final class GoogleDriveOAuth
{
    public const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /**
     * Where Google sends the browser back to: the console page itself, at the
     * platform host (`https://admin.ethr.et/admin/backups` when APP_DOMAIN is
     * ethr.et). Apache serves the static export there, the page reads `code`
     * and `state` from its query string and POSTs them to the API, so no
     * route has to answer an unauthenticated browser redirect. This is the
     * only place the value is built; the console shows it so the owner can
     * paste the same string into Google Cloud.
     */
    public static function redirectUri(): string
    {
        return FrontendUrl::platform('admin/backups');
    }

    public static function authorizationUrl(string $clientId, string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{access_token: string, expires_in: int, refresh_token: string|null}
     *
     * @throws GoogleDriveException
     */
    public static function exchangeCode(string $clientId, string $clientSecret, string $code): array
    {
        $json = self::token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => self::redirectUri(),
        ]);

        return [
            'access_token' => (string) $json['access_token'],
            'expires_in' => (int) ($json['expires_in'] ?? 3600),
            'refresh_token' => is_string($json['refresh_token'] ?? null) && $json['refresh_token'] !== '' ? $json['refresh_token'] : null,
        ];
    }

    /**
     * @return array{access_token: string, expires_in: int}
     *
     * @throws GoogleDriveException
     */
    public static function refresh(string $clientId, string $clientSecret, string $refreshToken): array
    {
        $json = self::token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        return ['access_token' => (string) $json['access_token'], 'expires_in' => (int) ($json['expires_in'] ?? 3600)];
    }

    /** Best effort: the owner can also remove ETHR at myaccount.google.com. */
    public static function revoke(string $token): void
    {
        try {
            self::http()->asForm()->post(self::REVOKE_URL, ['token' => $token]);
        } catch (ConnectionException) {
            // Disconnecting must not depend on Google answering.
        }
    }

    public static function http(): PendingRequest
    {
        return Http::connectTimeout((int) config('backup.google_drive.connect_timeout', 10))
            ->timeout((int) config('backup.google_drive.timeout', 60))
            ->acceptJson();
    }

    /**
     * Google's own message for a failed response, cut short. The request
     * carried a token or a secret; the response never repeats them.
     */
    public static function errorMessage(Response $response): string
    {
        $error = $response->json('error');
        $message = match (true) {
            is_array($error) => (string) ($error['message'] ?? ''),
            is_string($error) => trim($error.' '.(string) $response->json('error_description', '')),
            default => '',
        };

        return mb_substr($message !== '' ? $message : 'Google answered HTTP '.$response->status().'.', 0, 500);
    }

    /**
     * @param  array<string, string>  $form
     * @return array<string, mixed>
     */
    private static function token(array $form): array
    {
        try {
            $response = self::http()->asForm()->post(self::TOKEN_URL, $form);
        } catch (ConnectionException $e) {
            throw new GoogleDriveException('Could not reach Google: '.mb_substr($e->getMessage(), 0, 200), previous: $e);
        }

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new GoogleDriveException(self::errorMessage($response));
        }

        return (array) $response->json();
    }
}
