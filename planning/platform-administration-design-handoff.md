# WeavDash Administration — Claude Design Handoff

Prepared: 2026-10-25. Audience: Claude Design and the later Laravel/Vue implementation session. This file is self-contained: give Claude the whole document. Optional screenshots and source links at the end provide additional visual context.

## 1. Instruction to Claude Design

Design a complete, responsive **WeavDash Administration** interface for the owner and authorized operators of a multi-tenant creator/brand collaboration application. Deliver connected screens and interaction states that engineers can later implement in the existing application. Prioritize a coherent, usable administration console with realistic tables, details, forms, security flows and confirmations.

Use this brief as the behavior/data boundary. Make routine layout decisions, but label unresolved business policy rather than inventing it. Do not build a backend, connect accounts, send emails, operate infrastructure or delete data. Use clearly marked synthetic preview data only. The requested output is a visual reference/prototype; it does not implement or approve production operations.

**Confirmed D27:** administration is required before advancing milestones. The engineering sequence starts with ADM-01 authorization/MFA/owner setup, then ADM-02–ADM-07. UI-06 CRM work is already locally verified; UI-07, remaining portal-reference acceptance and M5 notification/automation work follow administration. Design the complete administration package now so it can guide those engineering slices.

### Evidence and availability labels

- **Confirmed:** user-approved requirement or existing repository UI rule.
- **Existing:** verified application entity, field or behavior. This does **not** mean a cross-workspace admin endpoint already exists.
- **Derived:** a UI projection computed from authorized data, not necessarily a stored column. New admin projections still need implementation.
- **Proposal:** suggested screen, field, capability, route or schema to support the administration requirement; names and mechanics remain subject to engineering review.
- **Assumption / Open:** policy/provider/commercial detail not yet settled. Show an explicit unavailable or decision-required state for dependent actions.
- **Mockup:** synthetic design data or visual reference, not a real customer, metric or operating result.

All new administration screens, proposed routes, platform roles/capabilities, suspension controls, MFA flows, support grants and cleanup controls below are **Proposal** implementations of the confirmed requirement. None currently exists as a production-ready admin console. Do not present this document as a final database migration or API contract.

## 2. Product context and boundaries

WeavDash connects creators/partners and brands. A person has one identity and can belong to several workspaces. A workspace is either a **Creator** workspace or a **Brand** workspace. Brand–creator partnerships are explicit relationships, not memberships in the other party's workspace.

Tenant roles currently are **Owner, Admin, Manager, Member, Viewer**. These apply within a workspace. **Platform owner / super admin** is a separate proposed platform grant. A workspace Owner or Admin cannot enter platform administration just because of that tenant role.

Administration is for operating the service: account/workspace access, pilot entitlements, delivery diagnostics, audited support and controlled cleanup. It is not a global editor for creators' private leads, contact notes, onboarding answers, documents or invoices. Show operational metadata by default. Tenant-content access requires a separate explicit, time-limited support grant; platform ownership alone does not unlock it.

One identity can have both platform privileges and tenant memberships. Make switching between **Administration** and an authorized partner/brand workspace deliberate and clearly labeled. Never silently impersonate a customer.

### Scope exclusions

Do not invent paid subscription checkout, MRR/ARR, revenue forecasts, charging/refunds, invoice issuance, transfers, mailbox syncing, AI scoring, campaign analytics or arbitrary database/server controls. Existing finance is private invoice drafts/manual receipt history; it is not platform revenue. Optional commercial pricing, provider settings, recovery targets and retention policies remain open.

Do not add universal “login as user,” automatic uncertain-email retry, unrestricted CSV exports, “delete all users,” editable audit logs or default unrestricted tenant access. Backend permission checks and privacy are engineering requirements even if a control is hidden in the design.

## 3. Visual direction and reusable patterns

The admin area should look like the same WeavDash product, with an explicit **Administration** badge and persistent environment label. It can use a desktop sidebar for denser navigation; that layout is a proposal, not an existing approved admin reference.

| Item | Required direction |
|---|---|
| Body font | **Manrope**, system sans fallback |
| Display/headings | **Bricolage Grotesque**, Manrope fallback |
| Page / cards | Cream `#FBF7EF` / white `#FFFFFF` |
| Primary / hover | Burgundy `#8E1650` / dark burgundy `#5E0E35` |
| Main / body / muted text | `#221A26` / `#4A3F4F` / `#6B5F70` |
| Borders / control borders / dividers | `#EADFCB` / `#D9CBB3` / `#F3ECE0` |
| Focus | Green `#2F7F69`, visible outline with offset |
| Success | Text `#24614F`, background `#E2F3EC` |
| Warning | Text `#7A4B12`, background `#F8EED9` |
| Danger | Text `#912238`, background `#FBECEF`, border `#9D2338` |
| Accents | Mint `#8FD0B8`, sand `#E3C79A`, blush `#F6E3EC` |
| Geometry | Existing cards generally 16px radius; fields/buttons 10px; light borders; restrained shadows |
| Controls | At least 44px touch targets; visible labels; primary, secondary and danger-outline hierarchy |
| Icons | Consistent Lucide-style line icons, with text labels or accessible names |
| Spacing | Follow existing 4px-based spacing rhythm; typical section gaps 20–28px, card padding 20–24px |

Use cream canvas, restrained burgundy accents, readable data density and clear typography. Avoid replacing the brand with a generic dark terminal or purple/blue dashboard. Status must use text/icon as well as color. Do not use severity color for ordinary identity/type labels.

