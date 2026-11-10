<?php
// lang/de.php
//
// Deutsch.
//
// Nur die Werte werden übersetzt, die Schlüssel bleiben unverändert. Den
// Stand der Übersetzung zeigt:
//   docker compose exec app php bin/dcinfrasnap lang:check
//
// Ein hier fehlender Schlüssel wird aus dem Englischen übernommen, eine
// unvollständige Übersetzung ergibt also trotzdem eine brauchbare Oberfläche.
//
// Fachbegriffe, die Administratoren im Original verwenden (Host, Cluster,
// Datastore, Template), bleiben stehen. Eine Übersetzung, die niemand
// benutzt, erschwert die Orientierung, statt sie zu erleichtern.

declare(strict_types=1);

return [
    'language.name' => 'Deutsch',

    // --- Anwendung -------------------------------------------------------
    'app.name'    => 'DCInfraSnap',
    'app.tagline' => 'Rechenzentrums-Inventar, erfasst und aufbewahrt',

    // --- Navigation ------------------------------------------------------
    'nav.overview'     => 'Überblick',
    'nav.vms'          => 'Virtuelle Maschinen',
    'nav.hosts'        => 'Hosts',
    'nav.clusters'     => 'Cluster',
    'nav.datastores'   => 'Datastores',
    'nav.licenses'     => 'Lizenzen',
    'nav.runs'         => 'Erfassungen',
    'nav.integrations' => 'Integrationen',
    'nav.database'     => 'Datenbank',
    'nav.logout'       => 'Abmelden',
    'nav.language'     => 'Sprache',

    // --- Allgemein -------------------------------------------------------
    'common.actions' => 'Aktionen',
    'common.add'     => 'Hinzufügen',
    'common.back'    => 'Zurück',
    'common.cancel'  => 'Abbrechen',
    'common.delete'  => 'Löschen',
    'common.edit'    => 'Bearbeiten',
    'common.name'    => 'Name',
    'common.host'    => 'Host',
    'common.cluster' => 'Cluster',
    'common.never'   => 'Nie',
    'common.no'      => 'Nein',
    'common.none'    => 'Keine',
    'common.save'    => 'Speichern',
    'common.search'  => 'Suchen',
    'common.status'  => 'Status',
    'common.unknown' => 'Unbekannt',
    'common.yes'     => 'Ja',

    // --- Anmeldung -------------------------------------------------------
    'login.title'          => 'Anmelden',
    'login.password'       => 'Passwort',
    'login.submit'         => 'Anmelden',
    'login.failed'         => 'Falsches Passwort.',
    'login.rate_limited'   => 'Zu viele Versuche. Bitte gleich noch einmal versuchen.',
    'login.not_configured' => 'Die Oberfläche ist gesperrt, weil kein Passwort gesetzt ist.',
    'login.setup_hint'     => 'Einen Hash mit „docker compose run --rm app php bin/dcinfrasnap password:hash" '
                              . 'erzeugen, ihn in .env unter APP_PASSWORD_HASH eintragen (in einfachen '
                              . 'Anführungszeichen) und dann „docker compose up -d" ausführen.',

    // --- Überblick -------------------------------------------------------
    'overview.title'           => 'Überblick',
    'overview.subtitle'        => 'Was erfasst wurde und woher',
    'overview.no_integrations' => 'Es wurde noch keine Quelle hinzugefügt.',
    'overview.add_first'       => 'Ein vCenter hinzufügen, damit die Erfassung beginnen kann.',
    'overview.counts'          => 'Erfasste Objekte',
    'overview.last_collection' => 'Letzte Erfassung',
    'overview.sources'         => 'Quellen',

    // --- Quellen ---------------------------------------------------------
    'integrations.subtitle'    => 'Die Systeme, aus denen DCInfraSnap Daten erfasst',
    'integrations.add_vcenter' => 'vCenter hinzufügen',
    'integrations.none'        => 'Es wurde noch keine Quelle hinzugefügt.',
    'integrations.none_hint'   => 'Ein vCenter hinzufügen, dann kann DCInfraSnap daraus erfassen.',

    'integrations.address'   => 'Adresse',
    'integrations.hostname'  => 'Hostname oder IP',
    'integrations.port'      => 'Port',
    'integrations.username'  => 'Benutzername',
    'integrations.password'  => 'Passwort',
    'integrations.version'   => 'Version',
    'integrations.last_test' => 'Verbindung',

    'integrations.name_placeholder'  => 'vcenter-01',
    'integrations.name_hint'         => 'Erscheint in Listen und Berichten. Eine Bezeichnung, die Ihnen etwas sagt.',
    'integrations.username_hint'     => 'Ein Konto mit reiner Lesekennung genügt. '
                                        . 'DCInfraSnap schreibt niemals ins vCenter.',
    'integrations.password_hint'     => 'Wird mit SECRET_KEY verschlüsselt gespeichert, nie im Klartext.',
    'integrations.password_separate' => 'Das Passwort wird hier nicht angezeigt.',
    'integrations.verify_peer'       => 'TLS-Zertifikat prüfen',
    'integrations.verify_peer_hint'  => 'Bei einem vCenter mit selbstsigniertem Zertifikat ausgeschaltet lassen.',
    'integrations.form_subtitle'     => 'Verbindungsdaten für ein vCenter.',
    'integrations.credentials_note'  => 'Zugangsdaten werden mit SECRET_KEY verschlüsselt und erst im Moment der '
                                        . 'Anmeldung am vCenter entschlüsselt. Sie werden nie wieder angezeigt.',

    'integrations.collect'              => 'Erfassen',
    'integrations.queued'               => ':name wurde als Lauf :run eingereiht. '
                                           . 'Der Worker übernimmt ihn in wenigen Sekunden.',
    'integrations.test'                 => 'Prüfen',
    'integrations.test_ok'              => 'OK',
    'integrations.test_failed'          => 'Fehlgeschlagen',
    'integrations.untested'             => 'Nicht geprüft',
    'integrations.test_ok_message'      => 'verbunden mit :version',
    'integrations.change_password'      => 'Passwort ändern',
    'integrations.new_password'         => 'Neues Passwort',
    'integrations.repeat_password'      => 'Passwort wiederholen',
    'integrations.password_title'       => 'Passwort für :name',
    'integrations.password_saved'       => 'Passwort gespeichert.',
    'integrations.password_change_note' => 'Das alte Passwort wird überschrieben und kann nicht wiederhergestellt werden.',

    'integrations.edit_title'   => ':name bearbeiten',
    'integrations.added'        => ':name hinzugefügt.',
    'integrations.added_hint'   => 'Die Verbindung prüfen, um zu bestätigen, dass die Zugangsdaten funktionieren.',
    'integrations.saved'        => 'Änderungen gespeichert.',
    'integrations.enable'       => 'Aktivieren',
    'integrations.disable'      => 'Deaktivieren',
    'integrations.disabled'     => 'Deaktiviert',
    'integrations.enabled_now'  => ':name ist aktiviert.',
    'integrations.disabled_now' => ':name ist deaktiviert.',

    'integrations.delete_title'        => ':name löschen?',
    'integrations.delete_warning'      => 'Damit wird auch alles gelöscht, was über diese Quelle erfasst wurde:',
    'integrations.delete_empty'        => 'Über diese Quelle wurde noch nichts erfasst.',
    'integrations.delete_irreversible' => 'Das lässt sich nicht rückgängig machen.',
    'integrations.delete_confirm'      => 'Endgültig löschen',
    'integrations.deleted'             => ':name gelöscht.',

    'integrations.error_name'           => 'Ein Name ist erforderlich.',
    'integrations.error_hostname'       => 'Ein Hostname oder eine IP-Adresse ist erforderlich.',
    'integrations.error_hostname_url'   => 'Nur den Hostnamen eingeben, ohne https:// — '
                                           . 'zum Beispiel vcenter.beispiel.local.',
    'integrations.error_port'           => 'Der Port muss zwischen 1 und 65535 liegen.',
    'integrations.error_username'       => 'Ein Benutzername ist erforderlich.',
    'integrations.error_password'       => 'Ein Passwort ist erforderlich.',
    'integrations.error_password_match' => 'Die beiden Passwörter stimmen nicht überein.',

    // --- Listen ----------------------------------------------------------
    'list.showing'            => 'Angezeigt: :count',
    'list.nothing_found'      => 'Zu diesen Filtern passt nichts.',
    'list.nothing_found_hint' => 'Breiter suchen oder die Filter zurücksetzen.',
    'list.nothing_yet'        => 'Noch nichts erfasst.',
    'list.collect_first'      => 'Eine Erfassung ausführen, dann füllt sich diese Seite.',
    'list.export_csv'         => 'CSV exportieren',
    'list.first_seen'         => 'Zuerst gesehen',
    'list.last_seen'          => 'Zuletzt gesehen',
    'list.missing'            => 'Fehlt',
    'list.missing_hint'       => 'In der letzten vollständigen Erfassung dieser Quelle nicht gesehen.',
    'list.previous'           => 'Zurück',
    'list.next'               => 'Weiter',
    'list.page_of'            => 'Seite :page von :pages',

    'filter.all_sources'  => 'Alle Quellen',
    'filter.any_power'    => 'Beliebiger Zustand',
    'filter.any_cluster'  => 'Beliebiges Cluster',
    'filter.any_host'     => 'Beliebiger Host',
    'filter.any_kind'     => 'Maschinen und Templates',
    'filter.missing_only' => 'Nur Fehlende',
    'filter.clear'        => 'Zurücksetzen',

    // --- Virtuelle Maschinen ---------------------------------------------
    'vms.search_placeholder' => 'Name, Hostname, Adresse, UUID oder Notizen',
    'vms.power'              => 'Zustand',
    'vms.guest'              => 'Gast',
    'vms.address'            => 'Adresse',
    'vms.hostname'           => 'Hostname',
    'vms.cpu'                => 'vCPU',
    'vms.cores_per_socket'   => 'Kerne pro Sockel',
    'vms.memory'             => 'Arbeitsspeicher',
    'vms.cpu_ram'            => 'CPU / RAM',
    'vms.disk'               => 'Festplatte',
    'vms.network'            => 'Netzwerk',
    'vms.disks'              => 'Festplatten',
    'vms.disk_space'         => 'Plattenplatz',
    'vms.nics'               => 'Netzwerkkarten',
    'vms.template'           => 'Template',
    'vms.templates_only'     => 'Nur Templates',
    'vms.machines_only'      => 'Nur Maschinen',
    'vms.identity'           => 'Kennungen',
    'vms.resources'          => 'Ressourcen',
    'vms.placement'          => 'Platzierung',
    'vms.notes'              => 'Notizen',
    'vms.folder'             => 'Ordner',
    'vms.moref'              => 'Objekt im vCenter',
    'vms.instance_uuid'      => 'Instance-UUID',
    'vms.bios_uuid'          => 'BIOS-UUID',
    'vms.hardware_version'   => 'Hardware-Version',
    'vms.guest_configured'   => 'Im vCenter eingestelltes OS',
    'vms.guest_reported'     => 'Vom Gast gemeldetes OS',
    'vms.guest_state'        => 'Gastzustand',
    'vms.tools'              => 'VMware Tools',

    // --- Hosts -----------------------------------------------------------
    'hosts.state'       => 'Zustand',
    'hosts.hardware'    => 'Hardware',
    'hosts.capacity'    => 'Kapazität',
    'hosts.software'    => 'Software',
    'hosts.cpu'         => 'Kerne / Sockel',
    'hosts.cpu_model'   => 'Prozessor',
    'hosts.sockets'     => 'Sockel',
    'hosts.cores'       => 'Kerne',
    'hosts.threads'     => 'Threads',
    'hosts.cpu_mhz'     => 'MHz je Kern',
    'hosts.memory'      => 'Arbeitsspeicher',
    'hosts.cpu_ram'     => 'CPU / RAM',
    'hosts.vms'         => 'Maschinen',
    'hosts.esxi'        => 'ESXi',
    'hosts.build'       => 'Build',
    'hosts.bios'        => 'BIOS',
    'hosts.vendor'      => 'Hersteller',
    'hosts.model'       => 'Modell',
    'hosts.serial'      => 'Seriennummer',
    'hosts.asset_tag'   => 'Inventarnummer',
    'hosts.hw_uuid'     => 'Hardware-UUID',
    'hosts.uptime'      => 'Laufzeit',
    'hosts.maintenance' => 'Wartung',
    'hosts.standalone'  => 'Einzeln',
    'hosts.running_vms' => 'Maschinen auf diesem Host (:count)',
    'hosts.no_vms'      => 'Auf diesem Host sind keine Maschinen registriert.',

    // --- Host-Geräte ------------------------------------------------------
    'devices.title'          => 'Geräte',
    'devices.none'           => 'Für diesen Host wurden noch keine Geräte erfasst.',
    'devices.vmk'            => 'VMkernel-Adapter',
    'devices.pnic'           => 'Netzwerkkarten',
    'devices.hba'            => 'Speicheradapter',
    'devices.vswitch'        => 'Standard-Switches',
    'devices.vswitch_single' => 'Switch',
    'devices.portgroup'      => 'Portgruppen',
    'devices.device'         => 'Gerät',
    'devices.type'           => 'Typ',
    'devices.speed'          => 'Geschwindigkeit',
    'devices.mac'            => 'MAC',
    'devices.driver'         => 'Treiber',
    'devices.firmware'       => 'Firmware',
    'devices.pci'            => 'PCI',
    'devices.port_group'     => 'Portgruppe',
    'devices.subnet'         => 'Subnetzmaske',
    'devices.gateway'        => 'Gateway',
    'devices.mtu'            => 'MTU',
    'devices.ports'          => 'Ports',
    'devices.ports_free'     => 'Frei',
    'devices.vlan'           => 'VLAN',
    'devices.no_vlan'        => 'keines',
    'devices.trunk'          => 'Trunk',
    'devices.identifier'     => 'Kennung',
    'devices.link_down'      => 'Link down',

    // --- Cluster ---------------------------------------------------------
    'clusters.datacenter' => 'Rechenzentrum',
    'clusters.ha'         => 'HA',
    'clusters.drs'        => 'DRS',

    // --- Datastores ------------------------------------------------------
    'datastores.type'         => 'Typ',
    'datastores.used'         => 'Belegt',
    'datastores.capacity'     => 'Kapazität',
    'datastores.free'         => 'Frei',
    'datastores.uncommitted'  => 'Nicht zugewiesen',
    'datastores.inaccessible' => 'Nicht erreichbar',

    // --- Lizenzen --------------------------------------------------------
    'licenses.key'         => 'Schlüssel',
    'licenses.product'     => 'Produkt',
    'licenses.used'        => 'Verwendet',
    'licenses.total'       => 'Gesamt',
    'licenses.unit'        => 'Gezählt nach',
    'licenses.unlimited'   => 'Unbegrenzt',
    'licenses.expires'     => 'Läuft ab',
    'licenses.perpetual'   => 'Unbefristet',
    'licenses.expired'     => 'Abgelaufen',
    'licenses.days_left'   => 'noch :days T.',
    'licenses.masked_note' => 'Lizenzschlüssel werden maskiert gespeichert — nur der letzte Block bleibt erhalten. '
                              . 'Ein vollständiger Schlüssel ist ein käuflicher Wert und gehört nicht in eine '
                              . 'Inventardatenbank.',

    // --- Änderungsjournal ------------------------------------------------
    'journal.title' => 'Was sich geändert hat',
    'journal.empty' => 'Seit der ersten Erfassung hat sich nichts geändert.',
    'journal.when'  => 'Wann',
    'journal.field' => 'Feld',
    'journal.from'  => 'Von',
    'journal.to'    => 'Auf',

    // --- Erfassungen -----------------------------------------------------
    'runs.subtitle'       => 'Jede Erfassung, erfolgreich oder nicht',
    'runs.none'           => 'Noch keine Erfassungen.',
    'runs.none_hint'      => 'Bei einer Quelle auf „Erfassen" klicken oder discover auf der Kommandozeile ausführen.',
    'runs.run'            => 'Lauf',
    'runs.source'         => 'Quelle',
    'runs.duration'       => 'Dauer',
    'runs.calls'          => 'Aufrufe',
    'runs.when'           => 'Gestartet',
    'runs.release'        => 'Freigeben',
    'runs.released'       => 'Lauf :run freigegeben. Die Quelle ist nicht mehr gesperrt.',
    'runs.not_releasable' => 'Lauf :run ist weder eingereiht noch aktiv.',
    'runs.status.queued'  => 'Eingereiht',
    'runs.status.running' => 'Läuft',
    'runs.status.success' => 'Erfolgreich',
    'runs.status.partial' => 'Teilweise',
    'runs.status.failed'  => 'Fehlgeschlagen',
    'runs.partial_note'   => 'Eine teilweise Erfassung hat einige Objekttypen erfasst und andere nicht. '
                              . 'Nur eine vollständige Erfassung entscheidet darüber, ob ein Objekt fehlt.',

    // --- Datenbank -------------------------------------------------------
    'database.summary'       => 'Tabellen: :tables, Zeilen: :rows',
    'database.table'         => 'Tabelle',
    'database.rows'          => 'Zeilen',
    'database.size'          => 'Größe',
    'database.purpose'       => 'Zweck',
    'database.row_count'     => 'Zeilen: :rows',
    'database.empty_table'   => 'Diese Tabelle ist leer.',
    'database.readonly_note' => 'Diese Seite liest nur. Keine SQL-Eingabe, kein Bearbeiten, kein Löschen. '
                                . 'Das verschlüsselte vCenter-Passwort wird gar nicht erst eingelesen.',

    // --- Einrichtung und Fehler ------------------------------------------
    'setup.not_migrated.title' => 'Die Datenbank ist leer',
    'setup.not_migrated.body'  => 'Die Migrationen ausführen und diese Seite neu laden:',
    'error.not_found'          => 'Diese Seite gibt es nicht.',
    'error.server'             => 'Etwas ist schiefgegangen.',
    'error.csrf'               => 'Das Formular ist abgelaufen. Bitte noch einmal versuchen.',
];
