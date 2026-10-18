# WeavDash

Creator pipeline and brand onboarding, built as a Laravel modular monolith. This checkout contains the **foundation and first M2 private-pipeline slice**, not a finished product. Official Inertia adapters connect Vue authentication, recovery, verification, workspace, and account-session screens to the Laravel backend. Tailwind CSS and a generated shadcn-vue button baseline are installed. The first CRM slice adds private leads, manual stage changes, next-action snoozing, and activity history. Reusable contacts, dedicated reminders, and outreach templates remain M2 work; production deployment is not included. Do not use this development slice with live data.

## Layout

```text
src/                  Laravel application, dependencies, tests, and build tools
  app/                HTTP endpoints, workspace services, and tenant context
  database/           Migrations, PostgreSQL policies, and runtime grants
  resources/          Vue foundation screens, styles, and backend views
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

## Local application setup

Docker uses PHP 8.5 (`php:8.5-cli-bookworm`) with Redis extension 6.3.0. Requires Docker with Compose and network access to download images/packages. Node, PHP, Composer, PostgreSQL, and Redis images are pinned by digest; update those digests deliberately and rerun the acceptance checks to adopt new images. The PHP 8.5 base already includes DOM/Lexbor, mbstring, and SQLite; the Dockerfile adds PostgreSQL, process control, and Redis extensions. For local execution, the Composer constraint remains PHP 8.3+ (including 8.5), with Composer 2, PostgreSQL 16, and the PHP Redis extension. PHP dependencies are locked in `src/composer.lock` (Laravel 13.34.0, PHPUnit 12.5.37). Node 24 is the frontend build/test baseline; a Docker build stage installs the frontend lockfile and bundles the complete client into the PHP image. Vue 3.5.44 and frontend tooling are locked in `src/package-lock.json`.

1. Copy `src/.env.example` to `src/.env`. Generate a base64 32-byte `APP_KEY` and distinct random `DB_PASSWORD`, `MIGRATION_DB_PASSWORD`, and `POSTGRES_PASSWORD`. Never commit `src/.env`.
2. Run `docker compose --env-file src/.env build --pull app migrate worker scheduler acceptance`.
3. Run `docker compose --env-file src/.env up -d --wait postgres redis`.
4. Run `docker compose --env-file src/.env run --rm migrate`, then `docker compose --env-file src/.env run --rm migrate php scripts/grant-runtime.php`.
5. Run `docker compose --env-file src/.env up -d app worker scheduler`. Open `http://localhost:8000/login`; liveness is at `http://localhost:8000/up`. The development HTTP command uses `--no-reload` to retain container-provided configuration.

The database and Redis have no published ports. The web port binds to loopback. Private files use a named volume. Local mail uses an in-memory transport in Compose, so no real recipients are contacted. To test notification URLs, use Laravel notification fakes in automated tests.

PostgreSQL initialization creates separate migration and runtime roles only on a new volume. The migration role owns schema objects and has BYPASSRLS for reviewed bootstrap/lookup functions. Web and worker credentials cannot bypass RLS or own tenant tables. Run migrations/grants through the `migrate` service only. This is a development configuration; production serving/TLS and remaining delivery validation are outstanding.

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

SQL checks exercise denied cross-organization reads/writes, viewer restrictions, revocation, append-only auditing, and commit/rollback context cleanup. PHPUnit integration checks request/service paths, denied/stale workspace selections, and rollback of writes and audit events after failures. It also requires Redis: a unique disposable queue exercises actual worker failure, another tenant's job, and processing after a worker process restart. Test probe jobs live under `tests/Fixtures/` and have no application route. PHPUnit settings override Docker environment values and write caches under writable storage.

The Compose image build, migrations, grants, SQL suite, and six integration tests (109 assertions) passed locally on PHP 8.5.11. The integration suite now includes Inertia workspace projection, partial reload isolation, stale selection cleanup, and named validation errors. The identity/Inertia suite has 23 passing tests (200 assertions). The baseline GitHub backend and clean-setup jobs passed; see [remote evidence](planning/evidence/m1/remote-ci.json).

For the concurrency and process tests, run from the repository root against a migrated **disposable** database:

```sh
docker compose --env-file src/.env run --rm -T acceptance
python3 src/scripts/check-process-restarts.py --project weavdash
```

For the retained `weavdash-m1` project, add `-p weavdash-m1` to Compose commands and use `--project weavdash-m1` for the restart script; the restart command requires an explicit project and deliberately SIGKILLs only its worker/scheduler Artisan processes. Both services use an init process for child reaping. The isolated `acceptance` service uses runtime credentials plus a separate migration-role fixture connection; this control credential is absent from web/worker/scheduler services and removed from runtime probe subprocesses. Fourteen tests passed with 312 assertions, including all five roles, forged identity/tenant fields, revoked membership, and adversarial partial props. The scheduler crash test waits for a real one-minute Redis lock expiry, so the suite takes about 70 seconds.

Concurrent revocation and role demotion are tested in both lock orderings, with PostgreSQL reporting the blocked backend. In-flight authorized transactions finish before membership changes commit; changes that lock first cause waiting writes to fail. A scoped security-definer function locks only the current actor's active membership in the selected workspace. Rollbacks release locks and audit writes. A fresh disposable database also passed full migration rollback/reapply and SQL RLS/function-privilege checks.

