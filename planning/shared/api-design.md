# Web and API Design

## Contract and boundaries

The approved Laravel + Vue/Inertia application uses Laravel web routes and controllers for page navigation and form actions. Inertia page props carry explicit authorized data; normal web mutations use redirect/session validation flows. Use shared domain services so later API endpoints do not duplicate business logic.

Reserve REST JSON under `/api/v1` with an OpenAPI contract for external integrations and selected JSON operations such as upload intents. Protected organization paths use `/orgs/{orgId}`; validate membership, policy, entitlement, and row ownership. Never trust an `org_id` supplied inside a body. Public capabilities and webhooks have separate routes and authorization.

The table below defines logical route families. Implement web/Inertia routes first where needed; introduce an equivalent `/api/v1` resource only for a concrete consumer. Webhook routes remain separately signed. A complete parallel REST interface is not an MVP prerequisite.

| Endpoint family | Purpose / required capability |
|---|---|
| `GET /me`, `GET /me/organizations` | Current user and active memberships only |
| `POST /orgs`, `GET /orgs/{orgId}` | Create organization; read authorized workspace |
| `/orgs/{orgId}/memberships`, `/invitations` | Membership management by owner/admin; owner transfer separately protected |
| `/orgs/{orgId}/pipeline-entries` | List/filter/create creator-private leads |
| `/orgs/{orgId}/pipeline-entries/{id}` | Read/update lead with optimistic version check |
| `POST .../{id}/stage-transitions` | Validated, audited stage command; emits durable domain event |
| `POST .../{id}/onboarding-requests` | Issue scoped invitation, expiry, and requested fields |
| `/orgs/{orgId}/activities`, `/reminders`, `/outreach-templates` | Private timeline, next actions, and merge templates |
| `/orgs/{orgId}/media-kit`, `/share-links` | Edit/publish/revoke explicitly public profile data |
| `GET /public/media-kits/{slug}` | Published safe projection only |
| `POST /public/lead-links/{token}/submissions` | Rate-limited inbound capture; no private record reads |
| `POST /public/onboarding/{token}/sessions` | Exchange scoped token for short-lived capability session |
| `GET/PATCH .../session`, `POST .../submit` | Safe form fields, draft resume, immutable submission version |
| `/orgs/{orgId}/documents/upload-intents` and `.../{id}/download-url` | Authorized size/type-limited upload; malware quarantine; authorized download |
| `/orgs/{orgId}/invoices`, `/payment-records`, `/payment-allocations` | Invoice drafts and manual reconciliation with finance permission |
| `/orgs/{orgId}/approval-tasks/{id}/approve` or `/reject` | Approve exact version of an action; stale drafts require reapproval |
| `/orgs/{orgId}/automation-rules`, `/notifications` | Named templates, preferences, read/dismiss |
| `/orgs/{orgId}/consents`, `/exports`, `/deletion-requests` | Purpose-specific consent and asynchronous privacy actions |
| `/orgs/{orgId}/subscription`, `/usage` | Read packaging; owner-controlled subscription operations later |
| `POST /webhooks/{provider}/{connectionId}` | Later signed provider delivery; tenant resolved from trusted connection mapping |

Endpoint suffixes beginning `/` in a family share the listed organization prefix. Document Inertia page props and Form Request inputs alongside each web feature; document actual JSON endpoints in OpenAPI during the relevant milestone. Do not generate generic CRUD routes automatically.

## Common behavior

