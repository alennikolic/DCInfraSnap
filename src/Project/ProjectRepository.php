<?php
// src/Project/ProjectRepository.php
//
// Reading and writing projects, and which sources belong to them.
//
// NOTE WHAT THIS DOES NOT TOUCH: credentials. A project refers to sources by
// id and never needs a password, so this repository takes no Secret and
// cannot decrypt anything. The source rows it reads for the assignment page
// are selected column by column, with password_encrypted left out of the
// query rather than selected and then ignored — the database browser had
// that bug once and it is not worth repeating.

declare(strict_types=1);

namespace DcInfraSnap\Project;

use PDO;
use RuntimeException;
use Throwable;

// Not final so a test can substitute one.
class ProjectRepository
{
    private const COLUMNS = 'id, name, client, description, created_at, updated_at';

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    /**
     * @return list<Project>
     */
    public function all(): array
    {
        return array_map(
            static fn (array $row): Project => Project::fromRow($row),
            $this->pdo->query('SELECT ' . self::COLUMNS . ' FROM project ORDER BY name')->fetchAll()
        );
    }

    public function find(int $id): ?Project
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM project WHERE id = :id');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : Project::fromRow($row);
    }

    public function findByName(string $name): ?Project
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM project WHERE name = :name');
        $stmt->execute(['name' => $name]);

        $row = $stmt->fetch();

        return $row === false ? null : Project::fromRow($row);
    }

    /**
     * The order sources are listed in, everywhere a project shows them.
     *
     * vCenters first, then switches. Spelled out rather than left to ORDER BY
     * type, because alphabetically "brocade" comes before "vcenter" and that
     * is the wrong way round: the report's first chapter is the VMware
     * environment and its second is the fabric, so a list that disagrees with
     * the document it feeds is just confusing.
     */
    private const SOURCE_ORDER = "CASE i.type WHEN 'vcenter' THEN 1 WHEN 'brocade' THEN 2 ELSE 3 END, i.name";

    /**
     * The sources in one project.
     *
     * @return list<array<string, mixed>>
     */
    public function sources(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.id, i.type, i.name, i.hostname, i.port, i.is_active, i.product_version
               FROM project_integration pi
               JOIN integration i ON i.id = pi.integration_id
              WHERE pi.project_id = :id
              ORDER BY ' . self::SOURCE_ORDER
        );
        $stmt->execute(['id' => $projectId]);

        return $stmt->fetchAll();
    }

    /**
     * Just the ids, for ticking the boxes on the assignment form.
     *
     * @return list<int>
     */
    public function sourceIds(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT integration_id FROM project_integration WHERE project_id = :id'
        );
        $stmt->execute(['id' => $projectId]);

        return array_map(
            static fn (mixed $id): int => (int) $id,
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    /**
     * Every source, for the assignment form.
     *
     * Disabled sources are included: a source can be switched off and still
     * belong to a project, and what it collected last time is still what the
     * report describes.
     *
     * @return list<array<string, mixed>>
     */
    public function selectableSources(): array
    {
        return $this->pdo->query(
            'SELECT i.id, i.type, i.name, i.hostname, i.port, i.is_active
               FROM integration i
              ORDER BY ' . self::SOURCE_ORDER
        )->fetchAll();
    }

    /**
     * Source names per project, for the list page.
     *
     * One query for the whole page rather than one per project.
     *
     * @return array<int, list<string>>
     */
    public function sourceNamesByProject(): array
    {
        $rows = $this->pdo->query(
            'SELECT pi.project_id, i.name
               FROM project_integration pi
               JOIN integration i ON i.id = pi.integration_id
              ORDER BY i.name'
        )->fetchAll();

        $names = [];

        foreach ($rows as $row) {
            $names[(int) $row['project_id']][] = (string) $row['name'];
        }

        return $names;
    }

    /**
     * Project names per source, for the Integrations page.
     *
     * The other direction of the same table: this is what shows that a
     * source really is in more than one project.
     *
     * @return array<int, list<string>>
     */
    public function projectNamesBySource(): array
    {
        $rows = $this->pdo->query(
            'SELECT pi.integration_id, p.name
               FROM project_integration pi
               JOIN project p ON p.id = pi.project_id
              ORDER BY p.name'
        )->fetchAll();

        $names = [];

        foreach ($rows as $row) {
            $names[(int) $row['integration_id']][] = (string) $row['name'];
        }

        return $names;
    }

    // -----------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------

    /**
     * @return int the id of the new project
     */
    public function create(string $name, ?string $client, ?string $description): int
    {
        if ($this->findByName($name) !== null) {
            throw new RuntimeException("A project named \"{$name}\" already exists.");
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO project (name, client, description, created_at, updated_at)
             VALUES (:name, :client, :description, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );

        $stmt->execute([
            'name'        => $name,
            'client'      => $client,
            'description' => $description,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, ?string $client, ?string $description): void
    {
        $existing = $this->findByName($name);

        if ($existing !== null && $existing->id !== $id) {
            throw new RuntimeException("A project named \"{$name}\" already exists.");
        }

        $stmt = $this->pdo->prepare(
            'UPDATE project
                SET name = :name, client = :client, description = :description,
                    updated_at = UTC_TIMESTAMP()
              WHERE id = :id'
        );

        $stmt->execute([
            'id'          => $id,
            'name'        => $name,
            'client'      => $client,
            'description' => $description,
        ]);
    }

    /**
     * Replace the project's source list with exactly these sources.
     *
     * The whole set is written at once rather than one add and one remove at
     * a time, because that is how the form sends it: what is ticked is what
     * the project consists of afterwards.
     *
     * Ids that do not exist are dropped instead of inserted. A posted form
     * can name a source that was deleted in another tab, and a foreign key
     * failure there would throw away the rest of the selection for no good
     * reason.
     *
     * @param list<int> $integrationIds
     *
     * @return int how many sources the project ended up with
     */
    public function setSources(int $projectId, array $integrationIds): int
    {
        // Unique, and only sources that are really there.
        $wanted = array_values(array_unique(array_map('intval', $integrationIds)));
        $valid  = [];

        if ($wanted !== []) {
            $check = $this->pdo->prepare('SELECT id FROM integration WHERE id = :id');

            foreach ($wanted as $candidate) {
                $check->execute(['id' => $candidate]);

                if ($check->fetchColumn() !== false) {
                    $valid[] = $candidate;
                }
            }
        }

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM project_integration WHERE project_id = :id'
            );
            $stmt->execute(['id' => $projectId]);

            if ($valid !== []) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO project_integration (project_id, integration_id, added_at)
                     VALUES (:project, :integration, UTC_TIMESTAMP())'
                );

                foreach ($valid as $integrationId) {
                    $insert->execute([
                        'project'     => $projectId,
                        'integration' => $integrationId,
                    ]);
                }
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return count($valid);
    }

    // -----------------------------------------------------------------
    // Deleting
    // -----------------------------------------------------------------

    /**
     * Remove a project.
     *
     * The membership rows go first, because the foreign key is RESTRICT.
     * Nothing else goes: the sources and everything collected through them
     * are untouched, which is the whole point of a project being a grouping
     * rather than an owner.
     */
    public function delete(int $id): void
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM project_integration WHERE project_id = :id'
            );
            $stmt->execute(['id' => $id]);

            $stmt = $this->pdo->prepare('DELETE FROM project WHERE id = :id');
            $stmt->execute(['id' => $id]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }
}
