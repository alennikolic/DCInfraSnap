<#
    DCInfraSnap - Hyper-V offline collector
    ---------------------------------------
    Collects a read-only inventory snapshot of the LOCAL Hyper-V host and, if the
    host is a failover cluster member, of the cluster as seen from this node.
    The result is written to a single JSON file that is uploaded into DCInfraSnap.

    THIS SCRIPT ONLY READS. It uses Get-* cmdlets and CIM queries exclusively.
    It never changes a setting, never starts or stops a machine, and never
    contacts any host other than the one it runs on (no WinRM, no remoting).

    Run once on EVERY node of a cluster, and once on every standalone host.

    Requirements
      - Windows Server 2016 or newer with the Hyper-V role (PowerShell 5.1 is enough)
      - Elevated prompt (Run as Administrator) - without it virtual disk details
        and cluster data are unavailable
      - FailoverClusters module, only on cluster nodes

    Usage
      powershell -ExecutionPolicy Bypass -File .\dcinfrasnap-hyperv.ps1

      -OutputDirectory <path>  where to write the JSON file (default: script directory)
      -Token <string>          integration token; DCInfraSnap embeds it automatically
                               when the script is downloaded from the integration page
      -SkipDiskDetails         do not call Get-VHD per virtual disk (much faster on
                               hosts with many machines, but no disk size or type)
      -Quiet                   no progress output

    Output file: dcinfrasnap-hyperv-<hostname>-<yyyyMMdd-HHmmss>.json
#>

[CmdletBinding()]
param(
    [string]$OutputDirectory,
    [string]$Token,
    [switch]$SkipDiskDetails,
    [switch]$Quiet
)

Set-StrictMode -Off
$ErrorActionPreference = 'Stop'

$ScriptVersion = '1.0.0'
$SchemaVersion = 1

# DCInfraSnap replaces this placeholder with the integration token on download.
$EmbeddedToken = '__SOURCE_TOKEN__'

$script:Steps    = New-Object System.Collections.ArrayList
$script:Warnings = New-Object System.Collections.ArrayList
$script:Started  = Get-Date

# ---------------------------------------------------------------------------
# helpers
# ---------------------------------------------------------------------------

function Write-Info {
    param([string]$Message)
    if (-not $Quiet) { Write-Host $Message }
}

function Add-Warning {
    param([string]$Message)
    [void]$script:Warnings.Add($Message)
    if (-not $Quiet) { Write-Host "  ! $Message" -ForegroundColor Yellow }
}

function Get-Prop {
    # Safe property read. Hyper-V and cluster objects gained properties over the
    # years; on 2016 several of the ones used below simply do not exist.
    param($Object, [string]$Name, $Default = $null)
    if ($null -eq $Object) { return $Default }
    $prop = $Object.PSObject.Properties[$Name]
    if ($null -eq $prop) { return $Default }
    $value = $prop.Value
    if ($null -eq $value) { return $Default }
    return $value
}

function Get-IsoUtc {
    param($Value)
    if ($null -eq $Value) { return $null }
    try {
        $dt = [datetime]$Value
        return $dt.ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    } catch {
        return $null
    }
}

function Get-StringValue {
    param($Value)
    if ($null -eq $Value) { return $null }
    return [string]$Value
}

function Get-LongValue {
    param($Value)
    if ($null -eq $Value) { return $null }
    try { return [long]$Value } catch { return $null }
}

function Get-IntValue {
    param($Value)
    if ($null -eq $Value) { return $null }
    try { return [int]$Value } catch { return $null }
}

function Get-BoolValue {
    param($Value)
    if ($null -eq $Value) { return $null }
    try { return [bool]$Value } catch { return $null }
}

function Get-MacAddress {
    # Hyper-V returns MAC addresses as 00155D010203, netadapters as 00-15-5D-01-02-03.
    param($Value)
    if ($null -eq $Value) { return $null }
    $raw = ([string]$Value) -replace '[^0-9A-Fa-f]', ''
    if ($raw.Length -ne 12) { return [string]$Value }
    $parts = New-Object System.Collections.ArrayList
    for ($i = 0; $i -lt 12; $i += 2) { [void]$parts.Add($raw.Substring($i, 2).ToUpper()) }
    return ($parts -join ':')
}

function Get-StringList {
    # Always an ArrayList so ConvertTo-Json emits a JSON array, even for one item.
    param($Value)
    $list = New-Object System.Collections.ArrayList
    if ($null -eq $Value) { return $list }
    foreach ($item in @($Value)) {
        if ($null -ne $item -and ([string]$item).Length -gt 0) { [void]$list.Add([string]$item) }
    }
    return $list
}

function Invoke-Step {
    <#
        Runs one collection step, records its outcome, and never lets a failure
        stop the collection. The recorded outcome is what tells DCInfraSnap which
        cmdlet returned nothing - usually a permission problem, not a parser fault.
    #>
    param(
        [string]$Name,
        [scriptblock]$Script,
        [switch]$Optional
    )
    $entry = [ordered]@{
        step    = $Name
        status  = 'ok'
        count   = 0
        message = ''
    }
    $result = $null
    try {
        $result = & $Script
        $count = 0
        if ($null -ne $result) {
            if ($result -is [System.Collections.ICollection]) { $count = @($result).Count }
            else { $count = 1 }
        }
        $entry.count = $count
        if ($count -eq 0) { $entry.status = 'empty' }
    } catch {
        $entry.status  = 'error'
        $entry.message = $_.Exception.Message
        if ($Optional) {
            $entry.status = 'unavailable'
        } else {
            Add-Warning "$Name failed: $($_.Exception.Message)"
        }
        $result = $null
    }
    [void]$script:Steps.Add($entry)
    return $result
}

function Test-Administrator {
    try {
        $identity  = [Security.Principal.WindowsIdentity]::GetCurrent()
        $principal = New-Object Security.Principal.WindowsPrincipal($identity)
        return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
    } catch {
        return $false
    }
}

# ---------------------------------------------------------------------------
# preflight
# ---------------------------------------------------------------------------

Write-Info "DCInfraSnap Hyper-V collector $ScriptVersion"

$isAdmin = Test-Administrator
if (-not $isAdmin) {
    Add-Warning 'Not running as Administrator - virtual disk details and cluster data will be incomplete.'
}

$hyperVModule = Get-Module -ListAvailable -Name 'Hyper-V' | Select-Object -First 1
if ($null -eq $hyperVModule) {
    Write-Host 'ERROR: the Hyper-V PowerShell module is not present on this host.' -ForegroundColor Red
    Write-Host 'Run this script on a Hyper-V host, not on a management workstation.' -ForegroundColor Red
    exit 2
}
Import-Module Hyper-V -ErrorAction SilentlyContinue | Out-Null

