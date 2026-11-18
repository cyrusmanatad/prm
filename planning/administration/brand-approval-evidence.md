# Brand workspace approval — implementation and verification

Scope: the [Brand approval contract](brand-approval-contract.md) (D37). Date: 2026-11-18. Revision: branch `feat/brand-approval`, one commit on `337906c`; the gates below ran on that tree before it was committed. Environment: disposable local Compose project `weavdash-brand` (`localhost:8020`, PostgreSQL 16, Redis, PHP 8.5.11, Node 24.15.0), created for this work from `compose.yaml` with a scratch override that bind-mounts `src/`, forces the in-memory mail transport and omits ClamAV. No shared, staging or production system was touched, no real email was sent, and the rollout command was not run against real accounts.

## What was implemented

- **Schema and enforcement.** Migration `2026_11_18_000002_add_brand_approval` (`brand-approval.sql`): requests, append-only events, an account-scoped notification outbox and its resolutions, all behind forced RLS. The ordinary creation function refuses new Brand workspaces, and a deferred constraint trigger refuses, for every role, a Brand workspace committed without an approved record.
- **Applicant journey.** `/brand/requests` (list, form, landing after Brand registration) and `/brand/requests/{request}` (status, feedback, revision, Open Brand workspace). Brand registration and sign-in resume into the request; `POST /workspaces` rejects `kind=brand`.
- **Review.** `/admin/brand-requests` and its detail, the decision endpoint, the `brands.review` capability in grants, operator management, the offline grant tool and navigation, a pending count and an unrecorded-workspace count on the Overview, and approval metadata on Brand workspace details.
- **Outcome email.** `AccountNotifications`, `DeliverAccountNotification` and `BrandRequestOutcome`, dispatched by the existing `notifications:dispatch` schedule. Notification diagnostics and resolution cover account notices.
- **Rollout.** `php artisan brand:rollout-approvals`, with `--dry-run`.
- **Fixtures.** `staging:bootstrap`, the demo seeder and the tests create synthetic Brand workspaces with recorded approval, since nothing else can.

Reused components: `FoundationShell`, `AdminShell`, `ContentSection`, `FormField`, `FieldGroup`, `SelectField`, `DetailList`, `DataTable`, `FilterBar`, `Pagination`, `StatusBadge`, `EmptyState`, `UnavailableNotice`, `CopyReference`, `BackLink`, the shared button and `confirmAction` with `ConfirmationDialog`. New, in `features/brand-requests/`: `BrandRequestFields` (the four fields, used by the new and revised forms), `SubmissionDetails` (applicant and reviewer views) and `RequestHistory` (both views), plus shared labels in `types.ts`. No new CSS rule was added; lists use the existing `wd-record` pattern.

## Commands and results

Backend commands ran through the disposable stack; frontend commands ran from `src/`.

