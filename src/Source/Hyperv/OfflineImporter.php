<?php
// src/Source/Hyperv/OfflineImporter.php
//
// Writes one set of collector files into the database as one run.
//
// This is the Hyper-V counterpart of Collector, and it deliberately does the
// same three things per object type in the same order: keep the raw payload,
// sync the inventory, record the outcome on the run. A separate path would
// mean a second place where change detection, journalling and the run report
// could drift apart.
//
// THE SET IS THE RUN. sync() reconciles a whole source, so a run must carry
// every node's file: one node alone would journal every machine on the other
// nodes as gone. The caller is responsible for refusing an incomplete set —
// OfflineMapper::missingNodes() is what says so — and this class assumes the
// decision has been made.

declare(strict_types=1);

namespace DcInfraSnap\Source\Hyperv;

use DcInfraSnap\Integration\Integration;
use DcInfraSnap\Inventory\InventoryRepository;
use DcInfraSnap\Inventory\ObjectType;
use DcInfraSnap\Inventory\RawObjectRepository;
use DcInfraSnap\Run\RunRepository;
use Throwable;

final class OfflineImporter
{
    /** @var callable(string): void */
    private $reporter;

    public function __construct(
        private readonly RawObjectRepository $raw,
        private readonly InventoryRepository $inventory,
        private readonly RunRepository $runs,
        ?callable $reporter = null,
    ) {
        $this->reporter = $reporter ?? static function (string $line): void {
        };
    }

    /**
     * @return array{status: string, types: array<string, array{found: int, changed: int, error: string|null}>}
     */
    public function import(Integration $integration, int $runId, OfflineMapper $mapper): array
    {
        $report  = $this->reporter;
        $results = [];

        $report(sprintf(
            'Importing %d file(s) into %s: %s',
            count($mapper->payloads()),
            $integration->name,
            implode(', ', array_map(
                static fn (OfflinePayload $p): string => $p->collectorHost(),
                $mapper->payloads()
            ))
        ));

        foreach ($mapper->warnings() as $warning) {
            $report('  note: ' . $warning);
        }

        $report('');

        foreach ($mapper->rows() as $key => $objects) {
            $results[$key] = $this->importType($key, $objects, $integration, $runId);
        }

        $failed = array_filter(
            $results,
            static fn (array $result): bool => $result['error'] !== null
        );

        // Partial, not failed, when some types went through: the same rule the
        // vCenter collector follows. A host that went in is usable even if
        // the machines on another one did not.
        $status = match (true) {
            $failed === []                       => 'ok',
            count($failed) === count($results)   => 'failed',
            default                              => 'partial',
        };

        $this->runs->finish($runId, $status, 0, self::firstError($results));

        $report('');
        $report('Import ' . $status . '.');

        return ['status' => $status, 'types' => $results];
    }

    /**
     * @param list<array{moref: string, fields: array<string, scalar|null>}> $objects
     *
     * @return array{found: int, changed: int, error: string|null}
     */
    private function importType(string $key, array $objects, Integration $integration, int $runId): array
    {
        $report = $this->reporter;
        $type   = ObjectType::get($key);

        // A type with nothing in it is not recorded at all. The absence of a
        // row on the run is what used to be 'pending', and a standalone host
        // legitimately produces no cluster objects — reporting those as
        // collected-and-empty would claim a cluster that does not exist.
        if ($objects === []) {
            $report(sprintf('  %-20s %4d', $key, 0));

            return ['found' => 0, 'changed' => 0, 'error' => null];
        }

        try {
            $this->raw->store($key, $integration->id, $runId, $objects);

            $counts = $this->inventory->sync($type, $integration->id, $runId, $objects);

            $this->runs->recordType(
                $runId,
                $key,
                $type->source,
                ObjectType::orderOf($key),
                'ok',
                $counts['found'],
                $counts['changed']
            );

            $report(sprintf(
                '  %-20s %4d found, %d new or changed',
                $key,
                $counts['found'],
                $counts['changed']
            ));

            return $counts + ['error' => null];
        } catch (Throwable $e) {
            $this->runs->recordType(
                $runId,
                $key,
                $type->source,
                ObjectType::orderOf($key),
                'failed',
                0,
                0,
                $e->getMessage()
            );

            $report(sprintf('  %-20s FAILED: %s', $key, $e->getMessage()));

            return ['found' => 0, 'changed' => 0, 'error' => $e->getMessage()];
        }
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
