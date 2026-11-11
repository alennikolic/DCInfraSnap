-- db/migrations/0003_brocade.sql
--
-- Brocade SAN inventory, and a change to how a run records its per-type
-- outcome.
--
--
-- WHY THE RUN COUNTERS MOVE TO THEIR OWN TABLE
--
-- discovery_run carried three columns per object type: a status, a found
-- count and a changed count. Six vCenter types already meant eighteen
-- columns. The seven types below would add twenty-one more, and every one of
-- them would be NULL on a vCenter run while the vCenter eighteen sat NULL on
-- a switch run. Worse, the two source types have nothing in common, so no
-- single row shape fits both.
--
-- discovery_run_type holds one row per type per run instead. A row exists
-- once that type was attempted, so a type with no row was never reached —
-- which is what 'pending' used to mean. The existing columns are copied over
-- and then dropped, so the Runs page keeps showing the runs already there.
--
--
-- WHAT IS STORED FOR A SWITCH
--
-- Seven types, each keyed by something the switch itself reports, so the same
-- object is recognised on the next collection and the journal records what
-- changed rather than a delete and an insert:
--
--   fc_switch         the switch itself, one row       moref = switch WWN
--   fc_fabric_member  every switch in the fabric       moref = member WWN
--   fc_port           every physical port              moref = port index
--   fc_isl            links to other switches          moref = local port
--   fc_device         what is logged in (name server)  moref = device WWPN
--   fc_zone_object    zones, aliases, configurations   moref = section/kind/name
--   fc_zone_member    what each of those contains      moref = the above + member
--
-- A NOTE ON FABRIC NAME. There is no fc_fabric table. fabricshow reports no
-- fabric WWN, and on this hardware "fabricname --show" answers "Fabric Name
-- is not configured" — so a fabric has no identity of its own to key on. The
-- fabric is therefore described by its members plus the principal switch,
-- which is what every switch in it agrees on, and the optional configured
-- name is kept on fc_switch.
--
-- ZONING IS STORED TWICE OVER, ON PURPOSE. cfgshow reports a defined
-- configuration and an effective one, and they differ: a defined zone lists
-- alias names, while the effective zone lists the WWPNs those aliases
-- resolved to. Both are worth keeping — the first is what somebody wrote,
-- the second is what the switch is enforcing — so section ('defined' or
-- 'effective') is part of the key rather than a flag.
--
-- WWN FORMAT. Every WWN, WWPN and WWNN is stored exactly as FOS prints it:
-- lower case hex, colon separated, 23 characters. No conversion, so what is
-- in the database is what you would see on the switch.


