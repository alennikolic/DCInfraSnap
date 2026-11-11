<?php
// src/Report/Chapter.php
//
// One chapter: a number, a heading and the sections under it.

declare(strict_types=1);

namespace DcInfraSnap\Report;

final class Chapter
{
    /**
     * @param list<Section> $sections
     */
    public function __construct(
        public readonly int $number,
        public readonly string $heading,
        public readonly array $sections,
    ) {
    }

    public function anchor(): string
    {
        return 'ch' . $this->number;
    }

    public function title(): string
    {
        return $this->number . '. ' . $this->heading;
    }
}
