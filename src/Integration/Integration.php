<?php
// src/Integration/Integration.php
//
// One configured source.
//
// NOTE WHAT IS NOT HERE: the password. It is deliberately absent, so it
// cannot end up in a var_dump, an exception trace or a log. When a client
// needs it, it asks IntegrationRepository::password() for it, and that
// decrypts it at the moment of signing in and not before.

declare(strict_types=1);

namespace DcInfraSnap\Integration;

final class Integration
{
    public const TYPE_VCENTER = 'vcenter';
    public const TYPE_BROCADE = 'brocade';
    public const TYPE_HYPERV  = 'hyperv';

    /**
     * Source types this build can collect from, and their default port.
     *
     * Hyper-V is port 0 because there is nothing to connect to: the data
     * arrives as a file collected on the host. The port is still part of
     * uq_integration_endpoint, so 0 keeps "one cluster, one source" working
     * without pretending there is a service listening somewhere.
     */
    public const TYPES = [
        self::TYPE_VCENTER => 443,
        self::TYPE_BROCADE => 22,
        self::TYPE_HYPERV  => 0,
    ];

    /**
     * Source types that are collected offline.
     *
     * These have no credentials and nothing to test: username and
     * password_encrypted are NULL in the database for them, and a connection
     * test would have nothing to connect to.
     */
    public const OFFLINE_TYPES = [self::TYPE_HYPERV];

    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $name,
        public readonly string $hostname,
        public readonly int $port,
        public readonly string $username,
        public readonly bool $verifyPeer,
        public readonly ?string $productVersion,
        public readonly bool $isActive,
        public readonly ?string $lastTestedAt,
        public readonly ?bool $lastTestOk,
        public readonly ?string $lastTestMessage,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id:              (int) $row['id'],
            type:            (string) $row['type'],
            name:            (string) $row['name'],
            hostname:        (string) $row['hostname'],
            port:            (int) $row['port'],
            username:        (string) $row['username'],
            verifyPeer:      (bool) $row['verify_peer'],
            productVersion:  $row['product_version'] !== null ? (string) $row['product_version'] : null,
            isActive:        (bool) $row['is_active'],
            lastTestedAt:    $row['last_tested_at'] !== null ? (string) $row['last_tested_at'] : null,
            lastTestOk:      $row['last_test_ok'] !== null ? (bool) $row['last_test_ok'] : null,
            lastTestMessage: $row['last_test_message'] !== null ? (string) $row['last_test_message'] : null,
            createdAt:       (string) $row['created_at'],
            updatedAt:       (string) $row['updated_at'],
        );
    }

    public static function defaultPort(string $type): int
    {
        return self::TYPES[$type] ?? 443;
    }

    public function isBrocade(): bool
    {
        return $this->type === self::TYPE_BROCADE;
    }

    public function isVCenter(): bool
    {
        return $this->type === self::TYPE_VCENTER;
    }

    public function isHyperV(): bool
    {
        return $this->type === self::TYPE_HYPERV;
    }

    /** Collected from an uploaded file rather than over the network. */
    public function isOffline(): bool
    {
        return in_array($this->type, self::OFFLINE_TYPES, true);
    }

    /**
     * The base URL, with the port left off when it is the default.
     *
     * Only meaningful for an HTTPS source. A Brocade switch is reached over
     * SSH and has no base URL.
     */
    public function baseUrl(): string
    {
        return 'https://' . $this->hostname . ($this->port === 443 ? '' : ':' . $this->port);
    }

    /** Host and port as shown in the interface. */
    public function endpoint(): string
    {
        return $this->hostname . ':' . $this->port;
    }
}
