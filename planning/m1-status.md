# M1 Foundation Status

Status: **in progress; not accepted**. No M2 features or production deployment are included.

## Implemented foundation slices

- Official Laravel 13 skeleton under `src/`, PHP dependency lockfile, and Laravel Pint.
- Password/session authentication, registration, email verification, recovery, logout, database-session revocation, and request throttling.
- UUID workspaces, active role memberships, private discovery, verified-identity bootstrap, selection, and owner/admin settings changes.
- PostgreSQL schema with forced RLS; reviewed bootstrap/membership functions; separate migration/runtime credentials; restricted runtime-role guard.
- Transaction-local actor/workspace settings, active-membership rechecks and scoped shared membership locks, scalar-ID job middleware, and append-only audit events without private form contents. A new migration orders in-flight actions against concurrent revocation/demotion; denied or zero-row settings changes cannot append success audit events.
- Development Compose definitions for backend, PostgreSQL, Redis, worker, scheduler, and controlled migrations; backend CI definition. The shared Docker image builds on PHP 8.5 with Redis extension 6.3.0 (D17). PHP, Composer, PostgreSQL, and Redis image digests are pinned to the tested inputs. Redundant extension builds that failed against PHP 8.5's bundled DOM/Lexbor have been removed.
- Routed Vue/TypeScript authentication and workspace screens using the reference palette, official Inertia Laravel/Vue adapters, Tailwind v4, and a generated shadcn-vue button baseline. Includes typed submissions, named validation errors, scoped settings controls, pending states, and focus handling after forms become enabled.
- Locked frontend dependencies, complete Vite application entry, lazy Inertia pages, and Node build stage bundled into the PHP image. Vue is updated to 3.5.44. Both workflows check dependency advisories; the backend workflow now defines desktop/mobile Chromium journeys. Node build inputs are pinned by digest too.
- Isolated acceptance service and deterministic PostgreSQL/process probes, plus worker/scheduler init processes and verified automatic container restart after SIGKILL. No business schedule or automation is added.
- Browser redirects retain JSON endpoint compatibility. Account/workspace props are allowlisted, stale selections cleared, history encrypted and cleared on logout, responses marked private/no-store, and Inertia DevTools recording disabled. Generic Inertia error pages preserve status codes and retry guidance. Request throttles are isolated per action.

## Verification evidence

Latest local verification (2026-10-18, Asia/Shanghai):

- PHP 8.5.11 images build; the new membership-lock migration applies to the existing database. A separate fresh synthetic database passed full migration rollback/reapply, runtime grants, and expanded SQL assertions (`RLS_CHECKS_PASSED`), including lock-function scope, ownership, fixed search path, and revoked PUBLIC execution privileges. The standalone schema/DDL check also passed.
- 23 default identity/Inertia/throttle tests passed with 200 assertions. Six real PostgreSQL/Redis integration tests passed with 109 assertions, including Inertia page/partial reload projection, stale selection cleanup, named error bags, worker failure rollback, cross-tenant processing, and fresh worker processing.
- Fourteen additional acceptance tests passed with 312 assertions in about 67 seconds. They observe real PostgreSQL blocking in both revocation/demotion orderings, stale HTTP sessions, rollback/lock cleanup, scope denial, SIGKILL worker recovery after Redis reservation expiry, scheduler exceptions, overlap prevention, and recovery after the real one-minute lock TTL. Docker worker and scheduler restart counters each increased from 0 to 1 after their Artisan processes were killed.
- 13 Vue interaction tests, strict component/TypeScript checks, and the complete application build passed. npm and Composer audits reported no known advisories after installing the official integration dependencies; Pint and strict Composer validation passed.
- Eleven Chromium browser tests passed with one intentional mobile duplicate skipped. Coverage includes all five roles, forged tenant/actor/role fields, adversarial Inertia partial props, revoked memberships, pre-login session fixation, missing-CSRF login, logged-out cookie replay, password-reset session revocation, and reset-link reuse. Viewport-independent session attacks run on desktop; recovery/revocation journeys also run on mobile. External email delivery was not exercised.
- Twenty axe scans across desktop/mobile authentication, verification, errors, workspace/security, and 200-percent root-font states reported zero violations. Fixed the missing mobile H1 and an unsupported generic-span ARIA label. Two decorative-initial contrast items required manual review; calculated contrast is 7.07:1. Keyboard/focus and horizontal-overflow checks pass. This does not certify screen-reader behavior, full browser zoom, or Safari/WebKit.
- Pinned Gitleaks source scanning passed with a synthetic positive control. It scans current tracked and nonignored untracked source, not Git history or ignored local credentials.
- A clean source/configuration/dependency/data setup passed with newly generated credentials and fresh PostgreSQL/Redis volumes: migrations, grants, SQL RLS, all PHP/Vue/browser suites, types, and build. It reused the existing Docker/Node/Python host and may reuse build/browser caches. Its disposable services and volumes were removed. Backend CI now defines source scanning, retained browser evidence, and a separate clean-setup job; no remote is configured, so GitHub execution remains unverified.
- [UI and security/accessibility evidence](evidence/m1/README.md) retains screenshots and structured [browser/accessibility](evidence/m1/security-accessibility.json) and [clean setup](evidence/m1/clean-setup.json) results.

