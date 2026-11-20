# Closure restriction verification and handoff

Date: 2026-11-20. Scope: M3-02 / PRIV-03. Revision: implementation on `feat/privacy-closure`, based on `64b1a6f` (`feat: add private account and workspace exports`). **Confirmed:** the user requested the prior changes be committed first, then the next slice implemented with updated docs. The export commit was created before closure implementation. This report does not advance erasure or production acceptance.

## Implemented behavior

[Delivered contract](privacy-contract.md#delivered-priv-03-closure-interface): personal/owned-workspace impact review, named modal confirmation, fresh password/single-use MFA, current manifest/version checks, 30-day minimum boundary, immediate scoped access restrictions, cancellation without capability/publication/mail replay, protected last owners/platform operators and metadata-only accountable `platform.owner` handling. No record erasure or automatic erasure job exists.

Private request/event tables force RLS without runtime table privileges. Fixed-search-path functions expose exact self/owner operations and minimized handler projections. Closure barriers cover tenant context, repeatable-read exports, public/onboarding entry points, both outboxes and direct reset/verification mail. Existing finance/audit/document/history records remain. Export revocation is permanent and feeds existing exact-file cleanup.

Reuses FoundationShell/AdminShell, ContentSection, FormField, SelectField, DetailList, StatusBadge, Pagination, shared Button, validation focus and `confirmAction`/ConfirmationDialog. Added ClosureImpact, ClosureForm, ClosureCancellation and PrivacyReviewCard for shared responsibilities. No new style system.

## Environment and commands

Only synthetic data: disposable Compose project `weavdash-closure`, PostgreSQL databases `weavdash_closure` and `weavdash_closure_fresh`, Redis, temporary private storage and local app at `127.0.0.1:8013`. Runtime is restricted `weavdash_app`; migrations use the separate migration owner. Temporary Compose/env files contain generated test-only secrets and are not repository artifacts. Existing local/staging data and services were untouched. Tests use array/capture mail, never external recipients.

Commands below run from `src/` unless otherwise indicated. Compose runs use the private temporary configuration from the repository root; its paths are environment details, not a reusable deployment command.

| Verification | Result |
| --- | --- |
| Fresh full `php artisan migrate --force`; closure `migrate:rollback --step=1 --force`; reapply; refresh `database/sql/runtime-grants.sql` | Passed in fresh database before requests. Rollback with synthetic closure history refused as designed, preserving barriers/history. |
| `vendor/bin/phpunit -c phpunit.acceptance.xml --filter 'PrivacyClosureTest\|PrivacyExportTest'` | **26 tests / 362 assertions passed** against the final cleanly migrated PostgreSQL schema, including physical removal of revoked export files. |
| Acceptance regression filter: OwnerMfa, BrandApproval, BrandLeadVisibility, NotificationOutbox, OnboardingNotification/Lifecycle, ScheduledNotification, BusinessSchedule, DeliveryOperations, MailBudget, FollowUpApproval/Draft, PlatformGovernance, PartnershipSecurity, ConcurrentMembership | **135 / 3,423 passed**. |
| Synthetic `tests/Integration/rls-fixtures.sql`, then raw `rls.sql`, then `vendor/bin/phpunit -c phpunit.pgsql.xml` | Raw isolation checks passed; **35 / 649** integration checks passed. |
| `vendor/bin/phpunit -c phpunit.xml` | **53 / 589 passed**. |
| `npm test` | **29 files / 158 component tests passed**. |
| `npm run format:changed`, `npm run format:check:changed`, `npm run typecheck`, `npm run build`, `vendor/bin/pint --dirty` | Passed. Formatter needed normal Git access outside the restricted subprocess; no gate changed. |
| `npx playwright test privacy-closure.spec.ts --project=desktop --project=mobile --reporter=list` against isolated app | **6 journeys passed**: account close/sign-out/restricted re-login/cancel; workspace restriction/cancel; operator assign/review. Axe reported no violations. |

Tests prove private-manifest/raw-table denial, hostile foreign self-service denial, required ownership/MFA, stale fingerprint/version rejection, preserved finance/documents, other-account/workspace isolation, no publication/relationship/export replay, scope-specific cancellation and retained uncertainty/mail consumption. Real PostgreSQL processes verify both lock orders for workspace work and direct mail, account-outbox admission, and simultaneous account closures by two owners. A stale repeatable-read snapshot initially read old data after closure; stronger user/workspace anchor locks fixed it, and the regression passes. Existing outbox and membership lock-order tests also pass.

`python3 src/scripts/check-secrets.py` passed: 1,395 reviewable files, no unreviewed findings. All 679 local Markdown file targets across changed documentation resolved; `git diff --check` passed.

## Rendering and interaction findings

Chromium desktop **1280×720** and mobile **390×664** use the approved magenta/cream shells, consistent cards/fields/buttons and readable request receipts. Workspace views were checked at **1440, 1024, 768, 390 and 320 px**, height **900**, without horizontal overflow or Axe violations. Confirmation initially focuses Cancel, supports Escape and restores the trigger; keyboard submission and verified cancellation work. Credentials clear after mutations; changing scope also clears the previous subject's closure reason. Public kits stay unavailable after cancellation, while eligible workspace access returns.

Inspection found repeated subject names created duplicate region labels; request references now distinguish receipts. Scope selection now follows the server's selected scope after a mutation redirects to the account preview. The final scope change is checked again in both desktop/mobile workspace journeys. Temporary screenshots were inspected outside the repository and removed; no new image evidence is retained. Final scope/subject reset rechecks passed both workspace journeys. The disposable stack/volumes and private temporary configuration/storage were removed after verification.

## Remaining gates and next step

PRIV-03 is locally verified, not deployed. Stop/drain old processes before a separately authorized rollout, apply the closure migration and runtime grants, and restart all app/worker/scheduler processes on matching code. Once history exists, fix forward. Remote CI, native Safari, broader manual/assistive review, production topology and live operator/recovery procedures remain open.

Next: PRIV-04 retained-class/hold design. Resolve exact purposes, triggers, periods, responsible reviewers and disposition for finance/audit/consent/security/Brand/relationship records, plus actual processor/backup behavior before implementing irreversible erasure. PRIV-05 can independently start report-only managed-object/orphan inventory under the approved seven-day/two-inventory policy; no removal is authorized by a report. PRIV-06 requires provider and isolated restore replay verification. The 30-day minimum does not itself establish statutory compliance or a scheduled deletion promise.

M3-02, ADM-07 cleanup execution and inherited M5/M6 release gates remain open. Preserve the unrelated untracked `planning/shared/staging-secret-rotation-plan.md` and its deferred execution. At the verification checkpoint, nothing had been pushed, deployed, sent externally or erased from a live environment. The user subsequently requested committing and opening a PR before moving to UI enhancement with Claude; further privacy implementation waits for their return.
