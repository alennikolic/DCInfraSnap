-- =======================================================================
-- 0006_hyperv_run_type.sql
--
-- Let a run report Hyper-V types.
--
-- discovery_run_type.source_type was ENUM('vcenter','brocade'), so the first
-- Hyper-V import failed with "Data truncated for column 'source_type'" —
-- MySQL silently has no room for a value the enum was never told about.
--
-- The column becomes VARCHAR(32), for the same reason raw_object.object_type
-- did in 0005: the set of source types lives in Integration::TYPES, and an
-- enum beside it is a second copy that has to be edited in lockstep. It is
-- not validation either — the only thing that ever writes this column is the
-- collector, from the type it is already holding.
--
-- type_key next to it has always been VARCHAR(32); this makes the pair agree.
ALTER TABLE discovery_run_type
    MODIFY COLUMN source_type VARCHAR(32) NOT NULL
           COMMENT 'Integration::TYPE_* the type belongs to';
