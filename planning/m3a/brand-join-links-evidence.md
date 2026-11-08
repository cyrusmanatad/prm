# Brand partner sign-up links: local verification

Date: 2026-11-08. Source: current working tree on `6975570`, including the preserved Professional Hub and pipeline-drag work. Reference: `bel/initial` commit `c5b91c6f8914cac7f65d67898b04e333aa039beb`, inspected with `git show`/`git ls-tree` only. No merge, cherry-pick, checkout, reset, data cleanup or production deployment occurred. [Contract](portal-contract.md).

## Environment and migration

Existing isolated Compose project `weavdash-professional`, restricted `weavdash_app` runtime, migration-role fixture control, existing PostgreSQL/Redis/ClamAV volumes, current source bind mount and production frontend bundle. All new identities/data are synthetic. Existing accounts, demo records, workspaces and accepted relationships are preserved. Browser mail verification uses signed local test links; external email delivery is outside this feature.

Run from the repository root with the current source mounted:

```sh
docker compose --env-file src/.env -p weavdash-professional run --rm -T --no-deps -v /home/cyrusadmin/weavdash/src:/var/www/html migrate php artisan migrate --force
docker compose --env-file src/.env -p weavdash-professional run --rm -T --no-deps -v /home/cyrusadmin/weavdash/src:/var/www/html migrate php scripts/grant-runtime.php
```

The additive `2026_11_08_000001_create_partner_join_links` migration applied successfully (71.50 ms); a preceding image-only invocation reported nothing to migrate because the image predated this source. Re-running standard runtime grants passed and retained read-only table privileges. No database reset occurred. The down migration deliberately refuses when any partnership originated from a reusable link, including revoked relationships. The rollback-refusal test executes inside a rolled-back fixture transaction and verifies the relationship survives. Do not remove accepted relationships to force rollback.

Local test commands below use `-f compose.yaml -f /tmp/weavdash-pipeline-compose.yaml` with `-p weavdash-professional`. That retained override binds the source for app/migrate/acceptance, runs as UID 1000 and mounts the existing test-only private storage directory; it adds no application behavior or production configuration.

## Automated gates

| Command (npm/PHP tooling from `src/`) | Result / retained output |
| --- | --- |
| `npm run format:changed`; `npm run format:check:changed` | 39 changed frontend files; [format](../evidence/brand-join-links/format.txt), [check](../evidence/brand-join-links/format-check.txt). Pinned Prettier and four-space configuration. A sandbox Git subprocess rejection was resolved by running the same formatter with approved permissions. |
| `npm run typecheck` | Passed; [output](../evidence/brand-join-links/types.txt) |
| `npm test` | 68 tests passed; [output](../evidence/brand-join-links/components.txt) |
| `COMPOSE_PROJECT_NAME=weavdash-professional npx playwright test join-links.spec.ts --project=desktop --project=mobile` (focused management and existing-account runs) | Four distinct journeys passed across both viewports; [management/signup](../evidence/brand-join-links/browser.txt), [existing-account/expiry/Professional](../evidence/brand-join-links/browser-expiry.txt). |
| `COMPOSE_PROJECT_NAME=weavdash-professional npx playwright test portals.spec.ts professional.spec.ts pipeline.spec.ts pipeline-drag.spec.ts --project=desktop --project=mobile` | 18 passed; [output](../evidence/brand-join-links/browser-regressions.txt). |
| `npm run build` | Passed; [output](../evidence/brand-join-links/build.txt) |
| `python3 src/scripts/check-secrets.py` | Positive control passed; two inherited checksum false positives in `planning/evidence/pipeline-drag/source-sha256.json:10` and `planning/evidence/m5/schedules/source-sha256.json:7`; [redacted output](../evidence/brand-join-links/secrets.txt). Scanner rules unchanged. |
| `vendor/bin/pint --dirty --test` | Passed; [output](../evidence/brand-join-links/pint.txt) |
| `acceptance vendor/bin/phpunit` | 30 tests, 265 assertions passed; [output](../evidence/brand-join-links/identity.txt) |
| `acceptance vendor/bin/phpunit -c phpunit.pgsql.xml --filter 'PortalTest\|PipelineTest'` | 13 tests, 245 assertions passed; [output](../evidence/brand-join-links/portal-pipeline.txt) |
| `acceptance vendor/bin/phpunit -c phpunit.acceptance.xml --filter 'JoinLinkTest\|PartnershipSecurityTest\|ProfessionalHubTest'` | 21 tests, 364 assertions passed after standard runtime grants; [output](../evidence/brand-join-links/security.txt) |

