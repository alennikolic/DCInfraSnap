<?php
// src/Source/Hyperv/OfflinePayload.php
//
// One uploaded collector file, read and checked.
//
// Hyper-V has no API worth reaching from PHP, so a read-only PowerShell
// script runs on the host and writes a JSON file. This class is the only
// thing that turns that file into data: it validates the envelope, and it
// owns every conversion between what PowerShell writes and what the database
// stores. OfflineMapper asks it for values and never touches the raw array.
//
// WHY THE CONVERSIONS LIVE HERE
//
// PowerShell's idea of a value is not MySQL's, and the differences are not
// guesses — each one was found in a real file collected from cluster CL01:
//
//   - An absent string is "" and not null. Stored as it arrives, the same
//     object would flip between "" and NULL the first time the script
//     changes, and every row would be journalled for nothing.
//   - A list is a comma separated string. Version 1.0.0 of the script
//     returned real arrays, and PowerShell unrolled them on the way out of
//     the function: two items came back as an array, one as a bare string,
//     none as an empty object. Version 1.0.1 joins them instead, so a list
//     is one string and splitting it is this class's job.
//   - GUIDs arrive in both cases. A virtual switch id is lowercase, the same
//     id inside an adapter id is uppercase. Keys are lowercased so one
//     object cannot be stored twice.
//   - A Server 2025 guest reports integration services version "0.0",
//     meaning it no longer has one. That is not a version and is read as
//     absent.
//   - Timestamps are ISO 8601 with a Z. MySQL DATETIME wants
//     "Y-m-d H:i:s" and the schema says UTC, so they are converted once,
//     here.

declare(strict_types=1);

namespace DcInfraSnap\Source\Hyperv;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Throwable;

final class OfflinePayload
{
    /** The payload contract this build understands. */
    public const SCHEMA_VERSION = 1;

    /** Guard against a file that is not a collector file at all. */
    private const MAX_BYTES = 64 * 1024 * 1024;

    /**
     * A firmware UUID that says nothing. Cloned hardware and some OEM boards
     * report one of these, and they must not become the key of two hosts.
     */
    private const USELESS_UUIDS = [
        '00000000-0000-0000-0000-000000000000',
        'ffffffff-ffff-ffff-ffff-ffffffffffff',
        '03000200-0400-0500-0006-000700080009',
    ];

    /** @param array<string, mixed> $data */
    private function __construct(
        public readonly string $label,
        private readonly array $data,
    ) {
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    public static function fromFile(string $path): self
    {
        $label = basename($path);

        if (!is_file($path) || !is_readable($path)) {
            throw HypervException::unreadable($label);
        }

        $size = filesize($path);

        if ($size === false || $size === 0) {
            throw HypervException::empty($label);
        }

        if ($size > self::MAX_BYTES) {
            throw new HypervException(
                "{$label} is " . round($size / 1048576, 1) . ' MB, which is not a collector file.'
            );
        }

        $json = file_get_contents($path);

        if ($json === false) {
            throw HypervException::unreadable($label);
        }

        return self::fromJson($json, $label);
    }

    public static function fromJson(string $json, string $label): self
    {
        // PowerShell 5.1 writes a BOM with Out-File; the collector avoids it,
        // but a file that has been through an editor may carry one and
        // json_decode refuses it.
        $json = preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json;
        $json = trim($json);

        // Guarded before decoding on purpose: an empty string reaching a
        // parser is how the worker was once killed by a ValueError that no
        // catch block was looking for.
        if ($json === '') {
            throw HypervException::empty($label);
        }

        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw HypervException::notJson($label, $e->getMessage());
        }

        if (!is_array($data)) {
            throw HypervException::notACollectorFile($label);
        }

        $payload = new self($label, $data);
        $payload->validate();

        return $payload;
    }

