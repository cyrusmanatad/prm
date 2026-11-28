# Incentives and campaign lead links — verification

Scope: [D43](../shared/decisions.md#d43--source-campaign-on-lead-registrations) source campaigns on registrations and [D44](../shared/decisions.md#d44--bring-brand-incentives-forward) Brand incentives, as set out in the [contract](incentives-contract.md). Date: 2026-11-28. Branch `feat/incentives` from `main` at `c00b015`. Local only; not deployed.

## Commands and results

| Check | Result |
|---|---|
| `npm run format:check:changed` | 34 changed frontend files pass |
| `npm run typecheck` | Pass |
| `npm test` | 44 files, 260 tests pass |
| `npm run build` | Pass |
| `vendor/bin/pint --test` | Pass |
| `php artisan test` (unit/feature) | 61 tests pass |
| `phpunit -c phpunit.pgsql.xml` (integration) | 45 tests pass |
| Full acceptance suite, fresh PostgreSQL database, runtime role, mail credentials cleared | 270 tests, 6,557 assertions, 3 skipped |
| Migrations `2026_12_01_000001`–`000002`: up, rollback and up again | Pass |
| Browser: `incentives`, `campaigns`, `partner-navigation`, `brand-lead-visibility` at desktop and mobile | 10 journeys pass, axe reports no violations on checked pages and dialogs |

`IncentiveTest` (acceptance) covers:

- **Links:** a link to a draft, another partnership's brief or an unknown campaign is refused; the composite foreign key refuses a cross-partnership link even for the migration role; link time and audit on set and clear; an edit without the field keeps the link; withdrawal keeps it as Partner history and hides it from the Brand; the campaign filter and drawer.
- **Offers:** drafts are private; sharing validates every field, the audience and caps; stale versions return 409; shared terms are read-only; only the audience sees the offer, without the total cap; the runtime role cannot write the tables; ending is audited.
- **Awards:** rule, stage and leads-per-award checks; one open or granted award per registration and incentive; reasons for a changed amount and for rejection; the per-Partner cap counts open and granted claims; rejection frees the lead; reversal; fulfilment date checks; audit events.
- **Lifecycle:** withdrawal cancels pending claims; ending the relationship keeps approved awards for the Brand and hides everything from the Partner; export and closure inventory include all four tables.
- **Campaign incentives:** only leads with that source campaign qualify; an open award locks the link; approval sets the Brand confirmation.
- **Concurrency:** two approvals queued behind a held incentive lock with a total cap of 1 produce exactly one approval; the other is refused with the cap message. The lock wait was observed in PostgreSQL, not assumed from timing.

## Visual inspection

Chromium at 1440×900 and 390×844, captured to temporary files outside the repository and deleted after review: the incentive form with its error summary and preview, the shared incentive record, the Brand list on the Awards tab, the award side panel, the Partner incentives page and the claim dialog.

- Fixed during review: four figures stacked one per row on phones (now two per row); the Currency field stretched beside an Amount field showing an error.
- Fixed during review: the Brand offer filter prop was named `status` and replaced flash messages with "all"; it is now `offerStatus`.
- Follow-up (2026-11-28): Partner offer cards keep a one-third width at 1360px and wider (two per row below, one on phones). The card was restyled to the approved reference: the Claim button sits beside the title, with a lock icon while disabled and linked to its reason; the reward has a tinted panel; rules use filled check badges; the cap and terms are a pill and a dashed box; the footer shows lead readiness with a progress bar. Checked at 1280×720, 1440×900 and 390×844; the incentives journey passes on desktop and mobile.
- Follow-up (2026-11-28): My Leads **Rewards earned** sits beside **Open pipeline** and replaces **Reply rate**; covered by `tests/Frontend/PartnerRewardsCard.test.ts` and the `pipeline.rewards` assertion in `IncentiveTest`.
- Follow-up (2026-11-28), per the reviewed [incentive form mockup](../../mockup/incentive-form/index.html): the shared form dialog now loads its own styles (on Incentives, Campaigns and the Partner claim dialog its header, close button and footer had lost their layout unless `records.css` was already loaded); Currency is the searchable currency select from the Add lead form; Partners is a new searchable `MultiSelectField` with chips, Select all/Clear and Done (locked to the campaign's Partner when a source campaign is chosen); the "What Partners see" preview is the shared `OfferCard` used on the Partner page, with an inert Claim button and sample readiness. Select lists (single and multiple) now open attached to their trigger with no gap, even when a hint sits below the field; text fields, select triggers and filter search boxes show an accent border with a soft accent glow on focus instead of the green ring (red border and glow while invalid), and select search boxes a thin accent border. The Add lead currency list stays a separate card because it is wider than its trigger. The Brand incentive record's offer panel became "What Partners see" with the shared offer card (sample readiness; "This offer has ended" once ended) and an all-Partners approved total below it; the offer button now reads **Claim**, with the offer title added for assistive technology. Both campaign record incentive panels use the shared offer card too: the Brand sees the Partner's card (inert Claim, sample readiness) with its status, an Open incentive link and its approved/pending counts; the Partner gets the live card, whose Claim button opens the offer on the Incentives page where the claim dialog lists qualifying leads. The last eight native selects (Lead registrations and Contacts rows per page, Contacts Sort and Lead links, Brand sharing Brand filter, the new onboarding request Lead, and Outreach templates Template and Fill from a lead) now use `SelectField`, which gained a placeholder, a compact toolbar size and inline or hidden labels; the Lead registrations filters widened to 230px so Partner names and counts fit on one line. Brand lead visibility, CRM, onboarding, onboarding lifecycle, outreach templates and UI refinement journeys pass on desktop and mobile. Checked at 1280×720 and 390×844; incentives, campaigns and Brand lead visibility journeys pass on desktop and mobile.
- Observation, not changed: after the award panel closes on a phone, flash toasts can extend past the right edge while they animate. This comes from the shared toast container, not this change.

## Unverified gates

- WebKit and Safari were not run for these journeys.
- No screen-reader pass beyond axe and the focus checks in the journeys.
- Claim dates use the database calendar day in UTC (**Assumption** in the contract).
