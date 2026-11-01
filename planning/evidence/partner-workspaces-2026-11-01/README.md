# Partner workspaces evidence — 2026-11-01

## Scope

Local verification for branch `bel/initial` at `829cf1b` (`feat: add Partner workspaces and Partner portal home`), implementing the **Confirmed** [D32](../../decisions.md#partner-workspaces) foundation slice of the [Partner portal contract](../../partner-portal-contract.md). The preceding closed-deals change (D31) is `be1e1f0`.

## Changes verified

| Area | Change |
|---|---|
| Data model | Third workspace kind `partner`; the workspace-creation, accept and join functions updated; accepting or joining requires a Partner workspace. |
| Flows | Partner workspaces are created only from a brand invitation or sign-up link and reused per account. `/partner/register` requires a retained invitation or link; `/partner/login` serves existing accounts; a fresh invitation link always prepares first. |
| Partner portal | `/partner` home: greeting, connected brands and partner-since summary, brand list with confirmed End partnership, Join another brand, Register my brand (separate Business workspace) and a "Coming soon" strip. Shares the portal header with the Professional portal. |
| Workspaces | Partner group with "Open Partner portal"; New workspace still offers only Business and Professional. The D31 temporary partnerships section is removed. |
| Migration | Existing partnerships move from Professional workspaces to the owner's Partner workspace, recorded in `partner_workspace_moves`; consented name snapshots unchanged. Rollback refuses once Partner workspaces exist. |

## Results

| Gate | Command (from `src/` unless noted) | Result | Log |
|---|---|---|---|
| Formatting | `npm run format:check -- <frontend files changed since be1e1f0>` | Pass | [format.log](format.log) |
| Types | `npm run typecheck` | Pass | [types.log](types.log) |
| Component tests | `npm test` | 12 files, 55 tests | [test.log](test.log) |
| Production build | `npm run build` | Pass | [build.log](build.log) |
| PHP style | `docker compose --env-file src/.env run --rm -T --no-deps app vendor/bin/pint --test` (repository root) | Pass | [pint.log](pint.log) |
| PostgreSQL integration | `… app vendor/bin/phpunit -c phpunit.pgsql.xml` (repository root) | 33 tests, 677 assertions | [phpunit-pgsql.log](phpunit-pgsql.log) |
| Unit/feature | `… app php artisan test --compact` (repository root) | 29 tests, 256 assertions | [phpunit.log](phpunit.log) |
| PostgreSQL acceptance | `docker compose --env-file src/.env run --rm -T acceptance` (repository root) | 53 tests, 1142 assertions | [phpunit-acceptance.log](phpunit-acceptance.log) |
| Browser journeys (Chromium) | `COMPOSE_PROJECT_NAME=weavdash npx playwright test --project=desktop --project=mobile` | 48 passed, 2 skipped, 0 failed | [browser.log](browser.log) |
| Local data migration | `psql` summary of `partner_workspace_moves` after `migrate` | 76 moved into 66 Partner workspaces, 0 ended as duplicates | [migration.log](migration.log) |

Browser skips are intentional (desktop-only session-attack and drag journeys). The browser run used local mail capture (`TRANSACTIONAL_MAIL_MAILER=array`); SMTP was restored afterwards. Logs have terminal colour codes removed and the local checkout path replaced with `<repo>`; results are unchanged.

The local database is a shared development database containing synthetic test data and real accounts. One partnership remained in a Professional workspace because that workspace has no active owner (only a revoked admin); the migration skips such workspaces by design.

## Screenshots

Synthetic local account that accepted a real invitation; Chromium, 1440×900 desktop and 390×844 mobile.

| Screen | Desktop | Mobile |
|---|---|---|
| Partner portal home | [desktop](partner-portal-desktop.png) | [mobile](partner-portal-mobile.png) |
| Register my brand dialog | [desktop](partner-register-brand-desktop.png) | [mobile](partner-register-brand-mobile.png) |
| Workspaces with Partner group | [desktop](workspaces-partner-desktop.png) | [mobile](workspaces-partner-mobile.png) |

All screenshots were inspected: one-row header with Partner badge and Home, real summary values, connected brand with End partnership, "Coming soon" strip, and the dialog at both widths.

## Unverified gates and open items

- Register my brand has no automated browser journey; it reuses the tested workspace-creation endpoint and was exercised only in the screenshot capture (dialog opened, not submitted).
- The migration rollback was written but not exercised; it refuses once Partner workspaces exist, as they now do locally.
- WebKit projects, manual screen-reader/zoom and native Safari reviews remain pending, as inherited.
- **Assumption / Open:** Partner workspaces show the owner-only "Private finance records" and "Pilot entitlements" links on the Workspaces page, like Professional workspaces. Whether partners should have invoicing is undecided.
- Projects, contracts, messages, files and onboarding remain a **Proposal** in the contract.
