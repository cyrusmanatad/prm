# WeavDash

Creator pipeline and brand onboarding, built as a Laravel modular monolith. This checkout contains the **foundation, creator CRM and M3 onboarding development implementation**, not a finished product. Official Inertia adapters connect Vue authentication, recovery, verification, workspace, and account-session screens to the Laravel backend. Tailwind CSS and a generated shadcn-vue button baseline are installed. The first CRM slice adds private leads, manual stage changes, next-action snoozing, and activity history. Private outreach templates support literal merge, draft editing, and manual copy. Reusable contacts and dedicated reminders support linking, completion and snooze; production deployment is not included. Do not use this development slice with live data.

Partner lead management now lives at `/partner/leads`, with Add lead in a modal and editing/actions in a drawer. Supporting pages use `/partner/contacts`, `/partner/outreach-templates`, `/partner/onboarding` and `/partner/media-kit`. Old page bookmarks redirect; existing JSON and mutation endpoints remain available. See the [partner navigation contract](planning/m3a-portal-contract.md#partner-navigation-and-lead-consolidation-follow-up).

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
3. Run `docker compose --env-file src/.env up -d --wait postgres redis clamav`.
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

Prettier uses the shared configuration in `src/.prettierrc.json`. From `src/`, format selected frontend files with `npm run format -- <paths>` and verify them with `npm run format:check -- <paths>`; for example, `npm run format -- resources/js/pages/Invoices.vue`. Frontend files, including Vue templates, use four-space indentation consistently with `.editorconfig`. Use `npm run format:changed` and `npm run format:check:changed` for new/modified frontend files relative to `HEAD`, including untracked files. Set `PRETTIER_BASE_REF` to a base commit to include already committed milestone changes. Frontend CI checks formatting against the pull request base or previous push; generated files and lockfiles are excluded. See [mandatory UI standards](AGENTS.md#mandatory-ui-standards-for-every-milestone) for the styling, component reuse, and review requirements that apply to every milestone and Codex session.

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

Start from the [current handoff](planning/implementation-handoff.md) and [remaining-task register](planning/remaining-tasks.md). M5's first notification slice is committed and locally verified; required M5 work remains open. [M6 preparation](planning/m6-status.md) has started; acceptance and inherited release gates remain pending.

Current development implementation is described in [M2 status](planning/m2-status.md), [M3 status](planning/m3-status.md) and [M3A status](planning/m3a-status.md); earlier foundation test counts in this README are historical. See [M1 status](planning/m1-status.md). Manual screen-reader/full zoom and native Safari reviews remain pending. The user authorized M2 development with these reviews carried forward (D19); this does not waive release acceptance. External Brevo mail delivery and link opening passed with user confirmation. The production MFA policy is confirmed for operators and owners (D18); enforcement remains pending before production. See the [manual acceptance checklist](planning/m1-manual-acceptance.md), including the proposed free Brevo SMTP trial. The Git remote is `origin` at `cyrusmanatad/prm`. Package network access and Docker work through approved commands outside the sandbox. `AGENTS.md` remains unchanged as requested.

## Creator pipeline (M2 first slice)

Select a workspace, then choose **Open creator pipeline** (or visit `/pipeline`). Capture a brand/contact, enter private notes and estimated value, move among seven stages, log activity, and snooze a next action to a future date. Filters and currency-separated totals derive from stored rows. Simultaneous edits require explicit reload after a version conflict. Viewer access is read-only. Activity logging does not send email.

See [M2 status](planning/m2-status.md) and the [implemented web contract](planning/m2-pipeline-contract.md) for boundaries and remaining work. The pipeline links to reusable contacts/reminders, manual templates, public presence and onboarding. Reminder delivery and automatic stage/onboarding actions remain outside this implementation.

Open **Outreach templates** from the pipeline to save private plain-text templates, fill named merge fields, review/edit a draft, and copy it manually. No message is sent. See the [template contract](planning/m2-outreach-template-contract.md) and [local verification](planning/evidence/m2/outreach-templates.json).

## Contacts and onboarding

Use **Contacts and reminders** to manage reusable private contacts, link them to several leads, and create/complete/reopen/snooze reminders. Open the lead-specific link to associate records with that lead. See the [CRM contract](planning/m2-crm-contract.md).

Choose **Start onboarding** on a lead to create a private invitation. Copy its one-time URL and open it in a separate browser session to test the brand journey. Drafts resume at the saved form URL in that browser; submission requires explicit sharing consent. The creator reviews submitted forms, requests corrections, accepts, replaces invitations or revokes access. No email is sent. **Public presence** manages explicit media-kit publication, up to eight manual social-account links, and revocable inbound lead links. See [M3 status](planning/m3-status.md) and the [contract](planning/m3-onboarding-contract.md).

The pinned official ClamAV service runs on the private Compose network with no published port. Its signature database is stored in a separate development volume. Application services wait for scanner health; startup needs more memory/time than the foundation-only stack. Uploads are optional non-sensitive PDF/PNG/JPEG/text references, at most 2 MB each and five per request. Pending/rejected files cannot be downloaded or submitted; the creator can retry a pending scan. Provider selection, retention/orphan reconciliation and production deployment remain separate gates.

The host lacks WebKit system libraries. Full fresh-setup verification can run in the pinned official Playwright container, which includes Node 24, Chromium/WebKit and their libraries. The retained [M3 evidence](planning/evidence/m3/README.md) records the image digest, isolated synthetic project, command and exact scope. This covers Linux WebKit; native Safari and manual assistive-technology/zoom acceptance remain pending.

## Connected partner and brand portals (M3A)

Use `/partner/login` or `/brand/login` with your existing account, or register from the matching portal. Registration provisions the matching workspace after email verification, including when verification occurs after signing out. Existing workspaces remain creator workspaces. Create additional partner or brand workspaces from `/workspaces` and open the selected workspace's portal. The legacy `/login` remains available.

Brand owners/admins choose **Invite partners**, enter the recipient email and copy the one-time link. Select **Email this invitation to the recipient** to request queued delivery, then check invitation history for status. Compose defaults to local mail capture. The verified recipient signs in, selects a creator workspace they own/administer and explicitly accepts the partnership. Both portals then show the same relationship. Either side's owner/admin can end it without deleting private records.

Real dashboards use stored private pipeline estimates and accepted relationships. Financial dashboard metrics, automatic invoicing/payouts, AI, campaign performance, tiers and automation remain explicitly unavailable; private M4 financial records are available separately from Workspaces. Both exact dashboard compositions can be checked with the isolated visual fixture described in [M3A evidence](planning/evidence/m3a/README.md); fixture data is never enabled by application routes. The board shows five columns while all seven stored stages, filters, detail access and totals remain supported. See [M3A status](planning/m3a-status.md) and [contract](planning/m3a-portal-contract.md).

## Financial records and pilot entitlements (M4)

Select a workspace you own and choose **Private finance records** at `/workspaces`. Draft invoices at `/finance/invoices` preserve exact minor-unit strings and support versioned editing and terminal voiding. `/finance/payments` records manual historical receipts, partial allocations and append-only reversals. No invoice is issued and no payment is initiated. Owners can view explicit pilot plan/usage state at `/workspace/entitlements`.

Pilot administration uses local/testing migration credentials and the audited `pilot:plan` / `pilot:assign` Artisan commands. No commercial prices, quota numbers or assignments are seeded. An unassigned workspace is explicitly development use; assigned plans enforce explicit record limits transactionally, including public lead capture, while expiry/downgrade preserves existing records. See the [M4 contract](planning/m4-finance-contract.md), [status](planning/m4-status.md) and [verification evidence](planning/evidence/m4/README.md) for boundaries and reproducible commands. Pricing, active-brand semantics and production operator/MFA policy enforcement remain separate gates.

## Durable partner invitation email (M5 first slice)

Invitation email requires an explicit selection in the brand dialog or `send_email: true` on the invitation request. The invitation, redacted audit and encrypted notification intent commit together. The scheduler runs `notifications:dispatch` every minute; workers recheck current authorization, recipient and invitation validity before sending. Redis replay cannot repeat a reserved send. Interrupted/ambiguous SMTP delivery requires review and is never automatically resent.

Compose app/worker/scheduler share `TRANSACTIONAL_MAIL_MAILER`, defaulting to `array` for local capture. The optional `smtp` override uses the shared `MAIL_*` transport/sender settings. A capture or server acceptance does not prove external receipt. Start the updated app, worker and scheduler after migration; see the [M5 contract](planning/m5-notification-contract.md) for commands and failure behavior. [M5 status](planning/m5-status.md) tracks remaining onboarding notifications, scheduling/digests and approval workflows. No mailbox integration is required.
