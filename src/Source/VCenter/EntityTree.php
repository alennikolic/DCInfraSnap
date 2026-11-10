<?php
// src/Source/VCenter/EntityTree.php
//
// Where an object sits in the inventory.
//
// vCenter does not tell you which datacentre a machine belongs to. It tells
// you its parent, and the parent's parent, up a chain of folders and compute
// resources until a Datacenter appears. This class holds that chain in
// memory for one run and answers the two questions worth asking:
//
//   datacenterOf()  which datacentre an object ends up in
//   folderPathOf()  the folder path a machine lives under, as people see it
//                   in the vSphere client
//
// It is built once per run, from one extra property retrieval over folders,
// datacentres and compute resources. Walking it is then free, which matters
// when it is asked the same question once per machine.

declare(strict_types=1);

namespace DcInfraSnap\Source\VCenter;

final class EntityTree
{
    /** @var array<string, array{name: string|null, parent: string|null, kind: string}> */
    private array $nodes = [];

    public function add(string $moref, string $kind, ?string $name, ?string $parent): void
    {
        $this->nodes[$moref] = ['name' => $name, 'parent' => $parent, 'kind' => $kind];
    }

    public function has(string $moref): bool
    {
        return isset($this->nodes[$moref]);
    }

    public function name(string $moref): ?string
    {
        return $this->nodes[$moref]['name'] ?? null;
    }

    public function kind(string $moref): ?string
    {
        return $this->nodes[$moref]['kind'] ?? null;
    }

    /**
     * The datacentre an object belongs to, as [moref, name].
     *
     * @return array{0: string|null, 1: string|null}
     */
    public function datacenterOf(?string $moref): array
    {
        foreach ($this->walkUp($moref) as $current => $node) {
            if ($node['kind'] === 'Datacenter') {
                return [(string) $current, $node['name']];
            }
        }

        return [null, null];
    }

    /**
     * The folder path of an object, e.g. "/Production/Databases".
     *
     * The datacentre's own "vm" and "host" root folders are left out: they
     * are not shown in the vSphere client either, and including them would
     * put a meaningless first segment on every path.
     */
    public function folderPathOf(?string $moref): ?string
    {
        $segments = [];

        foreach ($this->walkUp($moref) as $node) {
            if ($node['kind'] === 'Datacenter') {
                break;
            }

            if ($node['kind'] === 'Folder' && $node['name'] !== null) {
                $segments[] = $node['name'];
            }
        }

        // The last segment collected is the datacentre's root folder.
        array_pop($segments);

        if ($segments === []) {
            return '/';
        }

        return '/' . implode('/', array_reverse($segments));
    }

    /**
     * Walk from an object up to the root, yielding moref => node.
     *
     * The visited set is not caution for its own sake: a malformed parent
     * chain would otherwise spin here forever, and this runs once per
     * machine on an inventory of thousands.
     *
     * @return \Generator<string, array{name: string|null, parent: string|null, kind: string}>
     */
    private function walkUp(?string $moref): \Generator
    {
        $visited = [];

        while ($moref !== null && isset($this->nodes[$moref]) && !isset($visited[$moref])) {
            $visited[$moref] = true;

            yield $moref => $this->nodes[$moref];

            $moref = $this->nodes[$moref]['parent'];
        }
    }
}
