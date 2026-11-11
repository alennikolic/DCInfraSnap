<?php
// src/Report/HtmlWriter.php
//
// A Document as one self-contained HTML file.
//
// Self-contained on purpose: the file is downloaded and passed around, so it
// carries its own stylesheet and refers to nothing outside itself. Opened
// from a mail attachment on a machine that has never heard of DCInfraSnap,
// it still looks like the document it is.
//
// THE PRINT STYLESHEET IS THE PDF. There is no PDF writer: the browser's own
// "save as PDF" is the PDF, so the @page rules, the page breaks before each
// chapter and the repeating table headers are not decoration — they are what
// makes the printed file usable. A table header that does not repeat across a
// page break turns a forty-row port table into guesswork.
//
// Everything that comes out of the document is escaped here. The builder put
// no markup in it, and this is the only place that adds any.

declare(strict_types=1);

namespace DcInfraSnap\Report;

final class HtmlWriter
{
    public function extension(): string
    {
        return 'html';
    }

    public function contentType(): string
    {
        return 'text/html; charset=utf-8';
    }

    public function write(Document $document): string
    {
        $html = '<!doctype html>' . "\n"
            . '<html lang="' . $this->e($document->locale) . '">' . "\n"
            . '<head>' . "\n"
            . '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<title>' . $this->e($document->title . ' — ' . $document->subtitle) . '</title>' . "\n"
            . '<style>' . "\n" . $this->css() . '</style>' . "\n"
            . '</head>' . "\n"
            . '<body>' . "\n";

        $html .= $this->titlePage($document);
        $html .= $this->contents($document);

        foreach ($document->chapters as $chapter) {
            $html .= $this->chapter($chapter);
        }

        $html .= '<footer class="doc-footer">' . $this->e($document->footer)
            . ' · ' . $this->e($document->generatedAt) . '</footer>' . "\n";

        return $html . '</body>' . "\n" . '</html>' . "\n";
    }

    // -----------------------------------------------------------------
    // Front matter
    // -----------------------------------------------------------------

    private function titlePage(Document $document): string
    {
        $html = '<section class="title-page">' . "\n"
            . '<h1 class="doc-title">' . $this->e($document->title) . '</h1>' . "\n"
            . '<p class="doc-subtitle">' . $this->e($document->subtitle) . '</p>' . "\n";

        if ($document->titleFacts !== []) {
            $html .= '<dl class="facts">' . "\n";

            foreach ($document->titleFacts as [$label, $value]) {
                $html .= '<dt>' . $this->e($label) . '</dt><dd>' . $this->cell($value) . '</dd>' . "\n";
            }

            $html .= '</dl>' . "\n";
        }

        return $html . '</section>' . "\n";
    }

    /**
     * The table of contents.
     *
     * Chapters and both levels of section. In HTML these are links, which is
     * what the document is navigated by on screen; in print they lose the
     * page numbers a word processor would add, which is one of the reasons
     * the Word version is worth having.
     */
    private function contents(Document $document): string
    {
        $html = '<section class="contents page-break">' . "\n"
            . '<h2>' . $this->e($document->contentsHeading) . '</h2>' . "\n"
            . '<ul class="toc">' . "\n";

        foreach ($document->chapters as $chapter) {
            $html .= '<li class="toc-1"><a href="#' . $this->e($chapter->anchor()) . '">'
                . $this->e($chapter->title()) . '</a></li>' . "\n";

            foreach ($chapter->sections as $section) {
                $html .= '<li class="toc-' . $section->level . '"><a href="#'
                    . $this->e($section->anchor()) . '">'
                    . $this->e($section->title()) . '</a></li>' . "\n";
            }
        }

        return $html . '</ul>' . "\n" . '</section>' . "\n";
    }

    // -----------------------------------------------------------------
    // Body
    // -----------------------------------------------------------------

    private function chapter(Chapter $chapter): string
    {
        $html = '<section class="chapter page-break">' . "\n"
            . '<h1 id="' . $this->e($chapter->anchor()) . '">' . $this->e($chapter->title()) . '</h1>' . "\n";

        foreach ($chapter->sections as $section) {
            $tag = $section->level === 2 ? 'h2' : 'h3';

            $html .= '<' . $tag . ' id="' . $this->e($section->anchor()) . '">'
                . $this->e($section->title()) . '</' . $tag . '>' . "\n";

            foreach ($section->blocks as $block) {
                $html .= $this->block($block);
            }
        }

        return $html . '</section>' . "\n";
    }

    private function block(Block $block): string
    {
        return match ($block->kind) {
            Block::FACTS     => $this->facts($block),
            Block::TABLE     => $this->table($block),
            Block::PARAGRAPH => '<p class="note">' . $this->cell((string) $block->text) . '</p>' . "\n",
            Block::EMPTY     => '<p class="empty">' . $this->e((string) $block->text) . '</p>' . "\n",
            default          => '',
        };
    }