-- =======================================================================
-- Per-run, per-type outcome
-- =======================================================================
CREATE TABLE discovery_run_type (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id        INT UNSIGNED NOT NULL,

    type_key      VARCHAR(32)  NOT NULL
                  COMMENT 'ObjectType key: vm, host, fc_port, …',
    source_type   ENUM('vcenter','brocade') NOT NULL,

    -- Collection order, so the Runs page can show the types in the order
    -- they were collected without knowing what they are.
    sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    status        ENUM('ok','failed') NOT NULL,
    found         INT UNSIGNED NOT NULL DEFAULT 0,
    changed       INT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT         DEFAULT NULL,

    recorded_at   DATETIME     NOT NULL COMMENT 'UTC',

    PRIMARY KEY (id),

    -- One row per type per run. The collector writes a type once, and a
    -- retry within the same run replaces it rather than adding to it.
    UNIQUE KEY uq_run_type (run_id, type_key),
    KEY idx_run_type_run (run_id, sort_order),

    CONSTRAINT fk_run_type_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Per-run outcome for one object type';


-- Copy the existing counters across. Only types that were actually
-- attempted: a 'pending' column meant the run never got that far, and in the
-- new shape that is the absence of a row.
INSERT INTO discovery_run_type
        (run_id, type_key, source_type, sort_order, status, found, changed, recorded_at)
SELECT id, 'cluster', 'vcenter', 1, clusters_status, clusters_found, clusters_changed,
       COALESCE(finished_at, started_at, queued_at)
  FROM discovery_run WHERE clusters_status <> 'pending';

INSERT INTO discovery_run_type
        (run_id, type_key, source_type, sort_order, status, found, changed, recorded_at)
SELECT id, 'host', 'vcenter', 2, hosts_status, hosts_found, hosts_changed,
       COALESCE(finished_at, started_at, queued_at)
  FROM discovery_run WHERE hosts_status <> 'pending';

INSERT INTO discovery_run_type
        (run_id, type_key, source_type, sort_order, status, found, changed, recorded_at)
SELECT id, 'vm', 'vcenter', 3, vms_status, vms_found, vms_changed,
       COALESCE(finished_at, started_at, queued_at)
  FROM discovery_run WHERE vms_status <> 'pending';

INSERT INTO discovery_run_type
        (run_id, type_key, source_type, sort_order, status, found, changed, recorded_at)
SELECT id, 'datastore', 'vcenter', 4, datastores_status, datastores_found, datastores_changed,
       COALESCE(finished_at, started_at, queued_at)
  FROM discovery_run WHERE datastores_status <> 'pending';

INSERT INTO discovery_run_type
        (run_id, type_key, source_type, sort_order, status, found, changed, recorded_at)
SELECT id, 'host_device', 'vcenter', 5, host_devices_status, host_devices_found, host_devices_changed,
       COALESCE(finished_at, started_at, queued_at)
  FROM discovery_run WHERE host_devices_status <> 'pending';

INSERT INTO discovery_run_type
        (run_id, type_key, source_type, sort_order, status, found, changed, recorded_at)
SELECT id, 'license', 'vcenter', 6, licenses_status, licenses_found, licenses_changed,
       COALESCE(finished_at, started_at, queued_at)
  FROM discovery_run WHERE licenses_status <> 'pending';


-- The columns are now redundant. Dropping them is the point of the exercise:
-- left in place they would drift out of agreement with the child table, and
-- a later reader would not know which one to trust.
ALTER TABLE discovery_run
    DROP COLUMN clusters_status,
    DROP COLUMN hosts_status,
    DROP COLUMN vms_status,
    DROP COLUMN datastores_status,
    DROP COLUMN licenses_status,
    DROP COLUMN host_devices_status,
    DROP COLUMN clusters_found,
    DROP COLUMN hosts_found,
    DROP COLUMN vms_found,
    DROP COLUMN datastores_found,
    DROP COLUMN licenses_found,
    DROP COLUMN host_devices_found,
    DROP COLUMN clusters_changed,
    DROP COLUMN hosts_changed,
    DROP COLUMN vms_changed,
    DROP COLUMN datastores_changed,
    DROP COLUMN licenses_changed,
    DROP COLUMN host_devices_changed;


-- =======================================================================
-- The switch
-- =======================================================================
CREATE TABLE fc_switch (
    id                  INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id      SMALLINT UNSIGNED NOT NULL,

    moref               VARCHAR(160)      NOT NULL COMMENT 'The switch WWN',

    name                VARCHAR(128)      NOT NULL COMMENT 'switchName',
    switch_wwn          VARCHAR(32)       NOT NULL,
    domain_id           SMALLINT UNSIGNED DEFAULT NULL,
    switch_id           VARCHAR(16)       DEFAULT NULL COMMENT 'switchId, e.g. fffc0c',
    switch_type         VARCHAR(16)       DEFAULT NULL COMMENT 'switchType, e.g. 181.0',
    state               VARCHAR(32)       DEFAULT NULL COMMENT 'Online, Offline',
    switch_mode         VARCHAR(32)       DEFAULT NULL COMMENT 'Native, Access Gateway',
    switch_role         VARCHAR(32)       DEFAULT NULL COMMENT 'Principal, Subordinate',

    zoning_enabled      TINYINT(1)        DEFAULT NULL,
    effective_config    VARCHAR(128)      DEFAULT NULL
                        COMMENT 'The zone configuration the switch is enforcing',

    vf_id               SMALLINT          DEFAULT NULL COMMENT 'Virtual Fabric id, 128 = default',
    is_base_switch      TINYINT(1)        DEFAULT NULL,
    is_default_switch   TINYINT(1)        DEFAULT NULL,
    fc_router           TINYINT(1)        DEFAULT NULL,

    fos_version         VARCHAR(32)       DEFAULT NULL,
    kernel_version      VARCHAR(32)       DEFAULT NULL,
    bootprom            VARCHAR(32)       DEFAULT NULL,

    -- What the switch believes about its fabric. Kept here because a fabric
    -- has no row of its own; see the note at the top.
    fabric_name         VARCHAR(128)      DEFAULT NULL
                        COMMENT 'NULL when no fabric name is configured, which is usual',
    principal_wwn       VARCHAR(32)       DEFAULT NULL,
    fabric_member_count SMALLINT UNSIGNED DEFAULT NULL,

    -- From chassisshow. The factory values are Brocade's; the plain ones are
    -- the OEM's, and on rebadged hardware the two differ.
    serial_number       VARCHAR(64)       DEFAULT NULL COMMENT 'Factory Serial Num',
    oem_serial_number   VARCHAR(64)       DEFAULT NULL COMMENT 'Serial Num, as the OEM set it',
    part_number         VARCHAR(64)       DEFAULT NULL COMMENT 'Factory Part Num',
    oem_part_number     VARCHAR(64)       DEFAULT NULL,
    oem_id              VARCHAR(64)       DEFAULT NULL,

    port_count          SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Physical ports present',
    licensed_port_count SMALLINT UNSIGNED DEFAULT NULL
                        COMMENT 'Ports on Demand: how many are licensed',

    payload_hash        CHAR(64)          NOT NULL,

    first_seen          DATETIME          NOT NULL COMMENT 'UTC',
    last_seen           DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id        INT UNSIGNED      NOT NULL,
    last_run_id         INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_fc_switch (integration_id, moref),
    KEY idx_fc_switch_wwn (switch_wwn),
    KEY idx_fc_switch_last_seen (integration_id, last_seen),

    CONSTRAINT fk_fc_switch_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fc_switch_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fc_switch_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: FC switch, current state';


CREATE TABLE fc_switch_change (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    switch_id  INT UNSIGNED    NOT NULL,
    run_id     INT UNSIGNED    NOT NULL,
    changed_at DATETIME        NOT NULL COMMENT 'UTC',
    old_values JSON            NOT NULL COMMENT 'Only the fields that changed',
    new_values JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_fc_switch_change (switch_id, changed_at),
    KEY idx_fc_switch_change_run (run_id),

    CONSTRAINT fk_fc_switch_change_switch
        FOREIGN KEY (switch_id) REFERENCES fc_switch (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_fc_switch_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Change journal for FC switches';


-- =======================================================================
-- Fabric members
-- =======================================================================
CREATE TABLE fc_fabric_member (
    id             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id SMALLINT UNSIGNED NOT NULL,

    moref          VARCHAR(160)      NOT NULL COMMENT 'The member switch WWN',

    domain_id      SMALLINT UNSIGNED DEFAULT NULL,
    switch_id      VARCHAR(16)       DEFAULT NULL,
    switch_wwn     VARCHAR(32)       NOT NULL,
    switch_name    VARCHAR(128)      DEFAULT NULL,
    ip_address     VARCHAR(45)       DEFAULT NULL COMMENT 'Management address',
    fc_ip          VARCHAR(45)       DEFAULT NULL COMMENT 'Usually 0.0.0.0',
    is_principal   TINYINT(1)        DEFAULT NULL,

    -- Whether this member is the switch being collected. Without it, two
    -- switches in one fabric produce two sets of members and nothing says
    -- which row came from where.
    is_local       TINYINT(1)        NOT NULL DEFAULT 0,

    payload_hash   CHAR(64)          NOT NULL,

    first_seen     DATETIME          NOT NULL COMMENT 'UTC',
    last_seen      DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id   INT UNSIGNED      NOT NULL,
    last_run_id    INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_fc_member (integration_id, moref),
    KEY idx_fc_member_wwn (switch_wwn),
    KEY idx_fc_member_last_seen (integration_id, last_seen),

    CONSTRAINT fk_fc_member_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fc_member_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fc_member_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: switches in the fabric, as this switch sees them';


CREATE TABLE fc_fabric_member_change (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id  INT UNSIGNED    NOT NULL,
    run_id     INT UNSIGNED    NOT NULL,
    changed_at DATETIME        NOT NULL COMMENT 'UTC',
    old_values JSON            NOT NULL,
    new_values JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_fc_member_change (member_id, changed_at),
    KEY idx_fc_member_change_run (run_id),

    CONSTRAINT fk_fc_member_change_member
        FOREIGN KEY (member_id) REFERENCES fc_fabric_member (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_fc_member_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Change journal for fabric members';


-- =======================================================================
-- Ports
-- =======================================================================
CREATE TABLE fc_port (
    id                   INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id       SMALLINT UNSIGNED NOT NULL,

    moref                VARCHAR(160)      NOT NULL COMMENT 'port:<index>',

    port_index           SMALLINT UNSIGNED NOT NULL,
    slot                 SMALLINT UNSIGNED DEFAULT NULL
                         COMMENT 'Bladed chassis only; NULL on a fixed-port switch',
    port_wwn             VARCHAR(32)       DEFAULT NULL COMMENT 'The port''s own WWN',
    port_name            VARCHAR(128)      DEFAULT NULL COMMENT 'portname, as configured',

    -- A port nobody has named reports a generated name such as "port12".
    -- Worth knowing apart from a real name, and not worth guessing at twice.
    name_is_default      TINYINT(1)        NOT NULL DEFAULT 1,

    fcid                 VARCHAR(16)       DEFAULT NULL COMMENT 'Port ID, e.g. 0c0000',
    media                VARCHAR(16)       DEFAULT NULL COMMENT 'id, --, cu',
    speed_gb             SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Negotiated speed',
    speed_mode           VARCHAR(8)        DEFAULT NULL COMMENT 'auto or fixed',
    state                VARCHAR(32)       DEFAULT NULL COMMENT 'Online, No_Light, No_Module…',
    protocol             VARCHAR(16)       DEFAULT NULL COMMENT 'FC, FCoE',
    port_type            VARCHAR(16)       DEFAULT NULL
                         COMMENT 'F-Port, E-Port, G-Port…; NULL when the port is down',

    -- What is on the other end. Exactly one of these is set on a live port:
    -- a device WWPN on an F-Port, a switch WWN on an E-Port.
    attached_wwpn        VARCHAR(32)       DEFAULT NULL COMMENT 'F-Port: the device',
    npiv_count           SMALLINT UNSIGNED DEFAULT NULL
                         COMMENT 'F-Port with NPIV: how many logins share it',
    neighbor_switch_wwn  VARCHAR(32)       DEFAULT NULL COMMENT 'E-Port: the other switch',
    neighbor_switch_name VARCHAR(128)      DEFAULT NULL,

    trunk_master_port    SMALLINT UNSIGNED DEFAULT NULL
                         COMMENT 'Set on a trunk member, pointing at its master',
    is_trunk_master      TINYINT(1)        NOT NULL DEFAULT 0,

    -- Ports on Demand. An unlicensed port physically exists and can never
    -- carry traffic, which is not the same as a port that is merely down.
    is_licensed          TINYINT(1)        NOT NULL DEFAULT 1,

    payload_hash         CHAR(64)          NOT NULL,

    first_seen           DATETIME          NOT NULL COMMENT 'UTC',
    last_seen            DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id         INT UNSIGNED      NOT NULL,
    last_run_id          INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_fc_port (integration_id, moref),
    KEY idx_fc_port_index (integration_id, port_index),
    KEY idx_fc_port_type (integration_id, port_type),
    KEY idx_fc_port_attached (attached_wwpn),
    KEY idx_fc_port_last_seen (integration_id, last_seen),

    CONSTRAINT fk_fc_port_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fc_port_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fc_port_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: FC ports, current state';


CREATE TABLE fc_port_change (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    port_id    INT UNSIGNED    NOT NULL,
    run_id     INT UNSIGNED    NOT NULL,
    changed_at DATETIME        NOT NULL COMMENT 'UTC',
    old_values JSON            NOT NULL,
    new_values JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_fc_port_change (port_id, changed_at),
    KEY idx_fc_port_change_run (run_id),

    CONSTRAINT fk_fc_port_change_port
        FOREIGN KEY (port_id) REFERENCES fc_port (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_fc_port_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Change journal for FC ports';


-- =======================================================================
-- Inter-switch links
-- =======================================================================
CREATE TABLE fc_isl (
    id                  INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id      SMALLINT UNSIGNED NOT NULL,

    moref               VARCHAR(160)      NOT NULL COMMENT 'isl:<local port>',

    -- islshow numbers its rows from 1 and renumbers them when a link goes
    -- away, so this is kept for display and excluded from the comparison.
    isl_index           SMALLINT UNSIGNED DEFAULT NULL,

    local_port          SMALLINT UNSIGNED NOT NULL,
    remote_port         SMALLINT UNSIGNED DEFAULT NULL,
    remote_switch_wwn   VARCHAR(32)       DEFAULT NULL,
    remote_domain_id    SMALLINT UNSIGNED DEFAULT NULL,
    remote_switch_name  VARCHAR(128)      DEFAULT NULL,

    speed_gb            SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Speed of this link',
    bandwidth_gb        SMALLINT UNSIGNED DEFAULT NULL
                        COMMENT 'Total across the trunk, when trunked',

    flags               VARCHAR(128)      DEFAULT NULL COMMENT 'TRUNK QOS CR_RECOV FEC',
    is_trunk            TINYINT(1)        NOT NULL DEFAULT 0,
    trunk_group         SMALLINT UNSIGNED DEFAULT NULL,
    member_count        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    member_ports        VARCHAR(255)      DEFAULT NULL
                        COMMENT 'Comma separated port indexes making up the trunk',

    payload_hash        CHAR(64)          NOT NULL,

    first_seen          DATETIME          NOT NULL COMMENT 'UTC',
    last_seen           DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id        INT UNSIGNED      NOT NULL,
    last_run_id         INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_fc_isl (integration_id, moref),
    KEY idx_fc_isl_remote (remote_switch_wwn),
    KEY idx_fc_isl_last_seen (integration_id, last_seen),

    CONSTRAINT fk_fc_isl_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fc_isl_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fc_isl_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: inter-switch links, current state';


CREATE TABLE fc_isl_change (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    isl_id     INT UNSIGNED    NOT NULL,
    run_id     INT UNSIGNED    NOT NULL,
    changed_at DATETIME        NOT NULL COMMENT 'UTC',
    old_values JSON            NOT NULL,
    new_values JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_fc_isl_change (isl_id, changed_at),
    KEY idx_fc_isl_change_run (run_id),

    CONSTRAINT fk_fc_isl_change_isl
        FOREIGN KEY (isl_id) REFERENCES fc_isl (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_fc_isl_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Change journal for inter-switch links';


-- =======================================================================
-- Devices logged in to the fabric (name server)
-- =======================================================================
CREATE TABLE fc_device (
    id               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id   SMALLINT UNSIGNED NOT NULL,

    moref            VARCHAR(160)      NOT NULL COMMENT 'The device WWPN',

    entry_type       VARCHAR(8)        DEFAULT NULL COMMENT 'N = node, NL = loop',
    fcid             VARCHAR(16)       DEFAULT NULL COMMENT 'Port ID the device holds',
    port_index       SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Switch port it is on',

    wwpn             VARCHAR(32)       NOT NULL COMMENT 'Port WWN: identifies the HBA port',
    wwnn             VARCHAR(32)       DEFAULT NULL COMMENT 'Node WWN: identifies the HBA or array',
    permanent_wwpn   VARCHAR(32)       DEFAULT NULL
                     COMMENT 'Differs from wwpn on an NPIV login',
    fabric_port_wwn  VARCHAR(32)       DEFAULT NULL COMMENT 'WWN of the switch port it is attached to',

    -- HBA identification. FOS reports whatever the device registered, and
    -- what that contains depends entirely on the vendor: a QLogic HBA sends
    -- model, firmware and driver as one string, while an array sends its own
    -- format. Stored verbatim, because parsing it per vendor would be
    -- guesswork that silently rots.
    node_symb        VARCHAR(255)      DEFAULT NULL COMMENT 'Node symbolic name, as registered',
    port_symb        VARCHAR(255)      DEFAULT NULL COMMENT 'Port symbolic name, as registered',

    device_type      VARCHAR(64)       DEFAULT NULL
                     COMMENT 'Physical Initiator, Physical Target, NPIV Initiator…',
    fc4_types        VARCHAR(128)      DEFAULT NULL COMMENT 'FCP, FC-NVMe',
    link_speed       VARCHAR(16)       DEFAULT NULL COMMENT 'As the name server reports it, e.g. 32G',
    cos              VARCHAR(16)       DEFAULT NULL COMMENT 'Classes of service',
    scr              VARCHAR(16)       DEFAULT NULL COMMENT 'State change registration',
    is_npiv          TINYINT(1)        NOT NULL DEFAULT 0,

    payload_hash     CHAR(64)          NOT NULL,

    first_seen       DATETIME          NOT NULL COMMENT 'UTC',
    last_seen        DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id     INT UNSIGNED      NOT NULL,
    last_run_id      INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_fc_device (integration_id, moref),

    -- The index that makes a device findable by WWPN across every switch,
    -- which is how a host's HBA is matched to what the fabric sees.
    KEY idx_fc_device_wwpn (wwpn),
    KEY idx_fc_device_wwnn (wwnn),
    KEY idx_fc_device_port (integration_id, port_index),
    KEY idx_fc_device_last_seen (integration_id, last_seen),

    CONSTRAINT fk_fc_device_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fc_device_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fc_device_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: devices logged in to the fabric, current state';


CREATE TABLE fc_device_change (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id  INT UNSIGNED    NOT NULL,
    run_id     INT UNSIGNED    NOT NULL,
    changed_at DATETIME        NOT NULL COMMENT 'UTC',
    old_values JSON            NOT NULL,
    new_values JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_fc_device_change (device_id, changed_at),
    KEY idx_fc_device_change_run (run_id),

    CONSTRAINT fk_fc_device_change_device
        FOREIGN KEY (device_id) REFERENCES fc_device (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_fc_device_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Change journal for fabric devices';


-- =======================================================================
-- Zoning: the objects
-- =======================================================================
CREATE TABLE fc_zone_object (
    id             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id SMALLINT UNSIGNED NOT NULL,

    moref          VARCHAR(255)      NOT NULL COMMENT '<section>:<kind>:<name>',

    section        VARCHAR(16)       NOT NULL
                   COMMENT 'defined = written, effective = being enforced',
    kind           VARCHAR(16)       NOT NULL COMMENT 'cfg, zone, alias',
    name           VARCHAR(128)      NOT NULL,
    member_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    payload_hash   CHAR(64)          NOT NULL,

    first_seen     DATETIME          NOT NULL COMMENT 'UTC',
    last_seen      DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id   INT UNSIGNED      NOT NULL,
    last_run_id    INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_fc_zone_object (integration_id, moref),
    KEY idx_fc_zone_object_kind (integration_id, section, kind),
    KEY idx_fc_zone_object_name (integration_id, name),
    KEY idx_fc_zone_object_last_seen (integration_id, last_seen),

    CONSTRAINT fk_fc_zone_object_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fc_zone_object_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fc_zone_object_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: zone configurations, zones and aliases';


CREATE TABLE fc_zone_object_change (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    object_id  INT UNSIGNED    NOT NULL,
    run_id     INT UNSIGNED    NOT NULL,
    changed_at DATETIME        NOT NULL COMMENT 'UTC',
    old_values JSON            NOT NULL,
    new_values JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_fc_zone_object_change (object_id, changed_at),
    KEY idx_fc_zone_object_change_run (run_id),

    CONSTRAINT fk_fc_zone_object_change_object
        FOREIGN KEY (object_id) REFERENCES fc_zone_object (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_fc_zone_object_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Change journal for zoning objects';


-- =======================================================================
-- Zoning: what each object contains
-- =======================================================================
--
-- A row per membership rather than a list on the object, so that "which
-- zones is this WWPN in" is an indexed lookup instead of a scan through
-- text. member_name is deliberately not resolved: in the defined section it
-- is usually an alias name, in the effective section a WWPN, and flattening
-- the two would lose the distinction that makes both sections worth keeping.
CREATE TABLE fc_zone_member (
    id             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id SMALLINT UNSIGNED NOT NULL,

    moref          VARCHAR(255)      NOT NULL
                   COMMENT '<section>:<kind>:<object>:<member>',

    section        VARCHAR(16)       NOT NULL COMMENT 'defined or effective',
    kind           VARCHAR(16)       NOT NULL COMMENT 'Kind of the containing object',
    object_name    VARCHAR(128)      NOT NULL COMMENT 'The zone, alias or cfg it belongs to',

    member_name    VARCHAR(128)      NOT NULL COMMENT 'As written: an alias name, a WWPN or a zone',
    member_kind    VARCHAR(16)       NOT NULL
                   COMMENT 'wwpn, alias, zone or domain_port — what member_name looks like',

    -- Position within the object. It shifts when anything before it is
    -- removed, which is not a change to this member, so it is stored and
    -- kept current but excluded from the comparison.
    sort_order     SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    payload_hash   CHAR(64)          NOT NULL,

    first_seen     DATETIME          NOT NULL COMMENT 'UTC',
    last_seen      DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id   INT UNSIGNED      NOT NULL,
    last_run_id    INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_fc_zone_member (integration_id, moref),
    KEY idx_fc_zone_member_object (integration_id, section, kind, object_name),

    -- The lookup that answers "what is this WWPN zoned to".
    KEY idx_fc_zone_member_name (member_name),
    KEY idx_fc_zone_member_last_seen (integration_id, last_seen),

    CONSTRAINT fk_fc_zone_member_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fc_zone_member_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fc_zone_member_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: zone and alias membership';


CREATE TABLE fc_zone_member_change (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id  INT UNSIGNED    NOT NULL,
    run_id     INT UNSIGNED    NOT NULL,
    changed_at DATETIME        NOT NULL COMMENT 'UTC',
    old_values JSON            NOT NULL,
    new_values JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_fc_zone_member_change (member_id, changed_at),
    KEY idx_fc_zone_member_change_run (run_id),

    CONSTRAINT fk_fc_zone_member_change_member
        FOREIGN KEY (member_id) REFERENCES fc_zone_member (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_fc_zone_member_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Change journal for zone membership';


-- =======================================================================
-- The raw layer keeps one row per object per run, keyed by type.
-- =======================================================================
--
-- moref widens from 64 to 255. A vCenter identifier is short ("vm-1234"),
-- but a zone member is identified by section, kind, object and member, which
-- runs to about 145 characters. At 64 the raw insert would fail outright on
-- the zone members with "Data too long for column 'moref'", and the widest
-- inventory moref (255) is the right size for the layer that mirrors them.
ALTER TABLE raw_object
    MODIFY COLUMN moref VARCHAR(255) NOT NULL
        COMMENT 'Identifier at the source, e.g. vm-1234 or defined:zone:Z_X:alias',
    MODIFY COLUMN object_type
        ENUM('cluster','host','vm','datastore','license','host_device',
             'fc_switch','fc_fabric_member','fc_port','fc_isl','fc_device',
             'fc_zone_object','fc_zone_member') NOT NULL;
