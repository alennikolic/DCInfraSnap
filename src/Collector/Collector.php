<?php
// src/Collector/Collector.php
//
// One collection run, start to finish.
//
// The shape of a run:
//   connect -> for each object type: fetch, store raw, write inventory
//           -> record the outcome per type -> finish
//
// A failure of one type does not end the run. If datastores cannot be read
// because the account lacks a permission, the virtual machines that were
// already collected are worth keeping, and the run is recorded as partial
// so a later reader knows not to treat it as a complete picture.
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
        private readonly ClientFactory $clients,
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
        $report  = $this->reporter;
        $results = [];
        $client  = null;

        $report("Connecting to {$integration->endpoint()} ...");

        try {
            $client = $this->clients->connect(
                $integration,
                $this->integrations->password($integration->id)
            );
        } catch (Throwable $e) {
            if (!$dryRun) {
                $this->runs->finish($runId, 'failed', 0, $e->getMessage());
            }

            $report('Connection failed: ' . $e->getMessage());

            return ['status' => 'failed', 'types' => [], 'api_calls' => 0];
        }

        $report('Connected to ' . ($client->productFullName ?? 'vCenter') . '.');

        $collector = new InventoryCollector($client);

        try {
            // The placement tree is loaded once and used by every type, so a
            // failure here is worth reporting as itself rather than as five
            // identical failures further down.
            $report('Reading the inventory tree ...');
            $collector->loadTree();

            foreach (ObjectType::all() as $key => $type) {
                $results[$key] = $this->collectType($collector, $type, $integration, $runId, $dryRun);
            }
        } finally {
            $client->logout();
        }

        $apiCalls = $client->callCount();
        $status   = self::overallStatus($results);

        if (!$dryRun) {
            $this->runs->finish($runId, $status, $apiCalls, self::firstError($results));
        }

        return ['status' => $status, 'types' => $results, 'api_calls' => $apiCalls];
    }

    /**
     * One object type: fetch it, store it, record how it went.
     *
     * @return array{found: int, changed: int, error: string|null}
     */
    private function collectType(
        InventoryCollector $collector,
        ObjectType $type,
        Integration $integration,
        int $runId,
        bool $dryRun,
    ): array {
        $report = $this->reporter;

        try {
            $objects = match ($type->key) {
                'cluster'   => $collector->clusters(),
                'host'      => $collector->hosts(),
                'vm'        => $collector->vms(),
                'datastore' => $collector->datastores(),
                'license'   => $collector->licenses(),

                // A separate pass on purpose: measured, adding these fields
                // to the host read grows the response for five hosts from
                // 1.1 KB to 291 KB.
                'host_device' => $collector->hostDevices(),
            };

            if ($dryRun) {
                $report(sprintf('  %-11s %4d found (nothing written)', $type->key, count($objects)));

                return ['found' => count($objects), 'changed' => 0, 'error' => null];
            }

            $this->raw->store($type->key, $integration->id, $runId, $objects);

            $counts = $this->inventory->sync($type, $integration->id, $runId, $objects);

            $this->runs->recordType($runId, $type->runPrefix, 'ok', $counts['found'], $counts['changed']);

            $report(sprintf(
                '  %-11s %4d found, %d new or changed',
                $type->key,
                $counts['found'],
                $counts['changed']
            ));

            return $counts + ['error' => null];
        } catch (Throwable $e) {
            if (!$dryRun) {
                $this->runs->recordType($runId, $type->runPrefix, 'failed');
            }

            $report(sprintf('  %-11s FAILED: %s', $type->key, $e->getMessage()));

            return ['found' => 0, 'changed' => 0, 'error' => $e->getMessage()];
        }
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
