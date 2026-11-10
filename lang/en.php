<?php
// lang/en.php
//
// English — the source language and the fallback for every other one.
//
// Adding a language: copy this file to its code (de.php, sr.php, ru.php),
// translate the values, leave the keys untouched. Then check it with:
//   docker compose exec app php bin/dcinfrasnap lang:check
//
// Keys are grouped by where they appear. Keep them sorted within a group.
// Placeholders are :name.

declare(strict_types=1);

return [
    // The name of this language, in this language. Shown in the language
    // picker, so German reads "Deutsch" rather than "German".
    'language.name' => 'English',

    // --- Application ---------------------------------------------------
    'app.name'    => 'DCInfraSnap',
    'app.tagline' => 'Data centre inventory, collected and kept',

    // --- Navigation ----------------------------------------------------
    'nav.overview'     => 'Overview',
    'nav.vms'          => 'Virtual machines',
    'nav.hosts'        => 'Hosts',
    'nav.clusters'     => 'Clusters',
    'nav.datastores'   => 'Datastores',
    'nav.licenses'     => 'Licenses',
    'nav.runs'         => 'Collections',
    'nav.integrations' => 'Integrations',
    'nav.database'     => 'Database',
    'nav.logout'       => 'Sign out',
    'nav.language'     => 'Language',

    // --- Common --------------------------------------------------------
    'common.actions'     => 'Actions',
    'common.add'         => 'Add',
    'common.back'        => 'Back',
    'common.cancel'      => 'Cancel',
    'common.delete'      => 'Delete',
    'common.edit'        => 'Edit',
    'common.name'        => 'Name',
    'common.never'       => 'Never',
    'common.no'          => 'No',
    'common.none'        => 'None',
    'common.save'        => 'Save',
    'common.search'      => 'Search',
    'common.status'      => 'Status',
    'common.unknown'     => 'Unknown',
    'common.yes'         => 'Yes',

    // --- Sign in -------------------------------------------------------
    'login.title'          => 'Sign in',
    'login.password'       => 'Password',
    'login.submit'         => 'Sign in',
    'login.failed'         => 'Wrong password.',
    'login.rate_limited'   => 'Too many attempts. Try again in a moment.',
    'login.not_configured' => 'The interface is closed because no password is set.',
    'login.setup_hint'     => 'Generate a hash with "docker compose run --rm app php bin/dcinfrasnap password:hash", '
                              . 'put it in APP_PASSWORD_HASH in .env (in single quotes), then run "docker compose up -d".',

    // --- Overview ------------------------------------------------------
    'overview.title'             => 'Overview',
    'overview.subtitle'          => 'What has been collected, and from where',
    'overview.no_integrations'   => 'No source has been added yet.',
    'overview.add_first'         => 'Add a vCenter to start collecting.',
    'overview.counts'            => 'Collected objects',
    'overview.last_collection'   => 'Last collection',
    'overview.sources'           => 'Sources',

    // --- Sources ---------------------------------------------------------
    'integrations.subtitle'          => 'The systems DCInfraSnap collects from',
    'integrations.add_vcenter'       => 'Add vCenter',
    'integrations.none'              => 'No source has been added yet.',
    'integrations.none_hint'         => 'Add a vCenter and DCInfraSnap can start collecting from it.',

    'integrations.address'           => 'Address',
    'integrations.hostname'          => 'Hostname or IP',
    'integrations.port'              => 'Port',
    'integrations.username'          => 'Username',
    'integrations.password'          => 'Password',
    'integrations.version'           => 'Version',
    'integrations.last_test'         => 'Connection',

    'integrations.name_placeholder'  => 'vcenter-01',
    'integrations.name_hint'         => 'Shown in lists and reports. Any label that makes sense to you.',
    'integrations.username_hint'     => 'A read-only account is enough. DCInfraSnap never writes to vCenter.',
    'integrations.password_hint'     => 'Stored encrypted with SECRET_KEY, never in plain text.',
    'integrations.password_separate' => 'The password is not shown here.',
    'integrations.verify_peer'       => 'Verify the TLS certificate',
    'integrations.verify_peer_hint'  => 'Leave this off for a vCenter with a self-signed certificate.',
    'integrations.form_subtitle'     => 'Connection details for one vCenter.',
    'integrations.credentials_note'  => 'Credentials are encrypted with SECRET_KEY and decrypted only at the '
                                        . 'moment of signing in to vCenter. They are never shown again.',

    'integrations.test'              => 'Test',
    'integrations.test_ok'           => 'OK',
    'integrations.test_failed'       => 'Failed',
    'integrations.untested'          => 'Not tested',
    'integrations.test_ok_message'   => 'connected to :version',
    'integrations.change_password'   => 'Change password',
    'integrations.new_password'      => 'New password',
    'integrations.repeat_password'   => 'Repeat the password',
    'integrations.password_title'    => 'Password for :name',
    'integrations.password_saved'    => 'Password saved.',
    'integrations.password_change_note' => 'The old password is overwritten and cannot be recovered.',

    'integrations.edit_title'        => 'Edit :name',
    'integrations.added'             => ':name added.',
    'integrations.added_hint'        => 'Run a connection test to confirm the credentials work.',
    'integrations.saved'             => 'Changes saved.',
    'integrations.enable'            => 'Enable',
    'integrations.disable'           => 'Disable',
    'integrations.disabled'          => 'Disabled',
    'integrations.enabled_now'       => ':name is enabled.',
    'integrations.disabled_now'      => ':name is disabled.',

    'integrations.delete_title'      => 'Delete :name?',
    'integrations.delete_warning'    => 'This also deletes everything collected through this source:',
    'integrations.delete_empty'      => 'Nothing has been collected through this source yet.',
    'integrations.delete_irreversible' => 'This cannot be undone.',
    'integrations.delete_confirm'    => 'Delete permanently',
    'integrations.deleted'           => ':name deleted.',

    'integrations.error_name'          => 'A name is required.',
    'integrations.error_hostname'      => 'A hostname or IP address is required.',
    'integrations.error_hostname_url'  => 'Enter the hostname only, without https:// — for example vcenter.example.local.',
    'integrations.error_port'          => 'The port must be between 1 and 65535.',
    'integrations.error_username'      => 'A username is required.',
    'integrations.error_password'      => 'A password is required.',
    'integrations.error_password_match' => 'The two passwords do not match.',

    // --- Setup / errors -------------------------------------------------
    'setup.not_migrated.title' => 'The database is empty',
    'setup.not_migrated.body'  => 'Run the migrations, then reload this page:',
    'error.not_found'          => 'This page does not exist.',
    'error.server'             => 'Something went wrong.',
    'error.csrf'               => 'The form has expired. Please try again.',
];
