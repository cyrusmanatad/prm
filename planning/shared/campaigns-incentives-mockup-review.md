# Campaigns and Incentives — mockup review

Date: 2026-11-28. Baseline: `feat/campaign-briefs` at `8b2716f` (campaign briefs implemented, D43 and D44 approved, not implemented). Status: **Confirmed (2026-11-28):** the user approved this direction as proposed, including decisions 1–6 below. **Mockup**. Not implemented; nothing in production pages, components, CSS, routes or data has changed.

Scope:

- the campaign brief screens from D42, already implemented in [cyrusmanatad/prm#106](https://github.com/cyrusmanatad/prm/pull/106) and redesigned here
- source campaigns on lead registrations (D43, [proposal](../m7/campaign-lead-link-proposal.md))
- Brand incentives with offers and awards (D44, [proposal](../m8/incentives-proposal.md))

## Viewing

**Mockup:** [`mockup/campaigns-incentives/index.html`](../../mockup/campaigns-incentives/index.html), with `app.js`, `campaigns-incentives.css` and `base.css` beside it. `base.css` is an unchanged copy of the approved Partner-record mockup styles: production tokens, shell and shared components. The mockup uses the repository's licensed font subsets, so serve it from the repository root:

```bash
python3 -m http.server 8765 --bind 127.0.0.1
```

Open `http://127.0.0.1:8765/mockup/campaigns-incentives/`.

The dark dashed bar at the top is prototype-only. It has these controls:

- **Signed in as:** a Brand owner of Habi Home, or a Partner owner of Studio Alon Creative.
- **Screen:** jumps to any screen.
- **State:** Populated, First use, or Studio Alon's relationship ended.
- **Personal accent:** the existing appearance setting.
- **Notes:** shows or hides the dashed **D43**/**D44** design notes.

Review links can preset these, for example `?state=ended&palette=purple&notes=hide#/brand/campaigns/c1`. Data is synthetic, actions change it only in memory, and links outside the proposal report their app address instead of navigating.

## Current Campaigns page (production, PR #106)

These findings come from reading `Campaigns.vue` and the browser journey. I did not inspect the live page for this review.

- **List.** The list is a plain bulleted list of links and one description line. It has no status, partner column, filter or counts, so drafts and shared briefs look the same.
- **Create.** "Create a private draft" is a permanent card above the list. Every visit shows the form, even when the Brand only wants to review campaigns.
- **Draft.** Brief, due date, save, partner choice and share are stacked in one form with two submit paths. **Share brief** publishes immediately, with no confirmation, even though it exposes the brief to another organization and cannot be undone.
- **Shared detail.** The page shows the due date and the brief text, and nothing about results.
- **Feedback.** The saved or shared message is an unstyled status paragraph.

## Proposed direction

The proposal keeps the WeavDash shell, cards, badges, stage chips, tables, filter chips, pagination and confirmation pattern. It adds record headers, figures, a side panel for review and a live preview. Interaction patterns follow the approved Partner record: one record header, a primary column and a secondary column.

### Navigation

1. **Brand:** **Programs › Incentives** becomes available beside Campaigns.
2. **Partner:** **Confirmed:** a new **Programs** group holds Campaigns and Incentives. Campaigns moves out of Outreach, which mirrors the Brand sidebar. **Earnings** stays unavailable, because recorded awards are not platform earnings.

### Brand — Campaigns

3. **List:**
   - The page heading has a **New campaign** button that opens a small dialog for the name and creates the private draft. This replaces the permanent create card.
   - Four figures: Shared briefs, Private drafts, Leads from campaigns, and Awards to review. Awards to review is highlighted when there is something to review and links to Incentives.
   - Status filter chips: All, Drafts, Shared and Relationship ended. A chip only appears when it has records.
   - A table with Campaign (with its creation date), Partner, Status badge, Due, Leads and Incentive.
4. **Draft:**
   - A record header shows **Private draft**, the creation date and "Your workspace only".
   - The form has a Brief section (the brief and due date) and a Share section (the Partner).
   - A **Ready to share?** checklist updates as fields are filled. **Share brief…** stays disabled until all three items are done.
   - **Share brief…** opens the shared confirmation dialog. The dialog names the campaign, the Partner and the due date, and states the consequences: the Partner can read it, it becomes read-only, and ending the relationship removes access. Cancel has focus first. This closes the gap noted in the campaign review.
5. **Shared campaign:**
   - The record header shows the status, the Partner (avatar and link), the due date and the date shared, with **Open in Lead registrations** and **Add incentive** actions.
   - The primary column holds **Registered leads** (D43): a stage progress bar with a legend, then a table of Lead, Stage, Registered and **Linked to campaign**. A link set more than 7 days after the lead was registered shows the flag "Linked N days after registering".
   - The brief text sits below, marked read-only since sharing.
   - The secondary column shows the linked incentive (reward, awards approved against the cap, pending claims) or an "Add incentive" empty state, then Details.
   - When the relationship has ended, a warning explains that the Partner lost access and shared leads are hidden, while approved awards are kept.

### Brand — Incentives (D44)

6. **Offers and Awards tabs.**
   - Four figures: Active offers, Awards to review, Approved but not yet delivered (₱), and Delivered this year (₱).
   - The Offers table shows Incentive (with its campaign or "Any lead"), Reward, Audience, Status, Awards (approved against the total cap, plus pending) and Runs. Status filter chips sit above it.
   - The Awards tab opens on **To review**.
7. **Award review side panel.** Opening a Partner's award shows:
   - the reward and claim date, and the Partner's note
   - each cited lead with stage, registration date, source campaign and link date, the late-link flag, and a live rule check
   - an Approve or Reject choice; Approve shows how much of the Partner's cap it uses
   - an amount field for cash, defaulting to the offer value; changing it or rejecting requires a reason
   - for a campaign-linked incentive, a statement that approving also confirms the D43 source campaign
   - the claim history

   For an approved award, the panel records fulfilment instead: delivered date and an optional reference, with "WeavDash does not send money". **Reverse approval…** asks for confirmation.
8. **Incentive detail:**
   - The record header has **End incentive…**, which uses a danger confirmation stating that pending claims stay open and existing awards are kept.
   - The primary column holds the Awards table and the read-only Terms.
   - The secondary column holds the Offer card (reward, rule, total cap meter) and the Audience, with a per-Partner cap meter.
9. **New incentive form:**
   - **Offer:** title, reward type (Cash, Non-cash or Percentage bonus), amount or description, and terms.
   - **Who qualifies:** the stage reached, leads per award, an optional source campaign, and the Partners.
   - **Dates and limits:** start, end, awards per Partner and awards in total.
   - Choosing a campaign locks the audience to that campaign's Partner and explains why.
   - A sticky **What Partners see** preview updates live.
   - **Save draft** checks only the basic fields; **Share incentive…** checks everything. Errors appear inline, with a focused summary that links to each field. The confirmation names the audience and the consequences.

### Partner

10. **Campaigns:** a Brief table with From, Due ("In 17 days" or "Past due"), Your leads and an Incentive badge. After a relationship ends, a notice explains why that Brand's briefs disappeared, while other Brands' briefs remain.
11. **Brief:**
    - The record header has **Register a lead for this brief**. It opens the existing registration fields with **Source campaign** preselected and a hint that the Brand sees the campaign and the link date (D43).
    - The **Your leads for this brief** table shows the stage summary and each lead's award status.
    - The **Incentive on this brief** card shows the reward, the rule, the Partner's own cap meter, their claim counts and **Claim award**. On phones it moves above the lead list.
12. **Incentives:**
    - Four figures: Available offers, Claims in review, Approved awaiting delivery, and Received.
    - Offer cards show the reward, rule, own cap meter, terms and readiness ("1 lead ready", or "Needs 3 qualifying leads; 2 ready"), with **Claim award**.
    - The **Your awards** table shows status and details: delivery date and reference, or the rejection reason.
13. **Claim dialog:**
    - It lists every registration with a plain eligibility reason, eligible leads first. Examples: "Meets the rule · Onboarded", "Stage is Negotiating — needs Onboarded", "Source campaign isn't …", "Already used in an award for this incentive".
    - Selection is limited to the leads-per-award number, with a live "0 of 1 selected" count.
    - An optional note to the Brand.

## Decisions

Items 1–6 are **Confirmed (2026-11-28)** as part of the mockup approval. Items 7 and 8 were not confirmed by it.

1. **Confirmed:** the Partner **Programs** group for Campaigns and Incentives (item 2).
2. **Confirmed:** the campaign redesign replaces the PR #106 screens: the list table, figures and chips, the create dialog, the draft checklist, the share confirmation and the shared detail with leads. **Confirmed:** it was intended for PR #106. That PR merged first, so it lands in the follow-up [cyrusmanatad/prm#107](https://github.com/cyrusmanatad/prm/pull/107); see Implementation below.
3. **Confirmed:** award review in a side panel, rather than its own page, so a Brand can work through a queue without losing their place.
4. **Confirmed:** an explicit **Leads per award** rule field. D44 says one or more registrations, and this fixes the number per offer.
5. **Confirmed:** the late-link flag threshold of 7 days.
6. **Confirmed:** an optional note from Partner to Brand on a claim, and the fulfilment reference shown to the Partner.
7. **Assumption / Open:** the mockup answers the open D44 questions as recommended: both phases together, cash rewards allowed and labelled "paid outside WeavDash", Partners see only their own cap, and three reward types. They remain open in [D44](decisions.md#d44--bring-brand-incentives-forward).
8. **Assumption / Open:** a registration may support awards for different incentives. Stacking is allowed, but the same incentive can't use it twice.

## Verification

All checks used Chromium at 1440×900 and 390×844, against a local static server.

- **Screens:** all 10 screens at both sizes (20 renders):
  - no horizontal overflow
  - no console or page errors
  - zero axe violations, with the prototype bar excluded
- **Flows** at both sizes:
  - share confirmation: Cancel is focused first; Escape restores focus to **Share brief…**; confirming shares the brief
  - award review: a changed amount without a reason shows an error and focuses the reason field; approving works; marking fulfilled works
  - incentive form validation: the error summary takes focus and links to fields
  - Partner claim: the selection limit works and submission works
  - register-lead dialog: works, and the brief's lead list updates
  - zero axe violations in every open dialog or panel
- **Fixes made during these checks:**
  - phone overflow: grid tracks now use `minmax(0, 1fr)`
  - the Partner dark-header account label failed contrast and now matches production's white label
  - axe heading-order and list-structure issues in the incentive form
  - long non-cash rewards were sized down
  - offer cards now fill the row
  - zero-count filter chips are hidden
- **Formatting:** Prettier using `src/.prettierrc.json` passes for all mockup files.
- **Screenshots:** temporary only, kept outside the repository and deleted after inspection.
- **Not verified:** WebKit and Safari, the live app, and screen-reader passes beyond axe.

## Implementation (campaign briefs, PR #107)

Implemented 2026-11-28 on `feat/campaign-briefs`, in [cyrusmanatad/prm#107](https://github.com/cyrusmanatad/prm/pull/107). Not merged or deployed. The [verification record](../m7/campaign-brief-evidence.md) has the checks.

- **Built:** items 2–5 for D42 only:
  - the Brand list with status chips and a table
  - the **New campaign** dialog (the existing `FormDialog`)
  - the draft with a readiness checklist and the share confirmation (`confirmAction` and the shared `ConfirmationDialog`)
  - the read-only record for Brand and Partner, with an ended-relationship notice
  - the Partner list of shared briefs
  - the Partner **Programs** group (decision 1), with Incentives unavailable
- **Deliberately left out until D43 and D44 are built:**
  - the Leads and Incentive columns and figures
  - Registered leads on the campaign
  - the Incentive card
  - **Register a lead for this brief**

  Without them, the figures row would only repeat the chip counts, and the Details aside would only repeat the header. Both return with the D43 and D44 cards.
- **Reuse:**
  - The Partner record's breadcrumb, record header and column styles are now the shared `Breadcrumbs` and `RecordHeader` components and `wd-record-*` styles in `portal-theme.css`. The Partner record uses them too; its rendering and tests are unchanged.
  - Due dates use a time-zone-safe `calendarDate` (for example 3 Feb 2027), matching the portal's `dateOnly` format.
- **Wording:** the brief field is labelled "What should the Partner do?", because a field and its section were both named "Brief".
