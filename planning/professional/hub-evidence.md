# Professional Hub local verification

**Screenshot refresh (2026-11-08):** the subsequent [Professional UI verification](ui-evidence.md) reran the existing browser journey and refreshed its hub/profile/administration screenshot outputs. Results below retain their original verification date and scope; the separate UI evidence includes pre-refinement captures.

Date: 2026-11-07. Tested source: base `6975570` plus the working tree recorded in `source-revision.txt`. [Feature status](hub-status.md), [contract](hub-contract.md).

## Environment

Isolated Compose project `weavdash-professional`, newly created PostgreSQL 16/Redis/ClamAV volumes, PHP 8.5.11 and locked dependencies. Existing `weavdash`/`weavdash-m1` data was preserved. Runtime requests/probes use `weavdash_app`; only fixture control, migrations and offline-command rehearsals use `weavdash_migrator`. No external email, production credentials, production provisioning or cleanup is involved. The application was served from the current source bind mount and production frontend build.

The bind mount runs as UID 1000 to write test caches/views. A test-only Compose override mounts `/tmp/weavdash-professional-private` as private storage for scanner/upload checks; the original named volume belonged to the image user. Initial unwritable-storage failures were corrected through that isolated test configuration, without changing application storage permissions.

## Commands and results

Run Docker commands at the repository root with `--env-file src/.env -p weavdash-professional`; npm/PHP tooling runs in `src/`.

| Check | Result |
| --- | --- |
| Build `app migrate acceptance`; start PostgreSQL/Redis/ClamAV | Passed; fresh isolated volumes |
| `migrate`; `migrate php scripts/grant-runtime.php` | Passed with restricted runtime role |
| `migrate php artisan migrate:rollback --step=2 --force`, then `migrate` | Passed for both new migrations; rechecked after schema changes |
| `acceptance vendor/bin/phpunit -c phpunit.acceptance.xml --filter 'ProfessionalHubTest\|PilotEntitlementTest\|FollowUpDraftTest\|BusinessScheduleTest\|ScheduledNotificationTest\|OnboardingSecurityTest\|PartnershipSecurityTest'` | **52 tests, 968 assertions passed** |
| Focused `ProfessionalHubTest` after finance export completion | **13 tests, 189 assertions passed**; includes exact invoice lines and payment allocation/reversal history |
| `acceptance vendor/bin/phpunit` | **30 tests, 265 assertions passed** |
| `vendor/bin/pint --dirty --test` | Passed |
| `npm run format:changed`, `npm run format:check:changed` | Passed with pinned Prettier; four-space formatting retained |
| `npm run typecheck`, `npm run build` | Passed |
| `npm test` | **51 tests passed** |
| `npx playwright test professional.spec.ts --project=desktop --project=mobile --output=/tmp/weavdash-professional-browser --reporter=list` | Desktop/mobile journeys passed; keyboard modal checks and Axe scans passed |
| `python3 src/scripts/check-secrets.py` | Positive control passed; source scan retains the documented pre-existing `planning/evidence/m5/schedules/source-sha256.json:7` false positive. Scanner unchanged. |
| `git diff --check`; planning relative-link validation | Passed |

Final frontend screenshot-review corrections passed the same formatting/type/build/component/browser gates (26 supported frontend files). Final desktop/mobile run: 2 passed in 41.9 seconds; additional hub widths 320/768/1024/1440 passed. Relative-link validation checked 296 links with no missing targets. An expanded browser journey exceeded the original 60-second whole-test timeout once while concurrent PHP tests ran; its explicit 120-second budget accommodates the complete enrollment, renewal and eight-tool journey. No assertions were removed. The existing send-reservation concurrency test initially failed to connect a probe within its deadline; its focused rerun and final full affected suite both passed without changing its assertion or deadline.

## Coverage and limits

New PostgreSQL tests cover pending/future/active/exhausted/expired/renewed access; individual/business client records; existing identity workspace selection and verified registration intent; optional brand relationship scope; direct database escalation denial; owner-only finance exports; immutable version races with a proven PostgreSQL lock wait; atomic assignment/audit rollback; MFA enrollment/replay/expiry; offline bootstrap, last-owner denial and controlled recovery; expiry at deferred transaction commit; published expired profiles; paused public capture/onboarding; blocked approval/handoff; queued work and send reservation cancellation; and no renewal replay. A final focused test also verifies expired-workspace CSV exports retain exact invoice line amounts and payment allocation/reversal history. Existing affected suites retain private draft, role, RLS, quota concurrency, public capability and send-race checks. Synthetic limits appear only in tests; application code seeds none.

Browser checks exercise the production bundle at desktop/mobile widths, authenticating through `/professional/login`, creating a client lead, visiting every included tool, exporting CSV, enrolling/verifying TOTP and recording an administrator renewal. Confirmation initially focuses Cancel, Escape closes it and focus returns to the trigger. Axe reports no violations on checked screens. Additional hub overflow checks cover 320/768/1024/1440 pixels. Secret-bearing authenticator setup screens are excluded from screenshots. Manual screen-reader/native Safari and inherited release reviews remain open.

## Inspected screenshots

- [Desktop hub](../evidence/professional-hub/desktop-hub.png), [mobile hub](../evidence/professional-hub/mobile-hub.png).
- [Desktop profile](../evidence/professional-hub/desktop-profile.png), [mobile profile](../evidence/professional-hub/mobile-profile.png).
- [Desktop administration](../evidence/professional-hub/desktop-administration.png), [mobile administration](../evidence/professional-hub/mobile-administration.png).

Screenshots use synthetic fixture accounts and display test quotas only. Shared `FoundationShell`, `PartnerChrome`, `ContentSection`, `FormField`, `SelectField`, buttons, `AccountMenu` and `ConfirmationDialog` are reused. Screenshot review corrected section spacing, professional labels and directory search synchronization. All remaining administration/milestone/release work stays tracked in the [register](../shared/remaining-tasks.md).
