<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Models\Tenant;

interface SsoProviderInterface
{
    public function getLoginUrl(Tenant $tenant, string $relayState = ''): string;

    public function handleCallback(Tenant $tenant, array $requestData): SsoUser;

    public function getMetadataXml(Tenant $tenant): string;

    public function isConfigured(Tenant $tenant): bool;
}
