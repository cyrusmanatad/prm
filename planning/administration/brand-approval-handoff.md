# Brand workspace approval — handoff

Written 2026-11-18. Implements the [contract](brand-approval-contract.md) (D37). Verification results are in the [evidence](brand-approval-evidence.md).

## State

- **Revision:** branch `feat/brand-approval`, one commit on top of `337906c` (the reviewed baseline, rechecked before editing; the tree was clean). The local gates ran on that tree before it was committed.
- **Implemented and locally verified:** schema, database enforcement, `brands.review`, applicant and reviewer journeys, outcome email, the rollout command, tests and documentation.
- **Not done:** no deployment, no staging migration, no rollout on any real environment, no real email sent or received. Nothing here claims them.

## Migration

`2026_11_18_000001_add_brand_approval` runs `src/database/sql/brand-approval.sql`. Re-run `scripts/grant-runtime.php` afterwards, as for every migration.

- Adds `brand_requests`, `brand_request_events`, `account_notification_outbox`, `account_notification_resolutions`.
- Adds the provenance trigger on `organizations` and makes `app_create_typed_workspace` refuse new Brand workspaces.
- Replaces `app_platform_grant_save`, `app_platform_notification_list`, `app_platform_notification`, `app_platform_resolve_notification`, `app_platform_operations` and `app_platform_overview`.
- Widens the `platform_grants` capability check to include `brands.review`.
- Changes no existing row. Existing Brand workspaces keep working before and after the rollout command.
- **Rollback** restores the replaced functions exactly (verified by checksum) but refuses once any `brand_requests` row exists. After the rollout command has run, use forward fixes only. Never reopen self-service Brand creation as a rollback.

## Permission changes

- New capability `brands.review`. `platform.owner` implies it, so the two founders' existing grants cover review with no grant change.
- Delegate it at `/admin/operators`, or offline with `platform:grant <user-id> --permission=brands.review …`.
- It exposes request metadata, applicant name and email, and the applicant's verification and suspension state. It gives no directory, workspace-record or membership access.
- No email address appears in any authorization rule. The rollout approver is passed as an option and checked against the database.

## Files to read first

| Area | Files |
|---|---|
| Schema and functions | `src/database/sql/brand-approval.sql`, migration `2026_11_18_000001_add_brand_approval.php` |
| Applicant | `app/Services/BrandRequests.php`, `Http/Controllers/BrandRequestController.php`; `PortalController::enter`, `WorkspaceController::store` |
| Review | `Http/Controllers/PlatformBrandController.php`; `PlatformConsoleController::workspace` |
| Email | `app/Services/AccountNotifications.php`, `Jobs/DeliverAccountNotification.php`, `Mail/BrandRequestOutcome.php`, `resources/views/mail/brand-request-outcome.blade.php`, `routes/console.php` |
| Rollout | `app/Console/Commands/BrandRolloutApprovals.php` |
| Screens | `pages/BrandRequests.vue`, `BrandRequest.vue`, `PlatformBrandRequests.vue`, `PlatformBrandRequest.vue`, `features/brand-requests/`, `features/workspaces/WorkspaceScreen.vue` |
| Tests | `tests/Acceptance/BrandApprovalTest.php`, `tests/Frontend/BrandRequests.test.ts`, `tests/Browser/brand-approval.spec.ts`, `tests/Integration/rls.sql`, `tests/Support/BrandFixture.php` |

## Staging steps (prepared, not executed)

These need the user's go-ahead. They follow the [staging deployment](../shared/staging-deployment.md) procedure; run them in `/home/cyrusadmin/weavdash-staging` on the VPS. The order keeps the updated application hidden until migration, rollout and verification are complete.

1. **Build** matching app and web images from the commit and set both tags in `staging.env`.
2. **Back up** the database, configuration and referenced R2 objects, and take a checksum-verified off-host copy, as for earlier updates.
3. **Record the baseline** (read-only):

   ```sh
   docker compose --env-file staging.env -f compose.staging.yaml exec -T postgres psql -U postgres -d weavdash -Atc "SELECT count(*) FROM organizations WHERE kind='brand'; SELECT count(*) FROM memberships m JOIN organizations o ON o.id=m.org_id WHERE o.kind='brand'; SELECT count(*) FROM users WHERE registration_workspace_kind='brand';"
   ```

   The three figures are Brand workspaces, their memberships, and unfulfilled Brand registration intents.
