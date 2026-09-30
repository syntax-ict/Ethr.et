<?php

declare(strict_types=1);

use App\Models\SsoSetting;
use App\Models\Tenant;
use App\Services\Sso\SsoProviderInterface;
use App\Services\Sso\SsoUser;

/*
 * SamlProvider used to verify a signature over SignedInfo without ever checking
 * the Reference digest, then read the NameID from the first match anywhere in the
 * document. Any IdP-signed SignedInfo therefore authenticated any assertion.
 *
 * These tests sign real responses with a throwaway key, using a signer written
 * independently of the provider, and then break each link in the chain.
 */

/** @return array{key: OpenSSLAsymmetricKey, cert: string} */
function samlKeyPair(string $name): array
{
    static $pairs = [];
    if (isset($pairs[$name])) {
        return $pairs[$name];
    }

    // Some Windows PHP builds have no readable openssl.cnf; fall back to XAMPP's.
    $candidates = [null, 'C:/xampp/php/extras/ssl/openssl.cnf', 'C:/xampp/apache/conf/openssl.cnf'];
    foreach ($candidates as $conf) {
        if ($conf !== null && ! is_file($conf)) {
            continue;
        }
        $cfg = $conf === null ? [] : ['config' => $conf];
        $key = @openssl_pkey_new($cfg + ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            continue;
        }
        $csr = openssl_csr_new(['commonName' => "idp-{$name}.test"], $key, $cfg);
        $x509 = $csr === false ? false : openssl_csr_sign($csr, null, $key, 1, $cfg);
        if ($x509 === false) {
            continue;
        }
        openssl_x509_export($x509, $pem);
        while (openssl_error_string() !== false) {
            // drain the error queue so it cannot leak into a later test
        }

        return $pairs[$name] = ['key' => $key, 'cert' => $pem];
    }

    test()->markTestSkipped('OpenSSL cannot generate keys on this machine.');
}

/**
 * Build a SAML response. `sign` is 'assertion', 'response' or 'none'.
 *
 * @param  array<string, mixed>  $o
 */
function samlResponseXml(Tenant $tenant, array $o = []): string
{
    $o += [
        'nameId' => 'jane@acme.test',
        'issuer' => 'https://idp.example.com',
        'audience' => config('app.url').'/api/v1/sso/saml/'.$tenant->subdomain.'/metadata',
        'notOnOrAfter' => '+5 minutes',
        'conditions' => true,
        'sign' => 'assertion',
        'signer' => 'idp',
        'assertionId' => '_a'.bin2hex(random_bytes(8)),
        'extra' => '',
    ];

    $fmt = fn (string $when): string => gmdate('Y-m-d\TH:i:s\Z', strtotime($when));
    $conditions = $o['conditions']
        ? '<saml:Conditions NotBefore="'.$fmt('-1 minute').'" NotOnOrAfter="'.$fmt($o['notOnOrAfter']).'">'
            .'<saml:AudienceRestriction><saml:Audience>'.$o['audience'].'</saml:Audience></saml:AudienceRestriction>'
            .'</saml:Conditions>'
        : '';

    $xml = '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"'
        .' xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_resp1" Version="2.0"'
        .' IssueInstant="'.$fmt('now').'">'
        .'<saml:Issuer>'.$o['issuer'].'</saml:Issuer>'
        .$o['extra']
        .'<saml:Assertion ID="'.$o['assertionId'].'" Version="2.0" IssueInstant="'.$fmt('now').'">'
        .'<saml:Issuer>'.$o['issuer'].'</saml:Issuer>'
        .'<saml:Subject><saml:NameID>'.$o['nameId'].'</saml:NameID></saml:Subject>'
        .$conditions
        .'<saml:AuthnStatement SessionIndex="_s1"/>'
        .'</saml:Assertion>'
        .'</samlp:Response>';

    if ($o['sign'] === 'none') {
        return $xml;
    }

    $doc = new DOMDocument;
    $doc->loadXML($xml);
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');

    $target = $o['sign'] === 'response'
        ? $doc->documentElement
        : $xp->query('//saml:Assertion[@ID="'.$o['assertionId'].'"]')->item(0);
    $id = $target->getAttribute('ID');

    $digest = base64_encode(hash('sha256', $target->C14N(true, false), true));
    $ds = 'http://www.w3.org/2000/09/xmldsig#';

    $sigXml = '<ds:Signature xmlns:ds="'.$ds.'"><ds:SignedInfo>'
        .'<ds:CanonicalizationMethod Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/>'
        .'<ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"/>'
        .'<ds:Reference URI="#'.$id.'"><ds:Transforms>'
        .'<ds:Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/>'
        .'<ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/>'
        .'</ds:Transforms><ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
        .'<ds:DigestValue>'.$digest.'</ds:DigestValue></ds:Reference></ds:SignedInfo>'
        .'<ds:SignatureValue></ds:SignatureValue></ds:Signature>';

    $frag = new DOMDocument;
    $frag->loadXML($sigXml);
    $signature = $doc->importNode($frag->documentElement, true);
    // Schema position: directly after the Issuer of the element being signed.
    $issuer = $xp->query('saml:Issuer', $target)->item(0);
    $target->insertBefore($signature, $issuer->nextSibling);

    $sx = new DOMXPath($doc);
    $sx->registerNamespace('ds', $ds);
    $signedInfo = $sx->query('ds:SignedInfo', $signature)->item(0);
    openssl_sign($signedInfo->C14N(true, false), $raw, samlKeyPair($o['signer'])['key'], OPENSSL_ALGO_SHA256);
    $sx->query('ds:SignatureValue', $signature)->item(0)->textContent = base64_encode($raw);

    return $doc->saveXML();
}