$clusterModule = Get-Module -ListAvailable -Name 'FailoverClusters' | Select-Object -First 1
if ($null -ne $clusterModule) {
    Import-Module FailoverClusters -ErrorAction SilentlyContinue | Out-Null
}

$sourceToken = $Token
if ([string]::IsNullOrEmpty($sourceToken)) {
    if ($EmbeddedToken -eq ('__' + 'SOURCE_TOKEN__')) { $sourceToken = 'MANUAL' }
    else { $sourceToken = $EmbeddedToken }
}

if ([string]::IsNullOrEmpty($OutputDirectory)) {
    if ($PSScriptRoot) { $OutputDirectory = $PSScriptRoot }
    else { $OutputDirectory = (Get-Location).Path }
}
if (-not (Test-Path -LiteralPath $OutputDirectory)) {
    Write-Host "ERROR: output directory not found: $OutputDirectory" -ForegroundColor Red
    exit 2
}

# ---------------------------------------------------------------------------
# cluster
# ---------------------------------------------------------------------------

$clusterData = $null
$kind = 'standalone'

if ($null -ne $clusterModule) {
    $cluster = Invoke-Step -Name 'Get-Cluster' -Optional -Script { Get-Cluster -ErrorAction Stop }
    if ($null -ne $cluster) {
        $kind = 'cluster'
        Write-Info "Cluster: $(Get-Prop $cluster 'Name')"

        $nodes = @(Invoke-Step -Name 'Get-ClusterNode' -Script { Get-ClusterNode -ErrorAction Stop })
        $nodeList = New-Object System.Collections.ArrayList
        foreach ($node in $nodes) {
            [void]$nodeList.Add([ordered]@{
                name               = Get-StringValue (Get-Prop $node 'Name')
                id                 = Get-StringValue (Get-Prop $node 'Id')
                state              = Get-StringValue (Get-Prop $node 'State')
                drain_status       = Get-StringValue (Get-Prop $node 'DrainStatus')
                node_weight        = Get-IntValue    (Get-Prop $node 'NodeWeight')
                dynamic_weight     = Get-IntValue    (Get-Prop $node 'DynamicWeight')
                highest_version    = Get-StringValue (Get-Prop $node 'NodeHighestVersion')
                lowest_version     = Get-StringValue (Get-Prop $node 'NodeLowestVersion')
                status_information = Get-StringValue (Get-Prop $node 'StatusInformation')
            })
        }

        $quorum = Invoke-Step -Name 'Get-ClusterQuorum' -Optional -Script { Get-ClusterQuorum -ErrorAction Stop }

        $networks = @(Invoke-Step -Name 'Get-ClusterNetwork' -Optional -Script { Get-ClusterNetwork -ErrorAction Stop })
        $networkList = New-Object System.Collections.ArrayList
        foreach ($net in $networks) {
            [void]$networkList.Add([ordered]@{
                name         = Get-StringValue (Get-Prop $net 'Name')
                id           = Get-StringValue (Get-Prop $net 'Id')
                role         = Get-StringValue (Get-Prop $net 'Role')
                state        = Get-StringValue (Get-Prop $net 'State')
                address      = Get-StringValue (Get-Prop $net 'Address')
                address_mask = Get-StringValue (Get-Prop $net 'AddressMask')
                metric       = Get-LongValue   (Get-Prop $net 'Metric')
                ipv6_address = Get-StringValue (Get-Prop $net 'Ipv6Address')
            })
        }

        $volumes = @(Invoke-Step -Name 'Get-ClusterSharedVolume' -Optional -Script { Get-ClusterSharedVolume -ErrorAction Stop })
        $volumeList = New-Object System.Collections.ArrayList
        foreach ($csv in $volumes) {
            $info      = $null
            $infoArray = @(Get-Prop $csv 'SharedVolumeInfo')
            if ($infoArray.Count -gt 0) { $info = $infoArray[0] }
            $partition = Get-Prop $info 'Partition'

            [void]$volumeList.Add([ordered]@{
                name              = Get-StringValue (Get-Prop $csv 'Name')
                id                = Get-StringValue (Get-Prop $csv 'Id')
                state             = Get-StringValue (Get-Prop $csv 'State')
                owner_node        = Get-StringValue (Get-Prop $csv 'OwnerNode')
                friendly_path     = Get-StringValue (Get-Prop $info 'FriendlyVolumeName')
                fault_state       = Get-StringValue (Get-Prop $info 'FaultState')
                redirected_access = Get-BoolValue   (Get-Prop $info 'RedirectedAccess')
                maintenance_mode  = Get-BoolValue   (Get-Prop $info 'MaintenanceMode')
                volume_name       = Get-StringValue (Get-Prop $partition 'Name')
                file_system       = Get-StringValue (Get-Prop $partition 'FileSystem')
                size_bytes        = Get-LongValue   (Get-Prop $partition 'Size')
                used_bytes        = Get-LongValue   (Get-Prop $partition 'UsedSpace')
                free_bytes        = Get-LongValue   (Get-Prop $partition 'FreeSpace')
                percent_free      = Get-StringValue (Get-Prop $partition 'PercentFree')
            })
        }

        $clusterData = [ordered]@{
            name              = Get-StringValue (Get-Prop $cluster 'Name')
            id                = Get-StringValue (Get-Prop $cluster 'Id')
            domain            = Get-StringValue (Get-Prop $cluster 'Domain')
            functional_level  = Get-StringValue (Get-Prop $cluster 'ClusterFunctionalLevel')
            upgrade_version   = Get-StringValue (Get-Prop $cluster 'ClusterUpgradeVersion')
            quorum_type       = Get-StringValue (Get-Prop $quorum  'QuorumType')
            quorum_resource   = Get-StringValue (Get-Prop $quorum  'QuorumResource')
            shared_volumes_root = Get-StringValue (Get-Prop $cluster 'SharedVolumesRoot')
            node_count        = $nodeList.Count
            nodes             = $nodeList
            networks          = $networkList
            shared_volumes    = $volumeList
        }
    } else {
        Write-Info 'Not a cluster node (or the cluster service is unavailable) - collecting as standalone host.'
    }
} else {
    Write-Info 'FailoverClusters module not installed - collecting as standalone host.'
}

# ---------------------------------------------------------------------------
# host
# ---------------------------------------------------------------------------

