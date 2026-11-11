<?php
// src/Report/DocxWriter.php
//
// A Document as a real Word file.
//
// NOT CONVERTED FROM THE HTML. This walks the same Document the HtmlWriter
// walks. Going through HTML would make the Word file depend on the HTML's
// markup, so a change to one would break the other, and Word would be stuck
// with whatever HTML could express.
//
// WHAT A .docx IS. A ZIP of XML parts:
//
//   [Content_Types].xml        what every part in the package is
//   _rels/.rels                the package's own relationships
//   word/document.xml          the text
//   word/styles.xml            the named styles the text refers to
//   word/settings.xml          document-wide settings
//   word/footer1.xml           the footer, with the page-number fields
//   word/_rels/document.xml.rels   what document.xml refers to
//   docProps/core.xml, app.xml     title, author, application
//
// Hand-written WordprocessingML rather than PhpWord, which would break the
// no-third-party-packages rule, and the ZIP comes from src/Report/Zip.php
// rather than the zip extension, which the container does not have.
//
// WHY THE HEADINGS MATTER. Heading1, Heading2 and Heading3 carry outline
// levels, which is what puts the chapters in Word's navigation pane and what
// the table of contents is built from. A document that merely looked like it
// had headings — bold, larger text — would have an empty navigation pane and
// an empty TOC, and that is most of the reason Alen wanted Word at all.
//
// THE TABLE OF CONTENTS IS A FIELD, not a list written out here. Word fills
// it in, with the page numbers only it knows, and settings.xml asks for the
// fields to be updated when the document opens.
//
// Measurements are in twips, a twentieth of a point: 1 cm is 567, and an A4
// page is 11906 by 16838.

declare(strict_types=1);

namespace DcInfraSnap\Report;

final class DocxWriter implements Writer
{
    private const PAGE_WIDTH  = 11906;
    private const PAGE_HEIGHT = 16838;

    /** 1.5 cm. Narrower than a letter would be: these tables are wide. */
    private const MARGIN = 851;

    private const CONTENT_WIDTH = self::PAGE_WIDTH - (2 * self::MARGIN);

    private const RID_STYLES   = 'rId1';
    private const RID_SETTINGS = 'rId2';
    private const RID_FOOTER   = 'rId3';

    public function extension(): string
    {
        return 'docx';
    }