    private function validate(): void
    {
        $envelope = $this->data['envelope'] ?? null;

        if (!is_array($envelope)
            || ($envelope['product'] ?? null) !== 'DCInfraSnap'
            || ($envelope['collector'] ?? null) !== 'hyperv-offline') {
            throw HypervException::notACollectorFile($this->label);
        }

        $schema = (int) ($envelope['schema_version'] ?? 0);

        if ($schema !== self::SCHEMA_VERSION) {
            throw HypervException::unsupportedSchema($this->label, $schema, self::SCHEMA_VERSION);
        }

        if (!in_array($envelope['kind'] ?? '', ['cluster', 'standalone'], true)) {
            throw HypervException::missing($this->label, 'the kind of source');
        }

        if (self::text($envelope['collector_host'] ?? null) === null) {
            throw HypervException::missing($this->label, 'the name of the host it was collected on');
        }

        if ($this->generatedAt() === null) {
            throw HypervException::missing($this->label, 'the time it was generated');
        }

        if (!is_array($this->data['node'] ?? null)
            || self::text($this->data['node']['hostname'] ?? null) === null) {
            throw HypervException::missing($this->label, 'the host section');
        }

        if ($this->isCluster() && !is_array($this->data['cluster'] ?? null)) {
            throw HypervException::missing($this->label, 'the cluster section');
        }

        foreach (['vms', 'warnings', 'collection'] as $section) {
            if (!is_array($this->data[$section] ?? null)) {
                throw HypervException::missing($this->label, "the {$section} section");
            }
        }
    }

    // -----------------------------------------------------------------
    // Sections
    // -----------------------------------------------------------------

    /** @return array<string, mixed> */
    public function envelope(): array
    {
        return is_array($this->data['envelope']) ? $this->data['envelope'] : [];
    }

    /** @return array<string, mixed>|null */
    public function cluster(): ?array
    {
        $cluster = $this->data['cluster'] ?? null;

        return is_array($cluster) ? $cluster : null;
    }

    /** @return array<string, mixed> */
    public function node(): array
    {
        return is_array($this->data['node']) ? $this->data['node'] : [];
    }

    /** @return list<array<string, mixed>> */
    public function vms(): array
    {
        return self::rows($this->data['vms'] ?? []);
    }

    /** @return list<string> */
    public function warnings(): array
    {
        $out = [];

        foreach (self::listOf($this->data['warnings'] ?? []) as $warning) {
            if (is_string($warning) && trim($warning) !== '') {
                $out[] = trim($warning);
            }
        }

        return $out;
    }

