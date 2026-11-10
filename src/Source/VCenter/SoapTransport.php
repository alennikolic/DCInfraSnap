<?php
// src/Source/VCenter/SoapTransport.php
//
// Sends SOAP requests to a vCenter's /sdk endpoint.
//
// Why hand-built XML over curl rather than PHP's SoapClient: the vSphere WSDL
// and SoapClient do not get along. SoapClient emits an xsi:type prefix that
// vCenter rejects, sets the SOAPAction header wrongly (so vCenter reports the
// method does not exist), and the WSDL itself is enormous and slow to parse.
// Assembling the XML by hand is the shorter road.
//
// Two things SOAP needs that a REST call would not:
//
//   1. A session cookie. After Login, vCenter returns vmware_soap_session and
//      expects it on every subsequent request.
//
//   2. SOAPAction carrying the API version. Without the exact version,
//      vCenter reports newer methods as non-existent, so the version is read
//      from ServiceContent and used from the second call onwards.

declare(strict_types=1);

namespace DcInfraSnap\Source\VCenter;

// Not final so a test can substitute a transport that replays a recorded
// response instead of calling a real vCenter.
class SoapTransport
{
    private const CONNECT_TIMEOUT = 10;

    /** Session cookie, obtained after Login. */
    private ?string $sessionCookie = null;

    /** API version for SOAPAction; set from ServiceContent. */
    private ?string $apiVersion = null;

    private int $callCount = 0;

    public function __construct(
        private readonly string $baseUrl,
        private readonly bool $verifyPeer,
        private readonly string $host,
        private readonly int $timeout = 120,
    ) {
    }

    public function callCount(): int
    {
        return $this->callCount;
    }

    public function setApiVersion(?string $version): void
    {
        $this->apiVersion = $version;
    }

    public function hasSession(): bool
    {
        return $this->sessionCookie !== null;
    }

    public function forgetSession(): void
    {
        $this->sessionCookie = null;
    }

    /**
     * Send a SOAP request body and return the raw XML of the response.
     *
     * @param string $body the contents of <soapenv:Body>, without the wrapper
     */
    public function call(string $operation, string $body): string
    {
        $envelope = $this->envelope($body);

        $headers = [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "' . ($this->apiVersion === null ? 'urn:vim25' : 'urn:vim25/' . $this->apiVersion) . '"',

            // Without this, curl sends "Expect: 100-continue" and waits.
            'Expect:',
        ];

        if ($this->sessionCookie !== null) {
            $headers[] = 'Cookie: ' . $this->sessionCookie;
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->baseUrl . '/sdk',
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $envelope,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifyPeer,
            CURLOPT_SSL_VERIFYHOST => $this->verifyPeer ? 2 : 0,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line): int {
                $this->captureCookie($line);

                return strlen($line);
            },
        ]);

        $this->callCount++;

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);

        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            throw $this->translateCurlError($errno, $error);
        }

        $xml = (string) $raw;

        // vCenter returns errors as soapenv:Fault, with HTTP 500.
        if ($status === 500 || str_contains($xml, 'Fault>')) {
            throw $this->translateFault($operation, $xml, $status);
        }

        if ($status < 200 || $status >= 300) {
            throw VCenterException::unexpected($status, '/sdk (' . $operation . ')');
        }

        return $xml;
    }

    // -----------------------------------------------------------------

    private function envelope(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope'
            . ' xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"'
            . ' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<soapenv:Body>' . $body . '</soapenv:Body>'
            . '</soapenv:Envelope>';
    }

    private function captureCookie(string $headerLine): void
    {
        if (stripos($headerLine, 'Set-Cookie:') !== 0) {
            return;
        }

        $value = trim(substr($headerLine, 11));
        $pair  = explode(';', $value, 2)[0];

        if (str_starts_with($pair, 'vmware_soap_session')) {
            $this->sessionCookie = $pair;
        }
    }

    private function translateFault(string $operation, string $xml, int $status): VCenterException
    {
        $message = '';

        if (preg_match('#<faultstring[^>]*>(.*?)</faultstring>#s', $xml, $m) === 1) {
            $message = trim(html_entity_decode(strip_tags($m[1])));
        }

        // Recognise the kind of failure from the detail, where there is one.
        if (str_contains($xml, 'InvalidLogin') || str_contains($xml, 'NotAuthenticated')) {
            return VCenterException::badCredentials();
        }

        if (str_contains($xml, 'NoPermission')) {
            return VCenterException::forbidden('read this part of the inventory');
        }

        return new VCenterException(
            sprintf(
                'vCenter returned an error on SOAP call %s%s',
                $operation,
                $message === '' ? '.' : ': ' . mb_substr($message, 0, 300)
            ),
            $status
        );
    }

    /**
     * Numbers rather than the CURLE_* constants, because some of them are
     * missing from certain PHP builds.
     */
    private function translateCurlError(int $errno, string $message): VCenterException
    {
        return match ($errno) {
            35, 51, 58, 59, 60, 77, 83 => VCenterException::tls($this->host, $message),
            7  => VCenterException::unreachable($this->host, 'connection refused'),
            6  => VCenterException::unreachable($this->host, 'the name does not resolve'),
            28 => VCenterException::unreachable($this->host, 'timed out'),
            52 => VCenterException::unreachable($this->host, 'empty reply'),
            56 => VCenterException::unreachable($this->host, 'connection reset'),
            default => VCenterException::unreachable($this->host, $message),
        };
    }
}