Write-Info 'Collecting host...'

$vmHost      = Invoke-Step -Name 'Get-VMHost'                   -Script { Get-VMHost -ErrorAction Stop }
$computer    = Invoke-Step -Name 'Win32_ComputerSystem'         -Script { Get-CimInstance -ClassName Win32_ComputerSystem -ErrorAction Stop }
$product     = Invoke-Step -Name 'Win32_ComputerSystemProduct'  -Script { Get-CimInstance -ClassName Win32_ComputerSystemProduct -ErrorAction Stop }
$bios        = Invoke-Step -Name 'Win32_BIOS'                   -Script { Get-CimInstance -ClassName Win32_BIOS -ErrorAction Stop }
$os          = Invoke-Step -Name 'Win32_OperatingSystem'        -Script { Get-CimInstance -ClassName Win32_OperatingSystem -ErrorAction Stop }
$processors  = @(Invoke-Step -Name 'Win32_Processor'             -Script { Get-CimInstance -ClassName Win32_Processor -ErrorAction Stop })

$firstCpu = $null
if ($processors.Count -gt 0) { $firstCpu = $processors[0] }

$coresTotal   = 0
$logicalTotal = 0
foreach ($cpu in $processors) {
    $c = Get-IntValue (Get-Prop $cpu 'NumberOfCores')
    $l = Get-IntValue (Get-Prop $cpu 'NumberOfLogicalProcessors')
    if ($null -ne $c) { $coresTotal   += $c }
    if ($null -ne $l) { $logicalTotal += $l }
}

$uptimeSeconds = $null
$lastBoot = Get-Prop $os 'LastBootUpTime'
if ($null -ne $lastBoot) {
    try { $uptimeSeconds = [long]((Get-Date) - [datetime]$lastBoot).TotalSeconds } catch { $uptimeSeconds = $null }
}

# physical and virtual network adapters of the host
$netAdapters = @(Invoke-Step -Name 'Get-NetAdapter' -Optional -Script { Get-NetAdapter -IncludeHidden:$false -ErrorAction Stop })
$ipAddresses = @(Invoke-Step -Name 'Get-NetIPAddress' -Optional -Script { Get-NetIPAddress -ErrorAction Stop })

$ipByInterface = @{}
foreach ($ip in $ipAddresses) {
    $alias = Get-StringValue (Get-Prop $ip 'InterfaceAlias')
    if ([string]::IsNullOrEmpty($alias)) { continue }
    $family = Get-StringValue (Get-Prop $ip 'AddressFamily')
    $addr   = Get-StringValue (Get-Prop $ip 'IPAddress')
    $prefix = Get-IntValue    (Get-Prop $ip 'PrefixLength')
    if ([string]::IsNullOrEmpty($addr)) { continue }
    if (-not $ipByInterface.ContainsKey($alias)) {
        $ipByInterface[$alias] = New-Object System.Collections.ArrayList
    }
    [void]$ipByInterface[$alias].Add([ordered]@{
        address = $addr
        prefix  = $prefix
        family  = $family
    })
}

$nicList = New-Object System.Collections.ArrayList
foreach ($nic in $netAdapters) {
    $alias = Get-StringValue (Get-Prop $nic 'Name')
    $ips   = New-Object System.Collections.ArrayList
    if ($alias -and $ipByInterface.ContainsKey($alias)) { $ips = $ipByInterface[$alias] }

    [void]$nicList.Add([ordered]@{
        kind                  = 'pnic'
        name                  = $alias
        interface_description = Get-StringValue (Get-Prop $nic 'InterfaceDescription')
        interface_index       = Get-IntValue    (Get-Prop $nic 'InterfaceIndex')
        mac_address           = Get-MacAddress  (Get-Prop $nic 'MacAddress')
        status                = Get-StringValue (Get-Prop $nic 'Status')
        link_speed            = Get-StringValue (Get-Prop $nic 'LinkSpeed')
        speed_bps             = Get-LongValue   (Get-Prop $nic 'Speed')
        media_type            = Get-StringValue (Get-Prop $nic 'MediaType')
        full_duplex           = Get-BoolValue   (Get-Prop $nic 'FullDuplex')
        is_virtual            = Get-BoolValue   (Get-Prop $nic 'Virtual')
        driver_name           = Get-StringValue (Get-Prop $nic 'DriverFileName')
        driver_version        = Get-StringValue (Get-Prop $nic 'DriverVersion')
        vlan_id               = Get-IntValue    (Get-Prop $nic 'VlanID')
        ip_addresses          = $ips
    })
}

# NIC teams (LBFO). Switch embedded teaming is reported with the virtual switch.
$teams = @(Invoke-Step -Name 'Get-NetLbfoTeam' -Optional -Script { Get-NetLbfoTeam -ErrorAction Stop })
$teamList = New-Object System.Collections.ArrayList
foreach ($team in $teams) {
    [void]$teamList.Add([ordered]@{
        kind              = 'team'
        name              = Get-StringValue (Get-Prop $team 'Name')
        teaming_mode      = Get-StringValue (Get-Prop $team 'TeamingMode')
        load_balancing    = Get-StringValue (Get-Prop $team 'LoadBalancingAlgorithm')
        status            = Get-StringValue (Get-Prop $team 'Status')
        lacp_timer        = Get-StringValue (Get-Prop $team 'LacpTimer')
        members           = Get-StringList  (Get-Prop $team 'Members')
    })
}

# virtual switches
$switches = @(Invoke-Step -Name 'Get-VMSwitch' -Script { Get-VMSwitch -ErrorAction Stop })
$switchTeams = @(Invoke-Step -Name 'Get-VMSwitchTeam' -Optional -Script { Get-VMSwitchTeam -ErrorAction Stop })

$switchTeamByName = @{}
foreach ($st in $switchTeams) {
    $n = Get-StringValue (Get-Prop $st 'Name')
    if ($n) { $switchTeamByName[$n] = $st }
}