| Command | Result |
|---|---|
| `php artisan migrate` on an empty database, then `scripts/grant-runtime.php` | All migrations applied |
| Roll back the migration and reapply | Passed. The seven replaced functions and their privileges matched their pre-migration definitions by checksum |
| `psql … < tests/Integration/rls-fixtures.sql`, then `rls.sql` | `RLS_CHECKS_PASSED`, including the new Brand checks |
| `vendor/bin/phpunit -c phpunit.pgsql.xml` | 35 tests, 640 assertions passed |
| `vendor/bin/phpunit -c phpunit.acceptance.xml --filter BrandApprovalTest` | 12 tests, 394 assertions passed |
| `vendor/bin/phpunit -c phpunit.acceptance.xml` (fresh database) | 181 tests, 4,431 assertions: 176 passed, 3 skipped, 2 failed. Both failures are the known environment ones (`OnboardingSecurityTest` needs ClamAV; one `SyntheticRecoveryTest` SMTP test), recorded in the [administration handoff](implementation-handoff.md#working-notes-for-the-next-session) as failing on untouched `main` in such a stack |
| `php artisan test --compact` | 42 tests, 362 assertions passed |
| `vendor/bin/pint --test` | Passed |
| `npm run format:changed`, then `npm run format:check:changed` | 25 changed frontend files checked, passed |
| `npm run typecheck` | Passed |
| `npm test` | 26 files, 138 tests passed (10 new in `BrandRequests.test.ts`) |
| `npm run build` | Passed |
| `npm audit --audit-level=high` | Fails identically on the untouched baseline; no dependency was added or changed |
| `COMPOSE_PROJECT_NAME=weavdash-brand PLAYWRIGHT_BASE_URL=http://localhost:8020 npm run test:browser -- brand-approval.spec.ts portals.spec.ts join-links.spec.ts notifications.spec.ts professional-ui.spec.ts professional.spec.ts administration.spec.ts foundation.spec.ts --project=desktop --project=mobile` (with `COMPOSE_FILE` naming the scratch override) | 30 journeys: 27 passed on the first run. The 3 failures were expectations this change makes obsolete (one more administration navigation link on mobile; Brand sign-in with no Brand workspace now lands on `/brand/requests`, desktop and mobile). After updating them, those journeys were re-run and all 4 passed. Four existing specs that created a Brand workspace through the selector now use the migration-role fixture `brand-fixture.php` |

### Acceptance scenarios covered by `BrandApprovalTest`

| Scenario | Covered by |
|---|---|
| Brand registration, verification and sign-in across sessions lead to a request; no workspace | Registration test; browser journey |
| Existing users request Brands and keep other workspace access | Registration and approval tests |
| HTTP and runtime SQL cannot create a Brand workspace | Five runtime SQL attempts, the service, the endpoint, a kind change and a schema-owner insert are all refused |
| Information request, revision and resubmission keep history; decline permits a new request | Applicant lifecycle test, including refusal to rewrite or delete history as the schema owner |
| Approval creates one workspace owned by the applicant; the reviewer gains no membership | Approval test |
| Stale and concurrent decisions, retries and failures leave nothing partial or duplicated | Stale versions return 409; a second connection waits on the first decision's lock and then gets a conflict; a failure after approval rolls back workspace, event, audit and notice |
| Unverified or suspended applicants, revoked grants and expired proofs cannot approve | Refusal tests; also a wrong grant version, a grant without the capability and the reviewer's own request |
| Real RLS denies cross-applicant reads and unauthorized review | RLS test with the runtime role, and `rls.sql` |
| Outcome email survives queue failure, respects budgets, handles supersession and is not replayed | Delivery tests: queue failure deferral, lease, single send after three deliveries, shared global window, per-account window, invalid limits, log transport, uncertain outcome, stuck send, operator resolution, cancellation for superseded, suspended and unverified cases |
| Existing Brand backfill preserves data and access, attributes approval and is repeat-safe | Rollout test |
| Partner signup, Professional activation, invitations, join links and onboarding still work | Registration test and the unchanged existing suites |
| New ownership triggers owner-MFA behaviour | Approval test: the new owner is required to use an authenticator once enforcement is on |

## Rollout dry run

Rehearsed on the disposable stack only, after the test runs, against three synthetic Brand workspaces inserted without approval (dated 2026-10-22) and a synthetic platform owner. Command: `php artisan brand:rollout-approvals --approver=<id> --reason=… --operator=local-rehearsal [--dry-run]` through the `migrate` service.

| Step | Result |
|---|---|
| Before | 67 Brand workspaces, 64 already recorded (test fixtures), 524 memberships |
| Dry run | `"dry_run":true`, `"would_record":3`, the three workspace IDs listed; 0 rows written |
| Run | `"recorded":3`, same three IDs, `executed_at` the run time; "No email was sent" |
| Repeat | `"recorded":0`, `"already_recorded":67` |
| After | Workspace count, IDs, creation dates (checksum) and membership count unchanged; 3 rollout events and 3 audit entries; 0 Brand workspaces without approval; 0 account notices for rollout records |

The output prints the approver's account ID and never an email address. Refusals (runtime role, missing reason, non-owner, unknown or suspended approver) are covered by the acceptance test. **No dry run was made on staging:** the command needs the new schema, so it can only follow the migration there; the prepared commands are in the [handoff](brand-approval-handoff.md#staging-steps-prepared-not-executed).

## Rendering and accessibility findings

Inspected with temporary captures (removed afterwards) at 1440 and 1920 px desktop and at 390 and 320 px, for the request form, its validation error, the pending, needs-information, approved and declined request pages, the workspace selector with Brand chosen, the review list and detail, both confirmation dialogs and the Brand approval panel on a workspace.

- Applicant pages use the Brand header variant of `FoundationShell` and the same heading, card and field rhythm as Workspaces and Support access requests. Reviewer pages match the other Governance modules.
- At 320 px every page stays within the viewport; detail lists and the review table stack as the other administration tables do, and long addresses and email addresses wrap.
- axe reported no violations on any inspected page or dialog, at every width.
- Every visible control met the 44 px touch target. Two links inside detail lists were 27 px high at first and were given the existing `inline-flex min-h-11 items-center` treatment.
- Both dialogs open with focus on Cancel, contain focus, close on Escape, return focus to the trigger and send exactly one request on confirmation.
- A server validation error moves focus to the first invalid field and keeps what was typed. A missing decision is reported by the server beside the Decision control; marking that control `required` was removed after it was found to block submission silently, because the shared select's native control is hidden.
- Wording corrected during inspection: the applicant's history now says "Feedback from the reviewer" where the reviewer's view says "Feedback for the applicant".

## Not verified

- Deployment, staging migration, the rollout on real accounts and real email delivery or receipt. None was performed.
- CI, WebKit and native Safari. Chromium only, locally.
- Manual keyboard-only, screen-reader and browser-zoom review.
- The two environment-dependent acceptance tests above.
- `scripts/seed-demo.php` and `staging:bootstrap` were updated and syntax-checked but not executed end to end.
- The gates were run on the working tree that became the commit, not re-run afterwards; CI on the pull request is the independent run.
