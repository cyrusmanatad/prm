# Recovery PR CI repair

Scope: PR #16, `m5/synthetic-recovery-acceptance`, starting at `464b271`; repair verification in progress on 2026-11-13. The delivery-operations checkout is preserved; implementation uses `/tmp/weavdash-ci-repair`.

## Repairs

- Frontend CI trusts only its checkout path and verifies the formatting base exists after full-history checkout. The formatter fails with a direct repository/base diagnostic. Pinned Prettier remains unchanged.
- Secret scanning retains its pinned image and synthetic positive control. Exceptions match only `generic-api-key` plus the exact historical checksum and exact evidence path. Private temporary reports support exact comparison; console output remains redacted. Path/rule/value mutation controls reject other findings.
- Recovery stacks generate fresh app/runtime/migration/control credentials and pass one consistent environment through Compose and PHPUnit. Internal networking and fault-target/volume guards remain intact.
- Retained clean-setup logs showed hardcoded Professional stack/source mounts and obsolete lifecycle field labels. Fixtures now use the disposable stack's image and runtime credentials. Four independent clean-setup jobs retain Chromium/WebKit desktop/mobile coverage; formerly omitted mobile journeys run. No product/API/schema changes.
- Browser images are opt-in, with temporary output paths; default traces/screenshots are disabled. Missing `.env` no longer causes secondary backend cleanup failure. Fresh setup removes PostgreSQL port publication and refuses existing containers, volumes and networks.

## Verification

Pending final revision checks. Guard tests: 3 passed. Scanner exception tests: 1 passed, with actual pinned-scanner confirmation of both evidence findings and rejected path/rule/value mutations. Full scanner, frontend, PHP, recovery and fresh-setup/browser results will be recorded after completion.

## External and milestone gates

GitGuardian reports four historical disposable fixture findings in `464b271`: incident IDs 39976467–39976470 (runner password, Compose app key/password, documentation app key). Current-source literals have been removed; account-level classification and the final PR check remain pending. Do not rewrite history or merge during repair.

M5 provider trials/receipt, approved production budgets, administration, UI/manual and inherited release gates remain open. No deployment, external email, shared-data operation or milestone acceptance is authorized by this repair.
