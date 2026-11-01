# Partner portal contract

**Status: Proposal (2026-11-01).** Requires review and approval before implementation. Terminology and portal model are **Confirmed** in [D29](decisions.md).

## Purpose

**Confirmed:** the Partner portal serves people a Business or Professional works with, not workspace owners:

- partners invited by a Business through an email invitation or sign-up link (D28);
- converted leads of a Professional, who go through onboarding.

**Confirmed:** partners need only a WeavDash account, not a workspace. A partner may optionally register their own brand, which creates a separate Business workspace they own.

## Proposed scope

**Proposal:** routes live under `/partner/*`, replacing the temporary D29 redirects to `/pro/*`. Entry uses `/partner/login` and `/partner/register`.

| Area | Proposal |
|---|---|
| Home | List of the businesses/professionals the partner is connected to, with status and next actions. |
| Onboarding | Complete onboarding forms and upload files. Reuses the M3 onboarding form, document scanning and consent model, attached to the partner's account instead of a one-time capability link. |
| Projects | Track ongoing projects with the Professional: title, status, milestones and due dates. The Professional manages projects; the partner sees progress. |
| Contracts | View contract documents shared by the Professional, with version and shared date. Viewing only; e-signature is out of scope. |
| Messages | Per-relationship message thread between partner and Professional, with email notification of new messages (M5 outbox). |
| Own brand | "Register my brand" creates a Business workspace owned by the partner, unconnected to existing relationships unless explicitly linked. |

## Data and security

- **Proposal:** add a partner-account relationship that replaces creating a Professional workspace on acceptance: `partnerships` gains a partner user identity instead of requiring `creator_org_id` for this path. Existing workspace-to-workspace partnerships remain valid.
- **Proposal:** projects, contracts and messages are owned by the Business/Professional workspace and shared with exactly one relationship. RLS is tested against real PostgreSQL, including denied cross-relationship reads, revoked relationships and removed accounts.
- **Confirmed (existing):** destructive actions and access revocation use `confirmAction`; uploads use the existing malware scanning; tokens stay out of URLs and logs.

## Assumption / Open

- Can a Business (not only a Professional) run projects, contracts and messages with its partners?
- Do converted leads receive an account invitation automatically on conversion, or does the Professional send one explicitly?
- Message attachments, read receipts and retention period.
- Contract file types, size limits and whether partners can upload signed copies.
- Migration of partners who already joined through a Professional workspace (D28): keep as is, or offer a move to a partner account.
- Whether the `{{ creator_name }}` outreach merge token is renamed (requires migrating saved templates).

## Acceptance (proposed)

Real-PostgreSQL RLS tests for every new table; PHPUnit integration journeys for invitation → partner account → onboarding → project → message; component tests; Playwright desktop/mobile journeys with screenshots; Prettier, typecheck and build per [AGENTS.md](../AGENTS.md).

## Proposed layout

**Proposal:** the Partner portal follows the D30 production layout conventions: one-row header, working pages only, a menu button on narrow viewports and a single "Coming soon" strip for deferred features.

```
┌──────────────────────────────────────────────────────────────┐
│ WeavDash [Partner]   Home  Projects  Files  Messages   (BC) ☰ │
├──────────────────────────────────────────────────────────────┤
│ Hello, Sam                                     [Register my brand] │
│ ┌ Connected with ┐ ┌ Open projects ┐ ┌ Unread messages ┐        │
│ └────────────────┘ └───────────────┘ └─────────────────┘        │
│ ┌ Your businesses ───────────────────┐ ┌ Next steps ──────────┐ │
│ │ Habi Home · Onboarding 2 of 3  [›] │ │ Upload signed brief  │ │
│ │ Kape Kultura · Active project  [›] │ │ Reply to Bea         │ │
│ └────────────────────────────────────┘ └──────────────────────┘ │
└──────────────────────────────────────────────────────────────┘
```

| Page | Content |
|---|---|
| Home | Summary cards, connected businesses/professionals with status, next steps. |
| Relationship detail | Tabs for Onboarding, Project, Contract, Files and Messages for one business/professional. |
| Projects | All projects across relationships, filterable by status. |
| Files | Uploaded and shared files, grouped by relationship. |
| Messages | Conversation list with unread counts; opens the relationship's thread. |
| Register my brand | Dialog creating a Business workspace; opens the Brand portal afterwards. |
