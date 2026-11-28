# Incentives and campaign lead links — contract

**Confirmed (2026-11-28)** — the user authorized building [D43](../shared/decisions.md#d43--source-campaign-on-lead-registrations) and [D44](../shared/decisions.md#d44--bring-brand-incentives-forward) together, now, with the recommended answers to the open D44 questions. This contract turns the [lead link proposal](../m7/campaign-lead-link-proposal.md), the [incentives proposal](incentives-proposal.md) and the approved [mockup](../shared/campaigns-incentives-mockup-review.md) into implementation rules. Names of tables, columns, functions and audit events are **Proposal / implementation defaults**.

## Decisions applied

- **Confirmed:** offers and awards ship together, on top of D43.
- **Confirmed:** cash rewards are allowed as recorded obligations, labelled "paid outside WeavDash". Nothing is paid through the platform.
- **Confirmed:** a Partner sees only its own cap and remaining awards. It never sees the total cap or other Partners' counts. When the total cap is used up, the Partner sees only that no awards are left.
- **Confirmed:** three reward types: cash, non-cash and percentage bonus.
- **Confirmed:** a registration may support awards for different incentives, but only one non-released award per incentive.
- **Confirmed (mockup):** award review in a side panel, a **Leads per award** field, a 7-day late-link flag, an optional Partner note and a fulfilment reference shown to the Partner.

## Part A — source campaign (D43)

- `lead_registrations` gains `campaign_id`, `campaign_linked_at` and `campaign_confirmed_at`. The link and its time are set or cleared together. A composite foreign key `(campaign_id, partnership_id) → campaigns(id, partnership_id)` keeps the link inside the partnership.
- `app_register_lead` and `app_update_lead_registration` take an optional campaign. Only briefs shared on that partnership are accepted; anything else returns `unavailable`. Changing the link updates the registration version, its shared update time and the link time, and records `lead_registration.campaign_set` or `lead_registration.campaign_cleared`.
- **Brand confirmation:** approving an award for a campaign-linked incentive sets `campaign_confirmed_at` on the cited registrations. While a registration is cited by a pending, approved or fulfilled award of a campaign-linked incentive, its link cannot change (`locked`).
- **Brand view:** the campaign list shows linked active registrations; the shared campaign shows **Registered leads** with a stage summary, link dates and the late-link flag; Lead registrations has a **Campaign** filter and the drawer names the source campaign.
- **Partner view:** the register and edit forms have an optional **Source campaign** select, shown when the Brand has shared at least one brief on that partnership. The shared brief lists the Partner's linked registrations and offers **Register a lead for this brief**. The private lead shows the source campaign read-only.

## Part B — incentives (D44)

### Records

| Table | Holds |
|---|---|
| `incentives` | Brand workspace, title, terms, reward type, cash amount in minor units and currency, or reward text; rule stage; leads per award; optional shared campaign; start and end dates; optional caps per Partner and in total; state *draft*, *shared* or *ended*; draft audience; version and timestamps |
| `incentive_partners` | the audience, written when the incentive is shared |
| `incentive_awards` | incentive, partnership, state, amount, Partner note, decision and reasons, fulfilment date and reference, version |
| `incentive_award_registrations` | each cited registration, with a name and stage snapshot taken at claim time; released when the award is rejected or cancelled |

### Rules

- **Drafts** belong to the Brand. Owners and admins create and edit them; other members read them. Saving a draft checks the title only.
- **Sharing** checks every field, publishes the audience and makes the terms read-only. The audience is one or more active Partner relationships. With a campaign, the audience is exactly that campaign's Partner. Sharing is confirmed in a dialog.
- **Ending** stops new claims; pending claims stay open and existing awards are kept. Ending is confirmed in a danger dialog.
- **Claim:** a Partner owner or admin selects exactly *leads per award* of its own active registrations on an audience relationship. Each must be at or past the rule stage (pitched → negotiating → onboarded → active → repeat client; *lost* never qualifies), have the incentive's campaign as its source when one is set, and not already support a non-released award of the same incentive. Claims are accepted from the start date to the end date while the incentive is shared and the Partner is under its own cap. **Assumption:** dates are calendar days in UTC, and the stage is checked when claimed because registrations keep no stage history. The Brand decides whether the lead truly qualifies.
- **Decision:** a Brand owner or admin approves or rejects a pending award. Rejection needs a reason. Approval fixes the amount for cash rewards (the offer amount by default; a different amount needs a reason), confirms D43 links for campaign-linked incentives and is refused when it would exceed either cap. Caps count approved and fulfilled awards and are checked under a lock on the incentive.
- **Fulfilment:** the Brand marks an approved award fulfilled with a date and an optional reference. **Reverse approval** returns an approved award to pending, with a reason and confirmation.
- **Cancellation:** withdrawing a cited registration, deleting its source lead or ending the relationship cancels pending awards and releases their registrations. Approved and fulfilled awards stay.
- **Visibility:** Brand members read their incentives and awards, including after a relationship ends. Partner members read shared or ended incentives in their audience and their own awards only while the relationship is active and both workspaces are available. Other Partners of the same Brand see nothing.
- **Audit:** `incentive.created`, `incentive.saved`, `incentive.shared`, `incentive.ended`, `incentive_award.claimed`, `.approved`, `.rejected`, `.reversed`, `.fulfilled`, `.cancelled`.
- **Privacy:** all four tables are in the workspace export and the closure review.

### Screens

Brand **Programs › Incentives** (`/brand/incentives`): Offers and Awards tabs, status chips, the incentive record with awards, terms, offer and audience, the new/draft form with a live preview, and the award review side panel. Partner **Programs › Incentives** (`/partner/incentives`): offer cards with readiness and **Claim award**, the claim dialog, and **Your awards**. Campaign screens gain the Leads and Incentive columns and the incentive card.

**Confirmed (2026-11-28):** the Partner **My Leads** summary shows **Rewards earned** beside **Open pipeline** and drops the unmeasured **Reply rate** card. Rewards earned sums fulfilled cash awards per currency, counts fulfilled non-cash rewards (never summed), notes approved awards awaiting delivery, and links to Incentives. Like the Incentives page, it covers only awards from currently shared partnerships. **Expected this month** and **Commissions earned** stay unavailable; incentive awards are not commissions, which wait for the M7-01 commission ledger. **Assumption / Open:** reply rate needs outreach send and reply tracking, which no milestone yet owns.

## Verification gates

Follow the gates in both proposals: real PostgreSQL RLS isolation, viewer write denial, stale versions, caps under concurrent approval, one award per registration per incentive, withdrawal and relationship end, replacement partnerships, export and closure coverage, desktop and mobile browser journeys with axe, and the formatting, type-check, build and existing suites.
