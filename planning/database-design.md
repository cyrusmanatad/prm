# Initial Database Design

## Ownership and tenant isolation

**PDF §5; proposal elaboration:** each creator business, brand, agency, or enterprise is an organization. A user may hold memberships in several organizations. Do not use a single user-level role or a globally trusted tenant header. Resolve the active organization through an authenticated, active membership on each request.

Use one database and shared schema initially. Every private business row has a non-null owning `org_id` (or an explicitly named owner column), and RLS policies cover SELECT, INSERT, UPDATE, and DELETE, including write checks. Enable and FORCE RLS for tenant tables. Laravel web/queue runtime roles must be non-owner, non-superuser, and lack BYPASSRLS; migrations use separate credentials. Owners and privileged roles can bypass RLS unless configured appropriately ([PostgreSQL documentation](https://www.postgresql.org/docs/current/ddl-rowsecurity.html)).

Set actor and organization context transaction-locally on the same connection used by every repository operation. Never use session-persistent tenant settings with pooled connections. Missing or invalid context denies access. The backend is a trusted boundary: a database setting alone does not authenticate membership. Workers establish verified tenant context and recheck grants. Cache keys, files, exports, searches, and queue jobs also carry organization scope.

Laravel policies and Eloquent tenant scopes supplement database isolation. Use Laravel migrations plus reviewed PostgreSQL-specific policy definitions; Laravel's default model conventions do not enable RLS. Start the tenant transaction before scoped route-model binding and keep it active through authorized page-prop/resource serialization, including deferred Inertia requests. Avoid tenant queries in middleware or lazy callbacks outside that boundary. Queue jobs pass scalar identifiers and set context before querying; model rehydration must not precede tenant authorization. Reset long-lived worker state after every job. Native authentication/session tables need narrowly scoped identity access before a tenant is selected; this must not grant access to business rows.

Use composite unique keys `(org_id, id)` and composite foreign keys for same-owner child relations to prevent cross-tenant parent assignment. Index common filters with tenant first. Explicit sharing needs a separate policy, not a weakened blanket `OR` across all tables.

## Private and shared records

- Creator-owned `pipeline_entries`, contacts, notes, estimated rates, and activities are never exposed to a linked brand.
- A private prospect need not have a registered brand organization. Keep its local name/contact data; later link through a verified invitation/claim process. Do not auto-merge organizations based on names, TIN, or email domains.
- Shared `partnerships` and `collaborations` identify creator and brand organizations. `collaboration_participants` grants explicit resource access and permitted actions. Membership in a participant organization is necessary but not sufficient for financial operations.
- Onboarding request/submission versions and shared documents are separate from private pipeline notes. Submission does not automatically publish a brand profile or grant tenant membership.
- A public directory contains only approved public fields with publication consent. Internal billing/contact/tax profiles are never directory records.
- Agencies require explicit delegations and revocation, not unrestricted visibility into client organizations.

## Entity groups and key fields

| Group | Initial tables and principal relationships |
|---|---|
| Identity | `users` with Laravel-compatible credentials/verification, `organizations(type, country, currency)`, `memberships(org_id, user_id, role, status)`, invitations, sessions, password_reset_tokens; optional later `auth_identities(provider, subject)` |
| Packaging | plans, plan_versions, `subscriptions(org_id, status, period_end)`, entitlements, usage_counters, billing_events |
| Creator CRM | creator_profiles, social_accounts, private brand_contacts, `pipeline_entries(org_id, brand_org_id nullable, stage, value_minor, currency, win_probability, next_action_due, version)`, pipeline_contacts, activities, outreach_templates, reminders |
| Public presence | media_kits, revocable share_links, aggregated view_events, inbound_leads |
| Onboarding | onboarding_requests, hashed invitation_tokens, versioned onboarding_submissions, collaboration_participants, documents (disk, bucket/key, checksum, scan state), document_grants |
| MVP money records | invoices, invoice_lines, invoice_sequences, payment_records, payment_allocations; distinguish these from platform subscription invoices |
| Automation/privacy | automation_rules, scheduled_actions, workflow_runs, approval_tasks, notifications, outbox_events, processed_events, consents, audit_logs, export_requests, deletion_requests |
| Later campaigns | partnerships, collaborations, campaigns, campaign_partners, deliverables, approvals, contracts, immutable contract_versions, contract_signatures |
| Later attribution | integration_connections/store identities, webhook_receipts, tracking_links, discount_codes, orders, order_lines, refunds, attributions, commission_rule_versions |
| Later financial ledger | commission_entries, commission_events, payout_methods, payouts, payout_attempts, payout_items, tax_certificates |
| Later PRM/AI | program_tiers, incentives, lead_registrations/claims, enablement_assets/events, ai_insights, ai_feedback |

Proposed relationships: organization → many memberships/pipeline entries; pipeline entry → many activities and onboarding requests; request → many submission versions; invoice → many lines and allocations. Shared collaboration → participants and explicit document grants. Add full campaigns/ledger tables only in their implementation phase.

## Constraints and lifecycle

- Use UUID identifiers, UTC `timestamptz`, stored IANA timezone for schedules, and ISO currency codes. Initial locale/currency proposals are Philippines/PHP, not a single-currency schema.
- Money uses signed 64-bit minor-unit integers; API represents these as decimal strings to avoid JavaScript integer precision loss. Define rounding per currency. Win probability is constrained to 0–1. Never sum unlike currencies.
- Membership unique `(org_id, user_id)`; later provider identity unique `(provider, subject)`; public tokens stored hashed with expiry/revocation and narrow scope.
- Invoice number unique within issuer organization and approved series/year convention. Allocate atomically; issued invoice snapshots and corrections are immutable rather than silent edits. Tax wording/numbering needs accountant review before real issuance.
- Payment records are manually reconciled evidence, not proof that the platform transferred funds. Partial payments allocate across invoices; void/refund corrections preserve history.
- Later order key: `(brand_org_id, integration_connection_id, external_order_id)`. Deduplicate delivery IDs separately; handle updated orders and out-of-order/refund events, not just duplicate inserts.
- Later commission earnings, adjustments, reversals, and settlements append new entries/events referencing original entries and rule versions. Lock reservation/settlement to prevent duplicate payment. Separate payout attempts from a unique commission settlement allocation so failed attempts can retry safely.
- Shared order details expose only the creator's relevant attribution/commission projection, not unrelated customer/order data. Payout account details are not shared with ordinary collaborators.
- Documents store Laravel filesystem disk, object key, metadata/checksum, and scan state; private AWS S3 or OVH objects use short-lived authorized URLs. Object-key prefixes alone do not enforce tenant isolation. Test the chosen provider for signing, lifecycle, and upload behavior before selection. Encrypt TIN/payment references with managed key rotation; keep keys separate from database backups.
- Tenant-leading indexes: pipeline `(org_id, stage, next_action_due)`, activity `(org_id, pipeline_entry_id, occurred_at)`, pending jobs `(status, due_at)`, and invoice `(org_id, status, due_at)`.

## Audit, retention, and migration

Record actor, organization, event, subject, time, correlation ID, and redacted changes. Plan monthly audit partitions, including a default/future partition creation strategy. Define retention before collecting IPs, kit views, tax documents, or AI inputs. Export/deletion must traverse storage, jobs, integrations, and consent records; preserve only justified financial/audit retention and record exceptions.

Migration tests must prove RLS is enabled, policy coverage is complete, wrong-tenant writes fail, shared grants are narrow, and revoked memberships lose access. Include connection-pool reuse and background jobs. Schema-per-tenant or dedicated databases remain future options for contractual isolation/residency; avoid premature migration complexity.

## M2 implementation note

The first pipeline slice implements `pipeline_entries` and append-only `pipeline_activities` with forced RLS and a composite same-owner parent reference. The entry retains embedded primary contact fields; M2 also implements reusable `brand_contacts`, `pipeline_contacts`, dedicated `reminders` and `outreach_templates`. See the [contact/reminder contract](m2-crm-contract.md). See the [implemented pipeline contract](m2-pipeline-contract.md); the broader entity groups above remain proposals beyond delivered tables.

## M3 implementation note

M3 adds explicit-consent `media_kits` with manual social-account links, hashed/revocable `lead_links`, private inbound idempotency receipts, and separate onboarding requests, sessions, documents, immutable submissions and events. Capability RLS never extends to CRM tables. See the [M3 contract](m3-onboarding-contract.md) for reviewed public functions, minimal collection and upload quarantine. Production provider/region and retention/deletion choices remain open.

## D21 relationship design follow-up

**Confirmed:** brand roster rows and creator portal partnerships represent one relationship; private creator CRM remains private. **Proposal for M3A:** explicit brand-organization/creator-organization relationships with invitation, acceptance, revocation, status and scoped shared projections. Support many brands per creator and many creators per brand; constrain duplicate active relationships and concurrent acceptance. Model creator-to-brand onboarding separately from brand-to-partner invitations. Do not auto-convert prospects or onboarding submissions into memberships/partnerships. Review current schema, migration/backfill and RLS before implementation, including multi-brand isolation and termination. See [portal UI alignment](portal-ui-alignment.md).


**Confirmed D23:** extend the existing schema for explicit brand–creator relationships and scoped sharing after migration/backfill and permission review. Preserve private records on relationship removal. D22 leaves current pipeline storage and backend stages unchanged. Review uniqueness, invitation acceptance concurrency and cross-brand RLS. See [M3A handoff](m3a-implementation-handoff.md).

**M3A implementation:** the concrete routes, durable registration provisioning, creator backfill, owner/admin invitation/acceptance/revocation rules, forced RLS and shared-field allowlist are specified in the [implemented portal contract](m3a-portal-contract.md). See [status and current evidence](m3a-status.md). Prior proposal text above records the planning sequence.