$switchList = New-Object System.Collections.ArrayList
foreach ($sw in $switches) {
    $name = Get-StringValue (Get-Prop $sw 'Name')
    $st = $null
    if ($name -and $switchTeamByName.ContainsKey($name)) { $st = $switchTeamByName[$name] }

    [void]$switchList.Add([ordered]@{
        name                        = $name
        id                          = Get-StringValue (Get-Prop $sw 'Id')
        switch_type                 = Get-StringValue (Get-Prop $sw 'SwitchType')
        allow_management_os         = Get-BoolValue   (Get-Prop $sw 'AllowManagementOS')
        embedded_teaming            = Get-BoolValue   (Get-Prop $sw 'EmbeddedTeamingEnabled')
        iov_enabled                 = Get-BoolValue   (Get-Prop $sw 'IovEnabled')
        iov_support                 = Get-BoolValue   (Get-Prop $sw 'IovSupport')
        bandwidth_reservation_mode  = Get-StringValue (Get-Prop $sw 'BandwidthReservationMode')
        default_flow_min_bandwidth_absolute = Get-LongValue (Get-Prop $sw 'DefaultFlowMinimumBandwidthAbsolute')
        default_flow_min_bandwidth_weight   = Get-LongValue (Get-Prop $sw 'DefaultFlowMinimumBandwidthWeight')
        net_adapter_interface_description   = Get-StringValue (Get-Prop $sw 'NetAdapterInterfaceDescription')
        net_adapter_interface_descriptions  = Get-StringList  (Get-Prop $sw 'NetAdapterInterfaceDescriptions')
        packet_direct_enabled       = Get-BoolValue   (Get-Prop $sw 'PacketDirectEnabled')
        team_teaming_mode           = Get-StringValue (Get-Prop $st 'TeamingMode')
        team_load_balancing         = Get-StringValue (Get-Prop $st 'LoadBalancingAlgorithm')
        notes                       = Get-StringValue (Get-Prop $sw 'Notes')
    })
}

# host virtual adapters (management OS vNICs)
$hostVnics = @(Invoke-Step -Name 'Get-VMNetworkAdapter -ManagementOS' -Optional -Script { Get-VMNetworkAdapter -ManagementOS -ErrorAction Stop })

$hostVnicList = New-Object System.Collections.ArrayList
foreach ($vnic in $hostVnics) {
    $vnicName = Get-StringValue (Get-Prop $vnic 'Name')

    # Asked per adapter rather than matched against a bulk result: the VLAN
    # setting object identifies its adapter differently across versions.
    $vlan = $null
    try { $vlan = Get-VMNetworkAdapterVlan -VMNetworkAdapter $vnic -ErrorAction Stop } catch { $vlan = $null }

    $alias = "vEthernet ($vnicName)"
    $ips = New-Object System.Collections.ArrayList
    if ($ipByInterface.ContainsKey($alias)) { $ips = $ipByInterface[$alias] }

    [void]$hostVnicList.Add([ordered]@{
        kind              = 'host_vnic'
        name              = $vnicName
        id                = Get-StringValue (Get-Prop $vnic 'Id')
        switch_name       = Get-StringValue (Get-Prop $vnic 'SwitchName')
        switch_id         = Get-StringValue (Get-Prop $vnic 'SwitchId')
        mac_address       = Get-MacAddress  (Get-Prop $vnic 'MacAddress')
        status            = Get-StringValue (Get-Prop $vnic 'Status')
        vlan_mode         = Get-StringValue (Get-Prop $vlan 'OperationMode')
        vlan_access_id    = Get-IntValue    (Get-Prop $vlan 'AccessVlanId')
        vlan_native_id    = Get-IntValue    (Get-Prop $vlan 'NativeVlanId')
        vlan_allowed_list = Get-StringList  (Get-Prop $vlan 'AllowedVlanIdList')
        ip_addresses      = $ips
        reported_ips      = Get-StringList  (Get-Prop $vnic 'IPAddresses')
    })
}

$replicaServer = Invoke-Step -Name 'Get-VMReplicationServer' -Optional -Script { Get-VMReplicationServer -ErrorAction Stop }

# On a workgroup host there may be no DNS at all, so this must never throw.
$fqdn = Get-StringValue $env:COMPUTERNAME
try {
    $resolved = [System.Net.Dns]::GetHostEntry($env:COMPUTERNAME).HostName
    if (-not [string]::IsNullOrEmpty($resolved)) { $fqdn = [string]$resolved }
} catch {
    $dnsDomain = Get-StringValue (Get-Prop $computer 'Domain')
    if ($dnsDomain -and (Get-BoolValue (Get-Prop $computer 'PartOfDomain'))) {
        $fqdn = "$env:COMPUTERNAME.$dnsDomain"
    }
}

$nodeData = [ordered]@{
    hostname              = Get-StringValue $env:COMPUTERNAME
    fqdn                  = $fqdn
    domain                = Get-StringValue (Get-Prop $computer 'Domain')
    part_of_domain        = Get-BoolValue   (Get-Prop $computer 'PartOfDomain')
    smbios_uuid           = Get-StringValue (Get-Prop $product  'UUID')
    vendor                = Get-StringValue (Get-Prop $computer 'Manufacturer')
    model                 = Get-StringValue (Get-Prop $computer 'Model')
    serial_number         = Get-StringValue (Get-Prop $bios     'SerialNumber')
    bios_version          = Get-StringValue (Get-Prop $bios     'SMBIOSBIOSVersion')
    bios_release_date     = Get-IsoUtc      (Get-Prop $bios     'ReleaseDate')
    os_caption            = Get-StringValue (Get-Prop $os 'Caption')
    os_version            = Get-StringValue (Get-Prop $os 'Version')
    os_build              = Get-StringValue (Get-Prop $os 'BuildNumber')
    os_architecture       = Get-StringValue (Get-Prop $os 'OSArchitecture')
    os_install_date       = Get-IsoUtc      (Get-Prop $os 'InstallDate')
    last_boot             = Get-IsoUtc      $lastBoot
    uptime_seconds        = $uptimeSeconds
    cpu_model             = Get-StringValue (Get-Prop $firstCpu 'Name')
    cpu_sockets           = $processors.Count
    cpu_cores             = $coresTotal
    cpu_logical           = $logicalTotal
    cpu_max_clock_mhz     = Get-IntValue    (Get-Prop $firstCpu 'MaxClockSpeed')
    memory_bytes          = Get-LongValue   (Get-Prop $computer 'TotalPhysicalMemory')
    hyperv_logical_processors = Get-IntValue (Get-Prop $vmHost 'LogicalProcessorCount')
    hyperv_memory_capacity    = Get-LongValue (Get-Prop $vmHost 'MemoryCapacity')
    virtual_machine_path      = Get-StringValue (Get-Prop $vmHost 'VirtualMachinePath')
    virtual_hard_disk_path    = Get-StringValue (Get-Prop $vmHost 'VirtualHardDiskPath')
    numa_spanning_enabled     = Get-BoolValue   (Get-Prop $vmHost 'NumaSpanningEnabled')
    enhanced_session_mode     = Get-BoolValue   (Get-Prop $vmHost 'EnableEnhancedSessionMode')
    migration_enabled         = Get-BoolValue   (Get-Prop $vmHost 'VirtualMachineMigrationEnabled')
    migration_auth_type       = Get-StringValue (Get-Prop $vmHost 'VirtualMachineMigrationAuthenticationType')
    migration_performance     = Get-StringValue (Get-Prop $vmHost 'VirtualMachineMigrationPerformanceOption')
    max_vm_migrations         = Get-IntValue    (Get-Prop $vmHost 'MaximumVirtualMachineMigrations')
    max_storage_migrations    = Get-IntValue    (Get-Prop $vmHost 'MaximumStorageMigrations')
    iov_support               = Get-BoolValue   (Get-Prop $vmHost 'IovSupport')
    mac_address_minimum       = Get-MacAddress  (Get-Prop $vmHost 'MacAddressMinimum')
    mac_address_maximum       = Get-MacAddress  (Get-Prop $vmHost 'MacAddressMaximum')
    replica_enabled           = Get-BoolValue   (Get-Prop $replicaServer 'ReplicationEnabled')
    replica_allowed_any       = Get-BoolValue   (Get-Prop $replicaServer 'ReplicationAllowedFromAnyServer')
    replica_auth_type         = Get-StringValue (Get-Prop $replicaServer 'AllowedAuthenticationType')
    replica_kerberos_port     = Get-IntValue    (Get-Prop $replicaServer 'KerberosAuthenticationPort')
    replica_certificate_port  = Get-IntValue    (Get-Prop $replicaServer 'CertificateAuthenticationPort')
    replica_storage_location  = Get-StringValue (Get-Prop $replicaServer 'DefaultStorageLocation')
    cluster_member            = ($kind -eq 'cluster')
    physical_nics             = $nicList
    nic_teams                 = $teamList
    virtual_switches          = $switchList
    host_vnics                = $hostVnicList
}

