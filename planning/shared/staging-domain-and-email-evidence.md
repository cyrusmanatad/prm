# Staging domain and email verification

**Scope/date:** 2026-11-23 UTC, existing staging app/web images `0031e80`; configuration changes only. **Confirmed:** canonical application URL is `https://app-stg.weavdash.com`; transactional sender is `WeavDash <no-reply@weavdash.com>` through the existing Brevo SMTP credentials. Production deployment and provider-plan changes are outside this scope.

## Current configuration

| Area | Verified configuration |
| --- | --- |
| Cloudflare DNS | Proxied A record `app-stg` targets `216.219.85.134`; public answers are Cloudflare edge addresses, not the origin IP. |
| Origin HTTPS | Dedicated certificate for `app-stg.weavdash.com`, valid 2026-11-23 through 2041-11-19; certificate/key match. Files `/opt/nginx/ssl/weavdash-staging.pem` and `weavdash-staging.key`, mode 600. Preserve Full (strict); dashboard encryption mode was not independently inspected. |
| Application URL | `APP_URL=https://app-stg.weavdash.com` in private `staging.env`, effective in app, worker and scheduler. |
| Legacy hostname | HTTP/HTTPS `weavdash.cyrusmanatad.com` returns 301 to the canonical hostname using `$request_uri`, preserving paths/query strings. Keep its DNS and existing certificate while the redirect remains active. |
| Email | Private raw-format `smtp.env`: `MAIL_FROM_ADDRESS=no-reply@weavdash.com`, `MAIL_FROM_NAME=WeavDash`; existing Brevo login/key, SMTPS port 465 and SMTP mailer preserved. User confirmed the sending domain is authenticated in Brevo. |
| Other settings | R2 attachment storage, secure host-only sessions, owner MFA, image tags and database schema unchanged. |

The user activated the root-owned proxy changes using checksum-guarded sudo scripts after candidate `nginx -t` checks. Private backups are `backups/domain-20261123-054830`, `backups/domain-redirect-20261123-061831` and `backups/mail-sender-20261123-071420`. Proxy activation restores its backup on validation/reload failure; application configuration changes also retained rollback copies. App/worker/scheduler were recreated after environment changes and web restarted. Temporary certificate/key copies were removed after a protected server backup; no secrets or screenshots were committed.

## DNS conflict and correction

During Brevo setup, the app hostname became a DNS-only CNAME to `app-stg-weavdash-com.brand.brevosend.com`, resolving to `94.143.16.132`; public HTTPS timed out while direct origin checks returned 200. The user's DNS screenshot identified the conflicting CNAME. The user replaced it with the proxied A record above. Brevo DKIM, verification TXT, DMARC and Google Workspace records were preserved.

**Operational rule:** reserve `app-stg` for the application. Optional Brevo branding needs a different unused hostname, such as `send.weavdash.com`, configured using the records Brevo supplies. Branding migration has not been performed or verified. Do not rename existing branded records by guessing their targets, enable click tracking for capability links, or treat a branding warning as a reason to overwrite the app record. See [email design rules](email-design.md#rules-for-new-email) and [Brevo domain setup](https://help.brevo.com/hc/en-us/articles/35337929909778-Set-up-your-domain-in-Brevo).

## Verification and limits

| Check | Result |
| --- | --- |
| `openssl x509` metadata and public-key SHA-256 comparison | Correct hostname, valid certificate and matching key; private key not printed. Origin SNI later presented the new certificate. |
| Candidate/live `nginx -t`, guarded activation and reload | Passed. Domain activated 06:11:29 UTC; legacy redirect activated 06:18:59 UTC. |
| `docker compose ... config --quiet` and runtime assertions | Passed. All three backend services report the canonical URL and new sender. Sender change preserved all other SMTP fields; storage/Compose/application environment hashes unchanged. |
| Services and read-only monitoring snapshot | Six services running; web/PostgreSQL/Redis healthy; no app/web/worker/scheduler restarts or OOM kills. All 14 monitoring measurements below thresholds, no queued or failed jobs. |
| Chromium reviewer smoke | Partner, Brand and Professional sign-in/MFA, hubs and Workspaces passed at 1440×900 and 390×900; no overflow/browser errors or business writes after sign-in/workspace selection. Administration denied to ordinary reviewers. No screenshots retained. |
| Final encrypted DNS queries via Cloudflare and Google | Both return Cloudflare edge A records `104.21.27.159` and `172.67.143.60`, without the Brevo alias. Edge addresses may change; do not copy them into the origin A record. |
| Final public `curl` checks with certificate verification | `/up`, `/login`, `/partner/login`, `/brand/login` and `/professional/login` all 200. Legacy `/partner/login?dns_check=1` returns 301 to the identical path/query on the new host. Four unrelated shared sites also returned 200 after proxy activation. |
| User's Forgot password test | **Confirmed:** email arrived using the new WeavDash sender. This verifies application dispatch, Brevo acceptance and inbox receipt for that request. |

**Unverified:** completing the received password-reset link and signing in afterward; received-link hostname and DKIM/DMARC headers; broad Gmail/Outlook/Apple Mail visual acceptance; new-branding setup; certificate-expiry monitoring; production readiness. Earlier SSH timeouts recovered without SSH/firewall changes; cause unconfirmed. This receipt does not close the full M5 provider matrix or M6 release gates. Earlier provider journeys retain their original scope in [provider evidence](../m5/provider-delivery-evidence.md).

Documentation follow-up: relative-link validation passed for 386 file links across eight updated documents; `git diff --check` passed. Existing unrelated workspace edits were preserved. Application suites were not rerun for this documentation-only follow-up.