Design counterparts to existing engineering components: **FoundationShell**, **BrandLogo**, **AccountMenu**, **ContentSection**, **FieldGroup**, **FormField**, **SelectField**, shared **Button**, **ActionGroup**, **DialogClose**, **ConfirmationDialog** and persistent field/banner feedback. Repeated tables, detail summaries, status badges and impact panels should share one design vocabulary.

### Responsive behavior

Provide reference views at **1440×1000**, **1024×1000**, **390×844** and **320×800**; check 768px layout too. Sidebar becomes an accessible navigation drawer on small screens. Stack secondary panels below primary content. Avoid page-level horizontal scrolling; contain wide tables in labeled scroll regions or use equivalent compact records. Preserve key identity/status/actions on mobile, with secondary fields in details. No hover-only row actions. Long names, email addresses, IDs, errors and translated-length copy must wrap safely.

Keep dialogs within the device viewport and scroll their content internally. A sticky action footer must not obscure fields, keyboard focus or validation. Include reduced-motion behavior and visible keyboard focus.

## 4. Information architecture and screen inventory

**Proposal routes** below organize the prototype; they are not existing backend endpoints.

| Navigation | Proposed screens | Primary entities | ADM coverage |
|---|---|---|---|
| Access | `/admin/login`, verification, MFA enrollment/challenge/recovery, access denied | Identity, MFA enrollment, platform grant, admin session | ADM-01 |
| Overview | `/admin` | Authorized operational aggregates and attention items | ADM-02/ADM-05 |
| Accounts | `/admin/accounts`, account detail with Overview / Workspaces / Security / Activity | User, membership, sessions, access status | ADM-02/ADM-03 |
| Workspaces | `/admin/workspaces`, detail with Overview / Members / Entitlements / Operations / Activity | Organization, memberships, operational metadata | ADM-02–ADM-05 |
| Platform access | `/admin/operators`, operator detail, grant/revoke forms | Platform grants, MFA readiness | ADM-01/ADM-03 |
| Plans and entitlements | `/admin/plans`, plan-version detail/create; `/admin/assignments`, assignment detail/create | Pilot plan versions, assignments, usage | ADM-04 |
| Notifications | `/admin/notifications`, notification diagnostic detail | Notification outbox and redacted related metadata | ADM-05 |
| Jobs and health | `/admin/operations`, failed-job detail, heartbeat/service panels | Runtime health projections, failed jobs | ADM-05 |
| Support access | `/admin/support-access`, request/grant detail and scoped support session | Support requests/grants | ADM-06 |
| Audit | `/admin/audit`, event detail | Platform audit; separately authorized tenant-event metadata | ADM-06 |
| Data cleanup | `/admin/cleanup`, manifest wizard, run detail/results | Fixture provenance, cleanup runs/items, protected records | ADM-07 |
| My admin security | `/admin/security` | Own MFA/session state and recovery readiness | ADM-01/ADM-07 |

Workspace membership is shown as a detail tab rather than a second competing global accounts directory. Relationships, invitations and upload metadata belong under a workspace's Operations tab/support scope; they do not require independent global CRUD modules. Keep navigation manageable.

### Global shell and list conventions

Header: WeavDash logo, Administration label, environment (**Development / Staging / Production**), current operator/capability label, account/security menu, sign out. Workspace context is explicit whenever viewing a workspace. Support sessions add a persistent banner with target workspace, scope, expiry and End support access.

List pages: title, short purpose, permitted primary action, labeled search, relevant filters, applied-filter summary, clear filters, table/list, result range/count and pagination. Counts must say **matching records, all pages**, **this page**, or **unavailable** accurately. A pending filter indicator must not claim the new filter is applied while old results remain. Changing filters resets paging; URL/history must restore the selected filters and records. Empty matches and empty datasets have different messages.

Detail pages: human-readable title plus copyable non-secret ID, status, metadata summary, tabs and permitted actions. Use a full detail page for complex records and short drawers for quick inspection; preserve filter/paging context on return. Keep sensitive actions out of ordinary row clicks and avoid duplicating the same edit form in multiple places.

## 5. Entities, visible columns, details and actions

Fields below describe the **UI contract**, not permission to display raw database rows. Default columns are the first visible desktop table; secondary fields go in expandable details or an optional column chooser. Mobile uses the first identity/status fields plus an explicit action menu. Dates need readable timezone context and exact timestamps in details. Show `Unknown` / `Not collected` / `Not configured` where applicable, never fake zero or green health.

### 5.1 Accounts / users

**Existing:** `users.id` is a numeric identity; `name`, `email`, `email_verified_at`, `created_at`, `updated_at`. **Derived:** verification state, active membership/workspace counts and, once platform grants are implemented, whether a platform grant exists. **Proposal:** suspension state/reason/timestamps, security/MFA summary, last successful sign-in and fixture/protected-account markers. `sessions.last_activity` is not a stored successful-login timestamp.

| Area | UI specification |
|---|---|
| Default columns | Account (name + email); Verification; Access state; Workspaces; Platform access; Joined; Actions |
| Secondary/detail | Account ID; created/updated; verified at; suspension actor/reason/time; latest successful sign-in if collected; MFA status with required/not-required context; active sessions; grant summary; protected-owner/fixture classification |
| Filters | Name/email/ID search; verified/unverified; active/suspended; has platform grant; workspace; joined date; synthetic/protected classification when available |
| Tabs | Overview; Workspaces; Security; Activity |
| Actions | View; suspend/reactivate with impact review; revoke sessions; view related workspace/grant; initiate governed support request |
| Restricted/omitted | No password/hash, reset token, secret recovery codes, session cookies/payloads; no manual “mark email verified”; no direct password setting, email takeover or silent impersonation |

