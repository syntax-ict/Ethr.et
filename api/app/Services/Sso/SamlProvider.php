<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Models\SsoSetting;
use App\Models\Tenant;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class SamlProvider implements SsoProviderInterface
{
    private const SAML_NS = 'urn:oasis:names:tc:SAML:2.0:assertion';

    private const DS_NS = 'http://www.w3.org/2000/09/xmldsig#';

    private const EC_NS = 'http://www.w3.org/2001/10/xml-exc-c14n#';

    private const ALG_ENVELOPED = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    private const ALG_C14N = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

    private const ALG_EXC_C14N = 'http://www.w3.org/2001/10/xml-exc-c14n#';

    /** Signature algorithms accepted: RSA with SHA-2 only. SHA-1 is deliberately absent. */
    private const SIGNATURE_ALGORITHMS = [
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256' => OPENSSL_ALGO_SHA256,
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha384' => OPENSSL_ALGO_SHA384,
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512' => OPENSSL_ALGO_SHA512,
    ];

    private const DIGEST_ALGORITHMS = [
        'http://www.w3.org/2001/04/xmlenc#sha256' => 'sha256',
        'http://www.w3.org/2001/04/xmldsig-more#sha384' => 'sha384',
        'http://www.w3.org/2001/04/xmlenc#sha512' => 'sha512',
    ];

    private const CLOCK_SKEW = 120;

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

        $doc = $this->loadResponse($xml);

        $assertion = $this->verifiedAssertion($doc, $settings);
        $this->verifyIssuer($assertion, $settings);
        $notOnOrAfter = $this->verifyConditions($assertion, $tenant);
        $this->verifySubjectConfirmation($assertion, $tenant);
        $this->rejectReplay($assertion, $tenant, $notOnOrAfter);

        return $this->extractUser($assertion);
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

    /**
     * A SAML response is attacker-supplied XML, so it is parsed without network
     * access or entity expansion and a DOCTYPE is refused outright — the IdPs in
     * use never send one, and it is the carrier for XXE and entity-expansion abuse.
     */
    private function loadResponse(string $xml): DOMDocument
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $doc->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded === false || $doc->documentElement === null) {
            throw new RuntimeException('SAMLResponse is not valid XML');
        }
        if ($doc->doctype !== null) {
            throw new RuntimeException('SAMLResponse must not contain a DOCTYPE');
        }

        return $doc;
    }

    private function xpath(DOMDocument $doc): DOMXPath
    {
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('ds', self::DS_NS);
        $xpath->registerNamespace('ec', self::EC_NS);
        $xpath->registerNamespace('saml', self::SAML_NS);

        return $xpath;
    }

    /**
     * Return the one assertion this response is allowed to be read from, or throw.
     *
     * The previous implementation checked that *some* `SignedInfo` carried a valid
     * signature, never that the signature covered anything, and then read the
     * NameID from the first match anywhere in the document. Trust is now derived
     * only from a signature that verifies and whose Reference points at the
     * assertion itself, or at the Response that directly contains it.
     */
    private function verifiedAssertion(DOMDocument $doc, SsoSetting $settings): DOMElement
    {
        $xpath = $this->xpath($doc);

        if ($xpath->query('//saml:EncryptedAssertion')->length > 0) {
            throw new RuntimeException('Encrypted SAML assertions are not supported');
        }

        $assertions = $xpath->query('//saml:Assertion');
        if ($assertions === false || $assertions->length !== 1) {
            throw new RuntimeException('SAML response must contain exactly one assertion');
        }

        /** @var DOMElement $assertion */
        $assertion = $assertions->item(0);
        $root = $doc->documentElement;

        $signatures = $xpath->query('//ds:Signature');
        if ($signatures === false || $signatures->length === 0) {
            throw new RuntimeException('SAML response is not signed');
        }

        $publicKey = $this->publicKey($settings);

        $trusted = false;
        foreach ($signatures as $signature) {
            $parent = $signature->parentNode;
            // Only a signature enveloped directly in the assertion or in the
            // response can vouch for the assertion; any other is noise.
            if (! ($parent->isSameNode($assertion) || $parent->isSameNode($root))) {
                continue;
            }

            $signed = $this->verifyEnvelopedSignature($xpath, $signature, $publicKey);

            if ($signed->isSameNode($assertion)
                || ($signed->isSameNode($root) && $assertion->parentNode->isSameNode($root))) {
                $trusted = true;
            }
        }

        if (! $trusted) {
            throw new RuntimeException('SAML assertion is not covered by a valid signature');
        }

        return $assertion;
    }

    private function publicKey(SsoSetting $settings): \OpenSSLAsymmetricKey
    {
        $pem = trim((string) $settings->idp_certificate);
        if ($pem !== '' && ! str_contains($pem, '-----BEGIN')) {
            $pem = "-----BEGIN CERTIFICATE-----\n"
                .chunk_split((string) preg_replace('/\s+/', '', $pem), 64, "\n")
                ."-----END CERTIFICATE-----\n";
        }

        $cert = openssl_x509_read($pem);
        if ($cert === false) {
            throw new RuntimeException('Invalid IdP certificate');
        }

        $key = openssl_pkey_get_public($cert);
        if ($key === false) {
            throw new RuntimeException('Cannot extract public key from IdP certificate');
        }

        return $key;
    }

    /**
     * Verify one enveloped XML signature and return the element it signs.
     * `KeyInfo` is ignored on purpose: the only key trusted is the tenant's
     * configured IdP certificate.
     */
    private function verifyEnvelopedSignature(DOMXPath $xpath, DOMNode $signature, \OpenSSLAsymmetricKey $publicKey): DOMElement
    {
        $signedInfo = $this->single($xpath, 'ds:SignedInfo', $signature, 'SignedInfo');
        $signatureValue = $this->single($xpath, 'ds:SignatureValue', $signature, 'SignatureValue');

        $c14nAlgorithm = (string) $xpath->evaluate('string(ds:CanonicalizationMethod/@Algorithm)', $signedInfo);
        if (! in_array($c14nAlgorithm, [self::ALG_C14N, self::ALG_EXC_C14N], true)) {
            throw new RuntimeException('Unsupported SignedInfo canonicalization');
        }

        $signatureAlgorithm = (string) $xpath->evaluate('string(ds:SignatureMethod/@Algorithm)', $signedInfo);
        if (! isset(self::SIGNATURE_ALGORITHMS[$signatureAlgorithm])) {
            throw new RuntimeException('Unsupported SAML signature algorithm');
        }

        $canonicalSignedInfo = $signedInfo->C14N($c14nAlgorithm === self::ALG_EXC_C14N, false);
        $rawSignature = base64_decode((string) preg_replace('/\s+/', '', $signatureValue->textContent), true);
        if ($canonicalSignedInfo === false || $rawSignature === false || $rawSignature === '') {
            throw new RuntimeException('Cannot decode signature data');
        }

        if (openssl_verify($canonicalSignedInfo, $rawSignature, $publicKey, self::SIGNATURE_ALGORITHMS[$signatureAlgorithm]) !== 1) {
            throw new RuntimeException('SAML response signature verification failed');
        }

        // The signature is authentic. Now check that it covers what it claims to:
        // exactly one Reference, to an element we can identify unambiguously.
        $references = $xpath->query('ds:Reference', $signedInfo);
        if ($references === false || $references->length !== 1) {
            throw new RuntimeException('SAML signature must have exactly one Reference');
        }
        $reference = $references->item(0);

        $uri = (string) $xpath->evaluate('string(@URI)', $reference);
        if (preg_match('/^#([A-Za-z_][A-Za-z0-9_.\-]*)$/', $uri, $m) !== 1) {
            throw new RuntimeException('Unsupported SAML Reference URI');
        }

        $targets = $xpath->query('//*[@ID="'.$m[1].'"]');
        if ($targets === false || $targets->length !== 1) {
            throw new RuntimeException('SAML Reference does not resolve to exactly one element');
        }

        /** @var DOMElement $target */
        $target = $targets->item(0);
        if (! $target->isSameNode($signature->parentNode)) {
            throw new RuntimeException('SAML signature is not enveloped in the element it references');
        }

        [$exclusive, $prefixes] = $this->referenceTransforms($xpath, $reference);

        $canonical = $target->C14N($exclusive, false, [
            'query' => '(.//. | .//@* | .//namespace::*)[not(ancestor-or-self::ds:Signature)]',
            'namespaces' => ['ds' => self::DS_NS],
        ], $prefixes);

        $digestAlgorithm = (string) $xpath->evaluate('string(ds:DigestMethod/@Algorithm)', $reference);
        if ($canonical === false || ! isset(self::DIGEST_ALGORITHMS[$digestAlgorithm])) {
            throw new RuntimeException('Unsupported SAML digest algorithm');
        }

        $digestValue = $this->single($xpath, 'ds:DigestValue', $reference, 'DigestValue');
        $expected = base64_decode((string) preg_replace('/\s+/', '', $digestValue->textContent), true);
        $actual = hash(self::DIGEST_ALGORITHMS[$digestAlgorithm], $canonical, true);

        if ($expected === false || ! hash_equals($actual, $expected)) {
            throw new RuntimeException('SAML digest does not match the signed content');
        }

        return $target;
    }

    /**
     * @return array{0: bool, 1: list<string>|null} exclusive flag and InclusiveNamespaces prefixes
     */
    private function referenceTransforms(DOMXPath $xpath, DOMNode $reference): array
    {
        $enveloped = false;
        $exclusive = false;
        $prefixes = null;

        foreach ($this->elements($xpath, 'ds:Transforms/ds:Transform', $reference) as $transform) {
            $algorithm = $transform->getAttribute('Algorithm');

            if ($algorithm === self::ALG_ENVELOPED) {
                $enveloped = true;
            } elseif ($algorithm === self::ALG_EXC_C14N || $algorithm === self::ALG_C14N) {
                $exclusive = $algorithm === self::ALG_EXC_C14N;
                $list = trim((string) $xpath->evaluate('string(ec:InclusiveNamespaces/@PrefixList)', $transform));
                $prefixes = $list === '' ? null : preg_split('/\s+/', $list);
            } else {
                throw new RuntimeException('Unsupported SAML transform');
            }
        }

        if (! $enveloped) {
            throw new RuntimeException('SAML signature must use the enveloped-signature transform');
        }

        return [$exclusive, $prefixes ?: null];
    }

    /**
     * Elements matching a query. DOMXPath returns DOMNode|DOMNameSpaceNode; only
     * elements carry attributes, and nothing this class reads is anything else.
     *
     * @return list<DOMElement>
     */
    private function elements(DOMXPath $xpath, string $query, ?DOMNode $context = null): array
    {
        $nodes = $context === null ? $xpath->query($query) : $xpath->query($query, $context);

        $elements = [];
        foreach ($nodes ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    private function single(DOMXPath $xpath, string $query, DOMNode $context, string $what): DOMElement
    {
        $elements = $this->elements($xpath, $query, $context);
        if (count($elements) !== 1) {
            throw new RuntimeException("SAML signature must have exactly one {$what}");
        }

        return $elements[0];
    }

    private function verifyIssuer(DOMElement $assertion, SsoSetting $settings): void
    {
        $xpath = $this->xpath($assertion->ownerDocument);
        $issuer = trim((string) $xpath->evaluate('string(saml:Issuer)', $assertion));

        if ($issuer === '' || $issuer !== $settings->idp_entity_id) {
            throw new RuntimeException('SAML assertion issuer does not match the configured IdP');
        }
    }

    /**
     * An assertion with no expiry can be replayed forever and one with no audience
     * was not minted for this service provider, so both are required rather than
     * checked only when present.
     *
     * @return int the NotOnOrAfter instant, for replay bookkeeping
     */
    private function verifyConditions(DOMElement $assertion, Tenant $tenant): int
    {
        $xpath = $this->xpath($assertion->ownerDocument);

        $conditions = $this->elements($xpath, 'saml:Conditions', $assertion);
        if (count($conditions) !== 1) {
            throw new RuntimeException('SAML assertion must carry exactly one Conditions element');
        }
        $condition = $conditions[0];

        $now = time();

        $notOnOrAfter = $this->instant($condition->getAttribute('NotOnOrAfter'), 'NotOnOrAfter');
        if ($notOnOrAfter === null) {
            throw new RuntimeException('SAML assertion has no expiry');
        }
        if ($notOnOrAfter < $now - self::CLOCK_SKEW) {
            throw new RuntimeException('SAML assertion has expired');
        }

        $notBefore = $this->instant($condition->getAttribute('NotBefore'), 'NotBefore');
        if ($notBefore !== null && $notBefore > $now + self::CLOCK_SKEW) {
            throw new RuntimeException('SAML assertion is not yet valid');
        }

        $expectedAudience = $this->getSpEntityId($tenant);
        $found = false;
        foreach ($this->elements($xpath, 'saml:AudienceRestriction/saml:Audience', $condition) as $audience) {
            if (trim($audience->textContent) === $expectedAudience) {
                $found = true;
                break;
            }
        }
        if (! $found) {
            throw new RuntimeException('SAML assertion audience does not match SP entity ID');
        }

        return $notOnOrAfter;
    }

    private function instant(string $value, string $what): ?int
    {
        if ($value === '') {
            return null;
        }

        $time = strtotime($value);
        if ($time === false) {
            throw new RuntimeException("SAML {$what} is not a valid instant");
        }

        return $time;
    }

    private function verifySubjectConfirmation(DOMElement $assertion, Tenant $tenant): void
    {
        $xpath = $this->xpath($assertion->ownerDocument);

        foreach ($this->elements($xpath, 'saml:Subject/saml:SubjectConfirmation/saml:SubjectConfirmationData', $assertion) as $node) {
            $recipient = $node->getAttribute('Recipient');
            if ($recipient !== '' && $recipient !== $this->getAcsUrl($tenant)) {
                throw new RuntimeException('SAML assertion recipient does not match the ACS URL');
            }

            $expiry = $this->instant($node->getAttribute('NotOnOrAfter'), 'SubjectConfirmationData NotOnOrAfter');
            if ($expiry !== null && $expiry < time() - self::CLOCK_SKEW) {
                throw new RuntimeException('SAML subject confirmation has expired');
            }
        }
    }

    /** A given assertion is accepted once; presenting it again is a replay. */
    private function rejectReplay(DOMElement $assertion, Tenant $tenant, int $notOnOrAfter): void
    {
        $id = $assertion->getAttribute('ID');
        if ($id === '') {
            throw new RuntimeException('SAML assertion has no ID');
        }

        $ttl = max(60, $notOnOrAfter - time() + self::CLOCK_SKEW);

        if (! Cache::add('sso:saml:assertion:'.$tenant->id.':'.hash('sha256', $id), true, $ttl)) {
            throw new RuntimeException('SAML assertion has already been used');
        }
    }

    private function extractUser(DOMElement $assertion): SsoUser
    {
        $xpath = $this->xpath($assertion->ownerDocument);

        $nameIdNodes = $xpath->query('saml:Subject/saml:NameID', $assertion);
        if ($nameIdNodes === false || $nameIdNodes->length === 0) {
            throw new RuntimeException('Missing NameID in SAML assertion');
        }

        $nameId = trim($nameIdNodes->item(0)->textContent);
        $email = $nameId;

        $attributes = [];
        foreach ($this->elements($xpath, 'saml:AttributeStatement/saml:Attribute', $assertion) as $attr) {
            $values = $this->elements($xpath, 'saml:AttributeValue', $attr);
            if ($values !== []) {
                $attributes[$attr->getAttribute('Name')] = $values[0]->textContent;
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
        $authn = $this->elements($xpath, 'saml:AuthnStatement', $assertion);
        if ($authn !== []) {
            $sessionIndex = $authn[0]->getAttribute('SessionIndex') ?: null;
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
