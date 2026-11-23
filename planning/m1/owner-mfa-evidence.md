# M1-04 Workspace-owner MFA — verification

Scope: implementation of the [contract](owner-mfa-contract.md) under D18, D34 and D35. Date: 2026-11-17. Branch `feat/owner-mfa`, based on `main` at `d237d13`. Environment: disposable local stack `weavdash-admin` (PostgreSQL 16 with the restricted runtime role, `http://localhost:8010`). Not deployed to staging.

## What was built

- Migration `2026_11_17_000002_add_owner_mfa` (`database/sql/owner-mfa.sql`): `account_recovery_codes` and `account_security_events` under forced row-level security with no runtime policies, and narrow security-definer functions.
- `App\Services\Authenticator`: the shared code check, now also used by platform step-up, with a lock after five failed attempts in fifteen minutes.
- `EnforceOwnerMfa` middleware in the web group; `Account\AuthenticatorController` at `/account/authenticator`.
- Operator reset: `POST /admin/accounts/{account}/reset-authenticator` and the Security tab of the administration account page.
- UI: `AccountAuthenticator` page and `AuthenticatorScreen` (reusing `FoundationShell`, the auth card, `FormField`, `ActionGroup`, `CopyReference`, `StatusBadge`, the shared button and `ConfirmationDialog`); `AuthenticatorReset` in administration; `EnrollmentQr` and `enrollment.ts` moved to shared locations with the issuer as a parameter; a link in the Account security card.
- Setting `OWNER_MFA_ENFORCED` (`config/security.php`), passed through both Compose files. Production ignores it; staging mode honours it.

## Commands and results

| Check | Result |
|---|---|
| `phpunit -c phpunit.acceptance.xml --filter OwnerMfaTest` (acceptance service, real runtime role) | 8 tests pass |
| Same with `PlatformConsoleTest`, `PlatformGovernanceTest`, `RoleAuthorizationTest` on the final code | 29 tests, 1000 assertions pass |
| Full acceptance suite (before the last controller change, which only altered page redirects) | 168 tests, 2 failures, 3 skipped. Failures: `OnboardingSecurityTest` real-scanner test and `SyntheticRecoveryTest` SMTP disconnect test. This stack runs no ClamAV and is not the recovery environment; neither was re-run on `main`. |
| `php artisan test --compact` | 42 tests pass |
| `vendor/bin/pint --test` | pass |
| `npm run format:check:changed`, `npm run typecheck`, `npm run build` | pass |
| `npm test` | 126 tests pass, including 6 new in `AuthenticatorScreen.test.ts` |
| `owner-mfa.spec.ts`, desktop and mobile Chromium, enforcement on | 2 pass |
| `foundation.spec.ts` and `administration.spec.ts`, desktop and mobile Chromium, enforcement off | 6 pass (`owner-mfa.spec.ts` skips itself when enforcement is off) |

Acceptance coverage: an unenrolled or unverified owner is refused on direct routes, JSON requests, each of the four sign-in pages, registration followed by creating a workspace, and page visits (redirect); other roles and accounts with no workspace are unaffected; a demoted but enrolled account is still asked; code replay is refused; recovery codes work once and not across accounts; five failures lock the check; regeneration and replacement need a verified session; operator reset rules and protected accounts; production ignores the setting; the runtime role cannot read or write the two tables directly.

## Visual findings

Viewports 1440×1000 and 390×1000. Axe scans report no violations and no page has horizontal overflow on the setup, setup-key, recovery-codes, manage, challenge, recovery-code and administration reset states, and inside each confirmation dialog. Temporary captures were inspected and removed. One defect found and fixed: link-style buttons on the authenticator page were left-aligned next to centred buttons. One behaviour defect found and fixed: a rejected recovery code returned the person to the code form without showing the error.

## Deviations from the contract

- The operator reset takes no expected version: accounts have no version, as with the existing sign-out action.
- Recovery codes are flashed for one page view after a redirect instead of being rendered from the POST, so a reload does not repeat the change. The pending setup key stays visible until it expires.
- Platform operators are also asked for a code at sign-in, because an enrolled account is always asked (D35). Completing platform step-up also satisfies the sign-in check.
- The Account security card links to the authenticator page; it does not show enrollment status, because the shared `account` props are pinned by `RoleAuthorizationTest`.

## Unverified or open

- WebKit does not run on this host. CI uses `.env.example`, where enforcement is off, so `owner-mfa.spec.ts` skips there until a job sets `OWNER_MFA_ENFORCED=true`.
- With enforcement off, the existing suites do not prove enforcement; only the dedicated tests do (D35).
- `rls.sql` was not extended; isolation of the new tables is covered by `OwnerMfaTest`.
- Staging: **Confirmed (D36)** off for now; `compose.staging.yaml` defaults `OWNER_MFA_ENFORCED` to `false`. Staging therefore does not exercise owner MFA until it is switched on.
- The written identity-check procedure for operator resets belongs with M6-06. **Assumption / Open.**
- Screen-reader and native Safari review were not done.

## Enforced browser run and CI (2026-11-23)

Branch `feat/owner-mfa-enforced-ci` from `main` at `2c4b9a6`.

- **CI:** `src/scripts/check-clean-setup.py` (each clean-setup job: Chromium and Linux WebKit, desktop and mobile) now runs the normal browser suite with enforcement off as before, then restarts the application with `OWNER_MFA_ENFORCED=true` (phase `mfa-app`) and runs `owner-mfa.spec.ts` in the same browser project (phase `mfa-browser`, results kept as `mfa-browser-results`). There, `REQUIRE_OWNER_MFA=1` makes a stack without enforcement fail instead of skipping.
- **Defect found by the first enforced run:** both projects failed with "Enter a fresh authenticator code." The test's code helper picked the oldest step the server accepts (`-1`); when a 30-second boundary passed before the server checked it, the code was two steps old. The application was correct (host and container clocks agreed; the server accepts the previous, current and next step). The helper now takes the oldest unused step that stays valid for a margin (8 seconds, or 20 before the reset dialog's confirmation steps), as `administration.spec.ts` already does.
- **Local result:** `owner-mfa.spec.ts` with enforcement on and `REQUIRE_OWNER_MFA=1`, desktop and mobile Chromium, `--repeat-each=2`: 4 passed. This covers setup, challenge, recovery code, replacement and the operator reset through the compact Security tab's "Review reset" dialog and Cancel-first confirmation (from cyrusmanatad/prm#86), which had not run in a browser before.
- **Local clean-setup (`check-clean-setup.py --browser-project desktop`):** the full run passed every phase up to the existing browser suite, which then reached its 900-second limit on this host at test 66 of 70 with no failures (CI runs that phase in about 9 minutes). A temporary copy with that phase cut to `foundation.spec.ts` (deleted afterwards) passed every phase, including `mfa-app` and `mfa-browser`, where the owner-MFA journey passed (1 test, not skipped) on a fresh stack.
- **Reset procedure:** drafted as a Proposal in [owner-mfa-reset-procedure.md](owner-mfa-reset-procedure.md); its choices need the user's decision. A reset does not notify the account holder; the proposal adds a manual notice and recommends an automatic one.

Still open: staging enforcement and how the shared reviewer accounts get authenticators (D36); the procedure decisions; screen-reader and native Safari review.