Process tests kill a worker after writes but before commit, reclaim its expired Redis reservation in a replacement worker, and verify one committed audit per tenant. Synthetic scheduler tests cover task exceptions, overlapping runs, SIGKILL, real overlap-lock expiration, and dispatch after restart. Docker restart counters also increased after killing the actual worker/scheduler processes. Application schedules remain empty; these tests do not establish external-action idempotency, which belongs to M5.

## Frontend and browser verification

From `src/`, run:

```sh
npm ci --ignore-scripts
npm audit --audit-level=high
npm run typecheck
npm test
npm run build
```

Vite builds `resources/js/app.ts` with lazy Inertia pages and a Laravel-compatible asset manifest under `public/build/`. Docker builds these assets automatically. For local asset development, `npm run dev` provides Vite alongside the running Laravel app; the complete image requires no Vite server.

The screens cover registration, login, recovery/reset, verification/resend, workspace creation/selection, owner/admin settings, and session revocation. Browser mutations redirect; JSON clients retain JSON responses. Validation errors use Inertia's session transport, with separate create/rename error bags. Passwords are cleared after submissions and never included in page props. History encryption, logout history clearing, private/no-store responses, and disabled Inertia DevTools recording limit persistence of identity/reset data. Error pages give generic messages for denied, expired, throttled, or failed browser requests. Each throttled action has a separate counter.

The Tailwind v4 theme preserves the prototype palette. `components.json`, the utility alias, and the generated button source provide the shadcn-vue baseline. The button was generated with the [official CLI](https://shadcn-vue.com/docs/cli) and adapted to this repository's strict template/native attribute checks.

For a **running, migrated disposable Compose stack**, install Chromium and run:

```sh
npx playwright install chromium webkit
npm run test:browser
```

The browser suite defines Chromium and WebKit desktop/mobile projects. Use `npx playwright test --project=desktop --project=mobile` for Chromium only. WebKit coverage uses Playwright on Linux; native Safari/macOS and iOS review remains separate.

Tests default to Compose project `weavdash-m1` and `http://localhost:8000`. For the default setup project, use `COMPOSE_PROJECT_NAME=weavdash npm run test:browser`. `PLAYWRIGHT_BASE_URL` can override the browser address; it must match the app's `APP_URL` for signed verification links. On a fresh Linux host, `npx playwright install --with-deps chromium webkit` also installs browser system dependencies.

Browser tests create synthetic identities via `tests/Browser/fixture.php`, a CLI-only local/testing helper. They exercise registration, signed verification, reset links, invalid login, workspace validation/loading/settings, and revocation of two independent database sessions at desktop/mobile sizes. Mail uses the in-memory transport; link fixtures verify browser/server behavior without an external delivery service. Do not run these tests against a shared or production database. Screenshots/traces are generated under ignored `src/test-results/`; curated [UI evidence](planning/evidence/m1/README.md) is retained separately.

Eleven browser tests pass with one intentional mobile duplicate skipped. Desktop session attacks cover fixation, missing-CSRF login, logged-out cookie replay, reset-session revocation, and consumed reset links. All five roles are exercised in both viewports. Twenty axe scans report zero violations; the evidence records manual review of the decorative initial's contrast. Screen-reader, full browser zoom, and Safari/WebKit review remain open.

From the repository root, source scanning and an isolated fresh setup can be reproduced with:

```sh
python3 src/scripts/check-secrets.py
python3 src/scripts/check-clean-setup.py --project weavdash-m1-clean-review --port 8001
```

The pinned source scanner tests a synthetic positive control and scans tracked/nonignored current source, not Git history or ignored credentials. The setup runner requires Docker Compose, Python 3, Node 24/npm, and Chromium system libraries. It rejects existing project resources, copies source without local dependencies/configuration, generates new credentials, installs dependencies, runs the complete application suites, and removes its own services and volumes. Existing host/build/browser caches may be reused. A local run passed; [saved evidence](planning/evidence/m1/clean-setup.json) records its scope.

The frontend workflow checks dependency advisories, types, interactions, and the application build. The backend workflow includes desktop/mobile Chromium journeys after migration and isolation checks. Baseline remote GitHub execution passed; expanded Chromium/WebKit desktop/mobile coverage also passed on `264e8e3` (22 tests, two intentional skips; 40 axe scans with zero violations).

## Milestone status

See [M1 status](planning/m1-status.md). Manual screen-reader/full zoom and native Safari reviews remain pending. The user authorized M2 development with these reviews carried forward (D19); this does not waive release acceptance. External Brevo mail delivery and link opening passed with user confirmation. The production MFA policy is confirmed for operators and owners (D18); enforcement remains pending before production. See the [manual acceptance checklist](planning/m1-manual-acceptance.md), including the proposed free Brevo SMTP trial. The Git remote is `origin` at `cyrusmanatad/prm`. Package network access and Docker work through approved commands outside the sandbox. `AGENTS.md` remains unchanged as requested.

## Creator pipeline (M2 first slice)

Select a workspace, then choose **Open creator pipeline** (or visit `/pipeline`). Capture a brand/contact, enter private notes and estimated value, move among seven stages, log activity, and snooze a next action to a future date. Filters and currency-separated totals derive from stored rows. Simultaneous edits require explicit reload after a version conflict. Viewer access is read-only. Activity logging does not send email.

See [M2 status](planning/m2-status.md) and the [implemented web contract](planning/m2-pipeline-contract.md) for boundaries and remaining work. No contacts directory, reminder delivery, or onboarding automation is implied.
