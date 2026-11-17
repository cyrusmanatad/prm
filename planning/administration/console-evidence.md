# Administration console — implementation and verification

Scope: administration console slices approved under D31 (2026-11-16) and D32 (2026-11-17: suspension, support access, cleanup dry runs). Dates: 2026-11-16 to 2026-11-17. Revision: verified as a working tree on `administration/console`, based on `main` at `ddd6c53`, then committed unchanged as `1edc00a` and merged to `main` at `f16fd09` ([PR #18](https://github.com/cyrusmanatad/prm/pull/18)). Environment: disposable local Compose project `weavdash-admin` (PostgreSQL 16, Redis, application on `localhost:8010`, array mail, no ClamAV service), migrated from empty to `2026_11_16_000001_add_platform_console`. No shared, staging or production system was touched. No real operator was provisioned, no data was cleaned and no email was sent.

## What was implemented

| Package | Implemented in this slice | Still open |
|---|---|---|
| ADM-01 | Runtime operator management at `/admin/operators` (grant, change, revoke) for platform owners, with reason, expected version, password and a fresh single-use authenticator code; step-up expiry on a page visit returns to the challenge and then to the requested page; generic administration denial page; authenticator screen with copyable setup key and offline recovery guidance. | Real-owner provisioning; workspace-owner MFA (M1-04); QR code for enrollment (no dependency added). |
| ADM-02 | `AdminShell` (sidebar, small-screen navigation drawer, Administration/environment/operator labels); Overview; Accounts and Workspaces lists with search, filters, server totals and URL-restored state; account detail (Overview, Workspaces, Security, Activity); workspace detail (Overview, Members, Entitlements, Operations, Activity). | Fixture/protected classification and joined-date filters. |
| ADM-03 | Platform-grant revoke/reduce; account and workspace suspend/reactivate; sign out an account's sessions. Each needs a reason, the reviewed version, password and a fresh authenticator code, shows a server-computed impact review and uses modal confirmation. See [Governance slice](#governance-slice-d32). | Partner-side "paused" label; membership role changes (read-only). |
| ADM-04 | Plans list and plan-version detail; assignments list with derived state and history; assignment form moved to its own page reached from a workspace, with current usage, reduced-limit warning and consequence review. The `POST /admin/assignments` contract is unchanged. | Commercial prices/quotas (M4-01), production roster (M4-02). |
| ADM-05 | Notification list with totals and name/ID search; notification detail with the existing resolution flow; `/admin/operations` with sanitized failed jobs, Redis queue depth, notification outbox backlog and a scheduler heartbeat. | Worker, scanner and mail-provider health (shown as Unknown / Not configured); retry controls; M6-03 monitoring. |
| ADM-06 | `/admin/audit` list and event detail over `platform_audit` and `pilot_admin_events`. Support access: operator request, workspace-owner approval at `/workspace/support-access`, read-only scoped view with a persistent banner, automatic expiry, end by owner or operator. | Emergency access (none built); tenant audit-event browsing; export. |
| ADM-07 | Fixture batches, explicit marking with evidence, and immutable dry-run manifests with eligible/blocked/protected dispositions and reasons. No execution function or control exists. | Execution and its approval; file/object-storage inventory; real-owner bootstrap and recovery rehearsal; final acceptance. |

New capabilities: `operations.read`, `audit.read`, `access.manage`, `support.request`, `cleanup.review` (also accepted by the offline `platform:grant` command). Migration `2026_11_16_000001_add_platform_console` runs `src/database/sql/platform-console.sql` (gated SECURITY DEFINER projections, `platform_observations`) and `platform-governance.sql` (`platform_suspensions`, `support_grants`, `fixture_batches`, `fixture_marks`, `cleanup_runs` and their functions); it applied, rolled back and re-applied cleanly, and its rollback refuses to run while a suspension or support grant is active. Four existing access functions now also check suspension (see below). Other existing functions and the JSON contract of `/admin`, `/admin/assignments` and `/admin/notifications` (`notices` shape) are unchanged; `/admin/notifications` JSON gains `total` and `q`.

**Behaviour changes to note:** browser visits to `/admin` now show the Overview (JSON clients still receive the directory); after authenticator verification every operator lands on `/admin` or the page they asked for; a recorded assignment returns to the workspace's Entitlements tab; a recorded resolution returns to the notification detail.

## Governance slice (D32)

**Suspension.** `app_has_membership`, `app_lock_membership`, `app_platform_allowed` and `app_lock_platform_grant` were replaced to also require that the workspace and the acting account are not suspended. Every tenant policy, web request, worker job and scheduled task authorizes through these, so suspension is rechecked whenever work executes. The rollback script restores the previous definitions. In addition:

- A suspended account's sessions are deleted, sign-in is refused with a neutral message, and any surviving session is ended on its next request (`EnforceAccountAccess`).
- A suspended workspace shows its members "This workspace's access is suspended. Its records are kept. Contact support." Public lead links, media kits, onboarding links and partner join links answer as unavailable.
- Suspending a workspace cancels its queued notifications, ready scheduled runs and approved follow-ups and ends open support access. Reactivation restores access only.
- The acting operator and the last usable platform owner cannot be suspended. A suspended operator's grant is kept but unusable.

**Support access.** A request names the workspace, purpose, record areas and a duration of 1 to 24 hours. Only an active owner of that workspace can approve, decline or end it. Records are read through one function that requires the operator's own approved, unexpired grant covering the area, returns a fixed projection and writes each view to `platform_audit` and the workspace's `audit_logs`. Expiry is evaluated on every read, so access ends on time without a job. A grant gives no membership and no other tenant access.

**Implementation choice to review (Proposal):** inside an approved area the support view omits free-text notes, template bodies, onboarding answers, documents and payment evidence.

**Cleanup dry runs.** Records are marked one at a time with evidence. A dry run stores an immutable manifest: per marked record, the proposed disposition, the reasons and related record counts. Blocked: a workspace with finance records, an unmarked member, a partnership outside the batch or unsettled notifications; an account in a blocked or unmarked workspace or named in audit history. Protected: any account holding a platform grant. Audit history is excluded from every manifest. There is no function, route or control that deletes.

**Not built from the confirmed rules in this slice:** the partner's portal did not label a suspended workspace's partnership as paused, and work refused at execution because of suspension was not recorded as "skipped". Both were added on 2026-11-17; see the [follow-up evidence](follow-up-evidence.md).

## Reused and new shared components

Reused: `ContentSection`, `FormField`, `FieldGroup`, `SelectField`, `ActionGroup`, `DialogClose`, `AccountMenu`, `BrandLogo`, shared `Button`, `confirmAction`/`ConfirmationDialog`, `useValidationFocus`, `useNotify`, `containDialogFocus`, `recordResourceLabel`.

New in `src/resources/js/components/`, styled in `foundation.css` with existing tokens: `DataTable`, `Pagination`, `FilterBar`, `StatusBadge`, `TabNav`, `EmptyState`, `DetailList`, `CopyReference`, `StatCard`, `UnavailableNotice`. New `layouts/AdminShell.vue`, `composables/useListFilters.ts` and `css/admin.css` (scoped under `.wd-admin`). `FormField` accepts `inputmode="numeric"`. `PlatformDirectory.vue` was removed; its content moved to the pages above. Governance added `features/admin/AccessChange.vue` (shared suspend/reactivate/sign-out form), the support and cleanup pages, and `pages/WorkspaceSupport.vue` for workspace owners, linked from workspace settings.

## Commands and results

| Gate | Command (from `src/` unless stated) | Result |
|---|---|---|
| Formatting | `npm run format:check:changed`; `npm run format:check` on edited tracked files | Pass, 58 changed frontend files |
| Types | `npm run typecheck` | Pass |
| Component tests | `npm test` | Pass, 22 files / 108 tests (31 new) |
| Build | `npm run build` | Pass |
| PHP style | `vendor/bin/pint --test` | Pass, 203 files |
| PHP default suite | `php artisan test --compact` | Pass, 42 tests / 362 assertions |
| Administration acceptance (PostgreSQL) | `phpunit -c phpunit.acceptance.xml --filter 'PlatformGovernanceTest\|PlatformConsoleTest\|ProfessionalHubTest\|DeliveryOperationsTest\|PilotEntitlementTest'` | Pass, 45 tests / 1,122 assertions (13 new tests) |
| Full acceptance (PostgreSQL/Redis) | `docker compose … run --rm acceptance` | 157 tests, 3,702 assertions: 152 pass, 3 skipped, **2 fail for environment reasons** (below) |
| Browser, Chromium desktop | `npx playwright test administration.spec.ts professional.spec.ts delivery-operations.spec.ts accessibility.spec.ts adversarial.spec.ts --project=desktop` | Pass, 8 tests |
| Browser, Chromium mobile | same specs, `--project=mobile` | 7 of 8 pass every run; **the administration journey is intermittent on mobile** (below) |

The two full-suite failures are not caused by this change: `OnboardingSecurityTest` needs the real ClamAV service, which this disposable stack omits; `SyntheticRecoveryTest::test_smtp_disconnect_after_data_is_uncertain…` fails identically on an untouched checkout of `main` in the same stack and belongs to the dedicated recovery environment. All other pre-existing tenant, finance, onboarding, notification and scheduler acceptance tests pass with the modified access functions.

**Mobile administration journey — intermittent, cause not established.** After the governance steps were added, the mobile run of `administration.spec.ts` passed in 3 of 6 attempts. The three failures were at three different steps (a link tap that did not navigate, an unexpected sign-in page, and a second browser context whose sign-in request stayed pending past the 15-second limit). The desktop run of the same journey passed every time, and the same steps passed on mobile in the other attempts. Checked and ruled out: the new suspended-account sign-out never fired during two instrumented runs, and the database showed no blocked or idle-in-transaction sessions. Not ruled out: the single-process PHP development server stalling under two browser contexts and long accessibility scans, or a session race. Treat mobile coverage of this journey as **unverified** until it is run against a multi-worker server or the cause is found.

`PlatformGovernanceTest` covers, against the real runtime role: account suspension (capability, fresh proof, version conflict, self-targeting, session deletion, refused sign-in, ending a live session, denied tenant transactions, other members unaffected, reactivation not restoring a revoked membership); workspace suspension (cancelled queued notification, ended support grant, refused new support request, public-link resolution, denied member requests and worker transactions, no replay after reactivation); operator suspension and last-owner protection; session sign-out; the full support lifecycle (validation, duplicate request, nothing readable before approval, owner-only decisions, other roles and workspaces denied, exact 24-hour maximum, scoped projection without free text, out-of-scope and other-operator denial, both audit trails, no membership conferred, expiry, owner end, decline, operator withdrawal); and cleanup (capability, duplicate batch, invalid references, evidence, version conflicts, dispositions and reasons, stale runs, unchanged record counts, absence of any execution function, direct table access denied).

`PlatformConsoleTest` covers, against the real runtime role: denial without a grant, without step-up, with an expired or wrong-version proof, with another user's proof, without the matching capability and after revocation; direct calls to the gated functions and helper functions from the runtime role; absence of private workspace content, credentials and secrets from every projection; literal handling of search wildcards; operator grant validation, fresh-code requirement and replay, stale version, self-targeting, unverified targets, session invalidation, audit rows and rollback of rejected attempts; a revocation waiting for the target's in-flight request; sanitized failed-job output; unknown scheduler state; audit listing, filtering and read-only behaviour.

## Rendering and accessibility findings

Inspected with temporary captures (removed) and automated checks at 1440, 1024 and 768 wide on desktop and 390 and 320 on mobile, for all eleven modules plus account, workspace, plan, assignment, notification, operator-form, audit-event, suspension form, support request, support session, workspace-owner approval, cleanup batch and dry-run views.

- No axe violations and no page-level horizontal overflow on any administration screen at those widths.
- Tables render as labelled, keyboard-focusable scroll regions; below 600px each row becomes a labelled record with explicit table roles.
- Confirmation dialogs (assignment, grant, revoke, suspend, reactivate, approve and end support access) focus Cancel, close on Escape, contain focus, restore the trigger and send one request.
- The navigation drawer is modal, closes on Escape and navigation, and restores focus to Menu.
- Status always pairs colour with text and an icon.
- Found and fixed during inspection: a date formatter that threw in the browser; a table scroll region sharing its section's landmark name; a button whose accessible name omitted its visible text; duplicated wording on the uncertain-send detail; a usage table that squeezed its columns into very tall rows; short table cells breaking mid-word; an unstyled consequence list on the suspension form. Defects found by tests rather than inspection: two database functions used a variable named `found`, which collides with PostgreSQL's `FOUND`; a 24-hour grant expired one microsecond past its limit; an account was listed as eligible for cleanup while its workspace was blocked.
- Known cosmetic limits: on Platform access the change form opens below the operator table (focus and scroll move to it); in a table the notification ID copy button wraps under the ID at narrow column widths.

## Unverified gates

- The mobile administration journey is intermittent (see Commands and results); its cause is not established.
- PostgreSQL integration suite (`phpunit.pgsql.xml`) and the `rls.sql` scripts: not run; they need a freshly migrated database with their fixtures.
- WebKit desktop/mobile projects and the remaining browser specs: not run. Native Safari, screen-reader and browser-zoom review: not done.
- GitHub Actions: not run locally; they run on PR #18 and their result is not recorded here.
- The scheduler heartbeat was exercised through its database function, not by a running scheduler in this stack.
- Reduced-motion behaviour: no new animation was added; not separately tested.
- Staging and production behaviour, real-owner bootstrap and recovery rehearsal: not performed.
- `src/test-results/results.json` could not be written by the Playwright JSON reporter because the existing `test-results/` folder is owned by root; the list reporter output above is the record.
