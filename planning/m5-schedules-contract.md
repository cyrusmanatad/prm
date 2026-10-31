# M5-03 Persistent schedules, timezones, preferences and consent

**M5-04 update (2026-10-31):** reminder and weekly review consumers/delivery are now implemented; see the [delivery contract](m5-scheduled-delivery-contract.md) and [evidence](evidence/m5/scheduled-delivery/README.md). The foundation-only descriptions below record M5-03’s original boundary.

Status: **implemented; backend/desktop verified; small-screen acceptance pending**, based on `258136f`, 2026-10-26. [Evidence](evidence/m5/schedules/README.md).

## Scope and defaults

**Confirmed D28:** continue M5 backend work and necessary desktop controls. Administration and small-screen testing/refinement belong to Claude. UI-05 acceptance and inherited release gates stay open. No external messages or production operations are authorized.

**Implementation defaults under D15/D25:** schedules belong to one verified editing member in one workspace. Other members, including workspace administrators, cannot read or change that person's preferences or schedules. The named rules are `reminder`, `weekly_digest` and `follow_up_draft`, currently rule version 1. This is a scheduling foundation, not a configurable workflow builder or external delivery feature. Reminder/digest notification consumption is M5-04; follow-up drafts, reply cancellation, approval and delivery remain M5-05–M5-08. No existing records are backfilled and no previously approved invitation/lifecycle behavior changes.

Optional notice preferences start off. Each kind must be explicitly enabled, and each schedule requires explicit consent. Reminder and follow-up schedules reference an existing workspace reminder or pipeline entry and snapshot its version. They require a future, explicit-offset UTC-resolvable instant. Weekly digest schedules use an ISO weekday (Monday=1), local HH:MM and the person's saved IANA timezone. Only one enabled weekly digest schedule per person/workspace is allowed. The desktop UI exposes preferences and the weekly schedule; subject-specific CRM/follow-up controls belong to their subsequent workflows. Only the actor's verified account may be a future optional notice recipient; no arbitrary recipient is stored here.

Changing preferences increments their version, cancels ready runs and refreshes enabled schedules' preference and schedule versions. Timezone changes recalculate future weekly times; explicit-offset one-shot instants stay fixed. Turning a kind off disables its schedules. Turning it back on does not resume them; saving a schedule with fresh explicit consent is required. A dedicated consent withdrawal action disables the schedule and cancels ready runs. Preference decisions and schedule grants/withdrawals append versioned consent events; audit logs retain identifiers only. Recovery/verification and explicitly requested invitation/outcome messages are independent of these optional preferences.

Existing pilot semantics remain: unassigned workspaces are development-only; the latest assignment must currently be active when scheduling/materializing/consuming. No new commercial feature keys or quotas are invented. Numerical automation budgets remain M5-08. An inactive assignment blocks new scheduled work while retaining existing records and allowing consent withdrawal. Lost role/verification, disabled preferences, stale subject versions and inactive entitlement prevent execution. Reconciliation disables obsolete schedules and cancels ready runs; restored access does not silently restore disabled consent.

## Time and durable processing

Weekly rules preserve local time across offset changes. A nonexistent spring-forward local time skips that week's occurrence. A repeated fall-back time uses the first UTC instant exactly once; reconciliation after that first instant does not choose the repeated copy. Tests include northern/southern DST and fractional offsets. After downtime, a weekly schedule creates one overdue run and advances directly to the next future weekly occurrence, coalescing missed weeks rather than generating a backlog burst. A one-shot schedule creates one run and clears its next due time.

`automation:dispatch` runs each minute with the existing scheduler overlap pattern. Narrow security-definer functions reconcile obsolete schedules and discover at most 100 due routing identifiers. Redis jobs contain actor/workspace/schedule IDs only. Due state remains in PostgreSQL until the worker atomically inserts a run and advances the schedule. A unique `(schedule_id, schedule_version, due_at)` key and row locks deduplicate replay; Redis loss before materialization leaves the occurrence discoverable. Ready runs remain in PostgreSQL after materialization, independent of Redis.

`BusinessSchedules::execute` is the subsequent consumer's transaction boundary: it takes current membership, pilot advisory, preference, schedule, subject and run locks, rechecks the current basis/version, and invokes a callback to append durable intent. Intent and run completion commit together or roll back together. The callback must never contact an external service. Concurrent preference/consent changes serialize against execution; later mail workers must recheck the current basis before reserving a send, as in the existing outbox. M5-03 deliberately has no run-to-email consumer and makes no external delivery claim.

## API and database

All endpoints require authenticated verified identity, a selected workspace and the existing tenant transaction:

- `GET /automation`: own preferences and latest 100 schedules; Inertia or JSON.
- `PUT /automation/preferences`: expected version (`0` for no stored preference), IANA timezone and explicit booleans `reminders`, `weekly_digest`, `follow_up_drafts`.
- `POST /automation/schedules`, `PATCH /automation/schedules/{id}`: name, named kind, accepted `consent`; update requires version. Weekly rules require weekday/local_time; one-shot rules require subject_id/due_at (`Y-m-dTH:i:sP`). Server owns actor, workspace, rule/preference/subject versions and timezone.
- `DELETE /automation/schedules/{id}`: expected version; withdraws consent while retaining history. Desktop action uses shared modal confirmation, as does saving preferences that withdraw previously enabled consent.

Migration `2026_10_26_000002` adds forced-RLS `automation_preferences`, `business_schedules`, `schedule_runs` and append-only `automation_consent_events`. Composite foreign keys bind actor/workspace/schedule identity. No runtime DELETE policy is granted. The internal predicate function has no runtime or public EXECUTE permission; scoped current-basis checks and global routing/reconciliation functions expose no content or recipients. Runtime credentials retain no table ownership/RLS bypass.

Rollback discards only these four new tables and their scheduling/consent history; it does not remove CRM reminders, pipeline records, onboarding data or notification outbox history. Rehearse only in a disposable synthetic database.

## Acceptance and remaining work

Required local evidence: PostgreSQL RLS/role/capability denial, current membership/verification/entitlement/consent and subject-version rechecks, stale preference/schedule writes, append-only consent, rollback atomicity, queue loss/replay, coalescing, timezone/DST and migration rollback/reapply. Desktop controls reuse FoundationShell, ContentSection, FormField, SelectField, ActionGroup, shared Button, validation focus and ConfirmationDialog; formatting/types/build/component checks plus desktop browser/keyboard/Axe/screenshots are required. Small-screen acceptance is delegated to Claude and remains open. Full restart/outage/provider delivery/rate-budget and follow-up races remain their M5-04–M5-10 gates, along with inherited release acceptance.
