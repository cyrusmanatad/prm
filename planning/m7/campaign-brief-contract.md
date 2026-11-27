# Campaign briefs

**Confirmed (2026-11-28)** — bring forward the local Brand-to-Partner brief workflow from M7. A Brand creates a private draft, adds a brief and due date, then shares it with one selected active Partner. That Partner can open the brief from Campaigns. Another Partner cannot read it. Ending the relationship removes access through both the list and saved links.

## Implemented behavior

- Brand owners and admins can create a named draft, save partial content and share it once. The draft belongs to the Brand workspace. Other active Brand members can read it.
- Sharing requires a brief, due date and eligible Partner relationship. The Brand can save a private draft while choosing a recipient. Only **Share brief** publishes the current fields together. Concurrent or repeated submissions must match the stored draft version.
- The recipient is a Partner workspace. Its active members can read the shared brief. The relationship must still be active and both workspaces available. A later relationship with the same Partner has a different ID and does not restore access to the old brief.
- The shared brief is read-only. The Brand retains its own record after the relationship ends. The workspace privacy export includes campaigns visible under the same database read policies. A Brand workspace closure review counts its campaigns, and a brief changed after review makes that review stale. Closure keeps the records.
- Brand and Partner sidebar destinations are `/brand/campaigns` and `/partner/campaigns`. Each campaign has a detail address under the corresponding list. Sharing happens inside the portal.

Deliverable review, commerce connectors, attribution and commissions follow the [remaining M7 roadmap](../shared/implementation-roadmap.md). See the [verification record](campaign-brief-evidence.md).
