-- =======================================================================
-- 0005_hyperv.sql
--
-- Hyper-V as a third kind of source, collected offline: a read-only
-- PowerShell script runs on each host, writes one JSON file, and the files
-- are uploaded into DCInfraSnap. Nothing here is shared with the vCenter or
-- the FC tables; the hv_ set stands on its own.
--
-- The widths and the nullability below come from two real files collected on
-- 17 November 2026 from cluster CL01 (two Dell R470 nodes, Windows Server
-- 2025, five machines), not from guesswork.
--
-- -----------------------------------------------------------------------
-- Keys
-- -----------------------------------------------------------------------
-- Hyper-V has no managed object reference, so moref holds the most stable
-- identifier the source actually reports, lowercased so that the same object
-- cannot arrive twice in two letter cases:
--
--   hv_cluster          <cluster guid>                 Get-Cluster .Id
--   hv_cluster_network  net:<network guid>
--   hv_csv              csv:<csv guid>
--   hv_node             <smbios uuid>, or host:<hostname> when the firmware
--                       reports a useless one (all zeroes, all F, duplicated
--                       across cloned hardware)
--   hv_host_device      dev:<node moref>:<kind>:<mac or adapter id or name>
--   hv_vswitch          vswitch:<node moref>:<switch guid>
--   hv_vm               <vm guid>                      Get-VM .Id
--   hv_vm_disk          disk:<vm guid>:<controller type>:<number>:<location>
--   hv_vm_nic           <adapter id>                   Microsoft:<vm>\<adapter>
--   hv_vm_checkpoint    cp:<checkpoint guid>
--
-- Two things the collected data settled:
--
-- A virtual switch is per node, not per cluster. The switch called
-- vSwitch-VM reports guid fc1ca452-… on the first node and abb1c3ed-… on the
-- second, so the node has to be part of the key. The same is true of host
-- devices.
--
-- The cluster node id from Get-ClusterNode is a small integer (1, 2) that
-- means nothing outside the cluster, so it is an attribute here and never a
-- key. The SMBIOS UUID is per-chassis and was correct on both nodes.
--
-- A virtual machine's disk is keyed by its controller position rather than
-- by its file path: moving a VHDX is a change to a disk, not a different
-- disk. The path is indexed separately, because shared storage is found by
-- grouping on it.
-- =======================================================================


-- =======================================================================
-- Sources: an offline source has no credentials
-- =======================================================================
--
-- A Hyper-V integration is a label, not a connection: there is nothing to
-- sign in to, because the data arrives as an uploaded file. username and
-- password_encrypted were NOT NULL because every source so far was reached
-- over the network.
--
-- hostname carries the cluster name or the standalone host name and port
-- stays 0, so uq_integration_endpoint (type, hostname, port) keeps doing its
-- job: the same cluster cannot be registered twice.
ALTER TABLE integration
    MODIFY COLUMN username           VARCHAR(190)    DEFAULT NULL
           COMMENT 'Read-only account; NULL for an offline source',
    MODIFY COLUMN password_encrypted VARBINARY(1024) DEFAULT NULL
           COMMENT 'libsodium secretbox; NULL for an offline source';


-- =======================================================================
-- Raw layer: stop maintaining a second copy of the type list
-- =======================================================================
--
-- object_type was an ENUM, which meant every new object type needed this
-- column restated in full in a migration — the hand-written duplicate of
-- ObjectType::all() that has already caused one bug in this project. Ten new
-- types at once is the moment to stop.
--
-- Nothing is lost: ObjectType::all() is the only thing that ever writes this
-- column, and a value absent from it is unreachable. The index key stays
-- well inside InnoDB's limit (4 + 129 + 1022 = 1155 bytes of 3072).
ALTER TABLE raw_object
    MODIFY COLUMN object_type VARCHAR(32) NOT NULL
           COMMENT 'ObjectType::all() key';


