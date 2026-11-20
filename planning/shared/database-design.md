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

**M3-02 follow-up (2026-11-20):** the [source inventory](../m3/privacy-evidence.md) and proposed [privacy contract](../m3/privacy-contract.md) describe existing copies, immutable/FK constraints, scoped export, closure barriers, durable file deletion and restore replay. PRIV-02 now adds forced-RLS `privacy_exports` and append-only runtime `privacy_export_events`, immutable request scope/lifetime, sanitized own-account security projection and narrowly scoped sharing-lock/expiry-cleanup functions. Runtime cannot remove receipts/events or finish cleanup directly. General retention/deletion request ledgers remain proposed. D40 approves the product timings and handler roles; jurisdiction-specific retained-class periods and processor/recovery verification remain open. D32 audit/finance protections remain in force.

Record actor, organization, event, subject, time, correlation ID, and redacted changes. Plan monthly audit partitions, including a default/future partition creation strategy. Define retention before collecting IPs, kit views, tax documents, or AI inputs. Export/deletion must traverse storage, jobs, integrations, and consent records; preserve only justified financial/audit retention and record exceptions.

Migration tests must prove RLS is enabled, policy coverage is complete, wrong-tenant writes fail, shared grants are narrow, and revoked memberships lose access. Include connection-pool reuse and background jobs. Schema-per-tenant or dedicated databases remain future options for contractual isolation/residency; avoid premature migration complexity.

## M2 implementation note

The first pipeline slice implements `pipeline_entries` and append-only `pipeline_activities` with forced RLS and a composite same-owner parent reference. The entry retains embedded primary contact fields; M2 also implements reusable `brand_contacts`, `pipeline_contacts`, dedicated `reminders` and `outreach_templates`. See the [contact/reminder contract](../m2/crm-contract.md). See the [implemented pipeline contract](../m2/pipeline-contract.md); the broader entity groups above remain proposals beyond delivered tables.

## M3 implementation note

M3 adds explicit-consent `media_kits` with manual social-account links, hashed/revocable `lead_links`, private inbound idempotency receipts, and separate onboarding requests, sessions, documents, immutable submissions and events. Capability RLS never extends to CRM tables. See the [M3 contract](../m3/onboarding-contract.md) for reviewed public functions, minimal collection and upload quarantine. Production provider/region and retention/deletion choices remain open.

## D21 relationship design follow-up

**Confirmed:** brand roster rows and creator portal partnerships represent one relationship; private creator CRM remains private. **Proposal for M3A:** explicit brand-organization/creator-organization relationships with invitation, acceptance, revocation, status and scoped shared projections. Support many brands per creator and many creators per brand; constrain duplicate active relationships and concurrent acceptance. Model creator-to-brand onboarding separately from brand-to-partner invitations. Do not auto-convert prospects or onboarding submissions into memberships/partnerships. Review current schema, migration/backfill and RLS before implementation, including multi-brand isolation and termination. See [portal UI alignment](../m3a/ui-alignment.md).


**Confirmed D23:** extend the existing schema for explicit brand–creator relationships and scoped sharing after migration/backfill and permission review. Preserve private records on relationship removal. D22 leaves current pipeline storage and backend stages unchanged. Review uniqueness, invitation acceptance concurrency and cross-brand RLS. See [M3A handoff](../m3a/implementation-handoff.md).

**M3A implementation:** the concrete routes, durable registration provisioning, creator backfill, owner/admin invitation/acceptance/revocation rules, forced RLS and shared-field allowlist are specified in the [implemented portal contract](../m3a/portal-contract.md). See [status and current evidence](../m3a/status.md). Prior proposal text above records the planning sequence.

## Brand lead visibility implementation note

