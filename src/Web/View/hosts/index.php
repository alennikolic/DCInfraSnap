<?php
// src/Web/View/hosts/index.php
//
// ESXi hosts: what they are, what they run on, and how much of it there is.

/** @var callable $e */
/** @var callable $t */
/** @var list<array<string,mixed>> $rows */
/** @var array<int,int> $lastRuns */

use DcInfraSnap\Web\Format;
?>

<div class="page-head">
    <div>
        <h1><?= $e($t('nav.hosts')) ?></h1>
        <p><?= $e($t('list.showing', ['count' => Format::number(count($rows))])) ?></p>
    </div>
</div>

<?php if ($rows === []): ?>
    <div class="panel empty">
        <p class="empty-title"><?= $e($t('list.nothing_yet')) ?></p>
        <p><?= $e($t('list.collect_first')) ?></p>
    </div>
<?php else: ?>
    <div class="panel">
        <table>
            <thead>
                <tr>
                    <th><?= $e($t('common.name')) ?></th>
                    <th><?= $e($t('hosts.state')) ?></th>
                    <th><?= $e($t('common.cluster')) ?></th>
                    <th><?= $e($t('hosts.vms')) ?></th>
                    <th><?= $e($t('hosts.hardware')) ?></th>
                    <th><?= $e($t('hosts.cpu_ram')) ?></th>
                    <th><?= $e($t('hosts.esxi')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                $reference = $lastRuns[(int) $row['integration_id']] ?? null;
                $isMissing = $reference !== null && (int) $row['last_run_id'] < $reference;
                $state     = (string) ($row['connection_state'] ?? '');
                ?>
                <tr>
                    <td>
                        <a href="/hosts/<?= (int) $row['id'] ?>"><?= $e($row['name']) ?></a>
                        <?php if ((int) ($row['in_maintenance'] ?? 0) === 1): ?>
                            <span class="badge badge-idle"><?= $e($t('hosts.maintenance')) ?></span>
                        <?php endif; ?>
                        <?php if ($isMissing): ?>
                            <span class="badge badge-error"><?= $e($t('list.missing')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= $state === 'CONNECTED' ? 'badge-ok' : 'badge-error' ?>">
                            <?= $e(Format::state($state)) ?>
                        </span>
                    </td>
                    <td><?= $e($row['cluster_name'] ?? '—') ?></td>
                    <td class="data"><?= $e(Format::number($row['vm_count'])) ?></td>
                    <td>
                        <?= $e(trim(($row['vendor'] ?? '') . ' ' . ($row['model'] ?? '')) ?: '—') ?>
                        <?php if (($row['serial_number'] ?? '') !== ''): ?>
                            <div class="sub data"><?= $e($row['serial_number']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="data">
                        <?= $e(Format::number($row['cpu_cores'])) ?>
                        <span class="muted">&times; <?= $e(Format::number($row['cpu_sockets'])) ?></span>
                        <span class="sep">/</span>
                        <?= $e(Format::bytes($row['memory_bytes'])) ?>
                    </td>
                    <td class="data"><?= $e($row['esxi_version'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
