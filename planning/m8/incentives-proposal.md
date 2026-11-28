# Brand incentives

**Confirmed (2026-11-28)** — approved as [D44](../shared/decisions.md#d44--bring-brand-incentives-forward); not implemented. The concepts, phases, Brand approval of every award, recorded-only fulfilment, scope boundaries and readiness condition are confirmed. Items marked **Proposal** or **Open** below still need a decision. Bring the first part of Brand incentives forward from the deferred M8-02 scope ([remaining tasks](../shared/remaining-tasks.md)). A Brand offers rewards to selected Partners and approves the awards Partners earn through registered leads. Rewards are recorded and fulfilled outside the platform; nothing is paid through it.

## Problem

Brands run partner programs with rewards: bonuses per qualifying lead, product bundles, and quarterly or tier bonuses. Today the Brand **Incentives** item is unavailable. Rewards are agreed in chat or spreadsheets, with no shared record of the offer, who qualified, or what was owed and delivered.

## Concepts

- **Incentive (the offer).** What a Brand promises and on what terms. It belongs to the Brand workspace.
- **Incentive award (what was earned).** One Partner qualified for one incentive through specific lead registrations. It carries its own status, amount and history.
- **Who earns.** Partners earn rewards. Lead registrations are the qualifying evidence. Rewards to a lead or customer, such as discount codes, are a different product and out of scope.

Keeping offer and award separate lets one Partner earn the same incentive more than once and lets a Brand reject one claim without affecting another.

## Proposed behaviour

### Phase 1 — Offers

- Brand owners and admins create an incentive with:
  - title and terms
  - reward type: *cash amount* (in the workspace currency), *non-cash* (described in text) or *percentage bonus* (described in text)
  - reward value or description
  - qualifying rule (below)
  - start and end dates
  - optional cap on awards per Partner and in total
  - optional source campaign
- **Qualifying rule.** A registration in the Partner's relationship reaches a chosen stage, such as Onboarded, within the incentive's dates. When a campaign is linked, the registration must also have that campaign as its [D43 source campaign](../m7/campaign-lead-link-proposal.md). The rule is shown as plain text to both sides. It decides which registrations a Partner can claim with; it never grants an award by itself.
- **Audience.** The Brand shares an incentive with selected active Partners. When it is linked to a campaign, the campaign's recipient is the default and only audience, because a campaign is shared with one Partner.
- An incentive is a private draft until shared. Sharing publishes the terms, which are then read-only. The Brand can end an incentive early; this stops new claims and keeps existing awards. Ending it requires confirmation that names the incentive and the consequence.
- Partners see shared incentives, with terms, dates, remaining cap and their own awards, under a Partner **Incentives** page next to Campaigns. Brands see them under the existing **Incentives** item.

### Phase 2 — Awards

- A Partner owner or admin claims an award by selecting one or more of their active registrations that meet the rule. A registration can support only one award per incentive.
- The Brand approves or rejects each claim with a reason. Approval:
  - confirms the source-campaign link on the cited registrations, which D43 requires once rewards depend on it
  - fixes the award amount, defaulting to the offer value and editable with a reason
  - is refused when it would exceed a cap
- Statuses: *pending → approved or rejected*, then *approved → fulfilled*. The Brand marks an award fulfilled with the date and an optional reference, such as a transfer or delivery note, after delivering it outside the platform. Rejecting or reversing an approval requires confirmation.
- Withdrawing a cited registration, or ending the relationship, cancels pending claims. Approved and fulfilled awards remain as history for both sides while the relationship is visible and in the Brand's own records after it ends.

### Phase 3 — Payouts (not in this proposal)

Paying awards through the platform, statements, withholding and Form 2307 handling belong to M8-01 payouts and need separate provider, tax and business approvals.

## Data and access design

**Proposal / implementation defaults:**

- **`incentives`** stores:
  - the Brand workspace, title, terms, reward type, value in minor units or text, and currency
  - the rule stage, dates, caps and optional `campaign_id`
  - state (*draft*, *shared*, *ended*), version and timestamps
- **`incentive_partners`** links an incentive to each partnership in its audience.
- **`incentive_awards`** stores the incentive, partnership, state, amount, reasons, decision and fulfilment details, and version.
- **`incentive_award_registrations`** links each award to its cited registrations. A unique key on incentive and registration makes a registration support only one award per incentive.
- Forced RLS on all four tables, following the [campaign brief](../m7/campaign-brief-contract.md) pattern:
  - Brand members read their own incentives and awards.
  - A Partner reads shared incentives and its own awards only while the relationship is active and both workspaces are available.
  - Another Partner of the same Brand sees nothing.
- All writes go through security-definer functions with role checks, version checks and relationship locks. The runtime role cannot update or delete these tables directly. Caps are checked under a lock on the incentive, so concurrent approvals cannot exceed them.
- Audit every create, share, end, claim, decision, amount change and fulfilment.
- Include all four tables in the workspace privacy export and in the closure review, a gap the campaign review found.
- Money values use integer minor units and the existing finance formatting, like invoices and payments.

## Out of scope

- payouts or transfers
- tax withholding and Form 2307
- customer-facing rewards
- tiers
- automatic awards without Brand approval
- incentives across several Brands
- MDF and co-op funds
- leaderboards

## Readiness and open questions

1. **Confirmed:** start only when pilot Brands confirm they run reward programs and would record them here. M8-02 is otherwise deferred.
2. **Open:** Phase 1 alone or Phases 1 and 2 together? Offers without awards are low-risk but of limited use. **Proposal:** build both, behind the D43 source campaign.
3. **Open:** cash rewards in Phase 1, or non-cash only until payouts and tax handling exist? **Proposal:** allow cash amounts as recorded obligations, labelled "paid outside WeavDash".
4. **Open:** may a Partner see other Partners' remaining total cap, or only their own?
5. **Open:** reward types beyond the three listed.

## Verification gates

- PostgreSQL RLS and integration:
  - another Brand cannot read the data, and neither can a non-audience Partner of the same Brand
  - viewers cannot write
  - stale versions are rejected
  - cap limits hold under concurrent approvals
  - a registration cannot support two awards for the same incentive
  - withdrawal, ending a relationship and replacement partnerships behave as above
  - export and closure include all four tables
- Browser journeys at desktop and mobile widths: the Brand creates, shares and ends an offer; the Partner claims; the Brand approves, rejects and marks fulfilled. Confirmation dialogs follow the shared pattern, and axe reports no violations.
- Formatting, type check, build, and the existing finance and lead visibility suites pass.