    private function facts(Block $block): string
    {
        if ($block->facts === []) {
            return '';
        }

        $html = '';

        if ($block->caption !== null) {
            $html .= '<p class="caption">' . $this->e($block->caption) . '</p>' . "\n";
        }

        $html .= '<dl class="facts">' . "\n";

        foreach ($block->facts as [$label, $value]) {
            $html .= '<dt>' . $this->e($label) . '</dt><dd>' . $this->cell($value) . '</dd>' . "\n";
        }

        return $html . '</dl>' . "\n";
    }

    private function table(Block $block): string
    {
        $html = '';

        if ($block->caption !== null) {
            $html .= '<p class="caption">' . $this->e($block->caption) . '</p>' . "\n";
        }

        if ($block->rows === []) {
            return $html;
        }

        // thead is a real thead so it repeats on every printed page.
        $html .= '<table>' . "\n" . '<thead>' . "\n" . '<tr>';

        foreach ($block->headings as $heading) {
            $html .= '<th>' . $this->e($heading) . '</th>';
        }

        $html .= '</tr>' . "\n" . '</thead>' . "\n" . '<tbody>' . "\n";

        foreach ($block->rows as $row) {
            $html .= '<tr>';

            foreach ($row as $value) {
                $html .= '<td>' . $this->cell($value) . '</td>';
            }

            $html .= '</tr>' . "\n";
        }

        $html .= '</tbody>' . "\n" . '</table>' . "\n";

        if ($block->note !== null) {
            $html .= '<p class="note">' . $this->e($block->note) . '</p>' . "\n";
        }

        return $html;
    }

    // -----------------------------------------------------------------
    // Escaping
    // -----------------------------------------------------------------

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * A cell value, with the newlines the builder put in it turned into line
     * breaks.
     *
     * Escaped first and broken afterwards, so the break tags are the only
     * markup that survives: a zone member called "a<b" stays text.
     */
    private function cell(string $value): string
    {
        return str_replace("\n", '<br>', $this->e($value));
    }

    private function css(): string
    {
        return <<<'CSS'
:root { --ink:#1a1d21; --muted:#5b6570; --line:#c9d1d9; --head:#f1f4f7; }
* { box-sizing:border-box; }
body {
    margin:0 auto; padding:32px 28px 64px; max-width:1100px;
    font:14px/1.5 -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    color:var(--ink); background:#fff;
}
h1 { font-size:22px; margin:0 0 16px; padding-bottom:8px; border-bottom:2px solid var(--ink); }
h2 { font-size:17px; margin:28px 0 10px; }
h3 { font-size:15px; margin:22px 0 8px; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
p { margin:0 0 10px; }

.title-page { padding:48px 0 32px; }
.doc-title { font-size:32px; border:0; padding:0; margin:0 0 6px; }
.doc-subtitle { font-size:16px; color:var(--muted); margin:0 0 28px; }

.contents h2 { margin-top:0; }
.toc { list-style:none; margin:0; padding:0; }
.toc li { padding:2px 0; }
.toc-1 { margin-top:10px; font-weight:600; }
.toc-2 { padding-left:18px; }
.toc-3 { padding-left:38px; color:var(--muted); }
.toc a { color:inherit; text-decoration:none; }
.toc a:hover { text-decoration:underline; }

dl.facts { display:grid; grid-template-columns:minmax(140px,220px) 1fr; gap:2px 16px; margin:0 0 14px; }
dl.facts dt { color:var(--muted); }
dl.facts dd { margin:0; }

table { width:100%; border-collapse:collapse; margin:0 0 12px; font-size:12.5px; }
th, td { border:1px solid var(--line); padding:5px 7px; text-align:left; vertical-align:top; }
th { background:var(--head); font-weight:600; }

.caption { font-weight:600; margin:14px 0 6px; }
.note { color:var(--muted); font-size:12px; }
.empty { color:var(--muted); font-style:italic; }
.doc-footer { margin-top:40px; padding-top:10px; border-top:1px solid var(--line); color:var(--muted); font-size:12px; }

@media print {
    /* A4 with room for the printer's own header and footer. */
    @page { size:A4; margin:16mm 12mm; }
    body { max-width:none; padding:0; font-size:10.5pt; }
    table { font-size:8.5pt; }
    h1 { font-size:16pt; } h2 { font-size:12.5pt; } h3 { font-size:10.5pt; }

    .page-break { break-before:page; page-break-before:always; }

    /* Without this a long table loses its header on every page but the first. */
    thead { display:table-header-group; }
    tr, dl.facts { break-inside:avoid; page-break-inside:avoid; }
    h1, h2, h3, .caption { break-after:avoid; page-break-after:avoid; }
    a { color:inherit; text-decoration:none; }
    .doc-footer { display:none; }
}
CSS;
    }
}
