<?php
// src/Source/VCenter/Vim25Client.php
//
// The vCenter client, over the vSphere Web Services API (vim25, /sdk).
//
// The sequence for a collection run:
//   1. RetrieveServiceContent -> rootFolder, propertyCollector, API version
//   2. Login                  -> session cookie
//   3. CreateContainerView    -> a view over every object of some types
//   4. RetrievePropertiesEx   -> the requested fields for ALL of them at
//                                once, continuing while a token comes back
//   5. DestroyView
//   6. Logout
//
// Why a ContainerView rather than a hand-built TraversalSpec: the same
// result with a tenth of the XML. The classic approach needs a chain of
// specs through Datacenter -> hostFolder -> ComputeResource -> host, each
// with its own name and links.
//
// Why the XML is read via local-name(): vCenter's reply uses several
// namespaces and the prefixes differ between versions. Matching on the local
// name survives that; registering prefixes does not.
//
// The values this class returns are normalized on the way out —
// poweredOn becomes POWERED_ON, and so on — so the shape of the data does
// not depend on which API happened to produce it.

declare(strict_types=1);

namespace DcInfraSnap\Source\VCenter;

use DOMDocument;
use DOMElement;
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

    private ?EntityTree $tree = null;

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

    // =================================================================
    // Session
    // =================================================================

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
     * Sign in, read the version, sign out. Leaves no session behind.
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

    // =================================================================
    // Retrieving properties
    // =================================================================

    /**
     * Fields for every object of the given types, in one pass.
     *
     * @param array<string, list<string>> $propsByType vim25 type => property paths
     *
     * @return array<string, array<string, mixed>> moref => properties, plus _type
     */
    public function retrieve(array $propsByType): array
    {
        if ($propsByType === []) {
            return [];
        }

        $view = $this->createContainerView(array_keys($propsByType));

        try {
            $propSets = '';

            foreach ($propsByType as $type => $paths) {
                $propSets .= '<propSet><type>' . self::escape($type) . '</type>';

                foreach ($paths as $path) {
                    $propSets .= '<pathSet>' . self::escape($path) . '</pathSet>';
                }

                $propSets .= '</propSet>';
            }

            $xml = $this->http->call('RetrievePropertiesEx', sprintf(
                '<RetrievePropertiesEx xmlns="urn:vim25">'
                . '<_this type="PropertyCollector">%s</_this>'
                . '<specSet>'
                . '%s'
                . '<objectSet>'
                . '<obj type="ContainerView">%s</obj>'
                . '<skip>true</skip>'
                . '<selectSet xsi:type="TraversalSpec">'
                . '<name>view</name><type>ContainerView</type><path>view</path><skip>false</skip>'
                . '</selectSet>'
                . '</objectSet>'
                . '</specSet>'
                . '<options />'
                . '</RetrievePropertiesEx>',
                self::escape((string) $this->propertyCollector),
                $propSets,
                self::escape($view)
            ));

            $objects = [];
            $token   = $this->absorb($xml, $objects);

            // A large inventory comes back in pages. vCenter decides how big
            // a page is; the client simply keeps asking while a token comes
            // back.
            while ($token !== null) {
                $xml = $this->http->call('ContinueRetrievePropertiesEx', sprintf(
                    '<ContinueRetrievePropertiesEx xmlns="urn:vim25">'
                    . '<_this type="PropertyCollector">%s</_this>'
                    . '<token>%s</token>'
                    . '</ContinueRetrievePropertiesEx>',
                    self::escape((string) $this->propertyCollector),
                    self::escape($token)
                ));

                $token = $this->absorb($xml, $objects);
            }

            return $objects;
        } finally {
            $this->destroyView($view);
        }
    }

    /**
     * Fields of one specific object, with no container view.
     *
     * Used for the singletons — LicenseManager and friends — where creating
     * a view over one object would be two extra calls for nothing.
     *
     * @param list<string> $paths
     *
     * @return array<string, mixed>
     */
    public function retrieveOn(string $type, string $moref, array $paths): array
    {
        $pathSet = '';

        foreach ($paths as $path) {
            $pathSet .= '<pathSet>' . self::escape($path) . '</pathSet>';
        }

        $xml = $this->http->call('RetrievePropertiesEx', sprintf(
            '<RetrievePropertiesEx xmlns="urn:vim25">'
            . '<_this type="PropertyCollector">%s</_this>'
            . '<specSet>'
            . '<propSet><type>%s</type>%s</propSet>'
            . '<objectSet><obj type="%s">%s</obj><skip>false</skip></objectSet>'
            . '</specSet>'
            . '<options />'
            . '</RetrievePropertiesEx>',
            self::escape((string) $this->propertyCollector),
            self::escape($type),
            $pathSet,
            self::escape($type),
            self::escape($moref)
        ));

        $objects = [];
        $this->absorb($xml, $objects);

        return reset($objects) ?: [];
    }

    private function createContainerView(array $types): string
    {
        $typeXml = '';

        foreach ($types as $type) {
            $typeXml .= '<type>' . self::escape($type) . '</type>';
        }

        $xml = $this->http->call('CreateContainerView', sprintf(
            '<CreateContainerView xmlns="urn:vim25">'
            . '<_this type="ViewManager">%s</_this>'
            . '<container type="Folder">%s</container>'
            . '%s'
            . '<recursive>true</recursive>'
            . '</CreateContainerView>',
            self::escape($this->viewManager ?? 'ViewManager'),
            self::escape((string) $this->rootFolder),
            $typeXml
        ));

        $view = self::text(self::xpath($xml), '//*[local-name()="returnval"]');

        if ($view === null) {
            throw new VCenterException('vCenter did not return a container view.');
        }

        return $view;
    }

    private function destroyView(string $view): void
    {
        try {
            $this->http->call('DestroyView', sprintf(
                '<DestroyView xmlns="urn:vim25"><_this type="ContainerView">%s</_this></DestroyView>',
                self::escape($view)
            ));
        } catch (VCenterException) {
            // Views expire with the session. Failing to tidy one up is not a
            // reason to fail a collection that otherwise worked.
        }
    }

    /**
     * Read one page of a RetrievePropertiesEx reply into $objects.
     *
     * @param array<string, array<string, mixed>> $objects
     *
     * @return string|null the continuation token, when there is another page
     */
    private function absorb(string $xml, array &$objects): ?string
    {
        $xpath = self::xpath($xml);
        $nodes = $xpath->query('//*[local-name()="objects"]');

        if ($nodes !== false) {
            foreach ($nodes as $objectNode) {
                if (!$objectNode instanceof DOMElement) {
                    continue;
                }

                $objNode = $xpath->query('./*[local-name()="obj"]', $objectNode);

                if ($objNode === false || $objNode->length === 0) {
                    continue;
                }

                /** @var DOMElement $obj */
                $obj   = $objNode->item(0);
                $moref = trim($obj->textContent);

                $properties = ['_type' => $obj->getAttribute('type')];

                $propSets = $xpath->query('./*[local-name()="propSet"]', $objectNode);

                if ($propSets !== false) {
                    foreach ($propSets as $propSet) {
                        if (!$propSet instanceof DOMElement) {
                            continue;
                        }

                        $name = self::text($xpath, './*[local-name()="name"]', $propSet);
                        $val  = $xpath->query('./*[local-name()="val"]', $propSet);

                        if ($name === null || $val === false || $val->length === 0) {
                            continue;
                        }

                        /** @var DOMElement $valNode */
                        $valNode = $val->item(0);

                        $properties[$name] = self::valueOf($valNode);
                    }
                }

                $objects[$moref] = $properties;
            }
        }

        return self::text($xpath, '//*[local-name()="returnval"]/*[local-name()="token"]');
    }

    // =================================================================
    // XML to PHP values
    // =================================================================

    /**
     * Turn a <val> element into a plain PHP value.
     *
     * Scalars become strings, repeated children become lists, everything
     * else becomes a map. An element whose xsi:type starts with "ArrayOf" is
     * always a list, even when it holds a single item — otherwise a host
     * with one NIC would produce a different shape from a host with two, and
     * every reader would need to handle both.
     *
     * The xsi:type is kept as _type where there is one, because that is what
     * distinguishes a VirtualDisk from a VirtualEthernetCard in a device
     * list.
     */
    public static function valueOf(DOMElement $element): mixed
    {
        $xsiType  = $element->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type');
        $children = [];

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }

        if ($children === []) {
            $text = trim($element->textContent);

            return $text === '' ? null : $text;
        }

        if (str_starts_with($xsiType, 'ArrayOf')) {
            return array_map(static fn (DOMElement $c): mixed => self::valueOf($c), $children);
        }

        $out = [];

        foreach ($children as $child) {
            $name  = $child->localName;
            $value = self::valueOf($child);

            if (!array_key_exists($name, $out)) {
                $out[$name] = $value;
                continue;
            }

            // A repeated name is a list, whether or not it was declared one.
            if (!is_array($out[$name]) || !array_is_list($out[$name])) {
                $out[$name] = [$out[$name]];
            }

            $out[$name][] = $value;
        }

        if ($xsiType !== '') {
            $out['_type'] = $xsiType;
        }

        return $out;
    }

    public static function xpath(string $xml): DOMXPath
    {
        // An empty body has to be caught here: loadXML('') throws a
        // ValueError in PHP 8, which is not an Exception and would escape
        // every catch in the call chain as a fatal error.
        if (trim($xml) === '') {
            throw new VCenterException('vCenter returned an empty reply.');
        }

        $doc                     = new DOMDocument();
        $doc->preserveWhiteSpace = false;

        $previous = libxml_use_internal_errors(true);
        $loaded   = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_PARSEHUGE);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            throw new VCenterException('vCenter returned a reply that is not valid XML.');
        }

        return new DOMXPath($doc);
    }

    public static function text(DOMXPath $xpath, string $query, ?DOMElement $context = null): ?string
    {
        $nodes = $context === null ? $xpath->query($query) : $xpath->query($query, $context);

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