Suspension must distinguish account-wide impact from one workspace's access. An account with multiple workspaces needs an impact summary. Access suspension is proposed; unverified email is a separate state. Record reactivation does not restore previously revoked grants or replay obsolete work. Editing someone else's identity details and deleting an account are outside the initial admin actions until identity/retention contracts define them.

### 5.2 Workspaces / organizations

**Existing:** UUID `id`, `name`, `kind=creator|brand`, `created_at`, `updated_at`. Workspace kind is immutable. **Derived:** active members/owner metadata, selected entitlement state, operational counts. **Proposal:** suspension metadata, environment/fixture provenance and protected-record markers. Do not imply a single owner column exists; the membership model can represent multiple owners.

| Area | UI specification |
|---|---|
| Default columns | Workspace name; Type (Creator/Brand); Access state; Owners; Active members; Entitlement state; Created; Actions |
| Secondary/detail | Workspace UUID; updated at; owner list; suspension reason/actor; selected plan/version and dates; counts for supported resources; relevant pending/uncertain notices; last observation time |
| Filters | Name/ID; type; active/suspended; owner identity; entitlement state; created date; fixture/protected classification |
| Tabs | Overview; Members; Entitlements; Operations; Activity |
| Actions | View; suspend/reactivate with impact preview; manage assignment with capability; inspect redacted diagnostics; request scoped support |
| Restricted/omitted | No change-type toggle, automatic ownership transfer, global CRM/financial-document browsing or unrestricted workspace delete |

Operations tab summarizes relationship/capability/upload state without exposing private contents. Record usage is a count; it must not expose lead/contact names or financial amounts to an operator who only has metadata access.

### 5.3 Workspace memberships

**Existing:** composite key (`org_id`, `user_id`), `role`, `revoked_at`, `created_at`. Roles are Owner/Admin/Manager/Member/Viewer. **Proposal:** mutation version/change metadata if the administration workflow requires them.

| Area | UI specification |
|---|---|
| Default columns | Account; Workspace role; Membership state; Joined; Revoked at; Actions |
| Detail | Workspace and account IDs; role description; change/audit history; effective account/workspace suspension separately |
| Filters | Account name/email; role; active/revoked |
| Actions | View account, view related audit; role change/revoke only as a **policy-dependent proposed flow** with explicit affected workspace |

Do not treat platform grant revocation, tenant membership revocation and account suspension as the same action. Initial membership mutation scope and workspace-owner protections need engineering/policy review; show read-only metadata until enabled. Do not add a public invite/create-customer workflow simply to populate the directory.

### 5.4 Platform operators and grants

**Proposal entity:** explicit grant linked to `users.id`. Suggested UI-backed fields: grant ID, user ID, capability set or platform role, state, granted by/at, expires at if applicable, revoked by/at, reason and version. `Platform owner` is the initial primary role; operational/support/audit capability groupings below are design proposals, not approved additional roles.

| Area | UI specification |
|---|---|
| Default columns | Operator (name/email); Role/capabilities; Grant state; MFA readiness; Granted at; Expiry; Actions |
| Detail | Grant ID; grantor; reason; capability list; security prerequisites; last privileged verification if tracked; revocation history; last usable owner protection |
| Filters | Operator; capability/role; active/expired/revoked; MFA ready/not ready |
| Forms | Select an existing verified identity; choose permitted capabilities; optional expiry if supported; required reason; review effective access |
| Actions | Grant access; change scoped privileges; revoke grant; inspect audit; view account |

No shared administrator login or fixed default credentials. No self-grant via registration. Prevent removal/suspension of the last usable platform owner. Show a clear unavailable action with explanation, not a confirmation that can never succeed. Privilege reduction/revocation requires modal confirmation; privilege elevation needs explicit review, reason and fresh verification.

### 5.5 Security posture, MFA and sessions

**Existing:** sessions store user association, IP address, user agent and last activity. Never expose raw session IDs or payloads; use an opaque non-authenticating display reference. **Proposal:** MFA enrollment/confirmation, recovery readiness and privileged reauthentication event metadata. Enrolled is not equivalent to successfully challenged for this action.

| Surface | Visible fields and actions |
|---|---|
| Own admin security | Identity/verification; MFA required/enrolled state; enrollment date if available; recovery readiness; current/other session summary; own sign-out/recovery actions |
| Session table | Device/browser summary; masked network/IP if policy permits; last active; current session badge; access state; revoke action |
| Account security tab | Read-only enrollment/readiness; active session count; authorized revoke-sessions flow; audit history. Do not show another person's enrollment QR or recovery codes |
| MFA enrollment/challenge | Identity context; proposed authenticator enrollment instructions; verification input; expiry/error/rate-limit states; cancel/sign out; recovery alternative |
| Recovery | Own one-time recovery-code use if supported, exhausted-code state and governed owner-recovery guidance; no “disable MFA for anyone” button |

**Assumption / Open:** exact MFA methods and recovery policy. An authenticator/TOTP flow is a **Proposal** for the prototype. QR secret and recovery codes are limited to the enrolling person's dedicated security step and one-time recovery display; use dummy artwork/codes, never add them to accounts/operator tables or reusable diagnostics. Code regeneration/credential changes must explain consequences and require fresh verification under the eventual security contract.

### 5.6 Pilot plan versions

