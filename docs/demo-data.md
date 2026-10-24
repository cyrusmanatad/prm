# Local demo data

Created on 2026-10-24 in Compose project `weavdash-m1`, database `weavdash`.
The user requested a fresh database and synthetic data across implemented modules.
Existing local database records were replaced. Unfinished application changes were preserved.

## Accounts and data

Credentials and public demo links are generated locally in `docs/demo-user.txt`.
This file is Git-ignored because it contains active demo passwords and invitation tokens.

- 2 brand owners: Northstar Outdoors and Lumen Living.
- 10 separate creator/partner workspaces linked to each brand (20 partners total).
- 5–10 leads per partner (142 total), plus 7 sample leads per brand (156 overall).
- 156 linked contacts, 156 reminders, 66 outreach templates.
- 88 onboarding requests across draft, submitted, correction and accepted states.
- 22 real private sample attachments scanned by ClamAV, 22 published media kits and 22 lead-capture links.
- 66 private invoice records, including 22 voided drafts; 44 simulated receipts and allocations.
- 22 pilot assignments lasting 90 days, with explicit demo quotas of 1,000 per supported resource.
- 108 invitation notifications captured by the array mailer. No external delivery.
- Audit/activity history created through application services.

Future/unimplemented modules are not represented as completed features.

## Reproduction

The explicit CLI fixture is [seed-demo.php](../src/scripts/seed-demo.php). It refuses non-local/testing environments and nonempty user tables. It is intentionally not part of ordinary application startup or the default database seeder.

For an explicitly authorized disposable reset, stop app/worker/scheduler, reset the PostgreSQL public schema (including functions), recreate it owned by `weavdash_migrator`, then run `php artisan migrate:fresh --force` using the Compose migration service. Apply `src/database/sql/runtime-grants.sql` and restore runtime schema usage. Rebuild images to include current code before executing these steps.

Run `php scripts/seed-demo.php` in the app container as the restricted runtime database role. Run `php scripts/seed-demo.php --pilot` through the migration service. Copy `/tmp/demo-user.txt` from the app container to `docs/demo-user.txt`. The fixture generates new unique passwords on each fresh seed. Clear obsolete queued jobs and application cache before restarting the worker/scheduler.

## Verification

- All 17 migrations applied successfully from an empty schema.
- All 22 credential hashes and verified email states checked.
- Every account owns exactly one workspace, with 10 partnerships per brand and 1 per partner.
- Every workspace has 5–10 leads and all populated module records.
- Runtime tenant contexts cannot read another workspace's leads.
- All 22 stored sample attachments downloaded successfully through the service checksum/scan checks.
- All 108 notifications finished in `captured` state.
- PHP syntax, Laravel Pint and `git diff --check` passed.
- Browser sign-in passed for a brand owner and a partner, both reaching their correct workspace selection page.
- App and PostgreSQL health checks passed; worker and scheduler restored.
