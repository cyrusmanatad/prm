# Staging private R2 attachments

**Confirmed — 2026-11-09:** staging-only integration deployed with image tags `20261109-r2`; local development defaults to local storage. PostgreSQL retains metadata, permissions and SHA-256 checksums. R2 stores bytes using the unchanged `onboarding/<org>/<request>/<document>` keys. No database schema change is required; existing upload warnings now refer to private staging storage. See [deployment context](staging-deployment.md) and [verification](staging-r2-evidence.md). Production provider selection and release gates remain open.

## Bucket and token

The user supplied the bucket URL ending in `/weavdash`; configure bucket **`weavdash`** separately from the account endpoint below. The original proposed name was `weavdash-staging-private`; use that instead only if that is the bucket actually created. Verify that the selected bucket is dedicated to synthetic staging data before migration.

In Cloudflare R2, use Automatic location and Standard storage. Keep the public development URL disabled, no custom domains, no CORS rules, no automatic expiration or Infrequent Access transition, and bucket lock disabled. Create an account API token named `weavdash-staging-storage`, with Object Read & Write restricted to this bucket. Save the Access Key ID and one-time Secret Access Key privately. Neither a bucket URL nor a Cloudflare bearer token replaces these S3 credentials.

References: [bucket creation](https://developers.cloudflare.com/r2/buckets/create-buckets/), [tokens](https://developers.cloudflare.com/r2/api/tokens/), [locations](https://developers.cloudflare.com/r2/reference/data-location/), [pricing](https://developers.cloudflare.com/r2/pricing/), [S3 compatibility](https://developers.cloudflare.com/r2/api/s3/api/), [Laravel filesystem](https://laravel.com/framework/docs/13.x/filesystem).

## Private runtime settings

Create `storage.env` beside the deployed Compose file in `/home/cyrusadmin/weavdash-staging`, mode 600 inside the existing mode-700 directory. Edit privately; never paste credentials into chat, shell history, Git, browser code or build arguments. Compose reads this file in raw format for app, worker, scheduler and migrate. The file is optional for existing local-storage installations. Requires Docker Compose 2.30+ (same raw-file requirement as SMTP).

```dotenv
ONBOARDING_STORAGE_DRIVER=local
R2_ACCESS_KEY_ID=<access-key-id>
R2_SECRET_ACCESS_KEY=<secret-access-key>
R2_BUCKET=weavdash
R2_ENDPOINT=https://06212c577e738ca65fd4a2059e1bd791.r2.cloudflarestorage.com
R2_REGION=auto
```

Keep the driver local until copy and verification succeed. Then change only `ONBOARDING_STORAGE_DRIVER=s3`. `FILESYSTEM_DISK` does not select attachment storage. The named disk enforces private visibility, path-style requests and exceptions on storage failures. Existing protected downloads, scan checks, two-MiB limit and five-file limit remain. R2 supplies no antivirus scanning; staging unscanned labels and double opt-in remain required.

Build fresh staging images with the current Composer lock (S3 adapter included), transfer them and update Compose using the [deployment instructions](staging-deployment.md#deployment-and-updates). Preserve application/database keys, current images and local storage volume. The backend egress network already used for SMTP also permits HTTPS to R2. Do not print `docker compose config` with secrets; use `config --quiet`.

## Inventory, backup and switch

All commands below run from the VPS staging directory against its own Compose project. The tools refuse normal deployments. The copy/inventory command additionally requires PostgreSQL's `weavdash_migrator` role so forced RLS cannot silently hide other tenants' files. It prints only aggregate document counts/bytes; it never prints file contents, object keys or SDK exceptions.

1. Verify bucket privacy and token scope in Cloudflare. Deploy the new tool image/config with driver still local. Test synthetic write/read/size/delete before pausing colleagues:

   ```sh
   docker compose --env-file staging.env -f compose.staging.yaml config --quiet
   docker compose --env-file staging.env -f compose.staging.yaml run --rm -T --no-deps migrate php artisan staging:storage-probe
   docker compose --env-file staging.env -f compose.staging.yaml run --rm -T --no-deps migrate php artisan onboarding:storage inventory
   ```

2. Announce a maintenance window. Stop `web app worker scheduler` in this project; keep PostgreSQL and Redis running. Wait for active writers to finish before continuing. `--writers-paused` is an operator assertion, not an automatic lock. Take a fresh PostgreSQL dump, private-volume archive and copies of `staging.env`, `smtp.env`, `storage.env` and Compose with `umask 077`. With app stopped, archive local files through a one-off tool container:

   ```sh
   docker compose --env-file staging.env -f compose.staging.yaml stop web app worker scheduler
   docker compose --env-file staging.env -f compose.staging.yaml exec -T postgres pg_dump -U postgres -d weavdash -Fc > backups/before-r2.dump
   docker compose --env-file staging.env -f compose.staging.yaml run --rm -T --no-deps migrate tar -C storage/app/private -czf - . > backups/before-r2-private.tar.gz
   ```

   Use unique backup names and retain a protected off-host copy. Inventory again during the paused window and record counts/bytes privately.

3. Copy all referenced documents, then independently verify both endpoints against database size and SHA-256:

   ```sh
   docker compose --env-file staging.env -f compose.staging.yaml run --rm -T --no-deps migrate php artisan onboarding:storage copy --writers-paused
   docker compose --env-file staging.env -f compose.staging.yaml run --rm -T --no-deps migrate php artisan onboarding:storage verify --writers-paused
   ```

   The command retains all source files and database records, skips already verified destination objects on resume, and refuses conflicting destination contents or missing/corrupt source data. Empty inventories are valid. A failure means keep the local driver and investigate; do not switch. Unreferenced local objects are retained for later private reconciliation, not automatically published or deleted.

4. Set driver to `s3` in private `storage.env`. Recreate app, worker and scheduler with the new images/environment, then recreate web so its PHP upstream refreshes. This stack does not build a configuration cache; if one was introduced privately, clear/rebuild it in every backend runtime. Stop/recreate long-running workers rather than leaving stale credentials or driver configuration.

5. Verify synthetic upload/download/remove, unauthorized and foreign-workspace denial, scan-state blocking and retained labels. Capture an authorized reference privately, replace the app container and verify that reference again. Check desktop/mobile journeys at 1440×900 and 390×900. Record text findings in the evidence report. Screenshots, if needed, stay in `/tmp` and are removed after inspection. Do not describe local/fake tests as real R2 validation.

## Recovery and ongoing backups

Before any new R2 writes, switching back to local is safe only if local files still match every referenced record. After R2 writes begin, stop all writers again, inventory/back up current PostgreSQL, configuration and referenced R2 bytes, then run the reverse copy and verify:

```sh
docker compose --env-file staging.env -f compose.staging.yaml run --rm -T --no-deps migrate php artisan onboarding:storage copy --from=onboarding-r2 --to=onboarding-local --writers-paused
docker compose --env-file staging.env -f compose.staging.yaml run --rm -T --no-deps migrate php artisan onboarding:storage verify --from=onboarding-r2 --to=onboarding-local --writers-paused
```

Only after both succeed, set driver to `local` and recreate every backend runtime plus web. Restore compatible images as needed; do not restore an old database snapshot over newer writes or automatically reverse migrations. Keep R2 objects and original local volume throughout recovery. Conflicting local bytes require private investigation; the tool never overwrites them.

Once R2 is active, the local-volume archive alone is insufficient: back up current referenced R2 bytes with a matching PostgreSQL snapshot/config during a paused window. One option is the verified reverse copy followed by archiving the private volume; retain that snapshot off-host. Object deletion remains application-controlled and R2 is not itself a backup. Rotate a bucket-scoped token by updating `storage.env`, rerunning the probe and recreating all four backend consumers before revoking the previous token.