**Existing:** `pilot_plan_versions`: UUID `id`, `name`, positive `version`, `quotas` JSON object, `created_at`. Plans are immutable versions. Cross-workspace admin management is new work.

| Area | UI specification |
|---|---|
| Default columns | Plan name; Version; Assigned workspaces (derived); Created; Actions |
| Detail | Plan UUID; all seven resource limits; immutable-version explanation; related assignments; creation audit |
| Filters | Name/ID; version; creation date |
| Create form | Name; explicit version; seven integer create limits; required administrative reason; review |
| Actions | View version; create new version; optionally copy values into a new-version draft; view assignments |

Do not edit/delete an assigned version in place. No commercial prices, billing intervals, “Unlimited,” AI/storage/API quotas or recommended tier limits are approved. Use **Example pilot plan** in mockups, not real Free/Pro/Enterprise pricing.

### 5.7 Assignments and usage

**Existing:** `pilot_assignments`: UUID `id`, `org_id`, `plan_id`, positive assignment `version`, `starts_at`, `ends_at`, `created_at`. Assignment history is append-only; the newest assignment version is selected immediately. **Derived state:** `unassigned-development`, `scheduled`, `active`, `expired`. Suspension is a separate access condition.

| Area | UI specification |
|---|---|
| Default columns | Workspace; Plan + plan version; Assignment version; Entitlement state; Starts; Ends; Actions |
| Detail | Assignment UUID; created at; audit actor/reason when available; selected/history marker; usage and projected effect of replacement |
| Filters | Workspace; plan/version; state; ending date range; selected vs historical |
| Assignment form | Workspace; immutable plan version; start date/time with explicit zone; required end date/time; reason; current-versus-proposed review |
| Actions | View; create replacement assignment; inspect plan/usage/history |

**Important behavior:** a future-dated newest assignment blocks creates until its start; it does not leave the previous plan active until then. Expiry does not fall back to an older assignment. Show this in the review dialog. There is no automatic renewal/grace period or billing action. Replacing with a lower limit preserves existing records; it never deletes data. Do not offer an unimplemented “cancel assignment,” “unlimited access” or in-place date edit.

Usage table: **Resource / Retained records / Create limit / Remaining creates / State**. Supported resources are Pipeline leads (`pipeline_entries`), Contacts (`brand_contacts`), Reminders, Onboarding requests, Outreach templates, Invoice drafts and Manual payments. Retained completed reminders/voided finance records count. Limits are explicit integers; omitted limit means zero within an assigned plan. Show `0 remaining` and `Over limit by N` without deleting records. Unassigned development has no configured plan: display that state, not a zero limit or commercial free tier. Do not label resource counts as “active brands.”

Replacing entitlement access that removes/reduces creation privileges needs explicit impact review and the shared confirmation pattern. A routine non-revoking save can remain immediate once valid; avoid confirmation for every field change.

### 5.8 Notification intent and delivery diagnostics

**Existing:** `notification_outbox`: UUID `id`, `org_id`, `actor_id`, `kind`, `subject_id` or `onboarding_request_id`, `state`, `created_at`, `started_at`, `completed_at`. Current kinds: `partner.invitation`, `onboarding.invitation`. Future M5 kinds must appear as unavailable/planned, not working flows. Encrypted payload and invitation hashes are never UI columns.

| Area | UI specification |
|---|---|
| Default columns | Notification reference; Workspace; Kind; State; Created; Age/time in state (derived); Actions |
| Detail | Actor identity metadata; safe related-record reference; timestamps/timeline; sanitized operational classification; current authorization/subject-validity summary only if supplied |
| Filters | ID; workspace; kind; exact state; created date; age threshold with configured/not-configured indication |
| Actions | Inspect diagnostics; open authorized related metadata; view audit; open runbook. Reconciliation/resend/cancellation controls are policy-dependent and unavailable until supported |

Use the actual current state meanings:

| Stored state | Display label | Meaning |
|---|---|---|
| `pending` | Queued | Durable intent exists; sending has not been established |
| `sending` | Sending | Reserved send in progress; transport outcome may still be unknown |
| `sent` | Accepted by transport | Transport returned successfully; recipient delivery/read is not proven |
| `captured` | Captured locally | Development/local capture; no external delivery claim |
| `cancelled` | Cancelled | Intent will not send; show sanitized reason only if collected |
| `uncertain` | Outcome uncertain | May already have sent; operator reconciliation required; no automatic retry |

There is currently **no `failed` outbox state**, recipient delivery webhook or general outbox attempt-history column. Failed queue jobs are a separate entity. Provider receipt, recipient address/masked destination, attempt counts, provider message reference and cancellation/failure reasons are **Proposal** diagnostics only if safely recorded; otherwise show unavailable. The current encrypted payload is not a reporting source to decrypt for global lists, and it is erased after terminal processing. Do not promise recipients/history that the retained data cannot provide.

Never render email bodies, live invitation links, capability tokens or decrypted payloads. Do not present “Resend all” as an available operation. An uncertain-send detail should explain the ambiguity and show **Review delivery** guidance with unavailable resend policy, rather than a successful retry simulation.

### 5.9 Jobs, workers, scheduler and service observations

**Existing:** Laravel `failed_jobs` has UUID/reference, connection, queue, payload, exception, failed timestamp. `job_batches`/database `jobs` tables exist, but the configured production-style queue is Redis; database-job row counts are not the authoritative live Redis backlog. Raw payload/exception data is not safe UI output.

