# Lead activity tab evidence

**Scope (2026-11-21):** the Activity tab of the lead drawer (My leads and the Professional client leads page), following the user-approved Option A mockup (collapsed composer at the top). Branch `ui/activity-tab` from `origin/main` at `6d5af4d`. Local Compose stack (`weavdash`) with rebuilt `app`, `worker` and `scheduler` images; Chromium only.

## Change

- **Composer:** a one-line "Log a call, email, chat or note… / Log activity" button (with `aria-expanded`) opens the existing form.
  - The form has the type radio chips, details, the reply hint, and **Cancel** and **Record activity**.
  - Focus moves to the chosen type.
  - Escape or Cancel closes the composer without closing the drawer, drops the draft and returns focus to the button. It also closes after recording.
  - The reply confirmation is unchanged.
- **History:**
  - Grouped by local day: Today, Yesterday, then short dates, with the year when not this year.
  - A time on each entry and a rail joining the day's entries.
  - **Conversations** (note, call, email, chat, reply received) are cards with their type and time.
  - **Changes** are one muted line in plain language: "Stage Pitched → Negotiating" (stage names, not stored values), "Next action set to Sat, Nov 28, 9:00 AM" (the viewer's local time, not a UTC timestamp), "Details updated" and "Lead captured". Unrecognised text is shown as stored.
  - Each type and change has its own icon.
- **Filter:** All, Conversations and Changes chips with counts (shared `FilterChips` toggle buttons), with an empty message per filter.
- **End of list:** "Start of this lead's history", or "Showing the latest 50 events." only when the server limit is reached (was a permanent "Most recent 50 events").
- **Header:** "Updated …" moves beside the Contacts & reminders pill, so the stage and value line no longer wraps it onto its own line.
- **Phone:** 44px composer, chip and button targets; Cancel and Record share the width.
- **Reused/extracted:** a new `features/pipeline/activity.ts` holds the conversation and change grouping, the change wording, and the day and time labels. `FilterChips`, `Button` and Lucide icons are reused.

## Backend and data contract

None. The drawer rewords the server's existing fixed sentences (`Pipeline::change`) for new and older entries alike.

## Verification

| Command | Result |
| --- | --- |
| `format:check:changed`, `typecheck`, `npm test`, `build` | Passed; 198 component tests. The new `LeadActivity.test.ts` (3 tests) covers grouping, change wording with stage names and local time, the stored-text fallback, and day labels and grouping. `LeadDrawer.test.ts` gained 3 tests: the composer opening, Escape staying in the composer with focus return, day groups, filters and plain change text, and the 50-event note. |
| Browser `pipeline`, `logged-reply`, `ui-refinement`, `pipeline-drag`, `professional-ui`, `onboarding`, desktop and mobile | 24 passed. `pipeline` and `logged-reply` now open the composer with Log activity before recording. |

- **Rendering:** inspected at 1440 × 900, 1024 × 768 and 390 × 844, with eight entries (call, two stage changes, email, next-action change, chat, note, created). Also checked the open composer and the Changes filter. No horizontal overflow. One spacing issue before change times was found and fixed. Temporary captures were deleted.
- **Not verified:** WebKit/Safari, staging and a manual screen reader. There is no "load older events"; the server still returns the latest 50.
