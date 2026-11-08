# Repository Guidelines

## Project Structure & Module Organization

WeavDash is a Laravel application with Vue 3, TypeScript, Inertia, PostgreSQL, and Redis.

- `src/`: application root; backend code, `resources/js/`, `resources/css/`, migrations, tests, and dependency manifests.
- `planning/`: requirements, architecture, decisions, milestone contracts, status, and evidence. Start with `planning/README.md`.
- `docs/Creator_Brand_Platform_Business_Plan.pdf`: primary business reference.
- `mockup/index.html`: saved visual reference with embedded assets and font licenses; preserve those assets and notices.

Read the relevant milestone contract/status and inspect existing changes before editing. Preserve unfinished work from other sessions.

## Build, Test, and Development Commands

Run npm, Composer, and Artisan commands from `src/`; run Docker Compose from the repository root with `--env-file src/.env`. See [README.md](README.md) for setup and test prerequisites.

- `npm run format:changed`: apply Prettier to new/modified frontend files.
- `npm run format:check:changed`: verify that those files are formatted; also enforced in frontend CI.
- `npm run format -- <paths>` / `npm run format:check -- <paths>`: format/check explicit files, including already committed files in a milestone.
- `npm run typecheck`, `npm test`, `npm run build`: frontend types, component tests, and production build.
- `COMPOSE_PROJECT_NAME=weavdash npm run test:browser -- <spec> --project=desktop --project=mobile`: relevant browser journeys against the running local app.
- `rg --files planning docs mockup src/resources`: discover references before editing.

## Mandatory UI Standards for Every Milestone

**Confirmed:** these requirements apply to all current and future milestones, all contributors, and every Codex model/session. Include them in implementation, review, and handoff; do not defer UI quality to a later milestone.

- Align new and modified screens with the current approved UI. Inspect adjacent screens and reuse the existing palette, typography, spacing, cards, controls, focus styles, and responsive patterns in `src/resources/css/foundation.css`, `portal-theme.css`, and shared components. Follow [portal UI alignment](planning/m3a/ui-alignment.md) where applicable. Keep placeholders and empty/error/loading/disabled states consistent with the established design.
- Inspect `src/resources/js/components/`, `layouts/`, and feature components before adding markup. Reuse or extend existing components for repeated sections, navigation, fields, and groups. Extract repeated presentation when it has a shared responsibility; avoid copying entire blocks or creating competing styling systems. Current examples include `FormField`, `FieldGroup`, `ContentSection`, `FinanceLayout`, `FoundationShell`, and the shared UI button.
- Keep labels linked to controls, preserve keyboard/focus behavior and accessible feedback, and check desktop/mobile rendering. Keep business logic, validation, permissions, and data contracts intact during UI-only work.
- **Confirmed:** destructive actions and actions that revoke access require modal confirmation. Use `confirmAction` with the shared `ConfirmationDialog`; identify the affected record and consequence, use an explicit action label and Cancel, initially focus Cancel, contain focus, support Escape and restore the trigger. Keep dialogs outside existing forms/drawers, prevent duplicate submissions, retain accessible server errors and close after success. Preserve required reasons, password checks and record versions. Routine saves, navigation and reversible unsaved edits do not require confirmation; a save that unpublishes shared content does.
- Apply the pinned Prettier version and `src/.prettierrc.json` to every new or modified supported frontend file. Use four-space indentation, including Vue files, consistently with `.editorconfig`. Do not hand-format around Prettier, add ignore directives to bypass it, or weaken the formatting gate to make a change pass. Avoid unrelated formatting churn.
- Before marking a UI task or milestone complete, run formatting checks, type checking, the build, and relevant existing interaction/browser tests. Inspect rendering for visible changes and record commands, results, viewport sizes and visual findings in the milestone handoff. Report any unverified gate explicitly.
- **Confirmed (2026-11-08):** do not retain new screenshot/image evidence by default. When screenshots help visual inspection or failure diagnosis, use temporary files outside the repository and remove them after inspection. Configure captures in tests being run or updated to follow this policy rather than writing images into `planning/evidence/`. Keep concise text verification records; retain images only when explicitly requested. This supersedes older screenshot-retention requirements in planning documents. Preserve existing evidence images and product/reference assets unless cleanup is explicitly requested.

CI checks formatting automatically; visual alignment and sensible component reuse also require review. A passing formatter alone does not complete the UI acceptance gate.

## Coding Style & Documentation

Use concise Markdown, descriptive headings, relative links, and lowercase hyphenated document names such as `api-design.md`. Retain the evidence labels **PDF**, **Mockup**, **Confirmed**, **Proposal**, and **Assumption / Open**; distinguish proposals from approved decisions.

**Confirmed planning organization (2026-11-08):** keep all milestone topics together under `planning/<milestone>/<topic>.md`, for example `planning/m1/status.md`, `planning/m2/pipeline-contract.md` and `planning/m5/schedules-evidence.md`. Use `status.md`, descriptive `*-contract.md` names, `implementation-handoff.md` where needed, and `evidence.md` or `*-evidence.md` for verification. Do not repeat the milestone prefix in filenames or create a parallel Markdown hierarchy under `planning/evidence/`. Put cross-milestone topics under `planning/shared/` and separately approved workstreams under `planning/administration/` or `planning/professional/`; do not invent milestone ownership. Future milestones follow the same structure. Update the [planning index](planning/README.md), [evidence index](planning/evidence.md) and relevant status/handoff links when adding or moving topics. If an older skill or handoff names a flat planning path, discover its current location through these indexes. Existing non-Markdown evidence artifacts remain under `planning/evidence/`; retain images only under the explicit-request policy above. Keep verification reports concise: scope/date/revision, commands and results, visual findings where relevant, and unresolved gates.

Prettier, TypeScript/vue-tsc, Laravel Pint, PHPUnit, Vitest, and Playwright are installed. PHP static analysis remains a proposal. Use the existing tooling rather than introducing another formatting system.

## Testing Guidelines

Verify relative links and consistency across architecture, scope, decisions, and roadmap for documentation edits. For UI edits, inspect rendering and affected interactions. Tenant isolation must be tested against real PostgreSQL RLS; consult [implementation roadmap](planning/shared/implementation-roadmap.md) for milestone acceptance gates. No arbitrary coverage percentage is prescribed.

## Commit & Pull Request Guidelines

Use concise imperative subjects, for example `ui: reuse finance form sections`. PRs should describe the change, affected areas, validation, and related issue/decision. For UI changes, identify reused/extracted components, confirm Prettier checks, summarize desktop/mobile inspection and viewport sizes, and identify unresolved assumptions or unverified gates. Screenshots are optional and retained only when explicitly requested.