- Use cursor pagination, bounded page sizes, allowlisted sorts/filters, ISO-8601 timestamps, UUIDs, and money as `{amountMinor: "4500000", currency: "PHP"}`.
- Mutating financial commands, public submissions, and externally consequential operations accept `Idempotency-Key`. Scope by actor/capability, tenant, and operation; persist payload hash/result; reject key reuse with a different payload. Choose retention at implementation based on retry windows.
- Use version/ETag checks for concurrent lead edits and approval actions. A changed draft invalidates previous approval.
- JSON endpoints return stable structured errors with code, safe message, field errors, and correlation ID. Use 401 unauthenticated, 403 insufficient role/entitlement, 404 inaccessible resource, 409 conflict, 422 validation, and 429 rate limit. Avoid existence leaks through conflict details. Inertia forms follow Laravel redirect/error-bag conventions, with explicit conflict handling for stale versions rather than assuming all form failures return JSON 422.
- Public onboarding tokens are high-entropy, hashed, expiring, revocable, and request-scoped. Use no-referrer policy, redact URLs from logs, and avoid third-party scripts on sensitive forms. Resume links require the same capability or verified email; never use predictable IDs.
- Upload intent does not make a file trusted: enforce checksum/type/size, scan, restrict content disposition, and expose only after scan completion. Limit public upload quotas.
- Webhooks verify signatures over raw bytes, enforce replay controls, persist receipt before acknowledgment, and process asynchronously. Billing/payment status comes from verified events/reconciliation, never browser redirects. Outbound webhooks later use signatures, delivery IDs, retry limits, and endpoint validation against SSRF.

## Laravel web security

Use Laravel session authentication and CSRF protection for Inertia routes. Policies and Form Requests enforce organization permissions before model serialization or mutation. Shared and deferred Inertia props, partial reloads, and relation loading must remain inside an authorized tenant context. Clear client state on workspace switch/logout; never share tenant data through generic global props. Public capability forms have scoped sessions and CSRF protection where applicable; external webhooks use signature verification rather than browser CSRF tokens. Sanctum is an optional later tool for actual token/API consumers, not a requirement for Inertia login.

## Future resource APIs

Campaigns/deliverables; tracking links/codes/orders/attributions; commission approval/settlement; payout attempts; PRM claims, tiers and incentives; AI insights/feedback. Public API keys must be tenant-bound, hashed, scoped, expiring/revocable, and auditable. Do not expose these before the relevant business module is approved.

The first M2 slice implements session-protected `/pipeline` web actions and selected JSON compatibility. Its exact inputs, projections, paging, role defaults, and conflict behavior are documented in the [M2 pipeline contract](../m2/pipeline-contract.md). The proposed `/api/v1` resource families above are not parallel implemented APIs.

Subsequent M2 contracts cover [contacts/reminders](../m2/crm-contract.md) and [manual templates](../m2/outreach-template-contract.md). [M3](../m3/onboarding-contract.md) documents the actual public/capability and creator routes, safe projections, idempotency, session exchange and attachment authorization. These remain session-protected web/JSON contracts, not a published integration API.

## D21 portal routes and relationship contracts

**Confirmed:** separate brand/partner login pages and dashboards; the chosen entry point never grants access. **Proposal for M3A:** `/brand/login`, `/partner/login`, authorized portal landing/context-switch routes, brand-to-partner invitation/acceptance/revocation commands and minimal shared roster projections. Specify exact routes and legacy `/login` redirects during contract review. Keep existing scoped onboarding endpoints distinct; never expose private pipeline endpoints through partnership access. See [portal UI alignment](../m3a/ui-alignment.md).


**Confirmed D23:** add role-specific registration/workspace creation and partner acceptance of brand invitations to M3A contracts. Existing identities can hold both contexts; removing a partnership preserves private records. Exact routes/legacy redirects remain implementation contract choices. See [M3A handoff](../m3a/implementation-handoff.md).

**M3A implementation:** the concrete routes, durable registration provisioning, creator backfill, owner/admin invitation/acceptance/revocation rules, forced RLS and shared-field allowlist are specified in the [implemented portal contract](../m3a/portal-contract.md). See [status and current evidence](../m3a/status.md). Prior proposal text above records the planning sequence.

### Partner page URLs (D24)