# ---------------------------------------------------------------------------
# guest KVP data (hostname / OS as reported by integration services)
# ---------------------------------------------------------------------------

$kvpByVmId = @{}
$kvpComponents = @(Invoke-Step -Name 'Msvm_KvpExchangeComponent' -Optional -Script {
    Get-CimInstance -Namespace 'root\virtualization\v2' -ClassName 'Msvm_KvpExchangeComponent' -ErrorAction Stop
})

foreach ($component in $kvpComponents) {
    $systemName = Get-StringValue (Get-Prop $component 'SystemName')
    if ([string]::IsNullOrEmpty($systemName)) { continue }
    $items = @(Get-Prop $component 'GuestIntrinsicExchangeItems')
    if ($items.Count -eq 0) { continue }

    $bag = @{}
    foreach ($item in $items) {
        if ([string]::IsNullOrEmpty([string]$item)) { continue }
        try {
            $xml = [xml]$item
            $name  = $null
            $value = $null
            foreach ($property in $xml.INSTANCE.PROPERTY) {
                $pn = [string]$property.NAME
                if ($pn -eq 'Name') { $name = [string]$property.VALUE }
                if ($pn -eq 'Data') { $value = [string]$property.VALUE }
            }
            if ($name) { $bag[$name] = $value }
        } catch {
            continue
        }
    }
    if ($bag.Count -gt 0) { $kvpByVmId[$systemName.ToUpper()] = $bag }
}

function Get-KvpValue {
    param([string]$VmId, [string]$Key)
    if ([string]::IsNullOrEmpty($VmId)) { return $null }
    $k = $VmId.ToUpper()
    if (-not $kvpByVmId.ContainsKey($k)) { return $null }
    $bag = $kvpByVmId[$k]
    if (-not $bag.ContainsKey($Key)) { return $null }
    return [string]$bag[$Key]
}

# ---------------------------------------------------------------------------
# virtual machines
# ---------------------------------------------------------------------------

Write-Info 'Collecting virtual machines...'

$vms = @(Invoke-Step -Name 'Get-VM' -Script { Get-VM -ErrorAction Stop })
Write-Info "  $($vms.Count) machine(s) registered on this node"

$vmList = New-Object System.Collections.ArrayList
$vmIndex = 0

