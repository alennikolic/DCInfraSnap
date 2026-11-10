-- db/migrations/0001_schema.sql
--
-- DCInfraSnap — complete initial schema.
--
-- Everything the application stores lives in this one file. Later changes
-- are added as new numbered files (0002_*.sql, ...); an applied migration is
-- never edited, because the migrator keeps a checksum of every file it has
-- run and refuses to continue when one of them changes underneath it.
--
-- Two layers of data:
--
--   raw layer        raw_object — the payload exactly as the source returned
--                    it, one row per object per run. When a field turns out
--                    to be useful six months from now, it is extracted from
--                    here instead of rescanning the infrastructure.
--
--   inventory layer  vc_* tables — normalized current state, each with a
--                    matching *_change journal.
--
-- Rows are never deleted. When an object disappears from vCenter its row
-- stays and last_seen simply stops moving; deciding that it is gone is a
-- later concern, and it needs to know whether the run was complete at all.
--
-- Times are UTC everywhere. Conversion to local time happens in the view.
--
-- ON DELETE RESTRICT, almost everywhere.
--
-- Nothing in here disappears because something else was deleted. A cascade
-- from integration would have to pass through discovery_run, which every
-- inventory row points at twice (first_run_id, last_run_id), and MySQL gives
-- no guarantee about the order it resolves several cascade paths in. Rather
-- than depend on that, removing a source is an ordered transaction in the
-- application:
--
--   *_change -> inventory -> raw_object -> discovery_run -> integration
--
-- The single exception is raw_object -> discovery_run, which cascades: raw
-- payloads belong to their run and are the layer a retention policy prunes.



-- =======================================================================
-- Sources
-- =======================================================================

