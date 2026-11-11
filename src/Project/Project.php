<?php
// src/Project/Project.php
//
// One project: a named grouping of sources.
//
// It carries no data of its own beyond its label — the sources it refers to
// are read separately, because most pages need the project without needing
// its membership list.

declare(strict_types=1);

namespace DcInfraSnap\Project;

final class Project
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $client,
        public readonly ?string $description,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id:          (int) $row['id'],
            name:        (string) $row['name'],
            client:      $row['client'] !== null ? (string) $row['client'] : null,
            description: $row['description'] !== null ? (string) $row['description'] : null,
            createdAt:   (string) $row['created_at'],
            updatedAt:   (string) $row['updated_at'],
        );
    }

    /**
     * The project and its customer on one line, for a heading.
     */
    public function label(): string
    {
        return $this->client === null || $this->client === ''
            ? $this->name
            : $this->name . ' — ' . $this->client;
    }
}
