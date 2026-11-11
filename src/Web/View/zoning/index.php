<?php
// src/Web/View/zoning/index.php
//
// Zoning: the configurations, zones and aliases, and what is in each.
//
// TWO SECTIONS, SHOWN ONE AT A TIME. A switch keeps a defined configuration —
// what somebody wrote — and an effective one, which is what it is currently
// enforcing. They differ in a way that matters: a defined zone lists alias
// names, while the same zone in the effective configuration lists the WWPNs
// those aliases resolved to. Mixing them on one page would hide exactly the
// discrepancy you came here to find, so the section is a choice.
//
// The search looks inside the members as well as at the names, because the
// usual question is "what is this WWPN zoned to" rather than "show me zone X".

/** @var callable $e */
/** @var callable $t */
/** @var list<array<string,mixed>> $rows */
/** @var string $section */
/** @var string $kind */
/** @var string $search */

use DcInfraSnap\Web\Format;
?>

<div class="page-head">
    <div>
        <h1><?= $e($t('nav.zoning')) ?></h1>
        <p><?= $e($t('list.showing', ['count' => Format::number(count($rows))])) ?></p>
    </div>
</div>

<form method="get" class="panel filters">
    <input type="search" name="q" value="<?= $e($search) ?>"
           placeholder="<?= $e($t('zoning.search_placeholder')) ?>" class="filter-search">

    <select name="section">
        <option value="defined" <?= $section === 'defined' ? 'selected' : '' ?>>
            <?= $e($t('zoning.defined')) ?>
        </option>
        <option value="effective" <?= $section === 'effective' ? 'selected' : '' ?>>
            <?= $e($t('zoning.effective')) ?>
        </option>
    </select>

    <select name="kind">
        <option value=""><?= $e($t('zoning.any_kind')) ?></option>
        <?php foreach (['cfg', 'zone', 'alias'] as $option): ?>
            <option value="<?= $e($option) ?>" <?= $kind === $option ? 'selected' : '' ?>>
                <?= $e($t('zoning.kind_' . $option)) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <button type="submit" class="btn btn-inline"><?= $e($t('common.search')) ?></button>

    <?php if ($search !== '' || $kind !== '' || $section !== 'defined'): ?>
        <a href="/zoning" class="btn btn-inline"><?= $e($t('filter.clear')) ?></a>
    <?php endif; ?>
</form>

<p class="note-block">
    <?= $e($section === 'defined' ? $t('zoning.defined_note') : $t('zoning.effective_note')) ?>
</p>

<?php if ($rows === []): ?>
    <div class="panel empty">
        <p class="empty-title">
            <?= $e($search !== '' || $kind !== '' ? $t('list.nothing_found') : $t('zoning.none')) ?>
        </p>
        <p><?= $e($t('san.none_hint')) ?></p>
    </div>
<?php else: ?>
    <div class="panel">
        <table>
            <thead>
                <tr>
                    <th><?= $e($t('zoning.kind')) ?></th>
                    <th><?= $e($t('common.name')) ?></th>
                    <th><?= $e($t('zoning.members')) ?></th>
                    <th><?= $e($t('san.switch')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td>
                        <span class="badge badge-idle"><?= $e($t('zoning.kind_' . $row['kind'])) ?></span>
                    </td>
                    <td>
                        <?= $e($row['name']) ?>
                        <div class="sub"><?= $e($t('zoning.member_count', [
                            'count' => (string) (int) $row['member_count'],
                        ])) ?></div>
                    </td>
                    <td class="member-list">
                        <?php if ($row['members'] === []): ?>
                            <span class="muted">—</span>
                        <?php else: ?>
                            <?php foreach ($row['members'] as $member): ?>
                                <?php
                                // A WWPN is shown in the monospaced face, an
                                // alias or zone name in the body face: at a
                                // glance that is the difference between the
                                // defined and the effective view of a zone.
                                $isAddress = in_array(
                                    (string) $member['member_kind'],
                                    ['wwpn', 'domain_port'],
                                    true
                                );
                                ?>
                                <span class="member<?= $isAddress ? ' data' : '' ?>"><?=
                                    $e($member['member_name'])
                                ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td><?= $e($row['switch_name'] ?? $row['integration_name']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
