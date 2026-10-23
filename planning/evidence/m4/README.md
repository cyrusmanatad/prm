# M4 local verification evidence

Date: 2026-10-23. Branch: `m4/financial-records-entitlements`. Synthetic local data only. See [milestone status](../../m4-status.md) and [contract](../../m4-finance-contract.md).

## Environment and scope

WSL with Node 24.18.0, working Docker Compose, the repository's pinned PHP 8.5.11 image, PostgreSQL 16 and Redis. Locked Composer dependencies were installed in Docker; locked Node dependencies were copied from the successful pinned Node build into WSL after host npm network failures. The initial backend verification preserved dependency versions. The subsequent UI/tooling update added pinned Prettier 3.9.7 to the Node manifest and lockfile. Local Compose mail uses the array transport. ClamAV runs locally for the existing scanner regression. Local `.env` credentials were generated and remain ignored.

Early development used an untracked `/tmp` Compose override with source mounts and a named vendor volume. Final image builds and ordinary Compose services verify the packaged source and Vue assets. The local private-storage volume ownership was corrected to `www-data` when moving away from the WSL-user override. No existing remote environment was changed.

- [Full PostgreSQL acceptance output](acceptance.txt): includes M4 exact money/lifecycle, idempotency, failed-command rollback, cursor paging, all-role/direct RLS attacks, allocation integrity and concurrent retries/competing payments, quota concurrency/downgrade/expiry, CLI administration and public capability quota tests, plus existing milestone regressions. Concurrency tests assert PostgreSQL blocking PIDs rather than assuming contention from timing.
- [PostgreSQL integration output](integration.txt): existing tenant, pipeline, CRM, onboarding, public presence, partnership and worker regression suite.
- [Unit/feature output](unit-feature.txt): exact-money boundary arithmetic and existing identity/feature suite.
- [Browser results](browser-results.json): Chromium desktop/mobile draft and receipt/entitlement journeys. Six axe scans across four tests report zero violations. Includes values above JavaScript's safe integer range, stale-tab reload, receipt allocation/reversal/void, audited pilot assignment, usage visibility, quota rejection with preserved form and continued existing-record access.
- [Fresh-database M4 acceptance output](clean-m4.txt): database `weavdash_m4_clean`, all migrations from empty; M4 rollback/reapply; runtime grants; all ten M4 tables forced RLS; runtime cannot execute the quota definer routine; M4 acceptance rerun against that database. This is a migration/runtime check, not a repeat of the entire historical clean-setup/browser matrix.
- Frontend typecheck, 21 Vitest tests, Vite build, PHP Pint, relative links, whitespace and the pinned source-secret scanner (including its synthetic positive control) were checked. The standalone single-user schema-check script was extended for M4; local host PostgreSQL binaries are absent, so real Docker PostgreSQL migration/catalog/runtime checks supply local database evidence.

## Reproduction

Use a disposable local database with the setup/grants in the root [README](../../../README.md). Run from the repository root:

```sh
docker compose --env-file src/.env build app migrate acceptance
docker compose --env-file src/.env run --rm --no-deps migrate
docker compose --env-file src/.env run --rm --no-deps migrate php scripts/grant-runtime.php
docker compose --env-file src/.env run --rm --no-deps acceptance
docker compose --env-file src/.env run --rm --no-deps app vendor/bin/phpunit -c phpunit.pgsql.xml
docker compose --env-file src/.env run --rm --no-deps app php artisan test --compact
docker compose --env-file src/.env run --rm --no-deps app vendor/bin/pint --test
cd src
npm run typecheck
npm test
npm run build
COMPOSE_PROJECT_NAME=weavdash npx playwright test tests/Browser/finance.spec.ts --project=desktop --project=mobile
```

The browser pilot fixture runs only as a local/testing migration-role CLI command for synthetic finance identities. It creates explicit zero-limit test entitlements, never a commercial default. For the separate clean database, create a fresh database owned by `weavdash_migrator` using the local PostgreSQL administrator, then pass `-e DB_DATABASE=YOUR_DISPOSABLE_DATABASE` to migration/grant/acceptance commands. Perform `php artisan migrate:rollback --step=3 --force` only in that synthetic database, then reapply migrations and grants.

## Screenshots and limits

[Draft desktop](draft-desktop.png), [draft mobile](draft-mobile.png), [payment desktop](payments-desktop.png), [payment mobile](payments-mobile.png). These screenshots and browser results were refreshed from the post-enhancement Chromium run: four tests passed, with zero axe violations. Desktop payments and mobile draft screenshots were visually inspected for the shared cards, navigation, controls, readable money strings, wrapping and accessible form layout. Subsequent changes applied source formatting and contributor/CI standards; type checking and the production build passed again.

No real invoice numbering/issuance, tax calculation, subscription charging, bank connection, payment initiation, transfer, deployment or external message occurred. Commercial pricing/quotas, the active-brand definition, production administration/MFA, retention and carried-forward manual screen-reader/zoom/native Safari release reviews remain open. M4 WebKit coverage has not been run in this session; Chromium mobile emulation is not native iOS acceptance.
