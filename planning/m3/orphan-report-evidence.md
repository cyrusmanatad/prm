# PRIV-05 upload reconciliation (report only) evidence

**Scope (2026-11-23):** PRIV-05 under the approved P4 rule ([privacy contract](privacy-contract.md#policy-decisions-for-approval), D40). The rule: daily reconciliation; report unreferenced managed objects first; at least seven days from first observation and two consistent inventories before deletion, which needs approved deletion authority; pending or rejected uploads on editable requests are reported, never deleted because scanning failed. No deletion authority is assigned yet, so this slice reports and never deletes. Branch `feat/priv-05-upload-reconciliation` from `origin/main` at `8bf1c9e`. Tested on the local Compose stack against real PostgreSQL row-level security.

## Change

- **Migration `2026_11_28_000001_add_upload_reconciliation`** (`database/sql/upload-reconciliation.sql`): private tables `upload_reconciliation_runs` and `upload_reconciliation_findings` with forced RLS, no runtime table access (also revoked in `runtime-grants.sql`), and three functions:
  - `app_upload_reconcile_documents()`: each document's key, identifiers, scan state and whether its request is editable. No names, checksums or contents.
  - `app_upload_reconcile_record(...)`: records a run. A failed run leaves findings untouched. A completed run counts a finding again only when its kind is unchanged; a changed kind starts again; a finding absent from a completed run is dropped. Invalid kinds are refused.
  - `app_upload_reconciliation(filter, page)`: the administration read for `cleanup.review`. It returns the latest run, counts per kind (which never follow the filter), the matching total and one page of 25 findings with eligibility, longest-standing first, without keys, file names or contents.
- **`App\Services\UploadReconciliation`** and the command `privacy:reconcile-uploads`, scheduled daily at 03:15 UTC. One listing of the `onboarding/` prefix returns sizes and times without per-file requests. Findings:
  - **Orphaned:** a stored file with no record. Judged only once older than one hour, because uploads store the file before the record commits.
  - **Missing:** a record without a stored file.
  - **Held:** a pending or rejected upload on a draft or correction request. Staging's `unscanned` state is not treated as a scanning failure.
  - **Unrecognised:** a key not in the exact `onboarding/{uuid}/{uuid}/{uuid}` shape. Never attributed and never eligible.

  Records are attributed from their own identifiers. Storage or database errors are recorded as a fixed failure category; logs carry counts only.
- **Eligible:** orphaned, seen in at least two consecutive completed runs, and first seen at least seven days ago. Eligibility is reported only; nothing deletes.
- **Admin console:** a read-only "Storage reconciliation" card on Data cleanup (`features/admin/UploadReconciliationCard.vue`), redesigned from a user-approved mockup:
  - **Header:** a verdict badge (Not run yet, Last run failed, Needs review or All matched), with one line of run facts below: Report only · Checked … · N files compared · Runs daily at 03:15 UTC.
  - **Tiles:** count tiles that double as server filters (`?finding=`; the selected tile toggles back to all): files without a record, records without a file, held for review and eligible for deletion. Unrecognised files get a tile only when there are some. While filtered, a line reads "Showing N …" with "Show all".
  - **Table:** the findings table with numbered pages, 25 per page (`?finding_page=`). Parameters are validated, and the page's batch `view` filter is kept.
  - **Status:** only files without a record carry one ("Eligible" or "Waiting · N days left").
  - **Footer and states:** a one-line rule hint at the foot. A failed run is explained in a banner.
  - **Shared CSS:** the `rec-table td > a` rule gains a 44px minimum width.

## Verification

| Command | Result |
| --- | --- |
| `acceptance --filter UploadReconciliationTest` (with `PlatformGovernanceTest`) | 15 tests, 383 assertions passed. New, 7 tests: every finding kind with no file deleted; record attribution with a non-standard key; the in-progress grace; two runs plus seven days for eligibility, drift and kind changes starting again; unavailable storage recorded as failed with findings untouched, then a retry continuing the count; runtime table access refused and invalid kinds rejected; the report for `cleanup.review` only, with no keys or contents; server filtering and paging (27 orphans over two pages, counts unchanged by the filter, invalid filter and page refused). |
| Migration rollback, then migrate, then `grant-runtime.php` | Tables dropped then recreated; `weavdash_app` has no table privilege but executes the functions; PUBLIC cannot. |
| `privacy:reconcile-uploads` on the local stack | Compared 78 stored files; findings `held` 7, `orphaned` 6, `missing` 74 (acceptance fixtures insert records without files). Nothing deleted. `schedule:list` shows the daily 03:15 run. |
| `pint --test`, `format:changed`, `typecheck`, `build`, `npm test` | Passed; 38 files, 232 tests. New: the verdict badge, run facts, filter tiles (selected state and toggle links), the filter line, statuses only on files without a record, numbered page links, no buttons (read-only), and the never-run and failed states. |
| Browser `administration` cleanup tests, desktop and mobile | 4 passed; the journey checks the card is present. |
| 44px check of Data cleanup (temporary spec, deleted) at 1440 and 390 px, unfiltered and filtered | First run found a 39px-wide workspace link with a short name; after the CSS fix, no target below 44px and no horizontal scroll. A tile filters, and clicking it again returns to all findings; page links keep the filter. |

## Unverified gates

- **Deletion of eligible orphans:** needs a named deletion authority and a separate change.
- **Production volume:** storage listing is a single pass per run (the report itself is paged); very large stores need a paged listing.
- **R2 on staging:** not yet exercised against real object listing (local disk only); the first staging run will show it.
- **Exports:** `privacy-exports` archives are out of scope; `privacy:purge-exports` already handles them.