-- =======================================================================
-- Cluster
-- =======================================================================
--
-- One row per failover cluster. A standalone host has none, and its node row
-- carries cluster_moref NULL.
--
-- Every node reports the whole cluster section, and on CL01 the two files
-- agreed field for field. The importer therefore takes it from one file and
-- uses the others only to notice disagreement, which means the files were
-- collected too far apart to be one snapshot.
CREATE TABLE hv_cluster (
    id                  INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id      SMALLINT UNSIGNED NOT NULL,

    moref               VARCHAR(255)      NOT NULL COMMENT 'Cluster GUID, lowercase',

    name                VARCHAR(128)      NOT NULL COMMENT 'e.g. CL01',
    domain              VARCHAR(255)      DEFAULT NULL,

    -- Reported as digit strings, not integers: "12" is Server 2025.
    functional_level    VARCHAR(32)       DEFAULT NULL,
    upgrade_version     VARCHAR(32)       DEFAULT NULL,

    quorum_type         VARCHAR(64)       DEFAULT NULL COMMENT 'e.g. Majority',
    quorum_resource     VARCHAR(255)      DEFAULT NULL
                        COMMENT 'Witness resource name; need not be a CSV',

    shared_volumes_root VARCHAR(255)      DEFAULT NULL COMMENT 'Root of the cluster storage paths',
    node_count          SMALLINT UNSIGNED DEFAULT NULL,

    payload_hash        CHAR(64)          NOT NULL,

    first_seen          DATETIME          NOT NULL COMMENT 'UTC',
    last_seen           DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id        INT UNSIGNED      NOT NULL,
    last_run_id         INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_hv_cluster (integration_id, moref),
    KEY idx_hv_cluster_name (integration_id, name),
    KEY idx_hv_cluster_last_seen (integration_id, last_seen),

    CONSTRAINT fk_hv_cluster_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_hv_cluster_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_hv_cluster_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: Hyper-V failover cluster, current state';


CREATE TABLE hv_cluster_change (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cluster_id INT UNSIGNED    NOT NULL,
    run_id     INT UNSIGNED    NOT NULL,
    changed_at DATETIME        NOT NULL COMMENT 'UTC',
    old_values JSON            NOT NULL COMMENT 'Only the fields that changed',
    new_values JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_hv_cluster_change (cluster_id, changed_at),
    KEY idx_hv_cluster_change_run (run_id),

    CONSTRAINT fk_hv_cluster_change_cluster
        FOREIGN KEY (cluster_id) REFERENCES hv_cluster (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_hv_cluster_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Change journal for Hyper-V clusters';


-- =======================================================================
-- Cluster networks
-- =======================================================================
--
-- network_role, not role: ROLE is a keyword in MySQL 8 and this schema does
-- not use bare keywords as column names.
CREATE TABLE hv_cluster_network (
    id             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id SMALLINT UNSIGNED NOT NULL,

    moref          VARCHAR(255)      NOT NULL COMMENT 'net:<network guid>',

    cluster_moref  VARCHAR(255)      NOT NULL,

    name           VARCHAR(128)      NOT NULL COMMENT 'e.g. Mgmt, LM',
    network_role   VARCHAR(32)       DEFAULT NULL
                   COMMENT 'Cluster, ClusterAndClient, None',
    network_state  VARCHAR(32)       DEFAULT NULL COMMENT 'Up, Down, Partitioned',

    address        VARCHAR(64)       DEFAULT NULL COMMENT 'Subnet address',
    address_mask   VARCHAR(64)       DEFAULT NULL,
    ipv6_address   VARCHAR(128)      DEFAULT NULL,

    metric         INT UNSIGNED      DEFAULT NULL
                   COMMENT 'Lower wins for cluster traffic; 30080, 70080',

    payload_hash   CHAR(64)          NOT NULL,

    first_seen     DATETIME          NOT NULL COMMENT 'UTC',
    last_seen      DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id   INT UNSIGNED      NOT NULL,
    last_run_id    INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_hv_cluster_network (integration_id, moref),
    KEY idx_hv_cluster_network_cluster (integration_id, cluster_moref),
    KEY idx_hv_cluster_network_last_seen (integration_id, last_seen),

    CONSTRAINT fk_hv_cluster_network_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_hv_cluster_network_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_hv_cluster_network_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: cluster networks, current state';


CREATE TABLE hv_cluster_network_change (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    network_id INT UNSIGNED    NOT NULL,
    run_id     INT UNSIGNED    NOT NULL,
    changed_at DATETIME        NOT NULL COMMENT 'UTC',
    old_values JSON            NOT NULL,
    new_values JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_hv_cluster_network_change (network_id, changed_at),
    KEY idx_hv_cluster_network_change_run (run_id),

    CONSTRAINT fk_hv_cluster_network_change_network
        FOREIGN KEY (network_id) REFERENCES hv_cluster_network (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_hv_cluster_network_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Change journal for cluster networks';


-- =======================================================================
-- Cluster shared volumes
-- =======================================================================
--
-- used_bytes and free_bytes move whenever a machine writes a block, so they
-- are stored and kept current but excluded from the comparison. owner_node
-- is not: a CSV changing owner is a real event and belongs in the journal.
--
-- percent_free is deliberately absent. The source reports it as the string
-- "94.63495"; it is free_bytes over size_bytes and is better computed than
-- stored as text that would never compare cleanly.
CREATE TABLE hv_csv (
    id                INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id    SMALLINT UNSIGNED NOT NULL,

    moref             VARCHAR(255)      NOT NULL COMMENT 'csv:<csv guid>',

    cluster_moref     VARCHAR(255)      NOT NULL,

    name              VARCHAR(255)      NOT NULL COMMENT 'e.g. Cluster Disk 2',
    csv_state         VARCHAR(32)       DEFAULT NULL COMMENT 'Online, Offline, Failed',
    owner_node        VARCHAR(255)      DEFAULT NULL COMMENT 'Hostname of the current owner',

    friendly_path     VARCHAR(512)      DEFAULT NULL
                      COMMENT 'Mount path under the cluster storage root',
    volume_name       VARCHAR(255)      DEFAULT NULL
                      COMMENT 'Windows volume GUID path',
    file_system       VARCHAR(32)       DEFAULT NULL COMMENT 'CSVFS, ReFS',

    fault_state       VARCHAR(64)       DEFAULT NULL COMMENT 'NoFaults and the like',
    redirected_access TINYINT(1)        DEFAULT NULL,
    maintenance_mode  TINYINT(1)        DEFAULT NULL,

    size_bytes        BIGINT UNSIGNED   DEFAULT NULL,
    used_bytes        BIGINT UNSIGNED   DEFAULT NULL,
    free_bytes        BIGINT UNSIGNED   DEFAULT NULL,

    payload_hash      CHAR(64)          NOT NULL,

    first_seen        DATETIME          NOT NULL COMMENT 'UTC',
    last_seen         DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id      INT UNSIGNED      NOT NULL,
    last_run_id       INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_hv_csv (integration_id, moref),
    KEY idx_hv_csv_cluster (integration_id, cluster_moref),
    KEY idx_hv_csv_last_seen (integration_id, last_seen),

    CONSTRAINT fk_hv_csv_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_hv_csv_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_hv_csv_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: cluster shared volumes, current state';


CREATE TABLE hv_csv_change (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    csv_id     INT UNSIGNED    NOT NULL,
    run_id     INT UNSIGNED    NOT NULL,
    changed_at DATETIME        NOT NULL COMMENT 'UTC',
    old_values JSON            NOT NULL,
    new_values JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_hv_csv_change (csv_id, changed_at),
    KEY idx_hv_csv_change_run (run_id),

    CONSTRAINT fk_hv_csv_change_csv
        FOREIGN KEY (csv_id) REFERENCES hv_csv (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_hv_csv_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Change journal for cluster shared volumes';


-- =======================================================================
-- Nodes
-- =======================================================================
--
-- One row per Hyper-V host, whether it is a cluster node or standalone. The
-- cluster-side fields (cluster_node_id, node_state, drain_status) come from
-- Get-ClusterNode and stay NULL on a standalone host.
--
-- source_collected_at is the moment the script ran on that node. It differs
-- on every collection by definition, so it is volatile; it is kept because
-- a file set collected over three days is a question the Collections page
-- should be able to answer.
--
-- replica_* stayed NULL on both nodes: Get-VMReplicationServer fails with
-- "object was not found" on Windows Server 2025 when Hyper-V Replica has
-- never been configured, even for an administrator. Not configured is the
-- honest reading, and the step is recorded as unavailable rather than as an
-- error.
CREATE TABLE hv_node (
    id                        INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id            SMALLINT UNSIGNED NOT NULL,

    moref                     VARCHAR(255)      NOT NULL
                              COMMENT 'SMBIOS UUID, or host:<hostname>',

    hostname                  VARCHAR(255)      NOT NULL,
    fqdn                      VARCHAR(255)      DEFAULT NULL
                              COMMENT 'Falls back to the hostname off-domain',
    domain                    VARCHAR(255)      DEFAULT NULL
                              COMMENT 'WORKGROUP when not joined',
    part_of_domain            TINYINT(1)        DEFAULT NULL,

    cluster_moref             VARCHAR(255)      DEFAULT NULL
                              COMMENT 'NULL on a standalone host',
    cluster_node_id           VARCHAR(32)       DEFAULT NULL
                              COMMENT 'Cluster-local ordinal, not an identity',
    node_state                VARCHAR(32)       DEFAULT NULL COMMENT 'Up, Down, Paused',
    drain_status              VARCHAR(32)       DEFAULT NULL COMMENT 'NotInitiated and the like',

    -- --- Hardware -------------------------------------------------------
    smbios_uuid               CHAR(36)          DEFAULT NULL,
    vendor                    VARCHAR(100)      DEFAULT NULL COMMENT 'e.g. Dell Inc.',
    model                     VARCHAR(150)      DEFAULT NULL COMMENT 'e.g. PowerEdge R470',
    serial_number             VARCHAR(100)      DEFAULT NULL,
    bios_version              VARCHAR(64)       DEFAULT NULL,
    bios_release_date         DATETIME          DEFAULT NULL COMMENT 'UTC',

    cpu_model                 VARCHAR(150)      DEFAULT NULL,
    cpu_sockets               SMALLINT UNSIGNED DEFAULT NULL
                              COMMENT 'One Win32_Processor instance per socket',
    cpu_cores                 SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Total physical cores',
    cpu_logical               SMALLINT UNSIGNED DEFAULT NULL,
    cpu_max_clock_mhz         INT UNSIGNED      DEFAULT NULL,
    memory_bytes              BIGINT UNSIGNED   DEFAULT NULL,

    -- --- Operating system -----------------------------------------------
    os_caption                VARCHAR(190)      DEFAULT NULL
                              COMMENT 'e.g. Microsoft Windows Server 2025 Datacenter',
    os_version                VARCHAR(32)       DEFAULT NULL COMMENT 'e.g. 10.0.26100',
    os_build                  VARCHAR(32)       DEFAULT NULL,
    os_architecture           VARCHAR(32)       DEFAULT NULL,
    os_install_date           DATETIME          DEFAULT NULL COMMENT 'UTC',
    last_boot                 DATETIME          DEFAULT NULL COMMENT 'UTC',
    uptime_seconds            BIGINT UNSIGNED   DEFAULT NULL,

    -- --- Hyper-V --------------------------------------------------------
    hyperv_module_version     VARCHAR(32)       DEFAULT NULL,
    powershell_version        VARCHAR(32)       DEFAULT NULL,
    hyperv_logical_processors SMALLINT UNSIGNED DEFAULT NULL,
    hyperv_memory_capacity    BIGINT UNSIGNED   DEFAULT NULL,
    virtual_machine_path      VARCHAR(512)      DEFAULT NULL,
    virtual_hard_disk_path    VARCHAR(512)      DEFAULT NULL,
    numa_spanning_enabled     TINYINT(1)        DEFAULT NULL,
    enhanced_session_mode     TINYINT(1)        DEFAULT NULL,
    iov_support               TINYINT(1)        DEFAULT NULL,
    mac_address_minimum       CHAR(17)          DEFAULT NULL,
    mac_address_maximum       CHAR(17)          DEFAULT NULL,

    migration_enabled         TINYINT(1)        DEFAULT NULL,
    migration_auth_type       VARCHAR(32)       DEFAULT NULL COMMENT 'Kerberos, CredSSP',
    migration_performance     VARCHAR(32)       DEFAULT NULL COMMENT 'SMB, Compression, TCPIP',
    max_vm_migrations         SMALLINT UNSIGNED DEFAULT NULL,
    max_storage_migrations    SMALLINT UNSIGNED DEFAULT NULL,

    replica_enabled           TINYINT(1)        DEFAULT NULL
                              COMMENT 'NULL when Replica was never configured',
    replica_auth_type         VARCHAR(32)       DEFAULT NULL,
    replica_storage_location  VARCHAR(512)      DEFAULT NULL,

    vm_count                  SMALLINT UNSIGNED DEFAULT NULL
                              COMMENT 'Machines registered on this node',

    source_collected_at       DATETIME          DEFAULT NULL
                              COMMENT 'UTC; when the script ran on this node',

    payload_hash              CHAR(64)          NOT NULL,

    first_seen                DATETIME          NOT NULL COMMENT 'UTC',
    last_seen                 DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id              INT UNSIGNED      NOT NULL,
    last_run_id               INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_hv_node (integration_id, moref),
    KEY idx_hv_node_hostname (integration_id, hostname),
    KEY idx_hv_node_cluster (integration_id, cluster_moref),
    KEY idx_hv_node_last_seen (integration_id, last_seen),

    -- Not UNIQUE: cloned or badly flashed hardware repeats a UUID, and the
    -- import falls back to the hostname exactly when that happens.
    KEY idx_hv_node_uuid (smbios_uuid),

    CONSTRAINT fk_hv_node_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_hv_node_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_hv_node_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: Hyper-V hosts, current state';


CREATE TABLE hv_node_change (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    node_id    INT UNSIGNED    NOT NULL,
    run_id     INT UNSIGNED    NOT NULL,
    changed_at DATETIME        NOT NULL COMMENT 'UTC',
    old_values JSON            NOT NULL,
    new_values JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_hv_node_change (node_id, changed_at),
    KEY idx_hv_node_change_run (run_id),

    CONSTRAINT fk_hv_node_change_node
        FOREIGN KEY (node_id) REFERENCES hv_node (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_hv_node_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Change journal for Hyper-V hosts';


-- =======================================================================
-- Host network devices
-- =======================================================================
--
-- One table, four kinds, the same arrangement as vc_host_device:
--
--   pnic         physical adapter
--   host_nic_os  the management OS side of a virtual adapter, as
--                Get-NetAdapter reports it: "vEthernet (Mgmt)"
--   host_vnic    the same adapter as Hyper-V reports it, with its VLAN
--   team         an LBFO team (empty on CL01, which uses switch embedded
--                teaming instead — that is recorded on hv_vswitch)
--
-- Both views of a virtual adapter are kept because each carries what the
-- other does not: the OS side has the IP addresses, the Hyper-V side has the
-- VLAN and the switch.
--
-- interface_index is volatile. It is assigned by the OS and renumbers on
-- reboot or when a driver reloads, which is not a configuration change.
CREATE TABLE hv_host_device (
    id                    INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id        SMALLINT UNSIGNED NOT NULL,

    moref                 VARCHAR(255)      NOT NULL
                          COMMENT 'dev:<node>:<kind>:<mac|adapter id|name>',

    node_moref            VARCHAR(255)      NOT NULL,
    kind                  VARCHAR(16)       NOT NULL
                          COMMENT 'pnic, host_nic_os, host_vnic, team',

    name                  VARCHAR(255)      NOT NULL COMMENT 'e.g. NIC1, Mgmt',
    adapter_id            VARCHAR(255)      DEFAULT NULL
                          COMMENT 'Hyper-V adapter id; virtual adapters only',
    interface_description VARCHAR(255)      DEFAULT NULL
                          COMMENT 'Driver name as Windows reports it',
    interface_index       INT UNSIGNED      DEFAULT NULL,

    mac_address           CHAR(17)          DEFAULT NULL COMMENT 'Normalized to 00:11:22:…',
    device_status         VARCHAR(32)       DEFAULT NULL COMMENT 'Up, Disconnected, Ok',
    link_speed            VARCHAR(32)       DEFAULT NULL COMMENT 'As reported: "10 Gbps"',
    speed_bps             BIGINT UNSIGNED   DEFAULT NULL,
    media_type            VARCHAR(32)       DEFAULT NULL,
    full_duplex           TINYINT(1)        DEFAULT NULL,
    is_virtual            TINYINT(1)        DEFAULT NULL,

    driver_name           VARCHAR(128)      DEFAULT NULL,
    driver_version        VARCHAR(64)       DEFAULT NULL,

    switch_name           VARCHAR(128)      DEFAULT NULL,
    switch_moref          VARCHAR(255)      DEFAULT NULL,

    vlan_mode             VARCHAR(32)       DEFAULT NULL
                          COMMENT 'Access, Trunk, Untagged, Isolated',
    vlan_access_id        SMALLINT UNSIGNED DEFAULT NULL,
    vlan_native_id        SMALLINT UNSIGNED DEFAULT NULL,
    vlan_allowed_list     VARCHAR(512)      DEFAULT NULL
                          COMMENT 'Comma separated, trunk mode only',

    ip_addresses          VARCHAR(512)      DEFAULT NULL
                          COMMENT 'Comma separated, each as address/prefix',

    teaming_mode          VARCHAR(32)       DEFAULT NULL COMMENT 'LBFO teams only',
    load_balancing        VARCHAR(32)       DEFAULT NULL,
    team_members          VARCHAR(512)      DEFAULT NULL COMMENT 'Comma separated',

    payload_hash          CHAR(64)          NOT NULL,

    first_seen            DATETIME          NOT NULL COMMENT 'UTC',
    last_seen             DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id          INT UNSIGNED      NOT NULL,
    last_run_id           INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_hv_host_device (integration_id, moref),
    KEY idx_hv_host_device_node (integration_id, node_moref, kind),
    KEY idx_hv_host_device_mac (mac_address),
    KEY idx_hv_host_device_last_seen (integration_id, last_seen),

    CONSTRAINT fk_hv_host_device_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_hv_host_device_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_hv_host_device_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: host network devices, current state';


CREATE TABLE hv_host_device_change (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id  INT UNSIGNED    NOT NULL,
    run_id     INT UNSIGNED    NOT NULL,
    changed_at DATETIME        NOT NULL COMMENT 'UTC',
    old_values JSON            NOT NULL,
    new_values JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_hv_host_device_change (device_id, changed_at),
    KEY idx_hv_host_device_change_run (run_id),

    CONSTRAINT fk_hv_host_device_change_device
        FOREIGN KEY (device_id) REFERENCES hv_host_device (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_hv_host_device_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Change journal for host network devices';


-- =======================================================================
-- Virtual switches
-- =======================================================================
--
-- Per node, not per cluster: the same switch name carries a different GUID
-- on each host, which is why node_moref is part of the key.
--
-- Switch embedded teaming is not an LBFO team and has no object of its own.
-- It shows up here: embedded_teaming true, the uplinks in uplink_adapters,
-- and the teaming mode from Get-VMSwitchTeam.
CREATE TABLE hv_vswitch (
    id                           INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id               SMALLINT UNSIGNED NOT NULL,

    moref                        VARCHAR(255)      NOT NULL
                                 COMMENT 'vswitch:<node>:<switch guid>',

    node_moref                   VARCHAR(255)      NOT NULL,

    name                         VARCHAR(128)      NOT NULL,
    switch_guid                  CHAR(36)          DEFAULT NULL,
    switch_type                  VARCHAR(16)       DEFAULT NULL
                                 COMMENT 'External, Internal, Private',

    allow_management_os          TINYINT(1)        DEFAULT NULL,
    embedded_teaming             TINYINT(1)        DEFAULT NULL COMMENT 'SET',
    iov_enabled                  TINYINT(1)        DEFAULT NULL,
    iov_support                  TINYINT(1)        DEFAULT NULL,
    packet_direct_enabled        TINYINT(1)        DEFAULT NULL,

    bandwidth_reservation_mode   VARCHAR(16)       DEFAULT NULL
                                 COMMENT 'Absolute, Weight, Default, None',
    default_flow_min_absolute    BIGINT UNSIGNED   DEFAULT NULL COMMENT 'bits/s',
    default_flow_min_weight      SMALLINT UNSIGNED DEFAULT NULL,

    uplink_adapters              VARCHAR(512)      DEFAULT NULL
                                 COMMENT 'Comma separated interface descriptions',
    team_teaming_mode            VARCHAR(32)       DEFAULT NULL
                                 COMMENT 'SwitchIndependent, Lacp, Static',
    team_load_balancing          VARCHAR(32)       DEFAULT NULL
                                 COMMENT 'Dynamic, HyperVPort, TransportPorts',

    notes                        VARCHAR(512)      DEFAULT NULL,

    payload_hash                 CHAR(64)          NOT NULL,

    first_seen                   DATETIME          NOT NULL COMMENT 'UTC',
    last_seen                    DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id                 INT UNSIGNED      NOT NULL,
    last_run_id                  INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_hv_vswitch (integration_id, moref),
    KEY idx_hv_vswitch_node (integration_id, node_moref),
    KEY idx_hv_vswitch_name (integration_id, name),
    KEY idx_hv_vswitch_last_seen (integration_id, last_seen),

    CONSTRAINT fk_hv_vswitch_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_hv_vswitch_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_hv_vswitch_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: virtual switches per host, current state';


CREATE TABLE hv_vswitch_change (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vswitch_id INT UNSIGNED    NOT NULL,
    run_id     INT UNSIGNED    NOT NULL,
    changed_at DATETIME        NOT NULL COMMENT 'UTC',
    old_values JSON            NOT NULL,
    new_values JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_hv_vswitch_change (vswitch_id, changed_at),
    KEY idx_hv_vswitch_change_run (run_id),

    CONSTRAINT fk_hv_vswitch_change_vswitch
        FOREIGN KEY (vswitch_id) REFERENCES hv_vswitch (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_hv_vswitch_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Change journal for virtual switches';


-- =======================================================================
-- Virtual machines
-- =======================================================================
--
-- A node reports only the machines registered on it, so one file per node is
-- what makes a cluster complete. owner_node is therefore the node whose file
-- the machine arrived in, and a live migration shows up as a change to that
-- column rather than as a machine appearing and disappearing.
--
-- is_clustered is kept because a cluster node happily runs machines that are
-- not cluster resources: on CL01 both domain controllers sit on local disks.
--
-- memory_maximum is stored as reported even when dynamic memory is off, when
-- it is meaningless (every machine on CL01 reports 1 TiB). Hiding it is the
-- display's job, not storage's.
--
-- integration_services_version is reported as "0.0" by Server 2025 guests,
-- which no longer carry a version. The importer stores NULL for that, so the
-- column means "not reported" rather than "version zero".
--
-- Volatile: the four counters that move while nothing is configured —
-- uptime, assigned and demanded memory, CPU usage — plus the last
-- replication time, which advances every replication cycle.
CREATE TABLE hv_vm (
    id                           INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id               SMALLINT UNSIGNED NOT NULL,

    moref                        VARCHAR(255)      NOT NULL
                                 COMMENT 'VM GUID, lowercase; survives rename and migration',

    name                         VARCHAR(255)      NOT NULL,
    vm_state                     VARCHAR(32)       DEFAULT NULL
                                 COMMENT 'Running, Off, Saved, Paused',
    vm_status                    VARCHAR(190)      DEFAULT NULL
                                 COMMENT 'e.g. Operating normally',
    generation                   TINYINT UNSIGNED  DEFAULT NULL,
    config_version               VARCHAR(16)       DEFAULT NULL COMMENT 'e.g. 12.0',

    owner_node_moref             VARCHAR(255)      DEFAULT NULL,
    owner_node                   VARCHAR(255)      DEFAULT NULL COMMENT 'Hostname',
    is_clustered                 TINYINT(1)        DEFAULT NULL,

    -- --- Processor and memory -------------------------------------------
    processor_count              SMALLINT UNSIGNED DEFAULT NULL,
    cpu_usage_percent            SMALLINT UNSIGNED DEFAULT NULL,
    cpu_reserve_percent          SMALLINT UNSIGNED DEFAULT NULL,
    cpu_limit_percent            SMALLINT UNSIGNED DEFAULT NULL,
    cpu_relative_weight          INT UNSIGNED      DEFAULT NULL,
    cpu_compat_for_migration     TINYINT(1)        DEFAULT NULL,
    cpu_nested_virtualization    TINYINT(1)        DEFAULT NULL,
    cpu_threads_per_core         SMALLINT UNSIGNED DEFAULT NULL
                                 COMMENT '0 means inherit the host setting',

    memory_startup               BIGINT UNSIGNED   DEFAULT NULL,
    memory_minimum               BIGINT UNSIGNED   DEFAULT NULL,
    memory_maximum               BIGINT UNSIGNED   DEFAULT NULL
                                 COMMENT 'Meaningless while dynamic memory is off',
    dynamic_memory_enabled       TINYINT(1)        DEFAULT NULL,
    memory_buffer_percent        SMALLINT UNSIGNED DEFAULT NULL,
    memory_priority              SMALLINT UNSIGNED DEFAULT NULL,
    memory_assigned              BIGINT UNSIGNED   DEFAULT NULL,
    memory_demand                BIGINT UNSIGNED   DEFAULT NULL,

    uptime_seconds               BIGINT UNSIGNED   DEFAULT NULL,

    -- --- Guest ----------------------------------------------------------
    integration_services_version VARCHAR(32)       DEFAULT NULL
                                 COMMENT 'NULL on Server 2025 guests',
    integration_services_enabled VARCHAR(512)      DEFAULT NULL
                                 COMMENT 'Comma separated list of enabled services',
    guest_os_name                VARCHAR(190)      DEFAULT NULL COMMENT 'From KVP',
    guest_os_version             VARCHAR(64)       DEFAULT NULL COMMENT 'From KVP',
    guest_hostname               VARCHAR(255)      DEFAULT NULL
                                 COMMENT 'From KVP; a bare hostname off-domain',

    -- --- Firmware and behaviour -----------------------------------------
    secure_boot                  VARCHAR(16)       DEFAULT NULL COMMENT 'On, Off',
    secure_boot_template         VARCHAR(64)       DEFAULT NULL,
    boot_order                   VARCHAR(255)      DEFAULT NULL COMMENT 'Comma separated',

    automatic_start_action       VARCHAR(32)       DEFAULT NULL
                                 COMMENT 'Start, StartIfRunning, Nothing',
    automatic_start_delay        INT UNSIGNED      DEFAULT NULL,
    automatic_stop_action        VARCHAR(32)       DEFAULT NULL
                                 COMMENT 'Save, ShutDown, TurnOff',
    automatic_critical_action    VARCHAR(32)       DEFAULT NULL,
    automatic_checkpoints        TINYINT(1)        DEFAULT NULL,
    checkpoint_type              VARCHAR(32)       DEFAULT NULL
                                 COMMENT 'Production, Standard, Disabled',

    -- --- Storage locations ----------------------------------------------
    vm_path                      VARCHAR(512)      DEFAULT NULL,
    configuration_location       VARCHAR(512)      DEFAULT NULL,
    snapshot_file_location       VARCHAR(512)      DEFAULT NULL,
    smart_paging_file_path       VARCHAR(512)      DEFAULT NULL,

    created_at_source            DATETIME          DEFAULT NULL COMMENT 'UTC',

    -- No DEFAULT clause: MySQL rejects a default on a TEXT column, and
    -- "DEFAULT NULL" there is a coin toss between versions. Nullable is the
    -- implicit state anyway.
    notes                        TEXT,

    -- --- Rollups, so the list page needs no joins ------------------------
    disk_count                   SMALLINT UNSIGNED DEFAULT NULL,
    disk_capacity_bytes          BIGINT UNSIGNED   DEFAULT NULL COMMENT 'Sum of logical sizes',
    disk_file_bytes              BIGINT UNSIGNED   DEFAULT NULL COMMENT 'Sum of file sizes',
    nic_count                    SMALLINT UNSIGNED DEFAULT NULL,
    checkpoint_count             SMALLINT UNSIGNED DEFAULT NULL,

    -- --- Replication ----------------------------------------------------
    replication_state            VARCHAR(32)       DEFAULT NULL COMMENT 'Disabled when unused',
    replication_health           VARCHAR(32)       DEFAULT NULL,
    replication_mode             VARCHAR(32)       DEFAULT NULL,
    replication_role             VARCHAR(32)       DEFAULT NULL COMMENT 'Primary, Replica, Extended',
    replication_primary_server   VARCHAR(255)      DEFAULT NULL,
    replication_replica_server   VARCHAR(255)      DEFAULT NULL,
    replication_frequency_sec    INT UNSIGNED      DEFAULT NULL,
    replication_recovery_history SMALLINT UNSIGNED DEFAULT NULL,
    replicated_disk_count        SMALLINT UNSIGNED DEFAULT NULL,
    replication_last_time        DATETIME          DEFAULT NULL COMMENT 'UTC',

    payload_hash                 CHAR(64)          NOT NULL,

    first_seen                   DATETIME          NOT NULL COMMENT 'UTC',
    last_seen                    DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id                 INT UNSIGNED      NOT NULL,
    last_run_id                  INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_hv_vm (integration_id, moref),
    KEY idx_hv_vm_name (integration_id, name),
    KEY idx_hv_vm_node (integration_id, owner_node_moref),
    KEY idx_hv_vm_state (integration_id, vm_state),
    KEY idx_hv_vm_clustered (integration_id, is_clustered),
    KEY idx_hv_vm_replication (integration_id, replication_state),
    KEY idx_hv_vm_last_seen (integration_id, last_seen),

    CONSTRAINT fk_hv_vm_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_hv_vm_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_hv_vm_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: Hyper-V virtual machines, current state';


CREATE TABLE hv_vm_change (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vm_id      INT UNSIGNED    NOT NULL,
    run_id     INT UNSIGNED    NOT NULL,
    changed_at DATETIME        NOT NULL COMMENT 'UTC',
    old_values JSON            NOT NULL,
    new_values JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_hv_vm_change (vm_id, changed_at),
    KEY idx_hv_vm_change_run (run_id),

    CONSTRAINT fk_hv_vm_change_vm
        FOREIGN KEY (vm_id) REFERENCES hv_vm (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_hv_vm_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Change journal for Hyper-V virtual machines';


-- =======================================================================
-- Virtual machine disks
-- =======================================================================
--
-- Shared storage has no table of its own. A shared VHDX and a VHD Set are
-- ordinary rows with is_shared set, and the machines sharing one are found
-- by grouping on disk_path — which is why that column is indexed on its
-- first 191 characters.
--
-- is_shared is not guessed from the file extension: it is
-- SupportPersistentReservations from the controller, with a .vhds path
-- treated as shared as well.
--
-- vhd_error holds the reason the disk could not be read, which happens for
-- real: Get-VHD cannot open a shared VHDX that another machine holds. An
-- unreadable disk is still a disk and still recorded.
CREATE TABLE hv_vm_disk (
    id                   INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id       SMALLINT UNSIGNED NOT NULL,

    moref                VARCHAR(255)      NOT NULL
                         COMMENT 'disk:<vm>:<controller>:<number>:<location>',

    vm_moref             VARCHAR(255)      NOT NULL,

    controller_type      VARCHAR(16)       DEFAULT NULL COMMENT 'IDE, SCSI',
    controller_number    SMALLINT UNSIGNED DEFAULT NULL,
    controller_location  SMALLINT UNSIGNED DEFAULT NULL,

    disk_path            VARCHAR(512)      DEFAULT NULL
                         COMMENT 'NULL on a pass-through disk',
    disk_number          INT UNSIGNED      DEFAULT NULL
                         COMMENT 'Physical disk number, pass-through only',
    is_pass_through      TINYINT(1)        NOT NULL DEFAULT 0,
    is_shared            TINYINT(1)        NOT NULL DEFAULT 0
                         COMMENT 'Shared VHDX or VHD Set',
    supports_pr          TINYINT(1)        DEFAULT NULL
                         COMMENT 'SupportPersistentReservations',

    vhd_format           VARCHAR(16)       DEFAULT NULL COMMENT 'VHD, VHDX, VHDSet',
    vhd_type             VARCHAR(16)       DEFAULT NULL
                         COMMENT 'Fixed, Dynamic, Differencing',
    size_bytes           BIGINT UNSIGNED   DEFAULT NULL COMMENT 'Logical size',
    file_size_bytes      BIGINT UNSIGNED   DEFAULT NULL COMMENT 'Size on disk',
    parent_path          VARCHAR(512)      DEFAULT NULL
                         COMMENT 'Differencing disks only',
    block_size           INT UNSIGNED      DEFAULT NULL,
    logical_sector_size  INT UNSIGNED      DEFAULT NULL,
    physical_sector_size INT UNSIGNED      DEFAULT NULL,
    fragmentation_pct    SMALLINT UNSIGNED DEFAULT NULL,
    disk_identifier      CHAR(36)          DEFAULT NULL COMMENT 'The VHD''s own GUID',

    maximum_iops         INT UNSIGNED      DEFAULT NULL COMMENT '0 means no limit',
    minimum_iops         INT UNSIGNED      DEFAULT NULL,
    write_hardening      TINYINT(1)        DEFAULT NULL,

    vhd_error            VARCHAR(512)      DEFAULT NULL
                         COMMENT 'Why Get-VHD could not read it',

    payload_hash         CHAR(64)          NOT NULL,

    first_seen           DATETIME          NOT NULL COMMENT 'UTC',
    last_seen            DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id         INT UNSIGNED      NOT NULL,
    last_run_id          INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_hv_vm_disk (integration_id, moref),
    KEY idx_hv_vm_disk_vm (integration_id, vm_moref),
    KEY idx_hv_vm_disk_path (integration_id, disk_path(191)),
    KEY idx_hv_vm_disk_shared (integration_id, is_shared),
    KEY idx_hv_vm_disk_last_seen (integration_id, last_seen),

    CONSTRAINT fk_hv_vm_disk_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_hv_vm_disk_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_hv_vm_disk_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: virtual machine disks, current state';


CREATE TABLE hv_vm_disk_change (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    disk_id    INT UNSIGNED    NOT NULL,
    run_id     INT UNSIGNED    NOT NULL,
    changed_at DATETIME        NOT NULL COMMENT 'UTC',
    old_values JSON            NOT NULL,
    new_values JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_hv_vm_disk_change (disk_id, changed_at),
    KEY idx_hv_vm_disk_change_run (run_id),

    CONSTRAINT fk_hv_vm_disk_change_disk
        FOREIGN KEY (disk_id) REFERENCES hv_vm_disk (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_hv_vm_disk_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Change journal for virtual machine disks';


-- =======================================================================
-- Virtual machine network adapters
-- =======================================================================
--
-- The adapter id Hyper-V reports is already a stable composite of the
-- machine and the adapter — Microsoft:<vm guid>\<adapter guid> — so it is
-- the key as it stands, lowercased.
--
-- switch_moref points at hv_vswitch, which is per node. An adapter on a
-- machine that has migrated therefore points at a different switch row
-- afterwards, correctly: it is attached to that node's switch.
CREATE TABLE hv_vm_nic (
    id                     INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id         SMALLINT UNSIGNED NOT NULL,

    moref                  VARCHAR(255)      NOT NULL
                           COMMENT 'Hyper-V adapter id: microsoft, vm guid, adapter guid',

    vm_moref               VARCHAR(255)      NOT NULL,

    name                   VARCHAR(255)      DEFAULT NULL COMMENT 'e.g. Network Adapter',
    mac_address            CHAR(17)          DEFAULT NULL,
    dynamic_mac            TINYINT(1)        DEFAULT NULL,

    switch_name            VARCHAR(128)      DEFAULT NULL,
    switch_moref           VARCHAR(255)      DEFAULT NULL,
    is_connected           TINYINT(1)        DEFAULT NULL,
    nic_status             VARCHAR(32)       DEFAULT NULL,
    is_legacy              TINYINT(1)        DEFAULT NULL,
    device_naming          VARCHAR(16)       DEFAULT NULL COMMENT 'On, Off',

    -- --- Port security --------------------------------------------------
    mac_spoofing           VARCHAR(16)       DEFAULT NULL,
    dhcp_guard             VARCHAR(16)       DEFAULT NULL,
    router_guard           VARCHAR(16)       DEFAULT NULL,
    port_mirroring         VARCHAR(16)       DEFAULT NULL
                           COMMENT 'None, Source, Destination',

    -- --- VLAN -----------------------------------------------------------
    vlan_mode              VARCHAR(32)       DEFAULT NULL
                           COMMENT 'Access, Trunk, Untagged, Private, Isolated',
    vlan_access_id         SMALLINT UNSIGNED DEFAULT NULL,
    vlan_native_id         SMALLINT UNSIGNED DEFAULT NULL,
    vlan_primary_id        SMALLINT UNSIGNED DEFAULT NULL,
    vlan_allowed_list      VARCHAR(512)      DEFAULT NULL
                           COMMENT 'Comma separated, trunk mode only',

    isolation_mode         VARCHAR(32)       DEFAULT NULL,
    isolation_vlan_id      SMALLINT UNSIGNED DEFAULT NULL,
    allow_untagged         TINYINT(1)        DEFAULT NULL,

    -- --- Bandwidth ------------------------------------------------------
    vmq_weight             SMALLINT UNSIGNED DEFAULT NULL,
    iov_weight             SMALLINT UNSIGNED DEFAULT NULL,
    min_bandwidth_absolute BIGINT UNSIGNED   DEFAULT NULL COMMENT 'bits/s',
    min_bandwidth_weight   SMALLINT UNSIGNED DEFAULT NULL,
    max_bandwidth          BIGINT UNSIGNED   DEFAULT NULL COMMENT 'bits/s',

    ip_addresses           VARCHAR(512)      DEFAULT NULL
                           COMMENT 'Comma separated, reported by the guest',

    payload_hash           CHAR(64)          NOT NULL,

    first_seen             DATETIME          NOT NULL COMMENT 'UTC',
    last_seen              DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id           INT UNSIGNED      NOT NULL,
    last_run_id            INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_hv_vm_nic (integration_id, moref),
    KEY idx_hv_vm_nic_vm (integration_id, vm_moref),
    KEY idx_hv_vm_nic_switch (integration_id, switch_moref),
    KEY idx_hv_vm_nic_mac (mac_address),
    KEY idx_hv_vm_nic_vlan (integration_id, vlan_access_id),
    KEY idx_hv_vm_nic_last_seen (integration_id, last_seen),

    CONSTRAINT fk_hv_vm_nic_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_hv_vm_nic_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_hv_vm_nic_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: virtual machine network adapters, current state';


CREATE TABLE hv_vm_nic_change (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nic_id     INT UNSIGNED    NOT NULL,
    run_id     INT UNSIGNED    NOT NULL,
    changed_at DATETIME        NOT NULL COMMENT 'UTC',
    old_values JSON            NOT NULL,
    new_values JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_hv_vm_nic_change (nic_id, changed_at),
    KEY idx_hv_vm_nic_change_run (run_id),

    CONSTRAINT fk_hv_vm_nic_change_nic
        FOREIGN KEY (nic_id) REFERENCES hv_vm_nic (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_hv_vm_nic_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Change journal for virtual machine network adapters';


-- =======================================================================
-- Checkpoints
-- =======================================================================
--
-- parent_checkpoint_moref makes the tree readable without a second query,
-- and creation_time is what turns "there are checkpoints" into "there is a
-- checkpoint from eight months ago", which is the version worth reporting.
CREATE TABLE hv_vm_checkpoint (
    id                     INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id         SMALLINT UNSIGNED NOT NULL,

    moref                  VARCHAR(255)      NOT NULL COMMENT 'cp:<checkpoint guid>',

    vm_moref               VARCHAR(255)      NOT NULL,

    name                   VARCHAR(255)      NOT NULL,
    checkpoint_type        VARCHAR(32)       DEFAULT NULL
                           COMMENT 'Standard, Production, ProductionOnly',
    created_at_source      DATETIME          DEFAULT NULL COMMENT 'UTC',
    parent_checkpoint_moref VARCHAR(255)     DEFAULT NULL,
    parent_checkpoint_name VARCHAR(255)      DEFAULT NULL,
    checkpoint_path        VARCHAR(512)      DEFAULT NULL,
    config_location        VARCHAR(512)      DEFAULT NULL,

    payload_hash           CHAR(64)          NOT NULL,

    first_seen             DATETIME          NOT NULL COMMENT 'UTC',
    last_seen              DATETIME          NOT NULL COMMENT 'UTC',
    first_run_id           INT UNSIGNED      NOT NULL,
    last_run_id            INT UNSIGNED      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_hv_vm_checkpoint (integration_id, moref),
    KEY idx_hv_vm_checkpoint_vm (integration_id, vm_moref),
    KEY idx_hv_vm_checkpoint_age (integration_id, created_at_source),
    KEY idx_hv_vm_checkpoint_last_seen (integration_id, last_seen),

    CONSTRAINT fk_hv_vm_checkpoint_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_hv_vm_checkpoint_first_run
        FOREIGN KEY (first_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_hv_vm_checkpoint_last_run
        FOREIGN KEY (last_run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Inventory: virtual machine checkpoints, current state';


CREATE TABLE hv_vm_checkpoint_change (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    checkpoint_id INT UNSIGNED    NOT NULL,
    run_id        INT UNSIGNED    NOT NULL,
    changed_at    DATETIME        NOT NULL COMMENT 'UTC',
    old_values    JSON            NOT NULL,
    new_values    JSON            NOT NULL,

    PRIMARY KEY (id),
    KEY idx_hv_vm_checkpoint_change (checkpoint_id, changed_at),
    KEY idx_hv_vm_checkpoint_change_run (run_id),

    CONSTRAINT fk_hv_vm_checkpoint_change_cp
        FOREIGN KEY (checkpoint_id) REFERENCES hv_vm_checkpoint (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_hv_vm_checkpoint_change_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Change journal for virtual machine checkpoints';


-- =======================================================================
-- Import staging
-- =======================================================================
--
-- An uploaded file waits here until the set is complete. One run consumes
-- every staged file of its source at once, because sync() reconciles a whole
-- source: a run holding only one node's machines would mark every machine on
-- the other nodes as gone.
--
-- Not an inventory table, so it is not in ObjectType and nothing compares or
-- journals it. It is cleaned up with the integration, which is why the
-- foreign key cascades here where the inventory tables restrict: a staged
-- file has no value once its source is gone.
--
-- file_path is on disk rather than in the database. The payload is kept in
-- raw_object once imported, and a 2.5 MB upload has no business travelling
-- through a BLOB column twice.
CREATE TABLE hv_import_file (
    id             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    integration_id SMALLINT UNSIGNED NOT NULL,

    file_name      VARCHAR(255)      NOT NULL COMMENT 'As uploaded',
    file_path      VARCHAR(512)      NOT NULL COMMENT 'Where it was stored',
    byte_size      INT UNSIGNED      NOT NULL,
    sha256         CHAR(64)          NOT NULL COMMENT 'Guards against a double upload',

    -- From the envelope, so the staging list can be read without opening
    -- every file again.
    schema_version SMALLINT UNSIGNED DEFAULT NULL,
    script_version VARCHAR(16)       DEFAULT NULL,
    source_token   VARCHAR(64)       DEFAULT NULL,
    source_kind    VARCHAR(16)       DEFAULT NULL COMMENT 'cluster, standalone',
    collector_host VARCHAR(255)      DEFAULT NULL,
    cluster_guid   CHAR(36)          DEFAULT NULL,
    cluster_name   VARCHAR(128)      DEFAULT NULL,
    node_count     SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Nodes the file knows of',
    vm_count       SMALLINT UNSIGNED DEFAULT NULL,
    warning_count  SMALLINT UNSIGNED DEFAULT NULL,
    generated_at   DATETIME          DEFAULT NULL COMMENT 'UTC, from the envelope',

    uploaded_at    DATETIME          NOT NULL COMMENT 'UTC',

    status         ENUM('staged','imported','rejected') NOT NULL DEFAULT 'staged',
    reject_reason  VARCHAR(512)      DEFAULT NULL,
    run_id         INT UNSIGNED      DEFAULT NULL COMMENT 'The run that consumed it',

    PRIMARY KEY (id),

    -- The same file cannot be staged twice against one source.
    UNIQUE KEY uq_hv_import_file (integration_id, sha256),
    KEY idx_hv_import_file_status (integration_id, status, uploaded_at),
    KEY idx_hv_import_file_host (integration_id, collector_host),
    KEY idx_hv_import_file_run (run_id),

    CONSTRAINT fk_hv_import_file_integration
        FOREIGN KEY (integration_id) REFERENCES integration (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_hv_import_file_run
        FOREIGN KEY (run_id) REFERENCES discovery_run (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Uploaded Hyper-V collector files awaiting import';
