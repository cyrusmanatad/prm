# Outreach templates evidence

**Scope (2026-11-21):** the Outreach templates page (`/partner/outreach-templates`, `/professional/outreach-templates`), following the user-approved compact mockup. Branch `ui/outreach-templates` from `origin/main` at `e97cf17`. Local Compose stack (`weavdash`) with rebuilt `app` and `acceptance` images; Chromium only.

## Change

- **Layout:** breadcrumb, 28px title with the template count, and New template in the header. Three columns at 1100px and wider: saved templates, the editor, and the draft. Below 1100px, a Templates / Edit / Draft switch shows one panel at a time, and the Draft panel gains a template picker.
- **Saved templates:** client-side search, plus each template's subject and last update. The selected row is marked. Each row's button names only the template, with the subject and date as its description, and its hit area covers the whole row.
- **Editor:**
  - Subject and body character counters.
  - Insert buttons for the three placeholders, which insert at the caret of the field used last (subject or body).
  - New shared `PlaceholderTextarea`: a real plain-text textarea over a mirror that only paints highlights. Known placeholders are tinted; unknown ones are amber and underlined.
  - Duplicate, which opens an unsaved copy, and Copy template text.
  - A sticky save bar with Delete template (still confirmed), Unsaved changes, Discard and Save.
  - Viewers can still edit locally to prepare a draft but cannot save.
- **Draft:**
  - **Fill from a lead:** a choice of up to 50 recently updated leads, excluding lost ones, that fills the brand and contact names.
  - **Live preview:** filled values in green and missing ones in amber, with a sentence saying what still needs adding. The old Generate step is gone.
  - **Actions:** Copy draft, Open in email app (`mailto:` to the chosen lead's contact email, only when complete) and Edit before copying. That last one opens the previous editable draft fields, and changing the template or values returns to the preview.
  - **Unchanged:** drafts are never saved, and merging stays a single literal pass (`mergeText`). The new `mergeSegments` helper splits the same merge into plain, filled and missing parts for the preview.

## Backend and data contract

- `OutreachTemplates::listing` adds `updated_at` (ISO 8601) to each template.
- `OutreachTemplates::draftLeads` and the new page prop `leads` return `{id, brand_name, contact_name, contact_email}`. They are read inside the workspace context under the existing pipeline RLS, so every member who can read the pipeline can see them. Lost leads are excluded.
- No migration, route, or permission change.

## Verification

| Command | Result |
| --- | --- |
| `phpunit -c phpunit.pgsql.xml --filter OutreachTemplateTest` (one new test: own recent open leads only, ordering, update times) | 4 tests, 40 assertions passed. |
| `acceptance` `--filter OutreachTemplateRoleTest` | 1 test, 25 assertions passed. |
| `pint --test`, `format:check:changed`, `typecheck`, `npm test`, `build` | Passed; 175 component tests (new `OutreachTemplates.test.ts`: 6). |
| Browser `outreach-templates`, `professional-ui`, `ui-refinement`, `partner-navigation`, desktop and mobile | All passed, including the final rerun after the last mobile CSS change (4 passed for the outreach and Professional tools journeys). The outreach journey covers inserting at the caret, highlighting, Discard, the missing-value preview and Edit before copying. |

- **Accessibility:** axe flagged `label-content-name-mismatch` on the first list-row design, where each row button carried an `aria-label`. Rows now use the stretched-button pattern described under Change; the Professional journey's axe scan passes.
- **Rendering:** inspected at 1440, 1024 and 390px, with a typo placeholder, a partly filled lead and the mobile Draft panel. Mobile follow-ups made after the first inspection: the template picker label now stacks above its field, the clear-lead control no longer stretches, and the lead picker matches the other input heights. Temporary captures were deleted.
- **Not verified:** WebKit/Safari, staging, manual screen reader, and the full PostgreSQL suite with `rls.sql` in a disposable project.
