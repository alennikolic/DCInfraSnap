-- db/migrations/0004_projects.sql
--
-- Projects: a named grouping of sources.
--
-- One DCInfraSnap instance holds the sources of several unrelated
-- environments — a customer, a site, a system. A project is what says which
-- sources belong together, so a report can be generated for one of them
-- without anyone picking the right switches out of a list by hand.
--
-- MANY TO MANY, ON PURPOSE. The same source can appear in several projects:
-- one SAN fabric regularly serves two systems that are documented
-- separately, and a vCenter that hosts two customers' machines is no
-- different. A project_id column on integration would force a choice that
-- the real world does not make, and changing it later would be a migration
-- over a populated table.
--
-- A SOURCE WITHOUT A PROJECT IS A NORMAL STATE. Nothing here is required:
-- every source that exists today is unassigned after this migration and
-- keeps working exactly as before. A report can also be generated from a
-- hand-picked set of sources, so a project is a convenience and never a
-- precondition.
--
-- A PROJECT OWNS NO DATA. It holds no inventory, no runs and no payloads —
-- only the list of sources it refers to. Deleting a project therefore
-- deletes the grouping and nothing else, which is why the link table is the
-- only thing that has to go with it. That is the opposite of deleting a
-- source, which does take everything collected through it.
--
-- Nothing is required to exist: this migration only adds tables, so a report
-- can be built on top of it without any existing page changing behaviour.

CREATE TABLE project (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,

    name        VARCHAR(100)      NOT NULL COMMENT 'Label shown in the GUI and on the report',

    -- Both optional. The customer or organisation the environment belongs
    -- to, and a free line about what the project covers. They exist because
    -- a report title page reads poorly without them, not because anything
    -- depends on them.
    client      VARCHAR(150)      DEFAULT NULL COMMENT 'Customer or organisation',
    description VARCHAR(500)      DEFAULT NULL,

    created_at  DATETIME          NOT NULL COMMENT 'UTC',
    updated_at  DATETIME          NOT NULL COMMENT 'UTC',

    PRIMARY KEY (id),

    -- Two projects with one name cannot be told apart in a list, and the
    -- name is what ends up on the report.
    UNIQUE KEY uq_project_name (name)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Projects: a named grouping of sources';


-- Which sources a project covers.
--
-- The pair is the primary key, so the same source cannot be added to one
-- project twice. The second index is for the other direction — "which
-- projects is this source in", which the Integrations page asks for every
-- row.
--
-- RESTRICT on both sides, like everywhere else in this schema: deleting a
-- source or a project is an explicitly ordered transaction in the
-- repository, not something the database does quietly. ON UPDATE CASCADE is
-- safe here because neither column is a base column of a generated column —
-- that restriction applies to discovery_run.integration_id, which feeds
-- active_lock.
CREATE TABLE project_integration (
    project_id     SMALLINT UNSIGNED NOT NULL,
    integration_id SMALLINT UNSIGNED NOT NULL,

    added_at       DATETIME          NOT NULL COMMENT 'UTC',

    PRIMARY KEY (project_id, integration_id),
    KEY idx_project_integration_source (integration_id),

    CONSTRAINT fk_project_integration_project
        FOREIGN KEY (project_id) REFERENCES project (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_project_integration_source
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Which sources belong to which project';
