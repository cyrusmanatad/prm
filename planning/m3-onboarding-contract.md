# M3 onboarding and public presence contract

**Confirmed (D20):** onboarding starts through an explicit creator action on a lead. No pipeline stage automatically starts or completes onboarding. The form collects business/contact name, business email, website and collaboration brief. Tax IDs, permits and shipping details are excluded. Initial storage is private local storage with synthetic data; provider/region selection remains open.

**Proposal / implementation defaults:** invitations expire after seven days and exchange once for a 24-hour browser session, bounded by invitation expiry. Lead links expire after 30 days. Existing CRM editing roles may manage these features; viewers read only. These defaults are implementation contracts rather than new commercial policy.

## Creator routes

All creator routes require a verified identity, selected workspace and locked active membership. Mutations and audit share the tenant transaction. Inaccessible IDs return 404; viewer writes and revoked memberships return 403. Browser mutations redirect with status/errors; explicit JSON requests receive JSON.

| Route | Contract |
|---|---|
| `GET /public-presence` | Allowlisted private media-kit settings and lead-link metadata |
| `PUT /media-kit` | `title` (160), optional `bio` (5,000), HTTP(S) `website` (500), up to eight `social_links` (label 60 / HTTP(S) URL 500), boolean `published`, `version`; publishing additionally requires explicit `consent` |
| `POST /lead-links` | Create scoped inbound form link; return its URL once |
| `DELETE /lead-links/{id}` | Revoke inbound capture |
| `GET /onboarding-requests` | Latest 100 request summaries, submitted/awaiting-review indicator; optional `request` UUID for authorized detail/history |
| `POST /onboarding-requests` | Explicit `pipeline_entry_id`, `label` (160); create request and return invitation URL once |
| `POST /onboarding-requests/{id}/correct` | Current `version`, required correction `note` (2,000); submitted → correction |
| `POST /onboarding-requests/{id}/accept` | Current `version`; submitted → accepted |
| `POST /onboarding-requests/{id}/revoke` | Current `version`; end all invitation/session access |
| `POST /onboarding-requests/{id}/renew` | Current `version`; replace an unrevoked invitation, expire all prior browser sessions, extend invitation seven days |
| `GET /onboarding-requests/{id}/documents/{document}` | Authorized clean attachment download |
| `POST /onboarding-requests/{id}/documents/{document}` | Retry a pending scan; editing role required |

Request status is draft, submitted, correction, accepted or revoked. Row locks and versions reject stale writes. Accepting a submission does not change the pipeline stage. Leads with onboarding history cannot be deleted through the pipeline; a conflict directs the user to revoke access while retaining the history. Separate requests may reference the same lead; no deal-merging rule is inferred. The awaiting-review indicator and persistent submission events are the in-app notification. Email invitation/submission delivery and durable external actions remain M5 work.

## Public and capability routes

Public media kits are Laravel-rendered HTML at `GET /kits/{id}`. A reviewed security-definer projection exposes only title, introduction, website and approved social-link labels/URLs for a currently published, consented kit. Unpublishing immediately returns 404. Views increment an aggregate counter; no per-view identity/IP history is collected. Social accounts are manually entered public links, without connection credentials, identity verification or fetched statistics. Unknown nested fields and unsafe URL schemes are rejected, and the public SQL projection independently strips any internal metadata. There is no public directory or indexing opt-in beyond explicit kit publication.

Inbound links open `/lead#token=...`. `POST /lead` accepts the token, UUID `idempotency_key`, business/contact name, email and explicit contact-sharing consent. A narrow security-definer command validates expiry/revocation, creates one private Lead at zero estimated value in PHP, and retains a private receipt. Repeating the same key/payload returns the same acknowledgement; changed payload returns 409. No CRM projection or membership is returned. Revoked/expired links return 404.

