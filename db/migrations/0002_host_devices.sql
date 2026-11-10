-- db/migrations/0002_host_devices.sql
--
-- Host devices: physical network cards, VMkernel adapters, standard
-- switches, port groups and storage adapters.
--
-- ONE TABLE FOR FIVE KINDS. Each kind carries its own fields, so this is a
-- union of columns in which most are NULL on any given row. The alternative
-- is five tables with five journals, for data that is always looked at
-- together on the same host page. The kind column says which is which.
--
-- IDENTITY IS ASSEMBLED. These are not inventory objects and have no moref —
-- they exist only as entries in a host's configuration. The key is host,
-- kind and device name: "host-28:pnic:vmnic0". Names are unique within a
-- host, and the kind belongs in the key because a device name and a port
-- group name can coincide.
--
-- WWN IS STORED IN HEX. A Fibre Channel adapter reports a decimal number
-- (2378169888378566912) while SAS already reports a hex string. What is
-- stored is the form everyone actually uses: 21:00:f4:e9:d4:55:b9:00.

CREATE TABLE vc_host_device (
    id                  INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id      SMALLINT UNSIGNED NOT NULL,

    moref               VARCHAR(160)      NOT NULL
                        COMMENT 'Assembled key: host:kind:device',

    host_moref          VARCHAR(64)       NOT NULL COMMENT 'Joins to vc_host.moref',
    kind                VARCHAR(16)       NOT NULL COMMENT 'pnic, vmk, vswitch, portgroup, hba',
    device              VARCHAR(128)      NOT NULL COMMENT 'vmnic0, vmk0, vSwitch0, VLAN 1408, vmhba1',

    -- Network cards and VMkernel adapters
    mac                 VARCHAR(32)       DEFAULT NULL,
    driver              VARCHAR(64)       DEFAULT NULL COMMENT 'pnic and hba',
    driver_version      VARCHAR(64)       DEFAULT NULL COMMENT 'pnic only',
    firmware_version    VARCHAR(64)       DEFAULT NULL COMMENT 'pnic only',
    speed_mb            INT UNSIGNED      DEFAULT NULL
                        COMMENT 'pnic: linkSpeed.speedMb. Absent while the link is down',
    duplex              TINYINT(1)        DEFAULT NULL COMMENT 'pnic only',
    pci                 VARCHAR(32)       DEFAULT NULL COMMENT 'pnic and hba',

    -- VMkernel adapters: this is where a host's own IP addresses live
    ip_address          VARCHAR(45)       DEFAULT NULL COMMENT 'IPv4 or IPv6',
    subnet_mask         VARCHAR(45)       DEFAULT NULL,
    gateway             VARCHAR(45)       DEFAULT NULL,
    dhcp                TINYINT(1)        DEFAULT NULL,
    mtu                 INT UNSIGNED      DEFAULT NULL COMMENT 'vmk and vswitch',
    port_group          VARCHAR(128)      DEFAULT NULL COMMENT 'The port group a vmk sits on',

    -- Standard switches
    num_ports           INT UNSIGNED      DEFAULT NULL,
    num_ports_available INT UNSIGNED      DEFAULT NULL,

    -- Port groups
    vlan_id             INT               DEFAULT NULL COMMENT '0 = no VLAN, 4095 = trunk',
    vswitch_name        VARCHAR(128)      DEFAULT NULL,

    -- Storage adapters
    hba_type            VARCHAR(32)       DEFAULT NULL
                        COMMENT 'fibreChannel, iscsi, serialAttached, parallelScsi',
    model               VARCHAR(255)      DEFAULT NULL,
    status              VARCHAR(32)       DEFAULT NULL COMMENT 'online, offline, unknown, fault',
    storage_protocol    VARCHAR(32)       DEFAULT NULL,
    wwpn                VARCHAR(32)       DEFAULT NULL COMMENT 'Hex, colon separated',
    wwnn                VARCHAR(32)       DEFAULT NULL COMMENT 'Hex, colon separated',
    iscsi_name          VARCHAR(255)      DEFAULT NULL COMMENT 'iqn.…',

    payload_hash        CHAR(64)          NOT NULL,

    first_seen          DATETIME          NOT NULL COMMENT 'UTC',
    last_seen           DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id        INT UNSIGNED      NOT NULL,
    last_run_id         INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_host_device (integration_id, moref),
    KEY idx_host_device_host (integration_id, host_moref, kind),
    KEY idx_host_device_kind (integration_id, kind),
    KEY idx_host_device_last_seen (integration_id, last_seen),

    CONSTRAINT fk_host_device_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_host_device_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_host_device_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: host devices, current state';


CREATE TABLE vc_host_device_change (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id  INT UNSIGNED    NOT NULL,
    run_id     INT UNSIGNED    NOT NULL,
    changed_at DATETIME        NOT NULL COMMENT 'UTC',
    old_values JSON            NOT NULL COMMENT 'Only the fields that changed',
    new_values JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_host_device_change (device_id, changed_at),
    KEY idx_host_device_change_run (run_id),

    CONSTRAINT fk_host_device_change_device
        FOREIGN KEY (device_id) REFERENCES vc_host_device (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_host_device_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Change journal for host devices';


-- Per-run counters for the new type, alongside the existing ones.
-- RunRepository builds these column names from the type's prefix, so without
-- them the status write aims at a column that does not exist.
ALTER TABLE discovery_run
    ADD COLUMN host_devices_status  ENUM('pending','ok','failed') NOT NULL DEFAULT 'pending'
        AFTER licenses_status,
    ADD COLUMN host_devices_found   INT UNSIGNED NOT NULL DEFAULT 0 AFTER licenses_found,
    ADD COLUMN host_devices_changed INT UNSIGNED NOT NULL DEFAULT 0 AFTER licenses_changed;


-- The raw layer records one row per object per run, keyed by type.
ALTER TABLE raw_object
    MODIFY COLUMN object_type
        ENUM('cluster','host','vm','datastore','license','host_device') NOT NULL;
