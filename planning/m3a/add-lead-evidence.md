# Add lead evidence

**Scope (2026-11-21):** the Add lead dialog on My leads (`/partner/leads`, and the Professional client leads page), following the user-approved Option A mockup (compact centred dialog). Branch `ui/add-lead` from `origin/main` at `8fa1b66`. Local Compose stack (`weavdash`) with rebuilt `app`, `worker` and `scheduler` images; Chromium only.

## Change

- **Layout:** a 600px dialog with a fixed header and footer; the fields scroll between them when needed. It fits at 1440 × 900 without scrolling. On phones it opens as a full-screen sheet anchored to the device viewport, with 44px controls and full-width Cancel and Add lead buttons.
- **Header:** "Add lead" with "Private to your workspace · starts in Lead" and the stage chip.
- **Fields:** the same groups as the lead drawer.
  - The Brand name (Client name in Professional workspaces).
  - Estimated value: optional and blank by default, with a currency picker (the drawer's list) beside a right-aligned amount.
  - **Primary contact (optional):** contact name and email side by side.
  - **Follow-up (optional):** the next action with Tomorrow, In 3 days and Next week shortcuts, beside private notes.
- **Share with a Brand:** the optional Brand picker sits in a tinted box with a one-line note on what the Brand sees, shown only when a connected Brand can be chosen (as before).
- **Validation:** browser validation bubbles are off.
  - A missing name shows "Enter the Brand name." (or "client name") under the field and focuses it without sending a request.
  - Other fields keep the server's field errors. A blank value is sent as zero.
- **Footer:** "Only the Brand name is required.", Cancel and **Add lead** (was "Capture lead", now matching the trigger).
- **Reused/extracted:** new `leadFields.ts` holds the currency list, currency names, local date-time conversion and follow-up shortcuts, now shared by the Add lead form and the lead drawer. `EntryFields` became the sectioned Add lead fields. `FormField`, `SelectField`, `DialogClose` and the drawer's stage chip are reused.

## Backend and data contract

None. `POST /pipeline` receives the same fields; `register_with` is still sent only when a Brand is chosen.

## Verification

| Command | Result |
| --- | --- |
| `format:check:changed`, `typecheck`, `npm test`, `build` | Passed; 187 component tests. `PipelineMove.test.ts` has a new test: browser validation off, blank value sent as zero, missing name explained without a request, Tomorrow fills 9:00 local time. The Brand choice test was updated for the new label. |
| Browser `pipeline`, `logged-reply`, `onboarding-lifecycle`, `onboarding-notifications`, `onboarding`, `professional`, `professional-ui`, `brand-lead-visibility`, `ui-refinement`, `crm`, `partner-navigation`, desktop and mobile | First run: 34 passed and `pipeline` failed on both projects. The "Optional" tag inside the value label changed the label text, so it moved beside the label. Rerun of `pipeline`, `portals`, `partner-navigation`, `join-links`, `notifications` and `administration`: 20 passed. |

- **Spec updates:** 10 browser specs now use "Add lead", "Share with a Brand (optional)", "No Brand — keep private" and the "Private notes" label.
- **Rendering:** inspected at 1440 × 900 (fits, no inner scroll), 1024 × 768 (fields scroll, footer stays) and 390 × 844 (full-screen sheet), empty and with errors. No horizontal overflow. Temporary captures were deleted.
- **Not verified:** WebKit/Safari, staging and a manual screen reader.
