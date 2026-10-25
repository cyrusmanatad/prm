# UI refinement — 2026-10-25

## Approved scope and design read

**Confirmed:** the user approved the audit proposal before implementation. This is a brand-preserving refinement of the existing creator/brand SaaS, using the requested design-taste-frontend skill's audit and preservation workflow. Preserve burgundy `#8e1650`, cream `#fbf7ef`, Manrope body text, Bricolage Grotesque headings, the logo, navigation, field order and existing business behavior. Keep the established quiet motion and working density; do not apply marketing-page effects or a replacement dashboard system.

The audit inspected current components and styles, the [prior review](../ui-review-2026-10-24/README.md), and its desktop/mobile screenshots before proposing changes. Existing uncommitted backend, package, notification and frontend work was present at entry and was preserved. This session edits frontend presentation, UI interaction/accessibility, tests and this handoff only; it does not edit backend code, APIs, schemas or business rules.

## Findings and implementation

| Finding                                                    | Result                                                                                                                                                         |
| ---------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Adjacent CRM actions ran together                          | Shared `ActionGroup` supplies wrapping, consistent gaps and touch targets; linked-contact actions use the same grouping.                                       |
| Template editor touched the draft section                  | A shared section stack separates sections; draft preparation reuses `ContentSection` and retains `draft-title`.                                                |
| Destructive triggers competed with saves                   | CRM/template triggers use the existing danger palette with an outline treatment; final confirmation remains solid danger.                                      |
| Button utilities overrode secondary styling                | The shared button omits its default utility color variant when foundation semantic classes supply the appearance. Explicit caller variants remain available.   |
| Add lead lacked a visible footer Cancel                    | Add Cancel beside Capture lead, reuse the shared dialog surface/header/footer and existing labelled close control; retain the separate drawer geometry.        |
| Form legends lacked section hierarchy                      | Match visible top-level form legends to existing section heading typography.                                                                                   |
| Workspace security markup was duplicated                   | Extract `AccountSecurity`, keeping the same password model, errors, pending state, confirmation and parent handler in both workspace states.                   |
| Finance columns became cramped on tablets                  | Collapse at the existing 800px form breakpoint and allow grid children to shrink. Group invoice actions.                                                       |
| Keyboard select focus and committed value diverged         | Synchronize focused options, arrow/Home/End navigation and Enter selection; expose the active option on the focused search while preserving text-editing keys. |
| Dialog traversal included hidden disclosure controls       | Include summaries and exclude closed disclosure content, hidden/inert nodes and disabled-fieldset controls.                                                    |
| Account and lead-card names omitted visible content        | Derive accessible names from rendered content with a screen-reader-only action prefix.                                                                         |
| Toast timers were outside a landmark                       | Name each mounted notification container, including containers recreated during promise feedback.                                                              |
| Immediate clipboard completion left pending feedback stuck | Wait until the loading toast opens before updating feedback; preserve the caller's original clipboard promise and copy payload.                                |

Reused components/patterns: shared `Button`, `DialogClose`, `FormField`, `SelectField`, `ContentSection`, `FoundationShell`, existing two-column grids and `confirmAction`/`ConfirmationDialog`. Added `ActionGroup` and extracted `AccountSecurity`. No new dependencies or assets.

## Verification

| Gate                             | Result                                                                                           |
| -------------------------------- | ------------------------------------------------------------------------------------------------ |
| `npm run format:check:changed`   | Pass: 37 supported changed frontend files, pinned Prettier                                       |
| `npm run typecheck`              | Pass                                                                                             |
| `npm test`                       | Pass: 10 files, 42 tests                                                                         |
| `npm run build`                  | Pass: [build log](build.log)                                                                     |
| Responsive keyboard/layout test  | Pass: one Chromium journey; [log](responsive.log)                                                |
| Affected desktop/mobile journeys | Pass: 22 tests, 11 per viewport; [browser log](browser.log); Axe assertions retained and passing |
| `git diff --check`               | Pass                                                                                             |

