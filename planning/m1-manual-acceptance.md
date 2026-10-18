# M1 manual acceptance

Status: **Assumption / Open**. These checks are pending; automated browser and axe results do not establish that they passed. Use synthetic identities in a disposable environment.

Record the reviewer, date, OS/browser/assistive-technology versions, result, and evidence for each check. Link failures to a fix and repeat the affected journey.

## Screen reader and keyboard

- Complete registration, verification, login, recovery, and reset using keyboard navigation with a screen reader. Confirm page titles, headings, field names, required state, validation messages, and pending submission state are understandable.
- Create/select/rename a workspace, cause validation errors, and open account security. Confirm focus follows errors after submission and the disclosure announces its state.
- Check owner/admin and viewer experiences. Revoke a selected membership from a separate session, reload, and confirm the stale workspace disappears and the access-change notice is announced.
- Sign out all sessions and confirm the resulting login state and focus make sense. Confirm no keyboard trap or obscured focus.

## Full browser zoom and native Safari

- At 200% browser zoom, complete login, recovery, workspace selection/settings, and account security. Verify labels, errors, focus, buttons, and content remain usable without clipping or overlap. Root-font tests are separate evidence.
- At 400% zoom from a 1280 CSS-pixel-wide desktop window, check reflow and keyboard access to each control; record horizontal scrolling or loss of content.
- Repeat core account/workspace journeys in native macOS Safari and iOS Safari. Record actual device/browser versions. Linux Playwright WebKit is separate engine coverage.

## External mail

- Configure the selected service using private environment secrets and an authorized test inbox. The default Compose `MAIL_MAILER: array` must be explicitly overridden for a delivery trial.
- Request verification through the UI; confirm inbox delivery, intended sender, usable link origin, and successful verification. Check tampered and expired links fail.
- Request password recovery through the UI; confirm delivery, successful reset, invalidated existing sessions, and rejection of link reuse.
- Record provider message identifiers and redacted results. Keep credentials, full signed URLs, reset tokens, and mailbox contents out of committed evidence.
- Restore the in-memory transport after the trial. Provider selection and inbox receipt remain unresolved until supplied and observed.

## MFA policy

**Confirmed (D18):** require MFA for platform operators and workspace owners before production. Record the approved roles, enrollment/recovery process, enforcement point, and test requirements in [authentication and subscriptions](auth-and-subscriptions.md). Policy approval is not implemented MFA enforcement or acceptance evidence.

Track results and milestone disposition in [M1 status](m1-status.md).

## Suggested free mail trial

**Proposal (2026-10-18):** Brevo Free provides [300 emails per day](https://www.brevo.com/products/transactional-email/). Use SMTP so a later provider change requires configuration rather than notification changes. Account approval, sender verification, and actual delivery are still pending.

Create the account, verify the intended sender/domain, and get the SMTP login and SMTP key from its SMTP page. The [official SMTP instructions](https://help.brevo.com/hc/en-us/articles/7924908994450-Send-transactional-emails-using-Brevo-SMTP) distinguish this key from an API key. Set private `src/.env` values:

```dotenv
MAIL_SCHEME=smtps
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=465
MAIL_USERNAME=your-smtp-login
MAIL_PASSWORD=your-smtp-key
MAIL_FROM_ADDRESS=your-verified-sender
MAIL_FROM_NAME=WeavDash
```

Keep `MAIL_URL` unset so it cannot override these settings. For the retained disposable stack, enable the explicit application-only override from the repository root:

```sh
docker compose --env-file src/.env -p weavdash-m1 -f compose.yaml -f compose.mail-validation.yaml up -d --no-deps app
```

Verification/reset notifications currently send during HTTP requests. Workers and automated tests retain their in-memory transport. Submit the real UI flows using an authorized inbox; observe receipt and follow the links on the machine that can reach `APP_URL`. A successful SMTP handoff alone does not prove inbox delivery.

Restore the default transport after the trial:

```sh
docker compose --env-file src/.env -p weavdash-m1 up -d --no-deps app
```
