<?php
// src/Source/VCenter/VCenterException.php
//
// Anything that goes wrong talking to a vCenter.
//
// The named constructors exist so the message says what to do about it. The
// difference between "the name does not resolve", "the certificate is not
// trusted" and "the credentials are wrong" is the whole diagnosis, and a
// generic "connection failed" throws it away.

declare(strict_types=1);

namespace DcInfraSnap\Source\VCenter;

use RuntimeException;

final class VCenterException extends RuntimeException
{
    public static function unreachable(string $host, string $detail): self
    {
        return new self("Cannot reach {$host}: {$detail}.");
    }

    public static function tls(string $host, string $detail): self
    {
        return new self(
            "TLS error talking to {$host}: {$detail}. "
            . 'A vCenter with a self-signed certificate needs certificate checking turned off '
            . 'for this source.'
        );
    }

    public static function badCredentials(): self
    {
        return new self('vCenter rejected the credentials: wrong username or password.');
    }

    public static function forbidden(string $what): self
    {
        return new self("The account is not permitted to do this: {$what}.");
    }

    public static function notVCenter(string $host): self
    {
        return new self(
            "{$host} answered, but not like a vCenter: the SOAP reply carries no "
            . 'rootFolder or propertyCollector. Check the address and the port.'
        );
    }

    public static function unexpected(int $status, string $endpoint): self
    {
        return new self("Unexpected HTTP {$status} from {$endpoint}.", $status);
    }
}
