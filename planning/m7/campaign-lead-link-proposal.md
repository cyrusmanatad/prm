# Campaign lead links

**Confirmed (2026-11-28)** — approved as [D43](../shared/decisions.md#d43--source-campaign-on-lead-registrations); not implemented. The behaviour, scope, Brand confirmation rule and readiness condition are confirmed. Column, function and audit-event names under *Data and access design* are **Proposal / implementation defaults**. Let a Partner record which shared campaign brief a registered lead came from, so both sides can see which registered leads a brief produced. This extends the [campaign brief contract](campaign-brief-contract.md) (D42).

## Problem

A shared brief and the leads a Partner registers with the same Brand live under the same partnership but are unrelated records. A Brand cannot tell which registrations came from a brief, and a Partner cannot record that a lead was work for a brief.

## Proposed behaviour

- **What is linked.** A lead registration (`lead_registrations`) has an optional **source campaign**. The Partner's private lead (`pipeline_entries`) is not linked: the Brand only ever sees what the Partner already chose to register.
- **Cardinality.** One source campaign per registration; a campaign can be the source of many registrations. This is the single *primary source*. Crediting several campaigns ("influence") is a separate, later table and does not change what this field means.
- **Who links.**
  - Partner owners and admins set, change or clear the source campaign when registering a lead and when editing an existing registration.
  - The choices are briefs shared with that Partner on the registration's partnership.
  - A lead can be linked after the brief's due date. The due date is a content deadline, not an attribution window, because leads often arrive later.
  - The Brand cannot set, change, confirm or reject the link in this scope.
- **Link time.** The registration records when its source campaign was last set (`campaign_linked_at`). The Brand sees "Linked on …" next to the campaign, and every change is audited, so late tagging is visible rather than blocked.
- **Brand view.**
  - The campaign detail shows a **Registered leads** section: active linked registrations with stage, registration date and link date, each opening the existing registration drawer.
  - The section also shows a stage summary, for example how many linked leads are pitched, negotiating or onboarded. The useful measure is how far a brief's leads progress, not only how many there are.
  - The campaign list shows the count of linked active registrations.
  - The lead registrations list gains a **Campaign** filter next to Partner and stage, and the registration drawer names the source campaign.
- **Partner view.**
  - The shared brief shows the Partner's own registrations linked to it.
  - The register and edit forms gain an optional **Source campaign** select, shown only when the selected Brand has shared at least one brief.
  - The Partner's private lead shows its registration's source campaign as read-only text with a link to the brief. Editing stays on the registration, so the link has one place to change.
- **Lifecycle.**
  - Existing visibility rules apply unchanged. When a registration is withdrawn or the relationship ends, the Brand no longer sees it or its link.
  - The link and its time are kept on withdrawn registrations as Partner history. Re-sharing a withdrawn registration in the same partnership keeps them.
  - A later partnership with the same Partner has a different ID, so old campaigns cannot be selected for it.
  - Setting, changing or clearing the link is a Brand-visible change: it updates the registration version and its shared update time.

## Data and access design

- Add nullable `campaign_id` and `campaign_linked_at` columns to `lead_registrations`. Both are set or cleared together.
- Enforce at the database level that a campaign link and a registration share a partnership. Add a unique `(id, partnership_id)` index on `campaigns` and a composite foreign key `(campaign_id, partnership_id) → campaigns(id, partnership_id)`. A campaign's recipient cannot change once shared and campaigns cannot be deleted by the runtime role, so the key stays valid.
- **Proposal:** extend `app_register_lead` and `app_update_lead_registration` with an optional campaign argument, so registering with a campaign is one atomic step.
  - Keep the existing lock order (partnership, pilot lock, source lead) and the paused-work check.
  - A campaign that is not shared on that partnership returns `unavailable` before the foreign key is reached.
- No new read policy is needed. Brand and Partner reads of registrations and campaigns already follow relationship and workspace availability. Brand queries join campaigns under both policies.
- Record audit events: `lead_registration.campaign_set` and `lead_registration.campaign_cleared`.
- Add `campaign_id` and `campaign_linked_at` to the `lead_registrations` privacy-export columns. Closure review already covers registrations, and the new columns change their review fingerprints as expected.

## Out of scope

- Incentives, lead protection, attribution or commissions on linked leads.
- Revenue or conversion metrics beyond the stage summary.
- Crediting several campaigns per registration.
- Brand-side tagging, confirmation or rejection.
- Linking private leads.

## Later: Brand confirmation

Brand confirmation is not needed while links carry no payout or protection: an approval step would add work without a consequence, and links would sit unconfirmed. When incentives, 60-day lead protection or commissions depend on a source campaign, the same release must add a Brand accept/reject step with a reason, and only accepted links count. That release may also restrict eligible links to leads registered after the brief was shared.

## Readiness

**Confirmed:** build this only once pilot Brands ask which leads came from a brief. The rest of M7 remains gated on proven demand in the [implementation roadmap](../shared/implementation-roadmap.md).

## Verification gates

- PostgreSQL RLS and integration:
  - linking to another partnership's campaign, to a draft and to another Brand's campaign is rejected
  - another Partner and another Brand cannot see the link
  - a stale version is rejected
  - the link time is set on change and cleared with the link
  - withdrawal, ending a relationship and a replacement partnership behave as described above
  - export and closure include the link
- Browser journeys at desktop and mobile widths:
  - a Partner registers a lead with a source campaign and sees it read-only on the private lead
  - the Brand sees it on the campaign, with link date and stage summary, and filters registrations by campaign
  - axe reports no violations
- Formatting, type check, build and the existing lead visibility suites pass.

## Decisions (approved in D43)

| Question | Decision |
|---|---|
| One campaign per registration? | Yes, as the single source campaign. Multi-campaign influence comes later as a separate table. |
| Link after the due date? | Allowed. Record and show the link time; audit every change. |
| Brand confirmation? | Not now. Required in the same release that ties incentives, protection or commissions to the link. |
| Show in the Partner's pipeline? | Yes, read-only, with editing only on the registration. |
