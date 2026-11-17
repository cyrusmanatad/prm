**2026-11-07 checkpoint:** [Professional Hub and manual subscriptions](../professional/hub-status.md) add a creator-workspace experience and a verified/MFA-protected runtime plan-assignment path. Existing Partner/Brand workspaces retain their behavior. The local CLI remains available only under its prior limits; production provisioning, pricing and remaining release gates are not approved by this implementation.

# Authentication, Authorization, and Subscriptions

## Identity

**Approved application stack:** Laravel with Vue/Inertia. **Initial authentication proposal:** Laravel's Vue starter-kit authentication/Fortify with native Laravel session authentication. Use the same-origin web session for Inertia; a separate JWT login system, Supabase Auth, or managed identity service is not an MVP prerequisite. Password/session login is confirmed (D15). Production MFA is required for platform operators and workspace owners (D18). Operator MFA is implemented as TOTP with offline recovery (D31; see [Required platform administration](#required-platform-administration)); workspace-owner MFA (M1-04) is not implemented. Keep `users` independent of future provider identities; add `auth_identities` when social login or enterprise federation is introduced. Later SAML/OIDC should use a maintained integration/provider selected for budget, regional processing, and enterprise needs.

MVP identity acceptance: verified email signup/login through Laravel, recovery, logout/session revocation, invitation acceptance, and account lock/rate limits. Decide password versus magic-link UX with pilots; do not build both automatically. **Confirmed (D18):** require MFA for platform operators and workspace owners before production. MFA for privileged finance actions remains a proposal. For workspace owners, admin-role coverage, enrollment/recovery, and enforcement must still be specified and tested before production; the operator side is described below.

Use Laravel session authentication with secure HttpOnly cookies, explicit SameSite policy, CSRF protection, short session lifetimes with rotation, and server-side revocation. If OIDC is used, validate issuer/audience/state/nonce with a maintained client. Account linking requires verification; matching email alone must not silently merge identities. Audit sensitive login and access changes without storing secrets.

## Required platform administration

**Confirmed D27:** provide platform-owner/super-admin administration before advancing milestones, as specified in the [administration contract](../administration/contract.md). Platform grants, TOTP step-up and the administration console are implemented locally ([evidence](../administration/console-evidence.md)); operator MFA is confirmed as TOTP with offline recovery (D31). Global platform grants are distinct from workspace memberships; no public registration, tenant Owner/Admin role or hard-coded email may confer platform authority. A real initial owner must be explicitly provisioned, MFA-enrolled and protected during synthetic-data cleanup; none has been provisioned (**Assumption / Open**).

**Capabilities (implemented).** A grant holds one or more of:

| Capability | Allows |
|---|---|
| `platform.owner` | Everything below, plus managing other operators' grants and ending any support grant |
| `directory.read` | Account and workspace directories and details (metadata and counts only) |
| `plans.assign` | Plans, assignments and recording an assignment |
| `notifications.read` | Notification delivery metadata |
| `notifications.reconcile` | Recording a resolution for an uncertain send |
| `operations.read` | Jobs and health diagnostics |
| `audit.read` | Administration audit review |
| `access.manage` | Suspend or reactivate an account or workspace; sign out an account's sessions |
| `support.request` | Request, view and end support access |
| `cleanup.review` | Fixture batches, marking and cleanup dry runs |

An owner cannot change their own grant, and the last usable platform owner cannot be revoked, reduced or suspended. Changing a grant ends that operator's sessions.

**Step-up rule (implemented).** Operators enroll a TOTP authenticator at `/admin/security` with their password. Verifying password and code records a proof in the session that lasts 15 minutes and is bound to the user and the grant version; every other administration request requires it and PostgreSQL re-checks it. Codes are single use. Changing an operator grant, suspending, reactivating and signing out sessions each require the password and a fresh code again in the same request. A lost authenticator is recovered only through the offline `platform:grant --recover` procedure; there are no recovery codes and no self-service reset.

**Suspension and sign-in (implemented, D32).** A suspended account's sessions are deleted and sign-in is refused with a neutral message; password reset and email verification do not restore access, and any surviving session is ended on its next request. Members of a suspended workspace can sign in but cannot open that workspace. A suspended operator keeps their grant but cannot use it. Reactivation restores access from that moment only. See the [confirmed suspension rules](../administration/suspension-proposal.md).

**Support-access consent (implemented, D32).** Platform ownership grants no default access to tenant-private CRM, files or message content. An operator with `support.request` names a workspace, a purpose, record areas and a duration of 1 to 24 hours. Only an active owner of that workspace can approve, decline or later end the request, at `/workspace/support-access`. Approved access is read-only, limited to the named areas, expires on time without a job, confers no membership, and every view is written to both the administration audit and the workspace's audit log. There is no emergency path when no owner can approve (**Assumption / Open**, handoff decision C2).

