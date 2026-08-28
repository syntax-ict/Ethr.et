<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Models\SsoSetting;
use App\Models\Tenant;
use DOMDocument;
use DOMXPath;
use RuntimeException;

class SamlProvider implements SsoProviderInterface
{
    private const SAML_NS = 'urn:oasis:names:tc:SAML:2.0:assertion';

    public function getLoginUrl(Tenant $tenant, string $relayState = ''): string
    {
        $settings = $this->getSettings($tenant);

        $id = '_'.bin2hex(random_bytes(16));
        $issueInstant = gmdate('Y-m-d\TH:i:s\Z');
        $spEntityId = $this->getSpEntityId($tenant);
        $acsUrl = $this->getAcsUrl($tenant);

        $authnRequest = <<<XML
        <samlp:AuthnRequest
            xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"
            xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"
            ID="{$id}"
            Version="2.0"
            IssueInstant="{$issueInstant}"
            Destination="{$settings->idp_sso_url}"
            AssertionConsumerServiceURL="{$acsUrl}"
            ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST">
            <saml:Issuer>{$spEntityId}</saml:Issuer>
            <samlp:NameIDPolicy
                Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress"
                AllowCreate="true"/>
        </samlp:AuthnRequest>
        XML;

        $encoded = base64_encode(gzdeflate($authnRequest));
        $params = ['SAMLRequest' => $encoded];
        if ($relayState !== '') {
            $params['RelayState'] = $relayState;
        }

        return $settings->idp_sso_url.'?'.http_build_query($params);
    }

    public function handleCallback(Tenant $tenant, array $requestData): SsoUser
    {
        $settings = $this->getSettings($tenant);

        if (empty($requestData['SAMLResponse'])) {
            throw new RuntimeException('Missing SAMLResponse in callback data');
        }

        $xml = base64_decode($requestData['SAMLResponse'], true);
        if ($xml === false) {
            throw new RuntimeException('Invalid base64 in SAMLResponse');
        }

        $doc = new DOMDocument;
        $doc->loadXML($xml, LIBXML_NONET | LIBXML_DTDLOAD);

        $this->verifySignature($doc, $settings);
        $this->verifyConditions($doc, $tenant);

        return $this->extractUser($doc);
    }

    public function getMetadataXml(Tenant $tenant): string
    {
        $entityId = $this->getSpEntityId($tenant);
        $acsUrl = htmlspecialchars($this->getAcsUrl($tenant), ENT_XML1);

        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <md:EntityDescriptor
            xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata"
            entityID="{$entityId}">
            <md:SPSSODescriptor
                protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol"
                AuthnRequestsSigned="false"
                WantAssertionsSigned="true">
                <md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</md:NameIDFormat>
                <md:AssertionConsumerService
                    Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"
                    Location="{$acsUrl}"
                    index="0"
                    isDefault="true"/>
            </md:SPSSODescriptor>
        </md:EntityDescriptor>
        XML;
    }

    public function isConfigured(Tenant $tenant): bool
    {
        $settings = SsoSetting::where('tenant_id', $tenant->id)->first();

        return $settings !== null
            && $settings->is_enabled
            && $settings->idp_entity_id !== null
            && $settings->idp_sso_url !== null
            && $settings->idp_certificate !== null;
    }

    private function getSettings(Tenant $tenant): SsoSetting
    {
        $settings = SsoSetting::where('tenant_id', $tenant->id)->first();

        if (! $settings || ! $settings->is_enabled) {
            throw new RuntimeException('SSO is not configured for this tenant');
        }

        return $settings;
    }

    private function getSpEntityId(Tenant $tenant): string
    {
        return config('app.url').'/api/v1/sso/saml/'.$tenant->subdomain.'/metadata';
    }

    private function getAcsUrl(Tenant $tenant): string
    {
        return config('app.url').'/api/v1/sso/saml/'.$tenant->subdomain.'/acs';
    }

