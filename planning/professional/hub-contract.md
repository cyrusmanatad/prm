# Professional Hub and manual subscriptions

**Confirmed (2026-11-07):** prioritize PRO-01–PRO-05 before M5-07. Reuse creator storage and implemented Partner tools for independent professionals with individual/business clients, without a brand connection. Existing workspaces remain unchanged. No conversion, checkout, commercial pricing, default quotas, new professional tools, production provisioning or closure of the remaining administration package is authorized.

## Dependencies and acceptance

| ID | Scope | Gate |
| --- | --- | --- |
| PRO-01 | ADM-01 explicit platform permissions, verified identity, MFA, offline bootstrap/recovery | Deny public escalation, unverified/revoked/unenrolled requests; protect last owner; audit privileged operations |
| PRO-02 | Necessary ADM-02 metadata directory and ADM-04 immutable versioned plan assignments | Narrow database functions, runtime RLS, reasons, dates, seven explicit record quotas, optimistic versions and atomic audit |
| PRO-03 | Professional registration, persisted verification intent, workspace selection and shared tools | Separate experience on creator workspaces; unchanged Partner/Brand access; optional existing relationship flow |
| PRO-04 | Transactional subscription enforcement, public intake, workers and authorized exports | Pending/scheduled/expired permit reading, security, setup and exports; deny business writes, approvals and handoff; published profiles stay readable |
| PRO-05 | PostgreSQL races/isolation, migrations, PHP/frontend/browser/visual verification | Record exact checks and remaining gates before resuming M5-07 |

## Subscription semantics

Latest immutable assignment controls the workspace and all its members. No assignment means **pending activation** for professional workspaces and retains existing development behavior elsewhere. Starts are inclusive, ends exclusive, using database wall time. Quotas count all retained records, including voided finance. Active access never widens existing roles or RLS. Individual names use existing brand/business fields. Public pauses disclose no plan details.

**Implementation default:** assignment changes conservatively cancel enabled schedules, ready runs, pending notices and approved drafts for professional workspaces. Users explicitly recreate schedules/approve fresh drafts after renewal. No missed notification replay. Revocations remain permitted while inactive; record deletion/financial corrections are business mutations. Export is an ordinary authorized read, never outreach handoff; finance stays owner-only and private drafts stay actor-scoped. CSV cells neutralize spreadsheet formulas.

## Administration boundary

Platform grants are independent of tenant roles. Separate directory-read and plan-assignment permissions require verified identity and a recent password/TOTP step-up. Runtime functions expose only account/workspace metadata and immutable plans/assignments, never tenant-private content. Offline migration-role commands bootstrap/recover/revoke grants with reasons and audit; no default operator or secret is seeded. Recovery invalidates step-up sessions. Remaining ADM-03/05/06/07, workspace-owner MFA, privacy, provider, UI-05 and all inherited release gates remain open. Existing administration owner coordination is recorded here; no other active owner's changes were present in this clean checkout.

Implementation and local verification are recorded in [handoff](hub-status.md) and [evidence](hub-evidence.md). Release acceptance remains separate.