Invitations open `/onboarding#token=...`. The browser removes the fragment and submits the secret in `POST /onboarding/session`, with CSRF protection and a six-attempt/minute exchange throttle. Only a token hash is stored in the invitation table. The one-use exchange creates a separately hashed, scoped session and rotates the browser's session ID. Server session entries and one-time issuer-link flashes are encrypted with Laravel's application key; invitation input is excluded from validation old-input flashes.

A public session is keyed by request ID in the browser's server session. Every request resolves the capability through a reviewed security-definer function, locks its request, checks expiry/revocation, then establishes transaction-local organization/capability settings. The path ID must match the resolved request. RLS grants access only to that request's approved data, submissions, events and documents; private pipeline/contact/template tables retain membership-only policies. Tenant context clears on commit/rollback. If revocation holds the request lock first, a waiting capability is denied after revocation commits.

| Route | Contract |
|---|---|
| `GET /onboarding/form/{id}` | Safe form fields, status, correction note, version, file IDs/names/scan states |
| `PATCH /onboarding/form/{id}` | Partial drafts may contain blank fields; complete replacement of approved fields; current version required |
| `POST /onboarding/form/{id}/documents` | Current version plus one validated file; increment request version |
| `GET /onboarding/form/{id}/documents/{document}` | Only this request's clean file, forced attachment |
| `DELETE /onboarding/form/{id}/documents/{document}` | Current version; remove only editable, previously unsubmitted files |
| `POST /onboarding/form/{id}/submit` | Required business/contact/email/brief, optional HTTP(S) website, current version, explicit sharing consent and UUID idempotency key |

Draft save and upload do not submit the form. Reloading the same request URL in the same browser resumes saved progress during its session lifetime. Lost/expired sessions require a replacement invitation from the creator. Versions reject stale saves; the UI preserves rejected edits and requires explicit reload. A successful mutation acknowledges its submitted version plus one, so a later edit arriving during the redirect cannot silently become the base version of an older draft. Submitted/accepted forms are read only until the creator requests correction. Each submission appends an immutable field/document-checksum snapshot and consent timestamp. Duplicate submit keys compare normalized fields and version; repeated identical requests return the original submission, changed payloads return 409. Prior submission rows have no runtime update/delete policy.

All public browser mutations use the existing CSRF middleware. Exchanges, inbound capture and expensive form mutations are rate limited, use private/no-store responses and no-referrer headers, and load no third-party form scripts. Capability hashes, raw tokens, storage keys, CRM private notes and actor/organization fields are absent from public page props, including partial reloads. Document bytes are materialized inside the authorized transaction before it commits.

## Upload safety

Uploads accept actual MIME types PDF, PNG, JPEG or plain text, at most 2 MB each and five files per request. The server generates the object key, normalizes the download filename, stores SHA-256/size/type metadata and quarantines the file. A bounded ClamD INSTREAM scan releases it only on an exact clean reply; detection marks it rejected, and scanner errors/timeouts retain pending state. See the [official ClamD protocol](https://docs.clamav.net/manual/Usage/ClamdProtocol.html). The pinned official ClamAV container has no published port or access to private storage; the application streams bytes over the private Compose network.

Pending/rejected files cannot be downloaded or submitted. An owner may retry a pending scan. Unused rejected files may be removed before submission. Snapshots retain submitted references, so those files cannot be removed through form editing. Downloads recheck the checksum and scope and force attachment with nosniff/sandbox headers. No direct storage URL is exposed.

A crash between private object creation and database commit can leave an unreachable orphan; storage reconciliation/retention is an operations follow-up before live data. Scanning is synchronous and bounded; no asynchronous delivery/idempotency claim is made. The app does not fetch supplied website URLs. Actual object-provider behavior, production retention/deletion rules and live-data region remain release decisions.

## Acceptance boundary

See [M3 status](m3-status.md) and [evidence index](evidence/m3/README.md). Development completion is distinct from production readiness and carried-forward manual screen-reader, full browser zoom and native Safari reviews. Public kit indexing controls, high-volume request pagination, additional form steps and external notifications are follow-ups; sensitive collection remains excluded by D20.