-- One row per connected system. Today that is always a vCenter, but the
-- type column keeps the door open for other sources without a migration
-- over a populated table.
--
-- The password is stored encrypted (libsodium secretbox, see src/Core/Secret)
-- and is never read together with the rest of the row — there is a separate
-- call for it, so it cannot end up in a var_dump, an exception trace or a log.
CREATE TABLE integration (
    id                 SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,

    type               VARCHAR(32)       NOT NULL DEFAULT 'vcenter',
    name               VARCHAR(100)      NOT NULL COMMENT 'Label shown in the GUI',

    hostname           VARCHAR(255)      NOT NULL COMMENT 'FQDN or IP',
    port               SMALLINT UNSIGNED NOT NULL DEFAULT 443,

    username           VARCHAR(190)      NOT NULL COMMENT 'Read-only account',
    password_encrypted VARBINARY(1024)   NOT NULL COMMENT 'libsodium secretbox',

    -- Lab vCenters almost always carry a self-signed certificate. Off by
    -- default; turn it on once a trusted certificate is in place.
    verify_peer        TINYINT(1)        NOT NULL DEFAULT 0,

    -- Filled in by the connection test: "8.0.3" and the like. Useful when a
    -- field is missing and the question is whether the version supports it.
    product_version    VARCHAR(64)       DEFAULT NULL,

    is_active          TINYINT(1)        NOT NULL DEFAULT 1,

    last_tested_at     DATETIME          DEFAULT NULL COMMENT 'UTC',
    last_test_ok       TINYINT(1)        DEFAULT NULL,
    last_test_message  VARCHAR(255)      DEFAULT NULL,

    created_at         DATETIME          NOT NULL COMMENT 'UTC',
    updated_at         DATETIME          NOT NULL COMMENT 'UTC',

    PRIMARY KEY (id),

    -- The same endpoint cannot be registered twice.
    UNIQUE KEY uq_integration_endpoint (type, hostname, port),
    KEY idx_integration_active (is_active)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Connected sources and their encrypted credentials';


-- =======================================================================
-- Runs
-- =======================================================================

-- Every collection attempt is one row, successful or not.
--
-- This table is what gives last_seen its meaning: without knowing whether a
-- run was complete, "last seen" says nothing. That is also why the status is
-- tracked per object type — if VMs went through and hosts failed, a partial
-- run is still usable for VMs.
--
-- Locking: the generated column active_lock holds integration_id only while
-- a run is queued or running, and carries a UNIQUE index. The database
-- itself therefore prevents two concurrent runs against one source. NULLs do
-- not collide in MySQL, so finished runs stay out of the way.
CREATE TABLE discovery_run (
    id                   INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id       SMALLINT UNSIGNED NOT NULL,

    status               ENUM('queued','running','success','partial','failed')
                         NOT NULL DEFAULT 'queued',
    trigger_source       ENUM('gui','cli','schedule') NOT NULL DEFAULT 'cli',

    queued_at            DATETIME          NOT NULL COMMENT 'UTC',
    started_at           DATETIME          DEFAULT NULL COMMENT 'UTC',
    finished_at          DATETIME          DEFAULT NULL COMMENT 'UTC',

    -- Refreshed by the worker while it works. A run without a sign of life
    -- for long enough is declared dead, which releases the lock above.
    heartbeat_at         DATETIME          DEFAULT NULL COMMENT 'UTC',

    -- Per-type outcome. The column names are derived from the type names in
    -- the collector, so a new object type needs three columns here.
    clusters_status      ENUM('pending','ok','failed') NOT NULL DEFAULT 'pending',
    hosts_status         ENUM('pending','ok','failed') NOT NULL DEFAULT 'pending',
    vms_status           ENUM('pending','ok','failed') NOT NULL DEFAULT 'pending',
    datastores_status    ENUM('pending','ok','failed') NOT NULL DEFAULT 'pending',
    licenses_status      ENUM('pending','ok','failed') NOT NULL DEFAULT 'pending',

    clusters_found       INT UNSIGNED      NOT NULL DEFAULT 0,
    hosts_found          INT UNSIGNED      NOT NULL DEFAULT 0,
    vms_found            INT UNSIGNED      NOT NULL DEFAULT 0,
    datastores_found     INT UNSIGNED      NOT NULL DEFAULT 0,
    licenses_found       INT UNSIGNED      NOT NULL DEFAULT 0,

    clusters_changed     INT UNSIGNED      NOT NULL DEFAULT 0,
    hosts_changed        INT UNSIGNED      NOT NULL DEFAULT 0,
    vms_changed          INT UNSIGNED      NOT NULL DEFAULT 0,
    datastores_changed   INT UNSIGNED      NOT NULL DEFAULT 0,
    licenses_changed     INT UNSIGNED      NOT NULL DEFAULT 0,

    api_calls            INT UNSIGNED      NOT NULL DEFAULT 0
                         COMMENT 'Requests made, to gauge load on the source',

    error_message        TEXT              DEFAULT NULL,

    -- Holds integration_id only while the run is active; NULL otherwise.
    active_lock          SMALLINT UNSIGNED
                         GENERATED ALWAYS AS (
                             CASE WHEN status IN ('queued','running')
                                  THEN integration_id END
                         ) STORED,

    PRIMARY KEY (id),

    UNIQUE KEY uq_run_active (active_lock),
    KEY idx_run_integration (integration_id, queued_at),
    KEY idx_run_status (status),

    CONSTRAINT fk_run_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Collection runs: queue, outcome and counters';


-- =======================================================================
-- Raw layer
-- =======================================================================

-- payload is deliberately MEDIUMTEXT and not JSON: the point is to keep
-- exactly what the source returned, including a response MySQL's JSON
-- validator would reject. Validating is the mapper's job, not storage's.
--
-- Growth: 2000 VMs with a daily run is roughly 730,000 rows a year. A
-- retention policy deletes by run_id and gets introduced once there is
-- anything worth deleting.
CREATE TABLE raw_object (
    id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,

    run_id         INT UNSIGNED      NOT NULL,
    integration_id SMALLINT UNSIGNED NOT NULL
                   COMMENT 'Denormalized from discovery_run, for faster filtering',

    object_type    ENUM('cluster','host','vm','datastore','license') NOT NULL,
    moref          VARCHAR(64)       NOT NULL
                   COMMENT 'Identifier at the source, e.g. vm-1234',

    payload        MEDIUMTEXT        NOT NULL COMMENT 'Raw response',
    payload_hash   CHAR(64)          NOT NULL COMMENT 'SHA-256 of the normalized payload',

    collected_at   DATETIME          NOT NULL COMMENT 'UTC',

    PRIMARY KEY (id),

    -- One object is recorded exactly once per run. This also guards against
    -- duplicates when a listing is partitioned and an object shows up twice.
    UNIQUE KEY uq_raw_run_object (run_id, object_type, moref),

    -- The history of a single object across runs.
    KEY idx_raw_history (integration_id, object_type, moref, collected_at),

    CONSTRAINT fk_raw_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_raw_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Raw payload per object per run';


-- =======================================================================
-- Inventory: clusters
-- =======================================================================

-- payload_hash is the SHA-256 of the normalized fields, not of the raw
-- response: any field the source returns but DCInfraSnap does not keep would
-- otherwise produce a phantom change in the journal.
--
-- On each run, an unchanged hash refreshes only last_seen and last_run_id.
-- A changed hash updates the row and writes a journal entry holding just the
-- fields that actually differ.
CREATE TABLE vc_cluster (
    id               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id   SMALLINT UNSIGNED NOT NULL,

    moref            VARCHAR(64)       NOT NULL COMMENT 'e.g. domain-c123',
    name             VARCHAR(255)      NOT NULL,

    datacenter_moref VARCHAR(64)       DEFAULT NULL,
    datacenter_name  VARCHAR(255)      DEFAULT NULL,

    ha_enabled       TINYINT(1)        DEFAULT NULL,
    drs_enabled      TINYINT(1)        DEFAULT NULL,
    drs_behavior     VARCHAR(32)       DEFAULT NULL,

    payload_hash     CHAR(64)          NOT NULL,

    first_seen       DATETIME          NOT NULL COMMENT 'UTC',
    last_seen        DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id     INT UNSIGNED      NOT NULL,
    last_run_id      INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_cluster (integration_id, moref),
    KEY idx_cluster_last_seen (integration_id, last_seen),
    KEY idx_cluster_name (integration_id, name),

    CONSTRAINT fk_cluster_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_cluster_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_cluster_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: clusters, current state';


CREATE TABLE vc_cluster_change (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cluster_id INT UNSIGNED    NOT NULL,
    run_id     INT UNSIGNED    NOT NULL,
    changed_at DATETIME        NOT NULL COMMENT 'UTC',
    old_values JSON            NOT NULL COMMENT 'Only the fields that changed',
    new_values JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_cluster_change (cluster_id, changed_at),
    KEY idx_cluster_change_run (run_id),

    CONSTRAINT fk_cluster_change_cluster
        FOREIGN KEY (cluster_id) REFERENCES vc_cluster (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_cluster_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Change journal for clusters';


-- =======================================================================
-- Inventory: hosts
-- =======================================================================

-- The hardware columns are the reason a host is worth recording at all, so
-- they are here from the start rather than bolted on later over a full table.
--
-- power_state is nullable on purpose: a host that is NOT_RESPONDING does not
-- report one.
CREATE TABLE vc_host (
    id               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id   SMALLINT UNSIGNED NOT NULL,

    moref            VARCHAR(64)       NOT NULL COMMENT 'e.g. host-28',
    name             VARCHAR(255)      NOT NULL COMMENT 'FQDN as vCenter sees it',

    connection_state ENUM('CONNECTED','DISCONNECTED','NOT_RESPONDING') DEFAULT NULL,
    power_state      ENUM('POWERED_ON','POWERED_OFF','STANDBY','UNKNOWN') DEFAULT NULL,
    in_maintenance   TINYINT(1)        DEFAULT NULL,

    cluster_moref    VARCHAR(64)       DEFAULT NULL,
    datacenter_moref VARCHAR(64)       DEFAULT NULL,

    -- --- Hardware -------------------------------------------------------
    vendor           VARCHAR(100)      DEFAULT NULL,
    model            VARCHAR(150)      DEFAULT NULL,
    serial_number    VARCHAR(100)      DEFAULT NULL,
    asset_tag        VARCHAR(100)      DEFAULT NULL,
    hardware_uuid    CHAR(36)          DEFAULT NULL,

    cpu_model        VARCHAR(150)      DEFAULT NULL,
    cpu_sockets      SMALLINT UNSIGNED DEFAULT NULL,
    cpu_cores        SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Total cores',
    cpu_threads      SMALLINT UNSIGNED DEFAULT NULL,
    cpu_mhz          INT UNSIGNED      DEFAULT NULL,

    memory_bytes     BIGINT UNSIGNED   DEFAULT NULL,

    esxi_version     VARCHAR(32)       DEFAULT NULL COMMENT 'e.g. 8.0.3',
    esxi_build       VARCHAR(32)       DEFAULT NULL,
    bios_version     VARCHAR(64)       DEFAULT NULL,

    vm_count         SMALLINT UNSIGNED DEFAULT NULL,
    uptime_seconds   INT UNSIGNED      DEFAULT NULL,

    payload_hash     CHAR(64)          NOT NULL,

    first_seen       DATETIME          NOT NULL COMMENT 'UTC',
    last_seen        DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id     INT UNSIGNED      NOT NULL,
    last_run_id      INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_host (integration_id, moref),
    KEY idx_host_last_seen (integration_id, last_seen),
    KEY idx_host_name (integration_id, name),
    KEY idx_host_cluster (integration_id, cluster_moref),

    -- Not UNIQUE: serial numbers are regularly empty or misreported.
    KEY idx_host_serial (serial_number),
    KEY idx_host_hw_uuid (hardware_uuid),

    CONSTRAINT fk_host_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_host_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_host_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: ESXi hosts, current state';


CREATE TABLE vc_host_change (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    host_id    INT UNSIGNED    NOT NULL,
    run_id     INT UNSIGNED    NOT NULL,
    changed_at DATETIME        NOT NULL COMMENT 'UTC',
    old_values JSON            NOT NULL COMMENT 'Only the fields that changed',
    new_values JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_host_change (host_id, changed_at),
    KEY idx_host_change_run (run_id),

    CONSTRAINT fk_host_change_host
        FOREIGN KEY (host_id) REFERENCES vc_host (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_host_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Change journal for hosts';


-- =======================================================================
-- Inventory: virtual machines
-- =======================================================================

-- The identifiers matter most here:
--   instance_uuid  unique within one vCenter and stable  -> primary key
--   bios_uuid      SMBIOS UUID the guest sees; links to agents running
--                  inside the machine. NOT unique — clones repeat it.
--   moref          only valid inside the vCenter and changes on
--                  re-registration; kept for further calls and diagnostics
--
-- Both UUIDs are nullable: the identity block comes from the per-VM details
-- and can be missing.
--
-- Two descriptions of the operating system, and the difference is the point:
--   guest_os         what was configured in vCenter
--   guest_full_name  what the guest actually reports via VMware Tools
-- On neglected machines these drift apart, and that drift is worth seeing.
--
-- Notes (config.annotation) is the one free-text field people really fill in:
-- what the machine is for, who asked for it, how long it should live.
CREATE TABLE vc_vm (
    id                   INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id       SMALLINT UNSIGNED NOT NULL,

    moref                VARCHAR(64)       NOT NULL COMMENT 'e.g. vm-1234',
    instance_uuid        CHAR(36)          DEFAULT NULL COMMENT 'vCenter-specific',
    bios_uuid            CHAR(36)          DEFAULT NULL COMMENT 'SMBIOS, not unique',

    name                 VARCHAR(255)      NOT NULL,
    power_state          ENUM('POWERED_ON','POWERED_OFF','SUSPENDED') DEFAULT NULL,
    is_template          TINYINT(1)        NOT NULL DEFAULT 0,

    guest_os             VARCHAR(100)      DEFAULT NULL COMMENT 'Configured in vCenter',
    notes                TEXT              DEFAULT NULL COMMENT 'config.annotation',
    hardware_version     VARCHAR(16)       DEFAULT NULL COMMENT 'e.g. vmx-19',

    cpu_count            SMALLINT UNSIGNED DEFAULT NULL,
    cpu_cores_per_socket SMALLINT UNSIGNED DEFAULT NULL,
    memory_mib           INT UNSIGNED      DEFAULT NULL,

    disk_count           SMALLINT UNSIGNED DEFAULT NULL,
    disk_capacity_bytes  BIGINT UNSIGNED   DEFAULT NULL COMMENT 'Sum of all vDisks',
    nic_count            SMALLINT UNSIGNED DEFAULT NULL,

    -- --- Reported from inside the guest ---------------------------------
    -- Available only while VMware Tools are running. When they are not, the
    -- reason is recorded in guest_state instead of the fields going quietly
    -- empty.
    --
    -- These take part in payload_hash, so a changing IP address produces a
    -- journal entry. In a DHCP environment that can get noisy; if it does,
    -- they come out of the hash in the mapper, not out of the schema.
    guest_state          VARCHAR(32)       DEFAULT NULL,
    guest_tools_status   VARCHAR(32)       DEFAULT NULL,
    guest_hostname       VARCHAR(255)      DEFAULT NULL,
    guest_ip_address     VARCHAR(45)       DEFAULT NULL COMMENT 'Primary address, IPv4 or IPv6',
    guest_full_name      VARCHAR(150)      DEFAULT NULL COMMENT 'Reported by the guest',

    -- --- Placement ------------------------------------------------------
    host_moref           VARCHAR(64)       DEFAULT NULL,
    cluster_moref        VARCHAR(64)       DEFAULT NULL,
    datacenter_moref     VARCHAR(64)       DEFAULT NULL,
    folder_path          VARCHAR(512)      DEFAULT NULL,

    payload_hash         CHAR(64)          NOT NULL,

    first_seen           DATETIME          NOT NULL COMMENT 'UTC',
    last_seen            DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id         INT UNSIGNED      NOT NULL,
    last_run_id          INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_vm (integration_id, moref),
    UNIQUE KEY uq_vm_instance_uuid (integration_id, instance_uuid),

    -- bios_uuid is deliberately NOT unique — clones share it.
    KEY idx_vm_bios_uuid (bios_uuid),
    KEY idx_vm_instance_uuid (instance_uuid),

    -- For "whose address is this" lookups and correlation with other sources.
    KEY idx_vm_guest_hostname (guest_hostname),
    KEY idx_vm_guest_ip (guest_ip_address),

    KEY idx_vm_last_seen (integration_id, last_seen),
    KEY idx_vm_name (integration_id, name),
    KEY idx_vm_host (integration_id, host_moref),
    KEY idx_vm_cluster (integration_id, cluster_moref),

    CONSTRAINT fk_vm_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_vm_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_vm_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: virtual machines, current state';


CREATE TABLE vc_vm_change (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vm_id      INT UNSIGNED    NOT NULL,
    run_id     INT UNSIGNED    NOT NULL,
    changed_at DATETIME        NOT NULL COMMENT 'UTC',
    old_values JSON            NOT NULL COMMENT 'Only the fields that changed',
    new_values JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_vm_change (vm_id, changed_at),
    KEY idx_vm_change_run (run_id),

    CONSTRAINT fk_vm_change_vm
        FOREIGN KEY (vm_id) REFERENCES vc_vm (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_vm_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Change journal for virtual machines';


-- =======================================================================
-- Inventory: datastores
-- =======================================================================

-- Free space changes between every run, so the journal for this table grows
-- faster than the others. That is intentional: the history of how a datastore
-- filled up is exactly what nobody has when they need it.
CREATE TABLE vc_datastore (
    id               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id   SMALLINT UNSIGNED NOT NULL,

    moref            VARCHAR(64)       NOT NULL COMMENT 'e.g. datastore-15',
    name             VARCHAR(255)      NOT NULL,

    type             VARCHAR(32)       DEFAULT NULL COMMENT 'VMFS, NFS, vsan',
    accessible       TINYINT(1)        DEFAULT NULL,
    maintenance_mode VARCHAR(32)       DEFAULT NULL,

    capacity_bytes   BIGINT UNSIGNED   DEFAULT NULL,
    free_bytes       BIGINT UNSIGNED   DEFAULT NULL,
    uncommitted_bytes BIGINT UNSIGNED  DEFAULT NULL COMMENT 'Thin provisioning overcommit',

    url              VARCHAR(512)      DEFAULT NULL,
    vmfs_version     VARCHAR(16)       DEFAULT NULL,
    nfs_remote_host  VARCHAR(255)      DEFAULT NULL,
    nfs_remote_path  VARCHAR(512)      DEFAULT NULL,

    host_count       SMALLINT UNSIGNED DEFAULT NULL,
    vm_count         SMALLINT UNSIGNED DEFAULT NULL,

    datacenter_moref VARCHAR(64)       DEFAULT NULL,

    payload_hash     CHAR(64)          NOT NULL,

    first_seen       DATETIME          NOT NULL COMMENT 'UTC',
    last_seen        DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id     INT UNSIGNED      NOT NULL,
    last_run_id      INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_datastore (integration_id, moref),
    KEY idx_datastore_last_seen (integration_id, last_seen),
    KEY idx_datastore_name (integration_id, name),
    KEY idx_datastore_type (integration_id, type),

    CONSTRAINT fk_datastore_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_datastore_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_datastore_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: datastores, current state';


CREATE TABLE vc_datastore_change (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    datastore_id INT UNSIGNED    NOT NULL,
    run_id       INT UNSIGNED    NOT NULL,
    changed_at   DATETIME        NOT NULL COMMENT 'UTC',
    old_values   JSON            NOT NULL COMMENT 'Only the fields that changed',
    new_values   JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_datastore_change (datastore_id, changed_at),
    KEY idx_datastore_change_run (run_id),

    CONSTRAINT fk_datastore_change_datastore
        FOREIGN KEY (datastore_id) REFERENCES vc_datastore (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_datastore_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Change journal for datastores';


-- =======================================================================
-- Inventory: licenses
-- =======================================================================

-- The license key is stored masked, the way vCenter itself displays it
-- (XXXXX-XXXXX-XXXXX-XXXXX-12345). A full key is a purchasable asset and has
-- no business sitting in an inventory database; the last block is enough to
-- tell two licenses apart.
--
-- moref holds the masked key, since licenses have no managed object ID.
CREATE TABLE vc_license (
    id              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id  SMALLINT UNSIGNED NOT NULL,

    moref           VARCHAR(64)       NOT NULL COMMENT 'Masked license key',
    name            VARCHAR(255)      NOT NULL COMMENT 'Edition name',
    edition_key     VARCHAR(190)      DEFAULT NULL,

    product_name    VARCHAR(150)      DEFAULT NULL,
    product_version VARCHAR(32)       DEFAULT NULL,

    total_units     INT UNSIGNED      DEFAULT NULL COMMENT '0 = unlimited',
    used_units      INT UNSIGNED      DEFAULT NULL,
    cost_unit       VARCHAR(32)       DEFAULT NULL COMMENT 'cpuPackage, vm, server',

    expires_at      DATETIME          DEFAULT NULL COMMENT 'UTC, NULL means perpetual',

    payload_hash    CHAR(64)          NOT NULL,

    first_seen      DATETIME          NOT NULL COMMENT 'UTC',
    last_seen       DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id    INT UNSIGNED      NOT NULL,
    last_run_id     INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_license (integration_id, moref),
    KEY idx_license_last_seen (integration_id, last_seen),
    KEY idx_license_expires (expires_at),

    CONSTRAINT fk_license_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_license_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_license_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: licenses, current state';


CREATE TABLE vc_license_change (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    license_id INT UNSIGNED    NOT NULL,
    run_id     INT UNSIGNED    NOT NULL,
    changed_at DATETIME        NOT NULL COMMENT 'UTC',
    old_values JSON            NOT NULL COMMENT 'Only the fields that changed',
    new_values JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_license_change (license_id, changed_at),
    KEY idx_license_change_run (run_id),

    CONSTRAINT fk_license_change_license
        FOREIGN KEY (license_id) REFERENCES vc_license (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_license_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Change journal for licenses';