**Proposal derived observations:** worker/scheduler heartbeats, last dispatch time, queue depth/oldest job age, observation age, app/database/Redis/scanner health. Existing Compose health checks alone do not establish full monitoring or provider health.

| Surface | Visible columns/fields |
|---|---|
| Service panels | Service; Healthy/Degraded/Down/Unknown/Not configured; last observed; observation age; sanitized summary; inspect/runbook action |
| Queue table | Queue name; backend; pending count if observed; oldest pending age; failed job count for stated period; worker observation; observed at |
| Failed jobs | Safe job reference; allowlisted job type if known; queue; failed at; sanitized error category; related notification reference if correlated; Actions |
| Scheduler panel | Last heartbeat; last dispatch; expected cadence if configured; lateness if computable; configuration status |

Read-only initial actions: inspect sanitized details, filter related notifications, view audit/runbook, refresh observation. Do not add arbitrary payload editing, shell/SQL terminals, service restarts, mass queue flush, deploy buttons or blanket retry. Any future allowlisted retry requires current-authorization/idempotency checks and a separate operation contract. Missing telemetry is **Unknown**, not zero backlog or Healthy.

### 5.10 Audit events

**Existing tenant audit:** `audit_logs.id`, `org_id`, `actor_id`, `event`, `subject_id`, `occurred_at`. Existing pilot audit: `pilot_admin_events.id`, operator label, reason, event, subject ID, optional org ID, created at. Local pilot operator text is not an authenticated production-admin identity.

**Proposal platform audit:** authenticated actor/grant, action, target type/ID, optional workspace, reason, success/denied/failed result, correlation reference, redacted change summary and timestamp. Model global actor/target types explicitly; an existing tenant `subject_id` UUID is not a universal numeric-user target field.

| Area | UI specification |
|---|---|
| Default columns | Occurred at; Actor; Action; Target; Workspace/platform scope; Outcome; Actions |
| Detail | Event ID; authenticated actor/grant; reason; redacted changed-field names/allowed before/after; correlation reference; source (platform/tenant/local pilot); support-grant reference if applicable |
| Filters | Date/time range; actor; action; target type/ID; workspace; outcome; source/correlation |
| Actions | View event; navigate to authorized account/workspace/diagnostic metadata |

Audit is read-only and append-only. No edit/delete buttons or full raw JSON dump. Do not fabricate missing reason/outcome fields for historical events; display **Not recorded in this event source**. An audit export is not automatically authorized; omit or label policy-dependent. Never copy private record contents into an audit detail merely to explain a change.

### 5.11 Support requests and access grants

**Proposal entities:** request and scoped grant (may be implemented as one lifecycle). Suggested fields: ID, operator user ID, workspace ID, purpose/reason, allowed resource/action scope, requested expiry/duration, authorizing party/evidence reference, requested/approved/granted/expired/revoked timestamps and actors, state, version.

| Area | UI specification |
|---|---|
| Default columns | Request/grant reference; Operator; Workspace; Scope summary; State; Expires; Actions |
| Detail | Reason; authorization/consent summary; exact resources/actions; start/expiry; grantor/approver; linked audit history; revocation reason |
| Filters | Workspace; operator; requested/active/expired/revoked/denied state; scope; expiry range |
| Request form | Target workspace; specific purpose; selected allowlisted resources/actions; proposed end time; authorization evidence; review |
| Actions | Request; approve/deny if policy permits; open explicitly authorized support view; end own session; revoke grant; inspect audit |

No automatic self-approval is assumed. Consent/authorization, approver separation, permitted scope/duration and emergency access remain **Assumption / Open**. Show an honest **Support access policy not configured** state, alongside a representative explicitly labeled policy-enabled preview. An approved grant is not active after expiry/revocation. The support view must show its banner and current operator identity; do not reproduce the customer's avatar as though signed in as them. Start with metadata/read-only scope; any write scope requires an explicit later contract.

### 5.12 Synthetic fixture provenance and protected records

**Proposal entity/projection:** fixture batch/environment provenance with stable fixture identifiers, created by/at, source, linked record inventory and protection/exclusion reasons. Existing `@example.test` accounts are useful mock data but email pattern or name alone is not reliable cleanup provenance.

Visible inventory columns: **Record type / Record reference / Parent workspace or fixture batch / Synthetic evidence / Proposed disposition / Protection or block reason**. Filters: fixture batch, entity type, workspace, protected/excluded/eligible/blocked. Unknown provenance is excluded by default.

Protected categories include the real platform owner and grants, required audit/financial history, configuration and records connected to real identities/workspaces. A protected marker is not permission to ignore legal/retention decisions. Cross-links from synthetic to protected/unknown data must produce an explicit dependency/block, not a cascade-delete assumption.

### 5.13 Cleanup runs, manifests and execution results

**Proposal:** cleanup run with ID, environment, requesting operator, reason, fixture scope, state, created at, manifest version/reference, inventory counts, blocked/excluded reasons, protected-account checks, review/approval metadata, backup/recovery evidence reference, execution times, result summary and correlation reference. Manifest items need record type/reference, dependency group, intended disposition, exclusion/block reason and outcome. Database object keys, tokens and private contents never appear in the manifest UI.

| Area | UI specification |
|---|---|
| Runs table | Run reference; Environment; Fixture scope; State; Eligible/blocked/protected counts; Requested by; Created; Actions |
| Proposed states | Draft, Inspecting, Ready for review, Blocked, Approved, Running, Completed, Partial failure, Failed, Cancelled. These are design states, not existing storage enums |
| Detail | Exact reviewed manifest; per-entity counts/dispositions; protected owner/grants check; dependencies; authorization/recovery prerequisites; immutable execution history; residual failures |
| Actions | Create dry run; inspect inventory; narrow scope; exclude items; refresh/regenerate manifest; review; authorized execute; inspect results; cancel before execution if supported |

