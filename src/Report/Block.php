<?php
// src/Report/Block.php
//
// One piece of content inside a section.
//
// EVERY VALUE IN HERE IS ALREADY A DISPLAY STRING. The builder formats
// numbers, byte counts and timestamps before they get this far, and nothing
// in here is HTML. That is what lets a second writer — Word, next — walk the
// same document without reimplementing any of the formatting or stripping
// markup back out.
//
// Four kinds is enough for an inventory report: a list of label and value
// pairs, a table, a paragraph, and a line saying something is empty. A kind
// column rather than four subclasses, because every writer has to switch on
// the kind anyway.

declare(strict_types=1);

namespace DcInfraSnap\Report;

final class Block
{
    public const FACTS     = 'facts';
    public const TABLE     = 'table';
    public const PARAGRAPH = 'paragraph';
    public const EMPTY     = 'empty';

    /**
     * @param list<array{0: string, 1: string}> $facts   label and value pairs
     * @param list<string>                      $headings
     * @param list<list<string>>                $rows
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?string $caption = null,
        public readonly ?string $text = null,
        public readonly array $facts = [],
        public readonly array $headings = [],
        public readonly array $rows = [],
        public readonly ?string $note = null,
    ) {
    }

    /**
     * A list of label and value pairs: the identity of one switch, the
     * totals for one vCenter.
     *
     * Pairs whose value is null are dropped rather than printed as a dash. A
     * report of a switch that reports no OEM serial number reads better
     * without the line than with an empty one.
     *
     * @param array<string, string|null> $facts
     */
    public static function facts(array $facts, ?string $caption = null): self
    {
        $pairs = [];

        foreach ($facts as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $pairs[] = [$label, $value];
        }

        return new self(self::FACTS, caption: $caption, facts: $pairs);
    }

    /**
     * @param list<string>       $headings
     * @param list<list<string>> $rows
     */
    public static function table(
        array $headings,
        array $rows,
        ?string $caption = null,
        ?string $note = null,
    ): self {
        return new self(
            self::TABLE,
            caption: $caption,
            headings: $headings,
            rows: $rows,
            note: $note,
        );
    }

    public static function paragraph(string $text): self
    {
        return new self(self::PARAGRAPH, text: $text);
    }

    /**
     * Said instead of a table when there is nothing to put in one.
     *
     * An empty section is left in the document on purpose: "no inter-switch
     * links" is a finding about the environment, and a report that silently
     * omits the heading leaves the reader wondering whether it was collected.
     */
    public static function nothing(string $text): self
    {
        return new self(self::EMPTY, text: $text);
    }

    public function isEmpty(): bool
    {
        return match ($this->kind) {
            self::TABLE => $this->rows === [],
            self::FACTS => $this->facts === [],
            default     => false,
        };
    }
}
