# M3A portal and relationship contract

**Confirmed:** implements D21–D23 under the user's implementation request. Existing M1–M3 evidence and manual release gates remain applicable.

## Identity and routes

`/partner/login` and `/brand/login` use the same identity, password recovery, verification, session regeneration, throttles and logout. `/login` remains the legacy neutral entry. Portal entry records a presentation preference, never a membership. Only allowlisted portal destinations are used; arbitrary intended URLs are discarded. Public invitation landing pages keep fragment tokens out of access logs; a CSRF-protected prepare action retains the encrypted token in the server session for 15 minutes through sign-in/verification/selection. This grants no relationship access. `/partner` requires a selected creator workspace; `/brand` requires a selected brand workspace. Wrong-context requests redirect to workspace selection without dashboard props. Workspace selection rechecks membership and exposes the selected workspace's portal link, retaining the legacy selector destination. Existing identities can create either workspace type through the workspace selector.

Portal registration persists a pending workspace ID/type on the new identity, surviving session loss. Following verification, `/portal/enter` provisions it once using that fixed ID and the account name, then selects it. Provisioning is also resumed by login/verification in a different browser. Existing legacy registration is unchanged. A retry of the same provision ID is idempotent. Existing workspaces backfill to creator; their IDs, memberships and private records stay intact. Workspace type cannot be edited. No existing onboarding or prospect records are linked or reclassified.

## Relationship lifecycle and permissions

Only brand owners/admins invite a verified recipient email, with a seven-day invitation. Development uses a copied fragment-token link; no external message is sent. A token is random (256 bits), stored only as SHA-256, and exchanged in a CSRF-protected POST after login/verification and explicit consent. Only creator owners/admins can accept into their selected creator workspace, and the verified identity's email must match the recipient. Acceptance locks the invitation, rechecks expiry/status/recipient/workspace types and creates one active relationship per brand/creator pair. Duplicate outstanding invitations per brand/email conflict; duplicate active relationships conflict. Expired/revoked/consumed tokens cannot be accepted. Invitation revocation and acceptance serialize on the same row. Either party's owner/admin may terminate an active relationship; no private records or workspace memberships change.

Sharing is deliberately limited to workspace display names, relationship ID, status and dates. Acceptance explicitly authorizes those fields. Names are consented snapshots at acceptance; later private workspace edits do not silently change shared information. Each side sees its own active relationships only. A revoked relationship disappears from both dashboards. Brand invitation history is private to its brand; token hashes/emails never enter dashboard props. Audit events record IDs and event names, not tokens or emails.

Forced PostgreSQL RLS protects both new tables. There are no runtime mutation policies; fixed-search-path security-definer functions are the sole lifecycle mutation paths and validate actor/context/roles. Runtime gets explicit EXECUTE grants. Membership locking and transaction-local context also apply to page requests and jobs. No shared files, exports, caches or job payloads are introduced; existing creator file/export/worker paths retain their own workspace authorization. Relationship membership never grants creator workspace membership or private CRM access.

## UI and deferred capabilities

Both compositions derive from the retained reference HTML and local assets, without demo event handlers. Reference content is enabled only by an explicit component fixture prop in the separate browser-test harness; no application route, request parameter or page prop enables it, and production builds force fixture rendering off. Real account sections derive available private pipeline counts/currency totals and explicit relationship rows; finance, campaign performance, health, commissions, tiers, automation and lead registration show unavailable states. Unsupported controls are disabled and explained. Existing CRM/templates/onboarding/public presence remain accessible. Only five columns appear on boards; the full seven-stage filter/editor and direct detail routes retain omitted-stage access and aggregate semantics.

## Migration and verification

The additive migration defaults all existing organizations to creator and creates empty invitation/relationship tables. It does not modify pipeline/onboarding rows. Rollback drops only M3A objects and workspace type. Validate real restricted-role PostgreSQL, denied cross-brand/creator mutations, wrong recipient/expiry/replay, membership revocation, partial props, omitted-stage detail access, session/context journeys and reference visual comparisons. Exact visual acceptance requires the recorded comparisons and review; implementation alone is not acceptance.

## Partner navigation and lead consolidation follow-up

**Confirmed (2026-10-22):** consolidate the separate pipeline page into the partner portal and use section-specific partner URLs. `/partner/leads` is the canonical lead screen; `/partner` and HTML `/pipeline` requests redirect there, retaining query strings. Add lead uses a modal; selected leads use a modal drawer with the existing seven-stage editor, private notes/activity, snooze, deletion and explicit Start onboarding. The five-column board, filters, currency-separated totals and pagination share one screen; Repeat Client/Lost remain reachable through filters and links. Database schemas, stored stages, permissions and existing JSON/mutation endpoints remain intact.

| Partner section | Canonical page URL | State |
|---|---|---|
| My leads | `/partner/leads` | Implemented |
| Contacts and reminders | `/partner/contacts` | Implemented; avatar dropdown |
| Outreach templates | `/partner/outreach-templates` | Implemented; avatar dropdown |
| Onboarding requests | `/partner/onboarding` | Implemented; avatar dropdown |
| Media kit | `/partner/media-kit` | Implemented |
| Inbox | `/partner/inbox` | Reserved; navigation disabled, no page yet |
| Campaigns | `/partner/campaigns` | Reserved; navigation disabled, no page yet |
| Earnings | `/partner/earnings` | Reserved; navigation disabled, no page yet |
| Autopilot | `/partner/autopilot` | Reserved; navigation disabled, no page yet |

Legacy HTML CRM/template/onboarding/public-presence page URLs redirect with their query strings. JSON clients retain their existing contracts. Workspace switching and account security remain at the shared `/workspaces` page; sign out retains its existing action. The avatar dropdown includes Lucide icons and keyboard navigation. This follow-up changes real-account navigation and lead management; retained M3A reference-fixture parity evidence remains historical and is not evidence for these new interactions.

**Confirmed brand navigation follow-up (2026-10-22):** the brand header now places an account avatar immediately beside Invite partners. Its dropdown matches the partner menu's styling, Lucide icons and keyboard interaction, with the existing shared workspace/account-security link and Sign out action. These links move out of the brand footer; creator-private CRM links remain specific to the partner portal. This approved navigation change supersedes the original brand reference composition at the header.