    private function verifySignature(DOMDocument $doc, SsoSetting $settings): void
    {
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        $signatureNodes = $xpath->query('//ds:Signature');
        if ($signatureNodes === false || $signatureNodes->length === 0) {
            throw new RuntimeException('SAML response is not signed');
        }

        $cert = openssl_x509_read($settings->idp_certificate);
        if ($cert === false) {
            throw new RuntimeException('Invalid IdP certificate');
        }

        $pubKey = openssl_pkey_get_public($cert);
        if ($pubKey === false) {
            throw new RuntimeException('Cannot extract public key from IdP certificate');
        }

        $sigValue = $xpath->query('//ds:Signature/ds:SignatureValue');
        $signedInfo = $xpath->query('//ds:Signature/ds:SignedInfo');

        if ($sigValue === false || $sigValue->length === 0
            || $signedInfo === false || $signedInfo->length === 0) {
            throw new RuntimeException('Incomplete XML signature');
        }

        $signedInfoXml = $doc->saveXML($signedInfo->item(0));
        $signatureValue = base64_decode($sigValue->item(0)->textContent, true);

        if ($signedInfoXml === false || $signatureValue === false) {
            throw new RuntimeException('Cannot decode signature data');
        }

        $valid = openssl_verify($signedInfoXml, $signatureValue, $pubKey, OPENSSL_ALGO_SHA256);

        if ($valid !== 1) {
            throw new RuntimeException('SAML response signature verification failed');
        }
    }

    private function verifyConditions(DOMDocument $doc, Tenant $tenant): void
    {
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('saml', self::SAML_NS);

        $conditions = $xpath->query('//saml:Conditions');
        if ($conditions !== false && $conditions->length > 0) {
            $condition = $conditions->item(0);

            $notBefore = $condition->getAttribute('NotBefore');
            $notOnOrAfter = $condition->getAttribute('NotOnOrAfter');

            $now = time();
            $clockSkew = 120;

            if ($notBefore && strtotime($notBefore) > $now + $clockSkew) {
                throw new RuntimeException('SAML assertion is not yet valid');
            }
            if ($notOnOrAfter && strtotime($notOnOrAfter) < $now - $clockSkew) {
                throw new RuntimeException('SAML assertion has expired');
            }
        }

        $audiences = $xpath->query('//saml:Conditions/saml:AudienceRestriction/saml:Audience');
        if ($audiences !== false && $audiences->length > 0) {
            $expectedAudience = $this->getSpEntityId($tenant);
            $found = false;
            foreach ($audiences as $audience) {
                if (trim($audience->textContent) === $expectedAudience) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                throw new RuntimeException('SAML assertion audience does not match SP entity ID');
            }
        }
    }

    private function extractUser(DOMDocument $doc): SsoUser
    {
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('saml', self::SAML_NS);

        $nameIdNodes = $xpath->query('//saml:Subject/saml:NameID');
        if ($nameIdNodes === false || $nameIdNodes->length === 0) {
            throw new RuntimeException('Missing NameID in SAML assertion');
        }

        $nameId = trim($nameIdNodes->item(0)->textContent);
        $email = $nameId;

        $attributes = [];
        $attrNodes = $xpath->query('//saml:AttributeStatement/saml:Attribute');
        if ($attrNodes !== false) {
            foreach ($attrNodes as $attr) {
                $name = $attr->getAttribute('Name');
                $values = $xpath->query('saml:AttributeValue', $attr);
                if ($values !== false && $values->length > 0) {
                    $attributes[$name] = $values->item(0)->textContent;
                }
            }
        }

        $emailMap = ['email', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
            'urn:oid:0.9.2342.19200300.100.1.3', 'mail'];
        foreach ($emailMap as $key) {
            if (isset($attributes[$key])) {
                $email = $attributes[$key];
                break;
            }
        }

        $firstName = $attributes['firstName'] ?? $attributes['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname'] ?? $attributes['urn:oid:2.5.4.42'] ?? null;
        $lastName = $attributes['lastName'] ?? $attributes['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname'] ?? $attributes['urn:oid:2.5.4.4'] ?? null;

        $sessionIndex = null;
        $authnNodes = $xpath->query('//saml:AuthnStatement');
        if ($authnNodes !== false && $authnNodes->length > 0) {
            $sessionIndex = $authnNodes->item(0)->getAttribute('SessionIndex') ?: null;
        }

        return new SsoUser(
            nameId: $nameId,
            email: $email,
            firstName: $firstName,
            lastName: $lastName,
            attributes: $attributes,
            sessionIndex: $sessionIndex,
        );
    }
}
