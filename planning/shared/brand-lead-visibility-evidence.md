# Brand lead visibility — verification evidence

**Scope:** the [implementation contract](brand-lead-visibility-contract.md) for [D38](decisions.md#d38--brand-lead-visibility-and-partner-records), tasks BLV-01–BLV-06. **Date:** 2026-11-18. **Revision:** branch `feat/brand-lead-visibility`, working tree on `main` at `bf63e55`; the gates ran on that tree before it was committed.

**Environment:** two disposable local Compose projects created for this work from `compose.yaml` with a scratch override (bind-mounted `src/`, in-memory mail, owner MFA off by default, no ClamAV): `weavdash-blv` (`localhost:8030`, used for browser journeys) and `weavdash-blvfresh` (fresh database for migration, SQL and full-suite runs). PostgreSQL 16, Redis, PHP 8.5.11, Node 24.15.0, Playwright 1.64.1 (Chromium). Runtime role `weavdash_app`; fixtures use the separate migration-role control connection. No shared, staging or production system was touched and no email was sent.

## Commands and results

| Command | Result |
| --- | --- |
| `php artisan migrate --force`, `migrate:rollback --step=1`, `migrate --force`, `php scripts/grant-runtime.php` (fresh database) | Passed. The new migration applies, rolls back while empty and reapplies. |
| `tests/Integration/rls-fixtures.sql`, then `tests/Integration/rls.sql` | `RLS_CHECKS_PASSED`, including the new catalog block: forced row security on the three tables, read-only policies only, no runtime write privilege, no direct access to sign-in times, fixed search path and no `PUBLIC` execute on the ten functions, three triggers enabled. |
| `vendor/bin/phpunit -c phpunit.pgsql.xml` | 35 tests, 640 assertions, passed. |
| `vendor/bin/phpunit` | 42 tests, 362 assertions, passed. |
| `vendor/bin/phpunit -c phpunit.acceptance.xml` (fresh database) | 198 tests, 5,133 assertions: 193 passed, 3 skipped, 2 failed. Both failures are the known environment ones (`OnboardingSecurityTest` needs ClamAV; one `SyntheticRecoveryTest` SMTP test). Both also fail on the unchanged tree in this stack (checked with the changes stashed). |
| `vendor/bin/phpunit -c phpunit.acceptance.xml --filter 'BrandLeadVisibilityTest\|PresentationDatasetTest\|PartnershipSecurityTest\|JoinLinkTest\|PlatformGovernanceTest\|OwnerMfaTest\|PipelineRoleTest\|ProfessionalHubTest'` (final tree) | 47 tests, 1,500 assertions, passed. |
| `vendor/bin/pint --test <12 changed PHP files>` | Passed. `--dirty` is unavailable inside the container (no Git), so the files were named. |
| `npm run format:check:changed`, `npm run typecheck`, `npm test`, `npm run build` | 20 changed frontend files formatted; types clean; 27 files, 146 tests passed; build succeeded. |
| `npm run test:browser -- brand-lead-visibility.spec.ts portals.spec.ts partner-navigation.spec.ts join-links.spec.ts professional-ui.spec.ts pipeline.spec.ts --project=desktop --project=mobile` | 24 journeys. First run: 20 passed, 4 failed on two expectations this change makes obsolete (the Partner name cell is now a row header; the Partner account menu has one more link). After updating them, the 6 affected runs passed. The new journey passed on both projects in every run after its own fixes. |
| `python3 src/scripts/check-secrets.py`, `python3 src/scripts/check-postgres-schema.py`, `git diff --check` | Passed. |

## What the acceptance class proves

`tests/Acceptance/BrandLeadVisibilityTest.php` (8 tests) runs as the real runtime role against PostgreSQL row security, with two Brands and several Partners:

- **Producer:** a lead stays invisible until registered; viewer, member and manager are refused; foreign lead and partnership IDs are refused; a retried request creates nothing and keeps the first registration time; one audit event.
- **Isolation:** another Brand, another Partner, a revoked member and an anonymous caller read nothing, through JSON, full Inertia pages and partial reloads; a cross-Brand `partner` filter returns an empty list; under a Brand context the runtime role reads no private pipeline rows and cannot write the shared tables or read sign-in times directly; a Brand gains no rights over the source lead.
- **Projection:** list and detail contain only the allowlisted keys; notes, value, unshared contact, private lead name, source ID and account email are absent.
- **Source changes:** stage moves through all seven stages appear without re-registration; notes, value, name and activity edits change nothing shared; a contact change is shared only when opted in; stale and failed writes leave lead and registration unchanged together.
- **Lifecycle:** versioned withdrawal and re-share with the original registration time; source deletion and its rollback; suspension of either side; ending a partnership and reconnecting without revival.
- **Counts:** zero, two Brands, all seven stages, old lead with recent registration, the exact 720-hour boundary, one microsecond outside it, a future timestamp, withdrawn rows; the card equals the unfiltered list total across pages; the row count equals its filtered list.
- **Roster paging:** 0, 1, 10, 11 and 13 Partners with identical acceptance times; no duplicates or gaps; totals on every page; literal search; a page past the end; ending every partnership on the last page.
- **Last active:** recorded for a completed sign-in to the entered workspace only; not for a plain request, wrong password, suspended account, another workspace of the same account, a Brand sign-in, or pending/failed MFA; recorded after a passed authenticator check and after a recovery code; another Brand's lead and private edits do not move the signal; a sign-in older than the partnership is not disclosed; an exact tie reports the lead event.
- **Concurrency (real lock waits):** a revocation holding the partnership denies the waiting registration when it commits and allows it when it rolls back; a registration holding the lock completes and the waiting revocation then ends it; a membership revocation that locks first denies the waiting registration.
- **Profile:** nothing is published by default; unsafe addresses are refused by validation and by the table constraint; versioned saves; another Brand reads nothing.

## Rendering and interaction

Chromium, inspected by temporary full-page captures (written outside the repository and removed) and by the journey's assertions. Viewports: **1440×900 and 1920×900** (desktop project), **390×900 and 320×900** (mobile project).

- **Screens checked:** Brand hub, Lead registrations, registered lead, Partner record, Partner Brand sharing (form and list states), and both confirmation dialogs.
- **Every screen, every width:** axe reported no violations; the document did not overflow horizontally; on the mobile project no visible control in the main content was under 44px.
- **Findings fixed during inspection:** heading order on the Partner record (stat cards had no parent heading); the back link's icon wrapped above its text; two new hub links, the existing disabled `Edit rules` button, and standalone links/actions on the new screens were under 44px on phones.
- **Layout:** the roster keeps its bounded, keyboard-focusable scroll region on phones; registration tables become labelled cards below 600px; the header navigation wraps without overflow at 320px.
- **Interaction:** the roster pages with totals unchanged; reload and Back keep the page; search returns to page one and keeps its text in the address; the 30-day count and the summary card open lists whose totals match; a filtered list is restored after opening a lead and returning; the icon button opens the confirmation from the keyboard, focuses Cancel, closes on Escape and restores focus; Cancel and Escape send no request and Confirm sends exactly one; ending all partnerships on page two returns a valid first page.
- **Reuse:** `BrandChrome` was extracted from the hub and is shared by the four Brand screens; the new screens use the existing `DataTable`, `FilterBar`, `Pagination`, `DetailList`, `StatCard`, `StatusBadge`, `ContentSection`, `EmptyState`, `FormField`, `FieldGroup`, `SelectField`, shared Button, `BackLink`, `confirmAction` and `useListFilters`. One feature component was added (`RegistrationTable`, used by the list and the Partner record).

## Not verified

- **WebKit:** the host lacks WebKit system libraries (`browserType.launch` refused); only Chromium ran. Native Safari, screen-reader and browser-zoom review were not done.
- **Full browser suite and clean-setup runner:** only the new journey and the five affected specs ran.
- **ClamAV-dependent and SMTP-fault acceptance tests:** not available in this stack (see the table).
- **Staging and production:** nothing was migrated, seeded or deployed. Behaviour with the real staging dataset is unverified.
- **Scale:** list and roster queries were exercised with tens of rows, not measured at volume.

## Staging runbook (not executed)

Requires its own authorization; the earlier presentation-seeding authorization does not cover it.

1. Back up the staging database. Deploy the application and web images built from the merged revision.
2. Run `php artisan migrate --force` through the `migrate` service, then `php scripts/grant-runtime.php`. Both are required: the grant script removes the runtime role's write privileges on the new tables.
3. Check as the migration role that `lead_registrations`, `workspace_sign_ins` and `partner_shared_profiles` exist and are empty, and that `has_table_privilege('weavdash_app','lead_registrations','INSERT')` is false.
4. Sign in as the Northline Living owner: the hub should show 13 active partners over two roster pages, `Leads registered` 0, every `Leads (30d)` 0 and `Last active` `Not yet recorded` until a Partner signs in. The 273 private leads stay private.
5. **Backfill:** none. No existing lead or profile field is published. Demonstration registrations (for example the 13 Northline entries proposed in the handoff) need a separately versioned, reviewed, staging-only manifest and command; do not edit `northline-living-v1.json`.
6. **Rollback limits:** `migrate:rollback --step=1` works only while no registration or shared profile exists. After that it refuses; roll the application image back and fix forward. Rolling back the image alone is safe: the new tables and triggers do not affect older code.