    public function contentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }

    public function isDownload(): bool
    {
        return true;
    }

    public function write(Document $document): string
    {
        return (new Zip())
            ->add('[Content_Types].xml', $this->contentTypes())
            ->add('_rels/.rels', $this->packageRels())
            ->add('docProps/core.xml', $this->coreProperties($document))
            ->add('docProps/app.xml', $this->appProperties())
            ->add('word/_rels/document.xml.rels', $this->documentRels())
            ->add('word/styles.xml', $this->styles($document))
            ->add('word/settings.xml', $this->settings())
            ->add('word/footer1.xml', $this->footer($document))
            ->add('word/document.xml', $this->document($document))
            ->finish();
    }

    // =================================================================
    // The package
    // =================================================================

    private function contentTypes(): string
    {
        return $this->xmlDeclaration()
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/word/settings.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
            . '<Override PartName="/word/footer1.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
            . '<Override PartName="/docProps/core.xml"'
            . ' ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private function packageRels(): string
    {
        $base = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

        return $this->xmlDeclaration()
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $base . 'officeDocument" Target="word/document.xml"/>'
            . '<Relationship Id="rId2"'
            . ' Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties"'
            . ' Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="' . $base . 'extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function documentRels(): string
    {
        $base = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

        return $this->xmlDeclaration()
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="' . self::RID_STYLES . '" Type="' . $base . 'styles" Target="styles.xml"/>'
            . '<Relationship Id="' . self::RID_SETTINGS . '" Type="' . $base . 'settings" Target="settings.xml"/>'
            . '<Relationship Id="' . self::RID_FOOTER . '" Type="' . $base . 'footer" Target="footer1.xml"/>'
            . '</Relationships>';
    }

    private function coreProperties(Document $document): string
    {
        // The generated-at string is "YYYY-MM-DD HH:MM UTC"; W3CDTF wants
        // the same moment as 2026-11-11T13:00:00Z.
        $stamp = str_replace(' UTC', ':00Z', str_replace(' ', 'T', $document->generatedAt));

        return $this->xmlDeclaration()
            . '<cp:coreProperties'
            . ' xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
            . ' xmlns:dcterms="http://purl.org/dc/terms/"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . $this->e($document->title . ' — ' . $document->subtitle) . '</dc:title>'
            . '<dc:creator>DCInfraSnap</dc:creator>'
            . '<cp:lastModifiedBy>DCInfraSnap</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $this->e($stamp) . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $this->e($stamp) . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private function appProperties(): string
    {
        return $this->xmlDeclaration()
            . '<Properties'
            . ' xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
            . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>DCInfraSnap</Application>'
            . '</Properties>';
    }

    /**
     * Ask Word to update the fields when the document opens.
     *
     * Without this the table of contents shows its placeholder text until
     * somebody knows to press F9, which nobody does.
     */
    private function settings(): string
    {
        return $this->xmlDeclaration()
            . '<w:settings xmlns:w="' . $this->wordNamespace() . '">'
            . '<w:zoom w:percent="100"/>'
            . '<w:defaultTabStop w:val="708"/>'
            . '<w:updateFields w:val="true"/>'
            . '<w:compat/>'
            . '</w:settings>';
    }

    // =================================================================
    // Styles
    // =================================================================

    private function styles(Document $document): string
    {
        $language = $this->language($document->locale);

        $heading = static fn (string $id, string $name, int $halfPoints, int $outline, string $colour, string $extra = ''): string =>
            '<w:style w:type="paragraph" w:styleId="' . $id . '">'
            . '<w:name w:val="' . $name . '"/>'
            . '<w:basedOn w:val="Normal"/>'
            . '<w:next w:val="Normal"/>'
            . '<w:pPr>'
            . '<w:keepNext/>'
            . '<w:spacing w:before="280" w:after="120"/>'
            . '<w:outlineLvl w:val="' . $outline . '"/>'
            . $extra
            . '</w:pPr>'
            . '<w:rPr><w:b/><w:color w:val="' . $colour . '"/><w:sz w:val="' . $halfPoints . '"/></w:rPr>'
            . '</w:style>';

        return $this->xmlDeclaration()
            . '<w:styles xmlns:w="' . $this->wordNamespace() . '">'

            // The document's own defaults: one font, one size, one language,
            // so nothing has to be set run by run.
            . '<w:docDefaults><w:rPrDefault><w:rPr>'
            . '<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/>'
            . '<w:sz w:val="20"/><w:szCs w:val="20"/>'
            . '<w:lang w:val="' . $language . '"/>'
            . '</w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="252" w:lineRule="auto"/></w:pPr></w:pPrDefault>'
            . '</w:docDefaults>'

            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal">'
            . '<w:name w:val="Normal"/></w:style>'

            . '<w:style w:type="paragraph" w:styleId="Title">'
            . '<w:name w:val="Title"/><w:basedOn w:val="Normal"/>'
            . '<w:pPr><w:spacing w:before="1200" w:after="120"/></w:pPr>'
            . '<w:rPr><w:b/><w:sz w:val="56"/></w:rPr></w:style>'

            . '<w:style w:type="paragraph" w:styleId="Subtitle">'
            . '<w:name w:val="Subtitle"/><w:basedOn w:val="Normal"/>'
            . '<w:pPr><w:spacing w:after="480"/></w:pPr>'
            . '<w:rPr><w:color w:val="5B6570"/><w:sz w:val="28"/></w:rPr></w:style>'

            // A chapter starts on a new page. The same rule as the HTML's
            // page-break-before, expressed where Word reads it.
            . $heading('Heading1', 'heading 1', 32, 0, '1A1D21', '<w:pageBreakBefore/>')
            . $heading('Heading2', 'heading 2', 25, 1, '1A1D21')
            . $heading('Heading3', 'heading 3', 21, 2, '5B6570')

            // Looks like Heading1 but carries no outline level, so the table
            // of contents does not list itself.
            . '<w:style w:type="paragraph" w:styleId="ContentsHeading">'
            . '<w:name w:val="Contents Heading"/><w:basedOn w:val="Normal"/>'
            . '<w:pPr><w:pageBreakBefore/><w:keepNext/><w:spacing w:after="240"/></w:pPr>'
            . '<w:rPr><w:b/><w:sz w:val="32"/></w:rPr></w:style>'

            . '<w:style w:type="paragraph" w:styleId="Caption">'
            . '<w:name w:val="Caption"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/>'
            . '<w:pPr><w:keepNext/><w:spacing w:before="200" w:after="80"/></w:pPr>'
            . '<w:rPr><w:b/></w:rPr></w:style>'

            . '<w:style w:type="paragraph" w:styleId="Note">'
            . '<w:name w:val="Note"/><w:basedOn w:val="Normal"/>'
            . '<w:rPr><w:color w:val="5B6570"/><w:sz w:val="16"/></w:rPr></w:style>'

            . '<w:style w:type="paragraph" w:styleId="Empty">'
            . '<w:name w:val="Empty"/><w:basedOn w:val="Normal"/>'
            . '<w:rPr><w:i/><w:color w:val="5B6570"/></w:rPr></w:style>'

            // Table text is smaller than body text: a port table has eight
            // columns and some of them hold a WWPN.
            . '<w:style w:type="paragraph" w:styleId="TableCell">'
            . '<w:name w:val="Table Cell"/><w:basedOn w:val="Normal"/>'
            . '<w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr>'
            . '<w:rPr><w:sz w:val="16"/></w:rPr></w:style>'

            . '<w:style w:type="paragraph" w:styleId="TableHead">'
            . '<w:name w:val="Table Head"/><w:basedOn w:val="TableCell"/>'
            . '<w:rPr><w:b/><w:sz w:val="16"/></w:rPr></w:style>'

            . '<w:style w:type="table" w:default="1" w:styleId="TableNormal">'
            . '<w:name w:val="Normal Table"/>'
            . '<w:tblPr><w:tblCellMar>'
            . '<w:top w:w="0" w:type="dxa"/><w:left w:w="108" w:type="dxa"/>'
            . '<w:bottom w:w="0" w:type="dxa"/><w:right w:w="108" w:type="dxa"/>'
            . '</w:tblCellMar></w:tblPr></w:style>'

            . '<w:style w:type="table" w:styleId="ReportTable">'
            . '<w:name w:val="Report Table"/><w:basedOn w:val="TableNormal"/>'
            . '<w:tblPr><w:tblBorders>'
            . '<w:top w:val="single" w:sz="4" w:space="0" w:color="C9D1D9"/>'
            . '<w:left w:val="single" w:sz="4" w:space="0" w:color="C9D1D9"/>'
            . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="C9D1D9"/>'
            . '<w:right w:val="single" w:sz="4" w:space="0" w:color="C9D1D9"/>'
            . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="C9D1D9"/>'
            . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="C9D1D9"/>'
            . '</w:tblBorders>'
            . '<w:tblCellMar>'
            . '<w:top w:w="40" w:type="dxa"/><w:left w:w="80" w:type="dxa"/>'
            . '<w:bottom w:w="40" w:type="dxa"/><w:right w:w="80" w:type="dxa"/>'
            . '</w:tblCellMar></w:tblPr></w:style>'

            // The label-and-value blocks: a table with no borders, so the
            // values line up without looking like a table.
            . '<w:style w:type="table" w:styleId="FactsTable">'
            . '<w:name w:val="Facts Table"/><w:basedOn w:val="TableNormal"/>'
            . '<w:tblPr><w:tblBorders>'
            . '<w:top w:val="none" w:sz="0" w:space="0" w:color="auto"/>'
            . '<w:left w:val="none" w:sz="0" w:space="0" w:color="auto"/>'
            . '<w:bottom w:val="none" w:sz="0" w:space="0" w:color="auto"/>'
            . '<w:right w:val="none" w:sz="0" w:space="0" w:color="auto"/>'
            . '<w:insideH w:val="none" w:sz="0" w:space="0" w:color="auto"/>'
            . '<w:insideV w:val="none" w:sz="0" w:space="0" w:color="auto"/>'
            . '</w:tblBorders></w:tblPr></w:style>'

            . '<w:style w:type="paragraph" w:styleId="FactLabel">'
            . '<w:name w:val="Fact Label"/><w:basedOn w:val="Normal"/>'
            . '<w:pPr><w:spacing w:after="40" w:line="240" w:lineRule="auto"/></w:pPr>'
            . '<w:rPr><w:color w:val="5B6570"/></w:rPr></w:style>'

            . '<w:style w:type="paragraph" w:styleId="FactValue">'
            . '<w:name w:val="Fact Value"/><w:basedOn w:val="Normal"/>'
            . '<w:pPr><w:spacing w:after="40" w:line="240" w:lineRule="auto"/></w:pPr>'
            . '</w:style>'

            // Word writes the generated entries in these; defining them keeps
            // the indentation sensible rather than left to the defaults.
            . $this->tocStyle(1, 0)
            . $this->tocStyle(2, 220)
            . $this->tocStyle(3, 440)

            . '</w:styles>';
    }

    private function tocStyle(int $level, int $indent): string
    {
        return '<w:style w:type="paragraph" w:styleId="TOC' . $level . '">'
            . '<w:name w:val="toc ' . $level . '"/>'
            . '<w:basedOn w:val="Normal"/>'
            . '<w:pPr>'
            . '<w:tabs><w:tab w:val="right" w:leader="dot" w:pos="' . self::CONTENT_WIDTH . '"/></w:tabs>'
            . '<w:spacing w:after="0"/>'
            . '<w:ind w:left="' . $indent . '"/>'
            . '</w:pPr>'
            . ($level === 1 ? '<w:rPr><w:b/></w:rPr>' : '')
            . '</w:style>';
    }

    /**
     * The language tag Word tags the text with, for its spell checker.
     */
    private function language(string $locale): string
    {
        return match ($locale) {
            'de'    => 'de-DE',
            'sr'    => 'sr-Latn-RS',
            'ru'    => 'ru-RU',
            default => 'en-GB',
        };
    }

    // =================================================================
    // The footer
    // =================================================================

    private function footer(Document $document): string
    {
        return $this->xmlDeclaration()
            . '<w:ftr xmlns:w="' . $this->wordNamespace() . '">'
            . '<w:p><w:pPr><w:pStyle w:val="Note"/><w:jc w:val="center"/></w:pPr>'
            . $this->run($document->title . ' · ' . $document->generatedAt . ' · ')
            . $this->field('PAGE', '1')
            . $this->run(' / ')
            . $this->field('NUMPAGES', '1')
            . '</w:p>'
            . '</w:ftr>';
    }

    // =================================================================
    // The document
    // =================================================================

    private function document(Document $document): string
    {
        $body = $this->titlePage($document) . $this->contents($document);

        foreach ($document->chapters as $chapter) {
            $body .= $this->chapter($chapter);
        }

        return $this->xmlDeclaration()
            . '<w:document xmlns:w="' . $this->wordNamespace() . '"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<w:body>'
            . $body
            . $this->sectionProperties()
            . '</w:body>'
            . '</w:document>';
    }

    private function titlePage(Document $document): string
    {
        $xml = $this->paragraph($document->title, 'Title')
            . $this->paragraph($document->subtitle, 'Subtitle');

        if ($document->titleFacts !== []) {
            $xml .= $this->factsTable($document->titleFacts);
        }

        return $xml;
    }

    /**
     * The table of contents, as a field Word fills in.
     *
     *   TOC \o "1-3"   build it from outline levels 1 to 3
     *       \h         make the entries hyperlinks
     *       \z         hide the page numbers in web layout
     *       \u         use the outline levels of the paragraph styles
     *
     * dirty="true" marks the field as needing recalculation, which together
     * with updateFields in settings.xml is what gets it filled in on open.
     * The text between separate and end is what shows until then.
     */
    private function contents(Document $document): string
    {
        return $this->paragraph($document->contentsHeading, 'ContentsHeading')
            . '<w:p><w:pPr><w:pStyle w:val="Note"/></w:pPr>'
            . '<w:r><w:fldChar w:fldCharType="begin" w:dirty="true"/></w:r>'
            . '<w:r><w:instrText xml:space="preserve"> TOC \o "1-3" \h \z \u </w:instrText></w:r>'
            . '<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
            . $this->run($document->contentsHint)
            . '<w:r><w:fldChar w:fldCharType="end"/></w:r>'
            . '</w:p>';
    }

    private function chapter(Chapter $chapter): string
    {
        $xml = $this->paragraph($chapter->title(), 'Heading1');

        foreach ($chapter->sections as $section) {
            $xml .= $this->paragraph(
                $section->title(),
                $section->level === 2 ? 'Heading2' : 'Heading3'
            );

            foreach ($section->blocks as $block) {
                $xml .= $this->block($block);
            }
        }

        return $xml;
    }

    private function block(Block $block): string
    {
        return match ($block->kind) {
            Block::FACTS     => ($block->caption !== null ? $this->paragraph($block->caption, 'Caption') : '')
                                . $this->factsTable($block->facts),
            Block::TABLE     => $this->table($block),
            Block::PARAGRAPH => $this->paragraph((string) $block->text, 'Note'),
            Block::EMPTY     => $this->paragraph((string) $block->text, 'Empty'),
            default          => '',
        };
    }

    /**
     * @param list<array{0: string, 1: string}> $facts
     */
    private function factsTable(array $facts): string
    {
        if ($facts === []) {
            return '';
        }

        // A third for the label, the rest for the value.
        $label = (int) round(self::CONTENT_WIDTH * 0.28);
        $value = self::CONTENT_WIDTH - $label;

        $xml = '<w:tbl><w:tblPr>'
            . '<w:tblStyle w:val="FactsTable"/>'
            . '<w:tblW w:w="' . self::CONTENT_WIDTH . '" w:type="dxa"/>'
            . '<w:tblLayout w:type="fixed"/>'
            . '</w:tblPr>'
            . '<w:tblGrid><w:gridCol w:w="' . $label . '"/><w:gridCol w:w="' . $value . '"/></w:tblGrid>';

        foreach ($facts as [$name, $content]) {
            $xml .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>'
                . $this->cell($name, $label, 'FactLabel')
                . $this->cell($content, $value, 'FactValue')
                . '</w:tr>';
        }

        // A table must be followed by a paragraph: two tables with nothing
        // between them are merged into one by Word, and a table as the last
        // thing in the body is invalid.
        return $xml . '</w:tbl>' . $this->spacer();
    }

    private function table(Block $block): string
    {
        $xml = $block->caption !== null ? $this->paragraph($block->caption, 'Caption') : '';

        if ($block->rows === []) {
            return $xml;
        }

        $columns = count($block->headings);
        $widths  = $this->columnWidths($block->headings, $block->rows);

        // Fixed layout with widths worked out from the content, rather than
        // leaving it to Word's autofit. Autofit divides the page by what it
        // thinks looks balanced, which gives a column of MTU values the same
        // room as a column of WWPNs and wraps every address onto two lines.
        $xml .= '<w:tbl><w:tblPr>'
            . '<w:tblStyle w:val="ReportTable"/>'
            . '<w:tblW w:w="' . self::CONTENT_WIDTH . '" w:type="dxa"/>'
            . '<w:tblLayout w:type="fixed"/>'
            . '<w:tblLook w:val="04A0" w:firstRow="1" w:lastRow="0" w:firstColumn="0"'
            . ' w:lastColumn="0" w:noHBand="0" w:noVBand="1"/>'
            . '</w:tblPr><w:tblGrid>';

        foreach ($widths as $width) {
            $xml .= '<w:gridCol w:w="' . $width . '"/>';
        }

        $xml .= '</w:tblGrid>';

        // tblHeader is what makes the header row repeat on every page. The
        // HTML does the same thing with a real thead.
        $xml .= '<w:tr><w:trPr><w:tblHeader/><w:cantSplit/></w:trPr>';

        foreach ($block->headings as $index => $heading) {
            $xml .= $this->cell($heading, $widths[$index], 'TableHead', 'F1F4F7');
        }

        $xml .= '</w:tr>';

        foreach ($block->rows as $row) {
            $xml .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>';

            // A row shorter than the header would leave a ragged table; the
            // builder does not produce those, and padding the row is cheaper
            // than trusting that forever.
            for ($index = 0; $index < $columns; $index++) {
                $xml .= $this->cell((string) ($row[$index] ?? ''), $widths[$index], 'TableCell');
            }

            $xml .= '</w:tr>';
        }

        $xml .= '</w:tbl>' . $this->spacer();

        if ($block->note !== null) {
            $xml .= $this->paragraph($block->note, 'Note');
        }

        return $xml;
    }

    /**
     * How wide each column should be, from what is actually in it.
     *
     * Worked out in twips rather than in characters, because the cell
     * padding is a fixed amount and dividing the page by character counts
     * quietly spends it twice. A share that looks generous as a proportion
     * can still leave a six-letter heading wrapping onto two lines.
     *
     * Each column asks for the width of its longest line, capped so one
     * column of notes cannot starve the rest, and promises never to go below
     * the width of the longest word in its heading — a wrapped heading is
     * the one thing that makes a table look broken.
     *
     * When the asks do not fit, the excess comes off the columns that have
     * room above their minimum, in proportion to how much room they have.
     *
     * @param list<string>       $headings
     * @param list<list<string>> $rows
     *
     * @return list<int>
     */
    private function columnWidths(array $headings, array $rows): array
    {
        // Calibri at 8pt: a shade under 95 twips an average character, and
        // 160 of cell margin plus a little slack for the borders. The cap is
        // a shade under a WWPN, which is the longest value that has to fit on
        // one line; anything longer — an iqn, a symbolic name — wraps, and
        // asking for its full length would take that one line away from the
        // addresses.
        $perCharacter = 95;
        $padding      = 200;
        $cap          = 24;

        $columns = count($headings);
        $desired = [];
        $minimum = [];

        for ($index = 0; $index < $columns; $index++) {
            $heading = $headings[$index];
            $longest = mb_strlen($heading);

            foreach ($rows as $row) {
                // A multi-line cell is as wide as its widest line, not as
                // wide as all of them joined.
                foreach (explode("\n", (string) ($row[$index] ?? '')) as $line) {
                    $longest = max($longest, mb_strlen($line));
                }
            }

            // A heading wraps between words, so only its longest word has to
            // fit on one line.
            $headingWord = 0;

            foreach (preg_split('/\s+/', $heading) ?: [] as $word) {
                $headingWord = max($headingWord, mb_strlen($word));
            }

            $desired[$index] = min($longest, $cap) * $perCharacter + $padding;
            $minimum[$index] = min(
                $headingWord * $perCharacter + $padding,
                intdiv(self::CONTENT_WIDTH, 3)
            );
        }

        $total   = max(1, array_sum($desired));
        $widths  = [];

        if ($total <= self::CONTENT_WIDTH) {
            // Room to spare: spread it in proportion to what each asked for,
            // so the table still fills the text column.
            foreach ($desired as $index => $want) {
                $widths[$index] = (int) round($want * self::CONTENT_WIDTH / $total);
            }
        } else {
            $slack = 0;

            foreach ($desired as $index => $want) {
                $slack += max(0, $want - $minimum[$index]);
            }

            $excess = $total - self::CONTENT_WIDTH;

            foreach ($desired as $index => $want) {
                $widths[$index] = $slack > 0
                    ? $want - (int) round($excess * max(0, $want - $minimum[$index]) / $slack)
                    : (int) round($want * self::CONTENT_WIDTH / $total);

                $widths[$index] = max($widths[$index], $minimum[$index]);
            }
        }

        // The widths have to add up to the text width exactly: Word draws a
        // table that does not with a gap at the right margin, or running off
        // it. The difference goes on the widest column, which is the one
        // that can afford it either way.
        $difference = self::CONTENT_WIDTH - array_sum($widths);

        if ($difference !== 0) {
            $widest = array_keys($widths, max($widths), true)[0];

            $widths[$widest] += $difference;
        }

        return array_values($widths);
    }

    // =================================================================
    // Pieces
    // =================================================================

    private function cell(string $text, int $width, string $style, ?string $shade = null): string
    {
        return '<w:tc><w:tcPr>'
            . '<w:tcW w:w="' . $width . '" w:type="dxa"/>'
            . ($shade !== null ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $shade . '"/>' : '')
            . '</w:tcPr>'
            . $this->paragraph($text, $style)
            . '</w:tc>';
    }

    private function paragraph(string $text, string $style): string
    {
        return '<w:p><w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr>'
            . $this->run($text)
            . '</w:p>';
    }

    /**
     * One or more runs for a value, with the newlines the builder put in it
     * turned into line breaks.
     *
     * xml:space="preserve" keeps the leading and trailing spaces Word would
     * otherwise collapse — which matters for the footer, where the text ends
     * in a space before a field.
     */
    private function run(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $lines = explode("\n", $text);
        $runs  = [];

        foreach ($lines as $line) {
            $runs[] = '<w:t xml:space="preserve">' . $this->e($line) . '</w:t>';
        }

        return '<w:r>' . implode('<w:br/>', $runs) . '</w:r>';
    }

    /**
     * A field: Word computes the value, and the fallback shows until it does.
     */
    private function field(string $instruction, string $fallback): string
    {
        return '<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
            . '<w:r><w:instrText xml:space="preserve"> ' . $this->e($instruction) . ' </w:instrText></w:r>'
            . '<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
            . $this->run($fallback)
            . '<w:r><w:fldChar w:fldCharType="end"/></w:r>';
    }

    /** An empty paragraph, small, to separate two tables. */
    private function spacer(): string
    {
        return '<w:p><w:pPr><w:spacing w:after="0" w:line="120" w:lineRule="exact"/></w:pPr></w:p>';
    }

    private function sectionProperties(): string
    {
        return '<w:sectPr>'
            . '<w:footerReference w:type="default" r:id="' . self::RID_FOOTER . '"/>'
            . '<w:pgSz w:w="' . self::PAGE_WIDTH . '" w:h="' . self::PAGE_HEIGHT . '"/>'
            . '<w:pgMar w:top="' . self::MARGIN . '" w:right="' . self::MARGIN . '"'
            . ' w:bottom="' . self::MARGIN . '" w:left="' . self::MARGIN . '"'
            . ' w:header="567" w:footer="567" w:gutter="0"/>'
            . '<w:cols w:space="708"/>'
            . '<w:docGrid w:linePitch="360"/>'
            . '</w:sectPr>';
    }

    private function xmlDeclaration(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    }

    private function wordNamespace(): string
    {
        return 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    }

    /**
     * Escape a value for XML, and drop anything XML cannot hold.
     *
     * The control characters matter. What a Fibre Channel device registers
     * about itself is stored exactly as sent, and a stray byte in a symbolic
     * name would make the whole package unopenable — Word reports a corrupt
     * file and says nothing about which character did it.
     */
    private function e(string $value): string
    {
        if (function_exists('mb_scrub')) {
            $value = mb_scrub($value, 'UTF-8');
        }

        // The C0 range except tab, newline and carriage return.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? $value;

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