Concurrency/lifecycle probes run through `phpunit.acceptance.xml` in the isolated `acceptance` service. Its migration-role fixture credential is absent from web/worker/scheduler services and removed from runtime subprocesses. Scheduler fixtures configure only their own CLI process; application schedules remain empty until M5. The disposable Compose project is `weavdash-m1`; synthetic-data volumes, including the separate `weavdash_m1_lockcheck` database, are retained after stopping services.

The default SQLite tests cover identity only. Standalone PostgreSQL grants implicit privileges unsuitable for runtime isolation verification; its check is limited to schema/DDL validation. The separate Compose evidence above verifies real-server behavior but does not establish production readiness or exhaustive attack coverage.

## Outstanding acceptance gates

| Gate | Remaining work |
|---|---|
| F01 | Implemented and verified locally: official adapters, Tailwind/shadcn-vue baseline, routed screens/actions, redirects, validated projections, and complete application/container build |
| F02 | Core Chromium desktop/mobile journeys, redirects, error bags, and database session revocation pass. Session-attack coverage passes locally. Remaining: external mail delivery validation and operator/owner MFA policy before production |
| F03–F05 | Real PostgreSQL HTTP/role/isolation, denied/stale switching, Inertia projection, and coordinated concurrent revocation/demotion tests pass, including rollback and subsequent stale-session denial. All five roles, forged fields, adversarial props, and revoked browser selections pass locally |
| F06 | Implemented and verified locally: audit/context checks, worker failure and SIGKILL recovery, reservation reclaim, scheduler failure/overlap/lock-expiry recovery, and automatic worker/scheduler container restart. Durable business automation and external-action idempotency remain M5 scope |
| F07 | Complete images, repeatable migrations/grants in a fresh synthetic database, local integration/acceptance/browser tests, pinned inputs, and dependency audits pass. CI now defines the concurrency/lifecycle checks. Source secret scan and clean source/configuration/dependency/data setup pass locally. Remaining: execute GitHub CI on a remote runner |
| UI evidence | Desktop/mobile prototype and foundation captures plus keyboard/error/loading checks are saved. Twenty axe scans and keyboard/focus checks pass. Remaining: manual screen-reader/full zoom review and Safari/WebKit coverage |

## Environment blockers

The sandbox still restricts external package hosts and Docker daemon access, but approved commands outside it now provide working package network access and Docker. The previous environment blocker is resolved for container and package work. The official Inertia adapter/client, Tailwind, shadcn-vue baseline, and Chromium are installed. No custom router or session client replaces Inertia.

Continue with remote CI delivery validation, manual screen-reader/full zoom and Safari/WebKit review, external mail validation, and the production MFA policy. Remote CI requires a configured repository remote; none is present in this checkout. Do not mark this milestone complete or begin CRM implementation until all acceptance gates pass.
