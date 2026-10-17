# MVP Scope

## Confirmed objective

Launch creator pipeline and brand onboarding first, with manual payment reconciliation initially. Demonstrate that creators can keep follow-ups and brand paperwork in one place. A production-ready foundation is required even when commercial features are piloted manually.

## Approved technical baseline

Laravel/PHP with Vue 3 + TypeScript + Inertia, Tailwind/shadcn-vue, PostgreSQL RLS, Redis-backed Laravel queues and scheduler, Docker Compose, and GitHub Actions. Plan one application, with frontend assets built by Vite and private files accessed through Laravel's filesystem abstraction. AWS S3 versus OVH Object Storage is unresolved. Subsequent user instruction authorizes implementation one milestone at a time; remaining business decisions are still open.

## Proposed included features

1. Verified user access, creator organizations, minimum membership/role support, tenant isolation, audit trail, consent, and export/deletion workflow.
2. Mobile-friendly lead capture, private contacts/notes, seven-stage pipeline, next-action reminders, search/filter, activity timeline, outreach templates with manual copy/edit. Accessible stage menu alongside any drag-and-drop.
3. Revocable inbound lead link and media kit with explicit public fields and basic aggregated view counts.
4. Branded, scoped invitation with business/billing/brief/rights/shipping steps, save/resume, correction/resubmit, document upload, submission notification. Sensitive collection is optional until justified and reviewed.
5. Basic invoice drafts/records, due dates, manual payment status/allocations and evidence. Actual tax invoice issuance only after accounting approval; no automated transfers.
6. Named reminder/digest automations, explicit drafts/approval state, durable jobs/outbox, feature entitlements and usage accounting. AI pitch drafting may be a bounded opt-in pilot behind consent/budget controls, not a blocker for the non-AI workflow.
7. Operational readiness: CI, tenant regression suite, backups/restore drill, monitoring, upload safety, deployment/rollback and privacy runbooks.

These details beyond the confirmed objective are scope proposals. The PDF Phase 1 includes media kits, invoicing, payouts, and AI; manual financial handling is now confirmed, while AI and signature deferrals remain reviewable tradeoffs.

## Explicit future enhancements

- Full campaign scheduling, draft/proof-of-post approvals and deal rooms; integrated contract/e-signature evidence.
- Commerce attribution and order/refund ingestion, immutable commission ledger, automated payout provider integrations and batch approvals, Form 2307 collection workflow.
- Brand directory/discovery, AI fit/health/rate predictions, advanced forecasts and performance summaries, seasonal re-pitches.
- Full brand PRM hub, recruitment, tiers/sprints, enablement, B2B lead protection/conflicts, scorecards and opt-in rankings.
- Screenshot OCR/inbound-email extraction; chat/SMS integrations; offline PWA/push and native apps; configurable workflow builder.
- Fraud/disclosure assistance, gifting/courier integrations, marketplace fees, consent-based benchmarks.
- Agency portfolios, white-labeling, CRM integrations, enterprise SSO/SCIM, contractual data residency/SLAs.

## Pilot success and release boundary

PDF targets: 20–30 committed pilots before full build; Phase 1 goal of 200 creators and 50 onboarded brands with monthly active deal use. These are product goals, not demonstrated capacity or a committed launch forecast.

Proposed pilot measures: capture-to-next-action completion, invitation-to-submission conversion, weekly active creators/deals, time saved gathering documents, and willingness to pay. Set numeric thresholds after discovery instead of inventing them.

Technical release requires no unresolved critical isolation/security failures, tested recovery, and successful creator-to-brand submission and manual invoice reconciliation journeys. Commercial launch additionally requires approved pricing, processor setup, support ownership, and relevant privacy/accounting review. A development VPS demo does not establish production readiness.