foreach ($vm in $vms) {
    $vmIndex++
    $vmName = Get-StringValue (Get-Prop $vm 'Name')
    $vmId   = Get-StringValue (Get-Prop $vm 'Id')

    if (-not $Quiet) {
        $percent = [int](100 * $vmIndex / [Math]::Max($vms.Count, 1))
        Write-Progress -Activity 'DCInfraSnap: collecting virtual machines' -Status "$vmIndex / $($vms.Count): $vmName" -PercentComplete $percent
    }

    $uptime = Get-Prop $vm 'Uptime'
    $uptimeSec = $null
    if ($null -ne $uptime) {
        try { $uptimeSec = [long]$uptime.TotalSeconds } catch { $uptimeSec = $null }
    }

    # --- processor and memory -------------------------------------------------
    $cpu = $null
    $mem = $null
    try { $cpu = Get-VMProcessor -VM $vm -ErrorAction Stop } catch { $cpu = $null }
    try { $mem = Get-VMMemory    -VM $vm -ErrorAction Stop } catch { $mem = $null }

    # --- integration services ------------------------------------------------
    $isList = New-Object System.Collections.ArrayList
    try {
        $services = @(Get-VMIntegrationService -VM $vm -ErrorAction Stop)
        foreach ($service in $services) {
            [void]$isList.Add([ordered]@{
                name    = Get-StringValue (Get-Prop $service 'Name')
                enabled = Get-BoolValue   (Get-Prop $service 'Enabled')
                status  = Get-StringValue (Get-Prop $service 'PrimaryStatusDescription')
            })
        }
    } catch {
        [void]$script:Warnings.Add("Get-VMIntegrationService failed for '$vmName': $($_.Exception.Message)")
    }

    # --- firmware / bios -----------------------------------------------------
    $firmware = [ordered]@{
        secure_boot          = $null
        secure_boot_template = $null
        boot_order           = (New-Object System.Collections.ArrayList)
        num_lock             = $null
    }
    $generation = Get-IntValue (Get-Prop $vm 'Generation')
    try {
        if ($generation -eq 2) {
            $fw = Get-VMFirmware -VM $vm -ErrorAction Stop
            $firmware.secure_boot          = Get-StringValue (Get-Prop $fw 'SecureBoot')
            $firmware.secure_boot_template = Get-StringValue (Get-Prop $fw 'SecureBootTemplate')
            foreach ($entry in @(Get-Prop $fw 'BootOrder')) {
                [void]$firmware.boot_order.Add((Get-StringValue (Get-Prop $entry 'BootType')))
            }
        } else {
            $vmBios = Get-VMBios -VM $vm -ErrorAction Stop
            $firmware.num_lock = Get-BoolValue (Get-Prop $vmBios 'NumLockEnabled')
            foreach ($entry in @(Get-Prop $vmBios 'StartupOrder')) {
                [void]$firmware.boot_order.Add((Get-StringValue $entry))
            }
        }
    } catch {
        # firmware details are a nice-to-have, never a reason to lose the machine
    }

    # --- disks ---------------------------------------------------------------
    $diskList = New-Object System.Collections.ArrayList
    try {
        $drives = @(Get-VMHardDiskDrive -VM $vm -ErrorAction Stop)
        foreach ($drive in $drives) {
            $path = Get-StringValue (Get-Prop $drive 'Path')

            $disk = [ordered]@{
                controller_type     = Get-StringValue (Get-Prop $drive 'ControllerType')
                controller_number   = Get-IntValue    (Get-Prop $drive 'ControllerNumber')
                controller_location = Get-IntValue    (Get-Prop $drive 'ControllerLocation')
                path                = $path
                disk_number         = Get-IntValue    (Get-Prop $drive 'DiskNumber')
                is_pass_through     = ($null -ne (Get-Prop $drive 'DiskNumber'))
                supports_pr         = Get-BoolValue   (Get-Prop $drive 'SupportPersistentReservations')
                maximum_iops        = Get-LongValue   (Get-Prop $drive 'MaximumIOPS')
                minimum_iops        = Get-LongValue   (Get-Prop $drive 'MinimumIOPS')
                write_hardening     = Get-BoolValue   (Get-Prop $drive 'WriteHardeningEnabled')
                is_shared           = $false
                vhd_format          = $null
                vhd_type            = $null
                size_bytes          = $null
                file_size_bytes     = $null
                parent_path         = $null
                block_size          = $null
                logical_sector_size = $null
                physical_sector_size = $null
                fragmentation_pct   = $null
                disk_identifier     = $null
                vhd_error           = $null
            }

            # A shared VHDX or a VHD Set is what makes a guest cluster; both are
            # detected here rather than guessed from the extension alone.
            $isShared = Get-BoolValue (Get-Prop $drive 'SupportPersistentReservations')
            if ($null -eq $isShared) { $isShared = $false }
            if ($path -and $path.ToLower().EndsWith('.vhds')) { $isShared = $true }
            $disk.is_shared = $isShared

            if (-not $SkipDiskDetails -and $path -and -not $disk.is_pass_through) {
                try {
                    $vhd = Get-VHD -Path $path -ErrorAction Stop
                    $disk.vhd_format           = Get-StringValue (Get-Prop $vhd 'VhdFormat')
                    $disk.vhd_type             = Get-StringValue (Get-Prop $vhd 'VhdType')
                    $disk.size_bytes           = Get-LongValue   (Get-Prop $vhd 'Size')
                    $disk.file_size_bytes      = Get-LongValue   (Get-Prop $vhd 'FileSize')
                    $disk.parent_path          = Get-StringValue (Get-Prop $vhd 'ParentPath')
                    $disk.block_size           = Get-LongValue   (Get-Prop $vhd 'BlockSize')
                    $disk.logical_sector_size  = Get-LongValue   (Get-Prop $vhd 'LogicalSectorSize')
                    $disk.physical_sector_size = Get-LongValue   (Get-Prop $vhd 'PhysicalSectorSize')
                    $disk.fragmentation_pct    = Get-IntValue    (Get-Prop $vhd 'FragmentationPercentage')
                    $disk.disk_identifier      = Get-StringValue (Get-Prop $vhd 'DiskIdentifier')
                } catch {
                    $disk.vhd_error = $_.Exception.Message
                }
            }

            [void]$diskList.Add($disk)
        }
    } catch {
        [void]$script:Warnings.Add("Get-VMHardDiskDrive failed for '$vmName': $($_.Exception.Message)")
    }

    # --- dvd drives ----------------------------------------------------------
    $dvdList = New-Object System.Collections.ArrayList
    try {
        foreach ($dvd in @(Get-VMDvdDrive -VM $vm -ErrorAction Stop)) {
            [void]$dvdList.Add([ordered]@{
                controller_number   = Get-IntValue    (Get-Prop $dvd 'ControllerNumber')
                controller_location = Get-IntValue    (Get-Prop $dvd 'ControllerLocation')
                path                = Get-StringValue (Get-Prop $dvd 'Path')
            })
        }
    } catch {
        # no DVD drive on generation 2 machines is normal
    }

    # --- network adapters ----------------------------------------------------
    $nicListVm = New-Object System.Collections.ArrayList
    try {
        $adapters = @(Get-VMNetworkAdapter -VM $vm -ErrorAction Stop)
        foreach ($adapter in $adapters) {
            $vlan = $null
            try { $vlan = Get-VMNetworkAdapterVlan -VMNetworkAdapter $adapter -ErrorAction Stop } catch { $vlan = $null }

            $isolation = $null
            try { $isolation = Get-VMNetworkAdapterIsolation -VMNetworkAdapter $adapter -ErrorAction Stop } catch { $isolation = $null }

            $bandwidth = Get-Prop $adapter 'BandwidthSetting'

            [void]$nicListVm.Add([ordered]@{
                name                    = Get-StringValue (Get-Prop $adapter 'Name')
                id                      = Get-StringValue (Get-Prop $adapter 'Id')
                mac_address             = Get-MacAddress  (Get-Prop $adapter 'MacAddress')
                dynamic_mac             = Get-BoolValue   (Get-Prop $adapter 'DynamicMacAddressEnabled')
                switch_name             = Get-StringValue (Get-Prop $adapter 'SwitchName')
                switch_id               = Get-StringValue (Get-Prop $adapter 'SwitchId')
                connected               = Get-BoolValue   (Get-Prop $adapter 'Connected')
                status                  = Get-StringValue (Get-Prop $adapter 'Status')
                is_legacy               = Get-BoolValue   (Get-Prop $adapter 'IsLegacy')
                device_naming           = Get-StringValue (Get-Prop $adapter 'DeviceNaming')
                mac_spoofing            = Get-StringValue (Get-Prop $adapter 'MacAddressSpoofing')
                dhcp_guard              = Get-StringValue (Get-Prop $adapter 'DhcpGuard')
                router_guard            = Get-StringValue (Get-Prop $adapter 'RouterGuard')
                port_mirroring          = Get-StringValue (Get-Prop $adapter 'PortMirroringMode')
                vmq_weight              = Get-IntValue    (Get-Prop $adapter 'VmqWeight')
                iov_weight              = Get-IntValue    (Get-Prop $adapter 'IovWeight')
                min_bandwidth_absolute  = Get-LongValue   (Get-Prop $bandwidth 'MinimumBandwidthAbsolute')
                min_bandwidth_weight    = Get-LongValue   (Get-Prop $bandwidth 'MinimumBandwidthWeight')
                max_bandwidth           = Get-LongValue   (Get-Prop $bandwidth 'MaximumBandwidth')
                vlan_mode               = Get-StringValue (Get-Prop $vlan 'OperationMode')
                vlan_access_id          = Get-IntValue    (Get-Prop $vlan 'AccessVlanId')
                vlan_native_id          = Get-IntValue    (Get-Prop $vlan 'NativeVlanId')
                vlan_allowed_list       = Get-StringList  (Get-Prop $vlan 'AllowedVlanIdList')
                vlan_primary_id         = Get-IntValue    (Get-Prop $vlan 'PrimaryVlanId')
                isolation_mode          = Get-StringValue (Get-Prop $isolation 'IsolationMode')
                isolation_vlan_id       = Get-IntValue    (Get-Prop $isolation 'DefaultIsolationID')
                allow_untagged          = Get-BoolValue   (Get-Prop $isolation 'AllowUntaggedTraffic')
                ip_addresses            = Get-StringList  (Get-Prop $adapter 'IPAddresses')
            })
        }
    } catch {
        [void]$script:Warnings.Add("Get-VMNetworkAdapter failed for '$vmName': $($_.Exception.Message)")
    }

    # --- fibre channel adapters ---------------------------------------------
    $fcList = New-Object System.Collections.ArrayList
    try {
        foreach ($hba in @(Get-VMFibreChannelHba -VM $vm -ErrorAction Stop)) {
            [void]$fcList.Add([ordered]@{
                san_name       = Get-StringValue (Get-Prop $hba 'SanName')
                wwnn_a         = Get-StringValue (Get-Prop $hba 'WorldWideNodeNameSetA')
                wwpn_a         = Get-StringValue (Get-Prop $hba 'WorldWidePortNameSetA')
                wwnn_b         = Get-StringValue (Get-Prop $hba 'WorldWideNodeNameSetB')
                wwpn_b         = Get-StringValue (Get-Prop $hba 'WorldWidePortNameSetB')
            })
        }
    } catch {
        # virtual fibre channel is rarely configured
    }

    # --- checkpoints ---------------------------------------------------------
    $checkpointList = New-Object System.Collections.ArrayList
    try {
        foreach ($snapshot in @(Get-VMSnapshot -VM $vm -ErrorAction Stop)) {
            [void]$checkpointList.Add([ordered]@{
                id                   = Get-StringValue (Get-Prop $snapshot 'Id')
                name                 = Get-StringValue (Get-Prop $snapshot 'Name')
                checkpoint_type      = Get-StringValue (Get-Prop $snapshot 'SnapshotType')
                creation_time        = Get-IsoUtc      (Get-Prop $snapshot 'CreationTime')
                parent_checkpoint_id = Get-StringValue (Get-Prop $snapshot 'ParentSnapshotId')
                parent_checkpoint    = Get-StringValue (Get-Prop $snapshot 'ParentSnapshotName')
                path                 = Get-StringValue (Get-Prop $snapshot 'Path')
                config_location      = Get-StringValue (Get-Prop $snapshot 'ConfigurationLocation')
            })
        }
    } catch {
        [void]$script:Warnings.Add("Get-VMSnapshot failed for '$vmName': $($_.Exception.Message)")
    }

    # --- replication ---------------------------------------------------------
    $replication = [ordered]@{
        state                 = Get-StringValue (Get-Prop $vm 'ReplicationState')
        health                = Get-StringValue (Get-Prop $vm 'ReplicationHealth')
        mode                  = Get-StringValue (Get-Prop $vm 'ReplicationMode')
        role                  = $null
        primary_server        = $null
        replica_server        = $null
        replica_port          = $null
        auth_type             = $null
        frequency_seconds     = $null
        last_replication_time = $null
        recovery_history      = $null
        vss_enabled           = $null
        replicated_disk_count = $null
    }
    $replState = Get-StringValue (Get-Prop $vm 'ReplicationState')
    if ($replState -and $replState -ne 'Disabled') {
        try {
            $repl = Get-VMReplication -VM $vm -ErrorAction Stop
            $replication.role                  = Get-StringValue (Get-Prop $repl 'ReplicationRelationshipType')
            $replication.primary_server        = Get-StringValue (Get-Prop $repl 'PrimaryServerName')
            $replication.replica_server        = Get-StringValue (Get-Prop $repl 'ReplicaServerName')
            $replication.replica_port          = Get-IntValue    (Get-Prop $repl 'ReplicaServerPort')
            $replication.auth_type             = Get-StringValue (Get-Prop $repl 'AuthenticationType')
            $replication.frequency_seconds     = Get-IntValue    (Get-Prop $repl 'ReplicationFrequencySec')
            $replication.last_replication_time = Get-IsoUtc      (Get-Prop $repl 'LastReplicationTime')
            $replication.recovery_history      = Get-IntValue    (Get-Prop $repl 'RecoveryHistory')
            $replication.vss_enabled           = Get-BoolValue   (Get-Prop $repl 'VSSSnapshotReplicationEnabled')
            $replication.replicated_disk_count = @(Get-Prop $repl 'ReplicatedDisks').Count
            if ([string]::IsNullOrEmpty($replication.role)) {
                $replication.role = Get-StringValue (Get-Prop $repl 'Relationship')
            }
        } catch {
            [void]$script:Warnings.Add("Get-VMReplication failed for '$vmName': $($_.Exception.Message)")
        }
    }

    # --- the machine itself --------------------------------------------------
    [void]$vmList.Add([ordered]@{
        vm_id                     = $vmId
        name                      = $vmName
        state                     = Get-StringValue (Get-Prop $vm 'State')
        status                    = Get-StringValue (Get-Prop $vm 'Status')
        generation                = $generation
        config_version            = Get-StringValue (Get-Prop $vm 'Version')
        owner_node                = Get-StringValue $env:COMPUTERNAME
        is_clustered              = Get-BoolValue   (Get-Prop $vm 'IsClustered')
        uptime_seconds            = $uptimeSec
        cpu_usage_percent         = Get-IntValue    (Get-Prop $vm 'CPUUsage')
        processor_count           = Get-IntValue    (Get-Prop $vm 'ProcessorCount')
        memory_assigned           = Get-LongValue   (Get-Prop $vm 'MemoryAssigned')
        memory_demand             = Get-LongValue   (Get-Prop $vm 'MemoryDemand')
        memory_startup            = Get-LongValue   (Get-Prop $vm 'MemoryStartup')
        memory_minimum            = Get-LongValue   (Get-Prop $vm 'MemoryMinimum')
        memory_maximum            = Get-LongValue   (Get-Prop $vm 'MemoryMaximum')
        dynamic_memory_enabled    = Get-BoolValue   (Get-Prop $vm 'DynamicMemoryEnabled')
        memory_buffer_percent     = Get-IntValue    (Get-Prop $mem 'Buffer')
        memory_priority           = Get-IntValue    (Get-Prop $mem 'Priority')
        cpu_reserve_percent       = Get-IntValue    (Get-Prop $cpu 'Reserve')
        cpu_limit_percent         = Get-IntValue    (Get-Prop $cpu 'Maximum')
        cpu_relative_weight       = Get-IntValue    (Get-Prop $cpu 'RelativeWeight')
        cpu_compat_for_migration  = Get-BoolValue   (Get-Prop $cpu 'CompatibilityForMigrationEnabled')
        cpu_nested_virtualization = Get-BoolValue   (Get-Prop $cpu 'ExposeVirtualizationExtensions')
        cpu_threads_per_core      = Get-IntValue    (Get-Prop $cpu 'HwThreadCountPerCore')
        integration_services_version = Get-StringValue (Get-Prop $vm 'IntegrationServicesVersion')
        integration_services      = $isList
        automatic_start_action    = Get-StringValue (Get-Prop $vm 'AutomaticStartAction')
        automatic_start_delay     = Get-IntValue    (Get-Prop $vm 'AutomaticStartDelay')
        automatic_stop_action     = Get-StringValue (Get-Prop $vm 'AutomaticStopAction')
        automatic_critical_action = Get-StringValue (Get-Prop $vm 'AutomaticCriticalErrorAction')
        automatic_checkpoints     = Get-BoolValue   (Get-Prop $vm 'AutomaticCheckpointsEnabled')
        checkpoint_type           = Get-StringValue (Get-Prop $vm 'CheckpointType')
        configuration_location    = Get-StringValue (Get-Prop $vm 'ConfigurationLocation')
        snapshot_file_location    = Get-StringValue (Get-Prop $vm 'SnapshotFileLocation')
        smart_paging_file_path    = Get-StringValue (Get-Prop $vm 'SmartPagingFilePath')
        path                      = Get-StringValue (Get-Prop $vm 'Path')
        creation_time             = Get-IsoUtc      (Get-Prop $vm 'CreationTime')
        notes                     = Get-StringValue (Get-Prop $vm 'Notes')
        guest_os_name             = Get-KvpValue $vmId 'OSName'
        guest_os_version          = Get-KvpValue $vmId 'OSVersion'
        guest_fqdn                = Get-KvpValue $vmId 'FullyQualifiedDomainName'
        guest_csd_version         = Get-KvpValue $vmId 'CSDVersion'
        firmware                  = $firmware
        disks                     = $diskList
        dvd_drives                = $dvdList
        network_adapters          = $nicListVm
        fibre_channel_adapters    = $fcList
        checkpoints               = $checkpointList
        replication               = $replication
    })
}

