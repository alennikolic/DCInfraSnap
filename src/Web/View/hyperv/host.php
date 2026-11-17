<?php
// src/Web/View/hyperv/host.php
//
// One Hyper-V host: what it is, what it is wired to, and what runs on it.
//
// The network devices are shown in one table with a kind column, the same
// arrangement the vCenter host page uses, because a physical adapter and the
// management OS side of a virtual one are read together: one has the link,
// the other has the address.

/** @var callable $e */
/** @var callable $t */
/** @var array<string,mixed> $node */
/** @var list<array<string,mixed>> $devices */
/** @var list<array<string,mixed>> $switches */
/** @var list<array<string,mixed>> $machines */

use DcInfraSnap\Web\Format;
?>

<div class="page-head">
    <div>
        <h1><?= $e((string) $node['hostname']) ?></h1>
        <p><?= $e((string) ($node['cluster_name'] ?? $t('hv.standalone'))) ?>
           · <?= $e((string) $node['integration_name']) ?></p>
    </div>

    <div class="head-actions">
        <a href="/hyperv" class="btn btn-inline"><?= $e($t('common.back')) ?></a>
    </div>
</div>

<div class="panel">
    <table class="table-facts">
        <tbody>
            <tr><th><?= $e($t('hv.fqdn')) ?></th><td class="data"><?= $e((string) $node['fqdn']) ?></td></tr>
            <tr><th><?= $e($t('hv.hardware')) ?></th>
                <td><?= $e(trim((string) $node['vendor'] . ' ' . (string) $node['model'])) ?>
                    · <?= $e($t('hv.serial')) ?> <span class="data"><?= $e((string) $node['serial_number']) ?></span>
                    · BIOS <span class="data"><?= $e((string) $node['bios_version']) ?></span></td></tr>
            <tr><th><?= $e($t('hv.os')) ?></th>
                <td><?= $e((string) $node['os_caption']) ?>
                    <span class="data"><?= $e((string) $node['os_version']) ?></span></td></tr>
            <tr><th><?= $e($t('hv.processor')) ?></th>
                <td><?= $e((string) $node['cpu_model']) ?> —
                    <?= (int) $node['cpu_sockets'] ?> <?= $e($t('hv.sockets')) ?>,
                    <?= (int) $node['cpu_cores'] ?> <?= $e($t('hv.cores')) ?>,
                    <?= (int) $node['cpu_logical'] ?> <?= $e($t('hv.logical')) ?></td></tr>
            <tr><th><?= $e($t('hv.memory')) ?></th><td><?= $e(Format::bytes($node['memory_bytes'])) ?></td></tr>
            <tr><th><?= $e($t('hv.uptime')) ?></th><td><?= $e(Format::duration($node['uptime_seconds'])) ?></td></tr>
            <tr><th><?= $e($t('hv.vm_path')) ?></th><td class="data"><?= $e((string) $node['virtual_machine_path']) ?></td></tr>
            <tr><th><?= $e($t('hv.live_migration')) ?></th>
                <td>
                    <?= $node['migration_enabled']
                        ? $e((string) $node['migration_auth_type'] . ' / ' . (string) $node['migration_performance'])
                        : $e($t('common.no')) ?>
                </td></tr>
            <tr><th><?= $e($t('hv.replica')) ?></th>
                <td>
                    <?= $node['replica_enabled'] === null
                        ? '<span class="muted">' . $e($t('hv.replica_unconfigured')) . '</span>'
                        : $e(Format::flag($node['replica_enabled'], $t('common.yes'), $t('common.no'))) ?>
                </td></tr>
            <tr><th><?= $e($t('hv.collected')) ?></th>
                <td class="data"><?= $e((string) $node['source_collected_at']) ?>
                    <span class="muted">· PowerShell <?= $e((string) $node['powershell_version']) ?></span></td></tr>
        </tbody>
    </table>
</div>

