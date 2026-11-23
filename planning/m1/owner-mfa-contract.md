# M1-04 Workspace-owner MFA

Status: **implemented locally; verification recorded, not deployed** ([evidence](owner-mfa-evidence.md), 2026-11-17). Planned against `main` at `d237d13`. Decisions: [D18, D34, D35](../shared/decisions.md). Background: [handoff](owner-mfa-handoff.md). The user gave the go-ahead on 2026-11-17.

## Findings from the code

- Both paths that write a membership (`foundation.sql`, `portals.sql`) insert `owner`. No invite, promote or demote path exists, so every person who creates or is provisioned a workspace is an owner.
- Sign-in has several entrances: `/login`, `/{portal}/login` for brand, partner and professional, and registration, which signs the person in directly. There is no "remember me".
- The only role change that can happen today is becoming an owner by creating a first workspace during a session.
- Queued jobs (`PrepareFollowUp`, `MaterializeSchedule`, `DeliverNotification`) run as a recorded actor without a session.
- The code check is duplicated in `PlatformAdministration::confirm` and `PlatformController::verify`. `EnrollmentQr`, `enrollment.ts` and their styles are scoped to the administration console.
- The three staging reviewer accounts created by `StagingBootstrap` are owners with shared logins. Nearly every browser journey signs in as an owner.

## Rules

**Confirmed (D34, D35):**

- An account that holds an active `owner` membership must have a confirmed authenticator. Until it does, and until the current session has passed the code check, the account reaches only the authenticator page, sign-out and email verification.
- An enrolled account is asked for a code at every sign-in, including after it stops being an owner. No removal of an authenticator is offered.
- One authenticator per account, stored in the existing `users.admin_totp_*` columns. Operator step-up (password and a fresh code, 15 minutes) is unchanged and is not satisfied by the sign-in check.
- Recovery: ten single-use recovery codes shown once at enrollment, and a reset by a platform operator after the identity check in the written procedure.
- An account holding an active platform grant gets no recovery codes and no console reset; it keeps the offline `platform:grant --recover` only (D31).
- Enforcement is always on in production. A setting can turn it off elsewhere for staging and the existing test suites.

## Design

| Part | Approach |
|---|---|
| Enforcement | One web middleware next to `EnforceAccountAccess`. It asks a security-definer function whether the account must use MFA, checks the session proof (account ID and sign-in), and otherwise redirects page visits to the authenticator page and answers JSON requests with 403. Covers every login page, registration, existing sessions and a session that becomes an owner. |
| Setting | `OWNER_MFA_ENFORCED`, default on. Ignored (treated as on) when the application environment is production. |
| Code check | A shared authenticator service extracted from the two existing copies: RFC 6238, SHA-1, six digits, 30 seconds, one step either side, each counter used once, row locked. Operator behavior is unchanged. |
| Enrollment and challenge | `/account/authenticator`: password generates a setup key held in the session for 10 minutes; a correct code confirms it, shows the recovery codes once and marks the session verified. Enrolled accounts see the challenge with a recovery-code alternative. Throttled per account. Session regenerated on success. |
| Recovery codes | New table, codes stored hashed, single use, removed on reset or regeneration. Regeneration and authenticator replacement require password and a fresh code or a recovery-code sign-in. Runtime role reaches the table only through narrow functions. |
| Operator reset | New action on the administration account Security tab: `access.manage`, reason, password and fresh code, expected version. Clears the authenticator and recovery codes, deletes sessions, writes `platform_audit`. Refuses the operator's own account and any account with an active platform grant. |
| Queued work | No change. Jobs are created only by sessions that passed the check; a test shows a blocked session cannot create them. |
| Audit | Enrollment, recovery-code use, regeneration, replacement and reset are recorded without secrets or codes. |

## UI

Claude holds UI/UX responsibility (D31). Reuse `FoundationShell`, the auth card, `FormField`, `ContentSection`, `CopyReference`, `StatusBadge`, the shared button and `ConfirmationDialog`.

**Required:**

- Authenticator page in the sign-in style with setup, challenge and recovery-code states, plus loading, error and throttled states.
- One-time recovery-codes view with copy and a clear "shown once" notice.
- Account security card: authenticator status, "Replace authenticator" and "New recovery codes", each with modal confirmation because they revoke access.
- Administration account Security tab: enrollment state for any account and the reset action with modal confirmation naming the account and the consequence.
- Move `EnrollmentQr` and its styles to shared components; make the issuer a parameter (`WeavDash` for new enrollments).

**Optional (not planned unless approved):** promote Account security from a collapsed card to its own page.

## Verification

- Acceptance tests on real PostgreSQL: unenrolled and unverified owners blocked on direct routes, JSON requests, each login page, registration, an existing session and a session that becomes an owner; non-owners unaffected; code replay refused; recovery code single use; reset rules and protected accounts; production ignores the setting; tenant isolation of recovery codes under RLS.
- Component tests for the authenticator page states.
- One browser journey on desktop and mobile: enroll, sign out, challenge, recovery code, operator reset. Existing journeys run with the setting off.
- `npm run format:check:changed`, `npm run typecheck`, `npm test`, `npm run build`, Pint and PHPUnit. Text evidence in `owner-mfa-evidence.md`; no retained screenshots.

## Open

- **Assumption / Open:** the written identity-check procedure itself (who confirms, through which channel) belongs with M6-06.
- **Confirmed (D36, updated 2026-11-23):** staging enforces owner MFA. The shared reviewer accounts are enrolled, with their keys held only in the private `reviewers-mfa.json` on the staging host.
- With enforcement off outside production, only the dedicated tests prove enforcement; the existing suites do not.
