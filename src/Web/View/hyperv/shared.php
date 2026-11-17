<?php
// src/Web/View/hyperv/shared.php
//
// Virtual disks that more than one machine is attached to.
//
// There is no shared-disk table: a shared VHDX and a VHD Set are ordinary
// disk rows with a flag, and what makes them interesting is the grouping —
// which machines sit on the same file. That is a guest cluster, and it is
// the one thing about Hyper-V storage that a list of machines cannot show.

/** @var callable $e */
/** @var callable $t */
/** @var list<array<string,mixed>> $disks */

use DcInfraSnap\Web\Format;
?>

<div class="page-head">
    <div>
        <h1><?= $e($t('nav.hyperv_shared')) ?></h1>
        <p><?= $e($t('hv.shared_subtitle')) ?></p>
    </div>
</div>

<?php if ($disks === []): ?>

    <div class="panel empty">
        <p class="empty-title"><?= $e($t('hv.no_shared')) ?></p>
        <p><?= $e($t('hv.no_shared_hint')) ?></p>
    </div>

<?php else: ?>

    <div class="panel">
        <table>
            <thead>
                <tr>
                    <th><?= $e($t('hv.path')) ?></th>
                    <th class="numeric"><?= $e($t('hv.size')) ?></th>
                    <th class="numeric"><?= $e($t('hv.machines')) ?></th>
                    <th><?= $e($t('hv.attached')) ?></th>
                    <th><?= $e($t('hv.shared')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($disks as $disk): ?>
                <tr>
                    <td class="data"><?= $e((string) $disk['disk_path']) ?></td>
                    <td class="numeric"><?= $e(Format::bytes($disk['size_bytes'])) ?></td>
                    <td class="numeric"><?= (int) $disk['machine_count'] ?></td>
                    <td><?= $e((string) $disk['machines']) ?></td>
                    <td>
                        <?= $disk['is_shared']
                            ? '<span class="tag">' . $e($t('hv.shared')) . '</span>'
                            : '<span class="muted">' . $e($t('hv.same_file')) . '</span>' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php endif; ?>