Implemented by `brand-lead-visibility.sql` from migration `2026_11_18_000003_add_brand_lead_visibility` (D38): `lead_registrations`, `workspace_sign_ins` and `partner_shared_profiles`, all with forced row security, read-only policies and function-only writes. Tables, functions, triggers, lock order and rollback limits are in the [contract](brand-lead-visibility-contract.md#5-data-and-access).

## Brand approval implementation note

Implemented by `brand-approval.sql` from migration `2026_11_18_000002_add_brand_approval` (D37). See the [Brand approval contract](../administration/brand-approval-contract.md). All four tables have RLS enabled and forced; the only runtime policy lets an applicant read their own `brand_requests` rows, and every write goes through a security-definer function owned by the migration role.

| Table | Purpose | Key columns and states |
|---|---|---|
| `brand_requests` | One request, or one rollout approval of a workspace that predates review | `source` (`request`, `rollout`), `applicant_id` (null for rollout), four business fields (null for rollout), `status` (`pending`, `needs_information`, `approved`, `declined`), `version`, `org_id` (set exactly when approved, unique) |
| `brand_request_events` | Append-only submissions and decisions | `version`, `event`, `actor_id`, `operator` (rollout only), `reason`, `submission` snapshot. A decision event's `id` is its notification identity |
| `account_notification_outbox` | Account-scoped outcome email intent | `user_id`, `kind`, `event_id` (unique), `request_version`, the same states and deferral fields as `notification_outbox`. No recipient, link or body |
| `account_notification_resolutions` | Operator resolution of an uncertain account notice | Same shape as `notification_resolutions` |

Integrity rules: one unresolved request per applicant (partial unique index); a decided request cannot change and no request can be deleted; events cannot be updated or deleted; a deferred constraint trigger on `organizations` refuses, at commit and for every role, a new Brand workspace without an approved `brand_requests` row; `app_create_typed_workspace` refuses new Brand workspaces. A notice is current only while the request's `version` equals its `request_version`. Account notices use the shared `global` delivery window and an `account:<id>` window. `platform_grants.permissions` now allows eleven capabilities, adding `brands.review`. The migration replaces seven existing functions and its rollback restores them from their original files; rollback refuses to run once any `brand_requests` row exists.

## Administration implementation note

Implemented by `platform-activation.sql`, `delivery-controls.sql`, `platform-console.sql` and `platform-governance.sql` under `src/database/sql/`; the last two run from migration `2026_11_16_000001_add_platform_console`. See the [administration contract](../administration/contract.md) and [console evidence](../administration/console-evidence.md). Every table below has RLS enabled and forced with **no runtime policies**; the runtime role reaches them only through capability-gated SECURITY DEFINER functions. The single exception is `platform_grants`, where an actor may read their own row.

| Table | Purpose | Key columns and states |
|---|---|---|
| `platform_grants` | Platform authority for one identity | `user_id` (primary key), `permissions text[]` limited to the ten capabilities, `version`, `revoked_at` |
| `platform_audit` | Append-only administration audit | `actor_id` (null for offline actions), `operator` label, `event`, `target`, required `reason`, `occurred_at` |
| `notification_resolutions` | Operator's closure of an uncertain send | `notification_id` (primary key), `resolution` in `transport_accepted`, `not_sent`, `unknown_closed`; one per notification, never changed |
| `platform_observations` | Latest scheduler heartbeat | `source` (only `scheduler`), `observed_at` |
| `platform_suspensions` | Suspension history | `subject` in `account`, `workspace` with exactly one of `user_id` / `org_id`; `reason`, `suspended_by`, `suspended_at`; `lifted_by`, `lifted_at`, `lift_reason`. Active while `lifted_at` is null; rows are never deleted |
| `support_grants` | Owner-approved support access | `org_id`, `operator_id`, `purpose`, `resources text[]` (six areas), `hours` 1–24, `state` in `requested`, `approved`, `declined`, `revoked`, `cancelled`; `decided_by`, `decided_at`, `expires_at`, `ended_by`, `ended_at`, `end_reason`, `version` |
| `fixture_batches` | Named cleanup review batch | unique `name`, `reason`, `created_by`, `version` |
| `fixture_marks` | One explicitly marked account or workspace | `batch_id`, `subject`, one of `user_id` / `org_id`, required `evidence`, `marked_by` |
| `cleanup_runs` | Immutable dry-run manifest | `batch_id`, `batch_version`, `manifest jsonb`, `eligible`, `blocked`, `protected` counts |

Operator authenticator state is three columns on `users`: `admin_totp_secret` (encrypted), `admin_totp_confirmed_at` and `admin_totp_counter` (last accepted time step, so a code is single use).

**Capabilities:** `platform.owner`, `directory.read`, `plans.assign`, `notifications.read`, `notifications.reconcile`, `operations.read`, `audit.read`, `access.manage`, `support.request`, `cleanup.review`. `platform.owner` implies the other nine. A check constraint on `platform_grants.permissions` rejects any other value.

**Integrity rules:**

- At most one active suspension per account and per workspace (partial unique indexes).
- At most one open (`requested` or `approved`) support grant per operator and workspace.
- An approved support grant expires at most 24 hours after the decision; approval uses one instant for both timestamps.
- `expired` is not a stored support state. It is derived at read time from `expires_at`, so access ends on time without a job.
- Cleanup runs are never updated. A run is shown as stale once its `batch_version` differs from the batch's current version.
- A marked record belongs to a batch at most once; marking is by explicit ID with evidence, never by pattern.

**Version checks (stale writes fail with SQLSTATE `40001`):** grant `version`, raised on every change and bound into the step-up proof; suspension version, computed as the number of suspend events plus lift events for the subject; support grant `version`; batch `version`, raised on each mark or unmark. Plan assignments and notification resolutions keep their earlier version checks.

**Replaced core functions:** `app_has_membership`, `app_lock_membership`, `app_platform_allowed` and `app_lock_platform_grant` now also require that the workspace and the acting account are not suspended. `platform-governance-down.sql` restores their previous definitions. The migration's rollback refuses to run while any suspension is active or any support grant is approved, because removing them would silently change who has access. Rollback keeps `platform_audit` rows and removes the five newer capabilities from existing grants.

**Follow-up (migration `2026_11_17_000001_add_platform_followup`, `platform-followup.sql`):** `schedule_runs.state` gains `skipped`, used only for work that fell due while its workspace or actor was suspended. `app_skip_suspended_work()` and `app_skip_due_schedule()` record such runs and write `automation.run.skipped` to the workspace's `audit_logs`; the second acts only on a schedule that is both due and suspended. `app_partnership_paused()` tells a participant of an active partnership whether the other party's workspace is suspended, and nothing else. The directory functions accept a created-date range and the Overview adds two figures. Rollback converts `skipped` runs to `cancelled` (neither is replayed) and restores the earlier function definitions.

**Audit:** `platform_audit` is append-only, with no runtime read or write policy; rows are written only by the reviewed functions and the offline command, and read only through the `audit.read` projections. Support events that concern a workspace (approval, decline, end, each record view) are also written to that workspace's `audit_logs`. There is no audit export and no browsing of tenant `audit_logs` from administration. Retention of `platform_audit` is **Assumption / Open** with M3-02.

## M5-03 implemented scheduling foundation

The [schedule/consent contract](../m5/schedules-contract.md) defines forced-RLS `automation_preferences`, `business_schedules`, `schedule_runs` and append-only `automation_consent_events`. Preferences and schedules belong to a single person in a workspace. Composite routing constraints, versioned consent/rules/subjects and unique occurrence keys preserve isolation and replay safety. PostgreSQL retains due and ready work independently of Redis; the scheduling worker and downstream-intent boundary recheck current grants, verification, consent/preferences, subject version and pilot assignment. M5-04 adds unique `notification_outbox.schedule_run_id` with a composite actor/workspace/run foreign key, private actor-only scheduled-mail policies, routing-only discovery and current-basis predicates; see the [delivery contract](../m5/scheduled-delivery-contract.md). Migration rollback preserves schedules, runs and previous notification kinds; the initial entity proposals above do not imply other automation features are implemented.

## Mail-budget implementation note (2026-11-18)

**Confirmed targets; locally verified, inactive by default:** singleton `mail_budget_policy` and immutable runtime function-only `mail_budget_attempts` enforce 250 total / 200 business / 50 reserved attempts over rolling 24 hours. Both tables force RLS; runtime has no direct table access. Security-definer reserve/consume/snapshot functions serialize reservations; a one-use receipt expires after ten minutes, but unused/failed/uncertain consumption is never refunded. Consumed receipts stay charged for 24 hours from transport admission. Account outbox reservation and charge commit together; business/direct mail use independent committed accounting. Policy changes require the migration role and append platform audit events. A down migration refuses active/consumed ledgers. See [contract](../m5/sending-budget-proposal.md) and [runtime isolation/concurrency evidence](../m6/readiness-evidence.md).
