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

    /**
     * The base URL, with the port left off when it is the default.
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