<?php if ($switches !== []): ?>
    <div class="panel">
        <h2><?= $e($t('hv.switches')) ?></h2>
        <table>
            <thead>
                <tr>
                    <th><?= $e($t('common.name')) ?></th>
                    <th><?= $e($t('hv.type')) ?></th>
                    <th><?= $e($t('hv.teaming')) ?></th>
                    <th><?= $e($t('hv.uplinks')) ?></th>
                    <th><?= $e($t('hv.bandwidth_mode')) ?></th>
                    <th class="numeric"><?= $e($t('hv.adapters')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($switches as $switch): ?>
                <tr>
                    <td><?= $e((string) $switch['name']) ?></td>
                    <td><?= $e((string) $switch['switch_type']) ?></td>
                    <td>
                        <?= $switch['embedded_teaming']
                            ? $e('SET · ' . (string) $switch['team_teaming_mode'] . ' / ' . (string) $switch['team_load_balancing'])
                            : '<span class="muted">—</span>' ?>
                    </td>
                    <td class="data"><?= $e(str_replace(',', ', ', (string) $switch['uplink_adapters'])) ?></td>
                    <td><?= $e((string) $switch['bandwidth_reservation_mode']) ?></td>
                    <td class="numeric"><?= (int) $switch['nic_count'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($devices !== []): ?>
    <div class="panel">
        <h2><?= $e($t('hv.devices')) ?></h2>
        <table>
            <thead>
                <tr>
                    <th><?= $e($t('hv.kind')) ?></th>
                    <th><?= $e($t('common.name')) ?></th>
                    <th><?= $e($t('hv.mac')) ?></th>
                    <th><?= $e($t('hv.link')) ?></th>
                    <th><?= $e($t('hv.vlan')) ?></th>
                    <th><?= $e($t('hv.addresses')) ?></th>
                    <th><?= $e($t('hv.driver')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($devices as $device): ?>
                <tr>
                    <td><span class="tag"><?= $e((string) $device['kind']) ?></span></td>
                    <td><?= $e((string) $device['name']) ?></td>
                    <td class="data"><?= $e((string) $device['mac_address']) ?></td>
                    <td>
                        <?= $e((string) ($device['link_speed'] ?? '')) ?>
                        <span class="muted"><?= $e((string) $device['device_status']) ?></span>
                    </td>
                    <td class="data">
                        <?php if ($device['vlan_mode'] === 'Trunk'): ?>
                            <?= $e($t('hv.trunk')) ?> <?= $e((string) $device['vlan_allowed_list']) ?>
                        <?php elseif ($device['vlan_access_id'] !== null): ?>
                            <?= (int) $device['vlan_access_id'] ?>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="data"><?= $e(str_replace(',', ', ', (string) $device['ip_addresses'])) ?></td>
                    <td class="data">
                        <?= $e((string) $device['driver_name']) ?>
                        <span class="muted"><?= $e((string) $device['driver_version']) ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="panel">
    <h2><?= $e($t('hv.machines_here')) ?></h2>

    <?php if ($machines === []): ?>
        <p class="muted"><?= $e($t('hv.no_machines')) ?></p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th><?= $e($t('common.name')) ?></th>
                    <th><?= $e($t('common.status')) ?></th>
                    <th class="numeric"><?= $e($t('hv.vcpu')) ?></th>
                    <th class="numeric"><?= $e($t('hv.memory')) ?></th>
                    <th class="numeric"><?= $e($t('hv.disks')) ?></th>
                    <th><?= $e($t('hv.guest')) ?></th>
                    <th><?= $e($t('hv.clustered')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($machines as $machine): ?>
                <tr>
                    <td><a href="/hyperv/vms/<?= (int) $machine['id'] ?>"><?= $e((string) $machine['name']) ?></a></td>
                    <td><?= $e(Format::state((string) $machine['vm_state'])) ?></td>
                    <td class="numeric"><?= (int) $machine['processor_count'] ?></td>
                    <td class="numeric"><?= $e(Format::bytes($machine['memory_startup'])) ?></td>
                    <td class="numeric"><?= (int) $machine['disk_count'] ?></td>
                    <td><?= $e((string) ($machine['guest_os_name'] ?? '')) ?></td>
                    <td><?= $machine['is_clustered'] ? $e($t('common.yes')) : '<span class="muted">—</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