**Workspace-owner MFA (implemented locally, D34/D35; M1-04 verification pending).** An account with an active `owner` membership must set up an authenticator and enter a code at every sign-in; an enrolled account keeps being asked after it stops being an owner. Until the session passes the check, only `/account/authenticator`, sign-out and email verification are reachable. One authenticator per account is shared with platform step-up, which is unchanged. Recovery is by ten single-use recovery codes or an operator reset with `access.manage`; accounts holding a platform grant have neither and keep the offline reset. Enforcement is always on in production and controlled by `OWNER_MFA_ENFORCED` elsewhere. See the [contract](../m1/owner-mfa-contract.md) and [evidence](../m1/owner-mfa-evidence.md).

## Organization permissions

Proposed MVP policy uses the PDF roles with explicit capabilities. Laravel policies/gates and Form Requests enforce them for web actions and JSON endpoints; PostgreSQL RLS provides the independent database boundary:

| Role | Default permissions |
|---|---|
| Owner | Workspace management, billing, ownership transfer, deletion requests, members; finance capability subject to step-up controls |
| Admin | Settings, invitations, member roles below owner; no ownership transfer or unrestricted billing/deletion |
| Manager | Workspace workflow management and designated shared approvals; no membership/billing changes |
| Member | Assigned operational work; no finance approval or private records outside granted workspace scope |
| Viewer | Read-only permitted records; no sensitive tax/payment secrets by default |

Role grants are organization-specific. Shared collaboration permissions are resource-specific. Add finance approver and agency delegation as explicit capabilities rather than making every admin a payout operator. Platform support has no default tenant-data access; any support access is time-limited, justified, and audited. Revoking a membership also invalidates caches, sessions' active workspace access, pending grants, and worker permissions.

Public brand onboarding needs no general account but only grants access to its particular form and submitted fields. Claiming a brand workspace is a separate verified process. Paid status never bypasses authorization.

## Packaging and subscription lifecycle

**PDF §7:** free creators up to 10 active brands, basic kit/manual invoicing; Pro approximately PHP 299–499 monthly; Creator Business approximately PHP 999; brand Starter/Growth and optional modules. These are hypotheses, not configured prices. Define “active brand” (distinct brands or active deals; treatment of repeat/lost) before enforcing a limit.

**Open:** invited brands free for basic collaboration versus paid brand account from day one. Recommendation: free invitation participation; charge for standalone program-management value later.

Keep plan versions, entitlements, metered usage, and effective dates separate from user roles. Enforce limits transactionally in Laravel domain services called by web controllers, jobs, and APIs, including concurrent creates, uploads, and AI jobs. Cap storage, reminders, members, API usage, and AI cost only after pilot/budget decisions.

Proposed states: free, trialing, active, past_due, canceled, expired. Define grace period, cancellation effective date, renewal, refunds, and downgrade behavior with the chosen provider. Downgrades preserve data and block excess new usage; never silently delete records. Trial expiry and webhook delay require safe reconciliation.

MVP can operate with explicit time-limited pilot entitlements and audited manual subscription reconciliation. Real self-serve charging is a separate approval gate. SaaS subscription billing, creator invoices to brands, and brand-to-creator payouts are three distinct financial flows. No custody, payout fee collection, or automated transfers are included initially.

## Privacy and sensitive documents

The PDF requests consent records, AI consent, export/deletion, encrypted TINs/payment references, and professional review. Implement purpose/version/time/revocation records and minimize collection. Shipping and registration documents must not become generally visible to all collaborators. Define retention, processor agreements, hosting region, and tax-document handling before real-data launch. This plan makes no independent claim of Philippine legal or tax compliance.

## Separate portal entry points

**Confirmed D21:** separate brand and partner login pages share Laravel password/session authentication. A person may have both contexts; authorize each destination through active memberships and relationship grants. Login-page selection grants no permissions. **Proposal:** `/brand/login` and `/partner/login`, with safe intended redirects, context switching and explicit legacy-login behavior. Preserve existing recovery, verification, revocation and MFA requirements. A partnership does not make either side a member of the other's private workspace. Scoped anonymous onboarding remains distinct from a brand account and partnership acceptance. See [portal UI alignment](../m3a/ui-alignment.md).


**Confirmed D23:** role-specific registration creates a creator or brand workspace. Existing users reuse their identity and switch authorized contexts. Login pages share email/password/recovery form structure and a link to the other login, with partner burgundy and brand white headers. Brand invitations require partner acceptance; removal preserves private records. See [M3A handoff](../m3a/implementation-handoff.md).

**M3A implementation:** the concrete routes, durable registration provisioning, creator backfill, owner/admin invitation/acceptance/revocation rules, forced RLS and shared-field allowlist are specified in the [implemented portal contract](../m3a/portal-contract.md). See [status and current evidence](../m3a/status.md). Prior proposal text above records the planning sequence.
