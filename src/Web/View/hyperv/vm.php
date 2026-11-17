<?php
// src/Web/View/hyperv/vm.php
//
// One Hyper-V machine: configuration, disks, network, checkpoints,
// replication.
//
// Dynamic memory, replication and checkpoints are shown only when they are
// in use. Hyper-V reports a maximum memory of 1 TiB on every machine whose
// dynamic memory is off, and a replication state of Disabled on every
// machine that is not replicated — printing those would be four rows of
// noise on the machines that have nothing to say.

/** @var callable $e */
/** @var callable $t */
/** @var array<string,mixed> $machine */
/** @var list<array<string,mixed>> $disks */
/** @var list<array<string,mixed>> $nics */
/** @var list<array<string,mixed>> $checkpoints */

use DcInfraSnap\Web\Format;

$replicated = $machine['replication_state'] !== null
    && $machine['replication_state'] !== 'Disabled';
?>

<div class="page-head">
    <div>
        <h1><?= $e((string) $machine['name']) ?></h1>
        <p>
            <?= $e(Format::state((string) $machine['vm_state'])) ?> ·
            <?php if ($machine['node_id'] !== null): ?>
                <a href="/hyperv/hosts/<?= (int) $machine['node_id'] ?>"><?= $e((string) $machine['node_name']) ?></a>
            <?php else: ?>
                <?= $e((string) $machine['owner_node']) ?>
            <?php endif; ?>
            · <?= $e((string) $machine['integration_name']) ?>
        </p>
    </div>

    <div class="head-actions">
        <a href="/hyperv/vms" class="btn btn-inline"><?= $e($t('common.back')) ?></a>
    </div>
</div>

