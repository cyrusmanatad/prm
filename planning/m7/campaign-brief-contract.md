# Campaign briefs

**Confirmed (2026-11-28)** — bring forward the local Brand-to-Partner brief workflow from M7. A Brand creates a private draft, adds a brief and due date, then shares it with one selected active Partner. That Partner can open the brief from Campaigns. Another Partner cannot read it. Ending the relationship removes access through both the list and saved links.

## Implemented behavior

- Brand owners and admins can create a named draft, save partial content and share it once. The draft belongs to the Brand workspace. Other active Brand members can read it.
- Sharing requires a brief, due date and eligible Partner relationship. The Brand can save a private draft while choosing a recipient. Only **Share brief** publishes the current fields together. Concurrent or repeated submissions must match the stored draft version.
- The recipient is a Partner workspace. Its active members can read the shared brief. The relationship must still be active and both workspaces available. A later relationship with the same Partner has a different ID and does not restore access to the old brief.
- The shared brief is read-only. The Brand retains its own record after the relationship ends. The workspace privacy export includes campaigns visible under the same database read policies. A Brand workspace closure review counts its campaigns, and a brief changed after review makes that review stale. Closure keeps the records.
- Brand and Partner sidebar destinations are `/brand/campaigns` and `/partner/campaigns`. On both sidebars Campaigns sits in a **Programs** group. Each campaign has a detail address under the corresponding list. Sharing happens inside the portal.
- **Confirmed (2026-11-28) UI:** the screens follow the approved [Campaigns and Incentives mockup](../shared/campaigns-incentives-mockup-review.md).
  - The Brand list has status chips (All, Drafts, Shared, Relationship ended). A chip only appears when it has campaigns. The list shows Partner, status and due date, and `?status=` filters it.
  - **New campaign** opens a dialog.
  - A draft shows a readiness checklist. Sharing is confirmed in a dialog that names the campaign, the Partner and the due date.
  - Shared briefs show a record header with status, Partner, due date and the date shared. A relationship that has ended shows a notice.
- `shared_at` records when a brief was shared. Briefs shared before it existed take their creation time.

Deliverable review, commerce connectors, attribution and commissions follow the [remaining M7 roadmap](../shared/implementation-roadmap.md). See the [verification record](campaign-brief-evidence.md).
