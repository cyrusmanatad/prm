# Requirements and Mockup Gap Analysis

## Business feature inventory

| ID | Module and features | Evidence | Proposed delivery |
|---|---|---|---|
| R01 | Organizations, memberships, owner/admin/manager/member/viewer roles, RLS, audits, exports/deletion, modular entitlements | PDF §4–5, pp.4–6 | Foundation in MVP; enterprise extensions later |
| R02 | Brand/contact records; values, stages, next actions; outreach merge templates; reminders; manually logged email/chat/call activity | PDF §3.1, pp.2–3 | MVP |
| R03 | Profile/social accounts, shareable media kit and view tracking | PDF §3.1 | MVP; privacy-aware view counts |
| R04 | Discovery/fit, seasonal nurture calendar, health scores, re-pitch suggestions, weighted forecast/retainers | PDF §3.1 | Basic explicit-value summaries first; advanced scoring later |
| R05 | Branded onboarding: company, TIN, permits/registration, billing contacts, guidelines, brief/deliverables, rights/exclusivity, sample shipping | PDF §3.2 | MVP; sensitive fields optional until justified |
| R06 | Contract templates/signatures with signer/time/IP/user-agent evidence; numbered invoices, TIN/withholding; overdue reminders | PDF §3.3 | Basic invoice/payment records in MVP; signature integration later |
| R07 | GCash/Maya/bank/PayPal, batch payouts, payment status, Form 2307 tracking | PDF §3.3 | Manual reconciliation first (confirmed); integrations and tax workflow later |
| R08 | Recruitment/directory; campaigns, draft approvals, deadlines, proof-of-post links/screenshots | PDF §3.4 | Brand campaign product after MVP |
| R09 | Links/codes; Shopify/Shopee/Lazada/TikTok Shop/WooCommerce order matching; percentage/flat/CPA commissions and ledger | PDF §3.4 | Phase 2, one integration at a time |
| R10 | Follower/engagement anomalies, duplicate/self-referral checks, ad disclosures and regulated-category warnings | PDF §3.4 | Later, evidence-based human review |
| R11 | Product gifting and J&T/Lalamove tracking | PDF §3.4 | Later |
| R12 | Explainable deal priorities, pitch drafts, follow-up timing, rate suggestions, forecasts/concentration, content analysis | PDF §3.5 | Consent-based pitch draft pilot; advanced models later |
| R13 | Brand AI matching, quality/fraud flags, campaign rebook/drop summaries; enterprise portfolio/anomaly/compliance review | PDF §3.5 | Later |
| R14 | Agency shared pipelines/delegation, approvals, white-label reports, HubSpot; enterprise SAML/OIDC, residency, SLAs, admin controls | PDF §6 | Later; audit/security foundations now |
| R15 | Subscription tiers/modules, payout/marketplace fees, services, consent-based aggregated benchmarks | PDF §7 | Entitlement foundation; commercial activation after validation |

## Seven mockup boards

1. **Partner portal / My leads:** navigation for Inbox, Campaigns, Media kit, Earnings, Autopilot; greeting, KPIs, ranked actions with reasons, pipeline, six automation toggles, partner perks. Authored JavaScript clears actions for both CTA and snooze, and toggles local state. It does not implement actual snooze dates or draft review.
2. **Mobile capture and alerts:** brand/contact field, five source chips, save button, screenshot-forwarding promise, activity feed, bottom navigation. Source-chip selection changes local state; capture persistence is not implemented in authored component logic.
3. **Logo and palette:** loom, thread-W, spiral variants; icon sizes; fuchsia `#8E1650`, mint `#8FD0B8`, deep mint `#2F7F69`, wood `#E3C79A`, background `#FBF7EF`, ink `#221A26`; Bricolage Grotesque and Manrope fonts. Treat as design direction awaiting visual validation.
4. **Brand partner hub:** partner table, segments, health, nudges, lead-conflict queue, tiers (8/10/15%), sprint bonuses, enablement assets/usage. Nudge and approve handlers only change local display state.
5. **Automation playbook:** recruit/onboard/activate/grow/retain lifecycle. Creator capture, follow-ups, paperwork, deal rooms, invoice/payout automation, tier nudges, re-pitch, digest. Brand onboarding rescue, 60-day lead protection/conflicts, attribution, incentives, signals, campaign drops, compliance holds, scorecards/opt-in leaderboard.
6. **Suggested stack:** Next.js/tRPC, Supabase, Inngest, Claude/pgvector, Upstash, Xendit, e-sign, email/chat/SMS/couriers, Vercel, observability. These are source-observed mockup proposals. The approved stack is Laravel + Vue/Inertia with PostgreSQL, Redis/Laravel queues, Docker Compose, and GitHub Actions; AWS S3 or OVH storage remains open.
7. **Wireframes:** creator dashboard, lead drawer, public five-step brand form, brand hub, creator program checklist, automation builder; creator lead-to-payout and brand invite-to-growth flows.

## Discrepancies and missing behavior

| Finding | Implication / proposed resolution |
|---|---|
| PDF has seven stages: Lead, Pitched, Negotiating, Onboarded, Active, Repeat Client, Lost. Visible board shows five; automations depend on undefined “Won.” | Keep seven source stages; propose a separate accepted-deal event before onboarding. Approval required. |
| Both creator onboarding a brand and brand onboarding a creator exist. | Separate invitation types, forms, permissions, and completion events. |
| PDF's Phase 1 includes payouts and AI; playbook also labels deal rooms, contracts, and brand activation as Phase 1. | Confirmed MVP prioritizes pipeline/onboarding and manual reconciliation; other deferrals are explicit scope proposals. |
| “Everything else runs on its own” and auto-sent contracts conflict with approval language. | Default to draft approval for external messages, contracts, invoices, and financial actions; define narrow opt-in exceptions later. |
| Full brand hub, B2B lead protection, tiers, enablement, OCR/email capture, offline PWA and rule builder exceed the creator MVP. | Track as mockup-only expansion requirements pending validation. |
| Signup, org switching, billing, roles, consent management, export/delete, error/empty/loading states are absent or schematic. | Design these before feature completion; navigation labels are not complete screens. |
| Drag-and-drop is promised; authored components contain no drag handler. KPI totals/cards are illustrative and not reconciled. | Implement and test real stage transitions, accessible alternatives, and derived aggregates later. |
| Desktop boards use 1440px frames; mobile is a separate 390px frame. No authored CSS media queries found. | Responsive layouts and accessibility require browser validation; separate boards do not prove responsive support. |
| Sample schema uses globally unique external order IDs and mutable commission status. | Scope order keys by tenant/store; use immutable financial entries and append-only events. |
| Tenant sharing, directory consent, payout custody, attribution precedence, cancellations/refunds, and subscription lifecycle are unspecified. | Resolve in decision register before the dependent milestones. |

The mockup is a design reference with scripted demonstrations, not an implemented SaaS application. Embedded library/runtime code was identified; third-party library internals were not exhaustively audited.

## Technical baseline after stack approval

Plan Inertia pages served by Laravel controllers, Form Request validation, and organization-aware policies. Use Laravel queues/scheduler for automation and a PostgreSQL outbox for durability. REST APIs serve integrations and selected JSON operations rather than duplicating every Inertia screen. Native Laravel session authentication is the initial proposal; enterprise federation remains a later extension. These refinements do not add business features or approve the unresolved sharing, pricing, AI, or invoice decisions.