<div class="panel">
    <table>
        <tbody>
            <tr><th><?= $e($t('hv.status')) ?></th><td><?= $e((string) $machine['vm_status']) ?></td></tr>
            <tr><th><?= $e($t('hv.generation')) ?></th>
                <td><?= (int) $machine['generation'] ?>
                    <span class="muted"><?= $e($t('hv.config_version')) ?> <?= $e((string) $machine['config_version']) ?></span></td></tr>
            <tr><th><?= $e($t('hv.clustered')) ?></th>
                <td>
                    <?= $machine['is_clustered']
                        ? $e($t('common.yes'))
                        : $e($t('hv.not_clustered_hint')) ?>
                </td></tr>
            <tr><th><?= $e($t('hv.vcpu')) ?></th>
                <td><?= (int) $machine['processor_count'] ?>
                    <span class="muted"><?= $e($t('hv.cpu_limits', [
                        'reserve' => (string) (int) $machine['cpu_reserve_percent'],
                        'limit'   => (string) (int) $machine['cpu_limit_percent'],
                        'weight'  => (string) (int) $machine['cpu_relative_weight'],
                    ])) ?></span></td></tr>
            <tr><th><?= $e($t('hv.memory')) ?></th>
                <td>
                    <?= $e(Format::bytes($machine['memory_startup'])) ?>
                    <?php if ($machine['dynamic_memory_enabled']): ?>
                        <span class="muted">
                            <?= $e($t('hv.dynamic_range', [
                                'min' => Format::bytes($machine['memory_minimum']),
                                'max' => Format::bytes($machine['memory_maximum']),
                            ])) ?>
                        </span>
                    <?php else: ?>
                        <span class="muted"><?= $e($t('hv.static_memory')) ?></span>
                    <?php endif; ?>
                </td></tr>
            <tr><th><?= $e($t('hv.uptime')) ?></th><td><?= $e(Format::duration($machine['uptime_seconds'])) ?></td></tr>
            <tr><th><?= $e($t('hv.guest')) ?></th>
                <td>
                    <?= $e((string) ($machine['guest_os_name'] ?? $t('hv.guest_unreported'))) ?>
                    <?php if ($machine['guest_hostname'] !== null): ?>
                        <span class="data">· <?= $e((string) $machine['guest_hostname']) ?></span>
                    <?php endif; ?>
                </td></tr>
            <tr><th><?= $e($t('hv.integration_services')) ?></th>
                <td class="data"><?= $e(str_replace(',', ', ', (string) $machine['integration_services_enabled'])) ?></td></tr>
            <tr><th><?= $e($t('hv.secure_boot')) ?></th>
                <td><?= $e((string) $machine['secure_boot']) ?>
                    <span class="muted"><?= $e((string) $machine['secure_boot_template']) ?></span></td></tr>
            <tr><th><?= $e($t('hv.boot_order')) ?></th>
                <td class="data"><?= $e(str_replace(',', ' → ', (string) $machine['boot_order'])) ?></td></tr>
            <tr><th><?= $e($t('hv.automatic')) ?></th>
                <td><?= $e($t('hv.automatic_value', [
                        'start' => (string) $machine['automatic_start_action'],
                        'stop'  => (string) $machine['automatic_stop_action'],
                    ])) ?></td></tr>
            <tr><th><?= $e($t('hv.path')) ?></th><td class="data"><?= $e((string) $machine['vm_path']) ?></td></tr>
            <tr><th><?= $e($t('hv.created')) ?></th><td class="data"><?= $e((string) $machine['created_at_source']) ?></td></tr>
            <?php if ($machine['notes'] !== null): ?>
                <tr><th><?= $e($t('hv.notes')) ?></th><td><?= $e((string) $machine['notes']) ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="panel">
    <h2><?= $e($t('hv.disks')) ?></h2>

    <?php if ($disks === []): ?>
        <p class="muted">—</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th><?= $e($t('hv.controller')) ?></th>
                    <th><?= $e($t('hv.path')) ?></th>
                    <th><?= $e($t('hv.type')) ?></th>
                    <th class="numeric"><?= $e($t('hv.size')) ?></th>
                    <th class="numeric"><?= $e($t('hv.on_disk')) ?></th>
                    <th><?= $e($t('hv.shared')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($disks as $disk): ?>
                <tr>
                    <td class="data">
                        <?= $e((string) $disk['controller_type']) ?>
                        <?= (int) $disk['controller_number'] ?>:<?= (int) $disk['controller_location'] ?>
                    </td>
                    <td class="data"><?= $e((string) $disk['disk_path']) ?></td>
                    <td>
                        <?= $disk['is_pass_through']
                            ? $e($t('hv.pass_through'))
                            : $e((string) $disk['vhd_type'] . ' ' . (string) $disk['vhd_format']) ?>
                    </td>
                    <td class="numeric"><?= $e(Format::bytes($disk['size_bytes'])) ?></td>
                    <td class="numeric"><?= $e(Format::bytes($disk['file_size_bytes'])) ?></td>
                    <td>
                        <?php if ($disk['is_shared']): ?>
                            <span class="tag"><?= $e($t('hv.shared')) ?></span>
                        <?php elseif ((int) $disk['attached_to'] > 1): ?>
                            <span class="tag"><?= $e($t('hv.attached_to', [
                                'count' => (string) (int) $disk['attached_to'],
                            ])) ?></span>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($disk['vhd_error'] !== null): ?>
                    <tr><td colspan="6" class="muted"><?= $e((string) $disk['vhd_error']) ?></td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="panel">
    <h2><?= $e($t('hv.network')) ?></h2>

    <?php if ($nics === []): ?>
        <p class="muted">—</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th><?= $e($t('common.name')) ?></th>
                    <th><?= $e($t('hv.mac')) ?></th>
                    <th><?= $e($t('hv.switch')) ?></th>
                    <th><?= $e($t('hv.vlan')) ?></th>
                    <th><?= $e($t('hv.addresses')) ?></th>
                    <th><?= $e($t('hv.port_security')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($nics as $nic): ?>
                <?php
                $security = [];

                foreach (['mac_spoofing' => 'hv.mac_spoofing',
                          'dhcp_guard'   => 'hv.dhcp_guard',
                          'router_guard' => 'hv.router_guard'] as $column => $key) {
                    if ($nic[$column] === 'On') {
                        $security[] = $t($key);
                    }
                }
                ?>
                <tr>
                    <td><?= $e((string) $nic['name']) ?></td>
                    <td class="data">
                        <?= $e((string) $nic['mac_address']) ?>
                        <?php if (!$nic['dynamic_mac']): ?>
                            <span class="muted"><?= $e($t('hv.static_mac')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= $e((string) $nic['switch_name']) ?></td>
                    <td class="data">
                        <?php if ($nic['vlan_mode'] === 'Trunk'): ?>
                            <?= $e($t('hv.trunk')) ?> <?= $e((string) $nic['vlan_allowed_list']) ?>
                            <span class="muted"><?= $e($t('hv.native')) ?> <?= (int) $nic['vlan_native_id'] ?></span>
                        <?php elseif ($nic['vlan_access_id'] !== null): ?>
                            <?= (int) $nic['vlan_access_id'] ?>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="data"><?= $e(str_replace(',', ', ', (string) $nic['ip_addresses'])) ?></td>
                    <td>
                        <?= $security === []
                            ? '<span class="muted">—</span>'
                            : $e(implode(', ', $security)) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($checkpoints !== []): ?>
    <div class="panel">
        <h2><?= $e($t('hv.checkpoints')) ?></h2>
        <table>
            <thead>
                <tr>
                    <th><?= $e($t('common.name')) ?></th>
                    <th><?= $e($t('hv.type')) ?></th>
                    <th><?= $e($t('hv.created')) ?></th>
                    <th><?= $e($t('hv.parent')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($checkpoints as $checkpoint): ?>
                <tr>
                    <td><?= $e((string) $checkpoint['name']) ?></td>
                    <td><?= $e((string) $checkpoint['checkpoint_type']) ?></td>
                    <td class="data">
                        <?= $e((string) $checkpoint['created_at_source']) ?>
                        <span class="muted"><?= $e(Format::ago($checkpoint['created_at_source'])) ?></span>
                    </td>
                    <td><?= $e((string) ($checkpoint['parent_checkpoint_name'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($replicated): ?>
    <div class="panel">
        <h2><?= $e($t('hv.replication')) ?></h2>
        <table>
            <tbody>
                <tr><th><?= $e($t('common.status')) ?></th>
                    <td><?= $e((string) $machine['replication_state']) ?>
                        <span class="muted"><?= $e((string) $machine['replication_health']) ?></span></td></tr>
                <tr><th><?= $e($t('hv.role')) ?></th><td><?= $e((string) $machine['replication_role']) ?></td></tr>
                <tr><th><?= $e($t('hv.replica_server')) ?></th>
                    <td class="data"><?= $e((string) $machine['replication_replica_server']) ?></td></tr>
                <tr><th><?= $e($t('hv.frequency')) ?></th>
                    <td><?= $e(Format::duration($machine['replication_frequency_sec'])) ?></td></tr>
                <tr><th><?= $e($t('hv.last_replication')) ?></th>
                    <td class="data"><?= $e((string) $machine['replication_last_time']) ?></td></tr>
            </tbody>
        </table>
    </div>
<?php endif; ?>
