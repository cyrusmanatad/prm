# Staging review — 2026-11-08; resolved locally 2026-11-09

## Resume point

Both P2 findings are resolved and locally verified in the working tree on `m5/onboarding-lifecycle-email`, based on `3887f7d`. Existing uncommitted staging work is preserved. No deployment occurred; remote staging does not include these fixes. See [staging deployment and verification](staging-deployment.md) and the [current handoff](implementation-handoff.md).

## Original review output — 2026-11-08

The following review is retained as historical context; its findings are closed by the follow-up below.

The staging proxy collapses anonymous visitors into shared rate-limit buckets, and disabling uploads can strand existing onboarding drafts. Type checking and the five staging feature tests passed; the formatting check was blocked by an environment permission error.

Full review comments:

- [P2] Preserve the client address supplied by the trusted edge — /home/cyrusadmin/weavdash/src/docker/staging-nginx.conf:17-17
  Behind the configured shared edge proxy, `$remote_addr` is the edge container's address, not the visitor's. Replacing `X-Forwarded-For` with it makes all anonymous visitors share Laravel's IP-based throttle buckets, including registration (5/minute), recovery (5/minute), and onboarding exchange (6/minute). Several colleagues can therefore block each other's access. Preserve the sanitized client address from the trusted edge instead.

- [P2] Allow existing attachments to be removed when uploads are disabled — /home/cyrusadmin/weavdash/src/app/Services/OnboardingDocuments.php:66-66
  If uploads are disabled after a draft acquires a pending or rejected attachment, this guard prevents its removal while `Onboarding::submit()` still rejects the attachment. The recipient cannot complete the form without an operator re-enabling uploads, despite the interface saying they can continue without files. Block new uploads without blocking authorized removal of existing, unsubmitted attachments.

## Resolution and verification — 2026-11-09

1. **Resolved — client address:** staging Nginx passes `$http_x_forwarded_for` to FPM. The shared edge continues overwriting the header with `$remote_addr`, discarding caller-supplied values. No proxy trust expansion was needed. This assumes the documented private listener behind the trusted edge; do not expose the staging web listener directly.
2. **Resolved — attachment removal:** removed only the upload-enabled guard from `OnboardingDocuments::remove()`. Upload middleware/service guards remain. Capability authorization, tenant scoping, request version/status, immutable submitted-file protection and deletion after commit remain enforced. No UI changes were needed: the existing Remove action and shared confirmation dialog remain available when uploads are disabled.

| Check | Result |
| --- | --- |
| `npm run format:check:changed` from `src/` | Passed: seven changed frontend files; the earlier permission blocker did not recur. |
| `npm run typecheck`; `npm run build` from `src/` | Passed. |
| `vendor/bin/pint --test app/Services/OnboardingDocuments.php tests/Feature/StagingFeaturesTest.php tests/Integration/OnboardingTest.php` | Passed: three files. |
| `vendor/bin/phpunit tests/Feature/StagingFeaturesTest.php` | Passed: 6 tests / 74 assertions. Registration, recovery and onboarding exchange throttle one client at their configured limit while a second client behind the same proxy remains unthrottled. |
| `DB_DATABASE=weavdash_staging_p2_20261109 vendor/bin/phpunit -c phpunit.pgsql.xml tests/Integration/OnboardingTest.php` | Passed: 9 tests / 185 assertions against fresh PostgreSQL migrations/runtime grants. Pending/rejected files remain submission-blocking, new uploads fail, stale removal fails, authorized removal deletes bytes and increments the version, and submission without files succeeds. Submitted/snapshotted files, revoked capabilities and real cross-workspace file removal remain denied. |
| Actual Nginx edge → staging web → FPM chain | Passed in isolated local Compose project `weavdash-staging-p2-proxy`, with both repository Nginx configs mounted directly, temporary TLS and no host ports. Both `nginx -t` checks passed. Clients `172.28.0.4` and `172.28.0.2` reached Illuminate Request with their distinct IPs and HTTPS enabled. Forged single/multiple `X-Forwarded-For` and `X-Real-IP` values were replaced by the edge. Temporary containers/network were removed. |
| `COMPOSE_PROJECT_NAME=weavdash-professional npm run test:browser -- onboarding.spec.ts --project=desktop --project=mobile --output=/tmp/weavdash-staging-p2-browser --reporter=line` | Passed: 4 journeys, including upload/download, confirmed removal, submission/correction and revocation, plus existing public-presence coverage. Embedded Axe and overflow assertions passed. |
| `git diff --check`; changed-document relative links | Passed. |

PHP checks used the local `weavdash-professional-app-1` source mount. The integration database was created specifically for this review, migrated with `weavdash_migrator`, granted using `scripts/grant-runtime.php`, tested as restricted `weavdash_app` and removed afterward. Existing application databases and demo data were preserved. Browser checks used synthetic local accounts and captured mail.

Visual inspection at desktop 1280×720 and mobile 390×664 CSS pixels confirmed the existing onboarding cards, file controls and removal dialog render without horizontal overflow; Cancel has initial focus. Existing shared confirmation/focus/keyboard checks passed. Temporary form/dialog captures were inspected and removed; no images were added to the repository. Disabled-upload recovery itself is verified by the PostgreSQL HTTP tests, rather than changing the running app's configuration.

**Subsequent rollout:** these fixes were included in the verified R2 staging deployment; see the [current live evidence](staging-r2-evidence.md). The review session itself remained local: No external mail, live-data operation or deployment was performed. Existing M5/M6, provider, administration and release limitations in the staging handoff remain unchanged. No unrelated milestone work was started.