Partner HTML/Inertia pages now use `/partner/leads`, `/partner/contacts`, `/partner/outreach-templates`, `/partner/onboarding` and `/partner/media-kit`. The old `/partner`, `/pipeline`, `/crm`, `/outreach-templates`, `/onboarding-requests` and `/public-presence` page URLs redirect with their query strings. Existing JSON clients and mutation/document endpoints remain compatible; the URL consolidation does not change authorization or storage. Inbox/Campaigns/Earnings/Autopilot URLs are reserved and not implemented. See the [partner route contract](../m3a/portal-contract.md#partner-navigation-and-lead-consolidation-follow-up).

## Administration routes

**Implemented** session/CSRF-protected web routes in `src/routes/web.php`; not part of the proposed `/api/v1` families above. All `/admin` routes require a signed-in, email-verified identity with an unrevoked platform grant. Every route except `/admin/security*` also requires the 15-minute authenticator step-up. "Any" means any usable grant. See the [architecture](architecture.md#administration) for the trust chain and the [administration contract](../administration/contract.md) for status.

| Read route | Capability | Returns |
|---|---|---|
| `GET /admin` | Any; JSON needs `directory.read` | Overview page. JSON clients receive the original directory payload (`q`, `page`) |
| `GET /admin/security` | Grant only (no step-up) | Authenticator enrollment or challenge |
| `GET /admin/accounts`, `/admin/accounts/{account}` | `directory.read` | Account list and detail; the access-impact review is included only with `access.manage`. The list accepts `from` and `to` (joined date, `YYYY-MM-DD`, UTC days, `to` inclusive) |
| `GET /admin/workspaces`, `/admin/workspaces/{workspace}` | `directory.read` | Workspace list and detail (members, entitlement, usage and operational counts). The list accepts `from` and `to` (created date, same format) |
| `GET /admin/plans`, `/admin/plans/{plan}` | `plans.assign` | Plan versions and one version with its assignments |
| `GET /admin/assignments` | `plans.assign` | Assignments with derived state and history |
| `GET /admin/assignments/create?workspace=` | `plans.assign` and `directory.read` | Assignment form for one workspace |
| `GET /admin/notifications`, `/admin/notifications/{notification}` | `notifications.read` | Delivery metadata; never the payload |
| `GET /admin/operations` | `operations.read` | Scheduler heartbeat, queue depth, outbox backlog, sanitized failed jobs |
| `GET /admin/audit`, `/admin/audit/{source}/{event}` | `audit.read` | Administration audit; `source` is `platform` or `local-pilot` |
| `GET /admin/operators` | `platform.owner` | Operator grants |
| `GET /admin/support-access`, `/admin/support-access/{grant}` | `support.request` | Support requests; `?area=` reads records only for the operator's own approved grant |
| `GET /admin/cleanup`, `/admin/cleanup/{batch}`, `/admin/cleanup/runs/{run}` | `cleanup.review` | Batches, marks and dry-run manifests |

| Write route | Capability | Notes |
|---|---|---|
| `POST /admin/security/enroll` | Grant only | Body `password`; issues a setup key |
| `POST /admin/security/verify` | Grant only | Body `password`, `code`; records the step-up proof |
| `POST /admin/assignments` | `plans.assign` | Unchanged M4/PRO-02 contract; `expected_version` |
| `POST /admin/notifications/{notification}/resolve` | `notifications.reconcile` | `version`, `resolution`, `reason`; never resends |
| `POST /admin/operators` | `platform.owner` | Grant, change or revoke another operator; fresh proof |
| `POST /admin/accounts/{account}/suspend`, `/reactivate` | `access.manage` | `expected_version`, `reason`; fresh proof |
| `POST /admin/workspaces/{workspace}/suspend`, `/reactivate` | `access.manage` | `expected_version`, `reason`; fresh proof |
| `POST /admin/accounts/{account}/sign-out` | `access.manage` | `reason`; fresh proof |
| `POST /admin/accounts/{account}/reset-authenticator` | `access.manage` | `reason`; fresh proof. Refuses the caller's own account, accounts with an active platform grant and accounts with no authenticator (M1-04) |
| `POST /admin/support-access` | `support.request` | `workspace`, `purpose`, `resources`, `hours` (1–24) |
| `POST /admin/support-access/{grant}/end` | `support.request` (own grant) or `platform.owner` | `expected_version`, `reason` |
| `POST /admin/cleanup` | `cleanup.review` | Create a batch: `name`, `reason` |
| `POST /admin/cleanup/{batch}/marks` | `cleanup.review` | Mark or unmark one account or workspace by ID, with `evidence` |
| `POST /admin/cleanup/{batch}/runs` | `cleanup.review` | Record a dry run; deletes nothing |

| Brand review route (D37) | Capability | Notes |
|---|---|---|
| `GET /admin/brand-requests` | `brands.review` | Filters `q` (Brand, applicant name or email, request ID), `status`, `source` (`request`, `rollout`). Pending requests first, longest wait first |
| `GET /admin/brand-requests/{request}` | `brands.review` | Applicant identity with current verification and suspension state, business information, history with feedback, resulting workspace |
| `POST /admin/brand-requests/{request}/decision` | `brands.review` | `expected_version`, `decision` in `approve`, `request_information`, `decline`, and `feedback` (1–1,000 characters, shown to the applicant). Step-up only; no fresh proof. Approval creates the workspace with the applicant as owner. 409 unless the request is pending at that version; 422 for an unverified or suspended applicant or the reviewer's own request |

| Applicant route (D37) | Requirement | Notes |
|---|---|---|
| `GET /brand/requests` | Signed-in, verified account | The account's own requests, `open` and `registering`; also the landing page after Brand registration |
| `POST /brand/requests` | Signed-in, verified account | `brand_name`, `website` (HTTP or HTTPS, never fetched by the server), `applicant_role`, `intended_use`. 201 with `id`; 409 while another request is open |
| `GET /brand/requests/{request}` | The applicant | Status, submitted information, feedback and history without reviewer identities. 404 for any other account |
| `PATCH /brand/requests/{request}` | The applicant | The same four fields and `expected_version`. Only while the request needs information; otherwise 409 |

`POST /workspaces` no longer accepts `kind=brand` (422). `GET /admin/workspaces/{workspace}` adds `approval` for Brand workspaces (status, source, reviewer, operator, time, reason, request) and `canReviewBrands`. Notification diagnostics also list account notices: those rows have `org_id` and `workspace` null and name the `account`. See the [Brand approval contract](../administration/brand-approval-contract.md).

| Workspace-owner route | Requirement | Notes |
|---|---|---|
| `GET /workspace/support-access` | Active owner of the selected workspace | Support requests for that workspace |
| `POST /workspace/support-access/{grant}` | Active owner of the selected workspace | `expected_version`, `decision` in `approve`, `decline`, `end` |

Common behaviour:

- **Response form.** A request with `Accept: application/json` and no `X-Inertia` header receives JSON; otherwise reads render Inertia pages and writes redirect with a status message. Creating a support request, batch or dry run returns 201 to JSON clients.
- **403** without a grant, without step-up or without the capability. A browser page visit (GET) without a valid step-up is instead redirected to `/admin/security` and returned to the requested page after verification. Denied requests are recorded in the administration audit.
- **404** for unknown records. **409** with an `expected_version` error for a stale version (the notification resolution reports it on `version`). **422** for validation and rule violations, such as targeting your own grant or the last usable platform owner. **429** from per-route throttles.
- **Fresh proof.** "Fresh proof" above means `password` and a new six-digit `code` in the request body, in addition to the step-up. Codes are single use.
- **Paging.** Lists use `page` with 25 rows and a server `total`; filters are allowlisted. This differs from the cursor pagination proposed for tenant resources.
- **Partner portals.** Each row of `relationships` on `GET /brand` and `GET /partner` carries `paused`: true when the other party's workspace is suspended. No reason or other suspension detail is returned.
- No route changes a membership or role, deletes an account or workspace, executes a cleanup or exports audit data. The `/orgs/{orgId}/memberships` row above remains a tenant owner/admin responsibility.

## M5-03 implemented automation settings

Authenticated verified members use selected-workspace `GET /automation`, `PUT /automation/preferences`, `POST /automation/schedules`, and versioned `PATCH`/`DELETE /automation/schedules/{id}`. Editing roles may manage only their own settings and schedules. DELETE withdraws consent and retains history. The [implemented contract](../m5/schedules-contract.md#api-and-database) specifies named rules, explicit opt-in, timezone and date formats, server-owned versions and stale-write behavior. These are session/CSRF-protected web/JSON routes; `GET /automation` now also projects the first 100 open reminder choices and the actor’s latest 50 scheduled-email history items (ID/kind/state/created time only). M5-04 consumes reminder/weekly review runs server-side; existing schedule endpoints now queue email when due. See the [delivery contract](../m5/scheduled-delivery-contract.md).
