<?php
// src/Report/Document.php
//
// A finished report, in no particular file format.
//
// This is the whole contract between gathering the data and writing a file:
// the builder produces one of these, and a writer turns it into HTML now and
// into WordprocessingML next, without either of them knowing about the other.
//
// The title page fields live here rather than in the writer because they are
// content — what the document says about itself — and every format shows them.

declare(strict_types=1);

namespace DcInfraSnap\Report;

final class Document
{
    /**
     * @param list<Chapter>                     $chapters
     * @param list<array{0: string, 1: string}> $titleFacts label and value pairs for the title page
     */
    public function __construct(
        public readonly string $title,
        public readonly string $subtitle,
        public readonly array $titleFacts,
        public readonly array $chapters,
        public readonly string $locale,

        // The heading over the table of contents. It travels with the
        // document because the builder has the translator and no writer does:
        // handing every writer a Translator just to translate one word would
        // put the language back into the file formats, which is exactly what
        // this split exists to avoid.
        public readonly string $contentsHeading,

        // The whole document describes one moment. Stored as it is printed,
        // so every writer shows the same string rather than each formatting
        // a timestamp its own way.
        public readonly string $generatedAt,
        public readonly string $footer,
    ) {
    }

    /**
     * A file name for this report, without an extension.
     *
     * Built from the title and the date, with anything a file system might
     * object to replaced. A report of "MP Data Centre" collected today
     * becomes mp-data-centre-2026-11-11.
     */
    public function filename(): string
    {
        // Transliteration is deliberately not attempted: iconv depends on the
        // locale being installed in the container, and a name that silently
        // becomes "??" is worse than one that drops the letter. What is left
        // is ASCII letters, digits and dashes.
        $slug = strtolower($this->title);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = 'report';
        }

        return $slug . '-' . substr($this->generatedAt, 0, 10);
    }
}
