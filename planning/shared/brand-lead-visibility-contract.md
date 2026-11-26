# Brand lead visibility — implementation contract

**Date:** 2026-11-18. **Decision:** [D38](decisions.md#d38--brand-lead-visibility-and-partner-records). **Source:** [implementation handoff](brand-lead-visibility-handoff.md). **Verification:** [evidence](brand-lead-visibility-evidence.md). **Status:** implemented and locally verified on branch `feat/brand-lead-visibility`; not merged, not deployed.

Labels: **Confirmed** is the user's stated requirement. **Implementation choice** is a default the handoff allowed Claude to settle; each can be changed without a data migration unless noted.

## 1. Rules

| Rule | Label |
| --- | --- |
| A Brand sees a lead only after a Partner explicitly registers it with that Brand. Nothing is inferred from a partnership, a company name, an email domain or an existing lead. No migration publishes anything. | Confirmed |
| Lead registrations is a working Brand destination; Partner and lead names open their records. | Confirmed |
| `Leads registered` counts every currently Brand-visible registration, all dates and all seven stages; `0` when none. | Confirmed |
| `Leads (30d)` counts one Partner's registrations for this Brand in the rolling last 30 days and opens the matching list. | Confirmed |
| `Last active` is the latest of a successful Partner sign-in and a change to a lead registered with this Brand. | Confirmed |
| The Partner roster is paged on the server, with totals independent of the page. | Confirmed |
| Ending a partnership is an icon button that keeps the existing confirmation and permission checks. | Confirmed |
| Brands read registrations only. They cannot move stages, edit, export or see private CRM. | Implementation choice |
| Partner owner/admin register, edit the shared copy, withdraw and edit the shared profile. Other Partner roles keep their normal private CRM rights only. Every active Brand member, including viewers, can read. | Implementation choice |
| One lead can be registered with several Brands, by a separate explicit action each; the copies are independent. | Implementation choice |
| Page sizes: 10 Partners on the roster, 10 registrations on a Partner record, 25 on the consolidated list and on the Partner's own list. | Implementation choice |

## 2. What is shared

**Registration (per lead and Brand).** Lead name shown to the Brand (entered by the Partner; it starts as the private lead name and is stored separately from it), optional summary (up to 1,000 characters), current stage, first registration time, time of the last shared change, the Partner's workspace name and, only if the Partner ticks the option, the lead's contact name and email.

**Never shared.** Private notes, estimated value and currency, next action, reminders, activity history, outreach drafts, onboarding records and documents, finance records, other leads, other Brands, the source lead's ID, the registering member, member lists, and any account or security data.

**Shared Partner profile (per Partner workspace, optional).** Business contact name, business contact email, website or public profile address (`http`/`https` only) and a short description (500 characters). It is shown to every Brand the Partner is connected to; the editor says so. Nothing is copied from the account. Other personal data needs a new decision.

## 3. Lifecycle

| Event | Effect |
| --- | --- |
| Add a lead with `This lead is for` set (optional, added 2026-11-18) | The lead and its registration are saved together; the Brand sees the lead's name and stage, with no summary and no contact. Left empty, the lead is private as before. If the Brand cannot be registered with, neither is saved. Owners and admins only; other roles do not see the field. |
| Register | Creates one registration. Repeating the request returns the existing one unchanged, including its registration time. |
| Partner moves the private lead's stage, or changes its contact when the contact is shared | The registration is updated in the same transaction and its last-shared-change time moves. |
| Any other private edit (notes, value, name, activity) | Nothing shared changes; the Brand's activity signal does not move. |
| Edit shared details | Versioned; a stale version is refused with a reload message. |
| Withdraw | Confirmed in a dialog, versioned, audited. The Brand loses the lead at once. Allowed while the Brand is suspended or Professional work is paused. |
| Re-share after withdrawal (same partnership) | The same registration becomes active again with its **original** registration time, so it never re-enters the 30-day count as new. |
| Private lead deleted | Its registrations are withdrawn in the same transaction and the shared copy is cleared (name replaced, summary and contact removed). A failed delete changes neither. |
| Partnership ended | Its registrations are withdrawn in the same transaction. A later partnership between the same workspaces starts with none. |
| Either workspace suspended | The Brand sees the Partner as Paused with no figures, profile, activity or registrations; everything returns when the suspension is lifted. New registrations with a suspended Brand are refused. |
| Professional access expired or not yet active | Registering, editing and the profile editor are paused like other writes. Existing registrations stay readable, as other existing records do. |

## 4. Figures

- **One visibility rule.** A registration is Brand-visible when it is active, the partnership is active and neither workspace is suspended. This is the row policy of `lead_registrations`; the card, roster counts, lists, details and activity all read through it.
- **Leads registered** = visible registrations for the Brand. It equals the unfiltered list total and ignores the roster page and search.
- **Leads (30d)** = visible registrations of that partnership with `registered_at` in `[now − 720 hours, now]`. The lower bound counts; later timestamps do not. One database clock reading is used per response. Stage moves and edits never change `registered_at`.
- **Active partners / All N** = all active partnerships of the Brand, paused ones included, as before.
- **Last active** = the later of (a) the Partner workspace's latest sign-in and (b) the latest shared change among the Brand-visible registrations of that partnership. It carries a kind: Signed in, Lead registered or Lead updated. On an exact tie the lead event is reported. Nothing recorded shows `Not yet recorded`. No history is invented for existing records.

### Sign-in attribution (Implementation choice)

A sign-in is recorded once, for one creator workspace, when all of the following hold:

1. The password sign-in form succeeded and the account was not suspended.
2. The email is verified and, where owner MFA applies, the authenticator or a recovery code was accepted. The record is written by the workspace middleware, which runs after those checks.
3. It is the first workspace entered in that session within 60 minutes of signing in, and it is a creator (Partner or Professional) workspace. If the first workspace entered is a Brand workspace, nothing is recorded.

Not recorded: failed passwords, suspended accounts, pending or failed MFA, registration, later requests in the same session, other workspaces of the same account, Brand sign-ins and operator support access. Only a timestamp per workspace is stored; a Brand sees it only for an active, unpaused Partner and only if it is not older than the partnership.

## 5. Data and access

Migration `2026_11_18_000003_add_brand_lead_visibility` (`database/sql/brand-lead-visibility.sql`), additive:

| Object | Purpose |
| --- | --- |
| `lead_registrations` | The shared copy. Unique per partnership and source lead. Forced row security with two read-only policies (Brand-visible rule; the registering workspace's own rows). |
| `workspace_sign_ins` | One timestamp per creator workspace. No policies and no runtime table privileges. |
| `partner_shared_profiles` | Optional profile. Read policy for the owning workspace only. |
| `app_register_lead`, `app_update_lead_registration`, `app_withdraw_lead_registration`, `app_save_shared_profile` | The only write paths; they check role, workspace, partnership, suspension, Professional access and versions, and write audit events. |
| `app_partnership_sharing`, `app_partner_signed_in_at`, `app_partner_shared_profile`, `app_record_workspace_sign_in` | Narrow read/record helpers bound to the caller's workspace. |
| Triggers on `pipeline_entries` and `partnerships` | Keep registrations in step with source edits, source deletion and partnership ending, inside the same transaction. |

`runtime-grants.sql` removes the runtime role's write privileges on the three tables and all privileges on `workspace_sign_ins`. Existing pipeline, CRM and account policies are unchanged. Audit events: `lead_registration.registered`, `.reshared`, `.updated`, `.withdrawn`, `.source_deleted`, and `shared_profile.updated` (IDs only).

Writers always lock in the order partnership, workspace pilot lock, source lead, registration, so revocation, source edits and registration changes cannot deadlock. A revocation that locks first denies a waiting registration; a registration that locks first completes and is then ended by the waiting revocation.

**Rollback:** the migration rolls back cleanly while the tables are empty. Once a registration or shared profile exists, rollback is refused; fix forward.

## 6. Routes

| Route | Purpose |
| --- | --- |
| `GET /brand` | Hub. New props: `partners` (roster page), `partnerPagination {page, perPage, total}`, `partnerFilters {q}`, `metrics {activePartners, leadsRegistered}`. Query: `q` (literal name search), `page`. |
| `GET /brand/partners/{relationship}` | Partner record with shared profile and its registrations. Query: `page`, and (2026-11-26) `q`, `stage`, `window=30d` and `sort` with the same rules as the consolidated list. The partner is always the route's relationship; `partner` and `per_page` in the query are ignored. Returns `filters` and stage-only `facets`; header figures stay unfiltered. See [Partner record evidence](brand-partner-record-evidence.md). |
| `GET /brand/lead-registrations` | Consolidated list. Query: `partner` (partnership ID), `stage`, `q` (literal lead name search), `window=30d`, `page`. Sorted by registration time, newest first, then ID. |
| `GET /brand/lead-registrations/{registration}` | Read-only lead detail. The list query, or `from=partner`, is carried only to build the back link. |
| `GET /partner/brand-sharing` (and `/professional/brand-sharing`) | Partner screen. Query: `lead` (own lead to register), `page`. |
| `POST /pipeline` | Existing lead capture. New optional `register_with` (partnership ID); errors are reported on that field. The page payload gains `pipeline.registration {canRegister, brands}`. |
| `POST /lead-registrations` | `partnership`, `entry`, `display_name`, `summary`, `share_contact`. |
| `PATCH /lead-registrations/{registration}` | `version`, `display_name`, `summary`, `share_contact`. |
| `DELETE /lead-registrations/{registration}` | `version`. Withdraws. |
| `PUT /partner-shared-profile` | `version` (0 before the first save), `contact_name`, `contact_email`, `website_url`, `description`. |

Unknown, foreign, withdrawn, ended and paused records all answer 404. A `partner` filter naming another workspace's partnership returns an empty list. A page past the end returns the last page. Invalid filters are refused with 422.

**Compatibility note:** on `GET /brand`, `relationships` keeps its shape but now holds the current roster page only (it previously held every partnership). Use `metrics.activePartners` and `partnerPagination.total` for totals. The Partner and Professional `relationships` are unchanged.

## 7. Screens

- **Brand hub:** real `Leads registered` card with a link, searchable paged roster, Partner name links, linked `Leads (30d)`, `Last active` with relative time, exact time on hover and the activity kind, and an icon button named `End partnership with <Partner>` that opens the existing confirmation. Tier, Sales, Revenue, Health and the other cards stay `Unavailable`.
- **Lead registrations, registered lead, Partner record:** new Brand screens sharing one header (`BrandChrome`, extracted from the hub) and the shared table, filter, pagination, detail-list, stat-card and badge components.
- **Brand sharing (Partner):** opened from a lead's drawer (`Register with a Brand`), the account menu, or the brand-partners panel. It states what a Brand will and will not see, lists the workspace's registrations with edit and confirmed withdraw, and holds the shared profile editor.

## 8. Open items

- Staging migration and any demonstration registrations: see the [runbook in the evidence](brand-lead-visibility-evidence.md#staging-runbook-not-executed). Nothing was run against staging.
- The Professional navigation was left at its approved eight tools; Professional workspaces reach Brand sharing from a lead and from the brand-partners panel.
- A Partner's own list shows `Ended partnership` instead of the Brand's name once a partnership has ended, because ended partnerships are not readable.
