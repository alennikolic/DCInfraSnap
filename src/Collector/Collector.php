<?php
// src/Collector/Collector.php
//
// One collection run, start to finish, for either kind of source.
//
// The shape of a run:
//   connect -> for each object type: fetch, store raw, write inventory
//           -> record the outcome per type -> finish
//
// That shape is the same for a vCenter and for a switch; what differs is how
// to connect and what to call to fetch each type. So the two sources supply a
// map of type key to a function that fetches it, and everything after that is
// shared.
//
// A failure of one type does not end the run. If datastores cannot be read
// because the account lacks a permission, the virtual machines that were
// already collected are worth keeping, and the run is recorded as partial so
// a later reader knows not to treat it as a complete picture.
//
// A failure to connect at all is different: nothing was collected, and the
// run is simply failed.

declare(strict_types=1);

namespace DcInfraSnap\Collector;

use DcInfraSnap\Integration\Integration;
use DcInfraSnap\Integration\IntegrationRepository;
use DcInfraSnap\Inventory\InventoryRepository;
use DcInfraSnap\Inventory\ObjectType;
use DcInfraSnap\Inventory\RawObjectRepository;
use DcInfraSnap\Run\RunRepository;
use DcInfraSnap\Source\Brocade\ClientFactory as BrocadeClientFactory;
use DcInfraSnap\Source\Brocade\InventoryCollector as BrocadeInventory;
use DcInfraSnap\Source\VCenter\ClientFactory;
use DcInfraSnap\Source\VCenter\InventoryCollector;
use Throwable;

final class Collector
{
    /** @var callable(string): void */
    private $reporter;

    public function __construct(
        private readonly IntegrationRepository $integrations,
        private readonly RunRepository $runs,
        private readonly InventoryRepository $inventory,
        private readonly RawObjectRepository $raw,
        private readonly ClientFactory $vcenterClients,
        private readonly BrocadeClientFactory $brocadeClients,
        ?callable $reporter = null,
    ) {
        // Progress goes somewhere by default, and nowhere in particular.
        $this->reporter = $reporter ?? static function (string $line): void {
        };
    }

    /**
     * Collect everything for one source, recording it against a run.
     *
     * @param bool $dryRun fetch and report, write nothing
     *
     * @return array{status: string, types: array<string, array{found: int, changed: int, error: string|null}>, api_calls: int}
     */
    public function run(Integration $integration, int $runId, bool $dryRun = false): array
    {
        return $integration->isBrocade()
            ? $this->runBrocade($integration, $runId, $dryRun)
            : $this->runVCenter($integration, $runId, $dryRun);
    }

    // -----------------------------------------------------------------
    // vCenter
    // -----------------------------------------------------------------

    /**
     * @return array{status: string, types: array<string, array{found: int, changed: int, error: string|null}>, api_calls: int}
     */
    private function runVCenter(Integration $integration, int $runId, bool $dryRun): array
    {
        $report = $this->reporter;

        $report("Connecting to {$integration->endpoint()} ...");

        try {
            $client = $this->vcenterClients->connect(
                $integration,
                $this->integrations->password($integration->id)
            );
        } catch (Throwable $e) {
            return $this->connectionFailed($runId, $dryRun, $e);
        }

        $report('Connected to ' . ($client->productFullName ?? 'vCenter') . '.');

        $source  = new InventoryCollector($client);
        $results = [];

        try {
            // The placement tree is loaded once and used by every type, so a
            // failure here is worth reporting as itself rather than as six
            // identical failures further down.
            $report('Reading the inventory tree ...');
            $source->loadTree();

            $results = $this->collectAll($integration, $runId, $dryRun, [
                'cluster'   => static fn (): array => $source->clusters(),
                'host'      => static fn (): array => $source->hosts(),
                'vm'        => static fn (): array => $source->vms(),
                'datastore' => static fn (): array => $source->datastores(),

                // A separate pass on purpose: measured, adding these fields
                // to the host read grows the response for five hosts from
                // 1.1 KB to 291 KB.
                'host_device' => static fn (): array => $source->hostDevices(),

                'license'   => static fn (): array => $source->licenses(),
            ]);
        } finally {
            $client->logout();
        }

        return $this->finish($runId, $dryRun, $results, $client->callCount());
    }

    // -----------------------------------------------------------------
    // Brocade
    // -----------------------------------------------------------------

    /**
     * @return array{status: string, types: array<string, array{found: int, changed: int, error: string|null}>, api_calls: int}
     */
    private function runBrocade(Integration $integration, int $runId, bool $dryRun): array
    {
        $report = $this->reporter;

        $report("Connecting to {$integration->endpoint()} ...");

        $client = $this->brocadeClients->make(
            $integration,
            $this->integrations->password($integration->id)
        );

        // A switch is read in one login: the whole batch runs here, and
        // everything below reads the result. So unlike vCenter, a failure to
        // collect and a failure to connect are the same failure.
        try {
            $client->collect();
        } catch (Throwable $e) {
            return $this->connectionFailed($runId, $dryRun, $e);
        }

        $info = $client->switchInfo();

        $report(sprintf(
            'Connected to %s, FOS %s%s.',
            $info['name'] ?? 'switch',
            $info['fos_version'] ?? '?',
            $client->usedLegacyAlgorithms() ? ' (legacy SSH algorithms)' : ''
        ));

        $source = new BrocadeInventory($client);

        $results = $this->collectAll($integration, $runId, $dryRun, [
            'fc_switch'        => static fn (): array => $source->switchObject(),
            'fc_fabric_member' => static fn (): array => $source->fabricMembers(),
            'fc_port'          => static fn (): array => $source->ports(),
            'fc_isl'           => static fn (): array => $source->isls(),
            'fc_device'        => static fn (): array => $source->devices(),

            // Objects before members: a member names the object it belongs
            // to, and a reader is better served by that row already existing.
            'fc_zone_object'   => static fn (): array => $source->zoneObjects(),
            'fc_zone_member'   => static fn (): array => $source->zoneMembers(),
        ]);

        return $this->finish($runId, $dryRun, $results, $client->commandCount());
    }

