# Brand workspace approval — contract

Agreed 2026-11-18 (decision D37 in [decisions](../shared/decisions.md)). Reviewed baseline: `337906c`. Status, results and remaining gates are in the [evidence](brand-approval-evidence.md) and the [handoff](brand-approval-handoff.md).

## Policy change from D23

**Confirmed (D23, 2026-10-21), now superseded for Brand only:** role-specific registration created the corresponding workspace, and any verified account could create a Brand workspace from the workspace selector.

**Confirmed (D37, 2026-11-18):** every new Brand workspace needs administrator approval, whether it is requested during Brand registration or by an existing account. Partner signup and Professional subscription activation keep their D23/D29 behavior. Approval permits operating a Brand workspace. It is not a public "verified business" badge, grants no platform privilege and includes no paid subscription.

## Confirmed decisions

- Collect four fields: Brand name, website or public business profile, the applicant's role, and intended use. No document uploads.
- Three review decisions: approve, request more information, decline.
- Show request status in the application and email applicants about review outcomes.
- A dedicated `brands.review` capability. Platform owners inherit it and can delegate it.
- Existing Brand workspaces keep their access. Each receives an explicit rollout approval, recorded as a rollout approval and not as a historical signup review.
- On staging, rollout approvals are attributed to the verified platform-owner account named in the agreed plan, at the actual rollout time. **Recording note:** the [administration contract](contract.md#permission-and-data-boundaries) keeps operators' sign-in identities out of planning files, so that address is supplied when the command is run and is not written here.
- Original workspace creation dates, records, memberships and relationships are preserved.
- **Execution boundary:** this contract authorizes preparation and local implementation. Staging deployment and the real-account rollout command are separate execution steps, prepared for review in the handoff.

### Applicant journey (Confirmed)

- Brand signup still creates an account and requires email verification. It no longer provisions a workspace.
- After verification the applicant reaches a Brand request form. Registration intent is durable across sign-out, browser changes and interrupted verification.
- In the workspace selector the Brand option offers **Request Brand workspace** instead of **Create workspace**. Partner and Professional creation stay available.
- One unresolved request per applicant. Approved and declined requests stay in history, and further requests are allowed.
- A submitted request is `pending` and cannot be changed while it awaits review.
- `needs_information` makes the request editable. Resubmission returns it to `pending` and keeps earlier submissions and reviewer feedback.
- Decline is terminal for that request. The applicant may submit a new one.
- Approval creates the Brand workspace with the applicant as owner. The applicant gets an **Open Brand workspace** action; their active workspace is never switched during an administrator's session.
- Other workspaces, account security, email verification and sign-out stay available throughout review.
- All four fields are required. The address must be HTTP or HTTPS, is treated as applicant-supplied information and is never fetched by the server. The form states that submitting authorizes transactional updates about the request.

### Administrator journey (Confirmed)

- **Brand requests** appears in administration navigation, with a pending count on the Overview.
- The list filters by status and searches applicant and Brand, is paginated, and puts the oldest pending requests first.
- The detail shows applicant identity and current verification status, business information, submission history, reviewer feedback and any resulting workspace.
- All three decisions require a reason. It is visible to the applicant and the field is labelled accordingly.
- Only pending requests can be decided. Approval rechecks that the applicant is verified and not suspended.
- The existing administration MFA step-up and grant-version checks apply.
- Approve and decline need confirmation in the shared dialog, naming the applicant, the Brand and the consequence, with initial focus on Cancel.
- Entered feedback is preserved after validation and stale-version errors.
- Brand workspace details show approval status, reviewer, approval time, reason and source (request or rollout). Suspension remains the way to pause access later.

### Backend, security and email (Confirmed)

- Request records hold the applicant, the business fields, status, version, submission timestamps and a nullable resulting workspace. Submission and decision events are append-only and hold actor, time, reason and submitted information.
- Routes: `GET|POST /brand/requests`, `GET|PATCH /brand/requests/{request}`, `GET /admin/brand-requests`, `GET /admin/brand-requests/{request}`, `POST /admin/brand-requests/{request}/decision`. Mutations carry an expected version. Invalid fields return validation errors, stale decisions 409, inaccessible request IDs 404. Mutations are throttled.
- Approval, workspace creation, owner membership, decision history, audit and notification intent happen in one PostgreSQL transaction. Repeated or concurrent approvals produce one workspace, one approval event and one notification intent. The administrator gains no membership. Brand kind and workspace experience stay immutable.
- Neither registration intent nor the existing creation endpoint can bypass review. PostgreSQL enforces the boundary as well as the controllers: ordinary runtime creation functions refuse new Brand workspaces, and only the reviewed approval path creates them, with approval provenance.
- Narrow security-definer functions, fixed search paths and forced RLS. Applicants see only their own requests. Reviewers see request metadata through the capability and gain no tenant-private access.
- `brands.review` is added to permission validation, database constraints, operator management, the offline grant tool and navigation. No email address is an authorization rule.
- Outcome email uses an account-scoped durable outbox, because the business outbox requires a workspace and membership. No placeholder workspaces, and tenant constraints are not relaxed.
- Notification intent is recorded atomically with the decision; each decision event has one notification identity; retries and Redis replay never repeat a reserved send. Verification, suspension and the current recipient are rechecked before send reservation, and superseded notices are cancelled.
- Email links to the authenticated request page and contains neither the submission nor the reviewer's explanation.
- Account notices share the global delivery budget, with account-scoped throttling instead of workspace limits. Ambiguous SMTP outcomes are recorded as uncertain and need operator resolution; nothing is resent automatically.
- These notices appear in administration diagnostics without message bodies and without weakening tenant notification access.
- An email failure never undoes an approval or hides the in-app status.

### Rollout (Confirmed)

- An explicit, idempotent command, separate from schema migration, with a dry run that reports affected IDs and counts.
- It selects Brand workspaces without approval provenance and records an approved rollout entry for each. Business fields that were never collected stay absent.
- It records the supplied approving account, the actual execution time, the operator identity and a required reason.
- The approving account must be an existing, verified, unsuspended platform owner; otherwise the command fails without writing.
- It sends no outcome email. Repeating it creates no duplicate approvals or audit entries.
- Deployment order: schema migration, rollout recording and verification finish before the updated application is exposed. Old application processes and unfulfilled Brand registration intents must resume into the request flow.
- Self-service Brand creation is never reopened as a rollback. Destructive rollback refuses when it would discard requests or approved-workspace provenance.

## Implementation defaults

**Proposal:** choices made during implementation where the agreed plan left room. Each can change without altering a confirmed decision.

| Area | Default |
|---|---|
| Field limits | Brand name 120, address 500, role 120, intended use 2,000 characters |
| Reviewer feedback | 1 to 1,000 characters. The administration audit keeps the first 240; the request history keeps all of it |
| Self-review | A reviewer cannot decide their own request |
| Applicant view | Shows feedback and history, never the reviewer's identity |
| Request more information | Not confirmed in a dialog: it is routine and the applicant's reply reverses it |
| Throttles | 10 per minute for applicant submissions and revisions; 30 per minute for decisions |
| Duplicate open request | Returned as 409 with a field error |
| Access to existing Brand workspaces | Not gated on approval provenance. Until the rollout runs, administration shows "No approval recorded" and the Overview counts such workspaces |
| Rollout records | No applicant. They appear in the review list under source "Rollout approval" and cannot be decided |
| Provenance enforcement | A deferred constraint trigger refuses, at commit and for every role, a new Brand workspace without an approved record |
| Account notices | No recipient, link or body is stored; both are resolved at send. Per-account limit `NOTIFICATION_ACCOUNT_PER_MINUTE` (0 means none) |
| Unverified or suspended applicant | A pending notice is cancelled, not held |
| Rollout approver check | Email verified, no active suspension, active grant holding `platform.owner`. A confirmed authenticator is not required |
| Rollout audit | One `brand.rollout_approved` entry per workspace, with the operator label as actor |
| Test fixtures | Synthetic Brand workspaces are written with rollout-style approval through the migration role; the integration suite uses a helper that only `rls-fixtures.sql` installs |

## Assumption / Open

- Production budget values for account notices (with M5-09 budgets).
- Whether applicants should be told who reviewed their request.
- Whether a confirmed authenticator should be required of the rollout approver.
- Retention of declined requests and their business information (M3-02).
