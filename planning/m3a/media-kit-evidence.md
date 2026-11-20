# Media kit and formatted introduction evidence

**Scope (2026-11-21):** the Media kit / Professional profile page (`/partner/media-kit`, `/professional/profile`) and the public kit page (`/kits/{id}`), following the user-approved compact mockup and the request to use Tiptap for the public introduction. Branch `ui/media-kit` from `origin/main` at `42087df`. Local Compose stack (`weavdash`) with rebuilt `app`, `worker`, `scheduler`, `migrate` and `acceptance` images; Chromium only.

## Change

- **Layout:** 28px title with a Published / Draft badge; Open and Copy public link in the header. The editor sits beside a public-page card with the link, three figures (views, active lead links, leads received) and a live preview that includes unsaved edits. Inbound lead links span the full width below. Below 1100px an Edit / Preview / Lead links switch shows one section at a time.
- **Editor:** Profile, Social accounts and Publishing sections; a title character counter; compact social rows with an icon remove button; a sticky save bar showing unsaved changes with Discard and Save. Viewers see the page read only.
- **Formatted introduction:** new shared `RichTextEditor` (Tiptap 3.32.3, MIT) limited to paragraphs, bold, italic, one subheading level (H3), bulleted and numbered lists, and http/https/mailto links. Underline, strike, code, quotes, rules, images and pasted styling are excluded by the editor schema. The toolbar is a labelled `role="toolbar"` with pressed states and shortcuts. Links use an inline field rather than a browser prompt. The character count covers visible text only. Toolbar presses keep the text selection, which touch devices otherwise lose.
- **Lead links:** an optional private label at creation; a table with label, created, expiry (with relative time), status (Active, Expires soon within 7 days, Expired, Revoked) and leads received; status filter chips with counts; 10 links per page; Revoke… names the link and keeps the current filter and page. A new link appears once in a callout with a Copy button and the note that it is shown only once.
- **Public page:** renders the sanitized introduction. The description meta tag uses its plain text.

## Backend and data contract

- **Sanitization:** `App\Support\RichText` (`symfony/html-sanitizer` 7.4.23, MIT) allowlists `p`, `br`, `strong`, `em`, `h3`, `ul`, `ol`, `li` and `a[href]` with http, https and mailto only, and forces `rel="noopener noreferrer nofollow"`. It runs on save and again on every display. The 5,000-character limit counts visible text; stored HTML is capped at 20,000.
- **Legacy introductions:** plain-text introductions saved before this change are escaped and shown as paragraphs and line breaks on read. No data migration rewrites them.
- **Migration** `2026_11_21_000001`: widens `media_kits_bio_check` to 20,000 characters, adds `lead_links.label varchar(80)` and an `(org_id, created_at, id)` index. Rollback refuses to truncate introductions longer than 5,000 characters. RLS and grants are unchanged.
- **Page contract:** `GET /partner/media-kit` accepts `status` (`active` default, `expired`, `revoked`, `all`) and `page`. `links` becomes `{items, page, perPage, total, counts}`, and each item adds `label`, `created_at`, `status` and `leads`. `summary` and `filters` are new, and `kit.public_url` is added. **Contract change:** `links` was a flat array. `POST /lead-links` accepts `label` (up to 80 characters).
- **Privacy export:** still exports the stored introduction, now as sanitized HTML.
- **Assumption / Open:** consent is still required on every save while the kit is published. The user has not yet decided whether to require it only when publishing.

## Verification

| Command | Result |
| --- | --- |
| `phpunit -c phpunit.pgsql.xml --filter OnboardingTest` (2 new tests: sanitization, legacy text, limits, meta description; labels, statuses, counts, paging) | 12 tests, 248 assertions passed. |
| Full `phpunit -c phpunit.pgsql.xml` | 37 tests; the 3 `PortalTest` errors need the test-only `test_approved_brand` fixture, which is installed only in disposable databases and was not added to the local one. |
| `acceptance` `--filter 'OnboardingSecurityTest\|ProfessionalHubTest\|PlatformGovernanceTest'` | 25 tests, 572 assertions passed. One earlier run had one failure while browser tests shared the database; it did not recur. |
| `php artisan test --compact` | 53 tests, 589 assertions passed. |
| `composer validate --strict`, `pint --test`, `format:check:changed`, `typecheck`, `npm test`, `build` | Passed; 164 component tests (new `MediaKit.test.ts`: 6). |
| `npm audit --omit=dev` | 0 vulnerabilities before and after the new packages. |
| `scripts/check-npm-audit.mjs` | Fails identically on `main`: the picomatch exception is stale. Flagged as a separate task. |
| Browser: media kit journey (`onboarding.spec.ts`), Professional presentation and tools (`professional-ui.spec.ts`), desktop and mobile, on the final image | 6 passed. They exercise bold formatting reaching the public page, labelled link creation, the revoked filter and the section switch. The media kit journey was rerun after the last mobile alignment change: 2 passed. |
| Browser: full `onboarding`, `professional-ui`, `professional`, `partner-navigation`, `ui-refinement`, desktop and mobile (image before the final mobile CSS fixes) | 14 passed, 2 failed. Both failures are `partner-navigation` expecting 6 account-menu icons instead of 7; that stale assertion predates this work and is fixed in PR #58. |

- **Rendering:** inspected at 1440, 1024 and 390px, including the link field open, the preview with a list and subheading, and the mobile Lead links section. Mobile follow-ups made after the first inspection: list bullets restored, a single-line toolbar, a two-row save bar, short header labels (full accessible names kept) and compact link rows. A desktop width rule had pushed the mobile Revoke button outside its row; fixed and covered by the mobile journey. Temporary captures were deleted.
- **Not verified:** WebKit/Safari, staging, manual screen reader, and the full PostgreSQL suite with `rls.sql` in a disposable project.
