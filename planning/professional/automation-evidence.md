# Automation and approvals evidence

**Scope (2026-11-21):** the Automation page, which Partner (`/automation`) and Professional (`/professional/automation`) share. This follows the user-approved Option A mockup (tabs). Branch `ui/automation` from `origin/main` at `7cd45e4`. Tested on the local Compose stack (`weavdash`) with rebuilt `app`, `worker`, `scheduler` and `acceptance` images, in Chromium only.

## Change

- **Header:** a 28px title (24px on phones) with a one-line subtitle, **New schedule** and the brand-filled **Write follow-up**. A one-line note explains where emails go. Viewers see a read-only note and no create actions.
- **Tabs** (shared `Tabs`): Approvals, Schedules, Email history and Preferences. The tab is kept in the address (`?tab=`), so reload, Back and saved changes return to the same view. Approvals shows the draft count plus an "n to review" warning badge. On phones the tabs scroll on one row.
- **Approvals:**
  - Filter chips: Needs review, Approved, Closed and All. The list opens on Needs review when anything is waiting.
  - A compact table with the message and a one-line preview, the lead, the recipient, a status badge with its version, one main action (Approve, or Send for an approved draft that hasn't been sent) and a row menu (Open, Edit, Download approved message, Discard).
  - On phones, rows become two-line records.
  - A title opens a side drawer at `?draft=…` with the status line, lead, recipient, how the draft was prepared, the full message and the actions.
  - Edit works inside the drawer, with "Unsaved changes", Discard and Save draft, which stays disabled until something changes.
  - Approve, Send and Discard keep their existing Cancel-first confirmations and wording.
- **Write follow-up:** a dialog (lead, recipient, subject, message). The saved draft then opens in its drawer for review.
- **New schedule:** one dialog with a Weekly digest / Reminder email / Follow-up draft switch, replacing the three forms that were always open. Before anything is sent, it explains three blocked cases:
  - The notice type is off in Preferences; the dialog offers **Open preferences**.
  - An active weekly digest already exists.
  - There is no open reminder or active lead to choose.

  Edit opens the same dialog for a weekly digest. Field errors appear under fields (`novalidate`).
- **Schedules:** a table showing each schedule's name with what it is for (its weekday and time, or the reminder or lead it covers), the type, the next run in its own timezone, a status badge, and Edit / Withdraw. Withdraw keeps its confirmation.
- **Email history:** a table with plain-language status badges. A deferred attempt shows a note.
- **Preferences:** a timezone field and four switches with one-line descriptions, plus a sticky "Unsaved changes" footer with Discard and Save. Save is disabled until something changes. Turning a notice off still asks for confirmation.
- **Components:**
  - New: `DraftTable`, `DraftDrawer`, `FollowUpDialog`, `FollowUpFields` and `ScheduleDialog`.
  - Rewritten: `SavedSchedules`, `NoticeHistory` and `AutomationPreferences`.
  - Removed: `ApprovalInbox`, `FollowUpComposer` and `ReminderSchedule`.
  - Reused: `Tabs`, `DataTable`, `FilterChips`, `RowActionMenu`, `StatusBadge`, `EmptyState`, `DialogClose`, `containDialogFocus` and `confirmAction`.
  - Styles live in `resources/css/automation.css`, renamed `records.css` on 2026-11-21 when Finance began sharing them (see [Finance evidence](finance-evidence.md)).

## Backend contract

- **`AutomationController::index`:**
  - Drafts gain `lead_name`.
  - Schedules gain `subject_label` (a reminder's title) and `subject_lead` (a follow-up lead's name).
  - Both come from same-tenant subqueries under the existing row-security policies. No other fields are added.
- **New `Controller::automationReturn()`:** every automation mutation (preferences, schedule save and withdraw, draft save, approve, send and discard) returns pages to the automation address they came from, keeping only a valid `tab` and a UUID `draft`. Any other referer returns to `/automation`. Saving a new draft returns with `?draft=<id>`, which opens it. JSON responses are unchanged.
- No migration, route or permission change.

## Verification

| Command | Result |
| --- | --- |
| `acceptance` `--filter 'BusinessScheduleTest\|FollowUpDraft\|DeliveryOperations\|LoggedReply'` | 37 tests passed, including the new `test_automation_page_names_schedule_subjects_and_returns_to_the_same_view`. It checks the name fields, keeping the tab and draft, the Professional path, dropping unknown values and foreign pages, and opening a new draft. |
| `acceptance` over the 10 suites touching `/automation` | 93 of 94 passed (3 skipped). `SyntheticRecoveryTest::test_smtp_disconnect_after_data_is_uncertain…` fails the same way on `main` at `7cd45e4`. It is an existing SMTP-sink environment failure, unrelated to this branch. |
| `pint --test` | Passed, 276 files. |
| `format:check:changed`, `typecheck`, `build` | Passed. |
| `npm test` | 37 files, 210 tests passed. The new `Automation.test.ts` covers tabs and the address, the drawer opened from the address, filters, confirmed Approve, the edit flow and its request, blocked schedule types and the exact reminder instant, unsaved preferences with confirmed withdrawal, and the viewer state. |
| Browser `automation`, `follow-up-drafts`, `approval-basis`, `scheduled-delivery`, `delivery-operations`, `logged-reply`, `professional-ui`, `professional`, `portals`, `partner-navigation`, desktop and mobile | All passed across the final runs: 26, 2 and 4 tests (the reruns cover the fixes below). These specs were updated for tabs, dialogs and the drawer (new helpers `automationTab`, `newSchedule`, `writeFollowUp`). They keep axe, overflow and 44px touch checks, every confirmation's Cancel focus, Escape and return, the download content and the version checks. |

The first browser run had three findings, all fixed:

- Focus went back to New schedule instead of the Preferences tab after **Open preferences**.
- Draft titles in phone records were 19px tall; they are now 44px targets.
- A touch check measured the success toast's close button; the test now dismisses notifications first, as other specs do.

## Rendering

- **Inspected:** 1440 × 900, 1024 × 900 and 390 × 900 for Professional (approvals, the drawer, schedules, preferences with unsaved changes, and the New schedule dialog with a type turned off), plus the Partner page at 1440 and 390.
- **Fixed during inspection:**
  - The content was inset inside the Professional shell.
  - Tabs wrapped onto two rows on phones.
- No horizontal overflow at any width. Temporary captures and specs were deleted.

## Not verified

WebKit/Safari, staging, a manual screen reader, and the full PostgreSQL suite with `rls.sql`. Sending was exercised against the local capture transport only.
