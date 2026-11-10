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

    // --- Setup / errors -------------------------------------------------
    'setup.not_migrated.title' => 'The database is empty',
    'setup.not_migrated.body'  => 'Run the migrations, then reload this page:',
    'error.not_found'          => 'This page does not exist.',
    'error.server'             => 'Something went wrong.',
    'error.csrf'               => 'The form has expired. Please try again.',
];
