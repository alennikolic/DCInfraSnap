<?php
// src/Web/View/reports/index.php
//
// The form that generates a report.
//
// WHY A RADIO AND NOT CLEVERNESS. There are two ways to say what the report
// covers — a project, or a hand-picked set of sources — and both controls are
// on the page at once. Without JavaScript nothing can grey one out, and
// guessing which the person meant from which control they touched last is the
// kind of helpfulness that generates the wrong document. The radio says it.
//
// The language is not on this form on purpose: the report is written in the
// interface language, which is already chosen in the top right of every page.

/** @var callable $e */
/** @var callable $t */
/** @var string $csrfToken */
/** @var list<\DcInfraSnap\Project\Project> $projects */
/** @var array<int, int> $projectSourceCounts */
/** @var list<array<string, mixed>> $sources */
/** @var array<string, string> $values */
/** @var list<int> $selectedSources */
/** @var list<string> $selectedSections */
/** @var list<string> $errors */

use DcInfraSnap\Report\ReportBuilder;

$scope = ($values['scope'] ?? 'project') === 'sources' ? 'sources' : 'project';
?>

<div class="page-head">
    <div>
        <h1><?= $e($t('nav.reports')) ?></h1>
        <p><?= $e($t('reports.subtitle')) ?></p>
    </div>
</div>

<?php if ($sources === []): ?>

    <div class="panel empty">
        <p class="empty-title"><?= $e($t('projects.no_sources')) ?></p>
        <p><?= $e($t('projects.no_sources_hint')) ?></p>
        <p><a href="/integrations" class="btn btn-inline"><?= $e($t('nav.integrations')) ?></a></p>
    </div>

<?php else: ?>

    <form method="post" class="panel form-panel" action="/reports">

        <?php if ($errors !== []): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <div><?= $e($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">

        <h2><?= $e($t('reports.scope')) ?></h2>

        <label class="check">
            <input type="radio" name="scope" value="project" <?= $scope === 'project' ? 'checked' : '' ?>>
            <span><?= $e($t('reports.scope_project')) ?></span>
        </label>

        <label class="field">
            <span class="field-label"><?= $e($t('nav.projects')) ?></span>
            <select name="project">
                <?php if ($projects === []): ?>
                    <option value=""><?= $e($t('projects.none')) ?></option>
                <?php endif; ?>
                <?php foreach ($projects as $project): ?>
                    <option value="<?= (int) $project->id ?>"
                        <?= (string) ($values['project'] ?? '') === (string) $project->id ? 'selected' : '' ?>>
                        <?= $e($project->label()) ?>
                        (<?= $e($t('projects.sources_count', ['count' => $projectSourceCounts[$project->id] ?? 0])) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="check">
            <input type="radio" name="scope" value="sources" <?= $scope === 'sources' ? 'checked' : '' ?>>
            <span><?= $e($t('reports.scope_sources')) ?></span>
        </label>

        <?php foreach ($sources as $source): ?>
            <?php $sourceId = (int) $source['id']; ?>
            <label class="check">
                <input type="checkbox" name="sources[]" value="<?= $sourceId ?>"
                    <?= in_array($sourceId, $selectedSources, true) ? 'checked' : '' ?>>
                <span>
                    <?= $e((string) $source['name']) ?>
                    <span class="field-hint">
                        <?= $e($t('integrations.kind_' . (string) $source['type'])) ?>
                        · <?= $e((string) $source['hostname']) ?>
                    </span>
                </span>
            </label>
        <?php endforeach; ?>

        <h2><?= $e($t('reports.sections')) ?></h2>
        <p class="field-hint"><?= $e($t('reports.sections_hint')) ?></p>

        <p class="caption"><?= $e($t('report.chapter_vmware')) ?></p>

        <?php foreach (ReportBuilder::VMWARE_SECTIONS as $section): ?>
            <label class="check">
                <input type="checkbox" name="sections[]" value="<?= $e($section) ?>"
                    <?= in_array($section, $selectedSections, true) ? 'checked' : '' ?>>
                <span><?= $e($t(ReportBuilder::SECTION_LABELS[$section])) ?></span>
            </label>
        <?php endforeach; ?>

        <p class="caption"><?= $e($t('report.chapter_fc')) ?></p>

        <?php foreach (ReportBuilder::FC_SECTIONS as $section): ?>
            <label class="check">
                <input type="checkbox" name="sections[]" value="<?= $e($section) ?>"
                    <?= in_array($section, $selectedSections, true) ? 'checked' : '' ?>>
                <span><?= $e($t(ReportBuilder::SECTION_LABELS[$section])) ?></span>
            </label>
        <?php endforeach; ?>

        <div class="form-actions">
            <!-- The format travels on the button, so a further format is one
                 more button and no change to the handler. -->
            <button type="submit" name="format" value="docx" class="btn btn-primary btn-inline">
                <?= $e($t('reports.generate_docx')) ?>
            </button>
            <button type="submit" name="format" value="html" class="btn btn-inline">
                <?= $e($t('reports.generate_html')) ?>
            </button>
        </div>
    </form>

    <p class="note-block"><?= $e($t('reports.language_note')) ?></p>
    <p class="note-block"><?= $e($t('reports.docx_note')) ?></p>
    <p class="note-block"><?= $e($t('reports.pdf_note')) ?></p>

<?php endif; ?>
