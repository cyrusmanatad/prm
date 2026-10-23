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

The first M2 slice implements session-protected `/pipeline` web actions and selected JSON compatibility. Its exact inputs, projections, paging, role defaults, and conflict behavior are documented in the [M2 pipeline contract](m2-pipeline-contract.md). The proposed `/api/v1` resource families above are not parallel implemented APIs.

Subsequent M2 contracts cover [contacts/reminders](m2-crm-contract.md) and [manual templates](m2-outreach-template-contract.md). [M3](m3-onboarding-contract.md) documents the actual public/capability and creator routes, safe projections, idempotency, session exchange and attachment authorization. These remain session-protected web/JSON contracts, not a published integration API.

## D21 portal routes and relationship contracts

**Confirmed:** separate brand/partner login pages and dashboards; the chosen entry point never grants access. **Proposal for M3A:** `/brand/login`, `/partner/login`, authorized portal landing/context-switch routes, brand-to-partner invitation/acceptance/revocation commands and minimal shared roster projections. Specify exact routes and legacy `/login` redirects during contract review. Keep existing scoped onboarding endpoints distinct; never expose private pipeline endpoints through partnership access. See [portal UI alignment](portal-ui-alignment.md).


**Confirmed D23:** add role-specific registration/workspace creation and partner acceptance of brand invitations to M3A contracts. Existing identities can hold both contexts; removing a partnership preserves private records. Exact routes/legacy redirects remain implementation contract choices. See [M3A handoff](m3a-implementation-handoff.md).

**M3A implementation:** the concrete routes, durable registration provisioning, creator backfill, owner/admin invitation/acceptance/revocation rules, forced RLS and shared-field allowlist are specified in the [implemented portal contract](m3a-portal-contract.md). See [status and current evidence](m3a-status.md). Prior proposal text above records the planning sequence.

### Partner page URLs (D24)

Partner HTML/Inertia pages now use `/partner/leads`, `/partner/contacts`, `/partner/outreach-templates`, `/partner/onboarding` and `/partner/media-kit`. The old `/partner`, `/pipeline`, `/crm`, `/outreach-templates`, `/onboarding-requests` and `/public-presence` page URLs redirect with their query strings. Existing JSON clients and mutation/document endpoints remain compatible; the URL consolidation does not change authorization or storage. Inbox/Campaigns/Earnings/Autopilot URLs are reserved and not implemented. See the [partner route contract](m3a-portal-contract.md#partner-navigation-and-lead-consolidation-follow-up).