    /**
     * What each collection step produced, which is the first place to look
     * when a section is empty: usually an account permission, not a parser.
     *
     * @return list<array{step: string, status: string, count: int, message: string}>
     */
    public function collection(): array
    {
        $out = [];

        foreach (self::rows($this->data['collection'] ?? []) as $entry) {
            $out[] = [
                'step'    => (string) ($entry['step'] ?? '?'),
                'status'  => (string) ($entry['status'] ?? '?'),
                'count'   => (int) ($entry['count'] ?? 0),
                'message' => (string) ($entry['message'] ?? ''),
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function clusterNodes(): array
    {
        return self::rows($this->cluster()['nodes'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function clusterNetworks(): array
    {
        return self::rows($this->cluster()['networks'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function sharedVolumes(): array
    {
        return self::rows($this->cluster()['shared_volumes'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function hostDevices(): array
    {
        return self::rows($this->node()['physical_nics'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function nicTeams(): array
    {
        return self::rows($this->node()['nic_teams'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function hostVnics(): array
    {
        return self::rows($this->node()['host_vnics'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function virtualSwitches(): array
    {
        return self::rows($this->node()['virtual_switches'] ?? []);
    }

    // -----------------------------------------------------------------
    // Envelope facts
    // -----------------------------------------------------------------

    public function kind(): string
    {
        return (string) ($this->envelope()['kind'] ?? 'standalone');
    }

    public function isCluster(): bool
    {
        return $this->kind() === 'cluster';
    }

    public function collectorHost(): string
    {
        return (string) self::text($this->envelope()['collector_host'] ?? null);
    }

    public function scriptVersion(): ?string
    {
        return self::text($this->envelope()['script_version'] ?? null);
    }

    public function sourceToken(): ?string
    {
        return self::text($this->envelope()['source_token'] ?? null);
    }

    public function runAsAdmin(): bool
    {
        return (bool) ($this->envelope()['run_as_admin'] ?? false);
    }

    public function hasDiskDetails(): bool
    {
        return (bool) ($this->envelope()['disk_details'] ?? false);
    }

    public function durationSeconds(): float
    {
        return (float) ($this->envelope()['duration_seconds'] ?? 0);
    }

    /** UTC, as "Y-m-d H:i:s", or null when the envelope has no usable time. */
    public function generatedAt(): ?string
    {
        return self::utc($this->envelope()['generated_at'] ?? null);
    }

    public function clusterGuid(): ?string
    {
        return self::guid($this->cluster()['id'] ?? null);
    }

    public function clusterName(): ?string
    {
        return self::text($this->cluster()['name'] ?? null);
    }

    /**
     * The nodes this file knows the cluster to have. This is what says how
     * many files one import is still waiting for.
     *
     * @return list<string>
     */
    public function clusterNodeNames(): array
    {
        $names = [];

        foreach ($this->clusterNodes() as $node) {
            $name = self::text($node['name'] ?? null);

            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The key of the host this file describes.
     *
     * The firmware UUID is per chassis and survives a rename, so it is
     * preferred. When the board reports a useless one the hostname is used,
     * which is stable enough: a renamed host is a new row, and that is
     * better than two hosts sharing a key.
     */
    public function nodeMoref(): string
    {
        $uuid = self::guid($this->node()['smbios_uuid'] ?? null);

        if ($uuid !== null && !in_array($uuid, self::USELESS_UUIDS, true)) {
            return $uuid;
        }

        return 'host:' . strtolower((string) self::text($this->node()['hostname'] ?? null));
    }

    public function hostname(): string
    {
        return (string) self::text($this->node()['hostname'] ?? null);
    }

    // -----------------------------------------------------------------
    // Value conversion — the only place that knows PowerShell's habits
    // -----------------------------------------------------------------

    /** A string, or null when it is absent, empty or only whitespace. */
    public static function text(mixed $value): ?string
    {
        if (is_bool($value) || is_array($value) || $value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    public static function int(mixed $value): ?int
    {
        if ($value === null || $value === '' || is_array($value) || is_bool($value)) {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /** TINYINT(1), to match what the rest of the schema stores for a flag. */
    public static function flag(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_numeric($value)) {
            return (int) $value === 0 ? 0 : 1;
        }

        return in_array(strtolower((string) $value), ['true', 'on', 'yes', 'enabled'], true) ? 1 : 0;
    }

    /** Lowercased GUID, or null. Keys must not differ by letter case alone. */
    public static function guid(mixed $value): ?string
    {
        $text = self::text($value);

        return $text === null ? null : strtolower($text);
    }

    /** An ISO 8601 timestamp as MySQL DATETIME in UTC. */
    public static function utc(mixed $value): ?string
    {
        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        try {
            return (new DateTimeImmutable($text))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A comma separated list as the collector writes it, cleaned up.
     *
     * @return list<string>
     */
    public static function splitList(mixed $value): array
    {
        // Tolerates a real array as well: version 1.0.0 of the script emitted
        // one for these fields, and an old file should still read.
        if (is_array($value)) {
            $parts = $value;
        } else {
            $text = self::text($value);

            if ($text === null) {
                return [];
            }

            $parts = explode(',', $text);
        }

        $out = [];

        foreach ($parts as $part) {
            $clean = self::text($part);

            if ($clean !== null) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    /** The same list back as one string, or null when there is nothing in it. */
    public static function joinList(mixed $value): ?string
    {
        $parts = self::splitList($value);

        return $parts === [] ? null : implode(',', $parts);
    }

    /**
     * The host's IP addresses as "address/prefix", from the structured form
     * the collector emits for adapters.
     */
    public static function addressList(mixed $value): ?string
    {
        if (!is_array($value)) {
            return self::joinList($value);
        }

        $out = [];

        foreach ($value as $entry) {
            if (!is_array($entry)) {
                $text = self::text($entry);

                if ($text !== null) {
                    $out[] = $text;
                }

                continue;
            }

            $address = self::text($entry['address'] ?? null);

            if ($address === null) {
                continue;
            }

            $prefix = self::int($entry['prefix'] ?? null);
            $out[]  = $prefix === null ? $address : $address . '/' . $prefix;
        }

        return $out === [] ? null : implode(',', $out);
    }

    /**
     * An integration services version, with "0.0" read as absent.
     *
     * Every machine on CL01 reports "0.0" under Server 2025, where the guest
     * no longer carries a version. Stored as it arrives, the column would
     * claim a version of zero for every modern guest.
     */
    public static function servicesVersion(mixed $value): ?string
    {
        $text = self::text($value);

        return ($text === null || $text === '0.0') ? null : $text;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        $out = [];

        foreach (self::listOf($value) as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /** @return list<mixed> */
    private static function listOf(mixed $value): array
    {
        if (!is_array($value)) {
            return $value === null ? [] : [$value];
        }

        // An empty PowerShell collection can serialize as {} rather than [],
        // which decodes to an empty array either way; a single object that
        // was never wrapped decodes as a map and is treated as one row.
        return array_is_list($value) ? $value : [$value];
    }
}
