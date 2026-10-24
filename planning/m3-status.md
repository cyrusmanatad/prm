# M3 Onboarding and Public Presence Status

Current storage/privacy operations, notification gaps and inherited reviews remain indexed in [remaining tasks](remaining-tasks.md). See the [current handoff](implementation-handoff.md) for the latest checkpoint and next implementation sequence.

Status: **development complete; automated gates passed; manual release acceptance pending**. **Confirmed (D20):** finish M2 and proceed through M3 with explicit onboarding initiation and minimal non-sensitive fields. The manual M1 accessibility/zoom/native Safari reviews remain release gates and are not claimed complete.

## Implemented scope

- Explicit Start onboarding from a private lead; no implicit stage trigger or automatic transition.
- Private scoped invitation, one-use exchange, bounded session, renewal that closes prior sessions, expiry and immediate revocation checks.
- Minimal business/contact/email/website/brief form; saved draft resume, version conflicts, explicit submission consent, immutable submissions, creator correction/resubmission and acceptance.
- In-app awaiting-review indication and retained events; no external messages or production integrations.
- Private file upload with type/size/count limits, ClamAV quarantine/clean/rejected states, authorized forced downloads, checksum verification and removal of unused files.
- Explicit-consent public profile/media kit with up to eight manually entered social-account links, a safe HTML projection, unpublishing and aggregate view count.
- Revocable/expiring inbound lead link with consent, idempotent capture and no private read capability.
- Forced RLS on every new private table; narrowly scoped, fixed-search-path security-definer routines; redacted creator audit; allowlisted public props and encrypted server-side capability/session flashes.

See the [implementation contract](m3-onboarding-contract.md).

## Verification

The full fresh setup passed in 703 seconds with new synthetic credentials, database and storage in the pinned Playwright container, and removed its own stack/volumes. It passed 24 identity tests (208 assertions), 22 real PostgreSQL tests (392 assertions), 21 process/role/capability acceptance tests (510 assertions), 21 Vue tests, types and build. Full Pint, standalone DDL, source secret scanning/positive control, strict Composer validation and both dependency advisory checks passed. A later fresh database also passed rollback/reapply of all four new migrations, runtime grants and ten targeted integration tests (174 assertions), then was removed.

All 46 Chromium/WebKit desktop/mobile browser tests passed, with two existing duplicate mobile session tests intentionally skipped. All 76 axe scans had zero violations/incomplete results. The final labeled-history view subsequently passed four additional journeys and twelve clean axe scans; it displays the original submitted brief after correction rather than a technical snapshot dump. Screenshots were reviewed and overflow checks pass. Exact full-setup and follow-up source fingerprints are retained in the [evidence index](evidence/m3/README.md) and [structured verification](evidence/m3/verification.json). Remote CI was not run for this working tree.

The R03 profile follow-up adds explicit manual social-account links (up to eight), publication consent and independently allowlisted labels/URLs. On the final profile revision, the full PostgreSQL suite passed 22 tests (399 assertions), acceptance passed 21 tests (510 assertions), and all four profile/publication browser journeys passed with twelve clean axe scans. Invalid schemes, oversized account lists, unknown nested fields and internal metadata leakage are covered. Updated source and scope are retained separately in structured verification; social connections/verification and automatic platform statistics are not claimed.

Coverage includes invitation replay/expiry, scoped save/resume, consent and idempotent submission, immutable history, correction/resubmission, acceptance/revocation, denied foreign rows/files/partial props, role revocation, actual PostgreSQL blocking during capability revocation, clean/rejected/pending upload handling, public-field isolation and publication/lead-link revocation. Three Vue regressions prevent a later writer's redirect data from silently changing an older draft's acknowledged version. Shared-host response timing issues were corrected in the harness without removing functional assertions.

## Release gates and limitations

- Manual screen-reader, actual full browser zoom and native macOS/iOS Safari review remain pending from M1 under D19/D20.
- Production MFA enforcement, hosting/storage provider and region, retention/deletion operations, monitoring/recovery and commercial launch remain later gates.
- The later [M5-01 slice](m5-onboarding-email-contract.md) adds optional durable onboarding invitation/replacement email with local verification. Lifecycle notifications, provider delivery and remaining automation are still M5 work; original M3 evidence below remains historical.
- Upload scans require the healthy pinned ClamAV service and its signatures. Scanner failure retains quarantine. Private orphan reconciliation remains an operations follow-up before live data.
- The creator request list shows the most recent 100; high-volume paging remains a follow-up.

M4 development has not started. Its invoice draft/manual-payment and pilot entitlement decisions must be recovered from planning before a later continuation; no production readiness is implied by M3 development.

The subsequent M3A portal/relationship implementation has its own [status](m3a-status.md), [contract](m3a-portal-contract.md) and [verification evidence](evidence/m3a/README.md). Historical M3 verification above remains scoped to its recorded source.
