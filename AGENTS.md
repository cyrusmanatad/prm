# Repository Guidelines

## Project Structure & Module Organization

WeavDash is currently a planning repository with a saved UI prototype; application implementation has not started.

- `planning/`: requirements, architecture, database/API designs, decisions, and delivery roadmap. Start with `planning/README.md`.
- `docs/Creator_Brand_Platform_Business_Plan.pdf`: primary business reference.
- `mockup/index.html`: bundled prototype with embedded assets and font licenses.
- `backend/` and `frontend/`: empty placeholders, not separate applications.

The approved future stack is Laravel/PHP, Vue 3 + TypeScript + Inertia, PostgreSQL, and Redis. The proposed layout uses `app/`, `resources/js/`, `database/migrations/`, and `tests/` in one Laravel application. Do not create this scaffold without an implementation request.

## Build, Test, and Development Commands

No application dependency manifests, build scripts, automated tests, or CI configuration exist yet.

- `python3 -m http.server 5500 --bind 127.0.0.1`: serve repository files locally; visit `/mockup/` to inspect the prototype. Use only for local preview.
- `rg --files planning docs mockup`: list reference files before editing.

Do not assume `npm test`, Composer, or Docker Compose commands are available. Document executable commands when their configuration is added.

## Coding Style & Naming Conventions

Use concise Markdown, descriptive headings, relative links, and lowercase hyphenated document names such as `api-design.md`. Match surrounding indentation; use two spaces for any new HTML indentation. Preserve embedded asset data and license notices in the saved prototype.

Retain the planning evidence labels: **PDF**, **Mockup**, **Confirmed**, **Proposal**, and **Assumption / Open**. Keep proposals distinguishable from approved decisions. Laravel Pint, PHP static analysis, and frontend lint/format tooling are proposed, not installed.

## Testing Guidelines

For documentation changes, verify relative links and consistency across architecture, scope, decisions, and roadmap. For prototype edits, inspect browser rendering and affected interactions.

Future testing proposals include Pest/PHPUnit, Vitest with Vue Test Utils, and Playwright. No coverage percentage is prescribed. Tenant isolation must eventually be tested against real PostgreSQL RLS; consult `planning/implementation-roadmap.md` for acceptance gates.

## Commit & Pull Request Guidelines

Git history is unavailable in this checkout, so no existing commit convention can be verified. Use concise imperative subjects, for example `docs: clarify onboarding scope`.

PRs should describe the change, affected documents, validation performed, and related issue or decision. Include screenshots for visible prototype changes and identify unresolved assumptions explicitly.