if (-not $Quiet) { Write-Progress -Activity 'DCInfraSnap: collecting virtual machines' -Completed }

# ---------------------------------------------------------------------------
# assemble and write
# ---------------------------------------------------------------------------

$duration = [math]::Round(((Get-Date) - $script:Started).TotalSeconds, 1)

$root = [ordered]@{
    envelope = [ordered]@{
        product            = 'DCInfraSnap'
        collector          = 'hyperv-offline'
        schema_version     = $SchemaVersion
        script_version     = $ScriptVersion
        source_token       = $sourceToken
        kind               = $kind
        generated_at       = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
        generated_at_local = (Get-Date).ToString('yyyy-MM-ddTHH:mm:sszzz')
        collector_host     = Get-StringValue $env:COMPUTERNAME
        powershell_version = Get-StringValue $PSVersionTable.PSVersion.ToString()
        hyperv_module_version = Get-StringValue (Get-Prop $hyperVModule 'Version')
        run_as_admin       = $isAdmin
        disk_details       = (-not $SkipDiskDetails)
        duration_seconds   = $duration
        vm_count           = $vmList.Count
    }
    cluster    = $clusterData
    node       = $nodeData
    vms        = $vmList
    warnings   = $script:Warnings
    collection = $script:Steps
}

$fileName = 'dcinfrasnap-hyperv-{0}-{1}.json' -f $env:COMPUTERNAME, (Get-Date).ToString('yyyyMMdd-HHmmss')
$filePath = Join-Path -Path $OutputDirectory -ChildPath $fileName

