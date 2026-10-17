# M1 Foundation Status

Status: **in progress; not accepted**. No M2 features or production deployment are included.

## Implemented backend slice

- Official Laravel 13 skeleton under `src/`, PHP dependency lockfile, and Laravel Pint.
- Password/session authentication, registration, email verification, recovery, logout, database-session revocation, and request throttling.
- UUID workspaces, active role memberships, private discovery, verified-identity bootstrap, selection, and owner/admin settings changes.
- PostgreSQL schema with forced RLS; reviewed bootstrap/membership functions; separate migration/runtime credentials; restricted runtime-role guard.
- Transaction-local actor/workspace settings, active-membership rechecks, scalar-ID job middleware, and append-only audit events without private form contents.
- Development Compose definitions for backend, PostgreSQL, Redis, worker, scheduler, and controlled migrations; backend CI definition. The shared Docker image now targets PHP 8.5 with Redis extension 6.3.0 (D17); a real build/runtime check is still pending.

## Verification evidence

Verified locally: 16 identity/role/guard/health tests passed with 66 assertions; Laravel Pint and strict Composer validation passed. Compose configuration parsing, PostgreSQL DDL/catalog checks, shell syntax, CI configuration generation, and changed documentation links also passed.

The default SQLite tests cover identity only. Standalone PostgreSQL grants implicit privileges unsuitable for runtime isolation verification; its check is limited to schema/DDL validation. Real-server tests exist separately and must pass before acceptance.

## Outstanding acceptance gates

| Gate | Remaining work |
|---|---|
| F01 | Install official Inertia adapter/client and Vue/TypeScript/Tailwind/shadcn-vue baseline; create auth/workspace pages, frontend lockfile, and successful production build |
| F02 | Browser verification/recovery/login/session-revocation journeys; redirect/error-bag behavior for Inertia; operator/owner MFA policy before production |
| F03–F05 | Run real PostgreSQL HTTP/role/isolation tests, adversarial session switching/revocation tests, concurrent membership checks, and Inertia prop-leak tests |
| F06 | Run actual Redis worker/scheduler restart and failure tests in addition to middleware-level context checks |
| F07 | Build/run containers, execute GitHub CI, pin reviewed image digests, add dependency/secret checks and frontend checks; validate setup from a clean environment |
| UI evidence | Render prototype and foundation screens at desktop/mobile sizes; test keyboard focus, errors, loading states, and accessible controls |

## Environment blockers

The current sandbox cannot resolve external package hosts, bind TCP or Unix server sockets, or access the Docker daemon. Cached packages allowed a real Laravel installation. The official Inertia adapter and required UI dependencies are absent from the caches; they have not been replaced with custom implementations or a different framework.

Continue M1 in an environment with package network access and Docker/server socket permissions. Do not mark this milestone complete or begin CRM implementation based solely on static configuration or identity tests.