function samlTenant(): Tenant
{
    $tenant = createTenant(['subdomain' => 'acme']);
    SsoSetting::create([
        'tenant_id' => $tenant->id,
        'provider' => 'saml',
        'is_enabled' => true,
        'idp_entity_id' => 'https://idp.example.com',
        'idp_sso_url' => 'https://idp.example.com/sso',
        'idp_certificate' => samlKeyPair('idp')['cert'],
    ]);

    return $tenant;
}

function samlLogin(Tenant $tenant, string $xml): SsoUser
{
    return app(SsoProviderInterface::class)
        ->handleCallback($tenant, ['SAMLResponse' => base64_encode($xml)]);
}

it('accepts a response whose assertion is signed', function () {
    $tenant = samlTenant();

    $user = samlLogin($tenant, samlResponseXml($tenant));

    expect($user->email)->toBe('jane@acme.test')
        ->and($user->sessionIndex)->toBe('_s1');
});

it('accepts a response whose envelope is signed', function () {
    $tenant = samlTenant();

    $user = samlLogin($tenant, samlResponseXml($tenant, ['sign' => 'response']));

    expect($user->email)->toBe('jane@acme.test');
});

it('rejects an assertion altered after it was signed', function () {
    $tenant = samlTenant();
    $xml = str_replace('jane@acme.test', 'admin@acme.test', samlResponseXml($tenant));

    expect(fn () => samlLogin($tenant, $xml))
        ->toThrow(RuntimeException::class, 'digest does not match');
});

it('rejects a response signed with a key that is not the configured IdP key', function () {
    $tenant = samlTenant();

    expect(fn () => samlLogin($tenant, samlResponseXml($tenant, ['signer' => 'attacker'])))
        ->toThrow(RuntimeException::class, 'signature verification failed');
});

it('rejects a second, unsigned assertion smuggled in next to a signed one', function () {
    $tenant = samlTenant();
    $forged = '<saml:Assertion ID="_evil" Version="2.0"><saml:Subject><saml:NameID>admin@acme.test</saml:NameID></saml:Subject></saml:Assertion>';

    expect(fn () => samlLogin($tenant, samlResponseXml($tenant, ['sign' => 'assertion', 'extra' => $forged])))
        ->toThrow(RuntimeException::class, 'exactly one assertion');
});

it('rejects a response that carries no signature', function () {
    $tenant = samlTenant();

    expect(fn () => samlLogin($tenant, samlResponseXml($tenant, ['sign' => 'none'])))
        ->toThrow(RuntimeException::class, 'not signed');
});

it('rejects an assertion issued by someone other than the configured IdP', function () {
    $tenant = samlTenant();

    expect(fn () => samlLogin($tenant, samlResponseXml($tenant, ['issuer' => 'https://evil.example.com'])))
        ->toThrow(RuntimeException::class, 'issuer');
});

it('rejects an assertion minted for a different audience', function () {
    $tenant = samlTenant();

    expect(fn () => samlLogin($tenant, samlResponseXml($tenant, ['audience' => 'https://other-sp.example.com'])))
        ->toThrow(RuntimeException::class, 'audience');
});

it('rejects an expired assertion', function () {
    $tenant = samlTenant();

    expect(fn () => samlLogin($tenant, samlResponseXml($tenant, ['notOnOrAfter' => '-1 hour'])))
        ->toThrow(RuntimeException::class, 'expired');
});

it('rejects an assertion with no Conditions, which would never expire and has no audience', function () {
    $tenant = samlTenant();

    expect(fn () => samlLogin($tenant, samlResponseXml($tenant, ['conditions' => false])))
        ->toThrow(RuntimeException::class, 'Conditions');
});

it('accepts an assertion once and rejects its replay', function () {
    $tenant = samlTenant();
    $xml = samlResponseXml($tenant);

    samlLogin($tenant, $xml);

    expect(fn () => samlLogin($tenant, $xml))
        ->toThrow(RuntimeException::class, 'already been used');
});

it('refuses a document with a DOCTYPE', function () {
    $tenant = samlTenant();
    // After the XML declaration, where a DOCTYPE is legal — so this fails on the
    // DOCTYPE itself and not on malformed XML.
    $xml = preg_replace('/(<\?xml[^>]*\?>)/', '$1<!DOCTYPE r [<!ENTITY x "y">]>', samlResponseXml($tenant), 1);

    expect(fn () => samlLogin($tenant, $xml))
        ->toThrow(RuntimeException::class, 'DOCTYPE');
});