$json = ConvertTo-Json -InputObject $root -Depth 12

# PowerShell 5.1 writes a BOM with Out-File -Encoding UTF8, which breaks strict
# JSON parsers. Write the bytes without one.
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($filePath, $json, $utf8NoBom)

$sizeKb = [math]::Round((Get-Item -LiteralPath $filePath).Length / 1KB, 1)

Write-Info ''
Write-Info '--- summary -------------------------------------------------'
Write-Info ("  kind            : {0}" -f $kind)
if ($null -ne $clusterData) {
    Write-Info ("  cluster         : {0} ({1} nodes, {2} CSV)" -f $clusterData.name, $clusterData.node_count, $clusterData.shared_volumes.Count)
}
Write-Info ("  node            : {0}" -f $nodeData.hostname)
Write-Info ("  virtual machines: {0}" -f $vmList.Count)
Write-Info ("  virtual switches: {0}" -f $switchList.Count)
Write-Info ("  warnings        : {0}" -f $script:Warnings.Count)
Write-Info ("  duration        : {0}s" -f $duration)
Write-Info ("  output          : {0} ({1} KB)" -f $filePath, $sizeKb)
Write-Info '-------------------------------------------------------------'

if ($script:Warnings.Count -gt 0) {
    Write-Info ''
    Write-Info 'Warnings:'
    foreach ($warning in $script:Warnings) { Write-Info ("  - {0}" -f $warning) }
}

exit 0