The responsive test captures workspaces, CRM, templates, invoices, payments and Add lead at 1440, 1024, 768, 390 and 320px widths (1000px height). It checks document overflow, tablet column collapse, visible footer Cancel, primary/secondary color distinction, initial dialog focus, cancellation and trigger restoration. It also reproduces Tab/Enter selection on the visible workspace dropdown, checks open/closed lead-deletion disclosure traversal, and scans Add lead at each width plus the populated real pipeline with Axe. These scans pass without disabling rules.

Selected inspected responsive evidence:

| Screen           | Desktop                       | Tablet                       | Mobile                                               |
| ---------------- | ----------------------------- | ---------------------------- | ---------------------------------------------------- |
| Workspaces       | [1440px](workspaces-1440.png) | [768px](workspaces-768.png)  | [390px](workspaces-390.png)                          |
| CRM empty layout | [1440px](crm-1440.png)        | [768px](crm-768.png)         | [320px](crm-320.png)                                 |
| Templates        | [1440px](templates-1440.png)  | [1024px](templates-1024.png) | [390px](templates-390.png)                           |
| Payments         | [1440px](payments-1440.png)   | [768px](payments-768.png)    | [390px](payments-390.png)                            |
| Add lead         | [1440px](add-lead-1440.png)   | [1024px](add-lead-1024.png)  | [390px](add-lead-390.png), [320px](add-lead-320.png) |

Populated interaction captures were also inspected: [CRM desktop](crm-desktop.png), [CRM mobile](crm-mobile.png), [templates desktop](templates-desktop.png), [templates mobile](templates-mobile.png), [contact deletion confirmation](confirm-delete-contact-desktop.png) and [password-error confirmation on mobile](confirmation-password-error-mobile.png). The affected journeys retain permission, stale-version, exact-money, cancellation, duplicate-request, draft-preservation and relationship assertions.

Also inspected: [invoice actions at 1024px](invoices-1024.png) and the [populated production board at 390px](pipeline-populated-390.png). Full-page dialog captures include document content beyond the viewport; the native backdrop covers the viewport. These are synthetic local accounts, not production records.

Commands from `src/`:

```sh
npm run format:changed
npm run format:check:changed
npm run typecheck
npm test
npm run build
COMPOSE_PROJECT_NAME=weavdash npm run test:browser -- crm.spec.ts outreach-templates.spec.ts foundation.spec.ts confirmation-errors.spec.ts finance.spec.ts partner-navigation.spec.ts portals.spec.ts --project=desktop --project=mobile --output=/tmp/weavdash-ui-refinement-acceptance --reporter=list
COMPOSE_PROJECT_NAME=weavdash npm run test:browser -- ui-refinement.spec.ts --project=desktop --output=/tmp/weavdash-ui-refinement-responsive-acceptance --reporter=list
```

The [source checksums](source-sha256.json) identify the final changed UI/test sources. The host and running app build manifests both hash to `f347c4babca29de87450ab647f434624950a20cb9ab69d65541aef7b677265ab`.

The active local project is `weavdash`, not the older handoff's `weavdash-m1`. The app was rebuilt with `docker compose --env-file src/.env -p weavdash build app` and started with `up -d --no-deps --wait app`. Subsequent frontend corrections were built locally and copied with `docker cp src/public/build/. weavdash-app-1:/var/www/html/public/build/`. Sandbox-blocked commands were rerun with execution approval. The two unused `weavdash-m1` services briefly started during the initial project check were stopped; the active project's worker/scheduler were left running.

The initial browser runs identified the account-name, notification landmark, promise-feedback and button-style issues above. They also exposed a stale CRM contact-link selector and a responsive-test navigation race; these were corrected without weakening assertions. The inherited component version test now mocks router events and visits the existing Activity/Details tabs while retaining its draft-version assertions.

## Carry-forward limits

**Assumption / Open:** pipeline filtering, pagination, count/total and stage semantics are outside this UI-only refinement. UI-02 remains open; the production-board visual fixture and full portal-reference acceptance under UI-05 also remain open. Historical screenshots do not certify the current pipeline. M5/M6 and existing release gates remain unchanged.

Native Safari, Linux WebKit, actual screen-reader/voice-control review, browser zoom, Lighthouse, remote CI and full backend acceptance are not verified by this work. No production deployment or real-recipient email was performed.