4. **Stop the writers** so no old process meets the new schema:

   ```sh
   docker compose --env-file staging.env -f compose.staging.yaml stop web app worker scheduler
   ```

   An old application process that reached the new schema would fail to create a Brand workspace for a registrant, and the registrant's intent would be kept. Stopping the writers avoids the error page.
5. **Migrate and refresh grants:**

   ```sh
   docker compose --env-file staging.env -f compose.staging.yaml run --rm -T migrate
   docker compose --env-file staging.env -f compose.staging.yaml run --rm -T migrate php scripts/grant-runtime.php
   ```

6. **Dry run.** Set the approver in the shell only; it is the verified platform-owner account named in the agreed plan and is deliberately not written in this file:

   ```sh
   read -r ROLLOUT_APPROVER_EMAIL
   docker compose --env-file staging.env -f compose.staging.yaml run --rm -T migrate php artisan brand:rollout-approvals --approver-email="$ROLLOUT_APPROVER_EMAIL" --reason="Existing Brand workspaces approved at the D37 rollout" --operator="<who is running this>" --dry-run
   ```

   Check that `would_record` plus `already_recorded` equals the Brand workspace count from step 3, and review the listed workspaces. The command exits with an error and writes nothing if the account is missing, unverified, suspended or not a platform owner.
7. **Review the dry-run output with the user**, then run the same command without `--dry-run`. Keep its JSON line for the staging record. It prints the approver's account ID, not the address.
8. **Verify before exposing the application:**

   ```sh
   docker compose --env-file staging.env -f compose.staging.yaml exec -T postgres psql -U postgres -d weavdash -Atc "SELECT count(*) FROM organizations o WHERE kind='brand' AND NOT EXISTS (SELECT 1 FROM brand_requests r WHERE r.org_id=o.id AND r.status='approved'); SELECT count(*), min(e.occurred_at), max(e.occurred_at) FROM brand_request_events e WHERE e.event='rollout_approved'; SELECT count(*) FROM account_notification_outbox;"
   ```

   Expect `0`, then the recorded count with the execution time, then `0` notices. Repeat the step 3 query: the first two figures must be unchanged. Run the command a second time; it must report `"recorded":0`.
9. **Start** `app`, `worker`, `scheduler` and `web` with the new images and refresh the edge as usual.
10. **Smoke check:** the Brand reviewer account still opens `/brand`; `/admin/brand-requests` lists the rollout approvals; a Brand workspace's administration detail shows the rollout approval; registering a throwaway Brand account lands on `/brand/requests` and creates no workspace.
11. **Record** the update in [staging deployment](../shared/staging-deployment.md).

Unfulfilled Brand intents (the third figure in step 3) need no data change: those accounts are led to the request form at their next sign-in.

### Outcome email on staging

Account notices send only through SMTP. While staging runs the M5-09 trial override with the captured (`array`) transport, they are deferred as "transport unavailable" and stay pending; the decision and the in-app status are unaffected. They count against the trial's global limit of two per minute. `NOTIFICATION_ACCOUNT_PER_MINUTE` is unset there, which means no per-account limit; set it in `staging.env` if one is wanted. A real receipt test is a separate, authorized step.

## Rollout dry run (local)

Run on the disposable stack against synthetic data; see the [evidence](brand-approval-evidence.md#rollout-dry-run). No dry run has been made on staging: the command needs the new schema, so it can only run after step 5.

## Remaining gates

- User go-ahead for staging deployment and for the real-account rollout command.
- CI on the pull request (including WebKit) must pass; the local gates were not re-run after committing.
- Real outcome email: provider acceptance, receipt and link opening on staging.
- Production budget values for account notices, with the other M5-09 budgets.
- Manual keyboard, screen-reader, browser zoom and native Safari review of the four new screens (carried with ADM-07 B15).
- `OnboardingSecurityTest` (needs ClamAV) and one `SyntheticRecoveryTest` SMTP test fail on the disposable stack, as they do on untouched `main` there. Run them in a full stack or CI.
- The open questions in the [contract](brand-approval-contract.md#assumption--open).
- Production: no deployment exists; provisioning and the same rollout step apply when it does.
