# Workspace-owner MFA (M1-04) — implementation handoff

Written 2026-11-17 for a new session. Based on `main` at `d237d13`. Nothing in this document has been implemented.

## Start conditions

- **Confirmed (user, 2026-11-17): do not start this work until the user gives an explicit go-ahead.** Reading and planning are fine; no code, migrations or tests before that.
- **Confirmed (D34, 2026-11-17): the recovery rule and three follow-up questions are decided.** See [the decisions](#decision-needed-before-starting). The rest were settled by D35; see the [contract](owner-mfa-contract.md).
- The user expects a planning phase for larger work (findings, questions, plan, required versus optional UI fixes, verification) before code edits. Claude holds primary UI/UX responsibility (D31).

## What the task is

**Confirmed (D18):** MFA is required for platform operators and workspace owners before production. Platform-operator MFA is built (D31). Workspace owners still sign in with password and session only; see [authentication and subscriptions](../shared/auth-and-subscriptions.md) and row M1-04 in the [task register](../shared/remaining-tasks.md). The same item is B14 in the [administration handoff](../administration/implementation-handoff.md).

M1-04 as recorded: implement and verify MFA enforcement for workspace owners, including approved enrollment, recovery, role changes and bypass prevention. M4-02 and M6-06 depend on it.

**Proposal (not approved):** the work likely includes:

- An enrollment screen for owners that reuses the authenticator flow and QR code.
- A code challenge at sign-in for enrolled owners, and a block on owner-only actions until enrollment is complete.
- Role changes: a person promoted to owner must enroll; decide what a demoted owner keeps.
- Tests that enforcement cannot be bypassed (direct routes, JSON requests, other login pages, existing sessions, queued work).

## Decision needed before starting

**Confirmed ([D34](../shared/decisions.md#d34--workspace-owner-mfa-rules-m1-04), 2026-11-17):**

| Question | Answer |
|---|---|
| How does an owner recover a lost authenticator? | Both: one-time recovery codes shown at enrollment, and a reset by a platform operator after an identity check. |
| Which roles? | `owner` only (D18). |
| When is the code asked? | At every sign-in. |
| How many authenticators? | One per account, shared with any platform-operator use. |

The follow-up questions were settled by D35; the plan is in the [contract](owner-mfa-contract.md). Record further answers in [decisions](../shared/decisions.md) before implementing.

## What exists to reuse

| Piece | Where |
|---|---|
| Operator enrollment and verification page, with QR code and manual setup key | `src/resources/js/pages/PlatformSecurity.vue` |
| QR drawing in the browser and the `otpauth://` address | `src/resources/js/features/admin/EnrollmentQr.vue`, `enrollment.ts` (issuer is fixed to `WeavDash Admin`; an owner flow needs its own issuer or a parameter) |
| TOTP code calculation (RFC 6238, SHA-1, six digits, 30 seconds, each counter used once) and step-up handling | `src/app/Services/PlatformAdministration.php` |
| Enroll and verify actions, pending-secret session handling, throttles | `src/app/Http/Controllers/PlatformController.php`; routes `/admin/security*` in `src/routes/web.php` |
| Account columns | `users.admin_totp_secret`, `admin_totp_confirmed_at`, `admin_totp_counter` (added in `2026_11_07_000002_add_platform_activation`) |
| Offline reset for operators | `src/app/Console/Commands/PlatformGrant.php` (`--recover`) |
| Sign-in, sessions and account security UI | `src/app/Http/Controllers/Auth/`, `src/resources/js/components/AccountSecurity.vue`, `src/resources/js/features/auth/` |
| Roles and workspace context | `src/app/Enums/WorkspaceRole.php`, `src/app/Http/Middleware/WorkspaceContext.php`, `EnforceAccountAccess.php` |

The QR dependency (`uqr`) is already approved and installed (D33). The secret must be drawn in the browser and never sent to a third party.

## Rules that apply

Follow [AGENTS.md](../../AGENTS.md): reuse shared components and styles, modal confirmation for actions that revoke access, Prettier and four-space indentation, desktop and mobile checks, text evidence with no retained screenshots. Tenant isolation must be tested against real PostgreSQL RLS. Put the contract and evidence under `planning/m1/` and update the [planning index](../README.md) and [evidence index](../evidence.md).

## Local environment notes

- Browser journeys pass against the disposable stack `weavdash-admin`: run from `src/` with `PLAYWRIGHT_BASE_URL=http://localhost:8010 COMPOSE_PROJECT_NAME=weavdash-admin`. It mounts the working tree, so run `npm run build` first.
- The older stack on `localhost:8000` (`weavdash-professional`) fails at sign-in with a server error. It has not been investigated.
- `weavdash-admin` runs no ClamAV service and no worker, so `onboarding.spec.ts:19` (upload, scan, download) cannot pass there.
- Each Playwright run ends with `EACCES` writing `src/test-results/results.json` (root-owned directory). Results are unaffected.
- WebKit does not run on this host; it runs in CI.
- Browser test helpers for phone widths and touch targets are in `src/tests/Browser/helpers.ts` once PR #29 is merged.

## State of other work on 2026-11-17

- Open pull requests: [#29](https://github.com/cyrusmanatad/prm/pull/29) (M5 small-screen acceptance) and [#28](https://github.com/cyrusmanatad/prm/pull/28) (record of the second founder's staging grant).
- Staging runs `e607e97`. The partner greeting change (PR #27, merged) is not deployed; the user asked for it to go out with the next staging update, not on its own.
- Both founders hold platform-owner grants on staging. The second founder had not enrolled an authenticator when last checked.
- Other work ready to start, in the order discussed with the user: monitoring (M6-03), a backup and restore rehearsal (M6-01), deployment runbooks (M6-04), and the optional UI clean-ups listed in the administration handoff.