Design a staged flow:

1. **Select scope:** environment and explicitly identified synthetic fixture batch; purpose/reason. Never preselect all accounts/workspaces.
2. **Dry-run inventory:** per-entity impacts and dependencies, protected/unknown exclusions, pending synthetic notifications/jobs/files, retention blocks. Nothing is deleted.
3. **Review prerequisites:** protected owner/MFA check, relevant recovery evidence, exact manifest version, required approvals. Show blockers with links/guidance.
4. **Confirm execution:** identify environment and exact manifest/counts; fresh verification and required reason; shared destructive confirmation. Initial focus is Cancel. A typed phrase may be a proposed extra safeguard for environment/manifest identity, not a replacement for authorization.
5. **Execution/results:** progress and meaningful completed/blocked/failed outcomes, protected-account survival, residual records/files/jobs and audit reference. Do not imply undo or automatic restore.

Changing the manifest invalidates its approval. Server revalidation must catch a stale manifest or new dependency before deletion. Partial failure needs explicit remaining work, not a generic success toast or “Retry all” button. Prototype execution uses local synthetic state only and a visible **Preview — no real data deleted** notice. Real cleanup is a separately reviewed execution action after the feature exists.

### 5.14 Related business entities — metadata only

These are necessary for impact previews, support scope, workspace diagnostics and cleanup inventories. They do not require standalone global CRUD screens.

| Existing entity | Possible safe metadata columns, subject to explicit projection authorization | Boundaries / proposed admin actions |
|---|---|---|
| Partner invitations | Reference; brand workspace; lifecycle state; created/expiry; notification reference. Mask recipient only if authorized and available | Inspect lifecycle/impact; no token, invite URL, acceptance-as-user or automatic resend |
| Partnerships | Reference; brand workspace; creator workspace; active/revoked; accepted/revoked timestamps | View relationship impact; termination only under an explicit governed support/access-management policy; no access to either side's private CRM |
| Onboarding requests | Reference; owning workspace; lifecycle state (`draft`, `submitted`, `correction`, `accepted`, `revoked`); expiry; generation/version if available; document counts | No business/contact answers, brief, correction note, submission snapshot, capability URL or form access by default |
| Onboarding sessions | Non-authenticating display reference; request reference; expiry; effective active/expired state | Impact count only; never session token/hash or bearer identifier |
| Onboarding documents | Safe reference; workspace/request reference; MIME class; size; scan state (`pending`, `clean`, `rejected`) | Names can contain private data: exclude filenames by default. No content preview/download/storage key/checksum. Scanner retry only if separately authorized and implemented |
| Media kits | Safe reference; workspace; published state; revision; consent-state metadata if authorized | No unpublish/delete shortcut; access-revoking saves require governed confirmation. Public preview, if offered, uses already-public fields only |
| Lead links / public capture receipts | Safe link reference; workspace; expiry/revocation; receipt count | No token/hash/capability URL or captured prospect details. Revocation only via explicit support/access policy |
| Pipeline, contacts, reminders, templates | Workspace-level retained counts and quota state | No global brand/contact names, notes, rates, reminder text or template contents; record scope only for explicitly authorized support |
| Invoice drafts / lines | Workspace retained counts, aggregate quota usage, retention/block classification | No private customer/item/amount details by default; no platform issue/charge/refund/void control |
| Manual payments / allocations / reversals | Workspace retained counts and retention/block classification | Financial history must not be erased as disposable test data without provenance/retention review; no money-movement UI |
| Submissions/events/commands/idempotency records | Reference/type, owning workspace, dependency/retention class where authorized | Background dependencies for audit/cleanup, not a global raw-content browser |

UI references must not reuse bearer/session tokens as “copy ID.” Public IDs still need authorization; a displayed ID does not grant access. Workspace Operations may expose approved summary counts while the underlying records remain unavailable to the operator.

## 6. Overview dashboard

Use a concise operational overview with links into the real filtered lists, not a marketing analytics page.

**Proposed metric cards:** active accounts; active creator/brand workspaces; active pilot assignments; pending notices; uncertain sends; failed jobs in a stated period. Each card needs count scope, period/observation time and unavailable/loading state. A count filtered by the operator's capability is labeled as such. Do not synthesize telemetry or financial charts.

Attention list: uncertain-send review, missing/stale scheduler observation, expired pilot assignments, operator MFA not ready, support access nearing expiry, blocked cleanup reviews. Show severity, affected reference/workspace, age, concise next step and an authorized link. Avoid invented thresholds; use “Threshold not configured” where required. Include recent redacted administrative activity. Keep the first release useful without graphs.

## 7. Proposed capability and action matrix

This is a **Proposal** for communicating permission-aware designs, not approval of final role names or a delegation model. Initial implementation may use only the platform owner and explicitly scoped grants. Every granted admin capability requires verified identity, enforced MFA and active access; all others are denied.