    // -----------------------------------------------------------------
    // Shared
    // -----------------------------------------------------------------

    /**
     * Walk the types this source produces, in the order ObjectType declares.
     *
     * The order comes from ObjectType rather than from the map below, so that
     * what a run reports and what the inventory holds cannot drift apart. A
     * type declared for this source but absent from the map is a programming
     * error and says so, rather than quietly collecting nothing.
     *
     * @param array<string, callable(): list<array{moref: string, fields: array<string, mixed>, raw: array<string, mixed>}>> $fetchers
     *
     * @return array<string, array{found: int, changed: int, error: string|null}>
     */
    private function collectAll(
        Integration $integration,
        int $runId,
        bool $dryRun,
        array $fetchers,
    ): array {
        $results = [];

        foreach (ObjectType::forSource($integration->type) as $key => $type) {
            if (!isset($fetchers[$key])) {
                $results[$key] = [
                    'found'   => 0,
                    'changed' => 0,
                    'error'   => 'No collector is wired up for this type.',
                ];

                continue;
            }

            $results[$key] = $this->collectType($type, $fetchers[$key], $integration, $runId, $dryRun);
        }

        return $results;
    }

    /**
     * One object type: fetch it, store it, record how it went.
     *
     * @param callable(): list<array{moref: string, fields: array<string, mixed>, raw: array<string, mixed>}> $fetch
     *
     * @return array{found: int, changed: int, error: string|null}
     */
    private function collectType(
        ObjectType $type,
        callable $fetch,
        Integration $integration,
        int $runId,
        bool $dryRun,
    ): array {
        $report = $this->reporter;

        try {
            $objects = $fetch();

            if ($dryRun) {
                $report(sprintf('  %-17s %4d found (nothing written)', $type->key, count($objects)));

                return ['found' => count($objects), 'changed' => 0, 'error' => null];
            }

            $this->raw->store($type->key, $integration->id, $runId, $objects);

            $counts = $this->inventory->sync($type, $integration->id, $runId, $objects);

            $this->runs->recordType(
                $runId,
                $type->key,
                $type->source,
                ObjectType::orderOf($type->key),
                'ok',
                $counts['found'],
                $counts['changed']
            );

            $report(sprintf(
                '  %-17s %4d found, %d new or changed',
                $type->key,
                $counts['found'],
                $counts['changed']
            ));

            return $counts + ['error' => null];
        } catch (Throwable $e) {
            if (!$dryRun) {
                $this->runs->recordType(
                    $runId,
                    $type->key,
                    $type->source,
                    ObjectType::orderOf($type->key),
                    'failed',
                    0,
                    0,
                    $e->getMessage()
                );
            }

            $report(sprintf('  %-17s FAILED: %s', $type->key, $e->getMessage()));

            return ['found' => 0, 'changed' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{status: string, types: array<string, array{found: int, changed: int, error: string|null}>, api_calls: int}
     */
    private function connectionFailed(int $runId, bool $dryRun, Throwable $e): array
    {
        if (!$dryRun) {
            $this->runs->finish($runId, 'failed', 0, $e->getMessage());
        }

        ($this->reporter)('Connection failed: ' . $e->getMessage());

        return ['status' => 'failed', 'types' => [], 'api_calls' => 0];
    }

    /**
     * @param array<string, array{found: int, changed: int, error: string|null}> $results
     *
     * @return array{status: string, types: array<string, array{found: int, changed: int, error: string|null}>, api_calls: int}
     */
    private function finish(int $runId, bool $dryRun, array $results, int $calls): array
    {
        $status = self::overallStatus($results);

        if (!$dryRun) {
            $this->runs->finish($runId, $status, $calls, self::firstError($results));
        }

        return ['status' => $status, 'types' => $results, 'api_calls' => $calls];
    }

    /**
     * success when every type came through, failed when none did, partial
     * in between.
     *
     * @param array<string, array{found: int, changed: int, error: string|null}> $results
     */
    private static function overallStatus(array $results): string
    {
        if ($results === []) {
            return 'failed';
        }

        $failed = 0;

        foreach ($results as $result) {
            if ($result['error'] !== null) {
                $failed++;
            }
        }

        if ($failed === 0) {
            return 'success';
        }

        return $failed === count($results) ? 'failed' : 'partial';
    }

    /**
     * @param array<string, array{found: int, changed: int, error: string|null}> $results
     */
    private static function firstError(array $results): ?string
    {
        foreach ($results as $key => $result) {
            if ($result['error'] !== null) {
                return $key . ': ' . $result['error'];
            }
        }

        return null;
    }
}
