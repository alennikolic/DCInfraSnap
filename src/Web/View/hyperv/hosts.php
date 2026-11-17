<?php
// src/Web/View/hyperv/hosts.php
//
// Hyper-V clusters and the hosts under them.
//
// Clusters and hosts share a page because a Hyper-V cluster is thin: a name,
// a quorum, a handful of networks and the volumes. What is worth looking at
// is which hosts carry it, and that reads better directly underneath than on
// a page of its own.

/** @var callable $e */
/** @var callable $t */
/** @var list<array<string,mixed>> $clusters */
/** @var array<string, list<array<string,mixed>>> $networks */
/** @var array<string, list<array<string,mixed>>> $volumes */
/** @var list<array<string,mixed>> $nodes */

use DcInfraSnap\Web\Format;
?>

<div class="page-head">
    <div>
        <h1><?= $e($t('nav.hyperv_hosts')) ?></h1>
        <p><?= $e($t('hv.hosts_subtitle')) ?></p>
    </div>
</div>

<?php if ($nodes === []): ?>

    <div class="panel empty">
        <p class="empty-title"><?= $e($t('hv.none')) ?></p>
        <p><?= $e($t('hv.none_hint')) ?></p>
    </div>

<?php else: ?>

    <?php foreach ($clusters as $cluster): ?>
        <?php $key = (int) $cluster['integration_id'] . ':' . $cluster['moref']; ?>

        <div class="panel">
            <h2><?= $e((string) $cluster['name']) ?></h2>
            <p class="muted"><?= $e((string) $cluster['integration_name']) ?></p>

            <table class="table-facts">
                <tbody>
                    <tr>
                        <th><?= $e($t('hv.quorum')) ?></th>
                        <td><?= $e(trim((string) $cluster['quorum_type'] . ' — ' . (string) $cluster['quorum_resource'], ' —')) ?></td>
                    </tr>
                    <tr>
                        <th><?= $e($t('hv.functional_level')) ?></th>
                        <td class="data"><?= $e((string) $cluster['functional_level']) ?></td>
                    </tr>
                    <tr>
                        <th><?= $e($t('hv.nodes')) ?></th>
                        <td><?= (int) $cluster['node_rows'] ?> / <?= (int) $cluster['node_count'] ?></td>
                    </tr>
                    <tr>
                        <th><?= $e($t('hv.machines')) ?></th>
                        <td><?= (int) $cluster['vm_count'] ?></td>
                    </tr>
                </tbody>
            </table>

            <?php if (($volumes[$key] ?? []) !== []): ?>
                <h3><?= $e($t('hv.volumes')) ?></h3>
                <table>
                    <thead>
                        <tr>
                            <th><?= $e($t('common.name')) ?></th>
                            <th><?= $e($t('hv.path')) ?></th>
                            <th><?= $e($t('hv.filesystem')) ?></th>
                            <th class="numeric"><?= $e($t('hv.size')) ?></th>
                            <th class="numeric"><?= $e($t('hv.free')) ?></th>
                            <th><?= $e($t('hv.owner')) ?></th>
                            <th><?= $e($t('common.status')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($volumes[$key] as $volume): ?>
                        <tr>
                            <td><?= $e((string) $volume['name']) ?></td>
                            <td class="data"><?= $e((string) $volume['friendly_path']) ?></td>
                            <td class="data"><?= $e((string) $volume['file_system']) ?></td>
                            <td class="numeric"><?= $e(Format::bytes($volume['size_bytes'])) ?></td>
                            <td class="numeric"><?= $e(Format::bytes($volume['free_bytes'])) ?></td>
                            <td class="data"><?= $e((string) $volume['owner_node']) ?></td>
                            <td><?= $e(Format::state((string) $volume['csv_state'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (($networks[$key] ?? []) !== []): ?>
                <h3><?= $e($t('hv.cluster_networks')) ?></h3>
                <table>
                    <thead>
                        <tr>
                            <th><?= $e($t('common.name')) ?></th>
                            <th><?= $e($t('hv.role')) ?></th>
                            <th><?= $e($t('hv.subnet')) ?></th>
                            <th class="numeric"><?= $e($t('hv.metric')) ?></th>
                            <th><?= $e($t('common.status')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($networks[$key] as $network): ?>
                        <tr>
                            <td><?= $e((string) $network['name']) ?></td>
                            <td><?= $e((string) $network['network_role']) ?></td>
                            <td class="data">
                                <?= $e(trim((string) $network['address'] . ' / ' . (string) $network['address_mask'], ' /')) ?>
                            </td>
                            <td class="numeric"><?= $e(Format::number($network['metric'])) ?></td>
                            <td><?= $e(Format::state((string) $network['network_state'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="panel">
        <h2><?= $e($t('hv.hosts')) ?></h2>

        <table>
            <thead>
                <tr>
                    <th><?= $e($t('common.name')) ?></th>
                    <th><?= $e($t('hv.cluster')) ?></th>
                    <th><?= $e($t('hv.hardware')) ?></th>
                    <th><?= $e($t('hv.processor')) ?></th>
                    <th class="numeric"><?= $e($t('hv.memory')) ?></th>
                    <th class="numeric"><?= $e($t('hv.machines')) ?></th>
                    <th><?= $e($t('hv.os')) ?></th>
                    <th><?= $e($t('common.status')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($nodes as $node): ?>
                <tr>
                    <td>
                        <a href="/hyperv/hosts/<?= (int) $node['id'] ?>"><?= $e((string) $node['hostname']) ?></a>
                    </td>
                    <td>
                        <?= $node['cluster_name'] === null
                            ? '<span class="muted">' . $e($t('hv.standalone')) . '</span>'
                            : $e((string) $node['cluster_name']) ?>
                    </td>
                    <td><?= $e(trim((string) $node['vendor'] . ' ' . (string) $node['model'])) ?></td>
                    <td>
                        <?= (int) $node['cpu_sockets'] ?>&times;<?= (int) $node['cpu_cores'] ?>
                        <span class="muted">/ <?= (int) $node['cpu_logical'] ?></span>
                    </td>
                    <td class="numeric"><?= $e(Format::bytes($node['memory_bytes'])) ?></td>
                    <td class="numeric">
                        <?= (int) $node['machine_count'] ?>
                        <span class="muted">(<?= (int) $node['running_count'] ?>)</span>
                    </td>
                    <td class="data"><?= $e((string) $node['os_build']) ?></td>
                    <td>
                        <?= $node['node_state'] === null
                            ? '<span class="muted">—</span>'
                            : $e(Format::state((string) $node['node_state'])) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php endif; ?>
