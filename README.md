# WeavDash

Creator pipeline and brand onboarding, built as a Laravel modular monolith. This checkout contains the **first backend slice of M1**, not a finished application. The Vue/TypeScript/Inertia and shadcn-vue UI is not installed yet; `/` reports milestone status and password-reset links currently return a 503 UI placeholder. Do not use this slice with real users or live data.

## Layout

```text
src/                  Laravel application, dependencies, tests, and build tools
  app/                HTTP endpoints, workspace services, and tenant context
  database/           Migrations, PostgreSQL policies, and runtime grants
  resources/          Frontend source and views
  tests/              Identity and PostgreSQL integration tests
  docker/             Application image and database initialization
  scripts/            Application setup and verification scripts
docs/                 Business reference documents
mockup/               Saved UI prototype
planning/             Requirements, architecture, and milestone status
.github/              Repository CI workflows
compose.yaml          Local service orchestration
```

`src/` is the Laravel application root; keep its internal conventions intact. Run Composer, Artisan, npm, and application tests from `src/`. Keep contributor guides and project documentation at the repository root or in the reference directories above. Run Docker Compose from the repository root with `--env-file src/.env`.

## Local backend setup

Docker uses PHP 8.5 (`php:8.5-cli-bookworm`) with Redis extension 6.3.0. Requires Docker with Compose and network access to download images/packages. The [official PHP image tags](https://github.com/docker-library/official-images/blob/master/library/php) publish this stable branch; `build --pull` retrieves current base images. For local execution, the Composer constraint remains PHP 8.3+ (including 8.5), with Composer 2, PostgreSQL 16, and the PHP Redis extension. PHP dependencies are locked in `src/composer.lock` (Laravel 13.34.0, PHPUnit 12.5.37). The frontend manifest is still the uninstalled Laravel skeleton and has no lockfile.

1. Copy `src/.env.example` to `src/.env`. Generate a base64 32-byte `APP_KEY` and distinct random `DB_PASSWORD`, `MIGRATION_DB_PASSWORD`, and `POSTGRES_PASSWORD`. Never commit `src/.env`.
2. Run `docker compose --env-file src/.env build --pull app migrate`.
3. Run `docker compose --env-file src/.env up -d --wait postgres redis`.
4. Run `docker compose --env-file src/.env run --rm migrate`, then `docker compose --env-file src/.env run --rm migrate php scripts/grant-runtime.php`.
5. Run `docker compose --env-file src/.env up -d app worker scheduler`. Backend liveness is at `http://127.0.0.1:8000/up`.

The database and Redis have no published ports. The web port binds to loopback. Private files use a named volume. Local mail uses an in-memory transport in Compose, so no real recipients are contacted. To test notification URLs, use Laravel notification fakes in automated tests.

PostgreSQL initialization creates separate migration and runtime roles only on a new volume. The migration role owns schema objects and has BYPASSRLS for reviewed bootstrap/lookup functions. Web and worker credentials cannot bypass RLS or own tenant tables. Run migrations/grants through the `migrate` service only. This is a development configuration; image digest pinning, production serving/TLS, and delivery validation remain outstanding.

## Verification

Run these commands from the repository root; the first command enters the application:

```sh
cd src
composer install
composer validate --strict
vendor/bin/pint --test
php artisan test --compact
python3 scripts/check-postgres-schema.py
```

The default PHP suite uses SQLite **only for identity and role tests**. Workspace code refuses SQLite. The Python command checks PostgreSQL DDL/catalog definitions in a disposable single-user cluster; it does **not** establish runtime isolation.

From the repository root, for a freshly migrated, disposable Compose database, run these once before the integration suite:

```sh
docker compose --env-file src/.env exec -T postgres psql -U postgres -d weavdash -v ON_ERROR_STOP=1 < src/tests/Integration/rls-fixtures.sql
docker compose --env-file src/.env exec -T postgres psql -U postgres -d weavdash -v ON_ERROR_STOP=1 < src/tests/Integration/rls.sql
docker compose --env-file src/.env run --rm app vendor/bin/phpunit -c phpunit.pgsql.xml
```

SQL checks exercise denied cross-organization reads/writes, viewer restrictions, revocation, append-only auditing, and commit/rollback context cleanup. PHPUnit integration checks the actual request/service and worker middleware paths. The GitHub workflow defines these backend checks but has not run in this workspace.

## Remaining M1 work

See [M1 status](planning/m1-status.md). Complete the approved UI and dependency lockfiles, actual PostgreSQL/Redis/container checks, session/browser/accessibility checks, CI validation, and delivery gates before moving to M2. The sandbox currently denies network access, server socket creation, and Docker daemon access. `AGENTS.md` remains unchanged as requested.
