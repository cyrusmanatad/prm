# Onboarding requests evidence

**Scope (2026-11-21):** the Onboarding requests page (`/partner/onboarding`, `/professional/onboarding`), following the user-approved compact mockup. Branch `ui/onboarding-requests` from `origin/main` at `52d08c0`. Local Compose stack (`weavdash`) with rebuilt `app`, `worker`, `scheduler` and `acceptance` images; Chromium only.

## Change

- **Layout:** breadcrumb, 28px title with the total count, and a New request button. Status filter chips with counts use plain names for the stored statuses: Awaiting review (`submitted`), Invited (`draft`), Correction requested, Accepted, Revoked and All. Below the chips sit a request list and a detail panel. Below 1100px a Requests / Details switch shows one at a time.
- **List:** search by request or brand (debounced; kept in the address), 20 per page with numbered pagination, newest first. Each row shows the label, a status badge, the brand, when it was invited or submitted, and the time left on open invitations ("Expires in 5 days", "Expired"). Each row is a stretched link naming the request, with the details as its description.
- **Detail:**
  - Header: status, lead link, invited and submitted dates, version, and expiry.
  - In-page tabs (new shared `Tabs` component with tab semantics and arrow, Home and End keys): Submission (details, any correction note, reference files with size, scan badge, Download and Retry scan), History (submission versions) and Emails (writers only; the "Onboarding email history" region).
  - Review box (submitted requests only): a multi-line correction note marked as required only for corrections, the optional outcome email, and Request correction and Accept submission.
  - Access bar: Replace invitation… opens the replacement form inline (confirmed as before), plus Revoke invitation access (confirmed).
- **New request dialog:** choose a lead, a label suggested from it, the optional invitation email and submission notice, then Create request. Its fields are rendered only while it is open. The one-time link callout now has a Copy button.
- **Unchanged:** the review, replacement, revoke and scan endpoints and their emails, version handling, malware-scan download rules and viewer read-only access.
- Removed CSS that no longer matched any markup: the old `pro-request-*`, `pro-access-controls` and `wd-onboarding-admin > nav` Professional rules, and the `pro-template-layout` rules left behind by the Outreach redesign.

## Backend and data contract

- `GET /partner/onboarding` (and `/onboarding-requests` JSON) accepts `status` (`submitted`, `draft`, `correction`, `accepted`, `revoked`, `all`), `q` and `page`. With no status, it shows Awaiting review when anything is waiting and there is no `request` link; otherwise All. Out-of-range pages clamp.
- **Contract change:** `requests` changes from a flat array of up to 100 to `{items, page, perPage, total, counts}`. Counts follow the search. Items add `brand_name`, `created_at`, `expires_at` and `submitted_at` (latest submission).
- New props: `filters`, and `leads` (writers only; up to 100 recently updated, non-lost leads, `id` and `brand_name`). `selected` adds `pipeline_entry_id`, `brand_name`, `created_at`, `expires_at` and `submitted_at` for this owner view only. The recipient-facing form detail is unchanged.
- No migration. New requests use the existing `POST /onboarding-requests`.

## Verification

| Command | Result |
| --- | --- |
| `phpunit -c phpunit.pgsql.xml --filter OnboardingTest` (one new test: default filter, counts, search, paging and clamping, request details, leads, rejected status) | 13 tests, 279 assertions passed. |
| `acceptance` `--filter 'OnboardingSecurityTest\|OnboardingNotificationTest\|OnboardingLifecycleTest'` (viewers receive no leads) | 21 tests, 550 assertions passed. |
| `pint --test`, `format:check:changed`, `typecheck`, `npm test`, `build` | Passed; 181 component tests (new `OnboardingAdmin.test.ts`: 6). |
| Browser `onboarding`, `onboarding-lifecycle`, `onboarding-notifications`, `professional-ui`, `partner-navigation`, `ui-refinement`, desktop and mobile | All passed on the final image. Journeys now open the Emails and History tabs and Replace invitation… where needed. |

- **Fixed during verification:** a duplicate "Email this onboarding invitation" field from the closed dialog; the breadcrumb and panel switch now meet 44px mobile touch targets, as the lifecycle journey checks.
- **Rate limiting:** one mobile `onboarding.spec` run hit the invitation rate limit ("Too many attempts") after back-to-back runs and passed on its own rerun.
- **Rendering:** inspected at 1440, 1024 and 390px with submitted, invited and accepted requests, the review box and the New request dialog. Status filter chips wrap to three lines at 390px. Temporary captures were deleted.
- **Not verified:** WebKit/Safari, staging, manual screen reader, and the full PostgreSQL suite with `rls.sql` in a disposable project.