Join-link PostgreSQL coverage includes normalization, forced RLS, runtime grants, owner/admin boundaries and verified acceptance, selected/sole/multiple eligible workspaces, Professional destination, consent denial, expiry/context precedence, replacement/disable, stale IDs, duplicate acceptance, real lock waits for acceptance/disable in both orderings, audit rollback and rollback refusal. Existing invitation tests verify recipient binding, expiry, replay, private-record boundaries and membership revocation. Existing Professional tests preserve subscription/permission behavior. Component tests cover code entry, guest/verification/workspace recovery, named consent, selectable copy fields, manual-copy recovery messages, pending controls and confirmation requests without early mutations.

## Browser acceptance and inspected screenshots

Browser commands run from `src/` with `COMPOSE_PROJECT_NAME=weavdash-professional`, `--project=desktop --project=mobile`, production assets and synthetic local fixtures. The session-expiry fixture only updates the test identity's join-context expiry using the application's JSON session format. It is CLI-only and unavailable over HTTP.

All four distinct join journeys pass across the desktop/mobile runs: creation/copy/manual recovery; fresh signup and signed verification; selected-workspace consent; non-nested confirmations with Cancel focus, Escape and request-completion trigger restoration; replacement/disable with partnership preservation; invalid/duplicate codes; existing-account login and multiple-workspace selection; Professional routing; expiry and typed-code recovery. Final management/signup results are in [browser.txt](../evidence/brand-join-links/browser.txt); final existing-account/expiry/Professional results are in [browser-expiry.txt](../evidence/brand-join-links/browser-expiry.txt). A separate [mobile management](../evidence/brand-join-links/browser-mobile.txt) rerun also passed. The retained [earlier consolidated attempt](../evidence/brand-join-links/browser-consolidated-attempt.txt) includes test-navigation/selector failures corrected before these focused final runs. All 18 affected portal/Professional/pipeline/dragging regressions pass in [browser-regressions.txt](../evidence/brand-join-links/browser-regressions.txt). Axe scans report no violations on the checked Brand dialog, public join and consent screens, with no horizontal viewport overflow.

Inspected screenshots: [Brand desktop](../evidence/brand-join-links/brand-desktop.png), [Brand mobile with manual-copy recovery](../evidence/brand-join-links/brand-mobile.png), [consent desktop](../evidence/brand-join-links/join-desktop.png), [consent mobile](../evidence/brand-join-links/join-mobile.png). Native invitation dialogs scroll within the viewport; the mobile capture shows the reusable-link section after using Copy code. Synthetic codes captured here were subsequently replaced/disabled.

Earlier runs caught duplicated auth context and request-completion focus restoration, which were corrected. Test selectors were aligned with the existing account menu/workspace list/partnership row. The existing-account test now waits for workspace creation and logout navigation to finish before reopening the join URL, removing an intermittent navigation race. One run crossed an asset rebuild and correctly performed an Inertia version reload; final checks hold the build stable. No assertion was removed to bypass those failures.

## Scope and remaining gates

Reuses `FoundationShell`, `FormField`, shared button styles, `useNotify`, native dialog focus containment and the existing `ConfirmationDialog`/`confirmAction`. `JoinLinkPanel` contains only reusable-link management; the existing email-invitation form and recipient contract remain intact. No new CSS system or subscription/workspace classification is introduced. New portal/auth props are optional; existing JSON actions retain their responses.

Automated Chromium/Axe checks do not close inherited manual screen-reader/native Safari or release review gates. No external provider acceptance or production migration/deployment is claimed. M5-07 and the remaining administration/release work retain their previous status.

## Local app rebuild follow-up

On the user's request after verification, rebuilt `weavdash-professional-app` with the retained source-bind override, then recreated only the `app` service using `up -d --no-deps --force-recreate app`. Existing database/storage volumes and the other services were preserved. Migration-role status confirmed the join-link migration is applied. HTTP checks from the restarted app confirmed `/join` serves `JoinBrand` and the served Portal bundle contains **Share a sign-up link**. The controls are under **Brand Hub → Invite partners** for Brand owners/admins.
