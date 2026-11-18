# Staging presentation verification

**Confirmed — 2026-11-18 UTC:** approved presentation plan implemented and provisioned on staging. Source: working tree based on `27c1a7d`. Dataset, access model, commands, credentials handling and walkthrough: [dataset documentation](staging-presentation-dataset.md). Existing milestone/release gates are unchanged.

## Implementation and local verification

Added `staging:seed-presentation`, its fixture writer, fixed JSON manifest, PostgreSQL acceptance test and optional browser verifier. No schema, product UI, runtime configuration or platform-grant changes.

Manifest SHA-256: `8114860b2ee9df2da75c7e263846655d85d0b1c8cbefe7ebf31ea5069d166025`.

| Check | Result |
| --- | --- |
| Fresh migrations and runtime grants | All migrations applied to separate disposable PostgreSQL database `weavdash_presentation_final_20261118`, on local `weavdash-brand-postgres-1`; existing development databases preserved |
| `vendor/bin/phpunit -c phpunit.acceptance.xml --filter PresentationDatasetTest` | **1 test / 151 assertions passed**, PHP 8.5.11, PostgreSQL migration control plus restricted `weavdash_app` runtime |
| `vendor/bin/phpunit` | **42 tests / 362 assertions passed** |
| `vendor/bin/pint --test app/Services/PresentationDataset.php app/Console/Commands/StagingSeedPresentation.php tests/Acceptance/PresentationDatasetTest.php` | Passed, 3 files |
| `npm run format:check -- scripts/verify-presentation.mjs database/fixtures/northline-living-v1.json` | Passed with pinned Prettier |
| `node --check src/scripts/verify-presentation.mjs` | Passed, from repository root |
| `python3 src/scripts/check-secrets.py` | Positive control and source scan passed; no unreviewed findings |
| Markdown relative links / `git diff --check` | Passed |

The host PHP 8.3 cannot load the current lockfile, so PHP checks used the existing compatible `weavdash-professional-app` image with the working tree mounted. A private temporary runner supplied migration/runtime credentials without printing them. Acceptance requires a fresh disposable database because it verifies actual commit and runtime access across separate connections. No fixture/helper DDL was installed on staging.

The test covers staging/runtime-role guards, a no-write dry run, foreign-account collision, injected insertion rollback, injected deferred-commit rollback with durable roster retention, exact counts, seven stages, reminder categories, fixture registration, approval provenance, quotas, 90-day duration, repeat execution without duplicates/password changes, preserved stage/note edits, missing ownership/record refusal, and every account's real PostgreSQL RLS context. Brand HTTP projection has 13 relationships and no pipeline; other Partners cannot mutate foreign leads. No notification intents or account notices are created.

No Vue/TypeScript/CSS or product markup changed. Frontend type/build/component suites were not rerun; existing deployed assets were inspected directly. Native Safari, assistive-technology/manual release acceptance and existing commercial/provider gates remain outside this dataset task.

## Staging backup and execution

Inspected staging first: app/worker/scheduler image `weavdash-staging-app:c7cbb57`, matching web image `c7cbb57`. These images and their running services were preserved. The command/service/manifest were mounted read-only into the existing migration image for one-off provisioning; no application deployment, service restart or migration was required. The reviewed source is retained under `/home/cyrusadmin/weavdash-staging/presentation-v1/source/`.

Before the dry run, PostgreSQL custom-format dump and private configuration archive were saved at:

- Host: `/home/cyrusadmin/weavdash-staging/backups/presentation-20261118-044845/`.
- Checksum-verified off-host copy: `/tmp/weavdash-presentation-private/presentation-20261118-044845/`.
- Database dump SHA-256: `91fc7fdba8e4c2af30df613b96e1f835f7bd02d8f634dcae4bc545c6110705c1`.
- Configuration archive SHA-256: `a45a6aa7fa6563c985f40982c84247b99fabb24b94900dfb112b84a197681939`.

Configuration backup covers `staging.env`, `smtp.env`, `storage.env`, existing `reviewers.json`, canonical Compose and the mail-trial override. This data-only addition touches no files in R2; existing objects were not copied for this operation.

The staging dry run returned `would_create`, 14 verified accounts, 13 Partners, 273 leads, USD and 90 days. All identifiers and name-based Mailinator addresses matched the manifest, with no collisions. Executed at **2026-11-18 04:51:24 UTC**. Approval records carry that actual timestamp and explicit presentation-provisioning provenance; no administrator review or historical reviewer was invented. Entitlements end **2027-02-16 04:51:24 UTC**.

Both a dry repeat and a real repeat returned `preserve`. The private runner remains available from `/home/cyrusadmin/weavdash-staging`:

```sh
bash presentation-v1/run.sh php artisan staging:seed-presentation /run/presentation-private/roster.json --dry-run
```

The roster is mode 600 in the mode-700 host directory `presentation-v1/private/`; its absolute host path is `/home/cyrusadmin/weavdash-staging/presentation-v1/private/roster.json`. A mode-600 local copy is at `/tmp/weavdash-presentation-private/roster.json`. Credentials were not printed or added to Git. The existing reviewer roster and all private configuration checksums remain unchanged.

## Stored records and preservation

Immediately after provision: 14 new accounts/workspaces, 13 accepted invitations/active partnerships, 273 leads, 273 contacts, 273 contact links, 273 reminders, 546 dated seed activities, 14 entitlements and 28 fixture marks in one batch. All seven stages contain **39 leads** each. Reminder states after verification: **54 completed, 60 overdue, 159 upcoming**. Verification restored stage/reminder states and left six genuine stage-change activities, so the final activity count is 552.

No dataset onboarding requests, invoices, manual payments, notification outbox rows, account-notification rows or platform grants exist. No uploads or Professional workspaces were provisioned. Every original record in 22 snapshotted business/account tables still matched its pre-seed row after provisioning and again after browser verification. That includes existing accounts, workspaces, memberships, CRM, relationships, entitlements, Brand approvals, onboarding, finance and platform grants. Configuration files also passed their original checksum check after verification.

## Runtime and rendering

Ran `scripts/verify-presentation.mjs` against staging HTTPS with the private roster, `PRESENTATION_EXERCISE_EDITS=1` and temporary `/tmp/` captures. **17 authenticated sessions passed**: all 14 accounts at **1440×900**, plus Northline Living, Maya Chen Studio and Bennett Living Studio at **390×900**. No JavaScript page errors or document-level horizontal overflow.

Checks included actual password login, denied administrator access, Brand's 13 relationships and empty private pipeline, foreign-lead reads denied for other Partners and Brand, correct per-Partner counts, USD estimates, pagination for 26/27-lead lists, `Northline` search, lead drawer access, UI stage save and restoration, linked-contact region, reminder Due/Completed/Pending filters and reminder complete/reopen with version checks. Browser mutations used normal authenticated product endpoints and are retained as actual verification activity.

Visual inspection: existing cream/burgundy palette, typography, cards, controls and responsive patterns remain consistent. Company names, money, contacts and invented-data notes render clearly. Desktop board and Brand's 13-row table are readable; phone layout stacks cards and retains existing internal horizontal scrolling for board columns and the Brand table. Contacts/reminders wrap without page overflow. Existing unavailable metrics remain truthfully labelled. No product UI changes were needed. All 14 temporary inspection screenshots were removed; no images were retained in the repository.
