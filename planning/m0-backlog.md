# M0 Discovery and Delivery Backlog

## Status and evidence

Implementation is authorized milestone by milestone (D13). Development may start before recruiting 20–30 pilots (D14); no recruited pilots or willingness-to-pay evidence is claimed. Initial product defaults are accepted (D15). The approved stack and source-to-requirement inventory are recorded in [architecture](architecture.md) and [requirements](requirements.md).

M0 planning deliverables are ready for M1. Prototype sessions and rendered desktop/mobile inspection remain outstanding validation work; M0's original visual-validation acceptance is **not complete**. No runtime, accessibility, or capacity validation is claimed. Carry these checks into M1 before accepting UI behavior, and into the pilot gate before commercial launch.

## Initial implementation defaults

- One Laravel application under `src/` with Vue/TypeScript/Inertia; keep reference documents and the saved prototype at the repository root (D16).
- Password/session login, verified email, recovery, and session revocation. Production MFA is confirmed for operators and workspace owners (D18); enforcement remains pending.
- Free invited-brand participation through scoped forms/grants; private creator data stays private.
- Draft invoices and manual reconciliation; defer real issuance, signatures, and transfers.
- AI remains optional; no provider integration is required for the manual path.
- Require approval for external actions; an explicit send command can authorize an invitation.
- Use synthetic local data and private local storage initially. Do not select a paid storage provider or enable sensitive onboarding collection implicitly.

## M1 Foundation backlog

M1 foundation implementation and automated validation are delivered. D19 authorizes M2 development while the manual reviews remain pending; onboarding is delivered in M3 under D20; see [M3 status](m3-status.md).

| Task | Deliverable | Acceptance evidence |
|---|---|---|
| F01: scaffold | Laravel application under `src/`, Vue/Inertia pages, TypeScript, Vite, Tailwind/shadcn-vue, dependency locks | Clean install and production asset build; document exact supported versions and commands |
| F02: identity | Signup, email verification, password recovery, login/logout, session revocation, throttling | Feature tests cover verified/unverified users, invalid credentials, reset tokens, and revoked sessions; local email cannot reach real recipients |
| F03: workspaces | UUID organizations and active memberships with organization-specific roles; workspace selection and switching | Two fixture users/organizations cannot select each other's workspace; revoked memberships fail on the next request |
| F04: database boundary | Separate migration/runtime PostgreSQL roles, forced RLS, transaction-local context, same-owner constraints | Real PostgreSQL tests cover SELECT/INSERT/UPDATE/DELETE, missing context, foreign organization IDs, and reused connections; runtime role has no RLS bypass |
| F05: authorization | Thin controllers, policies, validation, scoped Inertia serialization | Cross-organization IDs and deferred/shared props disclose no private records; read-only members cannot mutate |
| F06: audit and processes | Redacted audit records; Redis worker/scheduler configuration and tenant-context boundary | Audit attribution is correct; repeated worker jobs clear context; no business automation added before M5 |
| F07: delivery | Local Docker Compose, health checks, CI checks, environment example and setup README | Repeatable migrations and asset build; PostgreSQL/Redis stay private; no committed secrets; CI runs relevant tests |

M1 manual acceptance remains pending; D19 permits M2 development while carrying the deferred manual checks. If the execution environment prevents a check, report it as unverified rather than treating configuration as proof.

## Required UI states

Apply these requirements to M1 screens and later feature milestones:

- Authentication: invalid/expired verification and reset links, validation errors, submission progress, throttling, and recovery confirmation.
- Workspaces: no membership, first-workspace creation, switching, revoked access, and forbidden operations.
- CRM (M2): empty pipeline, no search results, invalid input, stale concurrent edit, stage-change failure, and real future snooze.
- Onboarding (M3): expired/revoked invite, save/resume, upload rejection/pending scan, interrupted submission, and correction/resubmission.
- All screens: mobile layouts, visible keyboard focus, labeled controls, announced errors, and accessible alternatives to dragging.

## Deferred decisions and validation

D04 (hosting/storage/region/budget), D05 (future automatic triggers and repeat transitions), D06 (pricing/usage definitions), and D11 (production retention and any future sensitive collection) remain open. Resolve each before its dependent feature. Production MFA, provider credentials, and deployment are later gates. D12 belongs to later payout work.

Before pilot use, inspect all seven prototype boards at desktop/mobile sizes, record console/network failures and keyboard behavior, then compare implemented journeys against source requirements. Before commercial launch, record pilot recruitment/results and agreed success thresholds; development authorization is not evidence of product validation.

## D21 planning follow-up

**Confirmed:** add the connected brand/partner contexts, separate login pages and exact reference UI to delivery planning. **Confirmed D23:** complete M3A before M4: reference snapshot, relationship/schema/RLS review, portal/login contracts, five visible pipeline columns with existing seven-stage storage/backend preserved (D22), both dashboard compositions and visual/security acceptance. See [portal UI alignment](portal-ui-alignment.md). Historical M1–M3 evidence remains valid for its recorded scope, not proof of new UI acceptance. This request starts no application implementation.

See the [M3A implementation handoff](m3a-implementation-handoff.md) for approved D23 login designs, registration and relationship lifecycle, feature states, execution order and next-chat instructions.