| Capability group | Typical allowed UI | Must not imply |
|---|---|---|
| Platform owner | Operator grant management, protected setup/recovery, other explicitly granted administration capabilities | Unrestricted tenant-private content or silent impersonation |
| Account/workspace administration | Metadata directories, permitted suspension/reactivation/session revocation | Automatic operator management, commercial billing or tenant ownership takeover |
| Entitlement administration | Plans/assignments/usage and audit | Charging, invoice issuance or private finance access |
| Operations diagnostics | Redacted notices, failed jobs, health and runbooks | Arbitrary payloads, secrets, service/queue controls or uncertain-send replay |
| Audit review | Permitted immutable event metadata | Audit editing/deletion, unrestricted exports or tenant content snapshots |
| Support | Request/use an explicitly authorized scoped grant | Automatic self-approval, permanent membership or access after expiry |
| Synthetic cleanup | Dry-run inventory and separately authorized manifest execution | Deleting protected/unknown/retained data or issuing a database reset |

Show representative owner, limited operator and denied-user states. Hide navigation to wholly unauthorized modules; when a user can inspect a record but cannot perform a specific action, use a clear read-only/disabled state with a reason. Avoid dense per-row policy jargon in the normal product flow.

## 8. Actions, forms and confirmation behavior

Use explicit verbs and record identity: **Suspend account**, **Suspend workspace**, **Revoke platform access**, **Revoke support grant**, **Sign out sessions**, **Replace assignment**, **Execute reviewed cleanup**. “Delete” is never a substitute for suspend/revoke.

Required confirmation structure:

- Human-readable affected account/workspace plus safe reference.
- Concrete consequence and server-supplied impact summary, including the operator's own session/last-owner effect where relevant.
- Required reason; fresh password/MFA verification when the operation policy requires it. Do not ask for a customer's password.
- Explicit action label and **Cancel**, initially focused Cancel; focus containment, Escape and trigger restoration.
- Pending state that prevents duplicates; persistent inline server errors; stale-version response with copy-draft/reload guidance.
- Close only after success. Validation/authorization/conflict failures retain entered reason and actionable recovery guidance.

Confirmation must live outside any already open form/editor drawer; avoid stacked full-screen editors. Simple navigation and ordinary non-revoking saves do not need confirmation. Sensitive privilege elevation still needs clear review/verification even though it is not destructive.

Access-impact policy for suspension remains open: design a server-backed preview that states affected sessions, workspaces, shared relationships, public links/capabilities and queued/scheduled work. Never promise a particular cascade from guessed counts. Reactivation should explicitly retain prior revocations and not replay obsolete messages. Audit is part of the action outcome; stale errors cannot disappear solely in toasts.

## 9. Required access, empty, error and exceptional states

Design these states as part of the reference, not a follow-up polish task:

| Situation | Required UI response |
|---|---|
| Not signed in / unverified | Appropriate existing-identity login/verification step; no admin registration |
| No platform grant / grant revoked | Generic access denied with sign-out or authorized workspace return; do not enumerate private records |
| MFA required / bad code / recovery unavailable | Clear enrollment/challenge/recovery flow, accessible field error and throttled retry state |
| Last usable owner targeted | Action unavailable with protection explanation and governed recovery path |
| No data vs no matches | Distinct empty copy; permitted setup action only where meaningful; clear filters for no matches |
| Loading / unavailable observation | Layout-preserving loading and explicit Unknown/Not configured, rather than fake zero or healthy state |
| Pending action / repeated click | Visible progress, disabled duplicate action, preserved focus/error recovery |
| Validation / network failure | Persistent linked field/banner error; retained draft; safe retry where supported |
| Record changed / expired grant | Explicit reload/re-authorize action; no automatic overwrite or continued private access |
| Uncertain SMTP outcome | Ambiguity explanation, no auto-retry; correlation/runbook guidance |
| Expired/scheduled pilot | Existing records preserved; explain create restrictions and selected assignment dates |
| Out-of-scope support record | Access denied without leaked content; banner/scope remain explicit |
| Cleanup protected/unknown/dependency block | Excluded/blocked inventory, reason, prerequisites; execution unavailable |
| Cleanup partial failure | Per-item/group outcomes and retained work, immutable history, no false complete state |

Long-table counts, filters and badges must match the displayed server state and documented scope. System/backend errors must not expose stack traces, SQL, environment variables or secret configuration. Clipboard success/failure feedback must be truthful and accessible. Routine success can use a toast; recovery instructions remain on the page.

## 10. Synthetic design data and linked scenarios

Use only invented identities such as **Morgan Reyes — platform owner**, **Ari Cruz — limited operations reviewer**, **Jordan Vale — creator account**, **Taylor Reed — brand account**, with `@example.test` emails. Label previews **Synthetic demo**. Do not include real credentials or production account details.

Keep relationships internally consistent: **Cedar Studio** (Creator) belongs to Jordan; **Loom & Co** (Brand) belongs to Taylor; the same workspace/account references appear in directories, entitlements, notifications, support and audit. Include a second workspace for Jordan to expose account-wide versus workspace-only impact. Morgan is protected from fixture cleanup. The limited operator sees only allowed modules and metadata.

Required fixture scenarios:

1. Verified real-owner stand-in with MFA, active platform grant and protected cleanup status.
2. Limited operator, revoked operator, unverified account and MFA-not-ready state.
3. Active/suspended account and active/suspended workspace, plus revoked membership.
4. Enough accounts/workspaces to demonstrate pagination and matching/all-page counts; long names and emails.
5. Example immutable plan versions and active, future-start, expired and unassigned-development workspaces. Quota values are synthetic examples only; include over-limit retained usage.
6. All six actual notification states; both current kinds; local-capture label; unavailable recipient/transport metadata; uncertain-send guidance.
7. Failed queue job with sanitized category; unknown/missing/stale telemetry and one observed healthy service. All values are preview fixtures, not measured operations.
8. Support policy-unconfigured, pending request, approved scoped preview, expired/revoked grant and forbidden resource.
9. Audit examples with successful/denied proposed platform events and a historical tenant event with unavailable reason/outcome.
10. Cleanup dry-run with synthetic rows, protected Morgan, unknown-provenance exclusion, retained-finance block, dependent files/queued notices, stale manifest and partial failure.

