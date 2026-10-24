# M3A Connected Portals Status

**Current revision exception (2026-10-24):** the uncommitted portal/shared-control redesign is awaiting correction and acceptance under UI-02–UI-05. Account-menu/lead-card accessible-name scans fail, pipeline semantics need repair, and the visual fixture still renders the former board instead of the new production component. See the [review](evidence/ui-review-2026-10-24/README.md) and [register](remaining-tasks.md). Earlier reference screenshots and passing suites do not certify this revision.

See the [current handoff](implementation-handoff.md) and [remaining tasks](remaining-tasks.md) for today's checkpoint and carried-forward reviews/future PRM work. Statements below about later milestones not yet starting describe their historical verification period.

Status: **development complete; automated gates passed; manual release acceptance pending**. The user's implementation request authorizes D21–D23 execution before M4. Existing M1–M3 evidence is retained; manual screen-reader/full browser zoom/native Safari release reviews and production MFA remain pending.

## Implemented scope

- Shared identities with partner/brand login and registration pages, header variants, recovery links and authorized workspace context selection.
- Immutable workspace types; existing organizations backfill to creator without changing CRM storage. Durable registration intent provisions the corresponding workspace after verification and survives session loss.
- Explicit owner/admin brand invitations, seven-day expiry, verified-recipient consent/acceptance, single-use token hashes, concurrent duplicate protection and either-side partnership termination. Invitation tokens survive sign-in through a bounded encrypted server-session handoff. No external mail is sent.
- Forced RLS and narrowly scoped lifecycle routines; shared workspace-name snapshots/status/dates only. No cross-workspace membership, private CRM, file, export or worker access is granted by partnership.
- Complete reference dashboard compositions in Vue/Inertia; four/five summaries, actions, five-column board, roster, attention, automation, perks, registered-lead, incentive and enablement sections. Real dashboards derive available data and show unavailable states for deferred capabilities; unsupported switches are disabled.
- Retained seven-stage backend/filter/editor and aggregate semantics. Repeat Client/Lost remain directly accessible and are linked outside the five-column board on the existing detail screen. Existing contacts, templates, onboarding and public-presence flows remain available.

See the [implementation contract](m3a-portal-contract.md) and [evidence index](evidence/m3a/README.md).

## Acceptance and limitations

The synthetic reference fixtures render only through a separate development/test Vite entry point. Production page routes never set the fixture prop, and production builds force fixture rendering off. Full-page parity against the retained HTML has zero differing pixels for both portals at 1440×1000, 1024×1000 and 390×1000 in the recorded Chromium configuration. The source brand reference itself expands its document to 757px at the 390px viewport; that behavior is retained in its fixture, not silently corrected. Real-account brand and partner mobile pages pass document-overflow and automated accessibility checks.

The isolated fresh setup passed in 1,013 seconds: 26 identity tests (252 assertions), 26 PostgreSQL tests (475 assertions), 24 acceptance tests (566 assertions), 21 frontend tests and 58 Chromium/WebKit desktop/mobile browser tests, with two existing duplicate mobile session tests intentionally skipped. The final Vite 8.3.5/fixture-guard/roster follow-up passed types, production build, 21 frontend tests and all 12 portal journeys across both engines/viewports. Additional final PostgreSQL and partnership acceptance checks passed 4 tests/77 assertions and 3 tests/59 assertions. All 76 retained legacy axe scans and 20 final portal scans report zero violations; legacy scans also have zero incomplete findings. Full PHP style, DDL, secret scanning/positive control, Composer validation and both dependency audits passed. Exact source fingerprints and scope differences are retained in the evidence index. Historical evidence is not substituted for current acceptance. Remote CI was not run for this working tree. Manual screen-reader, true full-browser zoom and native Safari remain release gates; Linux WebKit is separate from native Safari. Finance, AI, campaigns, attribution, tiers, commissions, payouts and advanced PRM retain M4/M5/M7/M8 gates. No production deployment or external messaging is performed.

## Next step

Review the local portals at `/partner/login` and `/brand/login` on the development stack (`weavdash-m1`, port 8000). Run the carried-forward manual release checklist before production. M4 has not started; recover its approved contract/decisions when implementation is requested. The synthetic fresh-setup project, credentials, database and volumes were removed after verification. No production deployment or external message was performed.

## Partner navigation follow-up

The 2026-10-22 follow-up (D24) consolidates lead management at `/partner/leads`: Add lead modal, lead detail drawer, filters, totals, pagination and access to Repeat Client/Lost. Implemented partner feature pages use section-specific URLs; legacy page bookmarks redirect and existing JSON/mutation endpoints remain compatible. Account/workspace links and supporting CRM pages are reached through the navigation avatar dropdown with Lucide icons. Unimplemented sections remain disabled. See the [updated route contract](m3a-portal-contract.md#partner-navigation-and-lead-consolidation-follow-up). Verification for this follow-up is recorded separately from the original screenshot-parity baseline.

The final D24 revision passed all 40 Chromium/WebKit desktop/mobile journeys with no skips/failures/flaky tests; all 36 retained axe attachments report zero violations. Identity (26 tests/252 assertions), real PostgreSQL (27/503), focused role/partnership acceptance (5/128), frontend (21 tests), types, build, full PHP style and source secret scanning passed. See the [follow-up evidence](evidence/m3a/partner-navigation/README.md). Manual release reviews and remote CI remain unperformed for this working tree; M4 has not started.

The subsequent brand-avatar follow-up places the account dropdown beside Invite partners and removes the footer duplicates, with Lucide icons for workspace/security and sign out. Types/build and four focused Chromium/WebKit desktop/mobile checks pass; open-menu axe scans report zero violations with incomplete findings retained. See the [brand-avatar evidence](evidence/m3a/brand-avatar/README.md).
