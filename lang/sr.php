<?php
// lang/sr.php
//
// Srpski (latinica).
//
// Ključevi se ne prevode — prevode se samo vrednosti. Stanje prevoda se
// proverava sa:
//   docker compose exec app php bin/dcinfrasnap lang:check
//
// Ključ koji ovde nedostaje se povlači iz engleskog, pa nepotpun prevod i
// dalje daje upotrebljiv interfejs.
//
// Tehnički izrazi koje sistem inženjeri u praksi koriste u originalu (host,
// cluster, datastore, template) ostavljeni su kako jesu. Prevod koji niko
// ne koristi otežava snalaženje umesto da ga olakša.

declare(strict_types=1);

return [
    'language.name' => 'Srpski',

    // --- Aplikacija ------------------------------------------------------
    'app.name'    => 'DCInfraSnap',
    'app.tagline' => 'Inventar data centra, prikupljen i sačuvan',

    // --- Navigacija ------------------------------------------------------
    'nav.overview'     => 'Pregled',
    'nav.vms'          => 'Virtuelne mašine',
    'nav.hosts'        => 'Hostovi',
    'nav.clusters'     => 'Klasteri',
    'nav.datastores'   => 'Datastore-ovi',
    'nav.licenses'     => 'Licence',
    'nav.runs'         => 'Prikupljanja',
    'nav.integrations' => 'Integracije',
    'nav.database'     => 'Baza',
    'nav.logout'       => 'Odjava',
    'nav.language'     => 'Jezik',

    // --- Zajedničko ------------------------------------------------------
    'common.actions' => 'Radnje',
    'common.add'     => 'Dodaj',
    'common.back'    => 'Nazad',
    'common.cancel'  => 'Odustani',
    'common.delete'  => 'Obriši',
    'common.edit'    => 'Izmeni',
    'common.name'    => 'Naziv',
    'common.host'    => 'Host',
    'common.cluster' => 'Klaster',
    'common.never'   => 'Nikada',
    'common.no'      => 'Ne',
    'common.none'    => 'Nema',
    'common.save'    => 'Sačuvaj',
    'common.search'  => 'Pretraži',
    'common.status'  => 'Stanje',
    'common.unknown' => 'Nepoznato',
    'common.yes'     => 'Da',

    // --- Prijava ---------------------------------------------------------
    'login.title'          => 'Prijava',
    'login.password'       => 'Lozinka',
    'login.submit'         => 'Prijavi se',
    'login.failed'         => 'Pogrešna lozinka.',
    'login.rate_limited'   => 'Previše pokušaja. Pokušaj ponovo za koji trenutak.',
    'login.not_configured' => 'Interfejs je zatvoren jer lozinka nije postavljena.',
    'login.setup_hint'     => 'Generiši hash sa „docker compose run --rm app php bin/dcinfrasnap password:hash", '
                              . 'upiši ga u APP_PASSWORD_HASH u .env (pod jednostrukim navodnicima), '
                              . 'pa pokreni „docker compose up -d".',

    // --- Pregled ---------------------------------------------------------
    'overview.title'           => 'Pregled',
    'overview.subtitle'        => 'Šta je prikupljeno i odakle',
    'overview.no_integrations' => 'Nijedan izvor još nije dodat.',
    'overview.add_first'       => 'Dodaj vCenter da bi prikupljanje počelo.',
    'overview.counts'          => 'Prikupljeni objekti',
    'overview.last_collection' => 'Poslednje prikupljanje',
    'overview.sources'         => 'Izvori',

    // --- Izvori ----------------------------------------------------------
    'integrations.subtitle'    => 'Sistemi sa kojih DCInfraSnap prikuplja podatke',
    'integrations.add_vcenter' => 'Dodaj vCenter',
    'integrations.none'        => 'Nijedan izvor još nije dodat.',
    'integrations.none_hint'   => 'Dodaj vCenter i DCInfraSnap može da počne da prikuplja sa njega.',

    'integrations.address'   => 'Adresa',
    'integrations.hostname'  => 'Adresa ili IP',
    'integrations.port'      => 'Port',
    'integrations.username'  => 'Korisničko ime',
    'integrations.password'  => 'Lozinka',
    'integrations.version'   => 'Verzija',
    'integrations.last_test' => 'Veza',

    'integrations.name_placeholder'  => 'vcenter-01',
    'integrations.name_hint'         => 'Prikazuje se u spiskovima i izveštajima. Bilo koja oznaka koja tebi znači.',
    'integrations.username_hint'     => 'Dovoljan je nalog sa pravom samo za čitanje. '
                                        . 'DCInfraSnap nikada ne upisuje u vCenter.',
    'integrations.password_hint'     => 'Čuva se šifrovano pomoću SECRET_KEY, nikada kao čist tekst.',
    'integrations.password_separate' => 'Lozinka se ovde ne prikazuje.',
    'integrations.verify_peer'       => 'Proveri TLS sertifikat',
    'integrations.verify_peer_hint'  => 'Ostavi isključeno za vCenter sa samopotpisanim sertifikatom.',
    'integrations.form_subtitle'     => 'Podaci za povezivanje na jedan vCenter.',
    'integrations.credentials_note'  => 'Kredencijali se šifruju pomoću SECRET_KEY i dešifruju tek u trenutku '
                                        . 'prijave na vCenter. Više se nigde ne prikazuju.',

    'integrations.collect'              => 'Prikupi',
    'integrations.queued'               => ':name je u redu čekanja kao posao :run. '
                                           . 'Worker ga preuzima za nekoliko sekundi.',
    'integrations.test'                 => 'Proveri',
    'integrations.test_ok'              => 'OK',
    'integrations.test_failed'          => 'Greška',
    'integrations.untested'             => 'Nije provereno',
    'integrations.test_ok_message'      => 'povezan na :version',
    'integrations.change_password'      => 'Promeni lozinku',
    'integrations.new_password'         => 'Nova lozinka',
    'integrations.repeat_password'      => 'Ponovi lozinku',
    'integrations.password_title'       => 'Lozinka za :name',
    'integrations.password_saved'       => 'Lozinka je sačuvana.',
    'integrations.password_change_note' => 'Stara lozinka se prepisuje i ne može se vratiti.',

    'integrations.edit_title'   => 'Izmena: :name',
    'integrations.added'        => ':name je dodat.',
    'integrations.added_hint'   => 'Pokreni proveru veze da potvrdiš da kredencijali rade.',
    'integrations.saved'        => 'Izmene su sačuvane.',
    'integrations.enable'       => 'Uključi',
    'integrations.disable'      => 'Isključi',
    'integrations.disabled'     => 'Isključen',
    'integrations.enabled_now'  => ':name je uključen.',
    'integrations.disabled_now' => ':name je isključen.',

    'integrations.delete_title'        => 'Obrisati :name?',
    'integrations.delete_warning'      => 'Ovim se briše i sve što je prikupljeno sa ovog izvora:',
    'integrations.delete_empty'        => 'Sa ovog izvora još ništa nije prikupljeno.',
    'integrations.delete_irreversible' => 'Ovo se ne može poništiti.',
    'integrations.delete_confirm'      => 'Obriši trajno',
    'integrations.deleted'             => ':name je obrisan.',

    'integrations.error_name'           => 'Naziv je obavezan.',
    'integrations.error_hostname'       => 'Adresa ili IP su obavezni.',
    'integrations.error_hostname_url'   => 'Unesi samo adresu, bez https:// — na primer vcenter.primer.local.',
    'integrations.error_port'           => 'Port mora biti između 1 i 65535.',
    'integrations.error_username'       => 'Korisničko ime je obavezno.',
    'integrations.error_password'       => 'Lozinka je obavezna.',
    'integrations.error_password_match' => 'Lozinke se ne poklapaju.',

    // --- Spiskovi --------------------------------------------------------
    'list.showing'            => 'Prikazano: :count',
    'list.nothing_found'      => 'Ništa ne odgovara zadatim filterima.',
    'list.nothing_found_hint' => 'Probaj širu pretragu ili ukloni filtere.',
    'list.nothing_yet'        => 'Još ništa nije prikupljeno.',
    'list.collect_first'      => 'Pokreni prikupljanje i ova stranica će se popuniti.',
    'list.export_csv'         => 'Izvezi CSV',
    'list.first_seen'         => 'Prvi put viđeno',
    'list.last_seen'          => 'Poslednji put viđeno',
    'list.missing'            => 'Nedostaje',
    'list.missing_hint'       => 'Nije viđeno u poslednjem kompletnom prikupljanju sa ovog izvora.',
    'list.previous'           => 'Prethodna',
    'list.next'               => 'Sledeća',
    'list.page_of'            => 'Strana :page od :pages',

    'filter.all_sources'  => 'Svi izvori',
    'filter.any_power'    => 'Bilo koje stanje',
    'filter.any_cluster'  => 'Bilo koji klaster',
    'filter.any_host'     => 'Bilo koji host',
    'filter.any_kind'     => 'Mašine i template-i',
    'filter.missing_only' => 'Samo ono što nedostaje',
    'filter.clear'        => 'Poništi',

    // --- Virtuelne mašine ------------------------------------------------
    'vms.search_placeholder' => 'Naziv, hostname, adresa, UUID ili beleške',
    'vms.power'              => 'Napajanje',
    'vms.guest'              => 'Gost',
    'vms.address'            => 'Adresa',
    'vms.hostname'           => 'Hostname',
    'vms.cpu'                => 'vCPU',
    'vms.cores_per_socket'   => 'Jezgara po soketu',
    'vms.memory'             => 'Memorija',
    'vms.cpu_ram'            => 'CPU / RAM',
    'vms.disk'               => 'Disk',
    'vms.network'            => 'Mreža',
    'vms.disks'              => 'Diskovi',
    'vms.disk_space'         => 'Prostor na disku',
    'vms.nics'               => 'Mrežne kartice',
    'vms.template'           => 'Template',
    'vms.templates_only'     => 'Samo template-i',
    'vms.machines_only'      => 'Samo mašine',
    'vms.identity'           => 'Identifikatori',
    'vms.resources'          => 'Resursi',
    'vms.placement'          => 'Smeštaj',
    'vms.notes'              => 'Beleške',
    'vms.folder'             => 'Folder',
    'vms.moref'              => 'Objekat u vCenteru',
    'vms.instance_uuid'      => 'Instance UUID',
    'vms.bios_uuid'          => 'BIOS UUID',
    'vms.hardware_version'   => 'Verzija hardvera',
    'vms.guest_configured'   => 'OS podešen u vCenteru',
    'vms.guest_reported'     => 'OS koji prijavljuje gost',
    'vms.guest_state'        => 'Stanje gosta',
    'vms.tools'              => 'VMware Tools',

    // --- Hostovi ---------------------------------------------------------
    'hosts.state'       => 'Stanje',
    'hosts.hardware'    => 'Hardver',
    'hosts.capacity'    => 'Kapacitet',
    'hosts.software'    => 'Softver',
    'hosts.cpu'         => 'Jezgra / soketi',
    'hosts.cpu_model'   => 'Procesor',
    'hosts.sockets'     => 'Soketi',
    'hosts.cores'       => 'Jezgra',
    'hosts.threads'     => 'Niti',
    'hosts.cpu_mhz'     => 'MHz po jezgru',
    'hosts.memory'      => 'Memorija',
    'hosts.cpu_ram'     => 'CPU / RAM',
    'hosts.vms'         => 'Mašine',
    'hosts.esxi'        => 'ESXi',
    'hosts.build'       => 'Build',
    'hosts.bios'        => 'BIOS',
    'hosts.vendor'      => 'Proizvođač',
    'hosts.model'       => 'Model',
    'hosts.serial'      => 'Serijski broj',
    'hosts.asset_tag'   => 'Inventarski broj',
    'hosts.hw_uuid'     => 'Hardverski UUID',
    'hosts.uptime'      => 'Vreme rada',
    'hosts.maintenance' => 'Održavanje',
    'hosts.standalone'  => 'Samostalan',
    'hosts.running_vms' => 'Mašine na ovom hostu (:count)',
    'hosts.no_vms'      => 'Na ovom hostu nema registrovanih mašina.',

    // --- Uređaji hosta ---------------------------------------------------
    'devices.title'          => 'Uređaji',
    'devices.none'           => 'Za ovaj host još nisu prikupljeni uređaji.',
    'devices.vmk'            => 'VMkernel adapteri',
    'devices.pnic'           => 'Mrežne kartice',
    'devices.hba'            => 'HBA adapteri',
    'devices.vswitch'        => 'Standardni svičevi',
    'devices.vswitch_single' => 'Svič',
    'devices.portgroup'      => 'Port grupe',
    'devices.device'         => 'Uređaj',
    'devices.type'           => 'Tip',
    'devices.speed'          => 'Brzina',
    'devices.mac'            => 'MAC',
    'devices.driver'         => 'Drajver',
    'devices.firmware'       => 'Firmver',
    'devices.pci'            => 'PCI',
    'devices.port_group'     => 'Port grupa',
    'devices.subnet'         => 'Maska',
    'devices.gateway'        => 'Gateway',
    'devices.mtu'            => 'MTU',
    'devices.ports'          => 'Portova',
    'devices.ports_free'     => 'Slobodno',
    'devices.vlan'           => 'VLAN',
    'devices.no_vlan'        => 'nema',
    'devices.trunk'          => 'Trunk',
    'devices.identifier'     => 'Identifikator',
    'devices.link_down'      => 'Link je dole',

    // --- Klasteri --------------------------------------------------------
    'clusters.datacenter' => 'Datacentar',
    'clusters.ha'         => 'HA',
    'clusters.drs'        => 'DRS',

    // --- Datastore-ovi ---------------------------------------------------
    'datastores.type'         => 'Tip',
    'datastores.used'         => 'Zauzeto',
    'datastores.capacity'     => 'Kapacitet',
    'datastores.free'         => 'Slobodno',
    'datastores.uncommitted'  => 'Neupisano',
    'datastores.inaccessible' => 'Nedostupan',

    // --- Licence ---------------------------------------------------------
    'licenses.key'         => 'Ključ',
    'licenses.product'     => 'Proizvod',
    'licenses.used'        => 'Iskorišćeno',
    'licenses.total'       => 'Ukupno',
    'licenses.unit'        => 'Meri se po',
    'licenses.unlimited'   => 'Neograničeno',
    'licenses.expires'     => 'Ističe',
    'licenses.perpetual'   => 'Trajna',
    'licenses.expired'     => 'Istekla',
    'licenses.days_left'   => 'još :days d.',
    'licenses.masked_note' => 'Ključevi licenci se čuvaju maskirani — zadržava se samo poslednji blok. '
                              . 'Pun ključ je roba koja se kupuje i nema šta da traži u bazi inventara.',

    // --- Journal promena -------------------------------------------------
    'journal.title' => 'Šta se menjalo',
    'journal.empty' => 'Ništa se nije promenilo od prvog prikupljanja.',
    'journal.when'  => 'Kada',
    'journal.field' => 'Polje',
    'journal.from'  => 'Sa',
    'journal.to'    => 'Na',

    // --- Prikupljanja ----------------------------------------------------
    'runs.subtitle'       => 'Svako prikupljanje, uspešno ili ne',
    'runs.none'           => 'Još nema prikupljanja.',
    'runs.none_hint'      => 'Pritisni „Prikupi" na izvoru ili pokreni discover iz komandne linije.',
    'runs.run'            => 'Posao',
    'runs.source'         => 'Izvor',
    'runs.duration'       => 'Trajalo',
    'runs.calls'          => 'Poziva',
    'runs.when'           => 'Pokrenuto',
    'runs.release'        => 'Otključaj',
    'runs.released'       => 'Posao :run je otključan. Izvor više nije zaključan.',
    'runs.not_releasable' => 'Posao :run nije u redu čekanja niti u toku.',
    'runs.status.queued'  => 'U redu čekanja',
    'runs.status.running' => 'U toku',
    'runs.status.success' => 'Uspešno',
    'runs.status.partial' => 'Delimično',
    'runs.status.failed'  => 'Neuspešno',
    'runs.partial_note'   => 'Delimično prikupljanje je zahvatilo neke tipove objekata, a neke nije. '
                              . 'Samo kompletno prikupljanje se koristi za zaključak da objekat nedostaje.',

    // --- Baza ------------------------------------------------------------
    'database.summary'       => 'tabela: :tables, redova: :rows',
    'database.table'         => 'Tabela',
    'database.rows'          => 'Redova',
    'database.size'          => 'Veličina',
    'database.purpose'       => 'Namena',
    'database.row_count'     => 'redova: :rows',
    'database.empty_table'   => 'Ova tabela je prazna.',
    'database.readonly_note' => 'Ova stranica samo čita. Nema unosa SQL-a, nema izmena i nema brisanja. '
                                . 'Šifrovana lozinka vCenter naloga se uopšte i ne čita u prikaz.',

    // --- Podešavanje i greške --------------------------------------------
    'setup.not_migrated.title' => 'Baza je prazna',
    'setup.not_migrated.body'  => 'Pokreni migracije, pa osveži ovu stranicu:',
    'error.not_found'          => 'Ova stranica ne postoji.',
    'error.server'             => 'Nešto je pošlo naopako.',
    'error.csrf'               => 'Forma je istekla. Pokušaj ponovo.',

    // --- Object types, as a run reports them ------------------------------
    'runs.collected'        => 'Prikupljeno',
    'type.cluster'          => 'Klasteri',
    'type.host'             => 'Hostovi',
    'type.vm'               => 'Mašine',
    'type.datastore'        => 'Datastore',
    'type.host_device'      => 'Uređaji',
    'type.license'          => 'Licence',
    'type.fc_switch'        => 'Svič',
    'type.fc_fabric_member' => 'Fabric',
    'type.fc_port'          => 'Portovi',
    'type.fc_isl'           => 'ISL veze',
    'type.fc_device'        => 'Uređaji',
    'type.fc_zone_object'   => 'Zone',
    'type.fc_zone_member'   => 'Članovi zona',
    'type.raw_object'       => 'Sirovi podaci',
];
