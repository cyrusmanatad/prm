# Lead drawer evidence

**Scope (2026-11-21):** the lead drawer that opens from My leads (`/partner/leads?entry=…`, and the Professional client leads page), following the user-approved Option B mockup (one short Details view with status rows). Branch `ui/lead-drawer` from `origin/main` at `ac291c0`. Local Compose stack (`weavdash`) with rebuilt `app`, `worker`, `scheduler` and `acceptance` images; Chromium only.

## Change

- **Header:**
  - Name, stage chip, formatted value, "Updated …" time, and a "View only" tag for viewers.
  - A small **Delete** button named "Delete lead" with the existing danger confirmation, shown only when the lead can be deleted (owners and admins, no onboarding history).
  - **Contacts & reminders** becomes a small pill link.
  - Tabs are Details and Activity, with an activity count.
- **Details:** the same grouped fields as Add lead (`EntryFields`), with Stage beside the name.
  - Contact fields are now labelled "Contact name" and "Contact email" (were "Name" and "Email").
  - Follow-up shortcuts and private notes come from the shared component.
  - The date-time field stays inside its column, and the form has no browser validation bubbles.
- **Status rows:**
  - **Onboarding:** the latest request's status (Invited, Awaiting review, Correction requested, Accepted, Revoked) and start date, with **Open** to the request. Without a request it shows **Start onboarding…**, which opens a dialog with the existing email and submission-notice options, outside the drawer, with focus returned to the trigger. A lead with onboarding history explains that it is kept, matching the server's existing refusal to delete it.
  - **Brand sharing:** Private, Shared or Paused, with the Brand and the date shared (or a count), and **Share with a Brand** or **Manage sharing** (was "Register with a Brand", which always claimed the lead was private).
- **Footer:**
  - "Unsaved changes" with **Discard** while edits are pending; "No unsaved changes" otherwise.
  - **Save lead** is enabled only when something changed, including Stage.
  - The earlier inline onboarding form, Brand sharing card and Delete disclosure are removed from the scroll.
- **Activity:**
  - A compact composer: the five real types (Note, Call, Email, Chat, Reply received) as a radio group of chips, details and Record activity. The reply confirmation is unchanged.
  - The timeline shows an icon for each type.
- **Viewers:** a labelled summary (Contact, Next action, Private notes) and the status rows, with no edit, delete or start actions.
- **Phone:** single-column fields, 44px header, tab, chip and status controls, and full-width Discard and Save.
- **Reused:** `EntryFields` (with a new `stage` slot), `StatusBadge`, the onboarding status names and `ago()` from `features/onboarding/requests.ts`, `OnboardingEmailFields`, `OnboardingSubmissionNotice`, `DialogClose`, `containDialogFocus` and `confirmAction`. 26 unused drawer style rules were removed.
- **Kept:** the amount field still accepts plain digits only. `toMinor` deliberately rejects thousands separators (`PipelineMoney.test.ts`); the header shows the formatted value.

## Backend and data contract

`Pipeline::detail` adds three fields; there is no migration, route, permission or action change.

- `updated_at`.
- `onboarding`: `{ id, status, created_at }` of the latest request, or null. No token or form data.
- `sharing`: `[{ brand_name, paused, registered_at }]` for active registrations.

## Verification

| Command | Result |
| --- | --- |
| `app` `phpunit.pgsql.xml --filter PipelineTest` | 9 tests, 158 assertions passed. The new test checks the onboarding field's shape (no token) and that deletion is still refused with onboarding history. The capture test checks the empty `onboarding`, empty `sharing` and `updated_at`. |
| `acceptance` `--filter BrandLeadVisibilityTest` | 10 tests, 564 assertions passed. A shared lead lists its Brand (name, paused, date only), a private lead lists none, and viewer, member and manager all receive the status. |
| `pint --test`, `format:check:changed` (12 files), `typecheck`, `npm test`, `build` | Passed; 192 component tests. The new `LeadDrawer.test.ts` (5 tests) covers the header and confirmed delete, Save and Discard with unsaved changes, both status rows and kept leads, the onboarding dialog with focus return, the activity type and the viewer summary. |
| Browser `pipeline`, `ui-refinement`, `logged-reply`, `onboarding`, `onboarding-lifecycle`, `onboarding-notifications`, `professional-ui`, `professional`, `brand-lead-visibility`, `pipeline-filtering`, `pipeline-drag`, `crm`, desktop and mobile | 40 of 42 passed. `logged-reply` used the old type dropdown; it now checks the radio, and the chips became a named radio group whose inputs cover the chips. Rerun of `logged-reply` and `pipeline`: 6 passed. |

- **Spec updates:** 8 browser specs.
  - `pipeline` now checks Save disabled after saving, Unsaved changes and Discard, and Delete as a button.
  - `ui-refinement` checks Delete and Close keyboard order and a 44px Delete on phones.
  - Four onboarding specs open the Start onboarding… dialog.
  - `brand-lead-visibility` follows Share with a Brand.
  - `logged-reply` picks the reply type as a radio.
- **Rendering:** inspected at 1440 × 900 (about one screen of details), 1024 × 768 and 390 × 844, plus the onboarding dialog, the Activity tab after recording a call, and the viewer drawer. No horizontal overflow. Two issues found and fixed: the date-time field overlapping the notes column, and a smaller "Estimated value" label. Temporary captures were deleted.
- **Not verified:** WebKit/Safari, staging, a manual screen reader, and the full PostgreSQL suite with `rls.sql`.
