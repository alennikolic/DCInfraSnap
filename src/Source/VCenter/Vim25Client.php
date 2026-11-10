<?php
// src/Source/VCenter/Vim25Client.php
//
// The vCenter client, over the vSphere Web Services API (vim25, /sdk).
//
// In step 2 it does the session and nothing more: read ServiceContent, sign
// in, sign out. Step 3 adds inventory on top, using the managed object
// references this class already collects (rootFolder, propertyCollector,
// viewManager, licenseManager).
//
// The order matters. RetrieveServiceContent works WITHOUT signing in, and
// that is useful: if it succeeds and Login then fails, the address is right
// and the credentials are wrong — two different problems with two different
// fixes, and the connection test can say which.
//
// Why XML is read via local-name(): vCenter's reply uses several namespaces
// and the prefixes differ between versions. Matching on the local name
// survives that; registering prefixes does not.

declare(strict_types=1);

namespace DcInfraSnap\Source\VCenter;

use DOMDocument;
use DOMXPath;
use SensitiveParameter;

final class Vim25Client
{
    public ?string $rootFolder = null;
    public ?string $propertyCollector = null;
    public ?string $sessionManager = null;
    public ?string $viewManager = null;
    public ?string $licenseManager = null;

    /** e.g. "8.0.3" */
    public ?string $productVersion = null;

    /** e.g. "VMware vCenter Server 8.0.3 build-24322831" */
    public ?string $productFullName = null;

    /** e.g. "8.0.3.0" — needed in the SOAPAction header. */
    public ?string $apiVersion = null;

    public function __construct(
        private readonly SoapTransport $http,
        private readonly string $username,
        #[SensitiveParameter] private readonly string $password,
    ) {
    }

    public function callCount(): int
    {
        return $this->http->callCount();
    }

    // -----------------------------------------------------------------
    // Session
    // -----------------------------------------------------------------

    public function login(): void
    {
        if ($this->http->hasSession()) {
            return;
        }

        $this->retrieveServiceContent();

        $this->http->call('Login', sprintf(
            '<Login xmlns="urn:vim25">'
            . '<_this type="SessionManager">%s</_this>'
            . '<userName>%s</userName>'
            . '<password>%s</password>'
            . '</Login>',
            self::escape($this->sessionManager ?? 'SessionManager'),
            self::escape($this->username),
            self::escape($this->password)
        ));
    }

    public function logout(): void
    {
        if (!$this->http->hasSession()) {
            return;
        }

        try {
            $this->http->call('Logout', sprintf(
                '<Logout xmlns="urn:vim25"><_this type="SessionManager">%s</_this></Logout>',
                self::escape($this->sessionManager ?? 'SessionManager')
            ));
        } catch (VCenterException) {
            // A failed sign-out is not worth reporting — the session expires
            // on its own, and the caller already has what it came for.
        }

        $this->http->forgetSession();
    }

    /**
     * Sign in, read the version, sign out.
     *
     * This is what the "Test connection" button and integration:test run. It
     * leaves no session behind on the vCenter.
     *
     * @return array{version: string|null, full_name: string|null, api_version: string|null}
     */
    public function testConnection(): array
    {
        try {
            $this->login();

            return [
                'version'     => $this->productVersion,
                'full_name'   => $this->productFullName,
                'api_version' => $this->apiVersion,
            ];
        } finally {
            $this->logout();
        }
    }

    // -----------------------------------------------------------------
    // ServiceContent
    // -----------------------------------------------------------------

    public function retrieveServiceContent(): void
    {
        $xml = $this->http->call(
            'RetrieveServiceContent',
            '<RetrieveServiceContent xmlns="urn:vim25">'
            . '<_this type="ServiceInstance">ServiceInstance</_this>'
            . '</RetrieveServiceContent>'
        );

        $xpath = self::xpath($xml);

        $this->rootFolder        = self::text($xpath, '//*[local-name()="rootFolder"]');
        $this->propertyCollector = self::text($xpath, '//*[local-name()="propertyCollector"]');
        $this->sessionManager    = self::text($xpath, '//*[local-name()="sessionManager"]');
        $this->viewManager       = self::text($xpath, '//*[local-name()="viewManager"]');
        $this->licenseManager    = self::text($xpath, '//*[local-name()="licenseManager"]');

        $this->productVersion  = self::text($xpath, '//*[local-name()="about"]/*[local-name()="version"]');
        $this->productFullName = self::text($xpath, '//*[local-name()="about"]/*[local-name()="fullName"]');
        $this->apiVersion      = self::text($xpath, '//*[local-name()="about"]/*[local-name()="apiVersion"]');

        // SOAPAction has to carry the API version, or vCenter reports newer
        // methods as non-existent.
        $this->http->setApiVersion($this->apiVersion);

        if ($this->rootFolder === null || $this->propertyCollector === null) {
            throw VCenterException::notVCenter('this endpoint');
        }
    }

    // -----------------------------------------------------------------
    // XML helpers
    // -----------------------------------------------------------------

    public static function xpath(string $xml): DOMXPath
    {
        // An empty body has to be caught here: loadXML('') throws a
        // ValueError in PHP 8, which is not an Exception and would escape
        // every catch in the call chain as a fatal error. A proxy or a
        // half-open connection returning nothing is an ordinary failure and
        // deserves an ordinary message.
        if (trim($xml) === '') {
            throw new VCenterException('vCenter returned an empty reply.');
        }

        $doc = new DOMDocument();

        // vCenter's XML is machine-generated and well-formed; whitespace
        // nodes only make the tree bigger.
        $doc->preserveWhiteSpace = false;

        // libxml errors are collected rather than printed, so a malformed
        // reply becomes an exception instead of a warning in the middle of
        // the page.
        $previous = libxml_use_internal_errors(true);
        $loaded   = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            throw new VCenterException('vCenter returned a reply that is not valid XML.');
        }

        return new DOMXPath($doc);
    }

    public static function text(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $value = trim((string) $nodes->item(0)?->textContent);

        return $value === '' ? null : $value;
    }

    /**
     * Escape a value for insertion into the request XML.
     *
     * Passwords regularly contain &, < and >, and an unescaped one produces
     * a malformed envelope that vCenter rejects with a parse error — which
     * reads like a protocol problem rather than a quoting one.
     */
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
