<?php
// src/Report/Section.php
//
// A numbered heading and the blocks under it.
//
// The levels are flat rather than nested: a chapter holds a list of sections,
// each carrying the level it is at. "1.2" is a level 2 section and "1.2.3" a
// level 3 one, and the table of contents simply reads the list and keeps the
// levels it wants.
//
// Nesting them as children would mean every writer walking a tree, and a
// table of contents picking its way through it, for a document that is only
// ever three levels deep.

declare(strict_types=1);

namespace DcInfraSnap\Report;

final class Section
{
    /**
     * @param list<Block> $blocks
     */
    public function __construct(
        public readonly int $level,
        public readonly string $number,
        public readonly string $heading,
        public readonly array $blocks,
    ) {
    }

    /**
     * The id a link in the table of contents points at.
     *
     * Built from the number, which is unique within the document and contains
     * nothing that needs escaping once the dots are replaced.
     */
    public function anchor(): string
    {
        return 's' . str_replace('.', '-', $this->number);
    }

    /** "1.2 Hosts", as it is printed. */
    public function title(): string
    {
        return $this->number . ' ' . $this->heading;
    }
}
