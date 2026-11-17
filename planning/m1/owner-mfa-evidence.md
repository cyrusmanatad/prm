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
