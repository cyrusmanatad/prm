# Staging presentation dataset

**Confirmed — 2026-11-18:** implement the approved Northline Living presentation plan. All identities, companies, contacts, relationships and commercial activity are invented. USD estimates are examples, not transactions. Existing staging accounts and platform permissions remain intact.

## Dataset and access

The fixed [v1 manifest](../../src/database/fixtures/northline-living-v1.json) contains one Brand and these 13 Partners. Each Partner has all seven pipeline stages, linked CRM contacts, dated presentation activity, and upcoming, overdue and completed reminders. Dates are relative to the first execution; reruns do not shift them.

| Partner workspace | Location / focus | Private leads |
| --- | --- | ---: |
| Maya Chen Studio | Singapore / small-space living | 15 |
| Reed & Room | London / modern interiors | 16 |
| Amara Everyday | Lagos / family rituals | 17 |
| Casa Silva Creative | São Paulo / indoor-outdoor entertaining | 18 |
| Studio Sofia Rossi | Milan / table styling | 19 |
| North House Stories | Toronto / weekend hosting | 20 |
| Aiko Slow Home | Tokyo / mindful home routines | 21 |
| Atelier Ines | Paris / textile and colour stories | 22 |
| Seoul Space Agency | Seoul / compact apartment design | 23 |
| Desert Light Collective | Dubai / warm-weather living | 24 |
| Elena Casa | Barcelona / Mediterranean home styling | 25 |
| Murphy Field Notes | Dublin / sustainable everyday essentials | 26 |
| Bennett Living Studio | Melbourne / seasonal home refreshes | 27 |
| **Total** | | **273** |

The Brand login is `northline-living-demo@mailinator.com` at `/brand/login`. Partner addresses use `<person-name>-demo@mailinator.com`, listed exactly in the manifest and private roster; sign in at `/partner/login`. All 14 accounts are verified. No inbox access or verification email is needed.

Northline Living sees its 13 accepted, active partnerships. Leads and CRM remain private to each Partner; the Brand does not receive their pipeline. Revenue, commission, performance and other unavailable product metrics remain labelled unavailable. No onboarding submissions, attachments, invoices, payments, Professional accounts or platform grants are provisioned.

Each workspace receives one 90-day demonstration assignment with quota 100 for each existing supported resource: leads, contacts, reminders, onboarding requests, templates, invoice drafts and manual payments. This is presentation capacity, not commercial pricing or billing.

## Provisioning and repeat execution

The [Artisan command](../../src/app/Console/Commands/StagingSeedPresentation.php) uses the [fixture writer](../../src/app/Services/PresentationDataset.php). Run with PostgreSQL migration credentials and `STAGING_MODE=true`:

```sh
php artisan staging:seed-presentation /private/directory/roster.json --dry-run
php artisan staging:seed-presentation /private/directory/roster.json
```

Create the roster directory first with mode 700, outside the application and Git checkout. The command exclusively creates a mode-600 JSON roster with 14 unique random passwords; it never prints passwords. Keep the roster and its backup private. It is required for subsequent runs and is never overwritten. Password resets performed later through the product must be tracked privately by the presenter; the original roster is not automatically updated.

The transaction takes a dataset advisory lock. UUIDv5 identifiers and the manifest checksum identify the dataset. New runs reject existing account addresses, workspace IDs, other stable record IDs and conflicting batches. Reruns verify all expected records, relationships, fixture ownership and contact links, then preserve content, stages, dates, credentials and entitlements. Missing records or mismatched ownership abort; the command does not repair a partial or edited-away dataset. Expected records can be edited, and additional live presentation records are allowed.

Brand approval follows the existing staging-bootstrap convention: `source=rollout`, an approved request and a `rollout_approved` event with operator `staging:seed-presentation`. Its reason explicitly identifies presentation provisioning and disclaims a historical administrator review. The approval timestamp is actual execution time; there is no fabricated reviewer. The fixture batch records all 14 accounts and 14 workspaces, with the manifest checksum as ownership evidence. Its synthetic Brand account is the batch/mark attribution; it receives no administrator permission.

Product email-producing flows are not called. No verification, invitation, approval or scheduled-delivery intents are created.

The roster is flushed and synced before database commit. If a write fails, the transaction rolls back. If a commit fails or its acknowledgment is lost, a complete roster is retained: investigate database ownership and the private roster before retrying. Do not delete a roster or regenerate passwords until rollback is established. An orphan or missing roster deliberately blocks automatic provisioning.

## Presentation walkthrough

1. Sign in to Northline Living at `/brand/login`. Show the 13 active Partners and accepted invitation history. Explain that Partner CRM is private and unavailable metrics are not fabricated.
2. Sign in separately as Maya Chen at `/partner/login`. Show 15 leads, USD pipeline estimates, all seven stages and the active Northline Living relationship.
3. Search for `Northline`, open its lead and show the fictional brief, estimate, next action and dated activity. Change its stage and save to demonstrate persistence; restore the stage afterward if desired.
4. Open Contacts & reminders for the lead. Show its linked contact and the Pending, Due and Completed segments. Complete/reopen a reminder or edit a due date as a live demonstration.
5. Sign in as Chloe Bennett to show the 27-lead list and pagination. Use a separate browser context for the Brand and each Partner.
6. Repeat the Brand, pipeline and CRM views at phone width. Avoid creating onboarding, payment or upload examples in this dataset.

The [browser verifier](../../src/scripts/verify-presentation.mjs) checks all account logins, counts, access denial and representative rendering at 1440×900 and 390×900:

```sh
PRESENTATION_ROSTER=/private/directory/roster.json node scripts/verify-presentation.mjs
```

Set `PRESENTATION_EXERCISE_EDITS=1` to exercise and restore stage/reminder changes. These actions legitimately add activity/version updates; they do not rewrite the fictional seed history. Optional `PRESENTATION_CAPTURE_DIR` must point under `/tmp/`; inspect and remove those captures after use. Default verification produces no screenshots.

See [implementation and staging verification](staging-presentation-evidence.md) for backup identifiers, execution time and results. Fixture inventory and cleanup planning use the existing administration console; no cleanup execution is introduced.
