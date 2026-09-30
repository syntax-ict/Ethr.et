<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Device\DeviceHost;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * What each adapter needs in `connection_config`, and that the host it names is
 * one the server may connect to.
 *
 * The controller stores the config as submitted rather than validated(),
 * because adapters carry keys with no field rule (the generic adapter's
 * `mapping` and `auth`). So this rule is where the host-bearing keys are held
 * to account: `ip` for the three vendor adapters, `base_url` and the request
 * paths for the generic one.
 */
final class DeviceConnectionConfig implements ValidationRule
{
    private const IP_ADAPTERS = ['hikvision', 'zkteco', 'suprema'];

    private const GENERIC_PATH_KEYS = ['status_path', 'events_path', 'enrollments_path'];

    public function __construct(private readonly ?string $adapterType) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        if (in_array($this->adapterType, self::IP_ADAPTERS, true)) {
            $this->validateIpAdapter($value, $fail);
        } elseif ($this->adapterType === 'generic') {
            $this->validateGeneric($value, $fail);
        }
    }

    /**
     * @param  array<mixed>  $config
     */
    private function validateIpAdapter(array $config, Closure $fail): void
    {
        $ip = $config['ip'] ?? null;
        if (! is_string($ip) || $ip === '') {
            $fail(__('validation.required', ['attribute' => 'connection_config.ip']));

            return;
        }

        // Interpolated straight into "http://{ip}:{port}", so anything but a bare
        // host (`127.0.0.1:8443/admin#`, `a@b`) rewrites the URL the adapter calls.
        $refusal = DeviceHost::refusal($ip);
        if ($refusal !== null) {
            $fail($refusal);
        }

        $port = $config['port'] ?? null;
        if (filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
            $fail(__('validation.between.numeric', ['attribute' => 'connection_config.port', 'min' => 1, 'max' => 65535]));
        }
    }

    /**
     * @param  array<mixed>  $config
     */
    private function validateGeneric(array $config, Closure $fail): void
    {
        $baseUrl = $config['base_url'] ?? null;
        if (! is_string($baseUrl) || $baseUrl === '') {
            $fail(__('validation.required', ['attribute' => 'connection_config.base_url']));

            return;
        }

        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || parse_url($baseUrl, PHP_URL_USER) !== null) {
            $fail(__('validation.url', ['attribute' => 'connection_config.base_url']));

            return;
        }

        $refusal = DeviceHost::refusal(trim($host, '[]'));
        if ($refusal !== null) {
            $fail($refusal);
        }

        // The adapter calls base_url + path. A path such as `@evil.test/x` turns
        // http://device.test into http://device.test@evil.test/x, a different host.
        foreach (self::GENERIC_PATH_KEYS as $key) {
            if (! array_key_exists($key, $config)) {
                continue;
            }

            $path = $config[$key];
            if (! is_string($path) || ! self::isSafePath($path)) {
                $fail(__('device.path_invalid', ['key' => $key]));
            }
        }
    }

    /** A request path that cannot change the host: starts with one slash, no userinfo, no scheme. */
    public static function isSafePath(string $path): bool
    {
        return preg_match('#^/(?!/)[^\s@\\\\]*$#', $path) === 1;
    }
}
