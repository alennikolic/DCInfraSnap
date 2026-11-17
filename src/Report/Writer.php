<?php
// src/Report/Writer.php
//
// What a file format has to be able to do.
//
// The whole point of the Document model is that this interface is small: a
// writer receives a finished document in which every value is already a
// display string, and turns it into bytes. It does no formatting, no
// translating and no database reading, which is why adding a format never
// touches the report's content.

declare(strict_types=1);

namespace DcInfraSnap\Report;

interface Writer
{
    /** The whole file, as bytes. */
    public function write(Document $document): string;

    /** Without the dot: html, docx. */
    public function extension(): string;

    /** For the Content-Type header. */
    public function contentType(): string;

    /**
     * Whether the browser should download the file rather than show it.
     *
     * HTML is shown, because the browser is also where it gets printed to
     * PDF. A Word document can only be downloaded.
     */
    public function isDownload(): bool;
}
