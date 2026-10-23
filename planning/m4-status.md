# M4 Financial Records and Entitlements Status

Current commercial/operator decisions, inherited reviews and deferred finance expansion remain indexed in [remaining tasks](remaining-tasks.md). M5 has since begun; use the [current handoff](implementation-handoff.md) for the latest checkpoint. The evidence and sequencing statements below retain their original scope.

Status: **development slices complete; automated verification passed; release acceptance pending**. Resumed on 2026-10-23 on the existing `m4/financial-records-entitlements` branch. The original five uncommitted files were inspected as unfinished work and continued without discarding them. Prepared for pull-request review; production release remains subject to the gates below.

See the [M4 contract](m4-finance-contract.md) for implemented behavior, interface, proposal defaults and unresolved decisions, and [retained verification evidence](evidence/m4/README.md) for commands, logs and screenshots.

## Implemented

- Owner-only private invoice drafts in both workspace types: exact signed-64-bit minor-unit arithmetic/string transport, ordered lines, due dates, versioned atomic replacement, terminal history-preserving voids, cursor paging, idempotent commands and redacted audit events. Every record remains **Draft — not issued**.
- Manual historical receipts and partial allocations: currency/balance bounds, duplicate evidence references, immutable receipt details, linked corrections, append-only allocation reversals and explicit voiding. Named record selectors carry versions internally. Allocated drafts cannot be changed until allocations are reversed. There is no transfer, subscription purchase or official invoice issuance implied by reconciliation.
- Immutable pilot plan versions and append-only time-limited assignments: audited local/testing migration-role CLI administration, owner usage view, expiry/downgrade preserving existing data, PostgreSQL quota locks/triggers across seven supported create resources, including public capture. Unassigned workspaces remain explicitly development use. No commercial plan, price, quota number or assignment is seeded.
- Ten new tables with forced PostgreSQL RLS; composite financial target constraints; database allocation/immutability guards; authorization before command replay; actual PostgreSQL contention checks for retry, allocation and quota races.

## Actual local verification

Verified on WSL, Node 24.18.0, Docker PHP 8.5.11, PostgreSQL 16, Redis and local ClamAV. Composer dependencies were installed in the pinned PHP image; locked Node dependencies were installed through the pinned Docker Node build after host network installation failures. The initial backend verification preserved dependency versions; the subsequent UI/tooling update added pinned Prettier 3.9.7 to the Node manifest and lockfile. Final gates used packaged images and ordinary Compose services; the local private-storage volume ownership was corrected when switching from the early WSL-user source mounts.

| Gate | Actual result |
|---|---|
| Full PostgreSQL acceptance suite | **36 passed, 858 assertions**; includes original milestone regressions and M4 RLS/concurrency/finance/quota checks |
| PostgreSQL integration suite | **27 passed, 503 assertions** |
| Unit/identity/feature suite | **29 passed, 256 assertions**; includes max money, zero, multiplication and sum overflow |
| Separate fresh/reapplied database | Empty install, M4 rollback/reapply, grants and forced-RLS/function-privilege catalog checks passed; **12 M4 tests, 289 assertions** |
| Chromium desktop/mobile finance | **4 passed**; draft precision/edit/stale-tab reload/void, named receipt/draft allocation/reversal/void, audited pilot assignment, usage, quota rejection and preserved existing-record access |
| Axe | **6 scans, zero violations** across those browser journeys |
| Frontend | Typecheck, **21 Vitest tests**, Vite build passed |
| Build/style/source review | Final app/migration/acceptance images built; PHP Pint passed; relative links/whitespace and pinned source-secret scanner with positive control checked; see evidence for exact scope |

Concurrency acceptance observes PostgreSQL blocking PIDs. Same-key draft/allocation retries produce one result and audit; competing receipts cannot overallocate a draft; concurrent creates cannot exceed a one-record pilot cap. Failed commands roll back records, audit and reserved keys. Direct RLS attacks cover foreign workspaces and all non-owner finance roles. Direct SQL probes also verify balance/currency bounds, receipt immutability, allocated-draft protection and append-only allocations. All seven quota resource triggers and runtime pilot-administration denial are exercised. Valid public capture retries consume one slot.

The fresh database check does **not** claim a rerun of the entire historical clean-setup matrix. The standalone single-user schema checker was extended for M4; absent host PostgreSQL binaries were replaced for local verification by real Docker PostgreSQL migration/catalog/runtime checks. M4 Linux WebKit/native Safari, screen-reader and full zoom reviews remain unverified in this session. Screenshots were retained and mobile rendering inspected.

## UI handoff requirements

**Confirmed:** M4 and every subsequent milestone follow the [mandatory UI standards](../AGENTS.md#mandatory-ui-standards-for-every-milestone) and [roadmap UI completion gate](implementation-roadmap.md#ui-completion-gate-for-every-milestone), across all contributors and Codex models. The invoice, payment, and entitlement screens reuse `FinanceLayout`, `ContentSection`, and `FieldGroup` with the existing foundation palette and controls. Prettier is pinned and configured; changed frontend files must pass the CI formatting gate. Preserve these components and standards when extending the milestone, and record future UI validation and screenshot evidence in this handoff.

Local verification of this standards update: `npm run format:changed` and `npm run format:check:changed` covered 12 new/modified frontend files; a temporary unformatted TypeScript fixture was correctly rejected, then removed, and the final check passed. `npm run typecheck`, `npm run build`, and `git diff --check` passed. The frontend GitHub Actions formatting step is configured but has not run remotely in this session. This update changes contributor guidance, formatting enforcement, and source formatting; it adds no new visual behavior.

## Open decisions and release gates

**Assumption / Open:** pricing, the commercial “active brand” definition, numerical quotas, renewal/grace policy, storage/member/API/AI caps, production operator identity/authorization/MFA, evidence authenticity and retention/pruning remain undecided. The implemented record-count pilot experiments do not settle them. Future-dated assignments block creates until their explicit start; expired assignments never silently fall back to another plan. No production administration interface is enabled.

**Proposal / implementation defaults:** finance remains owner-only. Delegated finance roles, accounting approval, tax treatment and real invoice numbering/issuance require a subsequent contract. Existing M1–M3A manual release reviews and production MFA enforcement remain carried forward. Pilot validation and commercial launch acceptance remain open; this status does not approve production release.

No deployment, external message, real tax invoice, subscription charge, bank connection, payment initiation or transfer occurred. Local services and synthetic test databases remain available for review. The next milestone is M5 only on a subsequent implementation request; no M5 automation was started.
