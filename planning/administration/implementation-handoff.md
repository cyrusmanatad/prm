# Administration — next-session handoff

Written 2026-11-17, after the administration console merged to `main` at `f16fd09` ([PR #18](https://github.com/cyrusmanatad/prm/pull/18)). This is a self-contained brief for the next session. It covers three things: documentation that still has to catch up with the code, administration work that is not yet implemented, and decisions that need the user's approval.

**Confirmed:** nothing here is authorized for implementation beyond Part A. Part B items marked "needs approval" wait for the decisions in Part C.

## Read first

1. [`AGENTS.md`](../../AGENTS.md) — mandatory UI standards, planning conventions, no retained screenshots.
2. [Administration contract](contract.md), "Current status" table — what each ADM package has and lacks.
3. [Console evidence](console-evidence.md) — what was built, how it was verified, what was not run.
4. [Suspension rules](suspension-proposal.md) and decisions D31 and D32 in [decisions](../shared/decisions.md).

## Source of truth for the implementation

Describe what these files do; do not restate the planning documents.

| Area | Files |
|---|---|
| Schema and database functions | `src/database/sql/platform-activation.sql`, `delivery-controls.sql` (earlier slices); `platform-console.sql`, `platform-governance.sql`, `platform-governance-down.sql`; migration `2026_11_16_000001_add_platform_console.php` |
| Request handling | `src/app/Services/PlatformAdministration.php`; `PlatformController`, `PlatformNotificationController`, `PlatformConsoleController`, `PlatformGovernanceController`; `Middleware/EnforceAccountAccess.php`, `Middleware/WorkspaceContext.php`; `Requests/Auth/LoginRequest.php` |
| Routes | `src/routes/web.php` (`/admin/*`, `/workspace/support-access*`); heartbeat in `src/routes/console.php` |
| Offline commands | `src/app/Console/Commands/PlatformGrant.php` |
| Screens | `src/resources/js/layouts/AdminShell.vue`, `pages/Platform*.vue`, `pages/WorkspaceSupport.vue`, `features/admin/`, `css/admin.css` |
| Tests | `tests/Acceptance/PlatformConsoleTest.php`, `PlatformGovernanceTest.php`; `tests/Browser/administration.spec.ts`, `admin-fixture.php`; `tests/Frontend/Admin*.test.ts`, `RecordComponents.test.ts` |

## Part A — documentation to update (authorized; documentation only)

**Status: done 2026-11-17** on branch `docs/administration-part-a`. A1–A6 were written from the implementation files listed above; A7 link and whitespace checks passed. No code, test or migration changed and no gate was run. The section-by-section brief below is kept as the record of what was asked.

This is register item **M6-10** for the administration part. Keep the evidence labels. Mark what is implemented as implemented, and leave genuinely open items as **Assumption / Open**. Do not present proposals as built.

### A1. `planning/shared/architecture.md`

Add an "Administration" section after "Boundaries and request flow". It should explain:

- Administration is a separate `/admin` area on the same Laravel/Inertia application and session. There is no separate admin login or service.
- The trust chain for every admin request: signed-in verified identity, an unrevoked row in `platform_grants`, a confirmed authenticator, and a 15-minute step-up proof bound to the grant version. `PlatformAdministration::run` sets `app.admin_version`; PostgreSQL re-checks all of it in `app_platform_allowed()`.
- Cross-workspace reads never use a privileged runtime role. They go through SECURITY DEFINER functions owned by the migration role, each gated by a capability. The runtime role (`weavdash_app`) keeps tenant RLS.
- Suspension is enforced inside `app_has_membership`, `app_lock_membership`, `app_platform_allowed` and `app_lock_platform_grant`, so web requests, worker jobs and scheduled tasks are all rechecked when they execute.
- Support access is the only path from administration to private workspace records: one function, an owner-approved unexpired grant, a fixed read-only projection, a write to both audit trails.
- In "Reliable jobs and automation": the scheduler writes a one-minute heartbeat through `app_record_observation('scheduler')`. Worker, scanner and mail-provider health are not collected.

### A2. `planning/shared/database-design.md`

Add an "Administration implementation note" (same style as the existing M2/M3/M5-03 notes) listing:

- Tables: `platform_grants`, `platform_audit`, `notification_resolutions` (earlier slices); `platform_observations`, `platform_suspensions`, `support_grants`, `fixture_batches`, `fixture_marks`, `cleanup_runs`. For each: purpose, key columns, state values, and that RLS is forced with no runtime policies (except the self-read policy on `platform_grants`).
- The ten capabilities and that `platform.owner` implies the rest.
- Integrity rules worth stating: one active suspension per subject; one open support grant per operator and workspace; approved grants expire at most 24 hours after the decision; `support_grants` "expired" is derived at read time; cleanup runs are immutable and become stale when the batch version changes.
- Version checks: grant version, suspension version (count of suspend and lift events), support grant version, batch version.
- The four replaced core functions and that `platform-governance-down.sql` restores them. The migration's rollback refuses to run while a suspension or support grant is active.
- In "Audit, retention, and migration": `platform_audit` is append-only with no runtime read or write policy; workspace-facing support events also go to `audit_logs`; there is no audit export.

### A3. `planning/shared/api-design.md`

Add an "Administration routes" section. List the routes below with the capability each needs, and state the common behaviour.

- Reads: `/admin` (Overview; JSON clients receive the original directory payload), `/admin/accounts`, `/admin/accounts/{account}`, `/admin/workspaces`, `/admin/workspaces/{workspace}`, `/admin/plans`, `/admin/plans/{plan}`, `/admin/assignments`, `/admin/assignments/create`, `/admin/notifications`, `/admin/notifications/{notification}`, `/admin/operations`, `/admin/audit`, `/admin/audit/{source}/{event}`, `/admin/operators`, `/admin/support-access`, `/admin/support-access/{grant}`, `/admin/cleanup`, `/admin/cleanup/{batch}`, `/admin/cleanup/runs/{run}`, `/admin/security`.
- Writes: `POST /admin/security/enroll`, `/admin/security/verify`, `/admin/assignments`, `/admin/notifications/{notification}/resolve`, `/admin/operators`, `/admin/accounts|workspaces/{reference}/suspend|reactivate`, `/admin/accounts/{account}/sign-out`, `/admin/support-access`, `/admin/support-access/{grant}/end`, `/admin/cleanup`, `/admin/cleanup/{batch}/marks`, `/admin/cleanup/{batch}/runs`.
- Workspace owner: `GET /workspace/support-access`, `POST /workspace/support-access/{grant}`.
- Common behaviour to document: JSON for `Accept: application/json` without `X-Inertia`, otherwise Inertia pages; 403 without grant, step-up or capability (a browser page visit without step-up redirects to `/admin/security` and returns afterwards); 404 for unknown records; 409 with an `expected_version` error for stale versions; 422 for rule violations; per-route throttles. Operator-grant and access changes require `password` and a fresh `code` in the body.
- Update the existing row for `/orgs/{orgId}/memberships` only if it implies administration can change memberships; it cannot.

### A4. `planning/shared/deployment-and-operations.md`

In "Recovery, observability, and launch gates", add: first-owner bootstrap and authenticator recovery use `php artisan platform:grant` with the migration role (never the runtime role); `scripts/grant-runtime.php` must be re-run after migrating; the scheduler must be running for the heartbeat; what `/admin/operations` does and does not observe. State plainly that this is not monitoring or alerting (M6-03 stays open).

### A5. `planning/shared/auth-and-subscriptions.md`

The "Required platform administration" section has a one-line update. Expand it with the capability list, the step-up rule, suspension's effect on sign-in, and the support-access consent model. Remove or mark historical any remaining "pending" wording about operator MFA. Workspace-owner MFA (M1-04) is still not implemented; keep that explicit.

### A6. Root `README.md`

Add a "Platform administration" section after "Financial records and pilot entitlements (M4)":

- How to create the first platform owner locally: run `platform:grant {user} --permission=platform.owner --reason=… --operator=…` through the `migrate` Compose service, then sign in and enroll at `/admin/security`. `--recover` and `--revoke` exist. Do not put real identities or credentials in the README.
- What the console covers, in one short paragraph, with links to the contract and evidence.
- That workspace owners approve support access at `/workspace/support-access`.
- That cleanup is dry run only.
- Update the existing M4 sentence "Pilot administration uses local/testing migration credentials and the audited `pilot:plan` / `pilot:assign` Artisan commands": assignments are now also recorded in the console; the CLI remains local/testing only.
- In "Verification", add the two administration acceptance test classes and `administration.spec.ts`, and the note about the mobile journey below.

### A7. Close-out for Part A

Verify relative links, run `git diff --check`, update the [planning index](../README.md) and the M6-10 row in the [register](../shared/remaining-tasks.md) (administration part done; the rest of M6-10 stays open). Documentation only: no code, tests or migrations.

## Part B — administration work not yet implemented

B1–B6 were worked on 2026-11-17 on branch `administration/part-b`; results are in the [follow-up evidence](follow-up-evidence.md). B7–B13 still wait for the decisions in Part C.

| # | Item | Package | State |
|---|---|---|---|
| B1 | **Mobile administration journey is intermittent** (passed 3 of 6 runs; failures at three different steps). Find the cause. First check: run against a multi-worker server instead of the single-process PHP dev server; then look for a session race around `session()->regenerate()`. Details in the [evidence](console-evidence.md#commands-and-results). | ADM-02 | **Partly done 2026-11-17:** one test defect found and fixed; the mobile failures did not reproduce in 21 runs, so their cause is not established. See the [follow-up evidence](follow-up-evidence.md#b1--the-intermittent-administration-journey). |
| B2 | Run the gates that were not run: `phpunit.pgsql.xml` and `tests/Integration/rls.sql` on a fresh database (add platform checks to `rls.sql`, which has none); WebKit desktop/mobile; confirm CI on `main`. | ADM-01 to 07 | **Done 2026-11-17** except local WebKit (host lacks its libraries; it runs in CI). See the [follow-up evidence](follow-up-evidence.md#commands-and-results). |
| B3 | Partner-side "paused" label when the other party's workspace is suspended. This is the one confirmed suspension rule (rule 4) not built. | ADM-03 | **Done 2026-11-17.** |
| B4 | Record work refused at execution because of suspension as "skipped" (rule 5 says skipped and recorded; today it is only refused). | ADM-03 | **Done 2026-11-17**, including the same handling for account suspension. |
| B5 | Directory extras from the design brief: joined/created date filters, fixture/protected classification filters once B11 exists, optional column chooser. | ADM-02 | **Done 2026-11-17** for the date filters; classification filters wait for B11; column chooser not built. |
| B6 | Overview attention items for support access nearing expiry and blocked cleanup reviews. | ADM-05/06 | **Done 2026-11-17.** |
| B7 | QR code on authenticator enrollment. Needs a new frontend dependency. | ADM-01 | Needs approval (C6) |
| B8 | Worker heartbeat, scanner health and mail-provider health on `/admin/operations`. Overlaps M6-03 monitoring. | ADM-05 | Needs approval (C7) |
| B9 | Membership role change or revocation from administration. Read-only today. | ADM-03 | Needs approval (C3) |
| B10 | Emergency support access. None exists. | ADM-06 | Needs approval (C2) |
| B11 | Cleanup beyond the dry run: review/approval step, execution, results, and inventory of uploaded files and object storage. | ADM-07 | Needs approval (C4), and M3-02 retention policy |
| B12 | Tenant audit-event browsing and audit export. | ADM-06 | Needs approval (C5) |
| B13 | Real platform-owner provisioning and a rehearsed bootstrap/recovery on a disposable environment. No real operator exists. | ADM-01/07 | Needs approval (C8) |
| B14 | Workspace-owner MFA. | M1-04 | Not started; separate task |
| B15 | Final administration acceptance: manual keyboard, screen-reader, zoom and native Safari review; then close ADM packages with evidence. | ADM-07 | After B1–B4 |

Optional UI proposals from the original plan, outside administration scope and not done: adopt `StatusBadge`, `Pagination` and `FilterBar` in CRM, Payments, Invoices and portal dashboards; replace CRM's hand-rolled buttons; fix the CRM mobile `h1` size (`Crm.vue`); fix the undefined `--wd-text` token on `.wd-staging-banner` (`foundation.css`).

## Part C — decisions needed from the user

| # | Decision | Why it is needed | Recommendation |
|---|---|---|---|
| C1 | **Support view content.** Inside an approved area the view hides free-text notes, template bodies, onboarding answers, documents and payment evidence. D32 said "view-only", which did not require this. Keep it, or show notes? | It was my implementation choice, recorded as a **Proposal**. | Keep hidden; add specific fields later if support cannot do its job. |
| C2 | **Emergency support access.** Should any path exist when no workspace owner can approve (for example a locked-out sole owner)? | D32 did not cover it; none is built. | None for launch; handle such cases through the offline procedure. |
| C3 | **Membership changes from administration.** May operators change a role or revoke a membership, and what protects the last workspace owner? | The design brief leaves this policy-dependent. | Leave read-only; workspace owners manage their members. |
| C4 | **Cleanup execution.** Who approves a manifest, what backup evidence is required, and in which environments may it run? | D32 chose dry run only and kept execution a separately reviewed step. | Decide after M3-02 retention policy; never in production without a reviewed backup. |
| C5 | **Audit export and tenant audit browsing.** Allowed, and for whom? | Not authorized by any decision. | Not for launch. |
| C6 | **QR code dependency.** Approve adding a small QR library for enrollment? | Adds a dependency. | Approve; it removes manual key entry. |
| C7 | **Health telemetry scope.** Build worker/scanner/provider health in the console, or leave to M6-03 monitoring? | Avoids building it twice. | Leave to M6-03. |
| C8 | **Real owner and operator roster.** Which identity is the first platform owner, who else gets which capabilities, and when to provision. | Still **Assumption / Open** in the contract; needed before launch. | Decide before the bootstrap rehearsal (B13); name at least two owners so last-owner protection is not the only safeguard. |
| C9 | **Confirm the suspension implementation notes.** Accept B3 and B4 as follow-ups rather than blockers? | They were gaps against the confirmed rules. | No longer needed: B3 and B4 are implemented (2026-11-17). |

Related decisions owned elsewhere and still open: retention and privacy (M3-02), commercial prices and quotas (M4-01), production roster (M4-02), incident and support ownership (M6-06).

## Working notes for the next session

- The disposable test stack `weavdash-admin` (application on `localhost:8010`) was created for this work from a merged Compose file in a session scratch directory, with no ClamAV service. It may no longer exist. Recreate a disposable stack rather than migrating a shared one; the `weavdash-professional` stack was three migrations behind `main` when last checked.
- Two acceptance failures in such a stack are environmental: `OnboardingSecurityTest` needs ClamAV, and one `SyntheticRecoveryTest` SMTP test fails on untouched `main` there.
- The migration's rollback refuses to run while suspensions or approved support grants exist. On a disposable database, lift or end them first.
- `src/test-results/` is owned by root on the development host, so the Playwright JSON reporter cannot write there; the list reporter output is the record.
- Authenticator codes are single use and valid only near the current 30-second step. Tests that submit several protected changes must take a fresh, newer code each time and enter it after any slow accessibility scan.
