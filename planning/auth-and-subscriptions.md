# Authentication, Authorization, and Subscriptions

## Identity

**Approved application stack:** Laravel with Vue/Inertia. **Initial authentication proposal:** Laravel's Vue starter-kit authentication/Fortify with native Laravel session authentication. Use the same-origin web session for Inertia; a separate JWT login system, Supabase Auth, or managed identity service is not an MVP prerequisite. Password/session login is confirmed (D15). Production MFA is required for platform operators and workspace owners (D18); enrollment, recovery, and enforcement implementation remain pending. Keep `users` independent of future provider identities; add `auth_identities` when social login or enterprise federation is introduced. Later SAML/OIDC should use a maintained integration/provider selected for budget, regional processing, and enterprise needs.

MVP identity acceptance: verified email signup/login through Laravel, recovery, logout/session revocation, invitation acceptance, and account lock/rate limits. Decide password versus magic-link UX with pilots; do not build both automatically. **Confirmed (D18):** require MFA for platform operators and workspace owners before production. MFA for privileged finance actions remains a proposal; admin-role coverage, enrollment/recovery, and enforcement implementation must be specified and tested before production.

Use Laravel session authentication with secure HttpOnly cookies, explicit SameSite policy, CSRF protection, short session lifetimes with rotation, and server-side revocation. If OIDC is used, validate issuer/audience/state/nonce with a maintained client. Account linking requires verification; matching email alone must not silently merge identities. Audit sensitive login and access changes without storing secrets.

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