Cross-screen preview actions may update a coherent in-memory fixture only. Provide a visible reset-preview affordance if useful, separate from any production-style cleanup control. All financial amounts, commercial plans, delivery outcomes and monitoring values must remain clearly synthetic or unavailable; use no invented real performance claims.

## 11. Deliverables requested from Claude Design

Provide a connected visual prototype and a complete reference package:

1. Admin shell/navigation, overview, all inventory/detail pages above and the essential access/security flows. Work through the role/permission states, not only the owner happy path.
2. Account and workspace suspension impact/reason/confirmation flows; grant/revoke controls and last-owner protection; immutable plan creation and assignment replacement review.
3. Notification uncertainty and local-capture details; failed-job/unknown-health views; audit review; scoped support request/banner/expiry flows.
4. Cleanup scope → dry run → blocked/review → confirmation → execution/results, including protected owner and partial failure.
5. Desktop/tablet/mobile reference captures at the stated widths, plus populated/empty/loading/error/disabled states and long-content handling.
6. Exportable HTML/CSS or equivalent inspectable source, local assets/fonts and license notices, a short component/token inventory and a screen/state/interaction index. If only one HTML is supplied, include explicit navigation between screens and a state selector for exceptional views.
7. A concise behavior/data note describing columns, filter behavior, fixture relationships, permissions and any departures from this brief. List decisions still needed instead of silently resolving them.

References must be usable later without a hosted design account: prefer a portable folder with README, entry HTML, assets/fonts and screenshots. Do not replace the existing Laravel/Vue application with another framework. A design prototype's code is a reference to port into existing shared Vue components, not permission to introduce a competing component/styling system or backend.

**Acceptance checklist:** brand fonts/colors retained; all ADM areas represented; entities/columns distinguish Existing/Derived/Proposal; every visible action has a defined permission and outcome; no unauthorized private data; no fabricated delivery/health/revenue claim; no unrestricted cleanup; desktop/mobile keyboard/focus and confirmation states demonstrated; unavailable business/provider policy is clear.

## 12. Engineering mapping and source references

For the later implementation session, retain these sources with the returned design. The brief above remains understandable without opening them.

| Reference | Purpose |
|---|---|
| [Administration contract](platform-administration-contract.md) | Confirmed package, ADM acceptance, privacy/permission boundaries |
| [Remaining tasks](remaining-tasks.md) and [current handoff](implementation-handoff.md) | D27 priority and unfinished work; no milestone waived |
| [Identity and subscriptions](auth-and-subscriptions.md) | Tenant/platform distinction and MFA/support policy |
| [M4 finance contract](m4-finance-contract.md) | Immutable pilot versions, latest assignment semantics, record quotas and commercial limits |
| [Notification contract](m5-notification-contract.md) and [onboarding email contract](m5-onboarding-email-contract.md) | Durable outbox, captured/sent/uncertain meaning and capability safety |
| [Foundation tokens](../src/resources/css/foundation.css) and [portal theme](../src/resources/css/portal-theme.css) | Actual approved fonts, palette, focus and controls |
| [Shared components](../src/resources/js/components/) and [foundation layout](../src/resources/js/layouts/FoundationShell.vue) | Reuse targets; screenshot/reference implementation context |
| [Identity migration](../src/database/migrations/0001_01_01_000000_create_users_table.php) | Existing user/session fields; authentication secrets excluded from UI |
| [Foundation SQL](../src/database/sql/foundation.sql), [portal SQL](../src/database/sql/portals.sql) | Existing organization/membership/audit/relationship entities |
| [Pilot SQL](../src/database/sql/pilot-entitlements.sql) and [entitlement projection](../src/app/Services/PilotEntitlements.php) | Existing plan/assignment fields and computed usage/state |
| [Outbox SQL](../src/database/sql/notification-outbox.sql), [onboarding additions](../src/database/sql/onboarding-notifications.sql) | Current notification fields/states and kind-specific references |
| [Onboarding SQL](../src/database/sql/onboarding.sql) | Existing public/capability/document metadata; content remains private |
| [Job schema](../src/database/migrations/0001_01_01_000002_create_jobs_table.php) | Failed-job/batch fields; not an authoritative Redis-live-backlog source |
| [CRM visual evidence](evidence/crm-repair-2026-10-25/README.md) | Current shared surface/control styling and mobile/error-state screenshots |
| [Portal visual guidance](portal-ui-alignment.md) | Existing brand/partner compositions and privacy/navigation boundaries |

**Important reference distinction:** earlier pipeline-only mockup proposed Plus Jakarta Sans and alternate palette/aggregate behavior. Those do not supersede the existing Manrope/Bricolage brand, stored stages or finance/permission rules. The administration reference should follow this brief and the current shared tokens.

This is a **design handoff**, not implementation, schema approval, data cleanup, launch acceptance or permission to send messages. The real bootstrap identity, operator roster, exact capability model, MFA/recovery policy, suspension effects, support approval rules, retention policy, commercial quotas and notification reconciliation mechanics remain engineering/policy decisions in their owning tasks.
