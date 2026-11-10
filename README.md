# DCInfraSnap

Inventory of data centre infrastructure: collect it from the source, keep the history, record every change.

The first source is **vCenter** — clusters, ESXi hosts, virtual machines, datastores and licenses, read over the vSphere SOAP API (vim25).

> **Status:** step 1 of 4. The stack runs, the schema is in place, the interface signs you in and reports an empty database. Collection arrives in step 3.

---

## What it does

DCInfraSnap takes a picture of the infrastructure and keeps the pictures.

- connects to **several vCenter servers**, independently of each other
- collects **cluster / host / VM / datastore / license** objects
- keeps the **raw payload** of every object (raw layer) and **normalized tables** (inventory layer)
- keeps a **journal** — `first_seen`, `last_seen`, and a record of every real change, field by field
- **never deletes a row** — an object going missing is derived from `last_seen`, and deciding what that means is a separate concern
- shows all of it in a web interface, with search, filters and CSV export

It is not a synchronisation tool and it does not write to anything. It reads, records and shows.

### Languages

The interface is **English by default**. German, Serbian and Russian are prepared: the translation layer is in place and the language picker appears as soon as a second language file exists. Code, comments, keys and documentation stay in English.

Adding a language is one file — see [Translating](#translating).

---

## Stack

| Component | Choice |
|---|---|
| Application | PHP 8.3, no framework |
| Database | MySQL 8.4 |
| Web server | nginx 1.27 + PHP-FPM |
| Running it | Docker + Docker Compose |
| vCenter API | vSphere SOAP API (vim25) |

No third-party libraries. The dependencies are PHP extensions only: `pdo_mysql`, `sodium`, `soap`, `intl`, `json`, `mbstring`, `pcntl`. The application runs without `composer install` — if `vendor/` is missing, a minimal PSR-4 autoloader takes over.

---

## Getting started

### Prerequisites

- Docker Engine 24+ with Compose V2 (`docker compose`, not `docker-compose`)
- free ports `8080` (GUI) and `3307` (MySQL on the host) — both can be changed in `.env`

### 1. Clone and configure

```bash
git clone https://github.com/alennikolic/dcinfrasnap.git
cd dcinfrasnap
cp .env.example .env
```

In `.env`, change `DB_PASSWORD` and `DB_ROOT_PASSWORD`.

### 2. Build the image

```bash
docker compose build
```

The first build takes a few minutes — the PHP extensions are compiled.

### 3. Encryption key and GUI password

Both are generated before the first start, so no restart is needed later.

```bash
docker compose run --rm app php bin/dcinfrasnap key:generate
docker compose run --rm app php bin/dcinfrasnap password:hash
```

The first prints a value for `SECRET_KEY`, the second for `APP_PASSWORD_HASH`. Put both in `.env`.

> **The single quotes around the hash are mandatory.** A bcrypt hash contains `$`, which Compose would read as a variable reference and swallow part of the string:
> ```
> APP_PASSWORD_HASH='$2y$10$...'
> ```

> **If `SECRET_KEY` is lost or changed**, every stored vCenter credential becomes undecryptable and has to be entered again. That is the purpose of the key, not a flaw.

### 4. Start

```bash
docker compose up -d
docker compose ps
```

Four services come up: `dcinfrasnap-web`, `dcinfrasnap-app`, `dcinfrasnap-db` and `dcinfrasnap-worker`. The database should also report `healthy`.

### 5. Migrations

**Required after the first start and after every `git pull`.** Without it the database is empty and the interface says so.

```bash
docker compose exec app php bin/dcinfrasnap migrate
```

Expected on a first run:

```
Applying 1 migration(s)...

  0001  schema                        OK (812 ms)

Done. Applied in total: 1.
```

The command is idempotent — running it again reports that there is nothing to apply and does not touch the database. It is safe to run always.

### 6. Open it

**http://localhost:8080**, and sign in with the password from step 3.

If you get the "no password is set" notice instead of the form, `APP_PASSWORD_HASH` has not reached the container — check the quotes in `.env`, then `docker compose up -d`.

---

## The command line

> **Why `php bin/dcinfrasnap` and not `bin/dcinfrasnap`:** the repository is bind-mounted, so the container sees the permissions of the host machine. If the file is not executable there, calling it directly gives `permission denied`. Going through `php` always works.

```bash
# Migrations
docker compose exec app php bin/dcinfrasnap migrate
docker compose exec app php bin/dcinfrasnap migrate:status

# Generators (no database needed)
docker compose run --rm app php bin/dcinfrasnap key:generate
docker compose run --rm app php bin/dcinfrasnap password:hash

# Translations
docker compose exec app php bin/dcinfrasnap lang:check

docker compose exec app php bin/dcinfrasnap help
```

### Useful Docker commands

```bash
docker compose logs -f app          # PHP errors and the php-fpm log
docker compose logs -f worker       # the worker
docker compose logs -f web          # nginx
docker compose exec app sh          # a shell in the container
docker compose exec db mysql -u root -p dcinfrasnap

docker compose restart app          # after changing php.ini
docker compose up -d --build        # after changing the Dockerfile
docker compose down                 # stop (data is kept)
docker compose down -v              # stop + DELETE the database
```

Changes to PHP code are live — the repository is bind-mounted and OPcache is in development mode. The same goes for CSS: the stylesheet URL carries the file's modification time, so the browser cache never holds a change back.

---

## Configuration

Everything goes through `.env`, which is **not** in the repository.

| Variable | Meaning |
|---|---|
| `APP_ENV` | `dev` or `prod` |
| `APP_LOCALE` | default interface language (`en`) |
| `APP_PASSWORD_HASH` | bcrypt hash of the GUI password; empty means the GUI stays closed |
| `WEB_PORT` | port of the GUI on the host (default `8080`) |
| `DB_NAME`, `DB_USER`, `DB_PASSWORD` | database credentials |
| `DB_ROOT_PASSWORD` | MySQL root password |
| `DB_PORT_HOST` | port MySQL is published on, on the host (default `3307`) |
| `SECRET_KEY` | 32 bytes in base64, used to encrypt vCenter credentials |

vCenter credentials are **not kept in `.env`** — they are entered through the interface or the CLI and stored encrypted in the database (libsodium secretbox).

### Going to production

Four places, each marked with a comment in the file itself:

- `docker/php/php.ini` — `display_errors = Off`, `opcache.validate_timestamps = 0`
- `docker/php/php.ini` and `src/Web/Auth.php` — turn on the `secure` cookie once the application is served over HTTPS
- `docker-compose.yml` — delete the `ports` block of the `db` service, so the database is not exposed
- `.env` — `APP_ENV=prod`

---

## Repository layout

```
dcinfrasnap/
├── docker-compose.yml              # services: web, app, db, worker
├── .env.example
├── composer.json                   # PSR-4 autoload, PHP requirements
│
├── docker/
│   ├── php/{Dockerfile,php.ini}
│   └── nginx/default.conf
│
├── bin/dcinfrasnap                 # the only CLI entry point
├── config/
│   ├── bootstrap.php               # autoloading + extension check
│   └── config.php                  # the only place env vars are read
│
├── db/migrations/
│   └── 0001_schema.sql             # the whole schema, one file
│
├── lang/
│   └── en.php                      # source language and fallback
│
├── public/
│   ├── index.php                   # the only executable file
│   └── assets/app.css
│
└── src/
    ├── Core/{Database,Migrator,Secret}.php
    ├── I18n/Translator.php
    └── Web/
        ├── Auth.php
        ├── View.php
        └── View/                   # templates
```

**Only `public/` is served.** The document root is `public/`, and nginx executes `index.php` and nothing else — any other `.php` file that ends up there returns 404.

---

## The data model

Data is kept in two layers.

**Raw layer** (`raw_object`) — the payload of each object exactly as the source returned it, one row per object per run. When a field turns out to be useful six months from now, it is extracted from here instead of rescanning the infrastructure.

**Inventory layer** (`vc_cluster`, `vc_host`, `vc_vm`, `vc_datastore`, `vc_license`) — normalized tables holding current state, each with a matching `*_change` journal.

Every row in the inventory layer carries:

| Column | Meaning |
|---|---|
| `first_seen` | first time the object was detected |
| `last_seen` | most recent detection |
| `last_run_id` | the run it was last seen in |
| `payload_hash` | SHA-256 over the **normalized fields**, not over the raw response |

On each run: if `payload_hash` is unchanged, only `last_seen` and `last_run_id` are refreshed. If it changed, the row is updated and an entry goes into the `*_change` table holding **only the fields that actually differ**.

The hash is computed over normalized fields rather than the raw payload because any field the source returns but DCInfraSnap does not keep would otherwise produce a phantom change in the journal.

### Migrations

The schema lives in numbered SQL files under `db/migrations/`. The migrator records what it has applied in `schema_migrations` and applies only what is missing.

- **The whole initial schema is one file.** It only ever runs against an empty database.
- **An applied migration is never edited.** A fix goes in as a new version. The migrator keeps a SHA-256 of every file and refuses to run if the content of an applied one has changed — it exits with code `2` and explains what happened.
- **There are no `down` migrations.** Rolling back in production means restoring a backup, not running a script nobody has ever tested.

---

## Translating

The interface is English, and English is the fallback for every other language. A key missing from a translation is served from English rather than from a blank space, so a half-finished language is still a usable interface.

To add German:

```bash
cp lang/en.php lang/de.php
# translate the values, leave the keys alone
docker compose exec app php bin/dcinfrasnap lang:check
```

The language picker appears in the header as soon as a second language file exists. A visitor picks a language with `?lang=de`, and the choice is remembered for the session.

`lang:check` compares every language against English and lists what is missing and what is no longer used.

> **Plural forms are deliberately left out.** Russian needs three, Serbian three, German two, and a general solution is a small library of its own. The interface is instead written so that no string depends on a count — "Virtual machines: 5", not "5 virtual machines". If that stops being enough, `src/I18n/Translator.php` is where a CLDR plural rule goes.

---

## Roadmap

| Step | Content |
|---|---|
| 1 | **Done** — Docker stack, schema, CLI, translation layer, sign-in, overview |
| 2 | Integrations: add a vCenter, encrypted credentials, connection test |
| 3 | SOAP collector, worker, run history |
| 4 | Inventory pages: VMs, hosts, clusters, datastores, licenses, CSV export |

Later: host devices (NICs, kernel adapters, HBAs), port groups and switches, scheduled collection, user accounts.

---

## License

MIT.
