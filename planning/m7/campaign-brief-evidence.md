# Campaign brief verification

2026-11-28, local changes on `c8c2bd1`. Scope follows the [campaign brief contract](campaign-brief-contract.md). Verification used synthetic accounts in disposable PostgreSQL 16 databases with separate migration and runtime roles.

- Fresh migrations, the runtime grant script and the repository SQL RLS checks passed. The full PostgreSQL integration suite (`vendor/bin/phpunit -c phpunit.pgsql.xml`) passed 45 tests with 908 assertions using the image dependencies. Portal integration covered private drafts, missing fields, stale versions, another Brand, another active Partner of the same Brand, direct row reads, recipient list/detail access, workspace export visibility and relationship revocation. A replacement partnership could not reopen the old brief.
- The privacy export regression suite (`vendor/bin/phpunit -c phpunit.acceptance.xml --filter PrivacyExportTest`) passed 11 tests with 144 assertions.
- Chromium at 1280 × 720 and 390 × 664 passed the Brand draft, save, reload, share and Partner read journey. The Brand viewer received HTTP 403 on creation. Axe reported zero violations on the draft and shared detail at both sizes. Visual inspection showed readable wrapped text, labelled controls and a single-column form on mobile, with the current Campaigns link highlighted on desktop. Temporary images were removed.
- The pages reuse the Brand and Partner portal shells, ContentSection, FormField, FieldGroup, SelectField, Button, EmptyState and Pagination. The six existing portal Home journeys passed on Chromium. The new Campaigns journey and both existing Partner navigation journeys also passed with the committed account-menu wording.
- `npm run format:check:changed`, `npm run typecheck`, `npm run build` and scoped Pint checks passed. All 253 Vitest tests passed with only the campaign frontend changes applied to the base revision. The workspace has a separate pending account-menu label change. Four Vitest assertions and the existing desktop/mobile Partner navigation assertion expect its old wording.

## Review follow-up (2026-11-28)

A code review found that workspace closure review omitted campaigns. Migration `2026_11_30_000002_add_campaigns_to_privacy_inventory` adds them to `app_privacy_inventory` for workspaces; rollback restores the earlier definition. The closure impact list labels them **Campaign briefs**. Campaign due dates now use the shared finance date label, for example `Feb 3, 2027`.

- Fresh disposable Compose project with separate roles: migrations, runtime grants and repository RLS checks passed. `vendor/bin/phpunit -c phpunit.pgsql.xml` passed 45 tests with 908 assertions.
- `phpunit.acceptance.xml --filter 'PrivacyClosureTest|PrivacyExportTest'` passed 27 tests with 373 assertions. The new closure test failed after rolling back the inventory migration and passed after migrating again.
- `npm run format:check:changed`, `npm run typecheck`, `npm run build` and scoped Pint passed. Vitest passed 249 of 253 tests; the four failures expect the old account-menu wording from the separate pending change.
- Not rerun: the Playwright campaign journey (only its expected due-date text changed) and visual inspection of the date label.

Staging, production, WebKit and native Safari were outside this local verification. Applying this revision to another environment requires the campaign migration and the usual runtime grants.
