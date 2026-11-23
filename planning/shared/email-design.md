# Email Design: Shared HTML Presentation

Status: **merged to `main` (PR #45) and deployed to staging on 2026-11-18 (`54a48e6` after the logo path fix in PR #48); real-client rendering unverified**. Presentation only. Triggers, recipients, queueing, budgets, subjects, Message-IDs, link destinations and the wording of every plain-text part are unchanged.

**Confirmed (2026-11-18, user request):** transactional email gains a branded HTML part consistent with the application. This supersedes the "plain-text message" implementation default in the [M5 notification contract](../m5/notification-contract.md) and its later slices for presentation only; their delivery rules stand.

**Confirmed (D30, unchanged):** the approved follow-up message stays plain text with exactly the reviewed subject and body, per the [delivery operations contract](../m5/delivery-operations-contract.md). It receives no HTML part, header or footer.

**Current staging transport (2026-11-23):** `WeavDash <no-reply@weavdash.com>` sends through existing Brevo SMTP credentials; the canonical application URL is `https://app-stg.weavdash.com`. The user confirmed password-reset email receipt from that sender. This does not establish client rendering, logo loading, header authentication or reset completion; those gates remain open. See [domain/email evidence](staging-domain-and-email-evidence.md).

## Structure

| Area | Location |
| --- | --- |
| Theme (inlined at send time; colours mirror `--wd-*` in `foundation.css`) | `src/resources/views/vendor/mail/html/themes/default.css` |
| Shared layout, header, footer and button (Laravel Markdown mail components, published and restyled) | `src/resources/views/vendor/mail/html/` |
| Added components: `x-mail::details` (label/value rows) and `x-mail::link` (unparsed fallback link) | same directory, with text counterparts under `text/` |
| HTML body per notice | `src/resources/views/mail/html/` |
| Plain-text parts (unchanged files) | `src/resources/views/mail/*.blade.php` |
| Logo PNG rasterized from `public/favicon.svg` (email clients do not render SVG) | `src/public/portal-assets/email-logo.png` |

Mailables declare `markdown:` for HTML and keep `text:` for the existing plain-text view. Laravel's password-reset and verification notifications use the same components and take the theme without code changes. Operator monitoring alerts (`Monitoring`, raw text) are out of scope.

## Rules for new email

- Build HTML from the shared components; do not add per-email styles or a second layout.
- Keep an explicit plain-text view. Put capability links in `x-mail::button` and `x-mail::link` only: Markdown would read `_` and `*` in a token as emphasis.
- Link and image URLs come from `config('app.url')`, never the request host. The logo is the only remote resource and carries no recipient identifier. Do not add tracking pixels or enable provider click tracking: rewriting would expose capability links.
- Keep email assets under `public/portal-assets/`. A public directory named like an application route (`public/brand/` and `/brand`) makes the staging web server redirect that route instead of passing it to the application; `PublicAssetPathTest` guards this.
- No web fonts, JavaScript, background images or dark-mode variant. The system font stack stands in for Manrope.

## Verification (2026-11-18)

Run on a disposable Compose project (`weavdash-mailui`, fresh migrated database, no published ports, array transport). No email left the host.

| Command | Result |
| --- | --- |
| `vendor/bin/phpunit` (unit and feature, includes `MailPresentationTest`) | 52 tests, 587 assertions, pass |
| `vendor/bin/phpunit -c phpunit.acceptance.xml --filter 'NotificationOutboxTest\|OnboardingNotificationTest\|OnboardingLifecycleTest\|ScheduledNotificationTest\|DeliveryOperationsTest\|BrandApprovalTest\|MailBudgetTest'` (existing tests, unmodified) | 78 tests, 1750 assertions, pass |
| `vendor/bin/pint --test` | pass |
| `npx prettier --check resources/views/vendor/mail/html/themes/default.css` | pass |

**Visual findings:** every HTML type was rendered with `php artisan mail:preview <type>` and inspected in Chromium at 760 px and 375 px. No horizontal overflow at either width; the button becomes full width and detail rows stack on the narrow viewport; long capability links wrap inside the card. No screenshots retained.

**Unverified gates:** rendering in real Gmail, Outlook (Windows desktop) and Apple Mail/iOS clients; forced dark-mode inversion in mobile clients; logo loading from the deployed origin. Check these with a controlled recipient under the [provider delivery acceptance](../m5/provider-delivery-acceptance.md) rules before relying on the design externally.

**Open:** expiry values still print the stored timestamp (`2026-11-25 08:00:00+00`). A friendlier format needs a decision on time zone and is not part of this change.
